-- Policy & Procedure sections
CREATE TABLE IF NOT EXISTS `policy_procedure_sections` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title`      VARCHAR(255) NOT NULL,
  `content`    LONGTEXT NOT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed initial sections from the university content
INSERT IGNORE INTO `policy_procedure_sections` (`id`, `title`, `content`, `sort_order`, `is_active`) VALUES

(1, 'Semester',
'<table class="table table-bordered"><thead><tr><th>Semester</th><th>Duration</th></tr></thead><tbody><tr><td>Spring</td><td>February to May</td></tr><tr><td>Summer</td><td>June to September</td></tr><tr><td>Fall</td><td>October to January</td></tr></tbody></table>',
10, 1),

(2, 'Medium of Instruction',
'<p>The medium of instruction in the University is English, but the students of BEd may study in Bangla version also.</p>',
20, 1),

(3, 'Semester Withdrawal',
'<p>Students may apply for withdrawal from semester(s) after completion of at least one semester provided that they are in good standing (having CGPA 2.50 or above). Withdrawal will be allowed only once. A Student who is granted withdrawal must complete the program within specified academic years from the date of initial registration and will start taking courses that will be missed during withdrawal of semester and will be offered for regular students.</p>',
30, 1),

(4, 'Course Withdrawal',
'<p>A student can take withdrawal a maximum of two courses in a semester subject to the approval of the Head of the Department. The student needs to apply for the withdrawal of course(s) prior to the mid-term examination of that running semester.</p>',
40, 1),

(5, 'Readmission',
'<p>Prime University is an institution of higher learning and research dedicated to providing quality higher education commensurate with investment. The Courses and Curriculum are so designed as to enable a student to enter into the world of work and pursue higher academic and professional goals with a sound academic foundation. The University supports its students through its commitment to excellence and demonstrates it through the quality academic service. The University offers academically rigorous and practical instruction in different disciplines to cater to the growing demand for human resources development in compliance with the digital Bangladesh as well as in the context of the present day world.</p>',
50, 1),

(6, 'Grading System',
'<p>The letter grade of each course and corresponding grade point shall be calculated as per Uniform Grading System of UGC in the following manner.</p>
<table class="table table-bordered"><thead><tr><th>Numerical Grade</th><th>Letter Grade</th><th>Grade Point</th></tr></thead><tbody>
<tr><td>80% and above</td><td>A+ (A Plus)</td><td>4.0</td></tr>
<tr><td>75% to less than 80%</td><td>A (A regular)</td><td>3.75</td></tr>
<tr><td>70% to less than 75%</td><td>A– (A minus)</td><td>3.5</td></tr>
<tr><td>65% to less than 70%</td><td>B+ (B Plus)</td><td>3.25</td></tr>
<tr><td>60% to less than 65%</td><td>B (B regular)</td><td>3.0</td></tr>
<tr><td>55% to less than 60%</td><td>B– (B minus)</td><td>2.75</td></tr>
<tr><td>50% to less than 55%</td><td>C+ (C Plus)</td><td>2.5</td></tr>
<tr><td>45% to less than 50%</td><td>C (C regular)</td><td>2.25</td></tr>
<tr><td>40% to less than 45%</td><td>D</td><td>2.0</td></tr>
<tr><td>Less than 40%</td><td>F</td><td>0.0</td></tr>
</tbody></table>
<p>Grades will be converted into Grade Point Average of each semester and ultimately into Cumulative Grade Point Average (CGPA) on the basis of established rules. In preparing the results for the Honours and Master\'s program if a candidate falls short of First Class (CGPA 3.00) by not more than point 0.01 or of Second Class (CGPA 2.50) by not more than point 0.01, the relevant Examination Committee while finalizing the results shall have the power, with the approval of the Vice-Chancellor, to award such point (0.01) of marks to the candidate as would cover up his shortage of First Class (CGPA 3.00) or Second Class (CGPA 2.50), provided that in marking the award, the Examination Committee shall take into consideration the record of the work of the candidate during the period of course and reports of the teachers concerned about his general intelligence, regularity of work, earnestness and sense of responsibility and extra-curricular and co-curricular contribution in the department as well as to the University.</p>',
60, 1),

(7, 'Incomplete Grade',
'<p>A student who has appeared at the Semester Mid Term Examination but fails to appear at the Semester Final Examination will be assigned "I" Grade. Students having "I" Grade must sit for special examinations within 02 (two) weeks in order to replace the "I" grade. If a student fails to do so, he/she will automatically earn the "F" grade.</p>',
70, 1),

(8, 'Attendance Requirement',
'<ol type="a">
<li>Attendance in class is mandatory for all students.</li>
<li>A student with class attendance below 70% will not be allowed to sit for the final exams.</li>
<li>Student absent in 3 consecutive classes, will require permission of the Head of the Department to attend the following classes.</li>
<li>Student unable to attend classes for a known reason, must apply to the Head of the Department, with copies to the course teacher and the Registrar\'s Office, mentioning the dates and reasons for absence. If it is not possible to inform in advance, the Head of the Department must be informed by sending an application through messenger, post, fax or email.</li>
<li>A student will get proportionate marks on attendance depending on his/her percent of attendance in a course.</li>
</ol>',
80, 1),

(9, 'Duration (Validity) of Registration',
'<p>The Validity of registration of a student will be as noted below:</p>
<ol type="a">
<li>For 01 (one) year program student\'s registration will be valid for two years.</li>
<li>For 02 (two) year program student\'s registration will remain valid for 04 (four) years.</li>
<li>For 03 (three) year program student\'s registration will remain valid for 06 (six) years.</li>
<li>For 04 (four) year program student\'s registration will remain valid for 08 (eight) years.</li>
</ol>
<p>Consecutive discontinuation of a maximum of 03 (Three) semesters will be considered as break of study. In such case the student concerned shall have to apply for re-admission.</p>',
90, 1);

-- Register module
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`, `is_active`)
VALUES ('Policy & Procedure', 'policy-procedure', 'Manage academic policy and procedure sections', 'fas fa-file-contract', 62, 1);
