# Clinic requirements and first delivery decision
Date: 2026-11-04
Status: The owner selected option A. The local acceptance build is ready for review.

## Confirmed scope
Clinic will use Laravel, Blade, Alpine.js and MySQL.
Patients can book through the website or WhatsApp by choosing a branch, practitioner, service and available time.
The embeddable booking interface must support Traditional Chinese and English on mobile and desktop.
Booking availability must follow practitioner schedules and support rescheduling and cancellation.
Patients need booking confirmations and a WhatsApp reminder 24 hours before the appointment.
Email is an additional notification channel.
Booking data must reach CRM and clinical records through an API.
CRM must connect contact details, patient tags, segments, booking history, encounters and WhatsApp conversations.
Each branch needs a scoped data view.
Head office needs a consolidated view.
Clinical records must support structured symptoms, free text, diagnoses, prescriptions, treatment plans and reusable templates.
Practitioners need searchable historical records and visit comparisons.
Clinical records require encrypted storage and a review against Hong Kong privacy requirements.
Chinese herbal inventory must support branch operations and inventory usage reporting.
POS must generate receipts linked to bookings and encounters and consolidate branch financial data.
Reports must cover booking rates, revenue, repeat visits, inventory use and practitioner efficiency.
Reporting must support custom reports, charts and branch or head-office views.
WhatsApp must support general enquiries, booking, promotions, post-visit feedback, automated FAQ replies and payment notifications.
Multiple agents need routing by branch or enquiry type and configurable workflows.
SleekFlow is the proposed integration provider.
HR must cover staff identity and contact details, emergency contacts, employment history, pay changes, training and performance reviews.
HR must retain contracts and tax documents.
HR must support shifts, flexible schedules, overtime, late arrivals, early departures and online leave applications.

## First delivery options
| Option | Action | Relative cost | Risk | Verdict and reason |
| --- | --- | --- | --- | --- |
| A | Deliver one bilingual website booking flow backed by practitioner availability, a persisted appointment and a linked patient record. | Medium. Requires branch schedules, patient matching and concurrent booking protection. | MEDIUM | CHOOSE. This verifies the booking record used by reception, CRM and messaging. |
| B | Deliver one branch reception schedule with staff booking, rescheduling and cancellation. | Medium. Requires staff access, branch scoping and booking conflict handling. | MEDIUM | REJECT as the default first step. This validates reception operations while leaving the patient experience untested. |

## Proposed acceptance check for A
Use explicitly labelled fixture branches, services and practitioner schedules.
Switch language while booking on a mobile viewport and retain the selected branch and service.
Offer only slots valid for the chosen branch, practitioner and service.
Submit two concurrent requests for the same single-capacity slot and persist exactly one confirmed appointment.
Show the unsuccessful patient updated availability.
Verify that the successful booking appears once in the linked patient's CRM history.

## Proposed interface
Use a Swiss layout with a white surface, Helvetica Neue and a Traditional Chinese sans-serif fallback.
Use a single blue accent (#002FA7) and one-pixel rules.
Use the selected date as the heading for its available slots so patients can recheck the day before submitting.

## Dependencies and parked work
The owner selected the patient website booking workflow.
Branch and service lists, practitioner schedules and slot durations are inputs to live booking.
Patient identity matching and cross-branch access rules need owner input before handling live records.
Live WhatsApp delivery depends on provider selection, channel access and message templates.
Inventory transaction rules and reporting denominators were not specified in the brief.
Subsequent delivery is tracked in the Clinic requirements workbook. The first-delivery results below are historical.

## Local acceptance result
The local build used Laravel, Blade, Alpine.js and MySQL 8.4.
The browser check used one 390-pixel viewport and one 1280-pixel viewport.
Both browser sessions retained branch, service and practitioner selections after switching languages.
Two competing submissions returned one HTTP 201 response and one HTTP 409 response.
The losing browser displayed a conflict message and refreshed availability.
The updated availability response excluded the booked slot.
The patient relationship returned exactly one matching appointment for the test patient.
The acceptance check removed its synthetic patient and appointment after verification.
The local identity rule matches a normalized name and eight-digit Hong Kong number.
Live identity matching remains an owner decision.
