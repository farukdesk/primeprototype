-- ============================================================
-- Admissions – Student ID Seed Data for 16 Programs
-- Run this file after admissions-student-id.sql
-- Inserts default student ID settings for all 16 programs.
-- Existing rows (by program_id) will not be overwritten.
-- ============================================================

-- 💻 Engineering

-- 1. CSE
INSERT IGNORE INTO `adm_student_id_settings`
    (program_id, university_code, year_code, semester_code, faculty_code, subject_code, type_of_program, next_serial, serial_digits)
SELECT id, '028', '26', '2', '05', '10', '1', 1, 3
FROM `dept_academic_programs` WHERE program_name = 'CSE' LIMIT 1;

-- 2. EEE
INSERT IGNORE INTO `adm_student_id_settings`
    (program_id, university_code, year_code, semester_code, faculty_code, subject_code, type_of_program, next_serial, serial_digits)
SELECT id, '028', '26', '2', '05', '13', '1', 1, 3
FROM `dept_academic_programs` WHERE program_name = 'EEE' LIMIT 1;

-- 3. Civil
INSERT IGNORE INTO `adm_student_id_settings`
    (program_id, university_code, year_code, semester_code, faculty_code, subject_code, type_of_program, next_serial, serial_digits)
SELECT id, '028', '26', '2', '05', '08', '1', 1, 3
FROM `dept_academic_programs` WHERE program_name = 'Civil' LIMIT 1;

-- 💼 Business

-- 4. BBA
INSERT IGNORE INTO `adm_student_id_settings`
    (program_id, university_code, year_code, semester_code, faculty_code, subject_code, type_of_program, next_serial, serial_digits)
SELECT id, '028', '26', '2', '04', '08', '1', 1, 3
FROM `dept_academic_programs` WHERE program_name = 'BBA' LIMIT 1;

-- 5. MBA (1 Year)
INSERT IGNORE INTO `adm_student_id_settings`
    (program_id, university_code, year_code, semester_code, faculty_code, subject_code, type_of_program, next_serial, serial_digits)
SELECT id, '028', '26', '2', '04', '08', '3', 1, 3
FROM `dept_academic_programs` WHERE program_name = 'MBA (1 Year)' LIMIT 1;

-- 6. MBA (2 Years)
INSERT IGNORE INTO `adm_student_id_settings`
    (program_id, university_code, year_code, semester_code, faculty_code, subject_code, type_of_program, next_serial, serial_digits)
SELECT id, '028', '26', '2', '04', '08', '7', 1, 3
FROM `dept_academic_programs` WHERE program_name = 'MBA (2 Years)' LIMIT 1;

-- 📚 English

-- 7. English (Hons)
INSERT IGNORE INTO `adm_student_id_settings`
    (program_id, university_code, year_code, semester_code, faculty_code, subject_code, type_of_program, next_serial, serial_digits)
SELECT id, '028', '26', '2', '02', '16', '1', 1, 3
FROM `dept_academic_programs` WHERE program_name = 'English (Hons)' LIMIT 1;

-- 8. MA in English (1 Year)
INSERT IGNORE INTO `adm_student_id_settings`
    (program_id, university_code, year_code, semester_code, faculty_code, subject_code, type_of_program, next_serial, serial_digits)
SELECT id, '028', '26', '2', '02', '16', '3', 1, 3
FROM `dept_academic_programs` WHERE program_name = 'MA in English (1 Year)' LIMIT 1;

-- 9. MA in English (2 Years)
INSERT IGNORE INTO `adm_student_id_settings`
    (program_id, university_code, year_code, semester_code, faculty_code, subject_code, type_of_program, next_serial, serial_digits)
SELECT id, '028', '26', '2', '02', '16', '7', 1, 3
FROM `dept_academic_programs` WHERE program_name = 'MA in English (2 Years)' LIMIT 1;

-- 📝 Bangla

-- 10. Bangla (4 Years)
INSERT IGNORE INTO `adm_student_id_settings`
    (program_id, university_code, year_code, semester_code, faculty_code, subject_code, type_of_program, next_serial, serial_digits)
SELECT id, '028', '26', '2', '02', '08', '1', 1, 3
FROM `dept_academic_programs` WHERE program_name = 'Bangla (4 Years)' LIMIT 1;

-- 11. MA in Bangla (1 Year)
INSERT IGNORE INTO `adm_student_id_settings`
    (program_id, university_code, year_code, semester_code, faculty_code, subject_code, type_of_program, next_serial, serial_digits)
SELECT id, '028', '26', '2', '02', '08', '3', 1, 3
FROM `dept_academic_programs` WHERE program_name = 'MA in Bangla (1 Year)' LIMIT 1;

-- 12. MA in Bangla (2 Years)
INSERT IGNORE INTO `adm_student_id_settings`
    (program_id, university_code, year_code, semester_code, faculty_code, subject_code, type_of_program, next_serial, serial_digits)
SELECT id, '028', '26', '2', '02', '08', '7', 1, 3
FROM `dept_academic_programs` WHERE program_name = 'MA in Bangla (2 Years)' LIMIT 1;

-- ⚖️ Law

-- 13. LL.B (4 Years)
INSERT IGNORE INTO `adm_student_id_settings`
    (program_id, university_code, year_code, semester_code, faculty_code, subject_code, type_of_program, next_serial, serial_digits)
SELECT id, '028', '26', '2', '08', '13', '1', 1, 3
FROM `dept_academic_programs` WHERE program_name = 'LL.B (4 Years)' LIMIT 1;

-- 14. LL.M (1 Year)
INSERT IGNORE INTO `adm_student_id_settings`
    (program_id, university_code, year_code, semester_code, faculty_code, subject_code, type_of_program, next_serial, serial_digits)
SELECT id, '028', '26', '2', '08', '13', '3', 1, 3
FROM `dept_academic_programs` WHERE program_name = 'LL.M (1 Year)' LIMIT 1;

-- 15. LL.M (2 Years)
INSERT IGNORE INTO `adm_student_id_settings`
    (program_id, university_code, year_code, semester_code, faculty_code, subject_code, type_of_program, next_serial, serial_digits)
SELECT id, '028', '26', '2', '08', '13', '7', 1, 3
FROM `dept_academic_programs` WHERE program_name = 'LL.M (2 Years)' LIMIT 1;

-- 📌 Other

-- 16. Fashion Design
INSERT IGNORE INTO `adm_student_id_settings`
    (program_id, university_code, year_code, semester_code, faculty_code, subject_code, type_of_program, next_serial, serial_digits)
SELECT id, '028', '26', '2', '05', '40', '1', 1, 3
FROM `dept_academic_programs` WHERE program_name = 'Fashion Design' LIMIT 1;
