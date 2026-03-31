-- Department Module SQL Schema and Seed Data
-- Generated for Prime University Prototype
-- Run this AFTER the main database.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------
-- 1. dept_departments: core department info
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `dept_departments` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name`                VARCHAR(200) NOT NULL,
  `slug`                VARCHAR(120) NOT NULL UNIQUE,
  `code`                VARCHAR(50)  NOT NULL,
  `faculty_label`       VARCHAR(200) NOT NULL DEFAULT 'Faculty of Science & Engineering',
  `hero_title`          VARCHAR(300) NOT NULL,
  `hero_subtitle`       TEXT,
  `hero_description`    TEXT,
  `hero_icon`           VARCHAR(100) NOT NULL DEFAULT 'fas fa-graduation-cap',
  `cta_url`             VARCHAR(300) NOT NULL DEFAULT 'apply-now.html',
  `cta_text`            VARCHAR(100) NOT NULL DEFAULT 'Apply Now',
  `cta_section_title`   VARCHAR(300) DEFAULT NULL,
  `cta_section_text`    TEXT         DEFAULT NULL,
  `is_active`           TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- 2. dept_overview: vision, mission, dept head per department (1 per dept)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `dept_overview` (
  `id`                      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `dept_id`                 INT UNSIGNED NOT NULL,
  `vision`                  TEXT,
  `mission`                 TEXT,
  `head_name`               VARCHAR(200) DEFAULT NULL,
  `head_designation`        VARCHAR(200) DEFAULT NULL,
  `head_photo`              VARCHAR(300) DEFAULT NULL,
  `head_edu_qualifications` TEXT         DEFAULT NULL,
  `head_message`            LONGTEXT     DEFAULT NULL,
  `created_at`              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_dept_overview` (`dept_id`),
  CONSTRAINT `fk_overview_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- 3. dept_faculty: faculty members
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `dept_faculty` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `dept_id`        INT UNSIGNED NOT NULL,
  `name`           VARCHAR(200) NOT NULL,
  `designation`    VARCHAR(200) DEFAULT NULL,
  `photo`          VARCHAR(300) DEFAULT NULL,
  `specialization` VARCHAR(300) DEFAULT NULL,
  `email`          VARCHAR(200) DEFAULT NULL,
  `is_head`        TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`     INT          NOT NULL DEFAULT 0,
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_faculty_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- 4. dept_events: upcoming events
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `dept_events` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `dept_id`     INT UNSIGNED NOT NULL,
  `title`       VARCHAR(300) NOT NULL,
  `event_date`  DATE         DEFAULT NULL,
  `location`    VARCHAR(300) DEFAULT NULL,
  `description` TEXT         DEFAULT NULL,
  `link_url`    VARCHAR(500) DEFAULT NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_events_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- 5. dept_alumni: notable alumni
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `dept_alumni` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `dept_id`    INT UNSIGNED NOT NULL,
  `name`       VARCHAR(200) NOT NULL,
  `position`   VARCHAR(200) DEFAULT NULL,
  `company`    VARCHAR(200) DEFAULT NULL,
  `photo`      VARCHAR(300) DEFAULT NULL,
  `sort_order` INT          NOT NULL DEFAULT 0,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_alumni_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- 6. dept_notices: notice board
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `dept_notices` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `dept_id`     INT UNSIGNED NOT NULL,
  `title`       VARCHAR(300) NOT NULL,
  `content`     TEXT         DEFAULT NULL,
  `attachment`  VARCHAR(300) DEFAULT NULL,
  `notice_date` DATE         DEFAULT NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_notices_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- 7. dept_routines: class/exam routines
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `dept_routines` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `dept_id`        INT UNSIGNED NOT NULL,
  `title`          VARCHAR(300) NOT NULL,
  `type`           ENUM('class','exam') NOT NULL DEFAULT 'class',
  `file_path`      VARCHAR(300) DEFAULT NULL,
  `semester`       VARCHAR(100) DEFAULT NULL,
  `section`        VARCHAR(100) DEFAULT NULL,
  `effective_from` DATE         DEFAULT NULL,
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_routines_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- 8. dept_clubs: student clubs
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `dept_clubs` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `dept_id`        INT UNSIGNED NOT NULL,
  `name`           VARCHAR(200) NOT NULL,
  `description`    TEXT         DEFAULT NULL,
  `logo`           VARCHAR(300) DEFAULT NULL,
  `president_name` VARCHAR(200) DEFAULT NULL,
  `email`          VARCHAR(200) DEFAULT NULL,
  `sort_order`     INT          NOT NULL DEFAULT 0,
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_clubs_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- 9. dept_facilities: department facilities
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `dept_facilities` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `dept_id`     INT UNSIGNED NOT NULL,
  `title`       VARCHAR(200) NOT NULL,
  `description` TEXT         DEFAULT NULL,
  `icon`        VARCHAR(100) NOT NULL DEFAULT 'fas fa-desktop',
  `image`       VARCHAR(300) DEFAULT NULL,
  `sort_order`  INT          NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_facilities_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- 10. dept_academic_programs: academic programs
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `dept_academic_programs` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `dept_id`      INT UNSIGNED NOT NULL,
  `program_name` VARCHAR(300) NOT NULL,
  `degree_type`  VARCHAR(100) DEFAULT NULL,
  `duration`     VARCHAR(100) DEFAULT NULL,
  `total_credit` VARCHAR(100) DEFAULT NULL,
  `description`  TEXT         DEFAULT NULL,
  `sort_order`   INT          NOT NULL DEFAULT 0,
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_academic_programs_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- 11. dept_prime_pride: prime pride achievements
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `dept_prime_pride` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `dept_id`      INT UNSIGNED NOT NULL,
  `title`        VARCHAR(300) NOT NULL,
  `description`  TEXT         DEFAULT NULL,
  `image`        VARCHAR(300) DEFAULT NULL,
  `student_name` VARCHAR(200) DEFAULT NULL,
  `position`     VARCHAR(200) DEFAULT NULL,
  `batch_year`   VARCHAR(20)  DEFAULT NULL,
  `sort_order`   INT          NOT NULL DEFAULT 0,
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_prime_pride_dept` FOREIGN KEY (`dept_id`) REFERENCES `dept_departments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- SEED DATA
-- =====================================================

-- -----------------------------------------------------
-- Seed: BSc CSE Department
-- -----------------------------------------------------
INSERT INTO `dept_departments` (`id`, `name`, `slug`, `code`, `faculty_label`, `hero_title`, `hero_subtitle`, `hero_description`, `hero_icon`, `cta_url`, `cta_text`, `cta_section_title`, `cta_section_text`, `is_active`) VALUES
(1, 'BSc in Computer Science & Engineering', 'bsc-cse', 'CSE', 'Faculty of Science & Engineering',
 'BSc in Computer Science & Engineering', NULL,
 'Embark on a transformative journey in technology and innovation. Our comprehensive CSE program equips students with cutting-edge skills in software development, artificial intelligence, cybersecurity, and emerging technologies to become the leaders of tomorrow''s digital world.',
 'fas fa-laptop-code', 'apply-now.html', 'Apply Now',
 'Ready to Start Your Journey in Computer Science?',
 'Join Prime University''s BSc in CSE program and transform your passion for technology into a successful career.',
 1);

-- -----------------------------------------------------
-- Seed: Overview for BSc CSE
-- -----------------------------------------------------
INSERT INTO `dept_overview` (`dept_id`, `vision`, `mission`, `head_name`, `head_designation`, `head_photo`, `head_edu_qualifications`, `head_message`) VALUES
(1,
 'The department of computer science and engineering is to establish for facilitating high quality education and research also striving to inspire excellence in the creation, application and dissemination of knowledge in the field of computer science and engineering.',
 'To provide quality education in computer science and engineering through innovative curriculum, hands-on learning experiences, cutting-edge research opportunities, and strong industry partnerships. We are committed to developing ethical, skilled, and creative graduates who can contribute meaningfully to society, drive technological innovation, and adapt to the ever-evolving demands of the digital world.',
 'Colonel Md Shihabul Islam (Retd, Ex EME)', 'Associate Professor & Head of Department', NULL,
 '• Ph.D in Computer Science and Engineering (ongoing), Bangladesh University of Engineering and Technology (BUET)\n• M.Sc in Computer Science and Engineering, Bangladesh University of Engineering and Technology (BUET)\n• B.Sc in Electrical and Electronic Engineering, Bangladesh University of Engineering and Technology (BUET)',
 'Welcome to the department of Computer Science and Engineering (CSE) at Prime University. The department of CSE is one of the leading departments of its kind in Bangladesh.\n\nThe education of CSE Prime University is world-class in both the curriculum and research activity also we are dedicated to impart quality education to the students. We employ qualified learned faculty members and introduce modern computing laboratories, which will provide them with the best opportunities to gain adequate knowledge on computer science related subjects and prepare them for undertaking the new challenges. We offer state-of-the-art research facilities with supervision from a good number full-time faculty member.\n\nRecently, the department has been making remarkable progress in the field of programming, robotics, artificial intelligent, machine learning etc. This portrays a noteworthy standing of the department within the country as well as all over the globe.\n\nDue to the congenial atmosphere in the department of CSE, the faculty members and students maintain a very friendly relation thereby students are being benefited. Students are engaged in various academic, co-curricular and extra-curricular activities and enjoy their stay in the university.');

-- -----------------------------------------------------
-- Seed: Faculty members for BSc CSE
-- -----------------------------------------------------
INSERT INTO `dept_faculty` (`dept_id`, `name`, `designation`, `specialization`, `email`, `is_head`, `sort_order`, `is_active`) VALUES
(1, 'Colonel Md Shihabul Islam (Retd, Ex EME)', 'Associate Professor & Head of Department', 'Computer Science, AI', 'head.cse@primeuniversity.edu.bd', 1, 1, 1),
(1, 'Dr Momtaz Begum Momo', 'Professor', 'Machine Learning, Data Science', NULL, 0, 2, 1),
(1, 'Md Abdur Rahim', 'Associate Professor', 'Software Engineering', NULL, 0, 3, 1),
(1, 'Md Tareq Hasan', 'Assistant Professor', 'Networking, Cybersecurity', NULL, 0, 4, 1),
(1, 'Md. Mokhlesur Rahman', 'Assistant Professor', 'Database Systems', NULL, 0, 5, 1),
(1, 'Sanchita Rani Das', 'Lecturer', 'Web Development', NULL, 0, 6, 1),
(1, 'Omlan Jyoti Mondal', 'Lecturer', 'Algorithms, Programming', NULL, 0, 7, 1);

-- -----------------------------------------------------
-- Seed: Events for BSc CSE
-- -----------------------------------------------------
INSERT INTO `dept_events` (`dept_id`, `title`, `event_date`, `location`, `description`, `link_url`, `is_active`) VALUES
(1, 'Tech Fest 2026', '2026-03-15', 'Main Campus Auditorium', 'Annual technology festival featuring project showcases, coding competitions, and tech talks by industry leaders.', '#', 1),
(1, 'AI Workshop', '2026-03-22', 'CS Lab 301', 'Hands-on workshop on Machine Learning and AI fundamentals with practical implementation using Python and TensorFlow.', '#', 1),
(1, 'Industry Talk', '2026-04-05', 'Seminar Hall', 'Guest lecture by tech industry professionals sharing insights on career paths, emerging technologies, and market trends.', '#', 1);

-- -----------------------------------------------------
-- Seed: Alumni for BSc CSE
-- -----------------------------------------------------
INSERT INTO `dept_alumni` (`dept_id`, `name`, `position`, `company`, `sort_order`, `is_active`) VALUES
(1, 'Rafiq Ahmed', 'Software Engineer', 'Google, USA', 1, 1),
(1, 'Nusrat Jahan', 'ML Engineer', 'Microsoft, Canada', 2, 1),
(1, 'Tahmid Hassan', 'Tech Entrepreneur', 'Founder, TechVenture BD', 3, 1),
(1, 'Sabrina Khan', 'Cloud Architect', 'Amazon Web Services', 4, 1);

-- -----------------------------------------------------
-- Seed: Notices for BSc CSE
-- -----------------------------------------------------
INSERT INTO `dept_notices` (`dept_id`, `title`, `content`, `notice_date`, `is_active`) VALUES
(1, 'Spring 2026 Admission Deadline', 'Last date to submit Spring 2026 semester admission forms is January 15, 2026. Students must submit all required documents to the departmental office. Late submissions will not be accepted under any circumstances.', '2026-01-10', 1),
(1, 'Mid-term Exam Schedule', 'The mid-term examinations for Spring 2026 will commence from 20 January 2026. Students must collect their admit cards from the departmental office at least three days prior to the exam. All courses must be attended with a minimum 75% attendance.', '2026-01-12', 1),
(1, 'AI & Machine Learning Workshop', 'The department is organizing a two-day workshop on Artificial Intelligence and Machine Learning on February 5-6, 2026. Registration is open to all CSE students. Limited seats available.', '2026-01-15', 1),
(1, 'Final Exam Form Fill-up', 'Students wishing to appear in the Final Examination of Spring 2026 must fill up their examination forms by March 1, 2026. Forms are available at the departmental office.', '2026-02-20', 1),
(1, 'Industrial Visit', 'The department is organizing an industrial visit to Brain Station 23 and BJIT Limited on February 28, 2026. Interested students must register by February 20, 2026.', '2026-02-10', 1);

-- -----------------------------------------------------
-- Seed: Clubs for BSc CSE
-- -----------------------------------------------------
INSERT INTO `dept_clubs` (`dept_id`, `name`, `description`, `president_name`, `sort_order`, `is_active`) VALUES
(1, 'CSE Programming Club', 'A platform for CSE students to enhance their programming skills through regular contests, workshops, and coding challenges. Affiliated with competitive programming communities worldwide.', NULL, 1, 1),
(1, 'Robotics & AI Society', 'Dedicated to exploring the frontiers of robotics and artificial intelligence. Members participate in national and international robotics competitions and AI hackathons.', NULL, 2, 1),
(1, 'Web Development Society', 'Focused on modern web technologies, frameworks, and design patterns. Organizes workshops, webinars, and project-based learning sessions.', NULL, 3, 1),
(1, 'Cybersecurity Club', 'Promotes awareness and hands-on learning in cybersecurity, ethical hacking, and digital forensics through CTF (Capture The Flag) competitions and workshops.', NULL, 4, 1);

-- -----------------------------------------------------
-- Seed: Facilities for BSc CSE
-- -----------------------------------------------------
INSERT INTO `dept_facilities` (`dept_id`, `title`, `description`, `icon`, `sort_order`, `is_active`) VALUES
(1, 'Modern Computer Labs', 'State-of-the-art computer laboratories equipped with high-performance workstations, latest software tools, and high-speed internet connectivity for practical learning.', 'fas fa-desktop', 1, 1),
(1, 'Research & Innovation Center', 'A dedicated space for advanced research projects in AI, machine learning, IoT, and cybersecurity with specialized hardware and software resources.', 'fas fa-flask', 2, 1),
(1, 'Library & E-Resources', 'Comprehensive library with thousands of technical books, journals, and access to digital resources including IEEE Xplore, ACM Digital Library, and Springer.', 'fas fa-book', 3, 1),
(1, 'Seminar & Conference Hall', 'Modern seminar hall with projector, audio-visual equipment, and video conferencing facilities for lectures, guest talks, and academic events.', 'fas fa-chalkboard-teacher', 4, 1),
(1, 'Networking Lab', 'Specialized networking laboratory with Cisco routers, switches, and network simulation tools for hands-on networking and cybersecurity practicals.', 'fas fa-network-wired', 5, 1),
(1, 'Software Development Studio', 'Collaborative workspace designed for software development projects, team-based learning, and startup incubation activities.', 'fas fa-code', 6, 1);

-- -----------------------------------------------------
-- Seed: Academic Programs for BSc CSE
-- -----------------------------------------------------
INSERT INTO `dept_academic_programs` (`dept_id`, `program_name`, `degree_type`, `duration`, `total_credit`, `description`, `sort_order`, `is_active`) VALUES
(1, 'BSc in Computer Science & Engineering', 'Bachelor of Science', '4 Years (8 Semesters)', '160 Credits', 'A comprehensive undergraduate program covering core computer science fundamentals, software engineering, data structures, algorithms, AI, machine learning, networking, and cybersecurity. The program combines theoretical knowledge with intensive practical training.', 1, 1);

-- -----------------------------------------------------
-- Seed: Prime Pride for BSc CSE
-- -----------------------------------------------------
INSERT INTO `dept_prime_pride` (`dept_id`, `title`, `description`, `student_name`, `position`, `batch_year`, `sort_order`, `is_active`) VALUES
(1, 'National Programming Contest Winner', 'Won first place in the National Collegiate Programming Contest (NCPC) 2025, competing against 200+ teams from universities across Bangladesh.', 'Arif Hossain', 'Software Engineer at BJIT Ltd', '2024', 1, 1),
(1, 'International Robotics Champion', 'Represented Bangladesh in the International Robotics Olympiad 2025 and secured the 3rd position in the autonomous robot category.', 'Tasnim Rahman', 'Robotics Engineer at BUET', '2023', 2, 1),
(1, 'Startup Success Story', 'Co-founded TechBridge BD, a successful EdTech startup that raised seed funding of BDT 50 lacs from local investors within 6 months of launch.', 'Mehedi Hasan', 'CEO, TechBridge BD', '2022', 3, 1);
