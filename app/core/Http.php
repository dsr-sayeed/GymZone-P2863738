<?php
/** Request and response helpers: URLs, redirects, JSON responses and request input. */
// Http: small helpers for addresses (URLs), redirects, JSON answers and reading the request body.
final class Http
{
    /** Web path of the project folder, for example "/gymzone/" on XAMPP or "/" at a domain root. */
    public static function basePath(): string
    {
        // Worked out once per request, then remembered in a static variable.
        static $base = null;
        if ($base !== null) return $base;
        // Compare the script's file path with the project folder to find the web folder, for example /gymzone/.
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
        $root = str_replace('\\', '/', App::root());
        $name = $_SERVER['SCRIPT_NAME'] ?? '/';
        if ($script !== '' && str_starts_with($script, $root)) {
            $relative = substr($script, strlen($root));          // e.g. /admin/index.php
            if (str_ends_with($name, $relative)) {
                return $base = rtrim(substr($name, 0, -strlen($relative)), '/') . '/';
            }
        }
        return $base = '/';
    }

    // url(): a link inside GymZone, for example url('book.php') gives '/gymzone/book.php'.
    public static function url(string $path = ''): string
    {
        return self::basePath() . ltrim($path, '/');
    }

    /** Absolute URL for links in emails. */
    public static function absoluteUrl(string $path): string
    {
        // Use app_url from the configuration if it is set; otherwise build it from the request.
        $configured = rtrim((string) App::config('app_url'), '/');
        if ($configured !== '') return $configured . '/' . ltrim($path, '/');
        $scheme = self::isHttps() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . self::url($path);
    }

    // isHttps(): true if the request came over HTTPS (also behind a proxy that says so).
    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    // clientIp(): the visitor's IP address, used for rate limits. REMOTE_ADDR cannot be faked with a header.
    public static function clientIp(): string
    {
        return substr($_SERVER['REMOTE_ADDR'] ?? 'cli', 0, 45);
    }

    // isPost(): true for POST requests (forms and API actions).
    public static function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    // redirect(): sends the browser to another page and stops the script ("never" = it never returns).
    public static function redirect(string $path): never
    {
        header('Location: ' . (preg_match('#^https?://#', $path) ? $path : self::url($path)));
        exit;
    }

    // json(): sends data as JSON with a status code; no-store stops the browser from caching it.
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ok(): a successful answer, always with "ok": true.
    public static function ok(array $data = []): never
    {
        self::json(['ok' => true] + $data);
    }

    // fail(): an error answer {"ok": false, "error": "message"} with a status code (422 by default).
    public static function fail(string $message, int $status = 422, array $extra = []): never
    {
        self::json(['ok' => false, 'error' => $message] + $extra, $status);
    }

    /** Request body: JSON (from fetch) or a normal form post. */
    public static function input(): array
    {
        // Read once per request, then remembered.
        static $input = null;
        if ($input !== null) return $input;
        // fetch() sends JSON (Content-Type: application/json): decode the raw body.
        if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            $decoded = json_decode(file_get_contents('php://input') ?: '[]', true);
            return $input = is_array($decoded) ? $decoded : [];
        }
        // A normal HTML form sends form fields: use $_POST.
        return $input = $_POST;
    }

    // str(): one input value as text ('' if it is missing or not a simple value).
    public static function str(string $key, string $default = ''): string
    {
        $v = self::input()[$key] ?? $default;
        return is_scalar($v) ? (string) $v : $default;
    }

    // int(): one input value as a whole number (0 if it is missing).
    public static function int(string $key): int
    {
        return (int) (self::input()[$key] ?? 0);
    }

    /** Runs an API handler: catches rule errors and returns them as JSON messages. */
    public static function api(callable $handler): never
    {
        // Run the endpoint's code. If it does not send an answer itself, answer {"ok": true}.
        try {
            $handler();
            self::ok();
        } catch (ConfirmationRequired $e) {
            // An admin change that would cancel bookings: 409 with needs_confirm, so core.js can ask first.
            self::fail($e->getMessage(), 409, ['needs_confirm' => true, 'affected' => $e->affected]);
        } catch (UserError $e) {
            // A broken rule, for example "zone is full": send the friendly message with its status code.
            self::fail($e->getMessage(), $e->status());
        }
    }
}
