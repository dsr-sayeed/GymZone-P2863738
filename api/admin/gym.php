<?php
/** Administrator gym management (JSON, POST): zones, opening hours, sessions, closures and check-in rules. */
require __DIR__ . '/../../app/bootstrap.php';
// Only administrators (residents get 403), with the CSRF check.
$admin = Auth::requireApi('admin');
if (!Http::isPost()) Http::fail('Method not allowed.', 405);
Http::api(function () use ($admin) {
    $aid = (int) $admin['id'];
    // confirm = true is sent by core.js after the administrator agreed to cancel the affected bookings.
    $confirm = !empty(Http::input()['confirm']);
    // A sentence such as " 2 bookings were cancelled ..." for the success message.
    $cancelled = fn(int $n) => $n ? " $n booking" . ($n === 1 ? ' was' : 's were') . ' cancelled and the residents notified.' : '';
    switch (Http::str('action')) {
        case 'zone_update':
            // Change the name, description or capacity (the capacity cannot go below the current bookings).
            Zones::update(Http::int('id'), Http::str('name'), Http::str('description'), Http::int('capacity'));
            Http::ok(['message' => 'Zone saved. Availability has been recalculated.']);
        case 'zone_close':
            // Close a zone (asks first if bookings are affected).
            $n = Zones::setOpen(Http::int('id'), false, Http::str('note'), $confirm, $aid);
            View::flash('success', 'The zone is closed until you reopen it.' . $cancelled($n));
            Http::ok();
        case 'zone_open':
            // Reopen a zone.
            Zones::setOpen(Http::int('id'), true, '', true, $aid);
            View::flash('success', 'The zone is open again.');
            Http::ok();
        case 'hours':
            // Save the weekly opening hours.
            $days = Http::input()['days'] ?? [];
            if (!is_array($days)) throw new UserError('Opening hours are missing.');
            $n = Schedule::saveHours($days, $confirm, $aid);
            View::flash('success', 'Opening hours saved.' . $cancelled($n));
            Http::ok();
        case 'closure_add':
            // Plan a closure; an empty zone_id means the whole gym.
            $zone = Http::str('zone_id') === '' ? null : Http::int('zone_id');
            $start = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', Http::str('starts_at'));
            $end = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', Http::str('ends_at'));
            if (!$start || !$end) throw new UserError('Please enter the start and end of the closure.');
            $n = Schedule::addClosure($zone, $start, $end, Http::str('reason'), $confirm, $aid);
            View::flash('success', 'Closure added.' . $cancelled($n));
            Http::ok();
        case 'closure_delete':
            // Remove a closure.
            Schedule::deleteClosure(Http::int('id'));
            View::flash('success', 'Closure removed. Those sessions can be booked again.');
            Http::ok();
        case 'rules':
            // Booking rules: each value is checked against a minimum and a maximum.
            $max = Validator::intRange(Http::str('max_session_minutes'), 15, 360, 'The longest session');
            $def = Validator::intRange(Http::str('session_minutes'), 15, $max, 'The suggested length');
            if ($def % 5 || $max % 15) throw new UserError('Use 5-minute steps for the suggested length and 15-minute steps for the longest session.');
            $early = Validator::intRange(Http::str('checkin_early_minutes'), 0, 60, 'Early check-in');
            $grace = Validator::intRange(Http::str('checkin_grace_minutes'), 5, 60, 'The check-in period');
            $auto = Validator::intRange(Http::str('auto_checkout_hours'), 1, 12, 'Automatic check-out');
            // The automatic check-out must not come before the longest possible session has ended.
            if ($auto * 60 < $max) throw new UserError('The automatic check-out must not be earlier than the end of the longest session.');
            Settings::set(['session_minutes' => $def, 'max_session_minutes' => $max, 'checkin_early_minutes' => $early,
                           'checkin_grace_minutes' => $grace, 'auto_checkout_hours' => $auto]);
            Http::ok(['message' => 'Rules saved.']);
        default:
            throw new UserError('Unknown action.', 400);
    }
});
