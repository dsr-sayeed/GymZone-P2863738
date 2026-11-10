<?php
/** Messages from residents to the housing office (Help page) and the office's contact details. */
final class Contact
{
    // The topics a resident can choose on the Help page.
    public const SUBJECTS = ['Booking or check-in', 'My account', 'Equipment or cleaning', 'Something else'];

    // details(): the email, phone and office hours shown to residents.
    public static function details(): array
    {
        $s = Settings::all();
        return ['email' => $s['contact_email'], 'phone' => $s['contact_phone'], 'hours' => $s['contact_hours']];
    }

    // saveDetails(): an administrator saves the contact details (Messages page).
    public static function saveDetails(string $email, string $phone, string $hours): void
    {
        $email = Validator::email($email);
        $phone = Validator::text($phone, 40, 'a phone number', false);
        if ($phone !== '' && !preg_match('/^[0-9 +()\-]{4,40}$/', $phone)) throw new UserError('Please enter a valid phone number.');
        Settings::set(['contact_email' => $email, 'contact_phone' => $phone, 'contact_hours' => Validator::text($hours, 120, 'office hours', false)]);
    }

    /**
     * Stores the message (shown under Admin → Messages) and emails it to the contact address, with the
     * resident as reply-to. Limited to 5 messages per resident per hour. Returns whether the email was sent.
     */
    public static function send(array $user, string $subject, string $message): bool
    {
        // Check the topic and the message (10 to 2000 characters), then the limit: 5 per resident per hour.
        if (!in_array($subject, self::SUBJECTS, true)) throw new UserError('Please choose what your message is about.');
        $message = Validator::text($message, 2000, 'your message');
        if (mb_strlen($message) < 10) throw new UserError('Please write a little more so the housing office can help (at least 10 characters).');
        RateLimiter::guard('contact', (string) $user['id'], 5, 60, 'You have sent several messages in the last hour. Please wait before sending another.');
        // Save the message for the administrators' Messages page.
        Db::insert('INSERT INTO contact_messages (user_id, name, email, apartment, subject, message, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$user['id'], $user['full_name'], $user['email'], $user['apartment'] ?? null, $subject, $message, dt(Clock::now())]);
        // Email it to the office, with the resident as Reply-To, so the office can simply press Reply.
        $to = Settings::all()['contact_email'];
        if ($to === '') return false;
        try {
            Mailer::send($to, 'Housing office', EmailTemplates::contactMessage($user['full_name'], $user['email'], $user['apartment'] ?? null, $subject, $message,
                Http::absoluteUrl('admin/messages.php')) + ['reply_to' => $user['email'], 'reply_name' => $user['full_name']]);
            return true;
        } catch (MailException $e) {
            error_log('GymZone: contact message email not sent: ' . $e->getMessage());
            return false;
        }
    }

    // list(): open, handled or all messages. $where is one of three fixed texts, never user input.
    public static function list(string $filter): array
    {
        $where = $filter === 'handled' ? 'm.handled_at IS NOT NULL' : ($filter === 'all' ? '1 = 1' : 'm.handled_at IS NULL');
        return Db::all("SELECT m.*, h.full_name AS handler FROM contact_messages m LEFT JOIN users h ON h.id = m.handled_by
                         WHERE $where ORDER BY m.created_at DESC LIMIT 200");
    }

    // openCount(): the number on the red badge in the admin menu.
    public static function openCount(): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM contact_messages WHERE handled_at IS NULL');
    }

    // setHandled(): marks a message handled (with who and when), or open again.
    public static function setHandled(int $id, bool $handled, int $adminId): void
    {
        Db::run('UPDATE contact_messages SET handled_at = ?, handled_by = ? WHERE id = ?', $handled ? [dt(Clock::now()), $adminId, $id] : [null, null, $id]);
    }

    // forUser(): the resident's own recent messages (Help page).
    public static function forUser(int $userId): array
    {
        return Db::all('SELECT * FROM contact_messages WHERE user_id = ? ORDER BY created_at DESC LIMIT 10', [$userId]);
    }
}
