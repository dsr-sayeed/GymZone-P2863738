<?php
/** Gym zones and their capacities. */
// Zones: the gym's areas (Cardio, Weights, Machines) and their capacity.
final class Zones
{
    // all(): every zone, in display order.
    public static function all(): array
    {
        return Db::all('SELECT * FROM zones ORDER BY sort_order, name');
    }

    // find(): one zone by id, or null.
    public static function find(int $id): ?array
    {
        return Db::row('SELECT * FROM zones WHERE id = ?', [$id]);
    }

    /** Highest number of people booked into the zone at the same moment, from now on: ['count', 'start']. */
    public static function peakAllocation(int $zoneId): array
    {
        // Look at the next 60 days (longer than the 21-day booking window).
        $now = Clock::now();
        $iv = Availability::intervals($zoneId, $now, $now->modify('+60 days'));
        // The same +1 / -1 event count as Availability::peakOf(), also remembering when the peak happens.
        $events = [];
        foreach ($iv as [$s, $e]) { $events[] = [max($s, $now->getTimestamp()), 1]; $events[] = [$e, -1]; }
        usort($events, fn($x, $y) => $x[0] <=> $y[0] ?: $x[1] <=> $y[1]);
        $n = $peak = 0; $at = null;
        foreach ($events as [$t, $d]) { $n += $d; if ($n > $peak) { $peak = $n; $at = $t; } }
        return ['count' => $peak, 'start' => $at ? dt((new DateTimeImmutable())->setTimestamp($at)) : null];
    }

    // update(): an administrator changes a zone's name, description or capacity.
    public static function update(int $id, string $name, string $description, int $capacity): void
    {
        $zone = self::find($id) ?? throw new UserError('Zone not found.', 404);
        // Check the new values (capacity from 1 to 500).
        $name = Validator::text($name, 60, 'a zone name');
        $description = Validator::text($description, 255, 'a description', false);
        $capacity = Validator::intRange($capacity, 1, 500, 'Capacity');
        Db::transaction(function () use ($id, $name, $description, $capacity) {
            Db::row('SELECT id FROM zones WHERE id = ? FOR UPDATE', [$id]);   // waits for bookings in progress
            // The new capacity may not be lower than the most people already booked at the same time.
            $peak = self::peakAllocation($id);
            if ($capacity < $peak['count']) {
                $rel = View::relDate($peak['start']);
                $when = in_array($rel, ['Today', 'Tomorrow'], true) ? strtolower($rel) : 'on ' . $rel;
                throw new UserError("The capacity cannot be lower than {$peak['count']}: that many people are booked at the same time $when at "
                    . View::date($peak['start'], 'H:i') . '. Cancel bookings first if the capacity must be reduced.');
            }
            // Zone names are unique (uq_zones_name), so a name that is taken is refused with a message.
            try {
                Db::run('UPDATE zones SET name = ?, description = ?, capacity = ? WHERE id = ?', [$name, $description, $capacity, $id]);
            } catch (PDOException $e) {
                if (Db::isDuplicate($e)) throw new UserError('Another zone already has this name.');
                throw $e;
            }
        });
    }

    /** Temporarily closes a zone until reopened, or reopens it. Upcoming bookings are cancelled after confirmation. */
    public static function setOpen(int $id, bool $open, string $note, bool $confirmed, int $adminId): int
    {
        $zone = self::find($id) ?? throw new UserError('Zone not found.', 404);
        $note = Validator::text($note, 255, 'a reason', !$open);
        return Schedule::applyChange(fn() => Db::run('UPDATE zones SET is_open = ?, closed_note = ? WHERE id = ?', [$open ? 1 : 0, $open ? null : $note, $id]),
            $confirmed, $open ? '' : "{$zone['name']} is temporarily closed: $note", $adminId);
    }
}
