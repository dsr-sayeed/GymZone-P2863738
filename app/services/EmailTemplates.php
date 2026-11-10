<?php
/** HTML and plain-text emails in GymZone's style. Email clients need inline styles, so they are written out here. */
final class EmailTemplates
{
    // layout(): the shared HTML frame of every email: logo, card, title, text, optional button and footer.
    private static function layout(string $title, string $intro, ?array $button, string $after, string $footnote): string
    {
        // $e escapes values for HTML, like View::e().
        $e = fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        // The button, plus the link written out in full in case the button does not work.
        $btn = $button ? '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:28px 0"><tr><td style="border-radius:10px;background:#13294b">'
            . '<a href="' . $e($button[1]) . '" style="display:inline-block;padding:14px 26px;font-weight:600;font-size:16px;color:#ffffff;text-decoration:none;border-radius:10px">'
            . $e($button[0]) . '</a></td></tr></table>'
            . '<p style="margin:0 0 6px;font-size:13px;color:#5b6b80">If the button does not work, copy this link into your browser:</p>'
            . '<p style="margin:0;font-size:13px;word-break:break-all"><a href="' . $e($button[1]) . '" style="color:#1d4ed8">' . $e($button[1]) . '</a></p>' : '';
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . $e($title) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f2f4f8;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#13294b">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f8;padding:32px 12px"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px">'
            . '<tr><td style="padding:0 4px 16px"><span style="display:inline-block;width:28px;height:28px;border-radius:8px;background:#13294b;vertical-align:middle"></span>'
            . '<span style="font-size:20px;font-weight:700;vertical-align:middle;margin-left:8px;color:#13294b">GymZone</span></td></tr>'
            . '<tr><td style="background:#ffffff;border-radius:16px;padding:32px 28px;border:1px solid #e3e8f0">'
            . '<h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;color:#13294b">' . $e($title) . '</h1>'
            . '<div style="font-size:16px;line-height:1.6;color:#2b3a52">' . $intro . '</div>' . $btn
            . ($after !== '' ? '<div style="margin-top:24px;font-size:14px;line-height:1.6;color:#5b6b80">' . $after . '</div>' : '')
            . '</td></tr><tr><td style="padding:18px 8px;font-size:12px;line-height:1.5;color:#7a8799;text-align:center">' . $e($footnote) . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    // p(): one escaped paragraph.
    private static function p(string $text): string
    {
        return '<p style="margin:0 0 12px">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    // The footer text of every email.
    private const FOOT = 'GymZone is the gym booking service for residents of your housing community. This is an automatic message; please do not reply.';

    // Each method returns ['subject', 'tag', 'html', 'text']: the subject, a tag for Mailpit, and HTML and plain-text versions.
    // invitation(): the activation email (24 hours) sent when an administrator adds a resident.
    public static function invitation(string $name, string $link, int $hours, bool $reminder): array
    {
        $title = $reminder ? 'Your new GymZone activation link' : 'Welcome to GymZone';
        $intro = self::p("Hello $name,")
            . self::p($reminder ? 'The housing office has sent you a new link to activate your GymZone account. Earlier links no longer work.'
                                : 'The housing office has created a GymZone account for you. With GymZone you can see how busy the gym is, book sessions up to three weeks ahead and check in when you arrive.')
            . self::p('To start, choose a password for your account:');
        return ['subject' => $title, 'tag' => 'activation',
                'html' => self::layout($title, $intro, ['Activate my account', $link], 'This link works once and expires in ' . $hours . ' hours. If it has expired, ask the housing office to send a new one.', self::FOOT),
                'text' => "Hello $name,\n\n" . ($reminder ? "Here is a new link to activate your GymZone account. Earlier links no longer work." : "The housing office has created a GymZone account for you.")
                        . "\n\nChoose your password here:\n$link\n\nThe link works once and expires in $hours hours."];
    }

    // Forgotten-password link (60 minutes).
    public static function passwordReset(string $name, string $link, int $minutes): array
    {
        $intro = self::p("Hello $name,") . self::p('We received a request to reset the password for your GymZone account. Choose a new password with the button below.');
        return ['subject' => 'Reset your GymZone password', 'tag' => 'password-reset',
                'html' => self::layout('Reset your password', $intro, ['Choose a new password', $link],
                    "The link works once and expires in $minutes minutes. If you did not ask to reset your password, you can ignore this email; your password will not change.", self::FOOT),
                'text' => "Hello $name,\n\nReset your GymZone password here:\n$link\n\nThe link works once and expires in $minutes minutes. If you did not ask for this, ignore this email."];
    }

    // Confirmation of a password change (60 minutes).
    public static function passwordChange(string $name, string $link, int $minutes): array
    {
        $intro = self::p("Hello $name,") . self::p('You asked to change the password of your GymZone account. To protect your account, the new password is only saved after you confirm here.');
        return ['subject' => 'Confirm your new GymZone password', 'tag' => 'password-change',
                'html' => self::layout('Confirm your password change', $intro, ['Confirm password change', $link],
                    "The link works once and expires in $minutes minutes. If you did not make this request, ignore this email and consider changing your password.", self::FOOT),
                'text' => "Hello $name,\n\nConfirm your new GymZone password here:\n$link\n\nThe link works once and expires in $minutes minutes. If you did not request this, ignore this email."];
    }

    /** Sent to the CURRENT address: the change only happens if the account owner confirms from their existing inbox. */
    public static function emailChange(string $name, string $currentEmail, string $newEmail, string $link, int $hours): array
    {
        $intro = self::p("Hello $name,") . self::p("You asked to change the email address of your GymZone account from $currentEmail to $newEmail.")
            . self::p('To protect your account, we sent this confirmation to your current address. The change only happens after you confirm.');
        return ['subject' => 'Confirm the change of your GymZone email', 'tag' => 'email-change',
                'html' => self::layout('Confirm your email change', $intro, ['Confirm email change', $link],
                    "The link works once and expires in $hours hours. If you did not ask for this, ignore this email and consider changing your password.", self::FOOT),
                'text' => "Hello $name,\n\nYou asked to change your GymZone email from $currentEmail to $newEmail. Confirm here:\n$link\n\nThe link works once and expires in $hours hours. If you did not ask for this, ignore this email."];
    }

    /** Sent to the old address after the change. */
    public static function emailChanged(string $name, string $newEmail): array
    {
        $intro = self::p("Hello $name,") . self::p("The email address for your GymZone account has been changed to $newEmail. From now on, use the new address to sign in.");
        return ['subject' => 'Your GymZone email address was changed', 'tag' => 'email-changed',
                'html' => self::layout('Your email address was changed', $intro, null, 'If you did not make this change, contact the housing office straight away.', self::FOOT),
                'text' => "Hello $name,\n\nYour GymZone email address was changed to $newEmail. If you did not make this change, contact the housing office straight away."];
    }

    /** Sent to the new address after the change. */
    public static function emailChangedWelcome(string $name, string $newEmail, string $loginLink): array
    {
        $intro = self::p("Hello $name,") . self::p("This address, $newEmail, is now the email for your GymZone account. Use it the next time you sign in.");
        return ['subject' => 'This is now your GymZone email address', 'tag' => 'email-changed',
                'html' => self::layout('Your new email address is active', $intro, ['Open GymZone', $loginLink], '', self::FOOT),
                'text' => "Hello $name,\n\n$newEmail is now the email for your GymZone account. Sign in here: $loginLink"];
    }

    // Sent when an administrator deactivates the account.
    public static function accountDeactivated(string $name, string $contact): array
    {
        $intro = self::p("Hello $name,") . self::p('Your GymZone account has been deactivated by the housing office. You can no longer sign in, and your upcoming bookings have been cancelled.')
            . self::p($contact !== '' ? "If you think this is a mistake, contact the housing office at $contact." : 'If you think this is a mistake, contact the housing office.');
        return ['subject' => 'Your GymZone account has been deactivated', 'tag' => 'account-deactivated',
                'html' => self::layout('Your account has been deactivated', $intro, null, '', self::FOOT),
                'text' => "Hello $name,\n\nYour GymZone account has been deactivated by the housing office. Your upcoming bookings have been cancelled." . ($contact !== '' ? " Contact: $contact" : '')];
    }

    // Sent when an administrator reactivates the account.
    public static function accountReactivated(string $name, string $loginLink): array
    {
        $intro = self::p("Hello $name,") . self::p('Your GymZone account is active again. You can sign in with your usual email address and password.');
        return ['subject' => 'Your GymZone account is active again', 'tag' => 'account-reactivated',
                'html' => self::layout('Your account is active again', $intro, ['Sign in to GymZone', $loginLink], '', self::FOOT),
                'text' => "Hello $name,\n\nYour GymZone account is active again. Sign in here: $loginLink"];
    }

    // Sent when the account is deleted, by the resident or by an administrator.
    public static function accountDeleted(string $name, bool $byResident, string $contact): array
    {
        $intro = self::p("Hello $name,")
            . self::p($byResident ? 'As you requested, your GymZone account has been deleted.' : 'Your GymZone account has been deleted by the housing office.')
            . self::p('Your personal details, bookings and workout history have been removed from GymZone.')
            . self::p('If you want to use the gym again later, ask the housing office for a new invitation' . ($contact !== '' ? " ($contact)." : '.'));
        return ['subject' => 'Your GymZone account has been deleted', 'tag' => 'account-deleted',
                'html' => self::layout('Your account has been deleted', $intro, null, '', self::FOOT),
                'text' => "Hello $name,\n\n" . ($byResident ? 'As you requested, your GymZone account has been deleted.' : 'Your GymZone account has been deleted by the housing office.')
                        . ' Your details, bookings and history have been removed.'];
    }

    /** A message from a resident's Help page, sent to the housing office. */
    public static function contactMessage(string $name, string $email, ?string $apartment, string $subject, string $message, string $adminLink): array
    {
        $e = fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $intro = self::p("New message from $name" . ($apartment ? " (apartment $apartment)" : '') . " <$email>:")
            . '<p style="margin:0 0 6px;font-weight:600">' . $e($subject) . '</p>'
            . '<div style="white-space:pre-wrap;background:#f2f4f8;border-radius:10px;padding:14px">' . $e($message) . '</div>';
        return ['subject' => "GymZone message: $subject", 'tag' => 'contact',
                'html' => self::layout('Message from a resident', $intro, ['Open messages in GymZone', $adminLink], 'Reply to this email to answer the resident directly.', self::FOOT),
                'text' => "New message from $name" . ($apartment ? " (apartment $apartment)" : '') . " <$email>\n\n$subject\n\n$message\n\nMessages: $adminLink"];
    }

    // Sent when the office cancels a booking, or a closure or schedule change does.
    public static function bookingCancelled(string $name, string $zone, string $date, string $time, string $reason): array
    {
        $intro = self::p("Hello $name,") . self::p("Your $zone booking on $date at $time has been cancelled by the housing office.")
            . self::p("Reason: $reason") . self::p('We are sorry for the inconvenience. You are welcome to book another time in GymZone.');
        return ['subject' => "Your $zone booking was cancelled", 'tag' => 'booking-cancelled',
                'html' => self::layout('Your booking was cancelled', $intro, null, '', self::FOOT),
                'text' => "Hello $name,\n\nYour $zone booking on $date at $time has been cancelled by the housing office.\nReason: $reason\n\nYou are welcome to book another time in GymZone."];
    }
}
