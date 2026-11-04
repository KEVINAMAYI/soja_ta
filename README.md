# ⏱️ Soja Time & Attendance System

A modern **Laravel-based** employee time & attendance management system with **location tracking**, **QR code check-in
**, and **overtime calculation**.

---

## ✨ Features

- 📋 **Employee Management** – Add, edit, and manage employee records.
- 🕒 **Check-In & Check-Out** – Track working hours, overtime, and attendance status.
- 📍 **GPS Location Tracking** – Record latitude & longitude for every check-in/out.
- 📅 **Daily, Weekly & Monthly Reports** – Get attendance analytics at a glance.
- 📷 **Multiple Identification Methods**:
    - ID Number
    - QR Code
    - Face Recognition *(optional integration)*
- 📊 **Overtime Tracking** – Automatically calculate extra hours worked.

---

## 📦 Requirements

Before you begin, make sure your system meets these requirements:

- **PHP**: >= 8.1
- **Composer**
- **MySQL** / **MariaDB**
- **Node.js** & **NPM** / **Yarn** (for frontend assets)
- **Git**

---

## 🚀 Installation

### 1️⃣ Clone the Repository

```bash
  git clone https://github.com/your-username/soja-time-attendance.git
  cd soja-time-attendance
```

###  Install PHP Dependencies

``` bash
    composer install
```

#### Install JavaScript Dependencies

```bash
   npm install && npm run build
   (Or use yarn install && yarn build if you prefer Yarn)
```

#### Configure Environment
Copy .env.example to .env:

```
cp .env.example .env


APP_NAME="Soja Time & Attendance"
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=soja_attendance
DB_USERNAME=root
DB_PASSWORD=secret

```

#### Generate Application Key

```bash
  php artisan key:generate
```

#### Run Migrations

```bash
   php artisan migrate
   (Optional) Seed default data:
```

```bash
   php artisan db:seed
```

### 🏃 Running the Application

##### Start Laravel's development server:

```bash
  php artisan serve
```

##### The application will be available at:

http://127.0.0.1:8000

### ElevateHR leave webhook

1. Deploy the code and run `php artisan migrate`. The migration adds decimal
   `leaves.num_of_days`, backfills existing calendar-day durations, and adds
   organisation-scoped leave types and webhook reference/day-detail columns.
2. Set `APP_URL` to the public **HTTPS** address.
3. Grant `manage-elevate-callback` to the appropriate user role under Account
   Settings > Access & Security > User Roles & Permissions. The migration registers
   the permission without granting it to existing roles.
4. Open Account Settings > Access & Security > **ElevateHR Webhook** (next to QR
   Token Management) and choose **Generate webhook**, then **Copy URL**.
   Alternatively, run `php artisan elevatehr:leave-webhook <organisation-id>`.
   Copy the generated URL into that organisation's ElevateHR **Webhook URL**, save
   it, enable the webhook, and save again.

The endpoint is `POST /api/webhooks/elevatehr/leave/{organization}?token=...`.
Each organisation has its own randomly generated token; only its SHA-256 hash is
stored. Running the command again immediately rotates that organisation's token.
The **Replace webhook** action does the same: replacement is atomic, leaving one
active URL and permanently invalidating all older URLs. The URL is shown only
immediately after generation; it cannot be recovered after leaving/reloading the
page. If lost, generate a replacement and update ElevateHR.
The tab shows the 10 most recent generations, including their creator, generation
time, status, and replacement/revocation time; older audit records are retained.
Command-line generations are labelled accordingly. Existing tokens are imported
as legacy records with an unknown creator and the setting's last-updated time.
Missing/invalid credentials are rejected with HTTP 403. JSON validation,
a 256 KiB body limit, and a 120-request/minute/IP rate limit are also enforced.
Configure a matching body limit at the reverse proxy.

#### Webhook monitoring and failure alerts

Run `php artisan migrate` to create `failed_elevate_webhooks` and
`elevate_webhook_daily_stats`. On the same **ElevateHR Webhook** settings page:

- View today's and overall received/failed totals, daily totals for the latest
  30 days with deliveries, and the five most recent failure causes.
- Set a daily failure threshold, up to 20 notification email addresses, and
  either one email per day or repeat alerts at threshold multiples.
- Save alert settings independently of webhook generation; leaving recipients
  empty disables email alerts. The default threshold is 10 failures.

Counts are per organisation, use the application's timezone, and start when
monitoring is deployed (no historical backfill). Every delivery counts, including
successful duplicates, authentication rejections, rate-limited requests, invalid
payloads, and processing failures. Failed deliveries count only once, including
validation exceptions and rolled-back leave transactions. Daily records are
retained and summed for overall totals. Failures for nonexistent organisation
IDs are retained for operators but are not shown to other organisations.

Failure records retain source IP, HTTP status, a cause, and field validation
errors, not secret tokens, URLs, or raw leave payloads. Unexpected server errors
have a generic cause; use application error logs for technical diagnostics.
Employee lookup failures also retain metadata containing the attempted
`ad_employee_id`, the supplied employee name, and matching employees' internal
IDs, names, and emails (an empty list when no employee matches). Run
`php artisan migrate` before deploying the updated webhook.
Requests rejected before Laravel's route middleware (for example by the proxy,
PHP body limits, maintenance mode, or unmatched routes) cannot be counted here.

Configure a working Laravel mail transport and run `php artisan queue:work` in
production. Alert jobs retry failed delivery three times with a 60-second
backoff; inspect/retry exhausted jobs using Laravel's failed-job tooling.
Alerts are deduplicated against each day's stored notification count; when
workers lag, a single email can summarize multiple crossed thresholds. Mail
transport failures do not change webhook outcomes. Settings changes apply to
the next failure or pending alert job; saving alone does not send an alert.

Set `ELEVATEHR_WEBHOOK_ALLOWED_IPS` to a comma-separated list of ElevateHR
source IP addresses or CIDR ranges. Requests outside the list are rejected with
HTTP 403 and logged with their source IP and organisation ID. An unset or empty
list denies all webhook requests. If the application is behind a reverse proxy,
configure Laravel to trust only that proxy so `$request->ip()` resolves the
verified client IP; do not trust arbitrary forwarded-IP headers.

**Security limitation:** ElevateHR supplies no signature, authentication headers,
or published source IPs in the supplied contract. The secret URL is a bearer
credential, not proof that ElevateHR sent a request. Keep it out of access logs,
APM/error reports, screenshots, and source control; redact the `token` query
parameter at the proxy and in monitoring. Do not enable this endpoint over HTTP.
Origin/Referer headers are not trusted as sender authentication. Stronger sender
verification would require provider-supported signatures or verified source IPs.

Processing follows the existing `leaves` record format:

- `leave.employee_number` matches `employees.ad_employee_id`, **not** the internal
  employee ID or `id_number`. This is a string of up to 255 characters, supporting
  alphanumeric IDs and leading zeros. Exactly one employee must match within
  the organisation in the URL, excluding deleted employees and system users.
  Zero or multiple matches return HTTP 422 and record the reason and lookup
  metadata without creating or changing leave. `leaves.employee_id` still stores
  the matched employee's internal ID.
- `leave.approved` creates or updates an **approved** leave. Names are matched
  case-insensitively against the organisation's saved and built-in leave types;
  unknown names are created and become available in the existing UI/API lists.
- `leave.partial_recall` replaces the same leave's remaining dates/day details
  and decimal duration, keeping it approved. The supplied `days` and `total_days`
  are interpreted as the **remaining leave snapshot**, not days to subtract
  (consistent with the supplied example). Half-days and gaps are retained.
- `leave.recalled` marks the record **cancelled**, clears its remaining days and
  expected resumption, sets the duration to zero, and records `cancelled_at`.
- An organisation plus `leave_reference` identifies the same leave across events.
  A sequence no newer than the saved sequence is acknowledged without changing
  it. This makes retries and out-of-order deliveries safe. A recall arriving
  before approval creates the latest snapshot so an older approval cannot revive it.
- Successful/duplicate deliveries return HTTP 200 after the database transaction
  commits. Unknown employees/invalid data return HTTP 422; database failures
  remain errors so ElevateHR can retry. Only the three documented event types
  are accepted.

Per-day details drive leave report totals and prevent recalled gaps/holidays
from being marked as leave by the attendance seeder. Existing attendance uses a
single daily `on_leave` status; this integration does not introduce half-day
attendance statuses or rewrite historical attendance/punches. Half-day amounts
are retained in leave records and reports. Existing manual leave entry remains
whole-day based.

Focused tests (fixtures isolate this feature from historical migrations):

By default, the tests use an in-memory SQLite database and require PDO SQLite.
Alternatively, create a separate **empty, disposable MySQL database** whose name
ends in `_test` or `_testing`, copy `.env.testing.example` to `.env.testing`, and
fill in the `ELEVATEHR_TEST_DB_*` connection settings. Do not use the application
database: tests delete all tables in this disposable database after each test.
`ELEVATEHR_TEST_DB_RESET=true` explicitly enables this cleanup; the suite refuses
to start against a nonempty database. The test account needs schema/table
creation and deletion privileges within that database, not server-wide privileges.
Generate a test application key with `php artisan key:generate --env=testing`.
The local `.env.testing` file is gitignored. Then run the same focused command:

```bash
php artisan test tests/Unit/ElevateHrLeaveWebhookTest.php tests/Unit/ElevateHrWebhookAlertPolicyTest.php
```

Alert threshold logic can also be tested without any database:

```bash
php artisan test tests/Unit/ElevateHrWebhookAlertPolicyTest.php
```

For the related attendance regression tests (which still require PDO SQLite):

```bash
php artisan test tests/Unit/AttendanceSeederMultiShiftWindowTest.php
```

### 📜 License

This project is licensed under the MIT License – you are free to use, modify, and distribute it.

### 🤝 Contributing

Fork the project.

Create a new branch:

```bash
  git checkout -b feature/amazing-feature
  Commit your changes:
```

```bash
  git commit -m "Add amazing feature"
  git push origin feature/amazing-feature
```
