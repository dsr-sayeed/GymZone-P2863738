<?php
/** PDO connection and small query helpers. All queries use prepared statements. */
// Every database query in GymZone goes through this class. Each method uses a prepared statement:
// the SQL text and the values are sent separately, so a value can never change the SQL (no SQL injection).
final class Db
{
    // The one database connection for this request (created the first time it is needed).
    private static ?PDO $pdo = null;

    // connect(): opens a PDO connection to MySQL with the settings in config/config.php.
    // $withDatabase = false is used by the installer, before the database exists.
    public static function connect(bool $withDatabase = true): PDO
    {
        $c = App::config('db');
        // DSN = the connection address. utf8mb4 is full Unicode (æ, ø, å and emoji are stored correctly).
        $dsn = "mysql:host={$c['host']};port={$c['port']};charset=utf8mb4" . ($withDatabase ? ";dbname={$c['name']}" : '');
        // PDO options:
        return new PDO($dsn, $c['user'], $c['pass'], [
            // throw an exception when a query fails, instead of failing silently,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            // return rows as arrays with column names, for example $row['email'],
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // use real prepared statements inside MySQL (not imitated by PHP).
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    // pdo(): returns the shared connection and connects on first use (??= means "assign if still null").
    public static function pdo(): PDO
    {
        return self::$pdo ??= self::connect();
    }

    // use(): lets the installer and the tests switch to another connection.
    public static function use(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    // run(): prepares the SQL with ? placeholders, then executes it with the values. Example:
    // Db::run('UPDATE users SET username = ? WHERE id = ?', ['anna', 7]);
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    // row(): the first row of the result, or null if there is none.
    public static function row(string $sql, array $params = []): ?array
    {
        // fetch() returns false when there is no row; ?: turns that into null.
        return self::run($sql, $params)->fetch() ?: null;
    }

    // all(): every row of the result, as a list of arrays.
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    // value(): the first column of the first row, for example a COUNT(*); null if there is no row.
    public static function value(string $sql, array $params = [])
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    // insert(): runs an INSERT and returns the new row's id (the AUTO_INCREMENT number).
    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /** Runs $work inside a transaction (or inside the one already open) and returns its result. */
    public static function transaction(callable $work)
    {
        // Example: Db::transaction(function () { several queries }); they all succeed together, or none do.
        $pdo = self::pdo();
        // Already inside a transaction (for example walkIn() calling checkIn()): just run the work inside it.
        if ($pdo->inTransaction()) return $work();
        // Start the transaction, run the work, and save everything with commit().
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            // Something failed: undo every change since beginTransaction(), then pass the error on.
            // Re-throwing lets the caller (for example an API file) report the error to the user.
            $pdo->rollBack();
            throw $e;
        }
    }

    /** True if the exception is a unique-key violation, optionally on a specific key. */
    public static function isDuplicate(Throwable $e, string $key = ''): bool
    {
        // MySQL error 1062 means "duplicate entry for a unique key". $key narrows it to one key name,
        // for example 'uq_bookings_one_zone_per_day'.
        return $e instanceof PDOException && ($e->errorInfo[1] ?? 0) == 1062
            && ($key === '' || str_contains($e->getMessage(), $key));
    }
}

/** Formats a date-time for MySQL DATETIME columns. */
// Example: dt(Clock::now()) gives '2026-10-27 14:30:00', ready to pass as a ? value.
function dt(DateTimeInterface $d): string
{
    return $d->format('Y-m-d H:i:s');
}
