# Afhamha Wa Fahmni SIS

Laravel-based school information system for administration, parents, and students.

The current implementation is one unified Laravel application. The parent and student portals are Blade-based web portals inside the same project, using the same authentication system and the same database models/relationships as the administration portal.

## Local Development

The project uses the PostgreSQL database configured in `.env`. The application reads and writes real records through Laravel's database connection; browser storage is not the source of truth.

### Requirements

- PHP 8.3 or newer. The local machine used for this project has `C:\php\php.exe`.
- Composer dependencies installed in `vendor/`.
- SQLite PHP extensions enabled when running locally.

### Start the local server

Use the project script:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\start-local.ps1
```
 
Then open the local login page:

```text
http://127.0.0.1:5500/login
```

The script opens this URL automatically on Windows unless you pass `-NoBrowser`.

Use `Ctrl + F5` in the browser if the page was previously cached without styling.

In VS Code, use the task named `Start Laravel local server`. You can also double-click `start-local.cmd` on Windows. The Live Server extension's `Go Live` button can only open the helper page at the project root; it cannot run Laravel/PHP. The workspace moves Live Server to port `5501` so it does not take Laravel's `5500` port.

Why this script exists: `start-local.ps1` starts PHP directly and uses `start-local-router.php` so static files such as CSS, icons, and service worker files are served correctly.

## Demo Accounts

These accounts are created by the local demo seeder:

```text
Admin
Email: admin@example.test
Password: password123

Parent
Email: parent@example.test
Password: password123

Teacher
Email: teacher1@example.test
Password: password123

Second Teacher
Email: teacher2@example.test
Password: password123

Student
Email: student1@example.test
Password: password123

Second Student
Email: student2@example.test
Password: password123
```

## Parent Login

1. Open `http://127.0.0.1:5500/login`.
2. Enter:

```text
parent@example.test
password123
```

3. After login, the system redirects the parent to:

```text
http://127.0.0.1:5500/parent/dashboard
```

The parent account can only see students linked to its `guardians` record through the existing `guardian_student` relationship. Directly changing a student ID in the URL is blocked for unlinked students.

## Portals

### Public Login

All users sign in from the same login page:

```text
/login
```

After authentication, users are redirected by role:

- Admin users: `/admin/dashboard`
- Parent users: `/parent/dashboard`
- Student users: `/student/dashboard`

### Parent Portal

Parent routes are protected by authentication and the `parent` middleware:

```text
/parent/dashboard
/parent/children
/parent/children/{student}
/parent/results
/parent/messages
/parent/profile
/parent/more
```

Included parent features:

- Mobile-first RTL Blade layout.
- Bottom navigation for home, children, results, and messages.
- Compact side menu for profile, attendance, assignments, exams, notifications, and logout.
- Dashboard showing linked children and available academic summaries.
- Child list and child detail pages.
- Child switching when more than one student is linked.
- Profile page with real user record updates.
- PWA manifest, icons, service worker, and offline page.
- No sensitive portal pages are stored in service worker cache; only static assets and the offline page are cached.

### Student Portal

Student routes are protected by authentication and the `student` middleware:

```text
/student/dashboard
/student/results
/student/messages
/student/profile
```

The student portal uses the existing `users -> students` relationship and reads attendance, grades, and announcements from the shared database tables.

### Administration Portal

Admin routes remain under:

```text
/admin
/admin/dashboard
```

Only active admin users can access administration pages.

## Database

The application database target is PostgreSQL. Keep the existing database name and credentials in `.env`; do not use `migrate:fresh` against the real database.

Current local `.env` values:

```text
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=afhamha_sis
DB_USERNAME=postgres
DB_PASSWORD=
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
CACHE_STORE=file
```

## Local Database Setup

Run pending migrations against PostgreSQL:

```powershell
C:\php\php.exe artisan migrate --force
```

The default `DatabaseSeeder` is intentionally empty and does not modify real data. Seed demo accounts only when explicitly needed:

```powershell
C:\php\php.exe artisan db:seed --class=LocalDemoSeeder --force
```

Do not run `php artisan migrate:fresh` on `afhamha_sis`; it deletes all tables and data.

## PWA Files

Parent PWA files:

```text
/parent-manifest.webmanifest
/parent-sw.js
/parent-offline.html
/icons/parent-icon.svg
/icons/parent-icon-192.png
/icons/parent-icon-512.png
/icons/parent-maskable-512.png
/favicon.ico
```

Install behavior is available from the browser when opening the parent portal on a supported device/browser.

The manual PWA install and Lighthouse checklist is in `docs/parent-pwa-qa.md`.

Run the local parent PWA audit with the demo parent account:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\audit-pwa.ps1
```

On Windows you can also double-click `audit-pwa.cmd`.

## Tests

Run the full test suite with the configured test database:

```powershell
C:\php\php.exe vendor\phpunit\phpunit\phpunit
```

Or use the included helper:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\run-tests.ps1
```

On Windows you can also double-click `run-tests.cmd`.

Recent verification:

```text
68 tests, 526 assertions
```
