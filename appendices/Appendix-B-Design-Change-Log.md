# Appendix B: Detailed Design Change Log

*Appendix to the BSc (Hons) Computer Science thesis "Development of a Real-Time Availability and Booking System for Communal Gym Facilities" by Dewan Sayeed Raja (P2863738).*

The table records the material changes between the first and second working versions of GymZone and the reason for each change, supporting the Research through Design account in Section 5.2.

## Table B.1. Detailed design changes between the first and second versions of GymZone

| Issue identified | First version | Second version | Reason |
|---|---|---|---|
| Time model | Sessions used fixed time slots within set opening hours. | Any start minute and a chosen length from 15 minutes to 4 hours; open 24 hours by default. | Residents do not arrive on the hour; fixed slots forced visits into the system's timetable and left part of a slot unused. |
| Walk-in start | A walk-in was tied to the current time slot. | A walk-in starts at the minute of check-in (13:52 gives 13:52– 14:52). | The record should match the actual visit, and a resident should not lose part of the session. |
| Actual time used | Check-out ended the session without showing the time spent. | The exact check-out time is recorded and the real time is shown to the resident and the administrator. | Shows the difference between booked and actual use, and frees the place immediately (DC5). |
| Forgotten check-out | A resident who forgot to check out remained checked in. | Automatic check-out after 6 hours; the resident is asked for the real leaving time. | A forgotten check-out would block a place indefinitely and make the display wrong. |
| Email change | The confirmation link was sent to the new address. | The confirmation is sent to the current address, and both addresses are notified afterwards. | Only the person who controls the existing inbox should be able to move the account. |
| Leaving the service | No way for a resident to delete their account; no email on deactivation. | Residents can delete their account; emails are sent on deactivation, reactivation and deletion. | Data protection and transparency towards residents. |
| Help | No help or contact route. | A help page and a contact form for signed-in residents. | Residents who find a problem need a way to reach the housing office from inside the service. |
| Small screens | Mainly designed for larger screens. | Phone-first layout for residents and administrators, with a bottom tab bar. | Residents are most likely to check availability on a phone. |

## Change after the usability walkthrough

The usability walkthrough ([Appendix F](Appendix-F-Usability-Walkthrough.md)) showed that a single broken machine could only be handled by closing its whole zone. One change was made in response.

| Issue identified | Before | After | Reason |
|---|---|---|---|
| Broken equipment | The only way to warn residents about a broken machine was to close its whole zone, which cancels bookings and refuses walk-ins. | An administrator can add a short notice to a zone (for example "1 treadmill is out of order"). It is shown on the dashboard and the booking page while the zone stays open, and can be removed at any time. | Residents are told before they arrive, while the rest of the zone can still be booked and used. GymZone still has no record of individual machines. |

---
[Back to the list of appendices](README.md) · [Back to the repository](../README.md)
