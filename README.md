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
- **Break Scheduling** - Break windows in the same shift cannot overlap, including overnight windows.
  Back-to-back breaks are allowed. Set both window times or leave both blank for an unrestricted break.
  A break missing either window time is unrestricted and must be the only break in its shift.
  Creating or editing a conflicting break shows the existing break names and times in a five-second error alert.

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

