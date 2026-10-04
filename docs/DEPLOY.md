# Deploying CollegWeb (cloud / VPS)

Portable deployment for **DigitalOcean App Platform**, **AWS** (Lightsail, EC2 + RDS), or similar PHP + MySQL hosts. No provider-specific files in the repo — only environment variables.

See also [MIGRATING_TO_DIFFERENT_SERVER.md](MIGRATING_TO_DIFFERENT_SERVER.md) for moving data between servers.

---

## 1. Requirements

- PHP 8.1+ with extensions: `pdo_mysql`, `mbstring`, `openssl` (for SMTP)
- MySQL 8+ (or MariaDB compatible)
- Composer (build step or run locally before deploy)
- Web document root → **`public/`**

---

## 2. Environment variables

Set these in your host’s dashboard (App Platform, Elastic Beanstalk, Lightsail, etc.). **Do not commit secrets to git.**

### Database (use either naming style)

| Variable | Example | Notes |
|----------|---------|--------|
| `DB_HOST` | `your-db-host.example.com` | RDS endpoint, DO managed DB host, etc. |
| `DB_PORT` | `3306` | |
| `DB_NAME` | `collegeweb` | Database name |
| `DB_USER` | `app_user` | |
| `DB_PASSWORD` | *(secret)* | |

Aliases also supported: `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`.

### Email OTP (2FA) — optional, off by default

Sign-in is **email + password** unless you enable 2FA. To turn on email OTP later:

| Variable | Example |
|----------|---------|
| `PORTAL_2FA_ENABLED` | `1` |
| `SMTP_HOST` | `smtp.gmail.com` |
| `SMTP_PORT` | `587` |
| `SMTP_ENCRYPTION` | `tls` or `ssl` |
| `SMTP_USERNAME` | SMTP user |
| `SMTP_PASSWORD` | SMTP password / app password |
| `SMTP_FROM_EMAIL` | `noreply@yourdomain.edu` |
| `SMTP_FROM_NAME` | `Ashford College Admin` |
| `OTP_EXPIRY_MINUTES` | `5` (optional) |

Locally you can use `app/config/2fa_config.php` (copy from `2fa_config.php.example`) instead of SMTP env vars.

---

## 3. Build / install dependencies

```bash
composer install --no-dev --optimize-autoloader
```

On hosts that run Composer during deploy, use the same command in the build step. `vendor/` is gitignored but must exist on the server.

---

## 4. Database schema (one time per environment)

From your laptop or a one-off job, with DB env vars pointing at the **remote** database:

```bash
export DB_HOST=...
export DB_PORT=3306
export DB_NAME=...
export DB_USER=...
export DB_PASSWORD=...

php scripts/migrate.php
```

Optional data:

```bash
php scripts/import_all.php
php scripts/seed_demo_registration.php
php scripts/seed_superadmin.php <email@example.com> '<password>' [username]
```

---

## 5. Web server

- **Document root:** `public/`
- **Front controller / router:** `public/router.php` (PHP built-in server and many PaaS templates)
- **Direct PHP entry points:** `login.php`, `admin.php`, `verify_otp.php`, `index.php`

### PHP built-in (development)

```bash
php -S localhost:8000 -t public public/router.php
```

### Apache

Point `DocumentRoot` at `public/`. Enable `mod_rewrite` if you route through `index.php`.

### nginx + PHP-FPM

Root `public/`; pass PHP to FPM. Static files served directly.

---

## 6. Provider notes

### DigitalOcean App Platform

- Component type: **Web Service**, PHP
- HTTP port: often `8080` internally; platform sets `PORT`
- Run command example: `php -S 0.0.0.0:${PORT:-8080} -t public public/router.php`
- Add managed MySQL or attach a DO database cluster; map credentials to `DB_*` env vars
- Set SMTP env vars for 2FA

### AWS (EC2, Elastic Beanstalk, or Lightsail) + RDS

Do this once. The app does not read `database.local.php` when `DB_HOST` and `DB_NAME` are set, so this Mac’s MySQL password stays off the server.

1. Create an RDS MySQL 8 database. Note the endpoint, port, name, user, and password.
2. Security group: allow the web server to reach RDS on port 3306. Do not open 3306 to the whole internet.
3. Import the shared database. This file already has the schema and the logins. Do **not** run `scripts/import_all.php` afterward. That script replaces `collegeweb` with the older CSV import.

```bash
mysql -h YOUR_RDS_ENDPOINT -P 3306 -u YOUR_DB_USER -p < database/collegeweb.sql
```

4. Point the site at RDS. Set these on the instance or in the Elastic Beanstalk environment:

| Variable | Value |
|----------|--------|
| `DB_HOST` | RDS endpoint |
| `DB_PORT` | `3306` |
| `DB_NAME` | `collegeweb` |
| `DB_USER` | RDS user |
| `DB_PASSWORD` | RDS password |
| `APP_DEBUG` | `0` |
| `TRUST_PROXY` | `1` when HTTPS stops at a load balancer |

`DB_PASS` is accepted as an alias of `DB_PASSWORD`. `MYSQL_HOST`, `MYSQL_DATABASE`, `MYSQL_USER`, and `MYSQL_PASSWORD` work too.

5. Document root should be `public/`. If the host can only use the repo folder as the root, the root `.htaccess` forwards requests into `public/` and blocks `app/`, `database/`, and `scripts/`.
6. Optional TLS to RDS: set `DB_SSL_CA` to the path of the Amazon RDS CA bundle on the server.
7. Sign-in is email + `Main@1234` for the accounts already in the dump. Composer is only required if you later turn on email OTP.

Smoke test: `/` loads, `/login.php` does not say “Cannot connect to MySQL”, and an admin email reaches the dashboard.

---

## 7. Smoke test

1. Public home loads (`/`)
2. `login.php` — no “Cannot connect to MySQL”
3. Staff login with email + password → `admin.php?view=dashboard` (full dashboard with sidebar).
4. (Optional, when 2FA enabled) OTP email → `verify_otp.php`

---

## 8. Security checklist

- Never commit `database.local.php`, `2fa_config.php`, or `.env` with real passwords
- Rotate DB and SMTP credentials if they were ever exposed
- Use HTTPS on production
- `APP_DEBUG=0` in production
