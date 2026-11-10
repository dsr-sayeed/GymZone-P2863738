<?php
/** Check-in (with a booking or as a walk-in), check-out, and the automatic check-out after 6 hours. */
// Attendance records what really happened: the check-in and check-out times. A booking is the plan;
// an attendance row is the reality. A walk-in first creates a booking row (source 'walk_in').
final class Attendance
{
    /** [opens, closes] for checking in to a booking. */
    public static function window(array $booking): array
    {
        // Check-in opens checkin_early_minutes (10) before the start and closes checkin_grace_minutes (15)
        // after the start, or at the end of the session if that comes first.
        $start = new DateTimeImmutable($booking['start_at']);
        $end = new DateTimeImmutable($booking['end_at']);
        $opens = $start->modify('-' . Settings::int('checkin_early_minutes') . ' minutes');
        $closes = min($start->modify('+' . Settings::int('checkin_grace_minutes') . ' minutes'), $end);
        return [$opens, $closes];
    }

    // activeFor(): the resident's open attendance row (checked in, not checked out yet), or null.
    public static function activeFor(int $userId): ?array
    {
        return Db::row('SELECT t.*, b.zone_id, b.start_at, b.end_at, b.source, z.name AS zone_name FROM attendance t
                          JOIN bookings b ON b.id = t.booking_id JOIN zones z ON z.id = b.zone_id
                         WHERE t.user_id = ? AND t.checked_out_at IS NULL', [$userId]);
    }

    // Refuse if the resident is already checked in somewhere: one active check-in per resident.
    private static function assertNotCheckedIn(int $userId): void
    {
        if ($a = self::activeFor($userId)) throw new UserError("You are already checked in to {$a['zone_name']}. Please check out first.", 409);
    }

    /** Check in with an existing booking. */
    public static function checkIn(int $userId, int $bookingId): array
    {
        // Everything below happens in one transaction.
        return Db::transaction(function () use ($userId, $bookingId) {
            // Lock the resident row, so two clicks on Check in cannot run at the same time.
            Bookings::lockResident($userId);
            // Load the booking only if it belongs to this resident (b.user_id = ?), and lock it.
            $b = Db::row('SELECT b.*, z.name AS zone_name FROM bookings b JOIN zones z ON z.id = b.zone_id
                           WHERE b.id = ? AND b.user_id = ? FOR UPDATE', [$bookingId, $userId]);
            if (!$b) throw new UserError('Booking not found.', 404);
            // Only a 'booked' booking can be checked in.
            if ($b['status'] === 'checked_in') throw new UserError('You are already checked in to this session.', 409);
            if ($b['status'] !== 'booked') throw new UserError('This booking can no longer be used for check-in.');
            // Is it inside the check-in window?
            [$opens, $closes] = self::window($b);
            $now = Clock::now();
            if ($now < $opens) throw new UserError('Check-in for this session opens ' . strtolower(View::relDate($opens)) . ' at ' . $opens->format('H:i') . '.');
            if ($now >= $closes) throw new UserError('The check-in period for this session has ended.');
            // The zone must not have been closed in the meantime.
            $zone = Zones::find((int) $b['zone_id']);
            if ($r = Schedule::closedReason($zone, new DateTimeImmutable($b['start_at']), new DateTimeImmutable($b['end_at']))) throw new UserError("{$zone['name']} is not available. $r.");
            // Not already checked in somewhere else; then start the visit.
            self::assertNotCheckedIn($userId);
            self::start($b, $userId);
            // Return the booking, now with status checked_in.
            return Bookings::summary(array_merge($b, ['status' => 'checked_in']));
        });
    }

    /**
     * Walk-in: a session that starts at this minute and lasts $minutes (the default length unless the
     * resident chooses another). Allowed when the gym and zone are open for the whole time, a place is
     * free throughout, and the resident has no session in this zone today and none overlapping.
     */
    public static function walkIn(int $userId, int $zoneId, $minutes = null): array
    {
        // The session starts at this exact minute (the seconds are dropped), for example 13:52.
        $now = Clock::now();
        $start = $now->setTime((int) $now->format('H'), (int) $now->format('i'));
        // The length the resident chose, otherwise the suggested length (60 minutes).
        $minutes = Bookings::minutes($minutes ?? Settings::int('session_minutes'));
        $end = $start->modify("+$minutes minutes");
        // Everything below runs in one transaction, with the resident and zone rows locked.
        return Db::transaction(function () use ($userId, $zoneId, $start, $end, $now) {
            Bookings::lockResident($userId);
            $zone = Bookings::lockZone($zoneId);
            // Not already checked in.
            self::assertNotCheckedIn($userId);
            // A resident whose booking for this zone can be checked in now is simply checked in to it.
            $own = Db::row("SELECT * FROM bookings WHERE user_id = ? AND zone_id = ? AND booking_date = ? AND status = 'booked'",
                [$userId, $zoneId, $start->format('Y-m-d')]);
            if ($own) {
                [$opens, $closes] = self::window($own);
                if ($now >= $opens && $now < $closes) return self::checkIn($userId, (int) $own['id']);
            }
            // Check the same rules as a booking, from now to the end of the walk-in (capacity included).
            // true = walk-in wording, for example "Sorry, Weights is full right now."
            Bookings::evaluate($userId, $zone, $start, $end, true);
            // Save the walk-in as a booking row with source 'walk_in' and status 'checked_in' straight away.
            try {
                $id = Db::insert("INSERT INTO bookings (user_id, zone_id, booking_date, start_at, end_at, source, status, created_at)
                                  VALUES (?, ?, ?, ?, ?, 'walk_in', 'checked_in', ?)",
                    [$userId, $zoneId, $start->format('Y-m-d'), dt($start), dt($end), dt(Clock::now())]);
            } catch (PDOException $e) {
                // The unique key refused a second session in this zone today.
                if (Db::isDuplicate($e)) throw new UserError("You have already used {$zone['name']} today.", 409);
                throw $e;
            }
            $b = Bookings::find($id);
            // Record the real check-in time (the booking row already has status checked_in).
            self::start($b, $userId, false);
            return Bookings::summary($b);
        });
    }

    // start(): inserts the attendance row with the check-in time.
    private static function start(array $booking, int $userId, bool $updateBooking = true): void
    {
        try {
            Db::insert('INSERT INTO attendance (booking_id, user_id, checked_in_at) VALUES (?, ?, ?)', [$booking['id'], $userId, dt(Clock::now())]);
        } catch (PDOException $e) {
            // The unique key on active_user refuses a second open visit for the same resident.
            if (Db::isDuplicate($e)) throw new UserError('You are already checked in.', 409);
            throw $e;
        }
        // For a booking (not a walk-in), change its status from 'booked' to 'checked_in'.
        if ($updateBooking) Db::run("UPDATE bookings SET status = 'checked_in' WHERE id = ?", [$booking['id']]);
    }

    /** Checks the resident out now. Returns the session with its actual times. */
    public static function checkOut(int $userId): array
    {
        // Find the resident's open attendance row and lock it.
        $id = Db::transaction(function () use ($userId) {
            $a = Db::row('SELECT * FROM attendance WHERE user_id = ? AND checked_out_at IS NULL FOR UPDATE', [$userId]);
            if (!$a) throw new UserError('You are not checked in.', 409);
            // Save the check-out time and mark the booking completed.
            self::finish($a, Clock::now());
            return (int) $a['booking_id'];
        });
        // Return the session with its real times, for the "You have checked out" page.
        return self::session($id);
    }

    /** An administrator ends a session in progress (for example if a resident forgot to check out). */
    public static function adminCheckOut(int $bookingId): void
    {
        Db::transaction(function () use ($bookingId) {
            $a = Db::row('SELECT * FROM attendance WHERE booking_id = ? AND checked_out_at IS NULL FOR UPDATE', [$bookingId]);
            if (!$a) throw new UserError('This resident is not checked in.', 409);
            self::finish($a, Clock::now());
        });
    }

    // finish(): saves the check-out time and marks the booking completed.
    private static function finish(array $attendance, DateTimeImmutable $at): void
    {
        Db::run('UPDATE attendance SET checked_out_at = ? WHERE id = ?', [dt($at), $attendance['id']]);
        Db::run("UPDATE bookings SET status = 'completed' WHERE id = ?", [$attendance['booking_id']]);
    }

    /** A finished session with planned and actual times. */
    public static function session(int $bookingId): array
    {
        $b = Db::row('SELECT b.*, z.name AS zone_name, t.checked_in_at, t.checked_out_at, t.auto_checked_out, t.checkout_reported_at
                         FROM bookings b JOIN zones z ON z.id = b.zone_id LEFT JOIN attendance t ON t.booking_id = b.id WHERE b.id = ?', [$bookingId]);
        return Bookings::summary($b);
    }

    /**
     * Residents still checked in after auto_checkout_hours (6 by default) are checked out at that time.
     * They are then asked on their dashboard when they really left. Returns the number checked out.
     */
    public static function autoCheckOut(): int
    {
        // The limit: 6 hours ago (setting auto_checkout_hours).
        $hours = Settings::int('auto_checkout_hours');
        $limit = Clock::now()->modify("-$hours hours");
        // Everyone who checked in before the limit and has still not checked out.
        $rows = Db::all('SELECT * FROM attendance WHERE checked_out_at IS NULL AND checked_in_at <= ?', [dt($limit)]);
        foreach ($rows as $a) {
            // Check each one out at check-in time + 6 hours, and mark the check-out as automatic.
            Db::transaction(function () use ($a, $hours) {
                Db::run('UPDATE attendance SET checked_out_at = DATE_ADD(checked_in_at, INTERVAL ? HOUR), auto_checked_out = 1
                          WHERE id = ? AND checked_out_at IS NULL', [$hours, $a['id']]);
                Db::run("UPDATE bookings SET status = 'completed' WHERE id = ?", [$a['booking_id']]);
            });
        }
        // How many residents were checked out.
        return count($rows);
    }

    /** The resident's latest automatic check-out that still needs their real check-out time. */
    public static function pendingReport(int $userId): ?array
    {
        return Db::row('SELECT t.*, z.name AS zone_name FROM attendance t JOIN bookings b ON b.id = t.booking_id JOIN zones z ON z.id = b.zone_id
                         WHERE t.user_id = ? AND t.auto_checked_out = 1 AND t.checkout_reported_at IS NULL ORDER BY t.checked_in_at DESC LIMIT 1', [$userId]);
    }

    /** The resident reports when they really left (HH:MM, between check-in and the automatic check-out). */
    public static function reportCheckOut(int $userId, int $attendanceId, string $time): array
    {
        // The row must belong to this resident and be an automatic check-out.
        $a = Db::row('SELECT * FROM attendance WHERE id = ? AND user_id = ? AND auto_checked_out = 1', [$attendanceId, $userId]);
        if (!$a) throw new UserError('Session not found.', 404);
        if ($a['checkout_reported_at']) throw new UserError('You have already reported your check-out time for this session.');
        // The time must be HH:MM.
        Validator::time($time);
        $in = new DateTimeImmutable($a['checked_in_at']);
        $auto = new DateTimeImmutable($a['checked_out_at']);
        // Build the date and time: the day of the check-in, or the next day if the time is before the check-in.
        [$h, $m] = array_map('intval', explode(':', $time));
        $at = $in->setTime($h % 24, $m);
        if ($at <= $in) $at = $at->modify('+1 day');
        // It must lie between the check-in and the automatic check-out, and not in the future.
        if ($at > $auto || $at > Clock::now()) {
            throw new UserError('Please enter a time between ' . $in->format('H:i') . ' (when you checked in) and ' . $auto->format('H:i') . '.');
        }
        // Save the reported time as the real check-out, and remember that the resident answered.
        Db::run('UPDATE attendance SET checked_out_at = ?, checkout_reported_at = ? WHERE id = ?', [dt($at), dt(Clock::now()), $attendanceId]);
        return self::session((int) $a['booking_id']);
    }

    /** Confirms the automatic time as correct (the resident stayed until then). */
    public static function confirmAutoTime(int $userId, int $attendanceId): void
    {
        Db::run('UPDATE attendance SET checkout_reported_at = ? WHERE id = ? AND user_id = ? AND auto_checked_out = 1 AND checkout_reported_at IS NULL',
            [dt(Clock::now()), $attendanceId, $userId]);
    }

    // activeAll(): everyone in the gym right now, for the admin pages.
    public static function activeAll(): array
    {
        return Db::all('SELECT t.*, b.start_at, b.end_at, b.source, z.name AS zone_name, u.full_name, a.number AS apartment
                          FROM attendance t JOIN bookings b ON b.id = t.booking_id JOIN zones z ON z.id = b.zone_id
                          JOIN users u ON u.id = t.user_id LEFT JOIN apartments a ON a.id = u.apartment_id
                         WHERE t.checked_out_at IS NULL ORDER BY z.sort_order, t.checked_in_at');
    }
}
