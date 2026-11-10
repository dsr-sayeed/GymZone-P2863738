# Appendix E: Source Code, Installation Guide and Further Screenshots

*Appendix to the BSc (Hons) Computer Science thesis "Development of a Real-Time Availability and Booking System for Communal Gym Facilities" by Dewan Sayeed Raja (P2863738).*

The complete GymZone source code is held in this repository. It contains the application source code, the database script (`database/schema.sql`), project documentation, screenshots and the test suite. The full installation and usage guide is in the [README](../README.md).

## Table E.1. Main contents of the repository

| Path | Purpose |
|---|---|
| app/core/ | Application infrastructure: authentication, database access, clock, validation, rate limiting and views. |
| app/services/ | Business rules and services for bookings, availability, attendance, schedules, residents, accounts, email and maintenance. |
| api/ | JSON endpoints used by resident and administrator actions. |
| database/schema.sql | Database schema, generated columns, keys and constraints. |
| assets/ | Responsive CSS and browser-side JavaScript. |
| docs/ | Architecture, testing documentation and interface screenshots. |
| tests/run_tests.php | Automated functional test suite. |
| tests/responsive_check.py | Automated responsive-layout check. |
| README.md | Installation, configuration and usage guide. |

## Installation summary

1. Use PHP 8.1 or later with MySQL/MariaDB; the project is designed to run on a standard XAMPP installation.
2. Copy the project folder into XAMPP's `htdocs` folder as `gymzone` and, if needed, adjust the database settings in `config/config.php` or with environment variables.
3. Open `http://localhost/gymzone/install.php` (or run `php scripts/install.php`) to create the database tables, the first administrator account and optional sample data.
4. Mailpit is used during development and testing to capture application emails without sending real messages.
5. Run `php tests/run_tests.php` to rebuild the separate test database and execute the automated functional test suite.

## Further screenshots

**Resident dashboard on a desktop screen**

<img src="../docs/screenshots/dashboard-desktop.png" alt="Resident dashboard on a desktop screen" width="360">

**Resident dashboard while checked in (phone)**

<img src="../docs/screenshots/dashboard-checked-in-mobile.png" alt="Resident dashboard while checked in (phone)" width="360">

**Walk-in check-in (phone)**

<img src="../docs/screenshots/walkin-mobile.png" alt="Walk-in check-in (phone)" width="360">

**Booking page (phone)**

<img src="../docs/screenshots/booking-mobile.png" alt="Booking page (phone)" width="360">

**Booking page (desktop)**

<img src="../docs/screenshots/booking-desktop.png" alt="Booking page (desktop)" width="360">

**Completed bookings with real times (phone)**

<img src="../docs/screenshots/bookings-completed-mobile.png" alt="Completed bookings with real times (phone)" width="360">

**Check-out confirmation (phone)**

<img src="../docs/screenshots/checked-out-mobile.png" alt="Check-out confirmation (phone)" width="360">

**Profile (phone)**

<img src="../docs/screenshots/profile-mobile.png" alt="Profile (phone)" width="360">

**Settings (phone)**

<img src="../docs/screenshots/settings-mobile.png" alt="Settings (phone)" width="360">

**Account deletion (phone)**

<img src="../docs/screenshots/delete-mobile.png" alt="Account deletion (phone)" width="360">

**Help and contact for signed-in residents (phone)**

<img src="../docs/screenshots/help-mobile.png" alt="Help and contact for signed-in residents (phone)" width="360">

**Sign-in page (phone)**

<img src="../docs/screenshots/login-mobile.png" alt="Sign-in page (phone)" width="360">

**Notice when a second account signs in from the same browser**

<img src="../docs/screenshots/already-signed-in-mobile.png" alt="Notice when a second account signs in from the same browser" width="360">

**Administrator overview (phone)**

<img src="../docs/screenshots/admin-overview-mobile.png" alt="Administrator overview (phone)" width="360">

**Administrator bookings with checked-in residents (phone)**

<img src="../docs/screenshots/admin-bookings-mobile.png" alt="Administrator bookings with checked-in residents (phone)" width="360">

**Administrator messages (phone)**

<img src="../docs/screenshots/admin-messages-mobile.png" alt="Administrator messages (phone)" width="360">

**Activation email captured in Mailpit**

<img src="../docs/screenshots/email-activation.png" alt="Activation email captured in Mailpit" width="360">

**Web installer**

<img src="../docs/screenshots/installer.png" alt="Web installer" width="360">

---
[Back to the list of appendices](README.md) · [Back to the repository](../README.md)
