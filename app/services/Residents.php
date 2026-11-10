<?php
/** Resident accounts managed by housing administrators. There is no public registration. */
// Administrators create residents (status 'pending'), and each resident activates the account from an
// email link. Administrators can also edit, deactivate, reactivate and delete residents.
final class Residents
{
    // apartmentId(): finds the apartment row for a number such as "2B", or creates it.
    public static function apartmentId(string $number): int
    {
        $number = Validator::apartment($number);
        $id = Db::value('SELECT id FROM apartments WHERE number = ?', [$number]);
        return $id ? (int) $id : Db::insert('INSERT INTO apartments (number, created_at) VALUES (?, ?)', [$number, dt(Clock::now())]);
    }

    /** A unique username suggestion such as "anna.jensen" or "anna.jensen2". */
    public static function uniqueUsername(string $fullName): string
    {
        // Turn the name into plain letters in lower case (Danish æ, ø and å become ae, o and aa).
        $ascii = strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', strtr($fullName, ['æ' => 'ae', 'Æ' => 'ae', 'ø' => 'o', 'Ø' => 'o', 'å' => 'aa', 'Å' => 'aa'])));
        // Split it into words and use the first and the last: "Anna Marie Jensen" becomes "anna.jensen".
        $parts = array_values(array_filter(preg_split('/[^a-z0-9]+/', $ascii)));
        $base = $parts ? ($parts[0] . (count($parts) > 1 ? '.' . end($parts) : '')) : 'resident';
        $base = substr(str_pad($base, 3, '0'), 0, 24);
        $name = $base;
        // If that name is taken, try anna.jensen2, anna.jensen3 and so on.
        for ($i = 2; Db::value('SELECT 1 FROM users WHERE username = ?', [$name]); $i++) $name = $base . $i;
        return $name;
    }

    /**
     * Creates a pending resident and emails an activation link (valid 24 hours, single use).
     * Returns ['id', 'email_sent', 'mail_error'].
     */
    public static function create(string $fullName, string $apartment, string $email, int $adminId): array
    {
        // Check the input first.
        $fullName = Validator::fullName($fullName);
        $email = Validator::email($email);
        Validator::apartment($apartment);
        // In a transaction: refuse an email that is already used, then insert the resident as 'pending' (no password yet).
        $id = Db::transaction(function () use ($fullName, $apartment, $email) {
            if (Db::value('SELECT 1 FROM users WHERE email = ?', [$email])) throw new UserError('This email address is already registered.');
            try {
                return Db::insert("INSERT INTO users (role, status, full_name, username, email, apartment_id, created_at)
                                   VALUES ('resident', 'pending', ?, ?, ?, ?, ?)",
                    [$fullName, self::uniqueUsername($fullName), $email, self::apartmentId($apartment), dt(Clock::now())]);
            } catch (PDOException $e) {
                // The unique key on email is the final guard if two administrators add the same email at the same moment.
                if (Db::isDuplicate($e, 'uq_users_email')) throw new UserError('This email address is already registered.');
                throw $e;
            }
        });
        // Send the invitation email; the result says whether it was sent.
        return ['id' => $id] + self::sendInvitation($id, false);
    }

    /** Issues a fresh activation link (earlier ones stop working) and emails it. */
    public static function sendInvitation(int $userId, bool $reminder): array
    {
        $u = Db::row('SELECT * FROM users WHERE id = ?', [$userId]) ?? throw new UserError('Resident not found.', 404);
        // Only pending accounts get invitations.
        if ($u['status'] !== 'pending') throw new UserError('Invitations can only be sent to accounts that are waiting for activation.');
        // A new 24-hour activation token (earlier links stop working).
        $raw = Tokens::issue($userId, 'activation', Tokens::ACTIVATION_HOURS * 60);
        // Email the link. If Mailpit cannot be reached, report it instead of failing (the account is kept).
        try {
            Mailer::send($u['email'], $u['full_name'], EmailTemplates::invitation($u['full_name'], Http::absoluteUrl('activate.php?token=' . $raw), Tokens::ACTIVATION_HOURS, $reminder));
            return ['email_sent' => true, 'mail_error' => null];
        } catch (MailException $e) {
            error_log('GymZone: invitation not sent: ' . $e->getMessage());
            return ['email_sent' => false, 'mail_error' => $e->getMessage()];
        }
    }

    // resendInvitation(): at most 5 invitations per resident per hour.
    public static function resendInvitation(int $userId, int $adminId): array
    {
        RateLimiter::guard('invite', (string) $userId, 5, 60, 'Five invitations have been sent to this resident in the last hour. Please wait before sending another.');
        return self::sendInvitation($userId, true);
    }

    /** Administrators can correct the name and apartment, and the email while the account is still pending. */
    public static function update(int $id, string $fullName, string $apartment, ?string $email): void
    {
        $u = self::find($id) ?? throw new UserError('Resident not found.', 404);
        // Check the name, and find or create the apartment.
        $fullName = Validator::fullName($fullName);
        $aptId = self::apartmentId($apartment);
        $fields = ['full_name = ?', 'apartment_id = ?'];
        $params = [$fullName, $aptId];
        // The email can only be corrected while the account is still pending. After activation, residents change it
        // themselves, with a confirmation by email.
        if ($email !== null && strtolower(trim($email)) !== $u['email']) {
            if ($u['status'] !== 'pending') throw new UserError('Residents change their own email address after activation (Profile → Change email).');
            $email = Validator::email($email);
            if (Db::value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $id])) throw new UserError('This email address is already registered.');
            $fields[] = 'email = ?';
            $params[] = $email;
            Tokens::discard($id, 'activation');   // old link was sent to the wrong address
        }
        $params[] = $id;
        // The UPDATE is built from fixed column names; the values are still parameters.
        Db::run('UPDATE users SET ' . implode(', ', $fields) . " WHERE id = ? AND role = 'resident'", $params);
        // Apartments with no residents left are removed.
        self::removeUnusedApartments();
    }

    // contact(): the office's contact details, used in emails.
    private static function contact(): string
    {
        $s = Settings::all();
        return trim($s['contact_email'] . ($s['contact_phone'] !== '' ? ' or ' . $s['contact_phone'] : ''));
    }

    // notify(): sends an email. A failure is only logged, because the change has already been made.
    private static function notify(array $u, array $message): void
    {
        try { Mailer::send($u['email'], $u['full_name'], $message); }
        catch (MailException $e) { error_log('GymZone: account email not sent: ' . $e->getMessage()); }
    }

    /**
     * Deletes a resident account with its bookings and workout history. The resident is emailed first.
     * Used by the resident (Settings → Delete account) and by administrators.
     */
    public static function delete(int $id, bool $byResident): void
    {
        // The account must exist and be a resident (administrators cannot be deleted here).
        $u = Db::row("SELECT * FROM users WHERE id = ? AND role = 'resident'", [$id]) ?? throw new UserError('Resident not found.', 404);
        // Delete in the right order inside a transaction: attendance and bookings first (their foreign keys use
        // RESTRICT), then rate-limit rows, then the user. Tokens and reset links go automatically (ON DELETE CASCADE).
        Db::transaction(function () use ($id) {
            Db::row('SELECT id FROM users WHERE id = ? FOR UPDATE', [$id]);
            Db::run('DELETE FROM attendance WHERE user_id = ?', [$id]);
            Db::run('DELETE FROM bookings WHERE user_id = ?', [$id]);
            Db::run('DELETE FROM rate_limits WHERE subject IN (?, ?)', [(string) $id, strtolower(Db::value('SELECT email FROM users WHERE id = ?', [$id]))]);
            Db::run('DELETE FROM users WHERE id = ?', [$id]);   // tokens, resets: ON DELETE CASCADE; messages keep the text without the link
            self::removeUnusedApartments();
        });
        // Email the resident after the deletion.
        self::notify($u, EmailTemplates::accountDeleted($u['full_name'], $byResident, self::contact()));
    }

    /** 'inactive' deactivates (cancels upcoming bookings, ends a session in progress); 'active' restores access. */
    public static function setStatus(int $id, string $status, int $adminId): string
    {
        $u = self::find($id) ?? throw new UserError('Resident not found.', 404);
        // Deactivate:
        if ($status === 'inactive') {
            if ($u['status'] === 'inactive') return 'inactive';
            // In a transaction: block sign-in and sign out every device (session_version + 1),
            Db::transaction(function () use ($id, $adminId) {
                Db::run("UPDATE users SET status = 'inactive', session_version = session_version + 1 WHERE id = ?", [$id]);
                // cancel the upcoming bookings,
                Db::run("UPDATE bookings SET status = 'cancelled', cancelled_at = ?, cancelled_by = ?, cancel_reason = 'Account deactivated'
                          WHERE user_id = ? AND status = 'booked'", [dt(Clock::now()), $adminId, $id]);
                // check the resident out if they are in the gym,
                $a = Db::row('SELECT * FROM attendance WHERE user_id = ? AND checked_out_at IS NULL', [$id]);
                if ($a) Attendance::adminCheckOut((int) $a['booking_id']);
                // and make every open email link unusable.
                Db::run('UPDATE verification_tokens SET consumed_at = ? WHERE user_id = ? AND consumed_at IS NULL', [dt(Clock::now()), $id]);
                Db::run('UPDATE password_resets SET consumed_at = ? WHERE user_id = ? AND consumed_at IS NULL', [dt(Clock::now()), $id]);
            });
            // Email the resident.
            self::notify($u, EmailTemplates::accountDeactivated($u['full_name'], self::contact()));
            return 'inactive';
        }
        // Reactivate: back to 'active', or to 'pending' if no password was ever set.
        if ($status === 'active') {
            if ($u['status'] !== 'inactive') return $u['status'];
            $new = $u['has_password'] ? 'active' : 'pending';
            Db::run('UPDATE users SET status = ? WHERE id = ?', [$new, $id]);
            if ($new === 'active') self::notify($u, EmailTemplates::accountReactivated($u['full_name'], Http::absoluteUrl('login.php')));
            return $new;
        }
        throw new UserError('Unknown status.');
    }

    // find(): one resident with the apartment, whether a password is set, and when the invitation expires.
    public static function find(int $id): ?array
    {
        return Db::row("SELECT u.id, u.full_name, u.username, u.email, u.status, u.created_at, u.activated_at, u.last_login_at,
                               (u.password_hash IS NOT NULL) AS has_password, a.number AS apartment,
                               (SELECT MAX(expires_at) FROM verification_tokens v WHERE v.user_id = u.id AND v.purpose = 'activation' AND v.consumed_at IS NULL) AS invite_expires
                          FROM users u JOIN apartments a ON a.id = u.apartment_id WHERE u.id = ? AND u.role = 'resident'", [$id]);
    }

    // search(): residents that match a search text and a status, with their number of upcoming bookings.
    public static function search(string $q, string $status): array
    {
        $sql = "SELECT u.id, u.full_name, u.username, u.email, u.status, u.created_at, u.last_login_at, a.number AS apartment,
                       (SELECT COUNT(*) FROM bookings b WHERE b.user_id = u.id AND b.status IN ('booked','checked_in') AND b.end_at > ?) AS upcoming,
                       (SELECT MAX(expires_at) FROM verification_tokens v WHERE v.user_id = u.id AND v.purpose = 'activation' AND v.consumed_at IS NULL) AS invite_expires
                  FROM users u JOIN apartments a ON a.id = u.apartment_id WHERE u.role = 'resident'";
        $p = [dt(Clock::now())];
        // Search in name, email, username and apartment. addcslashes() escapes % and _ so they are searched as
        // normal characters, not as LIKE wildcards.
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $sql .= ' AND (u.full_name LIKE ? OR u.email LIKE ? OR u.username LIKE ? OR a.number LIKE ?)';
            array_push($p, $like, $like, $like, $like);
        }
        if (in_array($status, ['pending', 'active', 'inactive'], true)) { $sql .= ' AND u.status = ?'; $p[] = $status; }
        return Db::all($sql . ' ORDER BY a.number, u.full_name LIMIT 500', $p);
    }

    // recentBookings(): the resident's last 10 sessions (admin resident page).
    public static function recentBookings(int $id): array
    {
        return Db::all('SELECT b.*, z.name AS zone_name FROM bookings b JOIN zones z ON z.id = b.zone_id
                         WHERE b.user_id = ? ORDER BY b.start_at DESC LIMIT 10', [$id]);
    }

    // counts(): totals for the admin overview. SUM(status = 'active') counts the rows where it is true.
    public static function counts(): array
    {
        $r = Db::row("SELECT COUNT(*) AS total, SUM(status = 'active') AS active, SUM(status = 'pending') AS pending, SUM(status = 'inactive') AS inactive
                         FROM users WHERE role = 'resident'");
        return array_map('intval', $r);
    }

    // apartmentNumbers(): suggestions for the apartment field.
    public static function apartmentNumbers(): array
    {
        return array_column(Db::all('SELECT number FROM apartments ORDER BY number'), 'number');
    }

    // Deletes apartments that no resident uses any more.
    private static function removeUnusedApartments(): void
    {
        Db::run('DELETE a FROM apartments a LEFT JOIN users u ON u.apartment_id = a.id WHERE u.id IS NULL');
    }
}
