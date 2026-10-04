# Ashford College system manual

This describes how the midterm system is put together. The running application is PHP 8 and MySQL 8. The web root is `public/`. Staff sign in at `login.php` and work in `admin.php`.

## Database

The database name is `collegeweb`. A local machine reads `app/config/database.local.php`. A hosted machine reads `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` instead, so the server does not use this computer’s MySQL password.

Semester ids are mnemonic names: `fall2026`, `spring2027`, `winter2027`. Room ids are a building code plus three digits, such as `NAB102`. The campus has three buildings: NAB (North Academic Building), LIB (Library), and SSC (Student Services Center).

### Time slot

A time slot is not a single day or a single clock time. It is five relations:

| Relation | Role |
| --- | --- |
| `time_slot` | One id, for example `MWF-0900-0950` |
| `day` | `day_id` and weekday (`M` Monday through `R` Thursday) |
| `period` | `period_id`, `start_time`, `end_time` |
| `time_slot_day` | Which weekdays belong to the slot |
| `time_slot_period` | Which period belongs to the slot |

Each section stores `time_slot_id`. Meeting days and the clock time stay on the section so the schedule screen can show them.

### History

`student_history` primary key is `(student_id, crn)`. The other columns are `course_id`, `semester_id`, and `grade`. Each is a foreign key, to `students`, `classes`, `courses`, `semesters`, and `grade_letters`.

`faculty_history` primary key is `(faculty_id, crn)`. It has `course_id` and `semester_id` and no grade. Those columns are foreign keys to `faculty`, `classes`, `courses`, and `semesters`.

`crn` is the class id in `classes`, and it matches `sections.section_id`.

Grades already stored in `student_course_results` are copied onto the student history row. This load does not type in new grades.

## Sign-in

`public/login.php` checks the email and password against `auth_users`. A correct pair opens `admin.php`. A wrong email or password stays on the sign-in page and says the login was not valid. An administrator resets a password from Accounts. The person then signs in with the new password.

## Load the midterm relations

On a database that already has the Ashford students and sections:

```bash
php scripts/apply_midterm_schema.php
```

That script creates the time-slot and history tables, renames semester codes, and rewrites room ids. Run it once.

## Hosting

More than one person can sign in when the site is on a shared address, not only `127.0.0.1` on one Mac. Set the `DB_*` variables to the hosted MySQL database, import `database/collegeweb.sql`, then run `php scripts/apply_midterm_schema.php` if that dump is older than these tables. Point the web root at `public/`. The steps are in `docs/DEPLOY.md`.
