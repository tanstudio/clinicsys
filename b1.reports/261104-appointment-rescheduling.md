# Appointment rescheduling
The existing patient booking flow stores a unique appointment reference and a unique occupied slot.
Both delivery options need an atomic move that preserves the patient and reference and releases the original slot only after success.

| First interface | Action | Cost | Risk | Verdict and reason |
| --- | --- | --- | --- | --- |
| Patient self-service | Allow the creating browser session to change its own appointment. | Medium | MEDIUM | CHOOSE because this extends the accepted patient workflow. |
| Branch reception | Introduce staff login and branch access checks before allowing reception to change a booking. | Higher | MEDIUM | REJECT as the first interface because staff access is an additional dependency. |

The owner selected patient self-service.
The owner subsequently requested implementation of the full checklist, including cancellation.

## Verification
The MySQL regression check passed for an occupied destination, a past destination and a destination outside the booking window.
The successful move preserved the patient relationship and the appointment reference.
The check verified that the original slot became available.
Retrying the same successful move preserved the final state.
A stale form could not undo a newer move.
The test rolled back its synthetic data.
The existing two-browser booking acceptance check passed after creation and rescheduling started sharing the slot lock.
The patient management page now requires the booking browser session and permits rescheduling and cancellation before the visit.
