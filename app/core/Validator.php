<?php
/** Input validation shared by forms and API endpoints. Each method returns the cleaned value or throws UserError. */
// Validator checks and cleans what users type. Each method returns the clean value, or throws a
// UserError whose message is shown to the user. Used by forms, API endpoints and services.
final class Validator
{
    // Full name: spaces trimmed and joined, at most 100 characters; letters in any language, spaces, - ' and .
    public static function fullName(string $v): string
    {
        // Replace runs of spaces with one space.
        $v = preg_replace('/\s+/u', ' ', trim($v));
        if ($v === '' || mb_strlen($v) > 100) throw new UserError('Please enter a full name (up to 100 characters).');
        // \p{L} means "a letter in any language", so names such as Søren or Zoë are accepted.
        if (!preg_match("/^[\p{L}\p{M}][\p{L}\p{M}'’.\- ]*$/u", $v)) throw new UserError('The full name can only contain letters, spaces, hyphens and apostrophes.');
        return $v;
    }

    // Email: lower-cased, at most 190 characters, and a valid format according to PHP's email filter.
    public static function email(string $v): string
    {
        $v = strtolower(trim($v));
        if (mb_strlen($v) > 190 || !filter_var($v, FILTER_VALIDATE_EMAIL)) throw new UserError('Please enter a valid email address.');
        return $v;
    }

    // Apartment: upper-cased without spaces, so "2b" becomes "2B". Letters, digits, "-", "/" and ".".
    public static function apartment(string $v): string
    {
        $v = strtoupper(preg_replace('/\s+/', '', trim($v)));
        if (!preg_match('/^[A-Z0-9][A-Z0-9.\/-]{0,19}$/', $v)) throw new UserError('Apartment numbers can use letters, digits, "-", "/" and "." (up to 20 characters), for example 2B or 14-3.');
        return $v;
    }

    // Username: 3 to 30 letters, digits, dots, hyphens and underscores, starting with a letter or digit.
    public static function username(string $v): string
    {
        $v = trim($v);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,29}$/', $v)) {
            throw new UserError('Usernames are 3 to 30 characters: letters, digits, dots, hyphens and underscores, starting with a letter or digit.');
        }
        return $v;
    }

    // New password: 10 to 200 characters, letters plus a digit or symbol, typed the same twice.
    public static function newPassword(string $password, string $confirm): string
    {
        if (strlen($password) < 10) throw new UserError('Your password must be at least 10 characters long.');
        if (strlen($password) > 200) throw new UserError('Your password is too long.');
        // [0-9\W_] means a digit, or any character that is not a letter or digit (a symbol).
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9\W_]/', $password)) {
            throw new UserError('Use a mix of letters and at least one number or symbol in your password.');
        }
        if ($password !== $confirm) throw new UserError('The two passwords do not match.');
        return $password;
    }

    // Date in the form 2026-11-03.
    public static function date(string $v): DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        // createFromFormat() quietly turns 2026-02-31 into 3 March, so the result is compared with the input.
        if (!$d || $d->format('Y-m-d') !== $v) throw new UserError('Please choose a valid date.');
        return $d;
    }

    // Time in 24-hour form HH:MM; 24:00 is allowed as a closing time.
    public static function time(string $v): string
    {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$|^24:00$/', $v)) throw new UserError('Times must use the 24-hour HH:MM format.');
        return $v;
    }

    // A whole number from $min to $max; $label names the field in the error message.
    public static function intRange($v, int $min, int $max, string $label): int
    {
        if (filter_var($v, FILTER_VALIDATE_INT) === false || (int) $v < $min || (int) $v > $max) {
            throw new UserError("$label must be a whole number from $min to $max.");
        }
        return (int) $v;
    }

    // Any text: trimmed, required unless $required is false, and at most $max characters.
    public static function text(string $v, int $max, string $label, bool $required = true): string
    {
        $v = trim($v);
        if ($required && $v === '') throw new UserError("Please enter $label.");
        if (mb_strlen($v) > $max) throw new UserError(ucfirst($label) . " can be at most $max characters.");
        return $v;
    }
}
