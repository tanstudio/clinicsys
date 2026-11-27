# Packages phase 2: stored value, refunds, transfers, revenue basis and balance notices

Date: 2026-11-27.
Branch: `feat/xubincheng/packages-phase2`.
Scope: the phase 2 items of option R-B in `261127-tier-package-rules.md`.

## Rules head office edits at `/staff/loyalty`

No value below is in code.

| Rule | Where | Applies to |
|---|---|---|
| Plan type: session package or stored value | each plan | new sales |
| Stored value amount and bonus amount | each stored value plan | new sales |
| Refundable, refund fee % | each plan | copied onto each sold package |
| Transferable | each plan | copied onto each sold package |
| Revenue basis: selling branch at sale, or redeeming branch at each use | one setting | the report's recognized package income |
| WhatsApp balance notices on or off | one setting | ledger rows written while it is on |

## Behaviour

- Stored value pays any service at an allowed branch; reception enters the amount; an amount above the balance is refused with the balance shown.
- A stored value payment issues an HKD 0 receipt, as a session redemption does, and a branch administrator can void it.
- Refund: branch administrator only; open, unexpired packages only; whole remaining balance.
- Refund amount = remaining units × price ÷ total units, minus the fee % rounded to the cent. The package is closed.
- Refunds reduce the patient's tier spend.
- Transfer: branch administrator only; transferable packages only; whole remaining balance.
- The recipient is found by name and phone, as bookings identify patients, and must have booked at the branch.
- The recipient's package keeps the expiry and terms; its price is the paid share of the balance, so a later refund stays correct.
- A redemption cannot be voided after its package is refunded or transferred.
- Balance notice: one WhatsApp message per ledger row to the package owner, with the change and the new balance.
- A notice is queued only when the owner's latest booking has WhatsApp consent; delivery re-checks that consent.
- The migration marks ledger rows written before it as already projected, so the upgrade sends no back-dated notices.
- Readiness now checks 17 local prerequisites (was 16): the new `package` template kind.

## Report

`/staff/reports` → 套票及儲值 adds refunds, stored value used, stored value left and recognized package income.
Refunds are not subtracted from 實收收入; the table states this.

## Demonstration data

- 2 stored value plans: HK$2,000 + HK$200 (refundable, 10 % fee, transferable) and HK$5,000 + HK$800 (not refundable, transferable).
- Session plans now carry terms: 針灸 15 % fee, 推拿 and 中醫內科 10 % fee and transferable, 拔罐 trial not refundable.
- A fresh local run: 17 session packages, 10 stored value sales, 25 session redemptions, 1 void, 9 stored value payments, 1 refund and 1 transfer.
- The refund and the transfer happen at 旺角 by the 旺角 administrator.

## Production data task

- `deploy/task.py demo-seed` backs up the database, then runs `DemoDataSeeder` once with `CLINIC_DEMO_DATA=production`.
- Both demo seeders refuse production without that variable.
- `deploy/backup.php` takes an optional application directory and output file; `apply.sh` calls it without arguments, unchanged.

## Verification

- Migration: up, down and up again locally; 42 of 42 existing ledger rows were marked projected.
- `tests/loyalty.php` adds the phase 2 checks:
  - settings;
  - the stored value plan;
  - sale, payment, insufficient balance and void;
  - both revenue bases with exact figures;
  - refund amount, fee and closure;
  - tier spend after a refund;
  - transfer, rejections and the recipient's eligibility;
  - notices with consent, without consent, and with the setting off.
- 13 of 13 PHP runs and 7 of 7 Playwright suites passed with exit status 0 and a PASS line: 20 of 20.
- The 13 PHP runs are 12 suites plus messaging with `CLINIC_TEST_PHONE_REGION=86`.

### Local browser walk-through (head office, at 旺角)

- Changed a stored value plan's fee to 12 %, saw "已儲存套票方案。", restored 10 %.
- Patient #1784:
  - sold 儲值 HK$2,000 送 HK$200;
  - POS refused HK$3,000 with "儲值餘額不足：餘額 HK$ 2,200.00。";
  - paid visit #2812 with HK$376: receipt #2038, HK$0.00, balance HK$1,824.00;
  - refund quote HK$1,658.18 − HK$165.82 = HK$1,492.36, then refunded.
- Patient #1739 showed the refunded 推拿 package and the HK$948.00 transferred in from patient #1751.
- Report 2026-09-28 to 2026-11-27, 旺角, sale basis: HK$28,000 sold − HK$3,202.36 refunded = HK$24,797.64.
- Usage basis gave 中環 4,218.18, 旺角 6,262.50 and 銅鑼灣 2,763.80; an independent PHP sum gave the same 3 figures.
- At 375 px width, 5 of 5 changed pages had no horizontal overflow.

## Not done

- The `package` WhatsApp template is not configured anywhere. Meta is not connected, so no balance notice is delivered yet.
- `tests/recovery.sh` was not run. It needs disposable MySQL containers, and it has 2 more tables to restore.
- The demonstration phone numbers follow valid Hong Kong and mainland formats.
  Real people may own some of them, which is why the data carries no consent and no email.
