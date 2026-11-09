# Demonstration data and membership packages

Date: 2026-11-09, Asia/Hong_Kong.
Status: Items 1 to 6 of 6, the schedule change and inbox fix I-A are delivered locally for review. Three decisions are parked.

## Request

The owner wrote: "你給我做多點數據好證明功能是可用的，好多都沒有數據，就算有就只有一個 太少了 反映不到可用，比如不用客戶級別，各個方案類型設置及怎樣使用，客戶怎樣redeem".
The owner added: "这是现在的需求" and "先完善这个".
The owner then answered: "示范数据也得进入线上站点啊，重点是，得是真实数据，不是mock，本身就接入数据库了的" and chose "大量（约 50 客户）".
The owner confirmed the target: "不是，就是 Clinic".
The owner approved items 1 and 2 with "可以，做第 2 项" and "可以，做第 3 项".
The owner chose the schedule change and item 4: "改，再做第 4 项".
The owner approved item 4 and asked for item 5: "可以，做第 5 项".
The owner approved item 5 and asked for item 6: "可以，做第 6 项".
The owner chose the inbox fix: "修，按 I-A".

## Findings

Customer tiers, package or plan types and package redemption do not exist in Clinic.
A search of every local and remote Clinic branch, 15 of 15 refs, found no tier, package, redemption or Stripe code.
The only matches were the `membership` table alias in BranchDirectory and StaffBookingController.
Neither requirements workbook mentions tiers, packages, stored value, discounts or redemption.
The only related field is the free-text CRM segment ("客群") in crm_profiles.
Uncertain: the owner's description mentions 1 Stripe test order, a preview site at localhost:8089, 3 customers, 3 plans and 1 customer tier.
That description does not match Clinic.
localhost:8089 is the Docker container beautypos-preview from "/Users/xubincheng/Develop/Work/HK/POS system for the beauty industry".
Clinic runs locally at 127.0.0.1:18765 and had 0 patients before this work.

## Files

`database/seeders/DemoDataSeeder.php` creates the demonstration data set.
`database/seeders/DemoDataPurgeSeeder.php` removes it.
`.claude/launch.json` starts the local server for browser checks.

Run the seeder:

```sh
php artisan db:seed --class='Database\Seeders\DemoDataSeeder'
```

Remove the demo data set:

```sh
php artisan db:seed --class='Database\Seeders\DemoDataPurgeSeeder'
```

Every row is a real database row with the application's encryption.
Every demo patient's CRM profile carries the "示範數據" tag.
Herbal movements without an encounter carry the note suffix " · 示範數據".
The purge removes tagged patients with their bookings, events, encounters, receipts, herbal movements, follow-ups, CRM profiles, message threads and projections.
The purge reverses the stock balance of every removed movement.
The purge removes a stock row only when its balance is zero and no other movement remains.
The purge removes a clinical template only when both its name and its full record match the demo content.
The purge removes demo staff accounts, identified by the reserved `clinic-demo.invalid` email domain, with their HR records and access logs.
The purge clears demo staff from message thread and rule assignments.
The purge keeps branches, services, practitioners and slots, because real records may share them.
A second seeder run adds 0 rows.
A purge after the item 5 run left 0 patients, 0 herbal stocks, 0 HR rows and 1 staff account, the local review administrator.

## Schedule

Future days keep a full bookable timetable for the 14-day booking window.
Each practitioner works 14 slots a day at a home branch, and at a second branch on Tuesday and Thursday.
Past days use 2 two-hour sessions per practitioner per week, 10 sessions in total.
The 3 branches get 3, 3 and 4 sessions a week.
Each session holds 4 slots: 09:30 to 11:00 or 14:30 to 16:00.
The 60 past days hold 336 slots: 96 at 中環, 104 at 旺角 and 136 at 銅鑼灣.
The seeder removes stale past slots of the demo practitioners before inserting the plan.
It removes a slot only when no booking or booking event references it and the plan does not contain it.
That removal runs only in local and testing environments.

## Item 1: customers and future bookings

Future bookings go through CreateAppointment, RescheduleAppointment and CancelAppointment.

- 3 branches, 4 services and 5 practitioners.
- 50 patients: 40 Hong Kong numbers and 10 mainland China numbers, 50 distinct names.
- 97 future bookings.
- 10 rescheduled and 4 cancelled future bookings, across 3 of 3 branches.
- 50 CRM profiles: 13 新客, 13 回頭客, 12 長期調理 and 12 企業客戶.

## Item 2: past visits and clinical records

Past visits are inserted directly, because CreateAppointment only accepts future slots within 14 days.
The number of past visits follows the CRM segment: 0 to 1 for 新客, 2 to 3 for 回頭客, 4 to 6 for 長期調理 and 1 to 2 for 企業客戶.
長期調理 patients keep the practitioner of their first visit, so visit comparison shows one doctor's progress.

Current local result after the schedule change:

- 114 past bookings for 43 of 50 patients.
- 94 encounters: 31 at 中環, 34 at 旺角 and 29 at 銅鑼灣.
- Encounters by practitioner: 16 陳醫師, 15 林醫師, 12 黃醫師, 22 何醫師 and 29 張醫師.
- Encounters span 2026-09-15 11:40 to 2026-11-09 10:10 Hong Kong time, with 4 to 19 encounters in each of 9 weeks.
- 27 patients have 2 or more encounters.
- 12 past bookings were cancelled the evening before.
- 8 past bookings have no encounter, as when a patient does not attend.
- 10 clinical record types match the CRM concern tags, for example 不寐（心脾兩虛） for 失眠.
- Follow-up visits are labelled 覆診第 N 次 with improving notes.
- 12 clinical templates: 失眠調理, 腰背痛針灸, 鼻敏感調理 and 產後調理 in each of 3 branches.
- 25 follow-ups: 5 completed, 9 overdue and 11 upcoming.

Since item 5, each encounter records the demo doctor account of the slot's practitioner in `recorded_by`.
Each clinical template records the first demo doctor of its branch in `created_by`.
The item 5 run reassigned 94 of 94 existing encounters from the head-office administrator to the matching doctor.
No view displays `recorded_by`.

## Item 3: receipts

Each encounter gets one receipt, paid 5 to 15 minutes after the record, as reception does at POS.
About half of the encounters from the last 3 days stay unpaid, so POS shows a short payment queue.
The amount is the service fee plus HK$48 per herbal dose.
Service fees: 中醫內科 HK$280, 針灸 HK$450, 推拿 HK$400 and 拔罐 HK$300.
The description reads, for example, "針灸診金 HK$450、中藥 5 劑".
Receipts use the same columns that CommerceController::collect writes.
The `clinic.receipt.paid` event has no listener, so a direct insert skips no behaviour.

Current local result after the schedule change:

- 90 receipts for 90 of 94 encounters, HK$61,140 in total.
- 4 encounters await payment.
- 中環 HK$20,420, 旺角 HK$22,756 and 銅鑼灣 HK$17,964.
- 30 cash, 25 card, 30 FPS and 5 other.
- Weekly revenue from HK$1,156 to HK$13,204.
- 0 receipts outside the 5 to 15 minute payment window and 0 receipts dated in the future, checked before the schedule change.

## Item 4: herbal inventory

Each branch stocks 31 herbs: the 29 herbs in the demo prescriptions plus 枸杞子 and 菊花.
Each branch opens with 800 g of every herb at 08:30 on the first day of the window.
Each encounter dispenses its prescription, linked to the encounter, 3 minutes after the record.
The dispensed quantity is the grams per dose times the number of doses, for example 酸棗仁 15 g × 7 = 105 g.
When a herb would fall below 200 g, a 1 kg supplier delivery is received at 08:30 that morning.
Each branch records one 50 g stock-count loss of 陳皮.

Local result:

- 93 stock rows and 679 movements.
- 112 receipts: 93 openings and 19 supplier deliveries, 6 at 中環, 6 at 旺角 and 7 at 銅鑼灣.
- 567 dispenses: 564 linked to encounters and 3 stock-count losses.
- 94 of 94 encounters have dispense movements.
- 0 points in time with a negative balance, replayed per stock in time order.
- 0 stock rows whose balance differs from the sum of its movements.
- 0 movements dated in the future.
- 6 stock rows below 300 g, for example 旺角 熟地 at 200 g.
- Most used herbs: 當歸 3,060 g, 黨參 2,952 g, 黃芪 2,670 g, 甘草 2,256 g and 白朮 2,232 g.

## Item 5: staff and HR

HR records attach to `staff_accounts`, so item 5 creates 10 demo staff accounts.
5 doctors match the 5 practitioners by surname: 陳志遠, 林曉晴, 黃家樂, 何穎欣 and 張浩賢.
3 receptionists work at 中環, 旺角 and 銅鑼灣.
1 branch administrator works at 旺角, and 1 HR officer works at 中環.
Every account is active, so it appears in HR, staff administration and future message assignment like a real account.
Every password hash comes from 32 random bytes that are never stored or shown, so nobody can sign in.
Every email uses the reserved `.invalid` domain, so no email can be delivered.
Clinic has no self-service password reset.

Local result:

- 10 HR profiles with a synthetic identity label such as "示範證件 0007", phone, district address marked 示範地址 and an emergency contact.
- 36 employment history entries: 10 job, 10 training, 8 pay and 8 performance.
- 19 documents: 10 employment contracts and 9 IR56B tax forms.
- Each document is a 1-page PDF of about 619 bytes stating that it is a synthetic demo document.
- 285 shifts: 27 to 30 per person over the past 4 weeks and the next 2 weeks.
- Doctors and the HR officer work flexible shifts, and receptionists and the branch administrator work fixed shifts.
- 188 past shifts have clock-in and clock-out times.
- 19 late arrivals of up to 24 minutes, 7 early departures of up to 39 minutes and 20 overtime shifts of 30 to 90 minutes.
- 11 leave requests: 4 approved annual, 2 approved sick, 3 pending annual, 1 rejected annual and 1 approved 14-week maternity leave.
- The HR officer reviews leave, and the head-office administrator reviews the HR officer's own leave.

Consistency checks:

- 0 shifts with a clock-in but no clock-out.
- 0 clocked shifts today or later.
- 0 shifts on approved or pending leave days.
- 0 overlapping approved or pending leave requests per person.
- 0 leave decisions dated in the future.
- 0 self-reviewed leave requests.
- 19 of 19 documents decrypt to a file that starts with `%PDF-1.4`.

## Item 6: WhatsApp inbox

Each branch gets 4 keyword rules: opening hours, fees, parking, and insurance or receipts.
The insurance rule assigns the branch receptionist and pauses automation.
14 scripted conversations run through `Inbox::receive`, the same method the Meta webhook calls.
Each message runs at its own simulated time through `Carbon::setTestNow`, so timestamps and the 24-hour service window match the conversation.
Staff steps repeat the database effects of InboxController assign, reply and link.
Each contact also gets a `whatsapp_routes` row with its branch and last receipt time, as the webhook writes.
Before each later step and after each conversation, every queued reply receives a simulated Meta status with a `wamid.DEMO.` message ID.
The `whatsapp_settings` reception branch and the 7 template mappings were not changed, because they are production channel settings.

Covered behaviour:

- Keyword replies for opening hours, fees and parking.
- HELP and the branch list.
- 2 WhatsApp bookings by demo patients, without notification consent, which moved to the patient-linked thread.
- 3 unknown questions handed to reception, then answered by a receptionist.
- 1 explicit "真人" handoff after a fee reply, answered by reception the next morning.
- 1 insurance enquiry assigned to the receptionist by rule.
- 1 thread where reception answered, resumed automation, and the next question received a keyword reply.
- 1 STOP and 1 START consent change.
- 2 waiting enquiries from today, still unassigned in the handoff queue.
- 2 threads linked to the demo patient and closed by reception.

Local result:

- 12 rules, 16 threads across 中環 6, 旺角 5 and 銅鑼灣 5, and 22 inbound messages.
- 6 staff replies.
- 25 outbound replies: 22 read, 2 delivered and 1 failed with `META_131026`.
- Thread states: 10 open and automated, 2 open and assigned, 2 open waiting for reception, and 2 closed.
- 0 queued replies in demo threads.
- 4 queued reminders remain for item 1 bookings without consent; Delivery suppresses them when they fall due.
- 0 WhatsApp bookings with notification consent.
- 0 conversation steps skipped for lying in the future, in a run at 11:14 Hong Kong time.

## Verification

Item 6, /staff/inbox at 旺角 listed 22 threads, and the open queue listed 22.
Item 6, the head-office view of all branches showed 30 threads on page 1.
Item 6, the insurance thread showed 孔慧儀 as assignee, the rule reply, the staff reply and 已讀 statuses.
Item 6, the parking thread showed 發送失敗 with META_131026 and the administrator's retry form.

Item 5, /staff/hr at 旺角 listed 4 demo staff and the local review administrator.
Item 5, the 孔慧儀 page showed the profile, 4 history entries, 2 documents, shifts and 1 approved sick leave.
Item 5, the document download returned HTTP 200 with a 619-byte PDF.
Item 5, the shift list showed a fixed shift with a 21-minute late arrival and a flexible shift arriving 5 minutes after the latest start.

Item 1, head-office CRM list at /staff/patients?scope=all: 50 of 50 patients shown.
Item 1, segment filter: 13 新客, 13 回頭客, 12 長期調理 and 12 企業客戶.
Item 1, the search for 失眠 returned 5 patients, and the patient page showed the reschedule change log.
Item 2, the search for 不寐 returned 4 encounters, and the comparison view showed 2 visits of one patient side by side.
Item 2, a slot at 14:30 showed an encounter time of 15:10.
Item 2, the new-encounter form offered 4 of 4 demo templates.
Item 3, POS at 旺角 showed "合計 HK$ 23,286.00（含全部分頁）" before the schedule change, with pagination and the FPS filter.
Item 3, the receipt page showed branch, Hong Kong time, references, item, method and amount.
Item 4, /staff/inventory at 旺角 listed 31 herbs and 242 movements.
Item 4, the filter 陳皮 with 入庫 returned 1 movement: "期初入庫 · 示範數據" at 2026-09-10 08:30.
Item 4, the newest movements showed dispenses linked to encounter #1345 with "處方配藥 5 劑".

The head-office report for 2026-09-10 to 2026-11-09 after the schedule change and item 4:

| Branch | Revenue HK$ | Booking rate % | Repeat rate % | Herb use g | Stock use rate % | Visits per hour |
|---|---|---|---|---|---|---|
| 中環 | 20,420.00 | 29.03 | 57.14 | 11,573.000 | 37.57 | 0.50 |
| 旺角 | 22,756.00 | 28.79 | 76.92 | 13,076.000 | 42.45 | 0.52 |
| 銅鑼灣 | 17,964.00 | 21.33 | 64.29 | 11,231.000 | 35.32 | 0.39 |

The CSV export for the same period returned the same 3 rows.
Before the schedule change, the booking rates were 2.92 %, 2.66 % and 3.44 %.
The booking rates reached 21 % to 29 %, below the 30 % to 50 % first estimated.
The first estimate assumed 6-slot half-day sessions without computing the capacity.

After the item 4, item 5 and item 6 runs, 18 of 18 checks passed each time:

- 10 of 10 PHP suites: reschedule, staff-booking, clinical, commerce, hr, messaging, clinic-journey, patient-phone, privacy-rendering and whatsapp-cloud.
- The messaging suite with `CLINIC_TEST_PHONE_REGION=86`.
- 7 of 7 browser suites: booking, booking-management, staff-roles, clinical-form, operations-browser, whatsapp-browser and patient-phone.

## Errors corrected

The first item 1 run put 17, 34 and 48 bookings in the 3 branches.
The booking count and the branch both used `$i % 3`, and the count now uses `intdiv($i, 3) % 3`.
All 9 rescheduled bookings first landed in 旺角, because `$i % 6` selects one branch, and the rule now uses `$i % 5`.

```text
php artisan tinker
   ERROR  Command "tinker" is not defined.
```

Verification used a plain PHP bootstrap instead.

The first item 2 run picked the earliest free slot.
Every encounter was at 09:40, 36 encounters fell in the first week and 何醫師 had 7 encounters.
The seeder now picks among the free slots of a multi-day window.
The last visit first fell 6 days before today, and the window now ends the day before today.

The item 2 browser check showed encounter times 8 hours late, for example 23:10 for a 14:30 slot.
The application timezone is UTC, and `vendor/laravel/framework/config/app.php` sets `'timezone' => 'UTC'`.
Slots store Hong Kong wall-clock times, while timestamps are stored in UTC.
Every seeded timestamp now converts to UTC before storage.

One verification query failed because of a mistake in the query itself, not in the data:

```text
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'YEARWEEK(created_at,3)' in 'group statement'
```

3 suites failed after the first item 2 seeding:

```text
messaging FAIL (1)
Missing confirmation
patient-phone FAIL (1)
Mainland notification recipient mismatch
whatsapp-cloud FAIL (1)
RuntimeException: Webhook booking notification was not accepted at /Users/xubincheng/Develop/Work/HK/Clinic/tests/whatsapp-cloud.php:20
```

Root cause: the seeder left about 200 due outbox entries.
Delivery handles 100 due entries per call, so each test's own entry stayed behind the demo backlog.
Projection alone did not fix it, and messaging and patient-phone still failed with the same messages.
The seeder now runs the same collect and consume steps as one `clinic:messages` tick until no due entry remains.

The first schedule-change run deleted every past slot.
The plan keys used "09:30", while MySQL returns slots.starts_at as "09:30:00".
The plan keys now include seconds.
The removal of stale slots now runs before the insert, because an old slot at the same practitioner and time blocks a planned insert.

The first item 4 run stopped with this error:

```text
In DemoDataSeeder.php line 466:
  Undefined array key "陳皮"
```

The pattern `\p{Han}+` also matched the ideographic comma "、" through Unicode Script_Extensions.
The parsed names became "、陳皮" and similar, and 33 empty stock rows with those names were written at 中環.
The pattern now splits on explicit separators.
The 33 empty rows were removed locally; each had a zero balance and no movement.
The failed run had already committed patients, bookings and receipts, so the final check used a full purge and a fresh run.

## Notification safety

Demo bookings carry no email and no WhatsApp consent.
After item 4 seeding, 278 outbox entries were suppressed and 91 entries were queued for later due times.
Delivery suppresses the queued demo entries for missing consent when they fall due.
0 demo entries use email.
Synthetic phone numbers may belong to real people.
This is uncertain because Hong Kong has no reserved fictional mobile range.
The consent rule is the only protection against delivery to those numbers.

## Parked decision 3: demo staff account state

| Option | Action | Cost | Risk | Verdict |
|---|---|---|---|---|
| A-1 | Keep the 10 demo accounts active with unknown random passwords. | None. | LOW | CHOOSE. Nobody can sign in, and the accounts look and route like real staff. |
| A-2 | Mark the 10 demo accounts inactive. | One field. | LOW | REJECT. Staff administration would show them as disabled, and routing rules cannot assign inactive staff. |

The seeder uses A-1 until the owner decides.
On production, an administrator could still set a new password for a demo account in staff administration.

## Corrected in item 6

The first item 6 run left the fee reply queued until the "真人" message 5 minutes later paused automation.
The reply was then suppressed, although the scheduler would have delivered it within a minute.
The seeder now gives queued replies their simulated receipt before each later step.

## Findings in item 6 outside the demo data

F-1: the inbox shows UTC times as if they were Hong Kong times.
`resources/views/messaging/thread.blade.php` prints `{{ $e->created_at }}` without conversion, and the application timezone is UTC.
A message received at 15:15 Hong Kong time shows as 07:15.
The index view prints the thread `updated_at` the same way.
This affects real webhook messages too, so it is an application defect, not a data defect.

F-2: notification-only threads push conversations down the inbox.
NotificationQueue opens one thread per patient and branch for confirmations, payments and feedback.
The demo creates about 50 such threads, and their `updated_at` is the seeding time.
They sort above the scripted conversations, so page 1 of the 旺角 inbox mixes 22 threads of both kinds.
Real use creates the same notification threads, but their times spread over the days the bookings happened.

## Delivered: inbox fix I-A

The owner chose I-A over I-B (F-1 only) and I-C (no change).

F-1 fix, application code:

- `resources/views/messaging/thread.blade.php` converts each message time from UTC to Hong Kong time and labels the log 香港時間.
- `resources/views/messaging/index.blade.php` converts the thread update time the same way and labels the column 最近更新（香港時間）.
- Both use `Carbon::parse($value, 'UTC')->tz('Asia/Hong_Kong')->format('Y-m-d H:i')`, the format the POS and inventory views already use.

F-1 regression test in `tests/messaging.php`:

- It stores a message at 2026-01-02 07:15 UTC and requires the thread view to show 2026-01-02 15:15.
- It stores a thread update at 2037-01-02 07:16 UTC and requires the inbox list to show 2037-01-02 15:16.
- With both old views, the suite failed with "Thread view did not show Hong Kong time".
- With only the old list view, the suite failed with "Inbox list did not show Hong Kong time".
- With both fixed views, the suite passed.

F-2 fix, demo data only:

- Future bookings, reschedules and cancellations now run with the application clock set to a moment in the last 7 days.
- Each booking stays within the 14-day booking window that CreateAppointment enforces for the booking day.
- After each booking event, encounter, receipt and WhatsApp step, the seeder runs NotificationQueue::collect at that same moment, as the every-minute scheduler would have done.
- Future bookings are now spread over 7 days: 9, 9, 10, 11, 16, 20 and 24 bookings from 2026-11-03 to 2026-11-09.
- 7 of 66 threads were updated today, instead of about 50 threads at the seeding time.
- 0 bookings, booking events, outbox entries or threads carry a time after the real clock.
- 0 queued outbox entries were due after the final drain.
- The 旺角 inbox now lists today's waiting enquiry second and the insurance conversation fifth, among notification threads ordered by their real event times.

Errors corrected during I-A:

```text
Undefined variable $errors (View: /Users/xubincheng/Develop/Work/HK/Clinic/resources/views/staff/layout.blade.php)
```

The new test rendered the staff layout without the shared error bag, and it now shares an empty ViewErrorBag as tests/commerce.php does.
The first list assertion used 2026-01-02, which sorted off page 1 of a populated inbox.

```text
SQLSTATE[22007]: Invalid datetime format: 1292 Incorrect datetime value: '2099-01-02 07:16:00' for column 'updated_at' at row 1
```

`updated_at` is a MySQL TIMESTAMP, which ends in 2038, so the test uses 2037-01-02.
The first simulated-time run booked 69 of 99 future appointments today, because a booking 1 to 6 days before a slot more than 6 days ahead fell after the real clock.
Booking days now spread over the last 7 days within the 14-day window.

After the fix, Blade compilation passed and 18 of 18 checks passed.

New production risk for P-A: the seeder runs NotificationQueue::collect with the clock set in the past.
A real booking made during the seeding run would be projected with that past time.
This is uncertain, because it requires a real booking in the same seconds as a seeding step.

## Remaining items

All 6 items are delivered locally.

## Errors corrected in item 5

One verification script failed because of a mistake in the script itself, not in the data:

```text
PHP Fatal error:  Uncaught ErrorException: Undefined property: stdClass::$name_zh→ in Command line code:9
```

PHP read the arrow character as part of the variable name, and the corrected script uses string concatenation.

## Parked decision 1: tiers, packages and redemption

| Option | Action | Cost | Risk | Verdict |
|---|---|---|---|---|
| S-B | Build tiers, packages and redemption now with assumed rules, then seed them. | About 4 to 6 days. | HIGH | REJECT. Assumed pricing, expiry, refund and cross-branch rules would likely be rebuilt. |
| S-C | Send the owner a rule sheet, then build the module after the rules are confirmed. | About 3 to 5 days after the rules. | MEDIUM | CHOOSE. The module follows confirmed rules. |

Rules needed before building:

- Tier names, the number of tiers and how a patient moves between tiers.
- Tier benefits: discount percentage, priority booking or other benefits.
- Package types: session packs such as 10 acupuncture visits, stored value, or both.
- Package price, validity period and expiry handling.
- Whether a package can be redeemed at every branch or only at the selling branch.
- Whether redemption happens at booking, check-in or POS.
- Refund, transfer and partial-use rules.
- Whether the patient sees the remaining balance on the website or WhatsApp.

## Parked decision 2: loading demo data into production

The owner requires demo data on https://clinicsys.gogo2hk.com.
Both demo seeders refuse to run outside the local and testing environments.
The production host has no SSH; commands run through a temporary DirectAdmin cron job.

| Option | Action | Cost | Risk | Verdict |
|---|---|---|---|---|
| P-A | Allow both seeders in production behind an explicit `CLINIC_DEMO_DATA=1` flag. Deploy, back up the database, run once through the temporary cron, then remove the flag. | About half a day. | MEDIUM | CHOOSE. The data is tagged, removable and suppressed from notifications. |
| P-B | Create a separate demo site with its own database on the same DirectAdmin account. | About 1 day, plus a DNS record and a database. | LOW | REJECT for now. The owner asked for the live site. |

Risks of P-A:

- The seeder finds branches, services and practitioners by Chinese name, so a real branch named 中環, 旺角 or 銅鑼灣 would receive demo bookings and stock.
- Demo patients will appear in production reports until the purge runs.
- The drain step processes every due production outbox entry, the same work as one scheduler tick.
- The seeder requires an active head-office administrator on production.
- The seeder creates 10 active staff accounts on production with unknown passwords.
- The seeder writes 12 inbox rules into the production branches it finds by name.
- Simulated Meta receipts would sit beside real receipts until the purge runs.
- A production write needs the owner's explicit confirmation at run time.

## Not done

Nothing has been committed or pushed.
No production data has changed.
