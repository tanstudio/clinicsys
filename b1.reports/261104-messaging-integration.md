# Clinic messaging integration

## Local operation

PHP Artisan discovers `clinic:messages` and schedules it once per minute.
Run `php artisan schedule:work` continuously or invoke `php artisan schedule:run` from cron every minute.
The command projects persisted appointment events, receipts and completed encounters into an encrypted outbox.
It processes up to 250 unprocessed rows per source and submits up to 100 due messages per run.
The outbox stores timestamps in the application timezone.
Appointment times use Asia/Hong_Kong.
A booking made at least 24 hours before the appointment gets a reminder scheduled for the 24-hour point.
A later booking gets its confirmation only.
A reschedule or cancellation supersedes prior appointment notifications.
The feedback invitation is due one day after the encounter is recorded.
WhatsApp booking, reminder, feedback and payment templates require the patient's booking-specific opt-in.
Email requires an address supplied with the booking.
Marketing requires a separate START or 訂閱 message from the WhatsApp number.
STOP or 退訂 withdraws marketing consent, including for pending campaigns.

## Connect a SleekFlow tenant

Use a Premium or Enterprise tenant with the required Flow Builder capabilities and a connected WhatsApp channel.
The tenant generates its own endpoint and authentication headers.
Use the generated example request to set these environment variables in the deployment secret store.

| Variable | Value |
| --- | --- |
| SLEEKFLOW_FLOW_URL | HTTPS webhook URL copied from the outbound notification flow trigger |
| SLEEKFLOW_FLOW_HEADERS | JSON object with the exact webhook trigger ID and webhook token headers from its sample POST request |
| SLEEKFLOW_INBOUND_TOKEN | A random secret of at least 32 characters for the Clinic inbound route |
| CLINIC_EMAIL_ENABLED | true to enable SMTP submission |
| MAIL_MAILER | smtp |
| MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_SCHEME | Settings supplied by the SMTP provider |
| MAIL_FROM_ADDRESS, MAIL_FROM_NAME | Verified Clinic sender identity |

### Clinic to WhatsApp

1. Create a “Webhook payload received” trigger.
2. Map the phone-number contact identifier to the payload key `phone`.
3. Enable new customer enrollment to accept web bookings from new contacts.
4. Map `branch_id` to the branch's connected channel.
5. Branch by `kind`.
6. Map confirmed, rescheduled, cancelled, reminder, payment, feedback and marketing to their approved WhatsApp templates.
7. Map the Clinic body into the corresponding approved template parameters.
8. Use the manual send node only for `reply` inside the customer service window.
9. Use the stable `event_id` in your flow logs for reconciliation.
10. Publish and test the flow with a consented test number.

The Clinic sends JSON containing event_id, phone, branch_id, kind and body.
An HTTP 2xx records acceptance by the trigger.
Read receipts, handset delivery and the outcome of downstream flow actions require verification in the provider's logs.
A 429 triggers up to four deferred retries.
An ambiguous network failure, a 5xx or an abandoned in-flight claim requires operator reconciliation before resubmission.
A branch admin can retry failed or unconfigured messages from the conversation after checking that the provider did not accept them.
When production credentials are absent, pending messages become visibly unconfigured.

### WhatsApp to Clinic

1. Trigger the flow on incoming customer messages.
2. Derive the Clinic branch ID from the selected branch or receiving channel.
3. Add “Send HTTP request” with POST to `https://<clinic-host>/webhooks/sleekflow`.
4. Set `Content-Type: application/json`, `Accept: application/json` and `Authorization: Bearer <SLEEKFLOW_INBOUND_TOKEN>`.
5. Send the stable provider message ID, E.164 sender number, branch ID and message text as the following mapped keys.
6. Capture the response key `route` for normalization and handoff.

```json
{"message_id":"<provider-message-id>","branch_id":1,"phone":"+85291234567","text":"BOOK"}
```

This endpoint authenticates the tenant-configured authorization header.
Flow Builder's generic HTTP action supports custom headers without a payload-signature requirement.
Only trusted flow configuration sets the branch ID.
A replay with the same provider message ID returns `duplicate`.
The callback can also record human or AI agent replies composed inside SleekFlow by mapping the customer's number and setting `direction` to `outbound`.
Configure this outbound capture only for agent-authored replies to avoid mirroring Clinic notifications twice.

### FAQ, AI and agent handoff

Clinic staff can configure deterministic keyword replies and route enquiries to a branch-specific assignee under /staff/inbox.
The system reserves BOOK, START, STOP, HELP and human handoff commands.
BRANCHES lists branch selection commands.
BOOK@<branch ID> lists available slots for that branch with the practitioner, service and Hong Kong time.
The next-page command works inside WhatsApp.
BOOK followed by the slot number and full name completes a booking within WhatsApp.
BOOK+ uses the same booking workflow and explicitly opts into WhatsApp notifications.
The clinic revalidates the chosen slot inside the booking transaction.
Freeform messages remain available to reception in the branch inbox.
The Clinic API returns `local` when it queued a reply, `human` for an explicit handoff or a routed enquiry, and `triage` for an unassigned freeform enquiry.
In SleekFlow, invoke AgentFlow only on the `triage` response.
Configure AgentFlow with the clinic's approved bilingual administrative FAQ knowledge.
Route medical advice, requests for a person, and low-confidence answers to branch reception through an “Assign to” action.
Exclude locally answered, duplicate, mirrored and human-assigned messages from AI enrollment.
Validate the agent's answers and handoff using AgentFlow's playground before enabling the channel.
The local regression suite exercises deterministic routing and the boundary between Clinic and the provider.
Live AI responses depend on the tenant's deployed model and approved knowledge.

## Vendor documentation

- [Advanced webhook trigger and credentials](https://help.sleekflow.io/en_US/advanced-flow-builder-triggers/configuring-webhook-trigger-nodes-afb)
- [Configuring HTTP request headers and body](https://help.sleekflow.io/en_US/send-http-request-action-node)
- [WhatsApp manual and template actions](https://help.sleekflow.io/en_US/advanced-flow-builder-actions/send-message-action-node)
- [AgentFlow configuration and human handoff](https://help.sleekflow.io/en_US/manage-and-assign-new-contacts-using-agentflow)
