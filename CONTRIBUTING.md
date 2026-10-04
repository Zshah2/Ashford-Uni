# Contributing

Thanks for helping on Ashford College (CollegeWeb). This repo is shared by a small team — follow these rules so nobody overwrites each other's work.

## Golden rule

**Do not push directly to `main`.** The owner merges via Pull Requests only.

## Branch workflow

```bash
git checkout main
git pull origin main
git checkout -b feature/your-name-topic
```

### Branch naming examples

| Branch | When to use |
|--------|-------------|
| `feature/john-departments` | New admin departments UI |
| `feature/maria-catalog-filters` | Enhancement to existing area |
| `fix/maria-holds` | Bug fix |
| `docs/sara-readme` | Documentation only |

Use lowercase, hyphens, and a short topic. Prefix with `feature/`, `fix/`, or `docs/`.

### Commit and push

```bash
git add .
git commit -m "Short description of what changed and why"
git push -u origin feature/your-name-topic
```

Open a **Pull Request** on GitHub against `main`. Ask a teammate to review. After merge, delete the branch and pull fresh `main` before starting the next task.

```bash
git checkout main
git pull origin main
```

## Local setup

See **[docs/COLLAB.md](docs/COLLAB.md)** for clone → DB config → migrate → import/seed → run server.

Quick path:

```bash
git clone https://github.com/Zshah2/NorthBridge.git
cd NorthBridge
cp app/config/database.local.php.example app/config/database.local.php   # edit credentials
bash scripts/setup_teammate.sh
php -S 127.0.0.1:8000 -t public public/router.php
```

## Optional: pre-push guard

To get a reminder if you accidentally try to push to `main`, install the optional hook (one-time per clone):

```bash
cp scripts/pre-push .git/hooks/pre-push
chmod +x .git/hooks/pre-push
```

This is **opt-in** — it only affects your machine. To remove: `rm .git/hooks/pre-push`.

## Files to never commit

- `app/config/database.local.php`
- `app/config/2fa_config.php`, `2fa_config.local.php`
- `docs/LOGIN_CREDENTIALS.txt`
- `.env` files

Copy from the matching `.example` files instead.

## Testing before a PR

```bash
php scripts/smoke_check.php http://127.0.0.1:8000   # server must be running
```

For demo/grader flows: **[docs/PROFESSOR_TEST_CHECKLIST.md](docs/PROFESSOR_TEST_CHECKLIST.md)**

## Questions?

Ask in your team chat or open a draft PR early for feedback.
