<?php
/** Access to configuration and application paths. */
// App reads config/config.php once and gives access to its values.
final class App
{
    // The loaded configuration; null means config.php has not been read yet in this request.
    private static ?array $config = null;

    // config('db.name') walks into nested arrays: ['db']['name']. config() without a key returns everything.
    public static function config(?string $key = null)
    {
        // Load the configuration file the first time it is needed.
        // config.php ends with "return [...]", so require hands back that array.
        if (self::$config === null) {
            self::$config = require dirname(__DIR__, 2) . '/config/config.php';
        }
        if ($key === null) return self::$config;
        $value = self::$config;
        foreach (explode('.', $key) as $part) {
            // A missing key gives null instead of an error.
            $value = $value[$part] ?? null;
        }
        return $value;
    }

    /** Overrides configuration values (used by the tests). */
    public static function override(array $values): void
    {
        self::config();
        // Only the keys passed in are replaced; the other settings stay as they are.
        self::$config = array_replace_recursive(self::$config, $values);
    }

    // root(): the project folder on disk, for example C:\xampp\htdocs\gymzone.
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
