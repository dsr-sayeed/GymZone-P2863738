# GymZone testing

GymZone is tested at four levels:

1. **Automated functional tests** (`tests/run_tests.php`): 49 tests of the business rules, the email flows and HTTP access control.
2. **Automated responsive layout check** (`tests/responsive_check.py`): every page at four screen widths.
3. **Manual walkthrough**: the full user journeys in a real browser, on a phone-sized screen.
4. **Usability walkthrough**: each functional requirement with a user interface, followed through the navigation without guidance.

## 1. Automated functional tests

### How to run

1. Start MySQL (XAMPP) and Mailpit (`mailpit --listen 127.0.0.1:8025`).
2. From the `gymzone` folder, run:

   ```
   php tests/run_tests.php
   ```

   On Windows: `C:\xampp\php\php.exe tests\run_tests.php`.

What the test run does:

- It builds a separate database, `gymzone_test` (or the name in `GYM_TEST_DB`), from `database/schema.sql` on every run. Your real data is never touched.
- It fixes the clock at Monday 2 November 2026, 10:05, so the results are the same every time. Time-based rules move the clock forward with `Clock::set()`.
- Email tests read the real messages back through Mailpit's API (`/api/v1/search`, `/api/v1/message/{id}`) and follow the links inside them. They also check headers such as Reply-To.
  - Without Mailpit, they fall back to the mail log and report the Mailpit-specific check as skipped.
- HTTP tests start PHP's built-in web server on port 8765 and use cURL with a cookie jar, like a browser.
- The concurrency test starts 8 separate PHP processes (`tests/concurrent_book.php`) that try to book the last 3 places at the same moment.
- Results are written to `tests/TEST_RESULTS.md`.

### Coverage of the required test areas

| Required area | Tests (see `TEST_RESULTS.md`) |
|---|---|
| Resident account creation | Pending account with apartment; duplicate email and invalid input refused; shared apartments; no public registration page |
| Account activation through Mailpit | Invitation arrives in Mailpit and the token is stored only as a hash; resend invalidates the old link; links expire after 24 hours; password set through the link, then the link stops working |
| Resident login and logout | Correct, wrong, pending and deactivated accounts; rate limit after 5 failures; HTTP sign-in, session cookie, sign-out; explanation when a second account is used in the same browser |
| Password recovery | Reset link by email; new password works; link single use; unknown email gets the same answer; links expire after 60 minutes |
| Email change verification | Current password required; the new address must be typed the same twice; an address in use is refused; the confirmation goes to the **current** address; the email changes only after confirmation; notices go to the old and new addresses; the link is single use |
| Password change verification | New password works only after confirmation; never sent in an email; other sessions end |
| Booking capacity | Any start minute with a chosen length; sessions past midnight; capacity checked at every moment with partly overlapping sessions; 8 simultaneous processes for 3 places (exactly 3 succeed) |
| Duplicate bookings | Second session in the same zone on the same day refused, also by the database unique key; all three zones on one day allowed, back to back |
| Overlapping bookings | Sessions overlapping by a few minutes refused |
| Three-week booking window | Today and day 21 allowed, day 22 refused; past and current times refused; calendar shows exactly 21 days; closed days, times outside the opening hours and closures refused |
| Booking cancellation | Place released and rebookable; cannot cancel someone else's booking or cancel twice; zone bookable again the same day |
| Check-in and checkout | Check-in window; no double counting; a walk-in at 13:52 runs 13:52–14:52; a walk-in with a chosen length; second check-in, zone already used and a full zone refused, with a shorter session suggested; check-out records the exact time and shows early leaving; leaving early has no effect; automatic check-out after 6 hours and the reported time; unused bookings released |
| Administrator authorization | Resident gets 403 on admin pages and APIs; visitors are redirected or get 401; missing CSRF token gets 419; administrators can use every admin page |
| Mobile responsiveness | Every page has a responsive viewport; see section 2 for the layout check |

Additional tests cover:

- what happens when Mailpit is unavailable;
- refusing a capacity below the most people booked at the same time;
- closing a zone (confirmation, cancellation, notification email);
- deactivation and reactivation emails;
- account deletion by the resident and by an administrator, with emails;
- contact messages from the Help page (stored for the admin, emailed with Reply-To);
- booking through the JSON API.

### Latest result

**49 passed, 0 failed, 0 skipped** (4 November 2026, 20:32). The full table with the actual messages is in `tests/TEST_RESULTS.md`.

The suite was also run with Mailpit switched off (`GYM_MAILPIT_URL` pointing to a closed port): 48 passed, and the one Mailpit-specific check was skipped.

The upgrade from the first version was tested separately:

1. A database was installed with the first version, with sample data.
2. The new code was then run against it.
3. The upgrade added the new table and columns, removed the fixed time slots, and switched the unchanged opening hours to 24 hours, with all accounts and bookings kept.

## 2. Responsive layout check

```
pip install playwright
python -m playwright install chromium
python tests/responsive_check.py http://localhost/gymzone resident@example.com "password" admin@example.com "password"
```

The script signs in as a resident and as an administrator. It then opens every page (6 resident pages and 5 admin pages) at four screen sizes:

- 360 × 740 (small phone)
- 390 × 844 (phone)
- 768 × 1024 (tablet)
- 1280 × 860 (desktop)

On each page it checks:

- the page is never wider than the screen (no sideways scrolling);
- the navigation is visible (the bottom tab bar on phones, for residents and administrators);
- navigation links are at least 40 px tall, so they are easy to tap;
- no JavaScript errors occur.

**Result: 44 of 44 page and width combinations passed.**

## 3. Manual walkthrough

Carried out on a 390 × 844 phone screen in Chromium, against Apache (the same set-up as XAMPP), with Mailpit running. Screenshots are in `docs/screenshots/`.

| # | Journey | Expected | Result |
|---|---|---|---|
| 1 | Open `install.php`, create the administrator, add sample data | "GymZone is ready"; opening `install.php` again shows "Already installed" | Pass |
| 2 | Admin: **Residents → Add resident** | Success message; resident listed as *Pending activation*; invitation in Mailpit | Pass |
| 3 | Open the invitation, choose a password, sign in | "Your account is active"; dashboard | Pass |
| 4 | **Check in** on a zone, choose 45 minutes | Session starts at the exact minute, for example "since 10:42 · planned until 11:27" | Pass |
| 5 | **Check out** | "You have checked out." "Thank you for using GymZone!" and the exact time in the gym | Pass |
| 6 | **Book**: next week → Weights → start 18:43, length 1 h 15 min | Live check "6 of 6 places free from 18:43 to 19:58"; "Booking confirmed … 18:43–19:58 (1 h 15 min)" | Pass |
| 7 | **Settings → Change email** (new address twice, current password) | Confirmation sent to the *current* address; after confirming, notices to both addresses | Pass |
| 8 | **Help** → send a message | Message saved; admin sees it under **Messages** (badge on the tab bar); email to the contact address with Reply-To | Pass |
| 9 | Open the sign-in page in a second tab while signed in | "You are already signed in", with an explanation and options | Pass |
| 10 | **Settings → Delete my account** (password + DELETE) | Signed out with a confirmation message; "Your GymZone account has been deleted" email | Pass |
| 11 | Admin: **Bookings** on a phone | Booked time and real time in the gym, marked "left early" where relevant; check out a resident | Pass |
| 12 | Resident still checked in after 6 hours | Automatic check-out; dashboard asks "When did you leave the gym?" (covered by automated test) | Pass |

### Browser and accessibility checks

- Keyboard:
  - all actions can be reached with Tab;
  - modals trap focus (native `<dialog>`) and close with Escape;
  - tabs switch with the arrow keys;
  - the FAQ uses native `<details>`.
- Forms:
  - every field has a `<label>`;
  - error and success messages are announced (`role="alert"` or `role="status"`).
- Content:
  - status is shown with text as well as colour ("Few places left", "Full", "Closed");
  - when motion reduction is turned on in the operating system, animations are switched off.
- The Content-Security-Policy allows scripts and styles only from the site itself. The browser console showed no CSP violations or JavaScript errors during the walkthrough.

## 4. Usability walkthrough

The walkthrough checked whether the main tasks of residents and administrators can be completed through GymZone's navigation without guidance. It was carried out by the developer on a 390 × 844 phone screen, where GymZone uses a bottom tab bar, and repeated on a desktop screen. The tasks come from the functional requirements in Chapter Four: each requirement that involves the user interface was followed from its starting point to its result.

Four questions were asked for each task:

- Can the task be started from the tab bar or the dashboard?
- How many screens are passed before it is complete?
- Does the system confirm the result, or explain a refusal in plain words?
- Can the task be completed with the keyboard alone?

| ID | Task (requirement) | Route through the interface | Feedback to the user | Outcome |
|---|---|---|---|---|
| W1 | See free places in a zone (FR1) | Sign in → dashboard; the zone cards are the first content | Free places out of capacity, status in words, live time | Completed; no extra navigation needed |
| W2 | Book a session (FR2, FR3) | Tab bar → booking page → date → zone → time → confirm | Live check of free places before confirming; confirmation with the exact times | Completed |
| W3 | Book when a zone becomes full (FR3) | Same as W2, choosing a period that fills the zone | Message saying when the zone becomes full, with a shorter session suggested | Completed; refusal understandable |
| W4 | Walk in and check out (FR5, FR8) | Dashboard → zone card → **Check in** → length → **Check out** | Start time shown; thank-you screen with the real time spent | Completed |
| W5 | Check in to a booking (FR6) | Dashboard → booking shown on the zone card → **Check in** | Session shown as in progress | Completed |
| W6 | Cancel a booking (FR9) | Tab bar → list of bookings → booking → **Cancel** → confirm | Confirmation window; place shown as free again | Completed |
| W7 | Get help or contact the office (FR13) | Help button (?) → Help page → message form | Office contact details, common questions, "message sent" notice | Completed |
| W8 | Change email or password (FR12) | Profile → Settings → change form | Notice that a confirmation email was sent | Completed |
| W9 | Change a zone capacity (FR11) | Admin → Gym → zone → capacity | Refusal when below the people already booked; success message otherwise | Completed |
| W10 | Close a zone temporarily, for example for repairs (FR10, FR11) | Admin → Gym → zone → **Close temporarily** → reason → confirm; later **Reopen zone** | Dialog stating how many bookings will be cancelled; residents emailed with the reason; closed zone listed on the admin dashboard; residents see the reason when booking or checking in | Completed; nothing changes before confirmation |
| W11 | Read resident messages (FR11) | Administrator tab bar → Messages | Count of new messages on the tab; list of messages | Completed |

All 11 tasks were completed.

### Keyboard and screen-reader access

The same tasks were repeated with the keyboard only:

- all actions could be reached with Tab and activated with Enter;
- the focused element was always visible;
- confirmation windows kept the focus inside them and closed with Escape;
- tabs could be changed with the arrow keys.

Form fields have labels, and success and error messages are announced to screen readers. No screen reader was used, so this part was checked by inspection only.

### Problem found and change made

One problem was found. All tabs of a browser share one sign-in, so when a second person signed in from another tab, the first account was replaced without any explanation. The sign-in page now explains this when someone who is already signed in opens it. This is covered by manual journey 9 and by an automated HTTP test.

### Limits

- The developer who designed the system carried out the walkthrough. It shows that every main task has a clear, working route through the interface, but not how residents who have never seen GymZone would experience it.
- A broken machine can only be handled by closing its whole zone, either until it is reopened or for a set period, because GymZone has no record of individual equipment.

A test with residents and a status for individual equipment are described as future work in the thesis.
