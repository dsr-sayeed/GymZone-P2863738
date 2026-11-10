# Appendix F: Usability Walkthrough of Resident and Administrator Tasks

*Appendix to the BSc (Hons) Computer Science thesis "Development of a Real-Time Availability and Booking System for Communal Gym Facilities" by Dewan Sayeed Raja (P2863738).*

The usability walkthrough examined whether the main tasks of residents and administrators can be completed through the GymZone interface without guidance. It was carried out by the developer on a phone-sized screen of 390 × 844 pixels, where GymZone uses a bottom tab bar, and repeated on a desktop screen. The tasks were taken from the functional requirements in Table 4.1, so that each requirement involving the interface was followed from its starting point to its result.

Four questions were considered for each task: whether the task can be started from the tab bar or the dashboard; how many screens are passed before it is complete; whether the system confirms the result or explains a refusal in plain words; and whether the same task can be completed with the keyboard alone.

## Table F.1. Usability walkthrough tasks, routes and outcomes

| ID | Task (requirement) | Route through the interface | Feedback to the user | Outcome |
|---|---|---|---|---|
| W1 | See free places in a zone (FR1) | Sign in → dashboard; the zone cards are the first content | Free places out of capacity, status in words, time of the last update | Completed without further navigation |
| W2 | Book a session (FR2, FR3) | Tab bar → booking page → date → zone → time → confirm | Live check of free places before confirming; confirmation with the exact times | Completed |
| W3 | Book when a zone becomes full (FR3) | As W2, choosing a period in which the zone becomes full | Message stating when the zone becomes full, with a shorter session suggested | Completed; refusal understandable |
| W4 | Walk in and check out (FR5, FR8) | Dashboard → zone card → Check in → length → Check out | Start time shown; closing screen with the real time spent | Completed |
| W5 | Check in to a booking (FR6) | Dashboard → booking shown on the zone card → Check in | Session shown as in progress | Completed |
| W6 | Cancel a booking (FR9) | Tab bar → list of bookings → booking → Cancel → confirm | Confirmation window; the place is shown as free again | Completed |
| W7 | Get help or contact the office (FR13) | Help button (?) → help page → message form | Office contact details, common questions, notice that the message was sent | Completed |
| W8 | Change email or password (FR12) | Profile → Settings → change form | Notice that a confirmation email was sent | Completed |
| W9 | Change a zone capacity (FR11) | Admin → Gym → zone → capacity | Refusal when below the people already booked; success message otherwise | Completed |
| W10 | Close a zone temporarily, for example for repairs (FR10, FR11) | Admin → Gym → zone → Close temporarily → reason → confirm; later Reopen zone | Dialog stating how many bookings will be cancelled; residents emailed with the reason; closed zone listed on the admin dashboard; reason shown to residents who try to book or walk in | Completed; nothing changes before confirmation |
| W11 | Read resident messages (FR11) | Administrator tab bar → messages | Count of new messages on the tab; list of messages | Completed |

## Keyboard and screen-reader access

The same tasks were repeated with the keyboard only. All actions could be reached with the Tab key and activated with Enter, the focused element was visible, confirmation windows kept the focus inside them and closed with Escape, and tabs could be changed with the arrow keys. Form fields have labels, and success and error messages are announced to screen readers. No screen reader was used, so this part of the walkthrough is limited to inspection.

## Problem found and change made

One problem was found. When a second person signed in from another tab of the same browser, the first account was replaced without any explanation, because all tabs of a browser share one sign-in. The sign-in page now explains this when it is opened by someone who is already signed in.

## Limits of the walkthrough

Because the walkthrough was carried out by the person who designed the system, it shows that every main task has a clear and working route through the interface, but not how residents who have never seen GymZone would experience it. It also showed that a broken machine can only be handled by closing its whole zone, temporarily or for a set period, because GymZone has no record of individual equipment. A test with residents and a status for individual equipment are therefore described as future work in Chapter Six.

---
[Back to the list of appendices](README.md) · [Back to the repository](../README.md)
