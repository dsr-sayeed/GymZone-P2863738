"""
GymZone responsive layout check (optional; needs Python 3 and Playwright:
    pip install playwright && python -m playwright install chromium).

    python tests/responsive_check.py http://localhost/gymzone resident@example.com 'password' admin@example.com 'password'

Opens every page at phone, tablet and desktop widths and checks that
  - nothing is wider than the screen (no sideways scrolling),
  - the main navigation is visible (a bottom tab bar on phones, for residents and administrators),
  - buttons and links in the navigation are at least 40 px tall (comfortable to tap),
  - no JavaScript errors occur.
Screenshots are saved in tests/screenshots/.
"""
# os and sys come with Python; playwright drives a real Chromium browser.
import os, sys
from playwright.sync_api import sync_playwright

# Command-line arguments: the address of GymZone, then a resident's and an administrator's email and password.
base = sys.argv[1].rstrip('/') + '/'
resident = (sys.argv[2], sys.argv[3])
admin = (sys.argv[4], sys.argv[5])
# Four screen sizes: width, height and a name.
widths = [(360, 740, 'phone-small'), (390, 844, 'phone'), (768, 1024, 'tablet'), (1280, 860, 'desktop')]
# The pages to check for each role.
pages = {'resident': ['dashboard.php', 'book.php', 'bookings.php', 'profile.php', 'settings.php', 'help.php'],
         'admin': ['admin/index.php', 'admin/residents.php', 'admin/gym.php', 'admin/bookings.php', 'admin/messages.php']}
# Screenshots are saved in tests/screenshots/.
out = os.path.join(os.path.dirname(__file__), 'screenshots')
os.makedirs(out, exist_ok=True)
failures, rows = [], []

# For each role and each width: sign in, then open each page and measure it.
with sync_playwright() as p:
    browser = p.chromium.launch()
    for role, (email, pw) in [('resident', resident), ('admin', admin)]:
        for w, h, label in widths:
            page = browser.new_page(viewport={'width': w, 'height': h})
            errors = []
            # Collect JavaScript errors that happen on the page.
            page.on('pageerror', lambda e: errors.append(str(e)))
            page.goto(base + 'login.php')
            page.fill('#email', email); page.fill('#f-password', pw); page.click('button[type=submit]')
            page.wait_for_load_state()
            for path in pages[role]:
                page.goto(base + path); page.wait_for_timeout(600)
                # Overflow above 0 means the page is wider than the screen (sideways scrolling).
                overflow = page.evaluate('document.documentElement.scrollWidth - window.innerWidth')
                # Phones use the bottom tab bar; larger screens use the top menu.
                nav = '.tabbar' if w <= 760 else '.mainnav'
                nav_ok = page.locator(nav).is_visible()
                # Navigation links lower than 40 px are hard to tap.
                small = page.evaluate(f"Array.from(document.querySelectorAll('{nav} a')).filter(a => a.getBoundingClientRect().height < 40).length")
                ok = overflow <= 0 and nav_ok and small == 0 and not errors
                rows.append((role, path, label, overflow, nav_ok, small, ok))
                if not ok: failures.append(f'{role} {path} @ {w}px: overflow={overflow} nav={nav_ok} small-targets={small} errors={errors}')
                page.screenshot(path=os.path.join(out, f"{path.replace('/', '_').replace('.php', '')}-{label}.png"), full_page=True)
            page.close()
    browser.close()

# Print a table of results, and exit with 1 if anything failed.
print(f"{'Role':9} {'Page':22} {'Width':12} {'Overflow':>8} {'Nav':>4} {'Small':>5}  Result")
for r in rows:
    print(f"{r[0]:9} {r[1]:22} {r[2]:12} {r[3]:>8} {str(r[4]):>4} {r[5]:>5}  {'PASS' if r[6] else 'FAIL'}")
print(f"\n{len(rows) - len(failures)} of {len(rows)} page/width combinations passed.")
for f in failures: print('  ', f)
sys.exit(1 if failures else 0)
