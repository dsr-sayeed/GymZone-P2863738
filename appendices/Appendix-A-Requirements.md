# Appendix A: Full Requirements and Acceptance Conditions

*Appendix to the BSc (Hons) Computer Science thesis "Development of a Real-Time Availability and Booking System for Communal Gym Facilities" by Dewan Sayeed Raja (P2863738).*

This appendix provides the full acceptance conditions used to evaluate the functional and non-functional requirements summarised in Chapter Four (Table 4.1).

## Table A.1. Functional requirements and acceptance conditions

| ID | Requirement | Acceptance condition | Basis |
|---|---|---|---|
| FR1 | Show zone availability | The dashboard shows each zone's free places, capacity and a text status (for example "Few places left" or "Full"), and refreshes automatically every 15 seconds. | DC1, DC2 |
| FR2 | Book in advance | A resident can book a zone for any start minute and a length from 15 minutes to 4 hours, from today up to 20 days ahead (21 days in total). | DC3 |
| FR3 | Never exceed capacity | A booking or walk-in is refused if the number of people present at any moment of the session would exceed the zone's capacity, including when several requests arrive at the same moment. | DC5 |
| FR4 | Fair use of zones | A resident can have one session per zone per day, and a resident's sessions cannot overlap. | DC3, DC5 |
| FR5 | Register a walk-in | A resident can check in without a booking when a place is free; the session starts at the current minute. | DC3 |
| FR6 | Check in to a booking | Check-in is possible from 10 minutes before to 15 minutes after the booked start, and does not count the resident twice. | DC4 |
| FR7 | Release unused bookings | A booking not checked in within 15 minutes of its start is released and its place becomes free. | DC4 |
| FR8 | Check out | Check-out records the exact time and frees the place at once. A resident still checked in after 6 hours is checked out automatically and asked for the real leaving time. | DC4, DC5 |
| FR9 | Cancel a booking | A resident can cancel their own upcoming booking, and the place becomes free. | DC5 |
| FR10 | Respect opening hours and closures | Bookings are only possible inside the opening hours and outside closures. When an administrator closes a zone or changes the hours, affected bookings are cancelled only after confirmation, and the residents are notified. | DC5 |
| FR11 | Administer the gym | An administrator can manage residents and apartments, zone capacities, opening hours, closures, bookings and messages. A capacity lower than the most people already booked at the same time is refused. | DC1, DC5 |
| FR12 | Manage accounts securely | There is no public registration. Residents added by an administrator activate their account through an email link valid for 24 hours. Password resets, email changes and password changes are confirmed by email, and residents can delete their account. | Service operation |
| FR13 | Provide help and contact | A help page answers common questions, and signed-in residents can send a message to the housing office. | Service operation |

## Table A.2. Non-functional requirements and acceptance conditions

| ID | Quality | Acceptance condition |
|---|---|---|
| NFR1 | Mobile use | Every page works from a 360-pixel-wide phone screen to a desktop without sideways scrolling, and navigation links are at least 40 pixels tall. |
| NFR2 | Consistency | All rules are enforced on the server inside database transactions and are also protected by database constraints, so that the stored state cannot contradict the rules. |
| NFR3 | Security | Passwords are stored only as hashes; every page and endpoint checks the user's role; every change requires a CSRF token; database queries use prepared statements; sign-in and email-sending actions are rate limited. |
| NFR4 | Privacy | Only the data needed for the service is stored (name, apartment, email and usage records); email links are stored only as hashes; a resident can delete their account and data. |
| NFR5 | Accessibility | All actions can be reached with the keyboard, every form field has a label, messages are announced to screen readers, and status is shown with text as well as colour. |
| NFR6 | Portability | The system runs on a standard XAMPP installation (PHP 8.1 or later and MySQL or MariaDB) without additional frameworks or a build step. |
| NFR7 | Testability | The time used by the rules can be controlled, and the tests run against a separate database, so that results are repeatable. |

---
[Back to the list of appendices](README.md) · [Back to the repository](../README.md)
