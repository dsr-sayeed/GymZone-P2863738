<?php
/** Account activation, password recovery and the resident's own profile and security changes. */
// Account: everything a resident does with their own account. Links are made with Tokens and emailed
// with Mailer; nothing changes until the resident opens the link.
final class Account
{
    /** Sets the first password from an invitation link and activates the account. */
    public static function activate(string $raw, string $password, string $confirm): array
    {
        // The link must be valid: it exists, is not used and has not expired.
        $t = Tokens::lookup($raw, 'activation');
        if ($t['state'] !== 'valid') throw new UserError(Tokens::message($t['state'], 'activation') . ' Ask the housing office to send you a new invitation.', 410);
        // The new password must be strong enough and typed the same twice.
        $password = Validator::newPassword($password, $confirm);
        // In a transaction: lock the user, check the account is still pending, use up the token, then save.
        return Db::transaction(function () use ($t, $password) {
            $u = Db::row('SELECT * FROM users WHERE id = ? FOR UPDATE', [$t['row']['user_id']]);
            if (!$u || $u['status'] !== 'pending') throw new UserError('This account cannot be activated. Please contact the housing office.', 409);
            // consume() returns false if the link was used in the meantime (for example a double click).
            if (!Tokens::consume((int) $t['row']['id'])) throw new UserError(Tokens::message('used', 'activation'), 410);
            // Save the bcrypt hash of the password (never the password itself) and activate the account.
            Db::run("UPDATE users SET password_hash = ?, status = 'active', activated_at = ? WHERE id = ?",
                [password_hash($password, PASSWORD_DEFAULT), dt(Clock::now()), $u['id']]);
            return $u;
        });
    }

    /**
     * Forgotten password. The answer is the same whether or not the email is registered, so the
     * form cannot be used to find out who has an account. Limited to 3 requests per email and
     * 10 per IP address per hour.
     */
    public static function requestPasswordReset(string $email, string $ip): void
    {
        // Check the email format, then the limits: 10 requests per IP address per hour,
        $email = Validator::email($email);
        RateLimiter::guard('reset_ip', $ip, 10, 60, 'Too many requests. Please wait an hour and try again.');
        // and 3 per email per hour. Over the email limit we stop quietly, with the same answer as a success.
        if (RateLimiter::tooMany('reset_email', $email, 3, 60)) return;
        RateLimiter::hit('reset_email', $email);
        // Only active accounts can reset. For unknown emails nothing is sent, but the page looks the same.
        $u = Db::row("SELECT * FROM users WHERE email = ? AND status = 'active'", [$email]);
        if (!$u) return;
        // Create a 60-minute reset link and email it.
        $raw = Tokens::issueReset((int) $u['id'], $ip);
        try {
            Mailer::send($u['email'], $u['full_name'], EmailTemplates::passwordReset($u['full_name'], Http::absoluteUrl('reset-password.php?token=' . $raw), Tokens::RESET_MINUTES));
        } catch (MailException $e) {
            // If the email cannot be sent, log it; the visitor still sees the same neutral answer.
            error_log('GymZone: password reset email not sent: ' . $e->getMessage());
        }
    }

    // resetPassword(): sets a new password from a reset link (reset-password.php).
    public static function resetPassword(string $raw, string $password, string $confirm): void
    {
        $t = Tokens::lookup($raw, '', 'password_resets');
        if ($t['state'] !== 'valid') throw new UserError(Tokens::message($t['state'], 'password reset') . ' You can request a new one.', 410);
        $password = Validator::newPassword($password, $confirm);
        // In a transaction: check the account, use up the token, save the new hash.
        Db::transaction(function () use ($t, $password) {
            $u = Db::row('SELECT * FROM users WHERE id = ? FOR UPDATE', [$t['row']['user_id']]);
            if (!$u || $u['status'] !== 'active') throw new UserError('This account is not active. Please contact the housing office.', 409);
            if (!Tokens::consume((int) $t['row']['id'], 'password_resets')) throw new UserError(Tokens::message('used', 'password reset'), 410);
            // session_version + 1 signs out every other device that was signed in.
            Db::run('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
            // Any other open reset links for this user stop working.
            Db::run('UPDATE password_resets SET consumed_at = ? WHERE user_id = ? AND consumed_at IS NULL', [dt(Clock::now()), $u['id']]);
            // Clear the failed sign-in attempts, so the resident can sign in straight away.
            RateLimiter::clear('login', $u['email']);
        });
    }

    // updateUsername(): checks the format and that nobody else has the name, then saves it.
    public static function updateUsername(int $userId, string $username): string
    {
        $username = Validator::username($username);
        if (Db::value('SELECT 1 FROM users WHERE username = ? AND id <> ?', [$username, $userId])) throw new UserError('This username is already taken. Please choose another.');
        try {
            Db::run('UPDATE users SET username = ? WHERE id = ?', [$username, $userId]);
        } catch (PDOException $e) {
            // The unique key on username is the final guard if two people choose the same name at the same moment.
            if (Db::isDuplicate($e)) throw new UserError('This username is already taken. Please choose another.');
            throw $e;
        }
        return $username;
    }

    /** Re-checks the current password before a security change (5 wrong attempts per 15 minutes). */
    private static function verifyCurrentPassword(int $userId, string $password): array
    {
        // At most 5 wrong passwords per user in 15 minutes for security changes.
        if (RateLimiter::tooMany('reauth', (string) $userId, 5, 15)) throw new UserError('Too many incorrect password attempts. Please wait 15 minutes.', 429);
        $u = Db::row('SELECT * FROM users WHERE id = ?', [$userId]);
        if (!$u || !password_verify($password, (string) $u['password_hash'])) {
            RateLimiter::hit('reauth', (string) $userId);
            throw new UserError('Your current password is incorrect.');
        }
        return $u;
    }

    /**
     * Email change: the confirmation link goes to the resident's CURRENT address, so only the owner of
     * the account can approve it. The new address is typed twice to avoid typing mistakes. After the
     * change, both the old and the new address receive a notice.
     */
    public static function requestEmailChange(int $userId, string $newEmail, string $repeat, string $currentPassword): string
    {
        // 1. Ask for the current password, so someone at an unlocked computer cannot change the email.
        $u = self::verifyCurrentPassword($userId, $currentPassword);
        // 2. Check the new address: typed the same twice, different from the current one, and not used by anyone.
        $newEmail = Validator::email($newEmail);
        if ($newEmail !== strtolower(trim($repeat))) throw new UserError('The two email addresses do not match.');
        if ($newEmail === $u['email']) throw new UserError('This is already your email address.');
        if (Db::value('SELECT 1 FROM users WHERE email = ?', [$newEmail])) throw new UserError('This email address is already used by another account.');
        // 3. At most 5 change requests per hour.
        RateLimiter::guard('email_change', (string) $userId, 5, 60, 'You have requested several email changes in the last hour. Please wait and try again.');
        // 4. Create a 24-hour link; the new address waits with the token until it is confirmed.
        $raw = Tokens::issue($userId, 'email_change', Tokens::EMAIL_CHANGE_HOURS * 60, ['new_email' => $newEmail]);
        // 5. Send the link to the CURRENT address, so only the real owner can approve the change.
        try {
            Mailer::send($u['email'], $u['full_name'], EmailTemplates::emailChange($u['full_name'], $u['email'], $newEmail,
                Http::absoluteUrl('verify.php?type=email&token=' . $raw), Tokens::EMAIL_CHANGE_HOURS));
        } catch (MailException $e) {
            // The email could not be sent: delete the token, so nothing is half-done, and tell the resident.
            Tokens::discard($userId, 'email_change');
            error_log('GymZone: email change confirmation not sent: ' . $e->getMessage());
            throw new UserError('We could not send the confirmation email right now. Your email has not been changed. Please try again in a few minutes.', 503);
        }
        return $u['email'];
    }

    // confirmEmailChange(): runs when the link in the email is opened (verify.php?type=email).
    public static function confirmEmailChange(string $raw): array
    {
        $t = Tokens::lookup($raw, 'email_change');
        if ($t['state'] !== 'valid') throw new UserError(Tokens::message($t['state'], 'email confirmation'), 410);
        // In a transaction: lock the user, check the new address is still free, use up the token, save.
        $old = Db::transaction(function () use ($t) {
            $u = Db::row('SELECT * FROM users WHERE id = ? FOR UPDATE', [$t['row']['user_id']]);
            if (!$u || $u['status'] !== 'active') throw new UserError('This account is not active.', 409);
            if (Db::value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$t['row']['new_email'], $u['id']])) {
                throw new UserError('This email address is now used by another account.', 409);
            }
            if (!Tokens::consume((int) $t['row']['id'])) throw new UserError(Tokens::message('used', 'email confirmation'), 410);
            Db::run('UPDATE users SET email = ? WHERE id = ?', [$t['row']['new_email'], $u['id']]);
            // Failed sign-in attempts for the old address are cleared.
            RateLimiter::clear('login', $u['email']);
            return $u;
        });
        // After the change: warn the old address (in case of misuse) and welcome the new one.
        $new = $t['row']['new_email'];
        foreach ([[$old['email'], EmailTemplates::emailChanged($old['full_name'], $new)],
                  [$new, EmailTemplates::emailChangedWelcome($old['full_name'], $new, Http::absoluteUrl('login.php'))]] as [$to, $msg]) {
            try { Mailer::send($to, $old['full_name'], $msg); } catch (MailException $e) { error_log('GymZone: email-changed notice not sent: ' . $e->getMessage()); }
        }
        // Read the user again on the next Auth::user() call, so the new email is shown.
        Auth::refresh();
        return ['email' => $new];
    }

    /** The resident deletes their own account after re-entering the password and typing DELETE. */
    public static function deleteOwn(int $userId, string $password, string $confirmWord): void
    {
        // The password again, the word DELETE, and the resident must not be in the gym right now.
        self::verifyCurrentPassword($userId, $password);
        if (strtoupper(trim($confirmWord)) !== 'DELETE') throw new UserError('Type DELETE in capital letters to confirm.');
        if (Attendance::activeFor($userId)) throw new UserError('Please check out of the gym before deleting your account.');
        // Delete the account with its bookings and history (see Residents::delete()).
        Residents::delete($userId, true);
    }

    /** The new password is stored (hashed) with the token and applied only after confirmation by email. */
    public static function requestPasswordChange(int $userId, string $current, string $new, string $confirm): void
    {
        // The current password first, then a strong new password, typed twice and different from the old one.
        $u = self::verifyCurrentPassword($userId, $current);
        $new = Validator::newPassword($new, $confirm);
        if (password_verify($new, $u['password_hash'])) throw new UserError('The new password must be different from your current password.');
        // At most 5 requests per hour.
        RateLimiter::guard('password_change', (string) $userId, 5, 60, 'You have requested several password changes in the last hour. Please wait and try again.');
        // The NEW password is hashed now and stored with the token. It replaces the old one only when the link
        // in the email is opened (confirmPasswordChange()). The plain password is never stored or emailed.
        $raw = Tokens::issue($userId, 'password_change', Tokens::PASSWORD_CHANGE_MINUTES, ['new_password_hash' => password_hash($new, PASSWORD_DEFAULT)]);
        try {
            Mailer::send($u['email'], $u['full_name'], EmailTemplates::passwordChange($u['full_name'], Http::absoluteUrl('verify.php?type=password&token=' . $raw), Tokens::PASSWORD_CHANGE_MINUTES));
        } catch (MailException $e) {
            // The email could not be sent: delete the token, so nothing changes.
            Tokens::discard($userId, 'password_change');
            error_log('GymZone: password change verification not sent: ' . $e->getMessage());
            throw new UserError('We could not send the confirmation email right now. Your password has not been changed. Please try again in a few minutes.', 503);
        }
    }

    // confirmPasswordChange(): runs when the link is opened (verify.php?type=password).
    public static function confirmPasswordChange(string $raw): void
    {
        $t = Tokens::lookup($raw, 'password_change');
        if ($t['state'] !== 'valid') throw new UserError(Tokens::message($t['state'], 'password confirmation'), 410);
        $userId = (int) $t['row']['user_id'];
        // In a transaction: lock the user, use up the token, save the new hash and raise session_version
        // (which signs out every other device).
        Db::transaction(function () use ($t, $userId) {
            $u = Db::row('SELECT * FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if (!$u || $u['status'] !== 'active') throw new UserError('This account is not active.', 409);
            if (!Tokens::consume((int) $t['row']['id'])) throw new UserError(Tokens::message('used', 'password confirmation'), 410);
            Db::run('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?', [$t['row']['new_password_hash'], $userId]);
        });
        // Other devices are signed out. If this browser is the resident's own session, keep it signed in.
        if (session_status() === PHP_SESSION_ACTIVE && (int) ($_SESSION['uid'] ?? 0) === $userId) {
            session_regenerate_id(true);
            $_SESSION['ver'] = (int) Db::value('SELECT session_version FROM users WHERE id = ?', [$userId]);
            Auth::refresh();
        }
    }

    // pendingEmailChange(): the new address still waiting for confirmation (shown on the Settings page).
    public static function pendingEmailChange(int $userId): ?string
    {
        return Db::value("SELECT new_email FROM verification_tokens WHERE user_id = ? AND purpose = 'email_change'
                           AND consumed_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1", [$userId, dt(Clock::now())]);
    }
}
