<?php
/**
 * Single-use, expiring tokens for links sent by email. The link carries 32 random bytes (64 hex
 * characters); the database stores only their SHA-256 hash, so a database leak does not reveal
 * usable links. Issuing a new token of the same kind invalidates the earlier ones.
 */
// Used for account activation (24 h), email change (24 h), password change (60 min) and
// password reset (60 min, stored in the password_resets table).
final class Tokens
{
    public const ACTIVATION_HOURS = 24;
    public const EMAIL_CHANGE_HOURS = 24;
    public const PASSWORD_CHANGE_MINUTES = 60;
    public const RESET_MINUTES = 60;

    // make(): a new random token. random_bytes(32) gives 32 unpredictable bytes, and bin2hex() writes them
    // as 64 characters for the link. Only the SHA-256 hash of the token is stored.
    private static function make(): array
    {
        $raw = bin2hex(random_bytes(32));
        return [$raw, hash('sha256', $raw)];
    }

    // issue(): creates a link token for one purpose and returns the raw token for the email.
    public static function issue(int $userId, string $purpose, int $minutes, array $extra = []): string
    {
        [$raw, $hash] = self::make();
        $now = Clock::now();
        // Earlier unused links of the same purpose stop working (they are marked as used now).
        Db::run('UPDATE verification_tokens SET consumed_at = ? WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL', [dt($now), $userId, $purpose]);
        // Store only the hash, the expiry time and, for changes, the new email or the new password's hash.
        Db::insert('INSERT INTO verification_tokens (user_id, purpose, token_hash, new_email, new_password_hash, expires_at, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, $purpose, $hash, $extra['new_email'] ?? null, $extra['new_password_hash'] ?? null, dt($now->modify("+$minutes minutes")), dt($now)]);
        return $raw;
    }

    /** Looks a link up. Returns ['state' => 'valid'|'invalid'|'used'|'expired', 'row' => …]. Unknown links are rate limited per IP. */
    public static function lookup(string $raw, string $purpose, string $table = 'verification_tokens'): array
    {
        $ip = Http::clientIp();
        // More than 20 bad links from this IP address in 15 minutes: refuse (this stops guessing).
        if (RateLimiter::tooMany('token_ip', $ip, 20, 15)) throw new UserError('Too many attempts with invalid links. Please wait 15 minutes.', 429);
        $row = null;
        // A real token is exactly 64 hex characters; anything else is invalid without asking the database.
        if (preg_match('/^[a-f0-9]{64}$/', $raw)) {
            // Find the row by the HASH of the token, because only the hash is stored.
            $sql = $table === 'password_resets'
                ? 'SELECT * FROM password_resets WHERE token_hash = ?'
                : 'SELECT * FROM verification_tokens WHERE token_hash = ? AND purpose = ?';
            $row = Db::row($sql, $table === 'password_resets' ? [hash('sha256', $raw)] : [hash('sha256', $raw), $purpose]);
        }
        // Not found: count it as a bad attempt.
        if (!$row) { RateLimiter::hit('token_ip', $ip); return ['state' => 'invalid', 'row' => null]; }
        // Found: it is used, expired, or still valid.
        if ($row['consumed_at']) return ['state' => 'used', 'row' => $row];
        if (new DateTimeImmutable($row['expires_at']) <= Clock::now()) return ['state' => 'expired', 'row' => $row];
        return ['state' => 'valid', 'row' => $row];
    }

    /** Marks a token as used. Returns false if it was used in the meantime (two clicks at once). */
    public static function consume(int $id, string $table = 'verification_tokens'): bool
    // Only the two table names written here can be used, never a value typed by a user.
    {
        $table = $table === 'password_resets' ? 'password_resets' : 'verification_tokens';
        // "AND consumed_at IS NULL" makes this succeed only once, even if the link is clicked twice at the same time.
        return Db::run("UPDATE $table SET consumed_at = ? WHERE id = ? AND consumed_at IS NULL", [dt(Clock::now()), $id])->rowCount() === 1;
    }

    // discard(): deletes unused tokens, for example when the confirmation email could not be sent.
    public static function discard(int $userId, string $purpose): void
    {
        Db::run('DELETE FROM verification_tokens WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL', [$userId, $purpose]);
    }

    // issueReset(): the same idea for forgotten-password links, stored in password_resets.
    public static function issueReset(int $userId, string $ip): string
    {
        [$raw, $hash] = self::make();
        $now = Clock::now();
        Db::run('UPDATE password_resets SET consumed_at = ? WHERE user_id = ? AND consumed_at IS NULL', [dt($now), $userId]);
        Db::insert('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at, requested_ip) VALUES (?, ?, ?, ?, ?)',
            [$userId, $hash, dt($now->modify('+' . self::RESET_MINUTES . ' minutes')), dt($now), $ip]);
        return $raw;
    }

    // message(): a friendly text for a link that is used, expired or invalid.
    public static function message(string $state, string $what): string
    {
        return match ($state) {
            'used'    => "This $what link has already been used.",
            'expired' => "This $what link has expired.",
            default   => "This $what link is not valid. Please check that you copied the whole link.",
        };
    }
}
