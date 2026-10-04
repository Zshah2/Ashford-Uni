# Ashford College user manual

Ashford College’s site is a student-information system. Students, faculty, courses, sections, and registration live in MySQL. Staff open the admin site, sign in with a school email and password, and then use People, Master schedule, Registration, and Holds.

Semesters are named `fall2026`, `winter2027`, and `spring2027`. Classrooms are named like `NAB102`: the building code plus a three-digit room number. A class meets in a time slot, which is a set of weekdays plus a start and end time.

## Successful login

1. Open `login.php`.
2. Enter a school email that has an account, such as `zshah2@oldwestbury.edu`.
3. Enter the password.
4. Choose Sign in.

The admin dashboard opens. The sidebar lists People, Master schedule, Courses, Enrollment, Registration, and Holds.

## Unsuccessful login

Use a real school email that is not an account, or a real account with the wrong password.

1. Open `login.php`.
2. Enter the email and a wrong password.
3. Choose Sign in.

The page stays on sign-in and reports that the email or password is not valid. No dashboard opens.

## Password reset

An administrator resets another person’s password. The person does not stay signed in with the old password.

1. Sign in as the main administrator.
2. Open Accounts.
3. Choose the account and set a new password of at least 8 characters.
4. Sign out.
5. Sign in as that person with the new password.

Successful sign-in confirms the reset.

## Admin use cases to show

- **People.** Search a 7-digit student or faculty id. A real id opens the record. A made-up id says to enter a valid student or faculty id.
- **Master schedule.** The same id search finds that person. The section list shows the time slot’s days and time, and a room such as `NAB102`.
- **Registration.** Load a student for `fall2026`. Adding a class the student is not prepared for is blocked. A full section waitlists. An active hold blocks registration until the hold is cleared.
- **Holds.** Add a Registration hold on a student, try to register, then clear the hold and register again.

## What the history tables mean

Student history is one row per student per class (CRN), with the course, the semester, and the grade when one is already on file. Faculty history is one row per faculty member per class, with the course and the semester, and no grade.
