# update_changes_01 Checklist

Use this checklist while comparing `update_changes_01` with `main`. Database credentials must stay in temporary environment variables or a gitignored local file; never commit them.

## Before Testing

- [x] Confirm the active branch is `update_changes_01`
- [ ] Confirm `main` remains unchanged
- [ ] Confirm no `.env`, `.env.local`, or `app/config/database.local.php` is tracked
- [ ] Start MySQL with the local `collegeweb` database
- [ ] Start the app with temporary `MYSQL_*` or `DB_*` variables
- [ ] Run `php scripts/check_php_syntax.sh`
- [ ] Run `php scripts/smoke_check.php http://127.0.0.1:8000`

## Roster Identity Rules

- [ ] Every row represents exactly one person
- [ ] ID has its own column or clearly separate label
- [ ] First name and last name contain names only
- [ ] No name is displayed as `Name #123`
- [ ] Role is displayed separately as Student or Faculty
- [ ] Email is displayed separately and links with `mailto:`
- [ ] Duplicate names remain distinguishable by ID and email
- [ ] Missing names, email, phone, and department values show a clear empty value
- [ ] Every ID link opens the correct student or faculty record

## Schedule Filters

- [ ] No filter submission defaults to Students and Faculty
- [ ] Students only shows student rows
- [ ] Faculty only shows faculty rows
- [ ] Students and Faculty shows the unified roster
- [ ] Neither role shows an intentional empty state
- [ ] Search matches ID
- [ ] Search matches first name and last name
- [ ] Search matches email
- [ ] Search matches phone
- [ ] Applying filters resets pagination to page 1
- [ ] Pagination preserves the selected roles and search query
- [ ] Reset clears role filters, search, and pagination
- [ ] Refresh preserves the submitted filter state

## Related Pages

- [ ] Department detail shows chair name, ID, and email as separate values
- [ ] Department faculty list uses separate name and ID values
- [ ] Department student list uses separate name and ID values
- [ ] Student detail shows the correct person and related records
- [ ] Faculty links do not open a student record
- [ ] Student links do not open a faculty record
- [ ] Course and section faculty selectors identify people without merging ID into names
- [ ] Holds pages preserve the correct student ID and name relationship

## Administrator Management Paths

The main administrator controller accepts these write actions. Test each path from the UI and confirm the permission boundary is enforced server-side.

| Area | Admin action | Expected result |
| --- | --- | --- |
| People | Update student information, majors/minors, credit limits, and holds | Valid changes save; invalid IDs and invalid values are rejected |
| People | Update faculty information | Valid changes save; nonexistent faculty IDs cannot be updated |
| Academic records | Add or update a student course result and grade | Valid course, term, grade, and student are required |
| Departments | Add a department | Department ID and name are required; duplicate IDs are rejected |
| Departments | Assign or clear a department chair | Only an existing faculty member can be assigned |
| Catalog | Add or update a course | Course ID, name, credits, and department relationship are validated |
| Catalog | Add or remove prerequisites | Self-prerequisites are rejected; invalid prerequisite IDs do not create broken links |
| Sections | Add a course section | Existing term/course required; meeting data must be complete and valid |
| Sections | Update a course section | Existing section and matching term required |
| Sections | Assign faculty and room | Existing faculty only; overlapping instructor and room schedules are blocked |
| Terms | Open or close registration and set dates | Registration state and date range are saved consistently |
| Accounts | Change admin email, password, active state, or login details | Admin-only actions require admin permission and valid account data |
| Registration | Add, drop, or promote enrollment | Holds, term, duplicates, capacity, conflicts, credits, and prerequisites are enforced |

## Professor-Proof Adversarial Tests

- [ ] View-Only account can view every allowed page but cannot submit any write action
- [ ] Limited Admin can perform only the write actions allowed by its role
- [ ] A direct POST with a blocked action is rejected, even if the button is bypassed
- [ ] Missing or invalid CSRF token returns a controlled `403`
- [ ] Missing required fields show a controlled validation result, not a PHP error
- [ ] Duplicate department ID is rejected without overwriting the existing department
- [ ] Duplicate course ID updates only when the intended edit flow is used
- [ ] Invalid course credits, course IDs, and department IDs are handled predictably
- [ ] A section cannot reference a nonexistent course or term
- [ ] A section cannot use only meeting days or only meeting time
- [ ] Invalid meeting days and reversed or malformed times are rejected
- [ ] Same instructor cannot be assigned to overlapping sections in one term
- [ ] Same room cannot be assigned to overlapping sections in one term
- [ ] Updating a section does not conflict with itself
- [ ] A full section creates a waitlisted enrollment instead of exceeding capacity
- [ ] The same student cannot enroll in the same section twice
- [ ] The same student cannot enroll in two sections of the same course in one term
- [ ] An active hold blocks enrollment
- [ ] Registration outside the add/drop window is blocked unless the permitted override is used
- [ ] Credit limits block over-enrollment unless the permitted override is used
- [ ] Missing prerequisites block enrollment unless the permitted override is used
- [ ] Undergraduate students cannot enroll in graduate-only courses
- [ ] Graduate students cannot enroll in undergraduate-only courses
- [ ] A student or faculty member cannot be assigned to overlapping classes
- [ ] Dropping a seat promotes the oldest eligible waitlisted student
- [ ] Invalid student, section, term, and enrollment IDs do not modify another record
- [ ] Refreshing a successful POST does not repeat the write
- [ ] Two browser tabs cannot silently bypass a rule through stale form data

## Course Addition Walkthrough

- [ ] Open `admin.php?view=catalog` and create a new catalog course
- [ ] Confirm the course appears in the catalog with its department and credit value
- [ ] Edit the same course and confirm the intended fields change
- [ ] Add a prerequisite and confirm it appears on the course record
- [ ] Open `admin.php?view=courses` and create a section for an existing term
- [ ] Assign an existing faculty member, meeting days, meeting time, room, and capacity
- [ ] Confirm the section appears in the master schedule
- [ ] Attempt a duplicate or conflicting section and confirm the correct error appears
- [ ] Register a student for the new section and confirm the roster/enrollment updates
- [ ] Verify the new course, section, and enrollment are visible from related pages

## Course Lookup Requirements

The course lookup should let a professor find a course section using a combination of structured filters and search text. A lookup may use one filter or several filters together.

- [ ] Select a semester/year, such as `FA25` or `SP26`
- [ ] Select a department
- [x] Enter a CRN using the existing `section_id` value
- [ ] Enter a course ID, such as `CS101`
- [ ] Enter a course title or keyword
- [ ] Select or search by professor/instructor
- [ ] Enter a section ID when multiple sections exist
- [ ] Search by room, meeting days, or meeting time
- [ ] Combine year, department, professor, and text search without losing filters
- [ ] Show the selected filters after submission and refresh
- [ ] Reset all lookup filters to the default term and empty search
- [ ] Show a clear empty state when no sections match
- [ ] Preserve pagination when moving between result pages
- [ ] Open the correct course or section record from each result
- [ ] Keep course ID, section ID/CRN, professor, term, and room in separate fields

### Current Implementation Check

- [x] Course lookup currently supports term selection
- [x] Course lookup currently supports department selection
- [x] Free-text lookup currently searches course ID, title, instructor name, section ID, room, meeting days, and meeting time
- [x] Use the existing `section_id` as the CRN without adding a new schema column
- [x] Use the existing term selector for year/semester lookup
- [x] Add a dedicated professor selector alongside free-text instructor search

## Browser Flow

- [ ] `/` loads without warnings
- [ ] `/health` returns HTTP 200 while MySQL is available
- [ ] `/login` loads correctly
- [ ] Invalid login shows a controlled error
- [ ] Valid login reaches the admin dashboard
- [ ] `/admin/schedule` loads the roster
- [ ] Mobile layout can scroll wide tables horizontally
- [ ] Hide/show filters works after refresh
- [ ] Light and dark themes keep table text readable
- [ ] Logout returns to the login page

## Security and Data Safety

- [ ] Database password is not hardcoded in source files
- [ ] Database password is not committed to git
- [ ] Database password is not pasted into screenshots or logs
- [ ] Login and admin forms still enforce CSRF protection
- [ ] Unauthenticated admin requests redirect to login
- [ ] SQL queries use prepared parameters for user search values
- [ ] HTML output escapes names, emails, addresses, and department values
- [ ] `APP_DEBUG=0` does not expose stack traces

## Final Review

- [ ] Run `git diff main...update_changes_01 --stat`
- [ ] Review `git diff main...update_changes_01` for unrelated changes
- [ ] Run `php scripts/check_php_syntax.sh` again after final edits
- [ ] Run `php scripts/smoke_check.php http://127.0.0.1:8000` again
- [ ] Record any database-dependent checks that could not be completed
- [ ] Keep all approved work on `update_changes_01` until comparison is complete

## Current Status

- PHP syntax validation passes for 83 files.
- Local MySQL credentials were verified temporarily against `collegeweb`.
- The live app smoke test passed for `/`, `/health`, and `/login`.
- Browser-level roster filter testing still needs to be completed manually.

## Requirements Glossary

- **Administrator:** A system user with elevated privileges who manages the university registration system. Administrators have security levels: View-Only or Update-Enabled.
- **Academic Catalog:** A comprehensive listing of courses, programs, departments, and academic requirements offered by the university.
- **Add/Drop Window:** A specified period, typically four weeks, during which students can add or drop courses.
- **Advisor:** A faculty member assigned to guide students. Advisors must share a department with the student's major.
- **Advisee:** A student assigned to a faculty advisor for academic guidance.
- **CRN (Course Reference Number):** A unique identifier assigned to a course section for a specific semester.
- **Course:** An academic class identified by a course code, such as `CS101`, and a specific number of credits.
- **Course Prerequisite:** A course that must be completed with a passing grade before enrollment in a more advanced course.
- **Course Section:** A specific instance of a course offered in a semester, with assigned faculty, time slot, room, and seat capacity.
- **Credit Limit:** The maximum credits a student may enroll in per semester. Full-time students: 16 credits. Part-time students: 11 credits.
- **Degree Audit:** A report showing a student's progress toward completing degree requirements.
- **Department:** An academic unit offering courses and programs in a specific discipline, such as CIS - Computer Information Systems.
- **Enrollment:** The process of registering a student for a course section, or the resulting registration record.
- **Faculty:** Teaching staff who instruct courses. Part-time faculty are limited to teaching two sections per semester.
- **Full-Time Student:** A student enrolled in 12 or more credits per semester, with a maximum of 16 credits.
- **Grade:** A letter evaluation, such as `A`, `B`, `C`, `D`, or `F`, assigned for course performance.
- **Graduate Student:** A student pursuing a master's degree or higher. Graduate students may enroll only in graduate-level courses numbered `500+`.
- **Hold:** A restriction on a student's account that prevents course registration until resolved.
- **Major:** A student's primary field of study. Students must have at least one major and may have up to two majors.
- **Master Schedule:** A comprehensive listing of course sections offered in a semester, including faculty, time slots, and rooms.
- **Minor:** A secondary field of study. Students may have at most one minor and must have a major before declaring it.
- **Part-Time Student:** A student enrolled in fewer than 12 credits per semester, with a maximum of 11 credits.
- **Roster:** A list of all students enrolled in a specific course section.
- **Section ID:** A number identifying a specific course section, such as `-01` or `-02`, when multiple sections exist.
- **Semester:** An academic term during which courses are offered. The requirements currently identify Fall 2025 (`FA25`) and Spring 2026 (`SP26`).
- **SRS (Software Requirements Specification):** A document describing the system's functional and non-functional requirements.
- **Student:** A university user enrolled to take courses. Students may be undergraduate or graduate, full-time or part-time.
- **Time Conflict:** A conflict caused when a student or faculty member is assigned to two courses during overlapping time slots.
- **Time Slot:** A specific day and period combination when a class meets, such as `TS13`.
- **Transcript:** An official record of courses taken and grades received.
- **UC (Use Case):** A description of an interaction between a user and the system to achieve a goal.
- **Undergraduate Student:** A student pursuing a bachelor's degree. Undergraduates may enroll only in undergraduate-level courses numbered `100-400`.
- **Update-Enabled:** An administrator security level allowing full create, update, and delete operations.
- **View-Only:** An administrator security level allowing data viewing without modification.