<?php
/** HTML output helpers: escaping, flash messages, the page layout and small reusable components. */
// View prints HTML. e() escapes values; header() and footer() draw the layout of every page.
final class View
{
    // e(): escapes a value for HTML, so "<script>" is shown as text and never runs (protects against XSS).
    // Every value printed into a page goes through this function.
    public static function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // flash(): a one-time message stored in the session and shown on the next page (for example after a redirect).
    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = [$type, $message];
    }

    // icon(): a small SVG icon by name, drawn inline, so no image files are needed.
    public static function icon(string $name, string $class = 'icon'): string
    {
        $paths = [
            'home'     => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h5v-6h4v6h5V9.5"/>',
            'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
            'list'     => '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>',
            'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
            'logout'   => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 17l-5-5 5-5M5 12h11"/>',
            'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c1-3.5 3.5-5.5 6.5-5.5s5.5 2 6.5 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.8c1.8.8 3 2.6 3.5 5.2"/>',
            'gym'      => '<path d="M6.5 6.5v11M17.5 6.5v11M3.5 9v6M20.5 9v6M6.5 12h11"/>',
            'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'check'    => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
            'plus'     => '<path d="M12 5v14M5 12h14"/>',
            'search'   => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
            'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5 12 13l8.5-6.5"/>',
            'lock'     => '<rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>',
            'x'        => '<path d="M6 6l12 12M18 6 6 18"/>',
            'chevron'  => '<path d="M9 6l6 6-6 6"/>',
            'back'     => '<path d="M15 6l-6 6 6 6"/>',
            'help'     => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.2a2.6 2.6 0 0 1 5 .9c0 1.7-2.5 2.2-2.5 3.9"/><circle cx="12" cy="17" r=".6" fill="currentColor"/>',
            'inbox'    => '<path d="M3 13l2.5-7.5A2 2 0 0 1 7.4 4h9.2a2 2 0 0 1 1.9 1.5L21 13v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M3 13h5l1.5 2.5h5L16 13h5"/>',
        ];
        return '<svg class="' . self::e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . ($paths[$name] ?? '') . '</svg>';
    }

    // logo(): the GymZone logo as inline SVG.
    public static function logo(): string
    {
        return '<span class="logo" aria-hidden="true"><svg viewBox="0 0 32 32"><rect width="32" height="32" rx="9" fill="currentColor"/>'
            . '<path d="M9 11v10M23 11v10M6 13.5v5M26 13.5v5M9 16h14" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/></svg></span>';
    }

    /** Navigation entries per role: key => [label, path, icon]. */
    private static function nav(?array $user): array
    {
        // Nobody signed in: no menu.
        if (!$user) return [];
        // Administrators get the admin menu; residents get the resident menu.
        if ($user['role'] === 'admin') {
            return ['overview' => ['Overview', 'admin/index.php', 'home', 'Home'], 'residents' => ['Residents', 'admin/residents.php', 'users', 'Residents'],
                    'gym' => ['Gym', 'admin/gym.php', 'gym', 'Gym'], 'bookings' => ['Bookings', 'admin/bookings.php', 'calendar', 'Bookings'],
                    'messages' => ['Messages', 'admin/messages.php', 'inbox', 'Messages']];
        }
        return ['dashboard' => ['Dashboard', 'dashboard.php', 'home', 'Home'], 'book' => ['Book', 'book.php', 'calendar', 'Book'],
                'bookings' => ['My bookings', 'bookings.php', 'list', 'Bookings'], 'profile' => ['Profile', 'profile.php', 'user', 'Profile'],
                'settings' => ['Settings', 'settings.php', 'settings', 'Settings']];
    }

    // header(): prints the top of every page: the head, top bar, menus and flash messages.
    // $active = the menu item to highlight; $opts['guest'] = true for pages used without signing in.
    public static function header(string $title, string $active = '', array $opts = []): void
    {
        // The signed-in user (nobody on guest pages).
        $user = $opts['guest'] ?? false ? null : Auth::user();
        $nav = self::nav($user);
        $app = App::config('app_name');
        $isAdmin = $user && $user['role'] === 'admin';
        // The number of open messages, shown as a red badge in the admin menu.
        $badge = $isAdmin ? Contact::openCount() : 0;
        // A CSS class on the body, so the stylesheet can style residents, admins and guests differently.
        $bodyClass = trim(($user ? ($isAdmin ? 'is-admin' : 'is-resident') : 'is-guest') . ' ' . ($opts['bodyClass'] ?? ''));
        // From here PHP writes HTML directly; every value is printed through self::e().
        ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php // The CSRF token goes in a meta tag, so core.js can send it with every fetch() call. ?>
<meta name="csrf-token" content="<?= self::e(Auth::csrfToken()) ?>">
<?php // The web folder, so JavaScript can build correct links. ?>
<meta name="base-path" content="<?= self::e(Http::basePath()) ?>">
<meta name="theme-color" content="#13294b">
<title><?= self::e($title) ?> · <?= self::e($app) ?></title>
<link rel="icon" href="<?= self::e(Http::url('assets/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= self::e(Http::url('assets/css/app.css')) ?>">
</head>
<body class="<?= self::e($bodyClass) ?>">
<a class="skip-link" href="#main">Skip to content</a>
<?php // Top bar: logo, desktop menu, help button, username and sign-out button. ?>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="<?= self::e(Http::url($user ? Auth::home($user) : 'login.php')) ?>"><?= self::logo() ?><span><?= self::e($app) ?></span><?php if ($isAdmin): ?><span class="brand-tag">Admin</span><?php endif; ?></a>
    <?php if ($nav): ?>
    <nav class="mainnav" aria-label="Main">
      <?php foreach ($nav as $key => [$label, $path, $icon]): ?>
        <a href="<?= self::e(Http::url($path)) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= self::icon($icon) ?><span><?= self::e($label) ?></span><?= $key === 'messages' && $badge ? '<span class="badge">' . $badge . '</span>' : '' ?></a>
      <?php endforeach; ?>
    </nav>
    <?php // Sign-out is a POST form with the CSRF token, so another site cannot sign people out with a link. ?>
    <form method="post" action="<?= self::e(Http::url('logout.php')) ?>" class="logout-form">
      <?= Auth::csrfField() ?>
      <?php if (!$isAdmin): ?><a class="btn-icon<?= $active === 'help' ? ' is-active' : '' ?>" href="<?= self::e(Http::url('help.php')) ?>" title="Help and contact" aria-label="Help and contact"><?= self::icon('help') ?></a><?php endif; ?>
      <span class="who"><?= self::e($user['username']) ?></span>
      <button class="btn-icon" type="submit" title="Sign out"><?= self::icon('logout') ?><span class="sr-only-sm">Sign out</span></button>
    </form>
    <?php endif; ?>
  </div>
</header>
<?php // Bottom tab bar for phones (the CSS shows it only on small screens). ?>
<?php if ($nav): ?>
<nav class="tabbar" aria-label="Main (mobile)">
  <?php foreach ($nav as $key => [$label, $path, $icon, $short]): ?>
    <a href="<?= self::e(Http::url($path)) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= self::icon($icon) ?><span><?= self::e($short) ?></span><?= $key === 'messages' && $badge ? '<span class="badge">' . $badge . '</span>' : '' ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
<?php // The main content of each page starts here. ?>
<main id="main" class="page <?= self::e($opts['mainClass'] ?? '') ?>">
<?php // Flash messages from the session are shown once, then removed. ?>
<div class="flash-stack" id="flash-stack" aria-live="polite">
<?php foreach ($_SESSION['flash'] ?? [] as [$type, $msg]): ?>
  <div class="alert alert-<?= self::e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>"><?= self::e($msg) ?></div>
<?php endforeach; unset($_SESSION['flash']); ?>
</div>
<?php
    }

    // footer(): closes the page and loads core.js plus the page's own scripts (there are no inline scripts).
    public static function footer(array $scripts = []): void
    {
        ?>
</main>
<div class="toast-region" id="toast-region" aria-live="polite" aria-atomic="true"></div>
<?php // Every page loads core.js first, then the scripts the page asked for, for example booking.js. ?>
<script src="<?= self::e(Http::url('assets/js/core.js')) ?>"></script>
<?php foreach ($scripts as $s): ?><script src="<?= self::e(Http::url('assets/js/' . $s)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
    }

    /** A short standalone message page (errors, access denied). */
    public static function errorPage(string $title, string $message, ?string $back = null): never
    {
        self::header($title, '', ['mainClass' => 'narrow']);
        echo '<section class="card empty-card"><h1>' . self::e($title) . '</h1><p class="muted">' . self::e($message) . '</p>';
        if ($back) echo '<p><a class="btn btn-primary" href="' . self::e(Http::url($back)) . '">Continue</a></p>';
        echo '</section>';
        self::footer();
        exit;
    }

    /** A password input with a Show/Hide button. */
    public static function passwordField(string $name, string $label, string $autocomplete, string $hint = '', bool $autofocus = false): string
    {
        $id = 'f-' . $name;
        // Builds the HTML for one password field with a Show/Hide button; values are escaped with self::e().
        return '<div class="field"><label for="' . $id . '">' . self::e($label) . '</label><div class="password-wrap">'
            . '<input id="' . $id . '" name="' . self::e($name) . '" type="password" required autocomplete="' . self::e($autocomplete) . '"' . ($autofocus ? ' autofocus' : '')
            . ($hint ? ' aria-describedby="' . $id . '-hint"' : '') . '>'
            . '<button type="button" class="password-toggle" aria-pressed="false" aria-label="Show password">Show</button></div>'
            . ($hint ? '<span class="hint" id="' . $id . '-hint">' . self::e($hint) . '</span>' : '') . '</div>';
    }

    public const PASSWORD_HINT = 'At least 10 characters, with letters and at least one number or symbol.';

    /** Status pill for a booking. */
    public static function statusPill(string $status): string
    {
        $labels = ['booked' => 'Upcoming', 'checked_in' => 'Checked in', 'completed' => 'Completed', 'cancelled' => 'Cancelled',
                   'released' => 'Released', 'pending' => 'Pending activation', 'active' => 'Active', 'inactive' => 'Deactivated'];
        return '<span class="pill pill-' . self::e($status) . '">' . self::e($labels[$status] ?? $status) . '</span>';
    }

    // date(): formats a date, for example 'D j M' gives "Tue 3 Nov".
    public static function date(string|DateTimeInterface $d, string $format = 'D j M'): string
    {
        $d = is_string($d) ? new DateTimeImmutable($d) : $d;
        return $d->format($format);
    }

    /** "Today", "Tomorrow" or "Wed 23 Oct". */
    public static function relDate(string|DateTimeInterface $d): string
    {
        $d = (is_string($d) ? new DateTimeImmutable($d) : DateTimeImmutable::createFromInterface($d))->setTime(0, 0);
        $diff = (int) Clock::today()->diff($d)->format('%r%a');
        return match ($diff) { 0 => 'Today', 1 => 'Tomorrow', -1 => 'Yesterday', default => $d->format('D j M') };
    }

    /** "45 min", "1 h", "1 h 30 min". */
    public static function duration(int $minutes): string
    {
        $h = intdiv($minutes, 60); $m = $minutes % 60;
        return $h ? $h . ' h' . ($m ? " $m min" : '') : "$m min";
    }

    // minutesBetween(): the minutes between two date-times, for example for "48 min".
    public static function minutesBetween(string $start, string $end): int
    {
        return (int) round((strtotime($end) - strtotime($start)) / 60);
    }

    // timeRange(): "13:52–14:52"; an end at midnight is shown as 24:00.
    public static function timeRange(string $start, string $end): string
    {
        $e = new DateTimeImmutable($end);
        return (new DateTimeImmutable($start))->format('H:i') . '–' . ($e->format('H:i') === '00:00' ? '24:00' : $e->format('H:i'));
    }
}
