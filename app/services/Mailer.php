<?php
/**
 * Sends email. With the 'mailpit' transport, messages are posted as JSON to Mailpit's HTTP API
 * (POST /api/v1/send) using cURL; no SMTP is configured in PHP. Mailpit keeps them in its inbox
 * at http://localhost:8025 for testing. The 'log' transport appends them to storage/logs/mail.log.
 */
// Every email goes through send(). The tests read Mailer::$sent to see what was sent.
final class Mailer
{
    /** @var array Last message handed over, and all messages of this request (used by the tests). */
    public static array $last = [];
    public static array $sent = [];

    /** $message: ['subject', 'html', 'text', 'tag']. Throws MailException if the mail service is unavailable. */
    public static function send(string $toEmail, string $toName, array $message): string
    {
        // Which way to send comes from config/config.php ('mailpit' by default).
        $cfg = App::config('mail');
        self::$last = ['to' => $toEmail] + $message;
        self::$sent[] = self::$last;
        // match picks the transport; an unknown name throws MailException.
        return match ($cfg['transport']) {
            'mailpit' => self::viaMailpit($cfg, $toEmail, $toName, $message),
            'log'     => self::viaLog($toEmail, $message),
            default   => throw new MailException('Unknown mail transport "' . $cfg['transport'] . '" in config/config.php.'),
        };
    }

    private static function viaMailpit(array $cfg, string $toEmail, string $toName, array $m): string
    {
        // Without PHP's cURL extension we cannot call Mailpit.
        if (!function_exists('curl_init')) throw new MailException('The PHP cURL extension is not enabled.');
        // The message as JSON, in the format Mailpit's /api/v1/send expects.
        $payload = [
            'From'    => ['Email' => $cfg['from_email'], 'Name' => $cfg['from_name']],
            'To'      => [['Email' => $toEmail, 'Name' => $toName]],
            'Subject' => $m['subject'],
            'HTML'    => $m['html'],
            'Text'    => $m['text'],
            'Tags'    => array_values(array_filter([$m['tag'] ?? null])),
        ];
        // Reply-To is set for contact messages, so a reply goes to the resident.
        if (!empty($m['reply_to'])) $payload['ReplyTo'] = [['Email' => $m['reply_to'], 'Name' => $m['reply_name'] ?? '']];
        // POST the JSON to Mailpit with cURL, with short time limits so a page never hangs for long.
        $ch = curl_init(rtrim($cfg['mailpit_url'], '/') . '/api/v1/send');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $cfg['timeout'],
            CURLOPT_TIMEOUT        => $cfg['timeout'] + 5,
        ]);
        // Send it and read the answer.
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        // Mailpit is not running: throw MailException, and the caller decides what to do.
        if ($body === false) throw new MailException("Mailpit is not reachable at {$cfg['mailpit_url']} ($error).");
        // Mailpit answers with the new message's ID; anything else is an error.
        $data = json_decode($body, true);
        if ($status !== 200 || empty($data['ID'])) throw new MailException("Mailpit refused the message (HTTP $status): " . substr((string) $body, 0, 200));
        return $data['ID'];
    }

    // viaLog(): for computers without Mailpit: add the email as text to storage/logs/mail.log.
    private static function viaLog(string $toEmail, array $m): string
    {
        $id = bin2hex(random_bytes(6));
        $entry = sprintf("==== %s  id=%s  to=%s\nSubject: %s\n\n%s\n\n", Clock::now()->format('c'), $id, $toEmail, $m['subject'], $m['text']);
        if (@file_put_contents(App::root() . '/storage/logs/mail.log', $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new MailException('Could not write storage/logs/mail.log.');
        }
        return $id;
    }
}
