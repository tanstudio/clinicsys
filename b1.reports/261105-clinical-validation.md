# Clinical entry validation

Date: 2026-11-05, Asia/Singapore.
Requirement: UX-01, physician workflow acceptance.
Branch: fix/xubincheng/clinical-validation.
Base: v1.0.1, 786c93e8fe5cb44c5660d5d5aedae8081fac1f58.

## Scope

The owner deferred third-party platform work and requested local functionality improvements.
This delivery fixes the clinical entry form's validation recovery.
The owner's supplied AGENTS.md requires delivering one similar item for review before proceeding with the remaining items.
No architecture or provider decision was required for this existing-flow correction.

## Cause and correction

ClinicalController aborted invalid submissions with a generic HTTP 422 response.
The response provided no editable form or retained clinical fields.
Invalid HTML submissions now render the clinical form with a summary and field-specific messages.
Valid text and selected symptoms remain in the current response.
Malformed array values cannot reach text fields or the symptom checkbox renderer.
Blade escapes retained text.
The response includes no-store caching instructions.
The server does not flash clinical text to the session.
JSON clients receive HTTP 422 with field errors.
The controller verifies branch access before returning any form data.
Valid submissions continue to use the existing encrypted encounter transaction.
Clinical templates retain their existing validation behavior.

## Changed files

app/Http/Controllers/ClinicalController.php handles invalid HTML and JSON submissions.
resources/views/clinical/form.blade.php presents the error summary and initial focus.
resources/views/clinical/fields.blade.php connects individual fields to accessible error messages.
tests/clinical.php checks escaping, field retention, malformed values, no persistence and no session leakage.
tests/clinical-form-fixtures.php creates and removes synthetic local test accounts and appointments.
tests/clinical-form.mjs submits invalid and corrected forms in Chrome.
README.md documents the browser regression command.
The requirements workbook updates UX-01 evidence and records the owner's deferral of third-party testing.

## Verification

php tests/clinical.php passed before and after the correction.
The suite also verifies CRM, clinical encryption, templates, historical comparison, duplicate prevention and branch/role access.
CLINIC_SCREENSHOT_DIR=/tmp node tests/clinical-form.mjs passed after starting the local server.
The browser test passed 2 of 2 viewport sizes: 390 and 1280 pixels.
The browser test checks HTTP 422, no-store, retained fields, error focus, field navigation, no horizontal overflow and corrected resubmission.
The browser fixture cleanup ran in a finally block.
php tests/messaging.php passed with all transports faked during the initial inventory review.
No real third-party delivery was attempted.

The first browser attempt failed because the local development server was stopped.
The failing output was:

```text
node:internal/modules/run_main:107
    triggerUncaughtException(
    ^

page.goto: net::ERR_CONNECTION_REFUSED at http://127.0.0.1:18765/staff/login
Call log:
  - navigating to "http://127.0.0.1:18765/staff/login", waiting until "load"

    at /Users/xubincheng/Develop/Work/HK/Clinic/tests/clinical-form.mjs:17:16 {
  log: [
    '  - navigating to "http://127.0.0.1:18765/staff/login", waiting until "load"'
  ],
  name: 'Error'
}

Node.js v25.8.0
```

Starting PHP on 127.0.0.1:18765 resolved the environment failure.

The first workbook preservation check compared openpyxl StyleProxy wrappers directly.
It produced this false failure despite identical underlying style attributes:

```text
Traceback (most recent call last):
  File "<stdin>", line 16, in <module>
AssertionError: ('進度總覽', 'A1', 'style')
```

Comparing copied style values resolved the verification-script error without modifying workbook styles.
The final workbook check confirmed 7 of 7 intended cell changes.
Other values, formulas, fonts, fills, borders, alignment, number formats, tables, validations and frozen panes were preserved.
The summary reconciled 96 of 96 requirements.
Rendered mobile, desktop and changed workbook ranges passed visual review.

The commit hook rejected the initial subject because it lacked the repository's type prefix:

```text
提交信息格式不正确！
提交信息必须以 [类型]: <简短描述> 开头。
示例：[chore]: 更新 CLI 提交工具
```

The retry uses the required [fix]: prefix and an imperative Chinese subject.

## Remaining work

UX-01 remains pending physician acceptance because no physician representative performed the workflow.
The workbook retains 81 of 96 completed requirements and 15 of 96 pending requirements.
The 15 pending requirements comprise 11 channel tests, 1 privacy review and 3 role acceptance checks.
The owner deferred third-party configuration and testing.
This local correction has not been deployed or pushed.
This task made no changes to the production deployment.
