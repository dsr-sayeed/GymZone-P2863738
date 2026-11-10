<?php
/** Counts attempts per action and subject (an email, IP address or user id) within a time window. */
// RateLimiter stops repeated attempts (password guessing, spam). Each attempt is one row in the
// rate_limits table: the action (for example 'login'), the subject (an email, IP address or user id) and the time.
final class RateLimiter
{
    // tooMany(): true if there were already $max attempts for this action and subject in the last $minutes.
    public static function tooMany(string $action, string $subject, int $max, int $minutes): bool
    {
        $since = dt(Clock::now()->modify("-$minutes minutes"));
        $count = (int) Db::value('SELECT COUNT(*) FROM rate_limits WHERE action = ? AND subject = ? AND attempted_at > ?',
            [$action, strtolower($subject), $since]);
        return $count >= $max;
    }

    // hit(): records one attempt now.
    public static function hit(string $action, string $subject): void
    {
        Db::run('INSERT INTO rate_limits (action, subject, attempted_at) VALUES (?, ?, ?)', [$action, strtolower($subject), dt(Clock::now())]);
    }

    // clear(): forgets the attempts, for example after a successful sign-in.
    public static function clear(string $action, string $subject): void
    {
        Db::run('DELETE FROM rate_limits WHERE action = ? AND subject = ?', [$action, strtolower($subject)]);
    }

    /** Throws a friendly error if the limit is reached; otherwise records the attempt. */
    public static function guard(string $action, string $subject, int $max, int $minutes, string $message): void
    {
        // Used where every attempt counts, such as contact messages: refuse when over the limit, otherwise record it.
        if (self::tooMany($action, $subject, $max, $minutes)) throw new UserError($message, 429);
        self::hit($action, $subject);
    }

    // purge(): deletes rows older than two days so the table stays small (run by scripts/maintenance.php).
    public static function purge(): void
    {
        Db::run('DELETE FROM rate_limits WHERE attempted_at < ?', [dt(Clock::now()->modify('-2 days'))]);
    }
}
