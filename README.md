## Ashford College (CollegeWeb)

PHP + MySQL (PDO) + Tailwind. Public site uses the front controller (`public/index.php`); **admins** use **`public/login.php`** and the unified **`public/admin.php`** dashboard.

### For teammates

If you were invited as a GitHub collaborator:

1. Read **[CONTRIBUTING.md](CONTRIBUTING.md)** — **never push directly to `main`**; always use a feature branch and Pull Request.
2. Follow **[docs/COLLAB.md](docs/COLLAB.md)** for clone → DB → migrate → run server.
3. Quick setup: `bash scripts/setup_teammate.sh` (after configuring DB; see below).

Branch example: `git checkout -b feature/your-name-topic` from latest `main`.

### Admin accounts (local only)

Demo usernames and passwords are **not** stored in this repo. After cloning, copy `docs/LOGIN_CREDENTIALS.txt.example` → `docs/LOGIN_CREDENTIALS.txt` and fill in values for your machine (that file is gitignored).

### Database credentials

The app connects through **`app/lib/db.php`**. On a cloud host, set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` (and `DB_PORT` if needed) in the platform’s environment variables — or the `MYSQL_*` aliases (see [docs/DEPLOY.md](docs/DEPLOY.md)). Locally, copy **`app/config/database.local.php.example`** → **`app/config/database.local.php`** (same folder) and edit host, database name, user, and password.

If you see **“Cannot connect to MySQL”** on the login page, MySQL may be stopped **or** the DB user/password in your local config does not match your server.

### Setup

```bash
# Create DB (example)
mysql -e "CREATE DATABASE collegeweb CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"

# Option A: env vars
export DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=collegeweb DB_USER=… DB_PASS=…

# Option B: copy app/config/database.local.php.example → app/config/database.local.php and edit

php scripts/migrate.php
php scripts/import_all.php
# After import, automatically fills empty course descriptions and infers prerequisite links (same-prefix numbering).
# To skip that step: SKIP_CATALOG_ENRICH=1 php scripts/import_all.php

php scripts/seed_demo_registration.php
# Fills terms FA26/SP27/FA27, demo catalog rows (BI0101 chain), sections, and sample enrollments.
# SQL-only equivalent (after import): mysql … < database/seeds/prefill_course_demo.sql

php scripts/enrich_all_courses.php
# Optional manual re-run (same as post-import auto-enrich). Use --force to overwrite every description.

php scripts/seed_full_catalog.php
# One-shot: 5+ realistic courses per dept (BIO, CHE, COM, ECO, ENG, ENGL, HIS, PHI) with descriptions + prereq chains; then fills gaps on other imported courses.

php scripts/fix_duplicate_course_enrollments.php
# If two sections of the same course_id (e.g. BIO110) are enrolled/waitlisted for one student in one term, drops extras (keeps enrolled over waitlist, then earliest enrollment_id). Use --dry-run first.

# Admin portal users — use your own passwords (see docs/LOGIN_CREDENTIALS.txt.example)
php scripts/seed_superadmin.php <email> <password> [username]
php scripts/seed_limited_admin.php <username> <password>
php scripts/seed_staff.php

composer install
```

Optional: `APP_DEBUG=1` for verbose errors during development.

Staff sign-in is **email + password** (no 2FA for now). Optional email OTP can be enabled later via `portal_2fa_enabled` in `app/config/2fa_config.php`.

### URLs

- **Marketing / home:** `/` (via `public/index.php` + router)
- **Admin sign-in:** `public/login.php` (direct file — works with PhpStorm built-in server)
- **Admin dashboard:** `public/admin.php` — full sidebar (People, schedule, registration, catalog, holds, accounts, …)

### Built-in server

```bash
php -S localhost:8000 -t public public/router.php
```

Then open `http://localhost:8000/` and `http://localhost:8000/login.php`.

### Requirements

- PHP 8+
- MySQL 8+ (or compatible)
- [Composer](https://getcomposer.org/) (optional; only needed if you enable email OTP later)

### Health checks

Run a quick validation pass before you compare branches or prepare changes:

```bash
composer run check
```

This validates PHP syntax across the app, config, public entry points, and scripts.

### Deploy (DigitalOcean, AWS, VPS)

See **[docs/DEPLOY.md](docs/DEPLOY.md)** for environment variables (`DB_*`, `SMTP_*`), `composer install`, and `php scripts/migrate.php`. Moving servers: **[docs/MIGRATING_TO_DIFFERENT_SERVER.md](docs/MIGRATING_TO_DIFFERENT_SERVER.md)**.

### Project notes

- Department emails in `storage/import/department.csv` use `@ashford.edu`; re-run `import_all.php` after edits.
- UI polish backlog: [docs/UI_FINE_TUNE_CHECKLIST.txt](docs/UI_FINE_TUNE_CHECKLIST.txt)
- Grader checklist: [docs/PROFESSOR_TEST_CHECKLIST.md](docs/PROFESSOR_TEST_CHECKLIST.md)
