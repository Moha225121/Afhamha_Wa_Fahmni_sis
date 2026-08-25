# Afhamha Wa Fahmni SIS

Laravel-based school information system for administration, parents, and students.

The current implementation is one unified Laravel application. The parent and student portals are Blade-based web portals inside the same project, using the same authentication system and the same database models/relationships as the administration portal.

## Local Development

The project is configured to work locally with SQLite for now. PostgreSQL will be connected later by changing the `.env` database settings.

### Requirements

- PHP 8.3 or newer. The local machine used for this project has `C:\php-8.4.12\php.exe`.
- Composer dependencies installed in `vendor/`.
- SQLite PHP extensions enabled when running locally.

### Start the local server

Use the project script:

```powershell
.\start-local.ps1
```

Then open the local login page:

```text
http://127.0.0.1:5500/login
```

Use `Ctrl + F5` in the browser if the page was previously cached without styling.

Why this script exists: `php artisan serve` may start without the SQLite extensions on this machine. `start-local.ps1` starts PHP directly with `pdo_sqlite` and `sqlite3` enabled and uses `start-local-router.php` so static files such as CSS, icons, and service worker files are served correctly.

## Demo Accounts

These accounts are created by the local demo seeder:

```text
Admin
Email: admin@example.test
Password: password123

Parent
Email: parent@example.test
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
- Bottom navigation for home, children, results, messages, and more.
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

The approved production database target is PostgreSQL. Local development currently uses SQLite so the project can run before PostgreSQL is installed/configured.

Current local `.env` values:

```text
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
CACHE_STORE=file
```

When PostgreSQL is ready, update `.env` back to a PostgreSQL connection and run migrations against the PostgreSQL database:

```text
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=afhamha_sis
DB_USERNAME=postgres
DB_PASSWORD=
```

## Local Database Setup

Create the SQLite file and run migrations:

```powershell
New-Item -ItemType File -Force database\database.sqlite
C:\php-8.4.12\php.exe -d extension=fileinfo -d extension=zip -d extension=pdo_sqlite -d extension=sqlite3 artisan migrate --force
```

Seed demo accounts and linked parent/student data:

```powershell
C:\php-8.4.12\php.exe -d extension=fileinfo -d extension=zip -d extension=pdo_sqlite -d extension=sqlite3 artisan db:seed --class=LocalDemoSeeder --force
```

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
```

Install behavior is available from the browser when opening the parent portal on a supported device/browser.

## Tests

Run the full test suite with PHP 8.4 and SQLite extensions enabled:

```powershell
C:\php-8.4.12\php.exe -d extension=fileinfo -d extension=zip -d extension=pdo_sqlite -d extension=sqlite3 vendor\phpunit\phpunit\phpunit
```

Recent verification:

```text
18 tests, 66 assertions
```
