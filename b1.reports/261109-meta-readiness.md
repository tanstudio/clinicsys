# Meta integration readiness

Date: 2026-11-09, Asia/Singapore.
The owner instructed: "继续，直到需要meta api进行实际测试".
The previously selected architecture remains a native inbox connected directly to Meta Cloud API.
No new architecture choice was introduced.

## Completed work

Expired branch-selection messages remain in the existing reception branch.
A direct staff reply pauses automation and suppresses queued bot replies in the same transaction.
Verified patient threads are locked before their conversation state is read.
Routing rules cannot assign inactive staff, staff from another branch or unsupported roles.
Invalid rule targets fall back to an unassigned human queue with automation paused.
A corrupt encrypted message enters review without blocking the next queued message.
Receipt IDs must be nonempty and fit the database column.
Correlation references must be UUIDs.
Webhook bodies larger than 1 MiB are rejected.
Template-name validation now matches the 255-character database limit.
The new clinic:whatsapp-check command validates 16 local prerequisites without making HTTP requests.
The command reports schema, credential presence or shape, public HTTPS URL, reception branch and all seven template mappings.
The command never prints secrets and always reports live_verified=false.
No Composer or Node dependency was added.

## Verification

10 of 10 PHP suites passed: reschedule, staff-booking, clinical, commerce, hr, messaging, clinic-journey, patient-phone, privacy-rendering and whatsapp-cloud.
The messaging suite also passed with the mainland China phone fixture.
The WhatsApp suite passed 80 of 80 checks with all outbound HTTP faked.
The signed-webhook booking journey passed for 2 of 2 phone regions: Hong Kong and mainland China.
Each journey verified a single booking after callback replay, CRM phone normalization, consent, confirmation, receipt reconciliation and a scheduled reminder.
The preflight tests verified complete configuration, exact missing prerequisites, nonzero failure status and secret-free output.
The browser suite passed 4 of 4 page and viewport combinations at 390 and 1280 pixels.
The recovery drill verified 32 of 32 restored tables in disposable MySQL.
The empty-head-office test verified access to WhatsApp settings before any branch exists.
The same test denied global WhatsApp settings to a branch administrator.
Blade compilation passed.
PHP syntax checks passed for 51 of 51 application and test files.
The workbook verification confirmed 14 of 14 intended cell edits across 3 of 3 sheets.
All affected formulas, cell styles, columns, panes, merges, validation and the native table were preserved.
The actual local environment passed 1 of 16 preflight prerequisites because Meta settings have not been configured.
The complete synthetic configuration passed 16 of 16 prerequisites in the test transaction.
No real WhatsApp request or test email was sent in this continuation.
Resend remains accepted according to the owner's previous receipt confirmation.
The workbook keeps live Meta requirements pending rather than marking simulated delivery as accepted.

## Corrected failures

The new expired-selection regression exposed a routing defect before the fix.

```text
RuntimeException: Expired selection changed reception branch at /Users/xubincheng/Develop/Work/HK/Clinic/tests/whatsapp-cloud.php:20
```

The first two-region test fixture reused one physician time slot.
The corrected fixture uses two distinct hours.

```text
Illuminate\Database\UniqueConstraintViolationException: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '86-2026-11-11 15:00:00' for key 'slots.slots_practitioner_id_starts_at_unique' (Connection: mysql, Host: 127.0.0.1, Port: 33867, Database: clinic, SQL: insert into `slots` (`branch_id`, `practitioner_id`, `service_id`, `starts_at`) values (115, 86, 1, 2026-11-11 15:00:00)) at /Users/xubincheng/Develop/Work/HK/Clinic/vendor/laravel/framework/src/Illuminate/Database/Connection.php:838
```

The browser test initially ran while the local server was stopped.
The server was started and the complete browser suite passed on rerun.

```text
node:internal/modules/run_main:107
    triggerUncaughtException(
    ^

page.goto: net::ERR_CONNECTION_REFUSED at http://127.0.0.1:18765/staff/login
Call log:
  - navigating to "http://127.0.0.1:18765/staff/login", waiting until "load"

    at /Users/xubincheng/Develop/Work/HK/Clinic/tests/whatsapp-browser.mjs:13:16 {
  log: [
    '  - navigating to "http://127.0.0.1:18765/staff/login", waiting until "load"'
  ],
  name: 'Error'
}

Node.js v25.8.0
```

The historical interactive deployment helper was absent from the temporary directory.

```text
cat: /tmp/clinic-da-client-v1.py: No such file or directory
```

The first workbook comparison treated regenerated relationship IDs as content changes.
The corrected check resolves each relationship and compares the identical native table content.

```text
Traceback (most recent call last):
  File "<stdin>", line 24, in <module>
AssertionError: ('需求明細', 'tableParts')
```

## Inputs required for real Meta tests

Provide the Meta app and WhatsApp business account with permission to use Cloud API.
Place WHATSAPP_ACCESS_TOKEN and WHATSAPP_APP_SECRET in the private deployment environment or a secret manager.
Provide the secret location instead of placing credentials in Excel or Git.
Provide WHATSAPP_BUSINESS_ACCOUNT_ID and WHATSAPP_PHONE_NUMBER_ID.
Choose the supported WHATSAPP_GRAPH_VERSION shown for the actual Meta app.
Generate WHATSAPP_VERIFY_TOKEN locally with at least 32 random characters during activation.
Register https://clinicsys.gogo2hk.com/webhooks/whatsapp as the callback and subscribe to messages.
Select an actual reception branch in the staff portal.
Provide seven approved text-body template names, languages and ordered variables.
Provide an authorized test handset that can receive and reply to the chosen business number.
A +86 input format does not prove WhatsApp registration or Meta number eligibility.

## Live acceptance sequence

Verify the Meta challenge and application subscription.
Send HELP, BRANCHES and a branch selection from the authorized handset.
Confirm the selected branch retains subsequent messages and another branch cannot read the conversation.
Create a consented booking and compare the CRM record with the handset confirmation.
Replay the captured callback and confirm there is only one booking and one notification.
Verify staff takeover, reply, explicit automation resume and unknown-question handoff.
Verify confirmation, reschedule, cancellation, reminder, payment, feedback and marketing templates.
Verify STOP suppresses pending marketing.
Compare accepted, sent, delivered, read and failed states with real Meta receipts.
Verify freeform replies are blocked after the customer-service window.
Record actual provider errors and handset screenshots without exposing credentials.

## Limits and parked work

Actual account permissions, template approvals and delivery remain uncertain because no Meta credentials or test handset have been supplied.
Current official Meta documentation searches returned no results in this continuation.
The previous implementation report records the official archived SDK sources and their limits.
Generative AI remains unimplemented because the owner has not selected a model provider or approved a knowledge base.
The existing model options remain parked in the native WhatsApp report.
Keyword FAQ and human handoff work without an AI provider.
Media download, media replies and independent branch-owned WhatsApp numbers remain outside the current shared-number text integration.
Private-policy approval and business-user acceptance still require the clinic's designated reviewers.

## Repository hook correction

The first commit attempt omitted the required bracketed type prefix.
The retry uses the repository's [fix]: prefix.

```text
提交信息格式不正确！
提交信息必须以 [类型]: <简短描述> 开头。
示例：[chore]: 更新 CLI 提交工具
```
