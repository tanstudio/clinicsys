# Local operations workflow improvements

Date: 2026-11-05, Asia/Singapore.
Branch: feat/xubincheng/local-operations.
Base: 14717e0, including the prior clinical-form correction.

## Authorised scope

The owner requested continued local functionality improvements, local verification and an updated requirements workbook.
The owner's latest instruction supersedes the earlier requirement to pause after each similar item.
The owner's instruction was:

> 可以，继续完善不需要第三方平台的功能，本地测好并更新 Excel，不用每做一项都停下来问我。

The implementation keeps the existing Laravel, Blade, Alpine and MySQL architecture.
The implementation extends existing inventory, POS and reporting workflows.
No third-party configuration, outbound real messages, production deployment or default-branch mutation was needed.

## Delivered behavior

Pending encounter selectors show the patient's name, appointment time and encounter number in POS and inventory.
Receipt lookup accepts a receipt row number, complete public receipt reference or complete booking reference.
Receipt filters combine the Hong Kong date range and payment method.
Receipt totals include all matching pages.
Receipt rows show patient name, Hong Kong collection time and payment method.
Inventory movement filters combine the exact herb name, operation and Hong Kong date range.
Ledger filters do not change the current stock list or quantities.
Both ledgers retain filters when navigating between pages.
Date queries include the selected start day and all of the selected end day in Hong Kong time.
Report export uses the same scoped summary data as the screen.
CSV includes reporting dates, branch name and six measures.
CSV uses a UTF-8 BOM for Chinese text.
CSV leaves ratios without a denominator blank.
CSV neutralises spreadsheet formula prefixes in branch names.
The report download requires the same administrator and branch permissions as the HTML report.
The existing blue-and-white interface and responsive form layout remain in use.

## Dependencies and implementation

CommerceController uses the existing Patient model to decrypt only the patients associated with the displayed records.
CommerceController uses existing receipt, appointment, encounter and inventory tables.
OperationsReportController streams CSV through PHP's CSV writer and Laravel's existing response API.
No schema migration or package dependency was added.
The workbook updates existing requirement evidence rather than inventing new acceptance sign-offs.

## Verification

All 7 of 7 PHP regression suites passed: reschedule, staff-booking, clinical, commerce, hr, messaging and clinic-journey.
The commerce suite verifies reference lookup, empty results, invalid inputs and branch isolation.
The commerce suite verifies 4 of 4 date-boundary positions for both ledgers: just before midnight, midnight, the last second and the next midnight.
The commerce suite reconciles all 6 of 6 exported measures with the screen's calculated values.
The commerce suite verifies CSV formula-prefix escaping, empty-denominator values, head-office branch selection and denied HR access.
All 5 of 5 browser suites passed: booking, booking-management, clinical-form, staff-roles and operations-browser.
The operations browser suite passed 6 of 6 module/viewport combinations at 390 and 1280 pixels.
The operations browser suite checks a 26-row receipt result across 2 pages with an unchanged HK$3,250.00 total.
The operations browser suite checks a 26-row inventory result across 2 pages and retained filters.
The operations browser suite checks actual CSV downloads and the HK$3,495.00 fixture total.
The clinical form browser suite passed 2 of 2 viewport sizes.
The existing staff-role browser suite passed 50 of 50 role/viewport/screen combinations.
All browser fixtures use synthetic data and run cleanup in finally blocks.
Messaging regression uses fake transports.

An attempted read of the historical configuration path returned:

```text
cat: /Users/xubincheng/Develop/Work/codebasex/cb-config/AGENTS.md: No such file or directory
```

The user's supplied instructions remain the available source for that configuration.
No configuration-repository file was changed.

## Workbook and remaining status

The workbook retains all 96 of 96 requirements.
The workbook retains 81 of 96 completed requirements and 15 of 96 pending requirements.
The pending total consists of 11 channel checks, 1 privacy review and 3 human role acceptance checks.
Third-party checks remain deferred by the owner.
Automated browser tests do not constitute human clinical, reception or administration acceptance.
The workbook explicitly labels this batch as local-only.
Workbook verification confirmed 15 of 15 intended cell updates.
All other values, formulas, styles, table ranges, validations and frozen panes were preserved.
The workbook's status counts reconciled 96 of 96 requirements.
Rendered mobile and desktop screens and all changed workbook ranges passed visual review.
This batch has not been pushed or deployed.
