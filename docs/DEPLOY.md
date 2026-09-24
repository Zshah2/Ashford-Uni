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

### AWS Lightsail

- Lightsail PHP + MySQL blueprint, or LAMP instance
- Set `DB_*` to Lightsail database connection details
- `public/` as web root

### AWS EC2 + RDS

- EC2: Apache/nginx + PHP-FPM, `public/` as docroot
- RDS: set `DB_HOST` to RDS endpoint, `DB_PORT` to `3306`
- Security group: allow EC2 → RDS on 3306

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
