# Local acceptance readiness

Date: 2026-11-06, Asia/Singapore.
Branch: feat/xubincheng/acceptance-readiness.

## Scope and decisions

The owner requested work on the remaining 15 of 96 requirements.
The owner then deferred provider accounts, formal privacy materials and human sign-off.
The implementation retains Laravel, Blade, Alpine, MySQL and the existing notification adapter.
The owner previously requested mainland numbers for both clinic and patient use.
This batch implements patient-number support and local validation.
This batch does not register a clinic sender number with a provider.
No production records, credentials or provider settings were changed.
No default branch was mutated.

## Completed local changes

Web booking supports a bilingual Hong Kong/mainland phone-region selector.
Reception booking accepts the original eight-digit Hong Kong format and supported +852/+86 numbers.
WhatsApp booking uses the same validated patient-number contract.
The queue preserves the full +86 recipient number.
The original Hong Kong patient identity hashes remain unchanged.
Invalid numbers cannot create appointments.
Production-rendered booking and collection-notice views omit local-demo claims.
The collection notice describes both supported phone regions.
The notice is not a substitute for the clinic's approved privacy policy.
A disposable database recovery drill verifies patient data, clinical data and HR attachments.
The requirements workbook records current local evidence and corrects obsolete deployment statements.

## Verification

All 10 of 10 PHP suites passed, including the empty-head-office suite in a dedicated database.
The existing messaging suite passed for 2 of 2 phone regions, with transports faked.
All 6 of 6 browser suites passed.
The new mainland-phone browser suite passed 2 of 2 viewports: 390 and 1280 pixels.
The existing staff browser suite passed 50 of 50 role/viewport/screen combinations.
The operations browser suite passed 6 of 6 module/viewport combinations.
The clinical form browser suite passed 2 of 2 viewports.
The local SMTP test passed 2 of 2 checks: one confirmation and one correctly timed rescheduled reminder.
The recovery drill compared all rows in 29 of 29 restored tables.
The recovered clinical record, patient details and HR attachment decrypted correctly.
An independent incorrect encryption key failed to decrypt the attachment.
The recovery test removed its disposable container and temporary SQL dump.
No real WhatsApp messages were sent.

## Evidence for the remaining 15 requirements

| Requirement | Local evidence | Remaining acceptance |
| --- | --- | --- |
| NT-01 | Confirmation projection and recipient mapping pass for HK and mainland numbers. | A test handset must receive the approved confirmation. |
| NT-02 | Reminder projection, timezone and reschedule supersession pass. | A handset must receive one reminder at the agreed time. |
| NT-05 | The adapter sends kind, body, recipient, branch and stable event ID. | An approved template must be changed and verified in the tenant. |
| WA-01 | Inbound routing, assignment and reply queue pass. | A handset and an actual agent must exchange messages. |
| WA-02 | Both supported phone regions complete local WhatsApp-command booking. | A handset must complete the provider workflow. |
| WA-03 | Marketing opt-out suppresses queued promotion delivery. | A consenting test recipient must receive an approved promotion. |
| WA-04 | Follow-up scheduling and patient-thread association pass. | A handset must receive the invitation and return feedback. |
| WA-05 | Keyword replies, HELP and callback deduplication pass. | Approved clinic FAQ replies must arrive on a handset. |
| WA-06 | Local, human and triage routing boundaries pass. | AgentFlow must answer approved questions and hand off unknown questions. |
| WA-07 | Payment projection and amount association pass. | A handset must receive a matching payment template. |
| WA-14 | Authentication, idempotency, failure handling and routing pass with fake transport. | A real tenant must verify its flow and provider selection. |
| TC-10 | Encryption, access control, notice rendering and local recovery pass. | The clinic must approve its actual policy and operational controls. |
| UX-01 | Physician screens and record-entry validation pass automatically. | A physician representative must record an acceptance result. |
| UX-02 | Reception screens, branch isolation and inbox routing pass automatically. | A receptionist must record an acceptance result. |
| UX-03 | POS, inventory, reports and CSV workflows pass automatically. | An administrator must record an acceptance result. |

All 15 of 15 remain pending final acceptance under the owner's deferral.
The workbook still contains 81 of 96 developed requirements and 15 of 96 pending requirements.
No human acceptance signatures were invented.
Workbook verification confirmed 32 of 32 intended cell changes.
All 96 of 96 requirement statuses reconciled with the summary.
Unrelated values, formulas, styles, tables, validation rules and frozen panes were preserved.
The updated summary, channel evidence, role acceptance and dependency ranges passed visual review.
This batch remains local and has not been pushed or deployed.

## Parked operational choices

Production backup retention, custody and restore destination remain unspecified.
The local restore drill does not establish that production backups run on a schedule.

| Option | Action | Cost | Risk | Verdict |
| --- | --- | --- | --- | --- |
| A | Keep encrypted backups outside the application host, with a separately controlled key and an isolated restore destination. | Medium operating cost. | MEDIUM | CHOOSE as a recommendation only; independent storage limits a single-host loss. |
| B | Keep scheduled database dumps only on the application host. | Low operating cost. | HIGH | REJECT as the sole backup; the same host failure could remove the application and its backups. |

The owner has not selected a production backup arrangement.
Neither option was implemented in production.
Formal branch, practitioner, service and schedule data remain deferred by the owner.
The previously observed GitHub default-branch setting requires repository administrator access to change.

## Initial failures and resolutions

The first new phone test incorrectly assumed an Appointment patient relationship.
The test now reads the existing Patient model by patient_id.

```text
In patient-phone.php line 49:

  Attempt to read property "phone" on null
```

The first request assertion used a helper requiring an uninstalled PHPUnit package.
The test now checks recorded requests without adding a dependency.

```text
In Factory.php line 416:

  Class "PHPUnit\Framework\Assert" not found
```

The first browser run started before the local server was running.
Starting the local server and rerunning the browser suites resolved this failure.

```text
node:internal/modules/run_main:107
    triggerUncaughtException(
    ^

locator.click: Timeout 30000ms exceeded.
Call log:
  - waiting for locator('iframe').contentFrame().locator('.times button').first()

    at /Users/xubincheng/Develop/Work/HK/Clinic/tests/booking-management.mjs:19:48 {
  log: [
    "  - waiting for locator('iframe').contentFrame().locator('.times button').first()"
  ],
  name: 'TimeoutError'
}

Node.js v25.8.0
```

The empty-head-office suite correctly refused the populated local database.
The recovery harness now runs that suite before seeding its disposable database.

```text
Requires a dedicated, migrated test database without branches.
```

Initial path discovery tried names that are absent in Clinic.
The actual files were discovered before any edits.

```text
cat: config/messaging.php: No such file or directory
rg: scripts: No such file or directory (os error 2)
rg: b1.tasks: No such file or directory (os error 2)
cat: resources/views/booking/checkout.blade.php: No such file or directory
rg: resources/views/booking: No such file or directory (os error 2)
cat: docker-compose.yml: No such file or directory
cat: routes/console.php: No such file or directory
```
