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
3. Run `php artisan elevatehr:leave-webhook <organisation-id>`. Copy the generated
   URL into that organisation's ElevateHR **Webhook URL**, save it, enable the
   webhook, and save again.

The endpoint is `POST /api/webhooks/elevatehr/leave/{organization}?token=...`.
Each organisation has its own randomly generated token; only its SHA-256 hash is
stored. Running the command again immediately rotates that organisation's token.
Missing/invalid credentials are rejected with HTTP 403. JSON validation,
a 256 KiB body limit, and a 120-request/minute/IP rate limit are also enforced.
Configure a matching body limit at the reverse proxy.

**Security limitation:** ElevateHR supplies no signature, authentication headers,
or published source IPs in the supplied contract. The secret URL is a bearer
credential, not proof that ElevateHR sent a request. Keep it out of access logs,
APM/error reports, screenshots, and source control; redact the `token` query
parameter at the proxy and in monitoring. Do not enable this endpoint over HTTP.
Origin/Referer headers are not trusted as sender authentication. Stronger sender
verification would require provider-supported signatures or verified source IPs.

Processing follows the existing `leaves` record format:

- `leave.employee_number` matches `employees.id` (the value stored in
  `leaves.employee_id`), **not** `id_number` or `ad_employee_id`. The employee must
  belong to the organisation in the URL and must not be deleted/a system user.
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

Focused tests (PHP PDO SQLite is required; fixtures isolate this feature from
historical MySQL-only migrations):

```bash
php artisan test tests/Unit/ElevateHrLeaveWebhookTest.php tests/Unit/AttendanceSeederMultiShiftWindowTest.php
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


