# Appendix D: Testing Documentation and Full Automated Results

*Appendix to the BSc (Hons) Computer Science thesis "Development of a Real-Time Availability and Booking System for Communal Gym Facilities" by Dewan Sayeed Raja (P2863738).*

GymZone was tested through automated functional tests, an automated responsive-layout check and a manual browser walkthrough. The usability walkthrough is reported separately in [Appendix F](Appendix-F-Usability-Walkthrough.md). The details below are taken from the project testing documentation ([docs/TESTING.md](../docs/TESTING.md)) and the recorded final test run ([tests/TEST_RESULTS.md](../tests/TEST_RESULTS.md)).

## Testing approach

- **Automated functional tests:** 50 tests of business rules, email flows and HTTP access control (`tests/run_tests.php`).
- **Automated responsive-layout check:** every resident and administrator page at four screen sizes (`tests/responsive_check.py`).
- **Manual walkthrough:** twelve end-to-end user journeys in a browser on a phone-sized screen.
- The automated test database (`gymzone_test`) is rebuilt from the schema on each run, and the application clock is fixed (Monday 2 November 2026, 10:05) so that time-based tests are repeatable.
- The concurrency test (`tests/concurrent_book.php`) starts eight separate PHP processes competing for the last three places in a zone.

## Table D.1. Full automated functional-test results

Run 2026-11-12 11:09 with `php tests/run_tests.php`.

**Result: 50 passed, 0 failed, 0 skipped (50 tests).**

| Area | Test | Result | Details |
|---|---|---|---|
| Accounts | An administrator creates a pending resident with an apartment | PASS | Pending account, apartment 1A, username anna.jensen, invitation sent: yes |
| Accounts | Duplicate email addresses and invalid input are refused | PASS | This email address is already registered. / Please enter a valid email address. / Apartment numbers can use letters, digit… |
| Accounts | Several residents can share one apartment | PASS |  |
| Accounts | There is no public registration page | PASS |  |
| Activation | The invitation email reaches Mailpit with a single-use activation link | PASS | Subject "Welcome to GymZone", token stored only as a hash |
| Activation | Resending an invitation invalidates the earlier link | PASS |  |
| Activation | Expired links (after 24 hours) are refused | PASS | This activation link has expired. Ask the housing office to send you a new invitation. |
| Activation | The resident sets a password through the link; the link then stops working | PASS | Weak: Your password must be at least 10 characters long. / Mismatch: The two passwords do not match. / Reuse: This activation link has already been used. Ask the housing office to send you a new invitation. |
| Login | Correct password signs in; wrong password, pending and deactivated accounts are refused | PASS | Wrong: The email address or password is incorrect. / Pending: This account has not been activated yet. Use … / Deactivated: This account has been deactivated. Please contact the housing office. |
| Login | Five wrong passwords block sign-in for 15 minutes (rate limiting) | PASS | Blocked: Too many sign-in attempts. Please wait 15 minutes and try again, or reset your password. / After 16 minutes: signed in |
| Recovery | Forgot password sends a reset link; the new password works and the link is single use | PASS | Delivered via mailpit; old password refused; reuse: This password reset link has already been used. You can request a new one. |
| Recovery | Unknown emails get the same answer and no email is sent | PASS |  |
| Recovery | Reset links expire after 60 minutes | PASS | This password reset link has expired. You can request a new one. |
| Email change | The confirmation goes to the CURRENT address; the email changes only after it is opened | PASS | Wrong password: Your current password is incorrect. / Typo: The two email addresses do not match. / Taken: This email address is already used by another account. / Notices: old "Your GymZone email address was changed", new "This is now your GymZone email address" / Reuse: This email confirmation link has already been used. |
| Password change | The new password starts working only after email confirmation; other sessions end | PASS | Wrong current password: Your current password is incorrect. |
| Mail | When Mailpit is unavailable, accounts are still created and nothing is half-changed | PASS | Account created with "email not sent" flag; email change refused: We could not send the confirmation email right now. Your email has not been changed. Please try again in a few minutes. |
| Booking | Sessions start at any minute and last the chosen length | PASS | 13:52 + 60 min → 13:52–14:52; 07:07 + 35 min → 07:07–07:42 / Too short: A session can last from 15 minutes to 4 h. / Too long: A session can last from 15 minutes to 4 h. |
| Booking | The gym is open 24 hours by default; a session may continue past midnight | PASS |  |
| Capacity | A zone is never over capacity at any moment, also with partly overlapping sessions | PASS | Full: Sorry, Weights is fully booked at 18:50. Please choose another time. / Partly full: Weights is full from 18:45. You can choose a shorter session, until 18:45 (25 min). / 19:15–19:45 allowed |
| Capacity | Simultaneous requests cannot overbook (8 processes, 3 places) | PASS | 3 succeeded, 5 refused, 3 stored |
| Duplicates | One session per zone per resident per day (also enforced by a unique key) | PASS | You already have a Cardio session on Thu 5 Nov at 07:00–08:00. You can use each zone once per day. / Direct SQL insert: unique key refused the row |
| Duplicates | All three zones can be booked on the same day at different times | PASS |  |
| Overlap | Sessions cannot overlap, even by a few minutes | PASS | You already have a Cardio session 17:00–18:00. Sessions cannot overlap; choose another time or a shorter session. |
| Window | Bookings are possible from today up to 20 days ahead (21 days), not before or after | PASS | Past: This time has already passed. To train now, use "Check in" on the dashboard. / Now: This time has already passed. To train now, use "Check in" on the dashboard. / Day 22: You can book today and up to 20 days ahead. |
| Window | Closed days, times outside the opening hours and closures cannot be booked | PASS | Closed day: The gym is closed at 10:00 on Saturday 7 November. / Before opening: The gym is closed at 05:30 on Tuesday 3 November (opening hours: 06:00–22:00). / Past closing: The gym closes at 22:00, before this session would end. Please choose a shorter session or an earlier time. / Closure: Machines is not available during this time. Closed 06:00–10:00: Machine service. |
| Cancellation | Cancelling releases the place; only upcoming bookings of your own can be cancelled | PASS | Places taken 3 → 2, then rebooked by another resident / Someone else's booking: Booking not found. / Twice: This booking is already cancelled. |
| Cancellation | A cancelled booking does not block booking the same zone again that day | PASS |  |
| Check-in | Check-in with a booking is possible from 10 minutes before to 15 minutes after the start | PASS | Early: Check-in for this session opens today at 10:50. / Twice: You are already checked in to this session. |
| Check-in | Checking in with a booking does not count the resident twice | PASS |  |
| Walk-in | A walk-in at 13:52 runs 13:52–14:52, from the exact minute of check-in | PASS | Default length: 13:52–14:52 / Chosen 45 min: 15:20–16:05 |
| Walk-in | Refused for a second active check-in, a zone used today, a full zone; suggests a shorter session | PASS | Second check-in: You are already checked in to Weights. Please check out first. / Same zone again: You have already trained in Machines today. You can use each zone once per day. / Until the next booking: Weights is full from 16:00. You can choose a shorter session, until 16:00 (30 min). / Full: Sorry, Weights is full right now. |
| Check-out | Check-out records the exact time; leaving early is shown with the real times, also to administrators | PASS | Booked 11:00–12:00, in the gym 10:52–11:40 (48 min) / Second checkout: You are not checked in. |
| Check-out | Leaving early has no effect on future bookings (no penalties) | PASS |  |
| Check-out | After 6 hours a resident is checked out automatically and asked for the real time | PASS | Auto check-out at 18:10 / Out of range: Please enter a time between 12:10 (when you checked in) and 18:10. / Reported: 12:10–13:45 |
| Check-in | Unused bookings are released after the check-in period | PASS |  |
| Admin | Capacity cannot be reduced below the most people booked at the same time | PASS | The capacity cannot be lower than 3: that many people are booked at the same time on Fri 20 Nov at 18:20. Cancel bookings first if the capacity must be reduced. / Raising it to 6 is accepted |
| Admin | A zone notice is shown on the dashboard, kept when not sent, and removed when cleared | PASS | Too long: A notice can be at most 255 characters. / Cleared notice is stored as NULL |
| Admin | Closing a zone asks for confirmation, then cancels affected bookings and emails residents | PASS | 1 booking(s) cancelled after confirmation; residents notified |
| Accounts | Deactivating and reactivating a resident sends an email each time | PASS |  |
| Accounts | A resident can delete their own account (password and DELETE required); a confirmation email is sent | PASS | Wrong password: Your current password is incorrect. / Missing DELETE: Type DELETE in capital letters to confirm. |
| Accounts | An administrator can delete a resident; the resident is emailed | PASS |  |
| Contact | A resident message is stored for the admin and emailed to the contact address with Reply-To | PASS | Too short: Please write a little more so the housing office can help (at least 10 characters). / Sent to the contact address with Reply-To |
| Login | Sign-in and sign-out over HTTP (session cookie, redirects, security headers) | PASS | Signed in → dashboard 200 → signed out → dashboard redirects to sign-in |
| Authorization | Residents cannot open admin pages or call admin APIs | PASS | Admin page: 403, admin API: 403, nothing changed |
| Authorization | Visitors who are not signed in are sent to sign-in; APIs answer 401 | PASS |  |
| Authorization | Requests without the CSRF token are rejected | PASS |  |
| Authorization | Administrators can use the admin pages and API | PASS |  |
| Booking | A resident checks a time, books and cancels through the JSON API | PASS | Booked Cardio Tuesday 10 November 2026 07:23–08:03, then cancelled |
| Login | A second sign-in in the same browser explains that tabs share one account | PASS |  |
| Pages | Every page responds without errors | PASS | Sign-in and forgot-password pages have no contact link; contact.php: 404 / Resident pages: 200 with responsive viewport |

## Responsive and manual checks

The responsive check covered 11 pages (six resident pages and five administrator pages) at four screen sizes, 360 × 740, 390 × 844, 768 × 1024 and 1280 × 860 pixels (44 combinations), and passed all 44. The manual walkthrough covered twelve user journeys; all produced the expected result. The complete testing procedure is described in [docs/TESTING.md](../docs/TESTING.md).

---
[Back to the list of appendices](README.md) · [Back to the repository](../README.md)
