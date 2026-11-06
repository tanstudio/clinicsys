# Native WhatsApp delivery

Date: 2026-11-06, Asia/Singapore.
Status: Local implementation and tests complete for the direct transport and rule-based reception.
Production WhatsApp activation remains pending Meta credentials and handset verification.
The owner accepted the production Resend email test with "邮件过关".

## Confirmed decision

The owner instructed "不用sleekflow, 直接自行開發吧".
The owner selected option A below.

| Option | Action | Cost | Risk | Verdict and reason |
| --- | --- | --- | --- | --- |
| A | Build reception in Clinic and connect directly to Meta Cloud API. | Medium development and ongoing Meta usage. | LOW | CHOOSE. The owner selected the official direct integration. |
| B | Build an offline inbox without connecting WhatsApp. | Lower initial development. | LOW | REJECT. This cannot provide real WhatsApp communication. |

## Implemented behavior

Clinic no longer calls SleekFlow or exposes its former webhook route.
The GET and POST callback endpoint is `/webhooks/whatsapp`.
GET validates the verification token and echoes the challenge.
POST validates the raw-body HMAC-SHA256 signature with the Meta app secret.
The receiver checks the configured business account and phone-number IDs.
Repeated message IDs do not create repeated replies or bookings.
The current deployment model uses one shared business number for all branches.
Head office chooses the initial reception branch in `/staff/whatsapp`.
A patient's BOOK@branch selection persists for later messages.
Out-of-order callbacks do not replace the latest branch selection.
Late messages do not reopen the freeform reply window or execute old bookings.
Late START messages do not undo a later opt-out.
Staff accounts continue to use branch-scoped inbox permissions.
Human handoff pauses automation until reception explicitly resumes it.
Handoff suppresses queued automatic replies while preserving the handoff acknowledgement and staff replies.
Keyword rules support pipe-separated synonyms and branch-specific answers.
Unknown questions enter the human queue with one acknowledgement.
Non-text messages create a staff-follow-up entry instead of pretending to parse an attachment.
Clinic sends freeform replies only within the existing 24-hour service-window check.
Seven notification kinds use independently configured, approved text-body template mappings.
Head office can configure the template name, language, ordered variables and enabled state.
Appointment references, dates, branch names and payment amounts are supplied as structured template parameters.
Template parameters are encrypted at rest.
Unconfigured credentials or disabled templates stop delivery without substituting freeform text.
Provider IDs and correlation references reconcile receipts that arrive before HTTP acknowledgements.
Delivery states distinguish acceptance, sent, delivered, read, failure and uncertain outcomes.
Late receipts cannot regress a read message to sent.
Receipts must match the conversation's recipient.
Timeouts and malformed acknowledgements require review instead of blind retries.
The Resend email configuration and SMTP delivery path remain in use.

## Files and dependencies

`app/Messaging/CloudWhatsApp.php` owns the official outbound payload and receipt handling.
`app/Http/Controllers/WhatsAppWebhookController.php` owns signed inbound callbacks.
`app/Http/Controllers/WhatsAppSettingsController.php` owns head-office settings and template validation.
`app/Messaging/Inbox.php` owns reception, branch conversations and persistent handoff.
`app/Messaging/NotificationQueue.php` supplies structured notification variables.
`app/Messaging/Delivery.php` preserves email, consent and queue behavior while selecting Meta delivery.
The new migration adds template settings, contact routing and delivery correlation fields.
No Composer or Node dependencies were added.
Runtime dependencies remain PHP, Laravel, MySQL and the existing minute scheduler.
Production setup needs Meta credentials, one business number, approved templates and an authorized test handset.

## Verification

10 of 10 PHP suites passed: reschedule, staff-booking, clinical, commerce, HR, messaging, clinic-journey, patient-phone, privacy-rendering and whatsapp-cloud.
The messaging suite also passed separately with the mainland China phone fixture.
The native Cloud API suite passed 41 of 41 checks.
All Meta HTTP requests in these tests were faked.
The browser suite passed 4 of 4 page/viewport combinations at 390 and 1280 pixels.
The browser suite checked navigation, template inputs, settings indicators, synonyms and horizontal overflow.
The SMTP suite passed 2 of 2 real local-mailbox scenarios for confirmation and rescheduled reminders.
The new migration passed a local up/down/up cycle.
Blade compilation and PHP syntax checks passed.
The owner separately confirmed production Resend mail receipt.
No real WhatsApp messages were sent.

## Corrected verification failures

The existing transcript assertion selected a new acknowledgement instead of the latest inbound message.
The assertion now identifies inbound messages explicitly.
Original output:

```text
In messaging.php line 24:

  Ambiguous number linked
```

Nested request validation initially omitted the webhook change field from validated data.
The receiver now validates that field explicitly.
Original output:

```text
Illuminate\Database\RecordsNotFoundException:  at /Users/xubincheng/Develop/Work/HK/Clinic/vendor/laravel/framework/src/Illuminate/Database/Concerns/BuildsQueries.php:403
```

The first template-help string contained nested Blade delimiters.
The string now uses a verbatim block.
Original output:

```text
Illuminate\View\ViewException: Unclosed '(' does not match '}' (View: /Users/xubincheng/Develop/Work/HK/Clinic/resources/views/messaging/settings.blade.php) at /Users/xubincheng/Develop/Work/HK/Clinic/storage/framework/views/e9cc87944c44156ffc780423c42f80df.php:21
```

The patient-number test initially asserted the retired SleekFlow payload.
The assertion now verifies the Meta destination and structured appointment reference.
Original output:

```text
In patient-phone.php line 21:

  Mainland notification recipient mismatch
```

Laravel appends HTTP fake callbacks instead of replacing them.
The new transport test now creates a separate HTTP factory for each response scenario.
Original output:

```text
RuntimeException: Wrong recipient promoted delivery state at /Users/xubincheng/Develop/Work/HK/Clinic/tests/whatsapp-cloud.php:20
```

## Production activation inputs

Configure `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_APP_SECRET`, `WHATSAPP_VERIFY_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_BUSINESS_ACCOUNT_ID` and `WHATSAPP_GRAPH_VERSION` in the private environment.
Use a verification token containing at least 32 random characters.
Choose a currently supported Graph API version when registering the Meta application.
Subscribe the application to the WhatsApp business account and its messages webhook.
Set the callback URL to `https://clinicsys.gogo2hk.com/webhooks/whatsapp`.
Select the reception branch and configure the seven approved notification templates in the staff portal.
Supply an authorized handset for receipt, reply, template, consent and branch-routing verification.
Never place tokens or secrets in the workbook or Git.

## Parked work and limits

Real Meta account access, template approval and handset tests remain unavailable because the credentials have not been supplied.
This release supports text messages and text-body templates.
Media download, media replies, variable headers and dynamic template buttons are not implemented.
One shared business number is supported; independent branch-owned phone numbers require an additional channel mapping design.
WA-06 remains incomplete because deterministic FAQ rules are not generative AI.
The following model decision is parked for the owner under the supplied AGENTS.md rules.

| Option | Action | Cost | Risk | Verdict and reason |
| --- | --- | --- | --- | --- |
| AI-A | Build Clinic retrieval and handoff around an approved model API. | Medium development plus model usage. | MEDIUM | CHOOSE recommendation only. This avoids operating inference infrastructure, but needs provider approval and credentials. |
| AI-B | Run an approved open model on dedicated infrastructure. | High deployment and maintenance. | MEDIUM | REJECT recommendation only. Available inference capacity and operating responsibility have not been established. |

Neither AI option has been implemented or selected by the owner.
Clinic does not send patient records to an AI provider.

## Source verification limits

Meta's current developer pages returned `429 Too Many Requests` during this session.
The archived official Meta SDK documents the text and template payload structures used here.
The archived documentation is not proof of current account eligibility or live API compatibility.
The configured API version and callback correlation behavior still require live Meta verification.
Official text reference: https://whatsapp.github.io/WhatsApp-Nodejs-SDK/api-reference/messages/text/
Official template reference: https://whatsapp.github.io/WhatsApp-Nodejs-SDK/api-reference/messages/template/
Official webhook types: https://raw.githubusercontent.com/WhatsApp/WhatsApp-Nodejs-SDK/main/src/types/webhooks.ts
