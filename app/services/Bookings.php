<?php
/**
 * Booking rules (all checked on the server, inside a transaction):
 *  - a session starts at any minute and lasts 15 minutes up to the maximum length (4 hours by default);
 *  - it must start today or within the next 20 days, in the future, and lie inside the opening hours;
 *  - the zone must be open and not affected by a closure;
 *  - one session per zone per resident per day (also a unique key in the database);
 *  - a resident's sessions must not overlap;
 *  - at no moment during the session may the zone hold more people than its capacity.
 * The resident row and the zone row are locked (SELECT … FOR UPDATE), so two requests for the last
 * place are handled one after the other.
 */
// How to read this file: create() is the main entry point. It checks the time (validatePeriod), locks the
// resident and zone rows, checks every rule (evaluate) and inserts the row, all in one transaction.
final class Bookings
{
    // The shortest session a resident can book or walk in for.
    public const MIN_MINUTES = 15;

    // parseStart(): reads a start such as "2026-11-03 13:52" into a date object.
    public static function parseStart(string $value): DateTimeImmutable
    {
        // Accept both "2026-11-03 13:52" and the browser's "2026-11-03T13:52".
        $value = str_replace('T', ' ', trim($value));
        foreach (['!Y-m-d H:i:s', '!Y-m-d H:i'] as $f) {
            $d = DateTimeImmutable::createFromFormat($f, $value);
            // Keep the hours and minutes, drop any seconds.
            if ($d) return $d->setTime((int) $d->format('H'), (int) $d->format('i'));
        }
        throw new UserError('Please choose a start time.');
    }

    // minutes(): checks the length: from 15 minutes up to max_session_minutes (240 by default).
    public static function minutes($value): int
    {
        $max = Settings::int('max_session_minutes');
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < self::MIN_MINUTES || (int) $value > $max) {
            throw new UserError('A session can last from ' . self::MIN_MINUTES . ' minutes to ' . View::duration($max) . '.');
        }
        $m = (int) $value;
        // Lengths must be multiples of 5 minutes.
        if ($m % 5) throw new UserError('Please choose the session length in steps of 5 minutes.');
        return $m;
    }

    // find(): one booking with its zone name, the resident's name and email, and the apartment number.
    public static function find(int $id): ?array
    {
        return Db::row('SELECT b.*, z.name AS zone_name, u.full_name, u.email, u.username, a.number AS apartment
                          FROM bookings b JOIN zones z ON z.id = b.zone_id JOIN users u ON u.id = b.user_id
                          LEFT JOIN apartments a ON a.id = u.apartment_id WHERE b.id = ?', [$id]);
    }

    /** Locks the resident's row; throws if the account cannot book. */
    public static function lockResident(int $userId): array
    {
        // FOR UPDATE locks this user row until the transaction ends, so a second request for the same resident
        // waits here. This stops one resident from booking twice at the same moment (for example in two tabs).
        $u = Db::row('SELECT * FROM users WHERE id = ? FOR UPDATE', [$userId]);
        // Only active residents can book (not administrators, and not pending or deactivated accounts).
        if (!$u || $u['role'] !== 'resident' || $u['status'] !== 'active') throw new UserError('Only active resident accounts can book sessions.', 403);
        return $u;
    }

    // lockZone(): locks the zone row. Two residents booking the same zone at the same moment are handled one
    // after the other, so the second one sees the first one's booking (no overbooking).
    public static function lockZone(int $zoneId): array
    {
        // "?? throw" means: if no zone was found, refuse with a message.
        return Db::row('SELECT * FROM zones WHERE id = ? FOR UPDATE', [$zoneId]) ?? throw new UserError('Please choose a zone.', 404);
    }

    // when(): text such as "today at 13:52–14:52" for messages.
    public static function when(string $start, string $end): string
    {
        $rel = View::relDate($start);
        return (in_array($rel, ['Today', 'Tomorrow', 'Yesterday'], true) ? strtolower($rel) : 'on ' . $rel) . ' at ' . View::timeRange($start, $end);
    }

    /** "Today, 21 October", "Tomorrow, 22 October" or "Friday 23 October 2026". */
    public static function dateLabel(string $start): string
    {
        $rel = View::relDate($start);
        return in_array($rel, ['Today', 'Tomorrow', 'Yesterday'], true) ? $rel . ', ' . View::date($start, 'j F') : View::date($start, 'l j F Y');
    }

    /** Throws if the gym is not open for the whole period, with the reason. */
    private static function assertOpen(DateTimeImmutable $start, DateTimeImmutable $end): void
    {
        // Open for the whole session: nothing to explain.
        if (Schedule::isOpenDuring($start, $end)) return;
        // Otherwise find out why: the gym closes during the session, or it is closed at the start time.
        foreach (Schedule::openIntervals($start, $end) as [$a, $b]) {
            if ($a <= $start && $b > $start) {
                throw new UserError('The gym closes at ' . $b->format('H:i') . ', before this session would end. Please choose a shorter session or an earlier time.');
            }
        }
        $h = Schedule::hoursLabel($start);
        throw new UserError('The gym is closed at ' . $start->format('H:i') . ' on ' . $start->format('l j F') . ($h ? " (opening hours: $h)" : '') . '.');
    }

    /**
     * All rules for a resident using a zone from $start to $end. Returns the number of places free
     * during the period. $walkIn changes the wording of the "full" message.
     */
    public static function evaluate(int $userId, array $zone, DateTimeImmutable $start, DateTimeImmutable $end, bool $walkIn = false): int
    {
        // RULE 1: the gym must be open from the start to the end (see Schedule::isOpenDuring).
        self::assertOpen($start, $end);
        // RULE 2: the zone must not be closed, and no planned closure may cover the session.
        if ($r = Schedule::closedReason($zone, $start, $end)) throw new UserError("{$zone['name']} is not available during this time. $r.");
        // RULE 3: one session per zone per resident per day. Look for another booked, checked-in or completed
        // session in this zone on this date. The database enforces the same rule with a unique key
        // (uq_bookings_one_zone_per_day in database/schema.sql); this check gives the friendly message.
        $same = Db::row("SELECT start_at, end_at, status FROM bookings WHERE user_id = ? AND zone_id = ? AND booking_date = ?
                           AND status IN ('booked','checked_in','completed')", [$userId, $zone['id'], $start->format('Y-m-d')]);
        if ($same) {
            throw new UserError($same['status'] === 'completed'
                ? "You have already trained in {$zone['name']} " . strtolower(View::relDate($start)) . '. You can use each zone once per day.'
                : "You already have a {$zone['name']} session " . self::when($same['start_at'], $same['end_at']) . '. You can use each zone once per day.');
        }
        // RULE 4: the resident's sessions may not overlap. Two periods overlap when one starts before the other
        // ends and ends after the other starts. A checked-in session counts until the resident checks out.
        $overlap = Db::row("SELECT b.start_at, b.end_at, b.status, z.name FROM bookings b JOIN zones z ON z.id = b.zone_id
                             WHERE b.user_id = ? AND b.status IN ('booked','checked_in') AND b.start_at < ?
                               AND (b.end_at > ? OR b.status = 'checked_in')", [$userId, dt($end), dt($start)]);
        if ($overlap) {
            throw new UserError($overlap['status'] === 'checked_in'
                ? "You are checked in to {$overlap['name']}. Please check out first."
                : "You already have a {$overlap['name']} session " . View::timeRange($overlap['start_at'], $overlap['end_at']) . '. Sessions cannot overlap; choose another time or a shorter session.');
        }
        // RULE 5: capacity. peak = the most people present at the same moment during the session
        // (bookings not yet checked in plus residents checked in). If the peak already equals capacity, it is full.
        $cap = (int) $zone['capacity'];
        $peak = Availability::peak((int) $zone['id'], $start, $end);
        if ($peak >= $cap) {
            // Find the first moment it becomes full, to suggest a shorter session.
            $full = Availability::fullFrom((int) $zone['id'], $cap, $start, $end) ?? $start;
            // Full from the very start: refuse.
            if ($full <= $start) {
                throw new UserError($walkIn ? "Sorry, {$zone['name']} is full right now." : "Sorry, {$zone['name']} is fully booked at " . $start->format('H:i') . '. Please choose another time.', 409);
            }
            // Full only later: say from when, and suggest a session that ends then (if it is at least 15 minutes).
            $mins = (int) (($full->getTimestamp() - $start->getTimestamp()) / 60);
            throw new UserError("{$zone['name']} is full from " . $full->format('H:i') . '. '
                . ($mins >= self::MIN_MINUTES ? 'You can choose a shorter session, until ' . $full->format('H:i') . ' (' . View::duration($mins) . ').' : 'Please choose another time.'), 409);
        }
        // Every rule passed: return the number of free places during the session.
        return $cap - $peak;
    }

    /** Checks a requested session without booking it (for the live check on the booking page). */
    public static function check(int $userId, int $zoneId, string $startValue, $minutes): array
    {
        // The same rules as create(), but nothing is saved. The booking page calls this while the resident
        // chooses a time (booking.js, doCheck()).
        try {
            [$start, $end] = self::validatePeriod($startValue, $minutes);
            $zone = Zones::find($zoneId) ?? throw new UserError('Please choose a zone.');
            $free = self::evaluate($userId, $zone, $start, $end);
            return ['ok' => true, 'available' => $free, 'capacity' => (int) $zone['capacity'], 'start' => dt($start), 'end' => dt($end),
                    'label' => View::timeRange(dt($start), dt($end)), 'duration' => View::duration((int) $minutes)];
        } catch (UserError $e) {
            // A broken rule becomes {ok: false, reason: "..."} for the page to show.
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }

    /** Validates the start and length of a booking. */
    private static function validatePeriod(string $startValue, $minutes): array
    {
        // The start time and length must be valid.
        $start = self::parseStart($startValue);
        $minutes = self::minutes($minutes);
        // Only today and the next 20 days can be booked (booking_window_days = 21).
        if (!Availability::inWindow($start)) throw new UserError('You can book today and up to ' . (Settings::int('booking_window_days') - 1) . ' days ahead.');
        // A start time in the past cannot be booked; to train now, residents use Check in on the dashboard.
        if ($start <= Clock::now()) throw new UserError('This time has already passed. To train now, use "Check in" on the dashboard.');
        // Returns [start, end]; the end is the start plus the chosen minutes.
        return [$start, $start->modify("+$minutes minutes")];
    }

    // create(): makes a booking. Called by api/bookings.php (action "create").
    public static function create(int $userId, int $zoneId, string $startValue, $minutes): array
    {
        // 1. Check the time and length before opening the transaction.
        [$start, $end] = self::validatePeriod($startValue, $minutes);
        // 2. Everything below runs in one transaction: all of it is saved, or nothing is.
        $id = Db::transaction(function () use ($userId, $zoneId, $start, $end) {
            // 3. Lock the resident row, then the zone row (always in this order, so two requests never deadlock).
            self::lockResident($userId);
            $zone = self::lockZone($zoneId);
            // 4. Check every rule. A broken rule throws a UserError, and the transaction is rolled back.
            self::evaluate($userId, $zone, $start, $end);
            // 5. Insert the booking with status 'booked'.
            try {
                return Db::insert("INSERT INTO bookings (user_id, zone_id, booking_date, start_at, end_at, source, status, created_at)
                                   VALUES (?, ?, ?, ?, ?, 'booking', 'booked', ?)",
                    [$userId, $zoneId, $start->format('Y-m-d'), dt($start), dt($end), dt(Clock::now())]);
            } catch (PDOException $e) {
                // 6. If two requests passed the check at the same moment, the unique key in the database refuses the
                // second row. Turn that database error into a clear message.
                if (Db::isDuplicate($e, 'uq_bookings_one_zone_per_day')) throw new UserError("You already have a {$zone['name']} session on this day.");
                throw $e;
            }
        });
        // 7. Return the booking in the form the page shows (see summary()).
        return self::summary(self::find($id));
    }

    /** Fields shown to residents. With attendance data, includes the actual time in the gym. */
    public static function summary(array $b): array
    {
        // The fields sent to the browser for one booking (no internal columns).
        $out = ['id' => (int) $b['id'], 'zone' => $b['zone_name'], 'zone_id' => (int) $b['zone_id'], 'status' => $b['status'], 'source' => $b['source'] ?? 'booking',
                'date' => self::dateLabel($b['start_at']), 'time' => View::timeRange($b['start_at'], $b['end_at']),
                'duration' => View::duration(View::minutesBetween($b['start_at'], $b['end_at'])), 'start' => $b['start_at'], 'end' => $b['end_at']];
        // With attendance data: add the real times, for example "In the gym 13:52–14:20".
        if (!empty($b['checked_in_at'])) {
            $out['checked_in'] = View::date($b['checked_in_at'], 'H:i');
            if (!empty($b['checked_out_at'])) {
                $out['actual'] = View::timeRange($b['checked_in_at'], $b['checked_out_at']);
                $out['actual_duration'] = View::duration(View::minutesBetween($b['checked_in_at'], $b['checked_out_at']));
                $out['auto'] = !empty($b['auto_checked_out']);
                $out['reported'] = !empty($b['checkout_reported_at']);
            }
        }
        return $out;
    }

    // cancel(): the resident cancels one of their own upcoming bookings (api/bookings.php, action "cancel").
    public static function cancel(int $userId, int $bookingId): void
    {
        Db::transaction(function () use ($userId, $bookingId) {
            // "AND user_id = ?" means a resident can only find, and so only cancel, their own booking.
            // FOR UPDATE locks the row while it is changed.
            $b = Db::row('SELECT * FROM bookings WHERE id = ? AND user_id = ? FOR UPDATE', [$bookingId, $userId]);
            if (!$b) throw new UserError('Booking not found.', 404);
            // Only status 'booked' can be cancelled; otherwise explain why not.
            if ($b['status'] !== 'booked') {
                throw new UserError(match ($b['status']) {
                    'checked_in' => 'You are checked in to this session. Use Check out instead.',
                    'cancelled' => 'This booking is already cancelled.',
                    default => 'Only upcoming bookings can be cancelled.',
                });
            }
            // Mark it cancelled. The place is free at once, and day_key becomes NULL, so the day is free again.
            Db::run("UPDATE bookings SET status = 'cancelled', cancelled_at = ?, cancelled_by = ?, cancel_reason = 'Cancelled by the resident'
                      WHERE id = ?", [dt(Clock::now()), $userId, $bookingId]);
        });
    }

    /** Cancellation by an administrator (or by a schedule change). Only bookings not yet checked in. */
    public static function cancelForOperations(int $bookingId, int $adminId, string $reason): void
    {
        // Only rows that are still 'booked' change, so a session in progress is never cancelled by mistake.
        Db::run("UPDATE bookings SET status = 'cancelled', cancelled_at = ?, cancelled_by = ?, cancel_reason = ?
                  WHERE id = ? AND status = 'booked'", [dt(Clock::now()), $adminId, mb_substr($reason, 0, 255), $bookingId]);
    }

    // adminCancel(): the housing office cancels a booking with a reason, and the resident is emailed.
    public static function adminCancel(int $bookingId, int $adminId, string $reason): void
    {
        $reason = Validator::text($reason, 255, 'a reason');
        $b = self::find($bookingId) ?? throw new UserError('Booking not found.', 404);
        if ($b['status'] !== 'booked') throw new UserError('Only upcoming bookings can be cancelled. To end a session in progress, use "Check out".');
        self::cancelForOperations($bookingId, $adminId, $reason);
        self::notifyCancelled($b, $reason);
    }

    /** Emails the resident about a cancellation made by the housing office. A failed email does not undo the change. */
    public static function notifyCancelled(array $b, string $reason): void
    {
        try {
            Mailer::send($b['email'], $b['full_name'], EmailTemplates::bookingCancelled($b['full_name'], $b['zone_name'],
                self::dateLabel($b['start_at']), View::timeRange($b['start_at'], $b['end_at']), $reason));
        } catch (MailException $e) {
            error_log('GymZone: cancellation email not sent: ' . $e->getMessage());
        }
    }

    // The SELECT used by the lists below: bookings with zone names and, if any, the real attendance times.
    private const WITH_ATTENDANCE = 'SELECT b.*, z.name AS zone_name, t.checked_in_at, t.checked_out_at, t.auto_checked_out, t.checkout_reported_at
                                       FROM bookings b JOIN zones z ON z.id = b.zone_id LEFT JOIN attendance t ON t.booking_id = b.id';

    /** The resident's bookings grouped for "My bookings". */
    public static function forResident(int $userId): array
    {
        $now = dt(Clock::now());
        // $q runs one query for this resident with an extra WHERE condition and an ORDER BY.
        $q = fn(string $where, string $order, array $p = []) => Db::all(self::WITH_ATTENDANCE . " WHERE b.user_id = ? AND $where ORDER BY $order LIMIT 100", array_merge([$userId], $p));
        // Three lists: upcoming (booked and not yet ended, or checked in now), completed, and cancelled or released.
        return [
            'upcoming'  => array_map([self::class, 'summary'], $q("(b.status = 'checked_in' OR (b.status = 'booked' AND b.end_at > ?))", 'b.start_at', [$now])),
            'completed' => array_map([self::class, 'summary'], $q("b.status = 'completed'", 'b.start_at DESC')),
            // Cancelled and released bookings get a note that explains why.
            'cancelled' => array_map(fn($b) => self::summary($b) + ['note' => $b['status'] === 'released'
                ? 'Not checked in, so the place was released'
                : ((int) $b['cancelled_by'] === $userId ? 'Cancelled by you' : 'Cancelled by the housing office' . ($b['cancel_reason'] ? ': ' . $b['cancel_reason'] : ''))],
                $q("b.status IN ('cancelled','released')", 'b.start_at DESC')),
        ];
    }

    /** The resident's next booking that has not been checked in yet. */
    public static function nextFor(int $userId): ?array
    {
        // The next booking that is still 'booked' and has not ended yet.
        $b = Db::row("SELECT b.*, z.name AS zone_name FROM bookings b JOIN zones z ON z.id = b.zone_id
                       WHERE b.user_id = ? AND b.status = 'booked' AND b.end_at > ? ORDER BY b.start_at LIMIT 1", [$userId, dt(Clock::now())]);
        if (!$b) return null;
        // Can the resident check in right now? Only inside the check-in window (see Attendance::window()).
        [$opens, $closes] = Attendance::window($b);
        $now = Clock::now();
        return self::summary($b) + ['can_check_in' => $now >= $opens && $now < $closes, 'check_in_opens' => $opens->format('H:i'),
                                    'check_in_opens_label' => View::relDate($opens) . ' at ' . $opens->format('H:i')];
    }

    /** Administrator list with filters. */
    public static function adminList(string $date, ?int $zoneId, string $status): array
    {
        // The admin list for one date, optionally filtered by zone and status.
        $sql = 'SELECT b.*, z.name AS zone_name, u.full_name, u.username, a.number AS apartment,
                       t.checked_in_at, t.checked_out_at, t.auto_checked_out, t.checkout_reported_at
                  FROM bookings b JOIN zones z ON z.id = b.zone_id JOIN users u ON u.id = b.user_id
                  LEFT JOIN apartments a ON a.id = u.apartment_id LEFT JOIN attendance t ON t.booking_id = b.id
                 WHERE b.booking_date = ?';
        $p = [$date];
        // Each filter adds "AND ... = ?"; the values are still parameters.
        if ($zoneId) { $sql .= ' AND b.zone_id = ?'; $p[] = $zoneId; }
        // The status filter is used only if it is one of the five known statuses.
        if (in_array($status, ['booked', 'checked_in', 'completed', 'cancelled', 'released'], true)) { $sql .= ' AND b.status = ?'; $p[] = $status; }
        return Db::all($sql . ' ORDER BY b.start_at, z.sort_order, u.full_name', $p);
    }
}
