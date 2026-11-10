<?php
/** Creates the database, default gym set-up, the first administrator and optional sample data. */
final class Installer
{
    // createDatabase(): creates the MySQL database itself (connecting without a database name).
    public static function createDatabase(): void
    {
        // Backticks are removed from the name, so it cannot break out of the SQL.
        $name = str_replace('`', '', App::config('db.name'));
        Db::connect(false)->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    // isInstalled(): true when at least one administrator exists.
    public static function isInstalled(): bool
    {
        try {
            return (int) Db::value("SELECT COUNT(*) FROM users WHERE role = 'admin'") > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    /** Creates all tables (from database/schema.sql) and the default zones, hours and sessions. */
    public static function installSchema(bool $dropExisting = false): void
    {
        $pdo = Db::pdo();
        // $dropExisting = true is used by the tests to start from an empty database.
        if ($dropExisting) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach (['attendance', 'bookings', 'closures', 'time_slots', 'operating_hours', 'verification_tokens', 'password_resets',
                      'rate_limits', 'contact_messages', 'settings', 'users', 'apartments', 'zones'] as $t) $pdo->exec("DROP TABLE IF EXISTS `$t`");
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        // Read schema.sql, remove the -- comment lines, split it at each ";" and run each CREATE TABLE.
        $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents(App::root() . '/database/schema.sql'));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) $pdo->exec($statement);

        // Add the default settings (INSERT IGNORE keeps values that already exist).
        $ins = $pdo->prepare('INSERT IGNORE INTO settings (name, value) VALUES (?, ?)');
        foreach (Settings::DEFAULTS as $k => $v) $ins->execute([$k, $v]);
        Settings::forget();

        // Default zones on a new installation: Cardio 10, Weights 6, Machines 8 places.
        if (!(int) Db::value('SELECT COUNT(*) FROM zones')) {
            $z = $pdo->prepare('INSERT INTO zones (name, description, capacity, sort_order) VALUES (?, ?, ?, ?)');
            $z->execute(['Cardio', 'Treadmills, exercise bikes and rowing machines', 10, 1]);
            $z->execute(['Weights', 'Free weights, benches and squat racks', 6, 2]);
            $z->execute(['Machines', 'Resistance and cable machines', 8, 3]);
        }
        // Default opening hours: open 24 hours on all seven days.
        if (!(int) Db::value('SELECT COUNT(*) FROM operating_hours')) {
            $h = $pdo->prepare("INSERT INTO operating_hours (weekday, is_open, open_time, close_time) VALUES (?, 1, '00:00', '24:00')");
            foreach (Schedule::WEEKDAYS as $wd => $_) $h->execute([$wd]);   // open around the clock
        }
        Schedule::forgetCache();
    }

    // createAdmin(): the first administrator (or another one, from scripts/create-admin.php).
    public static function createAdmin(string $name, string $email, string $password, string $confirm): int
    {
        $name = Validator::fullName($name);
        $email = Validator::email($email);
        $password = Validator::newPassword($password, $confirm);
        // The same email check as for residents; the password is stored as a bcrypt hash.
        if (Db::value('SELECT 1 FROM users WHERE email = ?', [$email])) throw new UserError('This email address is already registered.');
        $id = Db::insert("INSERT INTO users (role, status, full_name, username, email, password_hash, created_at, activated_at)
                          VALUES ('admin', 'active', ?, ?, ?, ?, ?, ?)",
            [$name, Residents::uniqueUsername($name), $email, password_hash($password, PASSWORD_DEFAULT), dt(Clock::now()), dt(Clock::now())]);
        if (Settings::all(true)['contact_email'] === '') Settings::set(['contact_email' => $email]);   // shown on the Help page
        return $id;
    }

    /**
     * Realistic sample data for demonstrations: apartments, residents (active, pending and deactivated),
     * past, current and upcoming sessions, and a planned closure. Every active sample resident gets
     * the password given here; none is stored in the source code.
     */
    public static function seedSampleData(string $residentPassword, int $adminId): array
    {
        Validator::newPassword($residentPassword, $residentPassword);
        // mt_srand(2026) makes the "random" sample data the same on every installation.
        mt_srand(2026);
        $now = Clock::now();
        $hash = password_hash($residentPassword, PASSWORD_DEFAULT);
        $people = [
            ['Anna Jensen', '1A', 'active'], ['Mikkel Jensen', '1A', 'active'], ['Sofie Nielsen', '1B', 'active'], ['Lucas Hansen', '2A', 'active'],
            ['Emma Pedersen', '2C', 'active'], ['Oliver Andersen', '3B', 'active'], ['Ida Christensen', '4A', 'active'], ['William Larsen', '4D', 'active'],
            ['Freja Sørensen', '5C', 'active'], ['Noah Rasmussen', '5C', 'active'], ['Clara Madsen', '3B', 'pending'], ['Victor Kristensen', '2A', 'inactive'],
        ];
        $ids = [];
        foreach ($people as [$name, $apt, $status]) {
            $email = strtolower(str_replace(['ø', ' '], ['o', '.'], $name)) . '@example.com';
            if (Db::value('SELECT 1 FROM users WHERE email = ?', [$email])) continue;
            $id = Db::insert("INSERT INTO users (role, status, full_name, username, email, password_hash, apartment_id, created_at, activated_at)
                              VALUES ('resident', ?, ?, ?, ?, ?, ?, ?, ?)",
                [$status, $name, Residents::uniqueUsername($name), $email, $status === 'pending' ? null : $hash, Residents::apartmentId($apt),
                 dt($now->modify('-' . mt_rand(20, 90) . ' days')), $status === 'pending' ? null : dt($now->modify('-' . mt_rand(5, 19) . ' days'))]);
            if ($status === 'active') $ids[] = $id;
            if ($status === 'pending') Residents::sendInvitation($id, false);   // ignored if Mailpit is not running
        }
        if (!$ids) return ['residents' => 0, 'bookings' => 0];
        $zones = array_map(fn($z) => (int) $z['id'], Zones::all());
        $made = 0;

        // Past two weeks: completed sessions at varied times, with real check-in and check-out times.
        $lengths = [30, 45, 60, 60, 60, 75, 90, 120];
        for ($d = 14; $d >= 1; $d--) {
            $date = Clock::today()->modify("-$d days");
            foreach ($ids as $uid) {
                if (mt_rand(1, 100) > 45) continue;
                $start = $date->setTime(mt_rand(6, 21), mt_rand(0, 59));
                $len = $lengths[mt_rand(0, count($lengths) - 1)];
                $end = $start->modify("+$len minutes");
                $cancel = mt_rand(1, 100) <= 12;
                $bid = Db::insert('INSERT IGNORE INTO bookings (user_id, zone_id, booking_date, start_at, end_at, source, status, created_at, cancelled_at, cancelled_by, cancel_reason)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$uid, $zones[mt_rand(0, count($zones) - 1)], $date->format('Y-m-d'), dt($start), dt($end), mt_rand(1, 3) === 1 ? 'walk_in' : 'booking',
                     $cancel ? 'cancelled' : 'completed', dt($start->modify('-' . mt_rand(1, 48) . ' hours')),
                     $cancel ? dt($start->modify('-3 hours')) : null, $cancel ? $uid : null, $cancel ? 'Cancelled by the resident' : null]);
                if ($bid && !$cancel) {
                    $in = $start->modify('+' . mt_rand(0, 8) . ' minutes');
                    $out = mt_rand(1, 3) === 1 ? $end->modify('-' . mt_rand(5, min(25, $len - 10)) . ' minutes') : $end->modify('+' . mt_rand(0, 6) . ' minutes');
                    Db::run('INSERT INTO attendance (booking_id, user_id, checked_in_at, checked_out_at) VALUES (?, ?, ?, ?)', [$bid, $uid, dt($in), dt($out)]);
                }
                $made += $bid ? 1 : 0;
            }
        }

        // Right now: a few residents checked in as walk-ins.
        foreach (array_slice($ids, 3, 5) as $i => $uid) {
            try { Attendance::walkIn($uid, $zones[$i % count($zones)], [45, 60, 90][$i % 3]); $made++; } catch (UserError $e) { }
        }

        // Upcoming bookings over the next two weeks, at any minute, using the normal booking rules.
        for ($d = 0; $d <= 13; $d++) {
            foreach ($ids as $uid) {
                if (mt_rand(1, 100) > ($d < 3 ? 55 : 25)) continue;
                $start = Clock::today()->modify("+$d days")->setTime(mt_rand(6, 21), [0, 5, 10, 15, 20, 30, 40, 45, 50][mt_rand(0, 8)]);
                try { Bookings::create($uid, $zones[mt_rand(0, count($zones) - 1)], dt($start), $lengths[mt_rand(0, count($lengths) - 1)]); $made++; } catch (UserError $e) { }
            }
        }
        // Make the Weights zone busy in about an hour so a "few places left" state is visible.
        $soon = $now->modify('+70 minutes');
        $soon = $soon->setTime((int) $soon->format('H'), 0);
        foreach (array_slice($ids, 0, 5) as $uid) { try { Bookings::create($uid, $zones[1], dt($soon), 60); $made++; } catch (UserError $e) { } }

        // A planned closure next week.
        $day = Clock::today()->modify('+8 days');
        Db::insert('INSERT INTO closures (zone_id, starts_at, ends_at, reason, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$zones[2], dt($day->setTime(6, 0)), dt($day->setTime(10, 0)), 'Annual machine service', $adminId, dt($now)]);
        Schedule::applyChange(fn() => null, true, 'Annual machine service', $adminId);
        return ['residents' => count($people), 'bookings' => $made];
    }
}
