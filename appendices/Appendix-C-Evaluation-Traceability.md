# Appendix C: Evaluation Results and Requirements Traceability

*Appendix to the BSc (Hons) Computer Science thesis "Development of a Real-Time Availability and Booking System for Communal Gym Facilities" by Dewan Sayeed Raja (P2863738).*

This appendix links the planned evaluation scenarios (Table 3.2) and the functional requirements (Table 4.1) to implementation evidence and test results.

## Table C.1. Evaluation results by scenario

| Scenario (Table 3.2) | Evidence from the tests | Result |
|---|---|---|
| View availability | Dashboard counts match the stored records; a checked-in resident is counted until check-out; partly overlapping sessions are counted correctly at each moment. | Pass |
| Advance booking | Bookings at any minute and length (for example 13:52–14:52 and 07:07–07:42); places taken increase by one; booking through the JSON API. | Pass |
| Check-in for a booking | Check-in possible from 10 minutes before to 15 minutes after the start; the resident is not counted twice. | Pass |
| Walk-in registration | A walk-in at 13:52 runs 13:52–14:52; a chosen length of 45 minutes gives 15:20–16:05. | Pass |
| Full zone | "Sorry, Weights is fully booked at 18:50"; when a zone becomes full later, a shorter session is suggested. | Pass |
| Unclaimed reservation | Bookings not checked in by the end of the check-in period are released and the place is free again. | Pass |
| Zone administration | A capacity below the 3 people booked at the same time is refused; raising it is accepted; closing a zone asks for confirmation, cancels the affected booking and emails the resident. | Pass |
| Conflicting requests | 8 simultaneous requests for 3 places: exactly 3 stored. | Pass |
| Presentation and access | 44 of 44 layout checks passed; all 11 usability walkthrough tasks completed through the intended route (Appendix F); residents receive 403 on administrator pages and APIs; requests without a CSRF token are refused. | Pass |

## Table C.2. Functional-requirement traceability

| Req. | Implemented in | Test evidence (test area in the results) | Result |
|---|---|---|---|
| FR1 | dashboard.php, Availability::overview(), dashboard.js | Pages; capacity; manual journeys 3–4 | Pass |
| FR2 | book.php, Bookings::create() | Booking (any minute, past midnight); window (21 days) | Pass |
| FR3 | Bookings::evaluate() rule 5, row locks | Capacity (overlapping sessions; 8 processes, 3 places) | Pass |
| FR4 | Bookings::evaluate() rules 3 and 4, unique key | Duplicates (also direct SQL); overlap | Pass |
| FR5 | Attendance::walkIn() | Walk-in (13:52 example; refusals) | Pass |
| FR6 | Attendance::checkIn(), Attendance::window() | Check-in (window; no double counting) | Pass |
| FR7 | Maintenance::run() | Check-in (unused bookings released) | Pass |
| FR8 | Attendance::checkOut(), Attendance::autoCheckOut(), Attendance::reportCheckOut() | Check-out (exact time; no penalty; 6-hour automatic check-out) | Pass |
| FR9 | Bookings::cancel() | Cancellation (place released; own bookings only) | Pass |
| FR10 | Schedule::isOpenDuring(), Schedule::applyChange() | Window (closed days, hours, closures); admin (closing a zone) | Pass |
| FR11 | admin pages, Residents, Schedule, api/admin | Admin (capacity, closing); authorisation (admin access) | Pass |
| FR12 | Residents, Tokens, Account | Accounts; activation; sign-in; recovery; email and password change | Pass |
| FR13 | help.php, api/contact.php, Contact | Contact (resident); Pages (no contact link on the sign- in pages) | Pass |

---
[Back to the list of appendices](README.md) · [Back to the repository](../README.md)
