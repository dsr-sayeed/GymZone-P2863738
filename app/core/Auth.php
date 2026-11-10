<?php
/** Sessions, sign-in, roles and CSRF protection. */
// Auth decides who is signed in: the session cookie, sign-in and sign-out, the two roles
// (resident and admin), and the CSRF token that protects every form and API call.
final class Auth
{
    // Sign out automatically after 120 minutes without activity.
    public const IDLE_MINUTES = 120;
    // The signed-in user, remembered for this request (null = not loaded yet, false = nobody signed in).
    private static array|false|null $user = null;

    // startSession(): starts PHP's session with safe cookie settings. Called by bootstrap.php.
    public static function startSession(): void
    {
        // Already started: nothing to do.
        if (session_status() !== PHP_SESSION_NONE) return;
        // Strict mode: PHP refuses session IDs it did not create itself (protects against session fixation).
        ini_set('session.use_strict_mode', '1');
        // The session ID may only come from a cookie, never from the address bar.
        ini_set('session.use_only_cookies', '1');
        // The cookie is called gymzone_sid.
        session_name('gymzone_sid');
        // lifetime 0 = deleted when the browser closes; path = only sent to GymZone's folder;
        // httponly = JavaScript cannot read it; samesite Lax = not sent with forms posted from other sites;
        // secure = only sent over HTTPS when the site uses HTTPS.
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => Http::basePath(),
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => Http::isHttps(),
        ]);
        session_start();
        // Idle timeout: if the last request was more than 120 minutes ago, sign the user out.
        $last = $_SESSION['last_seen'] ?? null;
        if (isset($_SESSION['uid']) && $last && time() - $last > self::IDLE_MINUTES * 60) {
            self::logout();
            session_start();
            View::flash('info', 'You were signed out after a period of inactivity. Please sign in again.');
        }
        // Remember the time of this request for the next idle check.
        $_SESSION['last_seen'] = time();
    }

    /** The signed-in user (with apartment number), or null. Signs out deactivated users and stale sessions. */
    public static function user(): ?array
    {
        // Already loaded in this request: return it (or null if nobody is signed in).
        if (self::$user !== null) return self::$user ?: null;
        self::$user = false;
        // The session stores only the user's id; the details are read fresh from the database.
        $id = $_SESSION['uid'] ?? null;
        if (!$id) return null;
        // Load the user together with their apartment number.
        $u = Db::row('SELECT u.id, u.role, u.status, u.full_name, u.username, u.email, u.session_version, a.number AS apartment
                        FROM users u LEFT JOIN apartments a ON a.id = u.apartment_id WHERE u.id = ?', [$id]);
        // Sign out if the account was deleted or deactivated, or if session_version changed
        // (it changes after a password change or reset, which signs out every other device).
        if (!$u || $u['status'] !== 'active' || (int) $u['session_version'] !== (int) ($_SESSION['ver'] ?? 0)) {
            self::logout();
            return null;
        }
        return self::$user = $u;
    }

    // refresh(): forget the remembered user, so the next user() call reads the database again.
    public static function refresh(): void
    {
        self::$user = null;
    }

    /**
     * Checks an email and password. Returns the user or throws UserError with a friendly reason.
     * Wrong passwords are limited to 5 per email and 20 per IP address in 15 minutes.
     */
    public static function attempt(string $email, string $password, string $ip): array
    {
        // Emails are stored in lower case, so compare in lower case.
        $email = strtolower(trim($email));
        $msg = 'Too many sign-in attempts. Please wait 15 minutes and try again, or reset your password.';
        // Rate limit: at most 5 failed attempts per email and 20 per IP address in 15 minutes.
        if (RateLimiter::tooMany('login', $email, 5, 15) || RateLimiter::tooMany('login_ip', $ip, 20, 15)) {
            throw new UserError($msg, 429);
        }
        // Look the user up and compare the password with the stored bcrypt hash.
        $u = Db::row('SELECT * FROM users WHERE email = ?', [$email]);
        $valid = $u && $u['password_hash'] && password_verify($password, $u['password_hash']);
        if (!$valid) {
            // Unknown email: still check a dummy hash, so the answer takes the same time
            // (an attacker cannot tell from the timing whether an email has an account).
            if (!$u || !$u['password_hash']) password_verify($password, '$2y$10$IeDJoenDyzRgw4Qz0pf.iuvo9HPl0B0FrU0uDG0eNfbT9H1KaKuCi'); // even timing
            // Record the failed attempt for the rate limit.
            RateLimiter::hit('login', $email);
            RateLimiter::hit('login_ip', $ip);
            // A pending account has no password yet: tell the resident to use the invitation link.
            if ($u && $u['status'] === 'pending') {
                throw new UserError('This account has not been activated yet. Use the link in your invitation email, or ask the housing office to send a new one.', 401);
            }
            throw new UserError('The email address or password is incorrect.', 401);
        }
        // The password is right, but the account may be deactivated or not activated yet.
        if ($u['status'] === 'inactive') throw new UserError('This account has been deactivated. Please contact the housing office.', 403);
        if ($u['status'] !== 'active') throw new UserError('This account has not been activated yet.', 403);
        // Success: forget the earlier failed attempts for this email.
        RateLimiter::clear('login', $email);
        // If PHP's default hashing has become stronger since this hash was made, store a new hash.
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        return $u;
    }

    // login(): called after a successful attempt(). Starts a fresh session for this user.
    public static function login(array $user): void
    {
        // A new session ID, and the old one is destroyed: protects against session fixation.
        session_regenerate_id(true);
        // The session keeps only: user id, session_version, time of last activity and a new CSRF token.
        $_SESSION = ['uid' => (int) $user['id'], 'ver' => (int) $user['session_version'], 'last_seen' => time(),
                     'csrf' => bin2hex(random_bytes(32))];
        // Remember when the user last signed in (shown to administrators).
        Db::run('UPDATE users SET last_login_at = ? WHERE id = ?', [dt(Clock::now()), $user['id']]);
        self::$user = null;
    }

    // logout(): empties the session, deletes the cookie and destroys the session.
    public static function logout(): void
    {
        self::$user = false;
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        session_destroy();
    }

    // home(): the start page for each role.
    public static function home(array $user): string
    {
        return $user['role'] === 'admin' ? 'admin/index.php' : 'dashboard.php';
    }

    /** For pages: redirects to sign-in, or refuses access if the role does not match. */
    public static function requirePage(string $role): array
    {
        $u = self::user();
        // Not signed in: remember the page that was asked for, then send the visitor to the sign-in page.
        if (!$u) {
            $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? null;
            Http::redirect('login.php');
        }
        // Wrong role, for example a resident opening an admin page: show "No access" with status 403.
        if ($u['role'] !== $role) {
            http_response_code(403);
            View::errorPage('No access', 'This page is not available for your account.', Auth::home($u));
        }
        return $u;
    }

    /** For API endpoints: JSON 401/403, and CSRF check on every POST. */
    public static function requireApi(string $role): array
    {
        $u = self::user();
        // 401 = not signed in. core.js then sends the user to the sign-in page.
        if (!$u) Http::fail('Your session has ended. Please sign in again.', 401);
        // 403 = signed in, but with the wrong role.
        if ($u['role'] !== $role) Http::fail('You do not have access to this action.', 403);
        // Every POST must carry the CSRF token.
        if (Http::isPost()) self::verifyCsrf();
        return $u;
    }

    // csrfToken(): one random token per session (32 random bytes written as 64 hex characters).
    public static function csrfToken(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    // csrfField(): the hidden form field that carries the token in normal HTML forms.
    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf" value="' . View::e(self::csrfToken()) . '">';
    }

    // verifyCsrf(): the token must come back with every POST, either in the X-CSRF-Token header
    // (sent by core.js with fetch calls) or in the hidden "csrf" form field.
    public static function verifyCsrf(): void
    {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? (Http::input()['csrf'] ?? '');
        // hash_equals() compares in constant time, so the comparison does not leak timing information.
        if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
            // Wrong or missing token: 419 "session expired" (JSON for API calls, an HTML page otherwise).
            if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) Http::fail('Your session has expired. Please reload the page and try again.', 419);
            http_response_code(419);
            View::errorPage('Session expired', 'Your session has expired. Please go back, reload the page and try again.', 'index.php');
        }
    }
}
