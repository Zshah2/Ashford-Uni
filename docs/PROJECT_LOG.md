# Project log (reference)

Short, dated notes on **what changed** and **why**, so you (or a grader) can trace decisions without digging through chat history.

Add new entries at the **top** under the latest date.

---

## 2026-05-28 — UI fine-tune checklist + login polish

**Goal:** Single backlog for theme, holds, enrollment rules, admin UX, and grader smoke paths; then polish staff login.

**Changes**

| Area | Detail |
|------|--------|
| [docs/UI_FINE_TUNE_CHECKLIST.txt](UI_FINE_TUNE_CHECKLIST.txt) | New plain-text checklist (`[ ]` / `[x]` / `[~]`) — full backlog from recent work |
| [public/login.php](../public/login.php) | Light/dark alerts, logo, register form styling, background accent |
| Theme stack | `theme_init`, `theme_toggle`, `theme_boot`, [public/assets/css/theme.css](../public/assets/css/theme.css), `nb-staff` on admin/login |

**Next:** Work through section 1 (admin dark audit) using the checklist; optional homepage light hero.

---

## 2026-04-22 — `public/login.php` + `public/signup.php` shims

**Goal:** Some setups expect real files named `login.php` in `public/`.

**Change:** [public/login.php](../public/login.php) and [public/signup.php](../public/signup.php) issue a **302** to `index.php/login` and `index.php/signup` (path derived from `SCRIPT_NAME`). Main app remains single front controller [public/index.php](../public/index.php).

---

## 2026-04-22 — Avoid “404” with no PHP (router + .htaccess)

**Problem:** If the web server never runs `index.php` for `/login`, you get the server’s hard **404** (not the app’s styled page).

**Changes:** [public/router.php](../public/router.php) for `php -S … -t public public/router.php`; [public/.htaccess](../public/.htaccess) for Apache rewrite to `index.php`. README run instructions updated. [app/views/pages/404.php](../app/views/pages/404.php) copy softened (“This page isn’t here”).

---

## 2026-04-22 — Router path normalization + home CTA

**Changes:** [public/index.php](public/index.php) — normalize backslashes in `REQUEST_URI` path and `SCRIPT_NAME`; extra strip when path still contains `/index.php/`; comment pointing to [app/routes.php](app/routes.php). [app/views/pages/home.php](app/views/pages/home.php) — hero “Apply” CTA uses `nav_url()` like the navbar.

---

## 2026-04-22 — PhpStorm :63342 and `/public/login`

**Problem:** JetBrains’ built-in server (`http://localhost:63342/...`) does not rewrite `/CollegWeb/public/login` to `index.php`, so PHP routing never runs.

**Change:** [app/lib/url.php](app/lib/url.php) — when `HTTP_HOST` contains `:63342` (or `APP_USE_INDEX_PHP_LINKS=1`), internal links use `/…/public/index.php/login` style via `app_front_controller()` + `app_use_index_php_in_links()`. Override off with `APP_USE_INDEX_PHP_LINKS=0`.

---

## 2026-04-22 — Login link / subpath routing

**Problem:** Navbar **Login** used `href="/login"`, which goes to the **server root**, not the app folder when the site runs under a subpath (e.g. `.../public/`). Same for CSS/JS `/assets/...` and form `action` attributes.

**Changes**

| Area | Detail |
|------|--------|
| [app/lib/url.php](app/lib/url.php) | New `app_base_path()`, `url()`, `nav_url()` (optional `APP_BASE_PATH` override). |
| [public/index.php](public/index.php) | Strip `dirname(SCRIPT_NAME)` from the request path so `/public/login` matches the `/login` route. |
| Views / layout | All internal `href` / `action` and asset URLs go through `url()` or `nav_url()`. |
| [app/lib/auth.php](app/lib/auth.php), [app/controllers.php](app/controllers.php), [app/lib/csrf.php](app/lib/csrf.php) | Redirects / CSRF recovery link use `url()`. |

---

## 2026-04-22 — Styled 404 (unknown routes)

**Goal:** Unknown URLs were always `404` with plain text `Not found.` — correct behavior, but not obvious what went wrong.

**Changes:** [public/index.php](public/index.php) now renders [app/views/pages/404.php](app/views/pages/404.php) (same layout as the site) with the path and links to `/` and `/login`. [docs/PROFESSOR_TEST_CHECKLIST.md](docs/PROFESSOR_TEST_CHECKLIST.md) updated accordingly.

---

## 2026-04-22 — Login / signup UX

**Goal:** Make staff vs student expectations obvious; improve browser tab titles; avoid a raw-text CSRF failure.

**Changes**

| Area | Detail |
|------|--------|
| [app/views/pages/admin/login.php](app/views/pages/admin/login.php) | Staff + Admin labels, clearer copy (signup vs `seed_admin.php`, staff tools not student portal), link back to `/`. |
| [app/views/pages/admin/signup.php](app/views/pages/admin/signup.php) | Same labeling; explains admin account + password rule; link home. |
| [app/views/partials/seo.php](app/views/partials/seo.php) | Optional `pageTitle` → document title `Page — Ashford College`. |
| [app/controllers.php](app/controllers.php) | Pass `pageTitle` for login and signup renders. |
| [app/lib/csrf.php](app/lib/csrf.php) | CSRF failure returns a small styled HTML page with link to `/login`. |

---

## 2026-04-22 — Git: initial push to GitHub

**Goal:** Version the project on GitHub (`NorthBridge`, private remote).

**Changes**

| Area | Detail |
|------|--------|
| Repository | Initialized git in project root (replaced incomplete `.git` from an earlier partial init), first commit on `main`, remote `origin` → `https://github.com/Zshah2/NorthBridge.git`, pushed `main`. |

**Note:** Set `git config user.name` / `user.email` if you want commits attributed to your GitHub identity instead of the machine default.

---

## 2026-04-22 — Department emails: Northbridge branding

*(Historical note: school domain was later rebranded to `@ashford.edu` / Ashford College.)*

**Goal:** Remove the synthetic `@boolean.edu` domain from demo data so nothing in the repo reads “boolean” for school contact email.

**Changes**

| Area | Detail |
|------|--------|
| [storage/import/department.csv](../storage/import/department.csv) | Replaced `@boolean.edu` with `@ashford.edu` on all department `email` values (local-part unchanged, e.g. `physics@ashford.edu`). |
| [README.md](../README.md) | Noted that department emails use `@ashford.edu` and that `php scripts/import_all.php` should be re-run after editing the CSV so `departments.email` in MySQL stays in sync. |

**Verification**

- Repo search for `boolean` / `boolean.edu` under project root: **no matches** (after CSV update).

**What you should do locally**

- If MySQL was populated **before** this change: run `php scripts/import_all.php` (with your `DB_*` env) so `departments` rows pick up the new emails.

**Related**

- Import path: `import_departments()` in [scripts/import_all.php](../scripts/import_all.php).
- Demo / grading flows: [PROFESSOR_TEST_CHECKLIST.md](PROFESSOR_TEST_CHECKLIST.md).

---

## How to use this file

- After any meaningful change (schema, import data, admin behavior), add **one short dated block**: goal, files touched, how to verify, optional “run this command”.
- Keep it factual; link to other docs instead of pasting long SQL here.
