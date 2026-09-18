CLIENT REVIEW PACK — Phase 1 sample (manual verify)
====================================================
Folder: storage/import/client_review/
Status: DRAFT — not imported to MySQL

TEAM: look over AND fix
-----------------------
This pack is for the whole team — not read-only.
- Review counts, names, majors/minors, IDs, etc.
- If something looks wrong, edit the text files on this branch
  (or open a PR) and note what you changed in 11_VERIFY_CHECKLIST.txt.
- Prefer small clear fixes over rewriting everything.
- Do not import to MySQL until the checklist is signed off.


HOW TO VERIFY (suggested order)
-------------------------------
1. Read this file.
2. Check each numbered file top-to-bottom (row by row).
3. Use 11_VERIFY_CHECKLIST.txt to tick counts + cross-links.
4. Mark approve / changes at the bottom of the checklist.
   If you fixed files, list your name + what you changed.

ID RULES IN THIS SAMPLE
-----------------------
Faculty IDs:  900001–900006
Student IDs:  100001–100014
Dept codes:   COM ECO BIO ENGL HIS PHY
Emails:       @ashford.edu (demo brand)


FILE INDEX
----------
00_README.txt                 ← you are here
01_departments.txt            6 depts (subset of future 12)
02_majors.txt                 majors offered by those depts
03_minors.txt                 minors students can declare
04_faculty.txt                6 faculty people
05_faculty_departments.txt    which dept each faculty belongs to
06_students.txt               14 students (names + type)
07_undergrad_detail.txt       year level + full/part-time (12 UG)
08_student_major_minor.txt    major / minor declarations
09_grad_detail.txt            Masters / PhD + load (2 grad)
10_courses_sample.txt         small catalog sample (no schedules)
11_VERIFY_CHECKLIST.txt       counts + sign-off

Also kept for overview:
SAMPLE_20_PEOPLE.txt                 one-page summary (older 6-digit demo)
SAMPLE_BY_CLASS_AND_FACULTY.txt      ← clearest: each class year + faculty


WHAT IS INTENTIONALLY MISSING (for later)
-----------------------------------------
- Full 12 departments / 12 majors
- Full student headcounts (1,200+ / 12,000 TBD)
- Sections / meeting times / master schedule
  (lecture: do NOT copy real master schedules)


CLIENT
------
Reviewer: __________________
Date: __________________
Approved to scale?  Yes / No / Changes needed
Notes: __________________
