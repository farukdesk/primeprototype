-- ============================================================
-- Seed: Prime University Language School Department (id = 2)
-- Run after departments.sql (which creates all dept_* tables).
-- ============================================================

-- -----------------------------------------------------
-- 1. Department record
-- -----------------------------------------------------
INSERT INTO `dept_departments`
  (`id`, `name`, `slug`, `code`, `faculty_label`,
   `hero_title`, `hero_subtitle`, `hero_description`,
   `hero_icon`, `cta_url`, `cta_text`,
   `cta_section_title`, `cta_section_text`, `is_active`)
VALUES
(2,
 'Prime University Language School',
 'language-school',
 'PLS',
 'Language School',
 'Prime University Language School',
 'Empowering Minds Through Language',
 'Prime University Language School is committed to disseminating language teaching on several dominating languages of the world including English, Japanese, Chinese and more, creating competent manpower to cope with the challenges of the 21st century. Since 2007, we have been providing the best outcomes for learners completing different language courses at various levels.',
 'fas fa-language',
 'apply-now.php',
 'Enroll Now',
 'Ready to Begin Your Language Learning Journey?',
 'Join Prime University Language School and gain internationally recognised certificates while opening doors to global career opportunities.',
 1);

-- -----------------------------------------------------
-- 2. Overview
-- -----------------------------------------------------
INSERT INTO `dept_overview`
  (`dept_id`, `vision`, `mission`, `head_name`, `head_designation`,
   `head_photo`, `head_edu_qualifications`, `head_message`)
VALUES
(2,
 'To accelerate students'' competency through teaching different foreign languages for enhancing their employability in this competitive world.',
 'To disseminate quality language education across multiple world languages — including English, Japanese, and Chinese — by providing internationally acceptable certificates, qualified language professionals, and research-driven teaching methods that develop each learner''s full potential.',
 NULL,
 'Director, Language School',
 NULL,
 NULL,
 'Welcome to Prime University Language School. Since our founding in 2007, we have been passionately dedicated to equipping our learners with the communication skills they need to thrive in a multilingual, globalised world.\n\nIn this era of international connectivity, proficiency in a foreign language is no longer a luxury — it is an essential asset. Our school offers structured courses in English, Japanese, Chinese, and other leading world languages at multiple proficiency levels, designed to serve both beginners and advanced learners.\n\nOur highly qualified language professionals conduct classes through research-based experiments and needs analysis, ensuring that every learner receives the targeted support required to reach their goals. Beyond language competence, we actively assist our graduates in pursuing globally recognised certification and in exploring opportunities for career placement.\n\nWe invite you to join our vibrant learning community and take the first confident step toward a brighter, borderless future.');

-- -----------------------------------------------------
-- 3. Faculty
-- -----------------------------------------------------
INSERT INTO `dept_faculty`
  (`dept_id`, `name`, `designation`, `specialization`, `email`, `is_head`, `sort_order`, `is_active`)
VALUES
(2, 'Director, Language School',   'Director',            'Language Education, Curriculum Design', 'language@primeuniversity.edu.bd', 1, 1, 1),
(2, 'Senior Lecturer – English',   'Senior Lecturer',     'English Language Teaching, IELTS, TOEFL', NULL, 0, 2, 1),
(2, 'Senior Lecturer – Japanese',  'Senior Lecturer',     'Japanese Language, JLPT Preparation', NULL, 0, 3, 1),
(2, 'Lecturer – Chinese (Mandarin)','Lecturer',           'Mandarin Chinese, HSK Preparation', NULL, 0, 4, 1),
(2, 'Lecturer – English Communication', 'Lecturer',       'Business English, Spoken Communication', NULL, 0, 5, 1);

-- -----------------------------------------------------
-- 4. Academic Programs (Language Courses)
-- -----------------------------------------------------
INSERT INTO `dept_academic_programs`
  (`dept_id`, `program_name`, `degree_type`, `duration`, `total_credit`, `description`, `sort_order`, `is_active`)
VALUES
(2,
 'English Language Course',
 'Certificate',
 '3–6 Months (Multiple Levels)',
 NULL,
 'A structured English language programme covering four core skills — reading, writing, listening, and speaking — across beginner, intermediate, and advanced levels. Prepares learners for internationally recognised examinations such as IELTS and TOEFL.',
 1, 1),
(2,
 'Japanese Language Course',
 'Certificate',
 '6–12 Months (Multiple Levels)',
 NULL,
 'Comprehensive Japanese language training from basic Hiragana and Katakana through to advanced Kanji and conversational fluency. Aligned with JLPT (Japanese Language Proficiency Test) N5–N2 levels. Suitable for students, job seekers, and professionals.',
 2, 1),
(2,
 'Chinese (Mandarin) Language Course',
 'Certificate',
 '6–12 Months (Multiple Levels)',
 NULL,
 'Structured Mandarin Chinese programme focusing on speaking, listening, reading, and writing skills. Aligned with HSK (Hanyu Shuiping Kaoshi) certification levels 1–4. Opens opportunities for study and employment in China and the wider Chinese-speaking world.',
 3, 1),
(2,
 'Business English & Communication',
 'Certificate',
 '3 Months',
 NULL,
 'Specialised programme designed for working professionals, focusing on business writing, presentation skills, workplace communication, and professional vocabulary for a corporate environment.',
 4, 1);

-- -----------------------------------------------------
-- 5. Notices
-- -----------------------------------------------------
INSERT INTO `dept_notices`
  (`dept_id`, `title`, `content`, `notice_date`, `is_active`)
VALUES
(2,
 'New Batch Enrollment – Summer 2026',
 'Prime University Language School is pleased to announce enrollment for the Summer 2026 batch of English, Japanese, and Chinese language courses. Classes commence on 1 July 2026. Interested students should collect registration forms from the Language School office or enroll online through the university portal.',
 '2026-06-01',
 1),
(2,
 'IELTS Preparation Workshop – June 2026',
 'A two-day intensive IELTS preparation workshop will be held on June 20–21, 2026, at the Language School seminar room. The workshop covers all four IELTS modules — Listening, Reading, Writing, and Speaking. Seats are limited; early registration is encouraged.',
 '2026-06-05',
 1),
(2,
 'JLPT N4 & N5 Registration Guidance',
 'Students enrolled in the Japanese Language Course who wish to appear in the upcoming JLPT examination are requested to contact the Language School office for registration guidance and preparation resources. Deadline for JLPT application assistance: June 30, 2026.',
 '2026-06-07',
 1),
(2,
 'Certificate Distribution – Spring 2026 Graduates',
 'Certificates for students who successfully completed the Spring 2026 language courses are ready for collection from the Language School office. Graduates are requested to bring their student ID and course enrollment receipt.',
 '2026-05-25',
 1);

-- -----------------------------------------------------
-- 6. Events
-- -----------------------------------------------------
INSERT INTO `dept_events`
  (`dept_id`, `title`, `event_date`, `location`, `description`, `link_url`, `is_active`)
VALUES
(2,
 'International Language Day Celebration',
 '2026-09-26',
 'Prime University Main Auditorium',
 'Annual celebration of the European Day of Languages featuring cultural performances, language quiz competitions, and guest speeches highlighting the importance of multilingualism in today''s world.',
 '#',
 1),
(2,
 'English Debate Competition 2026',
 '2026-07-15',
 'Language School Seminar Hall',
 'Inter-departmental English debate competition open to all Prime University students. A great opportunity to practise public speaking and critical thinking in English.',
 '#',
 1),
(2,
 'Japanese Cultural Night',
 '2026-08-10',
 'Prime University Cultural Centre',
 'An evening celebrating Japanese culture through traditional music, art, calligraphy demonstrations, and Japanese language skits performed by students of the Japanese Language Course.',
 '#',
 1);

-- -----------------------------------------------------
-- 7. Clubs / Societies
-- -----------------------------------------------------
INSERT INTO `dept_clubs`
  (`dept_id`, `name`, `description`, `president_name`, `sort_order`, `is_active`)
VALUES
(2,
 'English Language & Debate Club',
 'A student-led club dedicated to enhancing English communication skills through regular debates, elocution contests, creative writing workshops, and movie-discussion sessions.',
 NULL,
 1,
 1),
(2,
 'Japanese Language Society',
 'Promotes the study and appreciation of Japanese language and culture through language exchange sessions, anime discussion groups, calligraphy workshops, and JLPT study circles.',
 NULL,
 2,
 1),
(2,
 'Chinese Language Circle',
 'Supports students learning Mandarin Chinese by organising HSK preparation groups, Chinese cultural events, and language exchange partnerships with native speakers.',
 NULL,
 3,
 1);

-- -----------------------------------------------------
-- 8. Facilities
-- -----------------------------------------------------
INSERT INTO `dept_facilities`
  (`dept_id`, `title`, `description`, `icon`, `sort_order`, `is_active`)
VALUES
(2,
 'Language Learning Lab',
 'Modern, air-conditioned language laboratory equipped with individual audio-visual workstations, headsets, and specialised software for pronunciation practice and listening comprehension exercises.',
 'fas fa-headphones',
 1,
 1),
(2,
 'Digital Resource Library',
 'Dedicated language library stocked with course textbooks, graded readers, audio CDs, and online access to leading language-learning platforms and international journals.',
 'fas fa-book-open',
 2,
 1),
(2,
 'Seminar & Presentation Room',
 'A comfortable seminar room with projector and audio-visual facilities, used for workshops, guest lectures, certificate distribution ceremonies, and student presentations.',
 'fas fa-chalkboard-teacher',
 3,
 1),
(2,
 'Career & Certification Guidance Centre',
 'Provides students with guidance on internationally recognised language certifications (IELTS, TOEFL, JLPT, HSK) and job placement support for language-related career opportunities.',
 'fas fa-briefcase',
 4,
 1);

-- -----------------------------------------------------
-- 9. Prime Pride (Notable Achievements)
-- -----------------------------------------------------
INSERT INTO `dept_prime_pride`
  (`dept_id`, `title`, `description`, `student_name`, `position`, `batch_year`, `sort_order`, `is_active`)
VALUES
(2,
 'IELTS Band 8.5 Achievement',
 'Achieved an outstanding IELTS overall band score of 8.5 after completing the Advanced English Language Course at Prime University Language School, securing a full scholarship to a UK university.',
 'Farhan Islam',
 'Graduate Student, University of Manchester, UK',
 '2023',
 1,
 1),
(2,
 'JLPT N2 Certification',
 'Successfully passed the JLPT N2 examination after one year of Japanese language study at the Language School, and secured employment at a leading Japanese multinational company in Dhaka.',
 'Sumaiya Akter',
 'Japanese Language Specialist, Mitsubishi Corporation BD',
 '2024',
 2,
 1),
(2,
 'HSK Level 4 & Study Abroad in China',
 'Attained HSK Level 4 certification and was awarded a Chinese Government Scholarship for a Master''s programme at Beijing Normal University.',
 'Rifat Hossain',
 'Master''s Student, Beijing Normal University, China',
 '2024',
 3,
 1);
