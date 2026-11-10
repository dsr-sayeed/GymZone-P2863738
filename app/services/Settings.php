<?php
/** Booking rules stored in the settings table. */
final class Settings
{
    // Default values. A row in the settings table overrides the default. Administrators change the rules
    // on the Gym page (api/admin/gym.php, action 'rules').
    public const DEFAULTS = [
        'session_minutes'        => '60',   // suggested length of a session (walk-ins use it too)
        'max_session_minutes'    => '240',  // longest session a resident can book
        'checkin_early_minutes'  => '10',   // check-in opens this many minutes before a booked start
        'checkin_grace_minutes'  => '15',   // an unused booking is released this many minutes after its start
        'booking_window_days'    => '21',   // residents can book today and the next 20 days
        'auto_checkout_hours'    => '6',    // residents still checked in after this long are checked out automatically
        'contact_email'          => '',     // shown on the Help page; contact messages are emailed here
        'contact_phone'          => '',
        'contact_hours'          => '',
        'schema_version'         => '2',
    ];
    // The settings, loaded once per request.
    private static ?array $cache = null;

    // all(): the defaults first, then overwritten by the rows in the settings table.
    public static function all(bool $refresh = false): array
    {
        if (self::$cache === null || $refresh) {
            self::$cache = self::DEFAULTS;
            foreach (Db::all('SELECT name, value FROM settings') as $r) self::$cache[$r['name']] = $r['value'];
        }
        return self::$cache;
    }

    // int(): one setting as a whole number, for example Settings::int('checkin_grace_minutes') gives 15.
    public static function int(string $name): int
    {
        return (int) self::all()[$name];
    }

    // set(): saves values. ON DUPLICATE KEY UPDATE inserts a new row, or updates the row that exists.
    public static function set(array $values): void
    {
        foreach ($values as $k => $v) {
            Db::run('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$k, (string) $v]);
        }
        self::all(true);
    }

    // forget(): clears the memory, so the next read loads from the database.
    public static function forget(): void
    {
        self::$cache = null;
    }
}
