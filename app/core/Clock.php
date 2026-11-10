<?php
/** The single source of "now". Tests can fix the time with Clock::set(). */
// Every rule asks Clock::now() instead of the computer clock directly, so the tests can choose the time.
final class Clock
{
    // A fixed time set by the tests; null in normal use.
    private static ?DateTimeImmutable $fixed = null;

    public static function now(): DateTimeImmutable
    {
        // The order: 1. a time fixed with Clock::set() (tests), 2. the GYM_FAKE_NOW environment variable, 3. the real time.
        if (self::$fixed) return self::$fixed;
        $env = getenv('GYM_FAKE_NOW');
        return $env ? new DateTimeImmutable($env) : new DateTimeImmutable('now');
    }

    // set(): used by the tests to travel in time, for example 16 minutes ahead to test the release rule.
    public static function set(?DateTimeImmutable $time): void
    {
        self::$fixed = $time;
    }

    // today(): today's date at 00:00.
    public static function today(): DateTimeImmutable
    {
        return self::now()->setTime(0, 0);
    }
}
