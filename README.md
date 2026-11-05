# Clinic

Clinic uses Laravel, Blade, Alpine.js and MySQL for multi-branch Chinese medicine clinics.
The patient website supports Traditional Chinese and English.
The staff portal connects branch scheduling, CRM, encounters, herbal inventory, POS, reporting, messaging and HR.

## Local setup

Use PHP 8.2 or later with PDO MySQL, Composer, Node.js, npm and Docker Compose.
Run these commands in a fresh checkout.

```sh
mkdir -p bootstrap/cache storage/framework/{cache/data,sessions,views} storage/logs
composer install
npm ci
npm run build
cp .env.example .env
php artisan key:generate
```

Set DB_PASSWORD to a random value before starting MySQL.
Protect the environment file and keep the application key stable to preserve access to encrypted records.
Start MySQL and wait for it to accept connections.

```sh
docker compose up -d
php artisan migrate --seed
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:18765 -t public
```

The sample seeder creates 14 calendar days of 30-minute appointments in Hong Kong time.
Run `php artisan db:seed` again to extend the sample calendar.
Use synthetic patient and employee details when reviewing the sample installation.

## Patient and staff access

Open http://127.0.0.1:18765/ for public bookings.
Open http://127.0.0.1:18765/appointments/manage in the booking browser session to reschedule or cancel.
Embed the public slot selector with an iframe pointing at /embed or /embed?lang=en.
The embedded selector opens a first-party checkout so patient sessions work with third-party cookies disabled.
Appointments created before browser ownership was introduced require reception assistance.

Open http://127.0.0.1:18765/staff for the staff portal.
Use the interactive account command from an authorised host to create credentials.
A head-office administrator can select a branch and view consolidated reporting.
A branch account stays scoped to its assigned branch.
Supported roles are admin, reception, doctor and hr.

```sh
php artisan clinic:staff manager@example.test 'Review manager' admin
php artisan clinic:staff reception@example.test 'Branch receptionist' reception --branch=1
```

The command prompts for a password without echoing it.
Only the current local review installation has a generated head-office login in storage/review-login.txt.
The credential file is excluded from version control and sits outside the web root.
The authenticated /staff/api/appointments endpoint supports patient_id, reference and after_id query parameters.

The POS screen identifies pending encounters by patient name and appointment time.
Filter issued receipts by receipt number, full receipt or booking reference, Hong Kong date range and payment method.
The displayed receipt total covers all matching pages.
Filter inventory movements by exact herb name, operation and Hong Kong date range without changing the displayed current stock.
The report screen exports the selected branches and dates as a UTF-8 CSV containing all six summary measures.
CSV ratios with no denominator remain blank, and spreadsheet formula prefixes in branch names are escaped.

## Notifications and privacy review

Run `php artisan schedule:work` in a separate local process or run `php artisan schedule:run` each minute under deployment supervision.
The worker projects appointment events, receipts and completed visits into the outbox.
Configure a real provider only after setting the approved tenant, sender and consent settings.
See [messaging setup](b1.reports/261104-messaging-integration.md) for SleekFlow mappings and SMTP configuration.
See [privacy review](b1.reports/261104-privacy-controls.md) for verified technical measures and operator decisions.

## Verification

Run these checks with the sample MySQL database.
Chrome browser checks require a running local web server.
Messaging tests use fake HTTP and mail transports.

```sh
for suite in reschedule staff-booking clinical commerce hr messaging clinic-journey; do php "tests/$suite.php" || exit 1; done
node tests/booking.mjs
node tests/booking-management.mjs
node tests/staff-roles.mjs
node tests/clinical-form.mjs
node tests/operations-browser.mjs
```
