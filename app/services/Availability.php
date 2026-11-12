<?php
/**
 * Availability calculations. Sessions can start at any minute, so the free places for a period are:
 *   available = capacity − the highest number of residents present at the same moment in that period
 * counting bookings not yet checked in and residents checked in. A booking that is checked in is the
 * same row, so each resident is counted once.
 */
// Two kinds of numbers come from this file: the live numbers for the dashboard (overview) and the free
// places for a future period (peak), which the booking rules use.
final class Availability
{
    // The statuses that take a place: booked (not arrived yet) and checked_in (in the gym).
    public const ACTIVE = "('booked','checked_in')";

    /**
     * Sessions of a zone that overlap a period, as [start, end] timestamps. A resident who is still
     * checked in counts at least until now, even after the planned end.
     */
    public static function intervals(int $zoneId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        // Load the zone's active sessions that overlap [from, to). A checked-in resident always counts,
        // even if their planned end has passed.
        $now = Clock::now()->getTimestamp();
        $rows = Db::all('SELECT start_at, end_at, status FROM bookings WHERE zone_id = ? AND status IN ' . self::ACTIVE
            . " AND start_at < ? AND (end_at > ? OR status = 'checked_in')", [$zoneId, dt($to), dt($from)]);
        $out = [];
        // Turn each session into a pair [start, end] of Unix timestamps (seconds), which are easy to compare.
        foreach ($rows as $r) {
            $s = strtotime($r['start_at']);
            $e = strtotime($r['end_at']);
            // Still inside after the planned end: count them until at least one minute from now.
            if ($r['status'] === 'checked_in') $e = max($e, $now + 60);
            if ($e > $from->getTimestamp()) $out[] = [$s, $e];
        }
        return $out;
    }

    /** Highest number of people present at the same moment within [a, b). */
    public static function peakOf(array $intervals, int $a, int $b): int
    {
        // Each session in the period becomes two events: +1 when it starts and -1 when it ends.
        // Parts outside [a, b) are cut off with max() and min().
        $events = [];
        foreach ($intervals as [$s, $e]) {
            if ($s < $b && $e > $a) { $events[] = [max($s, $a), 1]; $events[] = [min($e, $b), -1]; }
        }
        // Sort by time. At the same time, -1 comes before +1, so back-to-back sessions do not overlap.
        usort($events, fn($x, $y) => $x[0] <=> $y[0] ?: $x[1] <=> $y[1]);   // leaving before arriving at the same minute
        // Walk through the events in time order: $n = people present now, $peak = the highest $n seen.
        $n = $peak = 0;
        foreach ($events as [, $d]) { $n += $d; $peak = max($peak, $n); }
        return $peak;
    }

    // peak(): the most people present at the same moment in one zone between $start and $end.
    public static function peak(int $zoneId, DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return self::peakOf(self::intervals($zoneId, $start, $end), $start->getTimestamp(), $end->getTimestamp());
    }

    /** The first moment in [start, end) at which the zone becomes full, or null if it never does. */
    public static function fullFrom(int $zoneId, int $capacity, DateTimeImmutable $start, DateTimeImmutable $end): ?DateTimeImmutable
    {
        // The same walk through the events as peakOf(), but it stops when the count first reaches capacity.
        $a = $start->getTimestamp(); $b = $end->getTimestamp();
        $events = [];
        foreach (self::intervals($zoneId, $start, $end) as [$s, $e]) {
            if ($s < $b && $e > $a) { $events[] = [max($s, $a), 1]; $events[] = [min($e, $b), -1]; }
        }
        usort($events, fn($x, $y) => $x[0] <=> $y[0] ?: $x[1] <=> $y[1]);
        $n = 0;
        foreach ($events as [$t, $d]) {
            $n += $d;
            if ($n >= $capacity) return (new DateTimeImmutable())->setTimestamp($t);
        }
        return null;
    }

    /** 'available', 'limited' (20 % or fewer places left) or 'full'. */
    public static function status(int $available, int $capacity): string
    {
        // 0 places: full. 20 % of capacity or less (at least 1 place): limited. Otherwise: available.
        if ($available <= 0) return 'full';
        return $available <= max(1, (int) floor($capacity * 0.2)) ? 'limited' : 'available';
    }

    /** Who is in or due in each zone right now (for the dashboards). */
    public static function overview(): array
    {
        // The dashboard looks at this minute.
        $now = Clock::now();
        $soon = $now->modify('+1 minute');
        // Is the gym open right now?
        $open = Schedule::isOpenDuring($now, $soon);
        $zones = [];
        // For each zone:
        foreach (Zones::all() as $z) {
            $id = (int) $z['id'];
            // booked = bookings for right now that are not checked in yet (their place is still held),
            $booked = (int) Db::value("SELECT COUNT(*) FROM bookings WHERE zone_id = ? AND status = 'booked' AND start_at <= ? AND end_at > ?", [$id, dt($now), dt($now)]);
            // inside = residents checked in to this zone and not checked out yet.
            $inside = (int) Db::value("SELECT COUNT(*) FROM attendance t JOIN bookings b ON b.id = t.booking_id WHERE b.zone_id = ? AND t.checked_out_at IS NULL", [$id]);
            // Free places now = capacity - booked - inside (never below 0).
            $available = max(0, (int) $z['capacity'] - $booked - $inside);
            // A closed zone or a closure: show the reason instead of a status.
            $closed = $open ? Schedule::closedReason($z, $now, $soon) : 'The gym is closed now';
            $zones[] = ['id' => $id, 'name' => $z['name'], 'description' => $z['description'], 'capacity' => (int) $z['capacity'],
                        'booked' => $booked, 'checked_in' => $inside, 'available' => $available,
                        'status' => $closed ? 'closed' : self::status($available, (int) $z['capacity']), 'closed' => $closed,
                        'notice' => $z['notice'] ?? null];
        }
        // open_now, the next opening if closed, the zones, and the time of this update.
        return ['open_now' => $open, 'next_opening' => $open ? null : self::nextOpening($now), 'zones' => $zones, 'updated' => $now->format('H:i:s')];
    }

    /** "Tomorrow at 06:00" style label for the next time the gym opens. */
    public static function nextOpening(DateTimeImmutable $now): ?string
    {
        // Look at today and the next 7 days for the first opening that has not ended yet.
        for ($i = 0; $i < 8; $i++) {
            $o = Schedule::openingOn($now->modify("+$i day"));
            if ($o && $o[1] > $now) {
                $t = max($o[0], $now);
                $rel = View::relDate($t);
                return (in_array($rel, ['Today', 'Tomorrow'], true) ? strtolower($rel) : 'on ' . $rel) . ' at ' . $t->format('H:i');
            }
        }
        return null;
    }

    // The booking window starts today ...
    public static function windowStart(): DateTimeImmutable
    {
        return Clock::today();
    }

    // ... and ends today + 20 days (booking_window_days = 21 days in total).
    public static function windowEnd(): DateTimeImmutable   // last bookable date
    {
        return Clock::today()->modify('+' . (Settings::int('booking_window_days') - 1) . ' days');
    }

    // inWindow(): is this date inside the 21-day window? The time of day is ignored.
    public static function inWindow(DateTimeImmutable $date): bool
    {
        $d = $date->setTime(0, 0);
        return $d >= self::windowStart() && $d <= self::windowEnd();
    }

    /** The 21-day calendar: one entry per date with state 'open', 'closed' or 'over' (nothing left to book today). */
    public static function calendar(?int $userId = null): array
    {
        // From today to the day after the last bookable date.
        $from = self::windowStart();
        $to = self::windowEnd()->modify('+1 day');
        // A day is "over" when less than 15 minutes of opening time are left today.
        $now = Clock::now();
        $minLen = 15 * 60;
        // How many sessions the resident has on each day (shown as a dot in the calendar).
        $mine = [];
        if ($userId) {
            foreach (Db::all("SELECT booking_date, COUNT(*) AS n FROM bookings WHERE user_id = ? AND status IN ('booked','checked_in','completed')
                               AND booking_date BETWEEN ? AND ? GROUP BY booking_date", [$userId, $from->format('Y-m-d'), self::windowEnd()->format('Y-m-d')]) as $r) {
                $mine[$r['booking_date']] = (int) $r['n'];
            }
        }
        // Closures of the whole gym (zone_id NULL) in the window.
        $wholeGym = array_filter(Schedule::closures($from, $to), fn($c) => $c['zone_id'] === null);
        $days = [];
        // One entry per day: open, closed (closed day or whole-day closure) or over.
        for ($d = $from; $d < $to; $d = $d->modify('+1 day')) {
            $o = Schedule::openingOn($d);
            $state = 'closed';
            if ($o) {
                $state = 'open';
                $coveredAllDay = false;
                foreach ($wholeGym as $c) {
                    if (new DateTimeImmutable($c['starts_at']) <= $o[0] && new DateTimeImmutable($c['ends_at']) >= $o[1]) $coveredAllDay = true;
                }
                if ($coveredAllDay) $state = 'closed';
                elseif ($o[1]->getTimestamp() - max($o[0], $now)->getTimestamp() < $minLen) $state = 'over';
            }
            $days[] = ['date' => $d->format('Y-m-d'), 'day' => (int) $d->format('j'), 'weekday' => $d->format('D'), 'month' => $d->format('M'),
                       'label' => $d->format('l j F'), 'state' => $state, 'hours' => Schedule::hoursLabel($d), 'mine' => $mine[$d->format('Y-m-d')] ?? 0,
                       'today' => $d == $from];
        }
        return $days;
    }

    /**
     * An hour-by-hour view of one day for one zone, to help residents pick a time:
     * for each hour the lowest number of free places during that hour.
     */
    public static function dayOverview(DateTimeImmutable $date, int $zoneId, ?int $userId = null): array
    {
        // The zone and its capacity, and every session that day.
        $zone = Zones::find($zoneId) ?? throw new UserError('Please choose a zone.', 404);
        $date = $date->setTime(0, 0);
        $now = Clock::now();
        $cap = (int) $zone['capacity'];
        $iv = self::intervals($zoneId, $date, $date->modify('+1 day'));
        $hours = [];
        // For each of the 24 hours: past, closed, full, limited or available, with the lowest number of free places.
        for ($h = 0; $h < 24; $h++) {
            $hs = $date->setTime($h, 0);
            $he = $hs->modify('+1 hour');
            // Do not count the minutes of this hour that have already passed.
            $from = max($hs, $now->setTime((int) $now->format('H'), (int) $now->format('i')));
            $item = ['hour' => sprintf('%02d:00', $h), 'state' => 'open', 'free' => null, 'reason' => null];
            if ($he <= $now) { $item['state'] = 'past'; }
            elseif (!Schedule::openIntervals($from, $he)) { $item['state'] = 'closed'; $item['reason'] = 'Gym closed'; }
            elseif ($r = Schedule::closedReason($zone, $from, $he)) {
                $item['state'] = 'closed'; $item['reason'] = $r;
            } else {
                // Free places in this hour = capacity - the highest number present at any moment of the hour.
                $item['free'] = max(0, $cap - self::peakOf($iv, $from->getTimestamp(), $he->getTimestamp()));
                $item['state'] = $item['free'] === 0 ? 'full' : self::status($item['free'], $cap);
            }
            $hours[] = $item;
        }
        // The resident's own sessions that day, shown above the hour grid.
        $mine = [];
        if ($userId) {
            foreach (Db::all("SELECT b.start_at, b.end_at, z.name FROM bookings b JOIN zones z ON z.id = b.zone_id
                               WHERE b.user_id = ? AND b.booking_date = ? AND b.status IN ('booked','checked_in','completed') ORDER BY b.start_at",
                [$userId, $date->format('Y-m-d')]) as $b) {
                $mine[] = $b['name'] . ' ' . View::timeRange($b['start_at'], $b['end_at']);
            }
        }
        $rel = View::relDate($date);
        return ['zone' => ['id' => (int) $zone['id'], 'name' => $zone['name'], 'capacity' => $cap], 'date' => $date->format('Y-m-d'),
                'date_label' => in_array($rel, ['Today', 'Tomorrow'], true) ? $rel . ', ' . $date->format('j F') : $date->format('l j F'),
                'hours_label' => Schedule::hoursLabel($date), 'hours' => $hours, 'mine' => $mine];
    }
}
