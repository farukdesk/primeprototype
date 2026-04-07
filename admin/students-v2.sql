-- students-v2.sql
-- Run AFTER students.sql and students-migration.sql on already-installed databases.
-- Marks every student who has at least one CGPA record in student_results
-- as 'Graduated' (unless they are already 'Dropped').

SET NAMES utf8mb4;

UPDATE `students` s
SET    s.`status` = 'Graduated'
WHERE  s.`status` != 'Dropped'
  AND  EXISTS (
          SELECT 1
          FROM   `student_results` sr
          WHERE  sr.`student_id` = s.`id`
            AND  sr.`cgpa`       IS NOT NULL
            AND  sr.`cgpa`       != ''
       );
