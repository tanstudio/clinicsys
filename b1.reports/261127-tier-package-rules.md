# Configurable customer tiers, packages and redemption

Date: 2026-11-27, Asia/Hong_Kong.
Status: The owner chose R-B. Phase 1 is built and verified locally on branch feat/xubincheng/tiers-packages.

## Request

The owner wrote: "规则需要直接开放规则管理，不要确认，不要写死在代码中".
The owner originally asked to demonstrate "客戶級別，各個方案類型設置及怎樣使用，客戶怎樣redeem".
This replaces the clinic confirmation sheet; that sheet is no longer needed and was left with 1 of 10 sections written.

## Facts

Clinic has no tier, package or redemption feature today.
The only related field is the free-text CRM segment ("客群").
POS issues one receipt per encounter, with the methods cash, card, FPS and other.
WhatsApp notices need Meta-approved templates, and the live channel is not configured.

## Fixed design rules for every option

Rules live in database tables edited in the staff portal, never in code constants.
Only a head-office administrator edits rules.
Every rule change is versioned: a purchase or redemption keeps the rule values that applied at that moment.
Every balance change is a ledger row; balances are sums, never overwritten numbers.
Branch reception sees and redeems only in branches the package allows.

## Decision: scope of the first version

| Option | Action | Cost | Risk | Verdict |
|---|---|---|---|---|
| R-A | Everything at once: configurable tiers with automatic upgrades, session packs, stored value, POS redemption, expiry, refunds, transfers, cross-branch revenue rules and WhatsApp balance notices. | About 6 to 8 days. | HIGH | REJECT. Too many money rules ship untested together, and WhatsApp notices cannot be verified until Meta is live. |
| R-B | Phase 1: configurable tiers (manual or by 12-month spend, consultation discount %), configurable session-pack plans (service, sessions, bonus sessions, price, validity, allowed branches), purchase at POS, redemption at POS after an encounter, void with a reason, expiry, tier and package views in CRM, and demo data. Stored value, refunds, transfers and WhatsApp notices follow in phase 2. | About 3 to 4 days. | MEDIUM | CHOOSE. It covers the requested tiers, plan types and redemption with the smallest set of money rules. |
| R-C | A generic rule engine where administrators write conditions and formulas. | About 8 to 10 days. | HIGH | REJECT. Free-form formulas are hard for clinic staff to read and easy to get wrong with real money. |

## Phase 1 detail under R-B

- Tier settings: name, order, upgrade threshold in HKD over a rolling number of months, consultation discount %, active flag.
- A patient's tier is the highest tier whose threshold the patient's paid receipts reach; an administrator may set a manual tier with a reason.
- Plan settings: name, service, sessions, bonus sessions, price, validity in days, allowed branches, active flag.
- Purchase: reception sells a plan at POS and issues a receipt; the package records the plan version it was sold under.
- Redemption: after an encounter, reception chooses "redeem package" instead of payment; one session is deducted and the receipt shows HKD 0 with the package reference.
- Void: a branch administrator reverses a redemption with a reason; the ledger keeps both rows.
- Expiry: a package stops being redeemable after its end date; remaining sessions stay visible as expired.
- Reports: package sales revenue, redemptions by branch and outstanding sessions.

## Decision

The owner chose R-B: "R-B 分期做".

## Phase 1 result

Files:

- `database/migrations/2026_11_27_000000_create_loyalty_tables.php`: customer_tiers, patient_tiers, package_plans, package_plan_branches, patient_packages and package_ledger; receipts allow no appointment or encounter and gain kind, tier_name and discount_percent.
- `app/Support/Loyalty.php`: tier from spend or a manual setting, ledger balances and redemption eligibility.
- `app/Http/Controllers/LoyaltyController.php` and `routes/loyalty.php`: head-office rules, sale, manual tier, redemption and void.
- Views: `/staff/loyalty`, the patient page section, POS redemption and tier hints, receipt labels and the report table.
- `app/Messaging/NotificationQueue.php`: no payment notice for an HKD 0 package receipt.
- `tests/loyalty.php`, demo data in `DemoDataSeeder` and removal in `DemoDataPurgeSeeder`.

Rules live only in the database: 0 tier names, thresholds, prices or session counts are hard-coded outside the demo seeder.

Local demo data:

- 2 tiers and 4 plans, one plan limited to 中環 with 30-day validity.
- 17 packages sold for HK$36,640.
- 24 redemptions, 1 of them voided and then paid in cash.
- 3 expired trial packages with sessions left and 5 unused recent packages.
- Tiers: 5 金卡 (1 manual), 15 銀卡 and 30 without a tier.
- 0 balances out of range, 0 redemptions without their HKD 0 receipt, 0 visits with two receipts.

Browser check on the local app:

- `/staff/loyalty`: changed 銀卡 discount from 5 % to 6 %, saw "已儲存等級規則。", then restored 5 %.
- Patient #1608: sold 中醫內科八次套票 by card; 8 of 8 sessions, valid 2026-11-27 to 2027-07-24; 12-month spend rose by HK$2,100.
- POS: the sale receipt was listed; tier hints showed on unpaid visits; only the eligible visit #2472 offered redemption.
- Redemption: receipt #1675, HK$0.00, "使用療程套票：中醫內科八次套票（剩 7 次）".
- Void on the patient page with a reason: 8 of 8 sessions again and #2472 back in the unpaid list.
- Report for 2026-09-28 to 2026-11-27: 18 packages, HK$38,740 and 23 net redemptions across 3 branches.

Tests: 12 of 12 PHP suites, the messaging suite with `CLINIC_TEST_PHONE_REGION=86`, and 7 of 7 Playwright suites passed with exit status 0 and a PASS line: 20 of 20.
Not run: `tests/recovery.sh`, which needs disposable MySQL containers and now has 6 more tables to restore.

Errors corrected:

- The first `tests/loyalty.php` runs failed on the test itself: an invalid head-office email, HTML validation redirects instead of 422, a logout before login, and the login throttle of 10 a minute.
- The first demo run produced 0 voids because the void was tied to one patient without a package; the first redemption of a run is now the void example.
- The ledger on the patient page sorted by id, so purchases appeared out of date order; it now sorts by time.

Purge defect found and fixed:

```text
SQLSTATE[22003]: Numeric value out of range: 1690 BIGINT UNSIGNED value is out of range in '(`clinic`.`herbal_stocks`.`milligrams` - 540000)'
```

A real dispense of 30 g 陳皮 had used demo stock, so removing the demo receipts would make the balance negative.
The purge now stops and names each herb to count first, for example "中環 陳皮 30.000 g", instead of rewriting real stock.
The local test patients from the end-to-end check were removed so the local data is demo-only again.

Phase 2, not built: stored value, refunds, transfers, cross-branch revenue rules and WhatsApp balance notices.

## End-to-end browser check, 2026-11-27

The owner asked: "然后确认整个项目处于可用状态，完整业务流程闭环，需要你使用浏览器操作确认".
The check ran in the built-in browser against the local app at 127.0.0.1:18765.
The local app code equalled `origin/main` 15ede91, the live release.
Production was not used: it has no branches or schedules, a test there would write records into the real database, and its staff password cannot be entered for the owner.

Passed, with the evidence seen in the browser:

1. Patient booking: Central, 中醫內科, 陳醫師, 2026-11-28 10:30 for a synthetic patient; reference c9f94ec8.
2. Patient reschedule from "我的預約" to 2026-11-27 15:00: "預約時段已更新".
3. Staff sign-in with the local review account and the schedule: the moved booking at 15:00.
4. CRM: tags and segment saved; a follow-up for 2026-12-04 added.
5. Clinical record: the 失眠調理 template loaded all fields; record #2226 saved with Hong Kong time 13:42.
6. Inventory: 30 g of 陳皮 dispensed against #2226, stock 540 g to 510 g; a 9,999 g dispense was refused with "此藥材的分店存量不足。".
7. POS: #2226 listed as unpaid; receipt #1384 of HK$520.00 by FPS linked to booking c9f94ec8 and record #2226; #2226 left the unpaid list.
8. Reports: Central revenue for 2026-11-27 showed 520.00; the FPS filter showed 1 receipt and HK$520.00.
9. Notifications: one scheduler tick projected the reschedule and payment notices; both were suppressed for missing consent; feedback is queued for the next day.
10. Branch switcher: Central to Mong Kok and back through the branded select.
11. Inbox: a waiting enquiry assigned to 孔慧儀, automation paused, and a staff reply scheduled.
12. HR: a pending annual leave of 何穎欣 approved; a shift for 孔慧儀 added after the fix below.
13. Reception booking for a +86 number at 14:00, then cancelled from the schedule; the slot returned to 可預約.
14. 25 of 25 staff and patient pages returned HTTP 200 without error text, including the CSV export.

Defect found and fixed in PR #9:

```text
Carbon\CarbonImmutable::rawAddUnit(): Argument #3 ($value) must be of type int|float, string given
```

Adding a shift returned HTTP 500, because the form posts `required_minutes` as a string.
`tests/hr.php` had posted an integer and also exited with status 0 after a failure.
Production has the same defect until PR #9 is released.

Not closed end to end:

- The staff WhatsApp reply stayed `unconfigured` with `whatsapp_credentials_required`, because no Meta credentials exist locally or in production.
- Production cannot take bookings until branches, practitioners, services and schedules exist there.

Operator errors during the check, not application defects:

- Twice the branch did not switch, because the next page was opened before the switch form finished submitting.
