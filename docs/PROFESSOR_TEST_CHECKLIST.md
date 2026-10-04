# Midterm demo checklist

Use the database in `database/collegeweb.sql`. Do not run `php scripts/import_all.php` after that import. That script loads the older CSV copy.

```bash
mysql -u root -p < database/collegeweb.sql
php -S 127.0.0.1:8000 -t public public/router.php
```

Sign in at http://127.0.0.1:8000/login.php with a school email and `Main@1234`.

## What to show

1. Successful login opens the admin dashboard. Fall 2026 is the current term. The campus card says 3 buildings.
2. Sign out. Sign in with a real school email that has no account. The page stays on sign-in and says the email or password is not valid.
3. Sign in as the main admin. On Accounts, set a new password of at least 8 characters, sign out, and sign in with that password.
4. People: a real 7-digit id opens the record. A made-up 7-digit id asks for a valid student or faculty id.
5. The public Fall 2026 schedule shows a room like `NAB102`.
6. Registration blocks a missing prerequisite, waitlists a full section, and blocks an active hold until the hold is cleared.

The written steps are in `docs/USER_MANUAL.md`. How the tables are built is in `docs/SYSTEM_MANUAL.md`.
