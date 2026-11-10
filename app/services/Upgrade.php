<?php
/**
 * Brings a database created by an earlier GymZone version up to date. It runs at the start of each
 * request, but only does work when the stored schema_version is lower than the current one.
 *
 * Version 2: sessions start at any minute (time_slots table removed, open 24 hours by default),
 * automatic check-out after 6 hours with a reported check-out time, and contact messages.
 */
final class Upgrade
{
    public const VERSION = 2;

    public static function run(): void
    {
        // The stored version; 0 if this database has never been upgraded.
        $version = (int) Db::value("SELECT value FROM settings WHERE name = 'schema_version'");
        if ($version >= self::VERSION) return;

        // From version 1 to version 2:
        if ($version < 2) {
            $old = Schedule::hours();
            Installer::installSchema(false);   // creates contact_messages and adds new settings
            // Add two new columns to attendance if they are missing (information_schema lists the existing columns).
            foreach (['auto_checked_out' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER checked_out_at',
                      'checkout_reported_at' => 'DATETIME NULL AFTER auto_checked_out'] as $col => $def) {
                $exists = Db::value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                                      AND TABLE_NAME = 'attendance' AND COLUMN_NAME = ?", [$col]);
                if (!$exists) Db::pdo()->exec("ALTER TABLE attendance ADD COLUMN $col $def");
            }
            // The fixed one-hour slots of the first version are no longer used.
            Db::pdo()->exec('DROP TABLE IF EXISTS time_slots');
            // The first version opened 06–22 on weekdays and 08–20 at weekends. If those were never
            // changed, switch to the new default: open around the clock.
            $unchanged = count($old) === 7;
            foreach ($old as $wd => $h) {
                $expect = $wd <= 5 ? ['06:00', '22:00'] : ['08:00', '20:00'];
                if (!$h['is_open'] || substr($h['open_time'], 0, 5) !== $expect[0] || substr($h['close_time'], 0, 5) !== $expect[1]) $unchanged = false;
            }
            if ($unchanged) Db::run("UPDATE operating_hours SET is_open = 1, open_time = '00:00', close_time = '24:00'");
            // Use the first administrator's email as the contact address if none is set.
            if (Settings::all(true)['contact_email'] === '') {
                $email = Db::value("SELECT email FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id LIMIT 1");
                if ($email) Settings::set(['contact_email' => $email]);
            }
        }
        // Remember that this database is now up to date.
        Settings::set(['schema_version' => self::VERSION]);
        Schedule::forgetCache();
    }
}
