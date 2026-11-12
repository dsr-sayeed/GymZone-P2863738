# GymZone: residential gym booking and availability

> **Final year project artefact.** BSc (Hons) Computer Science thesis *"Development of a Real-Time Availability and Booking System for Communal Gym Facilities"* by Dewan Sayeed Raja (P2863738). Supervisor: Nadine Sandjo Tchatchoua.
>
> **Thesis appendices:** [A. Requirements](appendices/Appendix-A-Requirements.md) · [B. Design change log](appendices/Appendix-B-Design-Change-Log.md) · [C. Evaluation traceability](appendices/Appendix-C-Evaluation-Traceability.md) · [D. Testing and results](appendices/Appendix-D-Testing-and-Results.md) · [E. Source code and installation](appendices/Appendix-E-Source-Code-and-Installation.md) · [F. Usability walkthrough](appendices/Appendix-F-Usability-Walkthrough.md)
>
> **Database script:** [`database/schema.sql`](database/schema.sql). Sample data is created by the installer (see step 4 below).

GymZone is a web application for a residential housing community's gym. Residents can:

- see how busy each gym zone is right now;
- book a session at any minute they like, for as long as they need, up to three weeks ahead;
- check in and out, and cancel bookings;
- manage their own account;
- contact the housing office.

The app is designed for phones first: residents and administrators get a bottom tab bar on small screens.

Housing administrators:

- create resident accounts, which are activated by an email invitation;
- manage apartments, zones and capacities;
- set opening hours, sessions and closures;
- manage bookings.

**Technology:**

- HTML5, CSS3 and vanilla JavaScript, with the Fetch API for asynchronous requests
- PHP 8.1+ with PDO, and MySQL/MariaDB
- XAMPP for local development
- Mailpit for testing email

No frameworks are used.

More documentation:

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md): how the system is built, the data model and the build phases
- [docs/TESTING.md](docs/TESTING.md): test plan, automated tests, the responsive layout check and manual checks
- [tests/TEST_RESULTS.md](tests/TEST_RESULTS.md): results of the latest automated test run

---

## 1. Requirements

| Software | Version | Notes |
|---|---|---|
| XAMPP | 8.1 or later | Includes Apache, PHP 8 and MariaDB. The PHP extensions `pdo_mysql` and `curl` are enabled by default. |
| Mailpit | 1.x | A local email inbox for testing. A single program file; no installation needed. |
| A modern browser | | Chrome, Edge, Firefox or Safari |

MySQL 8.0+ also works instead of MariaDB.

## 2. Installation on XAMPP

### Step 1: Copy the files

Download or clone this repository and rename the folder to `gymzone` (the addresses below assume that name). Copy the `gymzone` folder into XAMPP's `htdocs` folder:

- Windows: `C:\xampp\htdocs\gymzone`
- macOS: `/Applications/XAMPP/htdocs/gymzone`

### Step 2: Start Apache and MySQL

Open the XAMPP Control Panel and start **Apache** and **MySQL**.

### Step 3: Start Mailpit

1. Download Mailpit for your system from <https://github.com/axllent/mailpit/releases> (for example `mailpit-windows-amd64.zip`) and unzip it.
2. In a terminal opened in that folder, run:

   ```
   mailpit --listen 127.0.0.1:8025 --smtp 127.0.0.1:1025
   ```

   (On Windows: `mailpit.exe --listen 127.0.0.1:8025 --smtp 127.0.0.1:1025`.)
3. Open <http://localhost:8025> to see the Mailpit inbox. Every email GymZone sends appears here.

`--listen 127.0.0.1:8025` keeps the Mailpit inbox and API on your own computer. **Never expose Mailpit to a network or the internet.**

### Step 4: Run the installer

1. Open <http://localhost/gymzone/install.php>. The installer creates the `gymzone` database and all tables. It also sets up the three default zones and the default rules:
   - Cardio: 10 places
   - Weights: 6 places
   - Machines: 8 places
   - open 24 hours, every day
   - a suggested session length of 1 hour, and a longest session of 4 hours
2. Enter the first administrator's name, email and password.
3. Leave **Add sample data** ticked for a demonstration, and choose a password for the sample residents.
4. Click **Install GymZone**.
5. Delete `install.php` afterwards. It already refuses to run a second time, because it writes `storage/install.lock`.

No default administrator password exists anywhere in the source code. You choose it during installation.

**Command-line alternative** (from the `gymzone` folder; on Windows use `C:\xampp\php\php.exe`):

```
php scripts/install.php --name="Housing Office" --email=office@example.com --sample-data
```

The command asks for the passwords without showing them as you type. For automated set-ups it can instead read them from the environment variables `GYM_ADMIN_PASSWORD` and `GYM_SAMPLE_PASSWORD`.

### Step 5: Open GymZone

Open <http://localhost/gymzone> and sign in.

| Account | Email | Password |
|---|---|---|
| Administrator | the email you entered | the password you chose |
| Sample residents (optional) | for example `anna.jensen@example.com`, `mikkel.jensen@example.com`, `sofie.nielsen@example.com` | the sample password you chose |

The sample data includes:

- 12 residents: 10 active, 1 waiting for activation (`clara.madsen@example.com`, whose invitation is in Mailpit) and 1 deactivated
- two shared apartments
- two weeks of past sessions at varied times, some of them left early
- residents checked in right now
- upcoming bookings
- a planned machine service

**Updating from the first GymZone version:** copy the new files over the old ones and keep your `config/config.php`. The database is upgraded automatically the first time a page is opened:

- the fixed one-hour slots are replaced by free start times;
- if the opening hours were never changed, the gym becomes open 24 hours;
- the new tables and columns are added.

Existing zones, accounts and bookings are kept.

## 3. Configuration

All settings are in `config/config.php`. Each can also be set with an environment variable.

| Setting | Variable | Default | Purpose |
|---|---|---|---|
| `app_url` | `GYM_APP_URL` | detected | Address used in email links, for example `http://localhost/gymzone` |
| `timezone` | `GYM_TIMEZONE` | `Europe/Copenhagen` | Session times are local to the gym |
| `debug` | `GYM_DEBUG=1` | off | Show error details in the browser (development only) |
| `db.host`, `db.port` | `GYM_DB_HOST`, `GYM_DB_PORT` | `127.0.0.1`, `3306` | Database server |
| `db.name` | `GYM_DB_NAME` | `gymzone` | Database name |
| `db.user`, `db.pass` | `GYM_DB_USER`, `GYM_DB_PASS` | `root`, empty | XAMPP defaults |
| `mail.transport` | `GYM_MAIL_TRANSPORT` | `mailpit` | `mailpit` (HTTP API) or `log` (writes emails to `storage/logs/mail.log`) |
| `mail.mailpit_url` | `GYM_MAILPIT_URL` | `http://127.0.0.1:8025` | Where Mailpit listens |
| `mail.from_email` | `GYM_MAIL_FROM` | `no-reply@gymzone.local` | Sender address |

Administrators change the booking rules in the app (**Gym → Hours and rules**):

- opening hours for each weekday (00:00–24:00 means open around the clock)
- the suggested session length (60 minutes by default) and the longest session (4 hours by default)
- how many minutes before a booked start check-in opens (10 by default)
- how many minutes after the start an unused booking is released (15 by default)
- after how many hours a resident who forgot to check out is checked out automatically (6 by default)

The contact details shown on the Help page are set under **Messages**. The contact email starts as the first administrator's address.

## 4. Using GymZone

### Residents

On a phone, the bottom tab bar has Home, Book, Bookings, Profile and Settings. The **?** button at the top opens Help.

| Page | What it does |
|---|---|
| **Home** | How many places are free in each zone right now, how many people are in the gym and how many are booked. It also shows your active session (with **Check out**), your next booking (with **Check in** when it is time) and a **Book a session** button. Numbers refresh every 15 seconds. **Check in** on a zone card is a walk-in: the session starts at that exact minute (for example 13:52–14:52), and you choose how long. |
| **Book** | One page: date (21 days in three weeks) → zone → **start time** (any minute) and **length** (15 minutes to 4 hours) → review and confirm. GymZone checks the exact time as you type and shows how many places are free for it. An hour-by-hour overview shows when the zone is quiet. |
| **Bookings** | Upcoming, Completed and Cancelled. Completed sessions show the exact time you were in the gym, for example "In the gym 13:52–14:20 (28 min)". Cancelling asks for confirmation. |
| **Profile** | Full name, apartment, username and email, all read-only, plus your sessions in the last 30 days. |
| **Settings** | Change the **username**. Change the **email**: the new address is typed twice, and the confirmation link goes to your *current* address. Change the **password**, confirmed by email. Sign out. At the very bottom, a discreet **Delete my account**. |
| **Help** | Contact details of the housing office, a form to send them a message, your recent messages, and answers to common questions. |
| **After check-out** | The screen shows "You have checked out." and "Thank you for using GymZone!", with the exact time in the gym (for example 13:52–14:20, 28 min). |

### Booking rules

- Book today and up to 20 days ahead (21 calendar days including today).
- Sessions start at any minute and last from 15 minutes up to the longest session (4 hours by default). A session may continue past midnight.
- One session per zone per resident per day. All three zones can be booked on the same day.
- Your sessions cannot overlap.
- A zone is never booked beyond its capacity at any moment. If a zone fills up part-way through the time you chose, GymZone says from when and suggests a shorter session.
- Check-in opens 10 minutes before a booked start. A booking not checked in within 15 minutes of the start is released so someone else can use the place.
- Walk-in check-in starts a session at the current minute. It works when the zone is open with a free place for the whole length, and you have not used or booked that zone that day.
- Only one active check-in per resident at a time.
- **Automatic check-out:** if you forget to check out, GymZone checks you out 6 hours after check-in. The next time you open the app, it asks when you really left; you can enter the time or confirm the automatic one. Administrators see which times were automatic or reported.
- There are no penalties, tokens or cooldowns. Leaving early or a released booking never affects future bookings.
- **Two accounts in two tabs:** all tabs of one browser share the same sign-in, as on most websites. To use a second account at the same time, open a private (incognito) window or another browser. If you open the sign-in page while signed in, GymZone explains this.

### Administrators

On a phone, the bottom tab bar has Home, Residents, Gym, Bookings and Messages. A red badge shows open messages.

| Page | What it does |
|---|---|
| **Overview** | Total residents, active accounts, pending activations, today's sessions, people in the gym now, open messages, zone availability, who is checked in (with their planned end), planned closures. |
| **Residents** | **Add resident** (full name, apartment, email) sends an activation email valid for 24 hours. You can search; open a resident; change their name or apartment; **Resend invitation**; **Deactivate** or **Reactivate**, which emails the resident; and, in a discreet section, **Delete account**, which also emails them. Deactivating cancels the resident's upcoming bookings. |
| **Gym** | **Zones**: name, description, capacity, a notice for residents (for example "1 treadmill is out of order"), temporary closure. **Hours and rules**: weekly hours, session lengths, check-in rules, automatic check-out. **Closures**: close the whole gym or one zone for a period. |
| **Bookings** | View by date, zone and status. Each row shows the booked time and the real time in the gym, marked "left early", "stayed longer", "automatic check-out" or "time reported by resident". You can cancel a booking with a reason (the resident is emailed) and check out a resident who forgot. |
| **Messages** | Messages residents send from Help: reply by email, and mark them handled or open again. Also the contact details shown to residents. |

Changes that would cancel upcoming bookings (closing a zone, a closure, changed opening hours) show how many bookings are affected and wait for the administrator to confirm. A zone's capacity cannot be set below the highest number of people already booked at the same time.

## 5. Email (Mailpit)

GymZone sends email by posting JSON to Mailpit's HTTP API (`POST http://127.0.0.1:8025/api/v1/send`) with PHP cURL. No SMTP is configured in PHP.

| Email | When |
|---|---|
| Welcome / activation | An administrator adds a resident |
| New activation link | An administrator resends an invitation (earlier links stop working) |
| Reset your password | **Forgot your password?** |
| Confirm your new password | **Settings → Change password** |
| Confirm the change of your email | **Settings → Change email**, sent to the resident's *current* address |
| Your email address was changed / This is now your email address | After an email change: one to the old address, one to the new one |
| Your account has been deactivated / is active again | An administrator deactivates or reactivates the account |
| Your account has been deleted | The resident deletes their account, or an administrator does |
| Your booking was cancelled | An administrator cancels a booking, or a closure or schedule change does |
| GymZone message: … | A resident sends a message from Help (to the contact address, with the resident as Reply-To) |

All links are single-use and time-limited:

- activation and email change: 24 hours
- password reset and password change: 60 minutes

Only a SHA-256 hash of each link token is stored in the database.

**If Mailpit is not running:**

- Adding a resident still creates the account. The administrator sees a warning and can use **Resend invitation** later.
- Contact messages are still saved and appear under **Messages**.
- Email and password changes are not started, and the resident is asked to try again.
- Forgot-password requests always show the same neutral message, and the failure is logged.

## 6. Scheduled maintenance (optional)

The app releases unused bookings and does the 6-hour automatic check-out on every page load. To keep the records correct when nobody is using the site, run this every 5 minutes:

```
php scripts/maintenance.php
```

- Windows: use **Task Scheduler**, with the program `C:\xampp\php\php.exe` and the argument `C:\xampp\htdocs\gymzone\scripts\maintenance.php`.
- macOS or Linux: add a cron job: `*/5 * * * * php /path/to/gymzone/scripts/maintenance.php`.

## 7. Tests

```
php tests/run_tests.php
```

On Windows: `C:\xampp\php\php.exe tests\run_tests.php`.

The test run:

- builds a separate database (`gymzone_test`) and fixes the clock, so results are the same every time;
- starts PHP's built-in web server for the HTTP tests;
- reads emails back from Mailpit to follow the real links.

**49 tests** cover:

- account creation, activation through Mailpit, sign-in and sign-out
- password recovery, email change (confirmed at the current address) and password change
- deactivation, reactivation and deletion emails, and contact messages with Reply-To
- any-minute start times, chosen lengths and sessions past midnight
- capacity with partly overlapping sessions, including a concurrency test with 8 simultaneous processes
- duplicate and overlapping bookings, the 21-day window, opening hours and closures, cancellation
- walk-ins from the exact minute, check-out times, leaving early, and the 6-hour automatic check-out with a reported time
- administrator operations and authorization

Results are written to `tests/TEST_RESULTS.md`. The responsive layout check (`tests/responsive_check.py`, Python + Playwright) is described in [docs/TESTING.md](docs/TESTING.md).

## 8. Folder structure

```
gymzone/
├── index.php, login.php, logout.php          Entry and sign-in
├── forgot-password.php, reset-password.php   Password recovery
├── activate.php                              Account activation from the invitation link
├── verify.php                                Email-change and password-change confirmation links
├── dashboard.php, book.php, bookings.php     Resident pages
├── profile.php, settings.php, help.php, checked-out.php
├── install.php                               One-time web installer (delete after use)
├── admin/                                    Admin pages: index, residents, resident, gym, bookings, messages
├── api/                                      JSON endpoints for fetch(): availability, bookings, attendance, account, contact
│   └── admin/                                Admin endpoints: residents, gym, bookings, messages
├── app/                                      Application code (blocked from the browser)
│   ├── bootstrap.php                         Autoloading, security headers, error handling, session, maintenance
│   ├── core/                                 App, Db, Auth, Http, View, Validator, RateLimiter, Clock, exceptions
│   ├── services/                             Business logic: Bookings, Attendance, Availability, Schedule, Zones,
│   │                                         Residents, Account, Contact, Tokens, Mailer, EmailTemplates,
│   │                                         Settings, Installer, Upgrade, Maintenance
│   └── views/Components.php                  Reusable HTML components (zone card, booking item, empty state)
├── assets/css/app.css                        All styles (responsive)
├── assets/js/                                core.js (fetch, modals, toasts) + one script per page
├── config/config.php                         Configuration
├── database/schema.sql                       Complete database schema
├── scripts/                                  Command line: install, create-admin, seed-sample, maintenance
├── storage/logs/                             Mail log (log transport) and install lock
├── tests/                                    Automated tests, concurrency worker, responsive check, results
├── docs/                                     Architecture, testing and screenshots
└── appendices/                               Thesis appendices A–F (linked from the thesis)
```

## 9. Security summary

- **Passwords:** hashed with `password_hash()`, and the hashes are upgraded automatically. Passwords need at least 10 characters with letters and a number or symbol. A plaintext password is never stored.
- **Sessions:**
  - the session cookie is `HttpOnly`, `SameSite=Lax`, and `Secure` on HTTPS, with strict mode on;
  - the session ID is regenerated at sign-in;
  - sessions end after 2 hours without activity;
  - a password change or reset signs out all other devices.
- **Authorization:** checked on every page and endpoint. Residents get 403 on admin pages and APIs. Every POST checks a CSRF token, sent as a form field or the `X-CSRF-Token` header.
- **Database and input:**
  - every database query uses PDO prepared statements;
  - all input is validated on the server and all output is escaped;
  - booking rules are enforced on the server with transactions and row locks, backed by database constraints.
- **Rate limiting:**
  - sign-in: 5 attempts per email and 20 per IP address in 15 minutes
  - password reset requests: 3 per email and 10 per IP address per hour
  - invalid verification links: 20 per IP address in 15 minutes
  - re-entering the current password: 5 per 15 minutes
  - email or password change requests: 5 per hour
  - contact messages: 5 per resident per hour
  - invitations: 5 per resident per hour
- **Headers:** Content-Security-Policy (scripts and styles only from the site itself), X-Frame-Options, X-Content-Type-Options, Referrer-Policy and Permissions-Policy.
- **Errors and files:**
  - visitors see a friendly error page; details go to the PHP error log;
  - `.htaccess` blocks `app/`, `config/`, `database/`, `scripts/`, `storage/`, `tests/`, `docs/` and documentation files from the browser.

## 10. Troubleshooting

| Problem | Solution |
|---|---|
| "GymZone is not set up yet, or the database cannot be reached" | Start MySQL in XAMPP, check `config/config.php`, then open `install.php`. |
| "Resident added, but the activation email could not be sent" | Start Mailpit (step 3), then click **Resend invitation**. |
| Email links point to the wrong address | Set `app_url` in `config/config.php`, for example `http://localhost/gymzone`. |
| Times look wrong | Set `timezone` in `config/config.php`. |
| "Too many sign-in attempts" | Wait 15 minutes or use **Forgot your password?**. |
| A second account in another tab signs out the first | Tabs share one sign-in. Use a private window or another browser for the second account. |
| The time picker shows AM/PM | The browser follows the phone's or computer's time format; GymZone stores and shows times in 24-hour format. |
