<?php
/**
 * Opening hours and closures. Residents choose any start minute and a duration, so instead of fixed
 * sessions the schedule answers: "is the gym open for the whole period from start to end?".
 * The default is open around the clock; a session may continue past midnight if both days are open.
 */
// Used by the booking rules (is the gym open?), the dashboard and the admin Gym page.
final class Schedule
{
    // ISO weekday numbers: 1 = Monday ... 7 = Sunday (the same numbers as in operating_hours).
    public const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    private static ?array $hours = null;

    // hours(): the 7 rows of operating_hours, read once per request and kept in memory.
    public static function hours(): array
    {
        if (self::$hours === null) {
            self::$hours = [];
            foreach (Db::all('SELECT * FROM operating_hours ORDER BY weekday') as $r) self::$hours[(int) $r['weekday']] = $r;
        }
        return self::$hours;
    }

    // forgetCache(): read the hours again after an administrator has changed them.
    public static function forgetCache(): void
    {
        self::$hours = null;
    }

    // minutes(): "06:30" becomes 390 (minutes after midnight).
    public static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        return $h * 60 + $m;
    }

    /** Opening period of one date, or null if closed that day. */
    public static function openingOn(DateTimeImmutable $date): ?array
    {
        $date = $date->setTime(0, 0);
        // The weekday of this date (N = 1 for Monday ... 7 for Sunday) picks the row.
        $h = self::hours()[(int) $date->format('N')] ?? null;
        if (!$h || !$h['is_open']) return null;
        // The opening and closing as full date-times on this date, for example 2026-11-03 00:00 and 2026-11-04 00:00.
        return [$date->modify('+' . self::minutes($h['open_time']) . ' minutes'), $date->modify('+' . self::minutes($h['close_time']) . ' minutes')];
    }

    /** Open periods between $from and $to, with back-to-back periods (for example across midnight) joined. */
    public static function openIntervals(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $out = [];
        // Start one day earlier, so an opening that began yesterday and runs past midnight is included.
        for ($d = $from->setTime(0, 0)->modify('-1 day'); $d <= $to; $d = $d->modify('+1 day')) {
            $o = self::openingOn($d);
            if (!$o || $o[1] <= $from || $o[0] >= $to) continue;
            // If this period starts where the previous one ended (for example at midnight), join them into one.
            if ($out && end($out)[1] >= $o[0]) { $out[count($out) - 1][1] = max(end($out)[1], $o[1]); }
            else $out[] = $o;
        }
        return $out;
    }

    /** True if the gym is open for the whole period. */
    public static function isOpenDuring(DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        // Open during the whole session = one open period covers it from start to end.
        foreach (self::openIntervals($start, $end) as [$a, $b]) {
            if ($a <= $start && $b >= $end) return true;
        }
        return false;
    }

    /** Short text such as "06:00–22:00" or "Open 24 hours" for a date, or null when closed. */
    public static function hoursLabel(DateTimeImmutable $date): ?string
    {
        $o = self::openingOn($date);
        if (!$o) return null;
        $mins = ($o[1]->getTimestamp() - $o[0]->getTimestamp()) / 60;
        return $mins >= 1440 ? 'Open 24 hours' : View::timeRange(dt($o[0]), dt($o[1]));
    }

    /** Closures overlapping a period. $zoneId: only closures affecting that zone (its own or whole-gym). */
    public static function closures(DateTimeImmutable $from, DateTimeImmutable $to, ?int $zoneId = null): array
    {
        // Closures that overlap [from, to): they start before "to" and end after "from".
        $sql = 'SELECT c.*, z.name AS zone_name FROM closures c LEFT JOIN zones z ON z.id = c.zone_id
                 WHERE c.starts_at < ? AND c.ends_at > ?';
        $params = [dt($to), dt($from)];
        // For one zone: closures of the whole gym (zone_id NULL) or of this zone.
        if ($zoneId !== null) { $sql .= ' AND (c.zone_id IS NULL OR c.zone_id = ?)'; $params[] = $zoneId; }
        return Db::all($sql . ' ORDER BY c.starts_at', $params);
    }

    /** Why a zone is unavailable during a period (zone closed or a closure), or null if it is open. */
    public static function closedReason(array $zone, DateTimeImmutable $start, DateTimeImmutable $end): ?string
    {
        // The zone itself has been closed by an administrator.
        if (!$zone['is_open']) return $zone['closed_note'] ? 'Closed: ' . $zone['closed_note'] : 'Temporarily closed';
        // Or a planned closure covers part of the period.
        $c = self::closures($start, $end, (int) $zone['id']);
        if (!$c) return null;
        return 'Closed ' . View::timeRange($c[0]['starts_at'], $c[0]['ends_at']) . ': ' . $c[0]['reason'];
    }

    /**
     * Applies an administrator change. Upcoming bookings that no longer fit (outside the opening hours,
     * zone closed, closure) are cancelled, but only after the administrator confirms; until then the
     * change is rolled back and ConfirmationRequired is thrown. Affected residents are emailed.
     */
    public static function applyChange(callable $change, bool $confirmed, string $reason, int $adminId): int
    {
        // Make the change inside a transaction, so it can still be undone.
        $cancelled = Db::transaction(function () use ($change, $confirmed, $reason, $adminId) {
            $change();
            self::forgetCache();
            // After the change: which upcoming bookings no longer fit?
            $affected = self::bookingsThatNoLongerFit();
            // Some do, and the administrator has not confirmed yet: throw ConfirmationRequired. Throwing rolls the
            // transaction back, so nothing is saved. core.js then shows a dialog and sends the request again with confirm = true.
            if ($affected && !$confirmed) {
                $n = count($affected);
                throw new ConfirmationRequired($n, $n === 1
                    ? 'This change affects 1 upcoming booking. It will be cancelled and the resident will be notified by email.'
                    : "This change affects $n upcoming bookings. They will be cancelled and the residents will be notified by email.");
            }
            // Confirmed: cancel the affected bookings.
            foreach ($affected as $b) Bookings::cancelForOperations((int) $b['id'], $adminId, $reason);
            return $affected;
        });
        self::forgetCache();
        // After the commit: email every affected resident (a failed email does not undo anything).
        foreach ($cancelled as $b) Bookings::notifyCancelled($b, $reason);
        return count($cancelled);
    }

    // bookingsThatNoLongerFit(): every upcoming 'booked' session that is now outside the opening hours,
    // in a closed zone, or inside a closure.
    public static function bookingsThatNoLongerFit(): array
    {
        $rows = Db::all("SELECT b.*, z.name AS zone_name, z.is_open, z.closed_note, u.email, u.full_name
                           FROM bookings b JOIN zones z ON z.id = b.zone_id JOIN users u ON u.id = b.user_id
                          WHERE b.status = 'booked' AND b.start_at > ?", [dt(Clock::now())]);
        $out = [];
        foreach ($rows as $b) {
            $start = new DateTimeImmutable($b['start_at']);
            $end = new DateTimeImmutable($b['end_at']);
            $zone = ['id' => $b['zone_id'], 'is_open' => $b['is_open'], 'closed_note' => $b['closed_note']];
            if (!self::isOpenDuring($start, $end) || self::closedReason($zone, $start, $end)) $out[] = $b;
        }
        return $out;
    }

    /* ---------- Administrator changes ---------- */

    /** $days: weekday => ['is_open' => bool, 'open' => 'HH:MM', 'close' => 'HH:MM'] (24:00 allowed as closing time). */
    public static function saveHours(array $days, bool $confirmed, int $adminId): int
    {
        // Check all seven days first; nothing is changed unless every day is valid.
        $clean = [];
        foreach (self::WEEKDAYS as $wd => $name) {
            $d = $days[$wd] ?? null;
            if (!$d) throw new UserError("Opening hours for $name are missing.");
            $open = Validator::time((string) $d['open']);
            $close = Validator::time((string) $d['close']);
            if ($open === '24:00') throw new UserError("$name: the opening time must be before 24:00.");
            if (self::minutes($close) <= self::minutes($open)) throw new UserError("$name: the closing time must be after the opening time.");
            $clean[$wd] = [!empty($d['is_open']) ? 1 : 0, $open, $close];
        }
        // Then save all seven rows through applyChange(), which asks first if bookings are affected.
        return self::applyChange(function () use ($clean) {
            foreach ($clean as $wd => [$isOpen, $open, $close]) {
                Db::run('UPDATE operating_hours SET is_open = ?, open_time = ?, close_time = ? WHERE weekday = ?', [$isOpen, $open, $close, $wd]);
            }
        }, $confirmed, 'The gym opening hours have changed.', $adminId);
    }

    // addClosure(): closes the whole gym ($zoneId = null) or one zone for a period, with a reason.
    public static function addClosure(?int $zoneId, DateTimeImmutable $start, DateTimeImmutable $end, string $reason, bool $confirmed, int $adminId): int
    {
        $reason = Validator::text($reason, 255, 'a reason for the closure');
        if ($end <= $start) throw new UserError('The closure must end after it starts.');
        if ($end <= Clock::now()) throw new UserError('The closure is entirely in the past.');
        if ($zoneId !== null && !Zones::find($zoneId)) throw new UserError('Please choose a zone.');
        return self::applyChange(fn() => Db::insert('INSERT INTO closures (zone_id, starts_at, ends_at, reason, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$zoneId, dt($start), dt($end), $reason, $adminId, dt(Clock::now())]), $confirmed, $reason, $adminId);
    }

    // deleteClosure(): removes a closure. Bookings that were cancelled because of it are not restored.
    public static function deleteClosure(int $id): void
    {
        Db::run('DELETE FROM closures WHERE id = ?', [$id]);
    }

    // upcomingClosures(): closures that have not ended yet, for the admin pages.
    public static function upcomingClosures(): array
    {
        return Db::all('SELECT c.*, z.name AS zone_name FROM closures c LEFT JOIN zones z ON z.id = c.zone_id
                         WHERE c.ends_at > ? ORDER BY c.starts_at', [dt(Clock::now())]);
    }
}
