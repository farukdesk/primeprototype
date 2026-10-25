-- Staff Attendance: "Exam Duty" quick day mark
-- ---------------------------------------------------------------------------
-- Adds the 'exam_duty' value to the att_day_status.status enum so an admin can
-- mark a single day as Exam Duty from the staff calendar (staff.php modal).
-- A day marked Exam Duty is never counted Late In / Early Out / Insufficient
-- Hours — same exemption as a rostered invigilation duty (ei_slots), but for
-- duties not registered in the Exam Invigilation module.
--
-- NOTE: helpers.php auto-applies this migration on first use via
-- att_ensure_exam_duty_mark(); this file is the reference mirror for manual
-- deployments.

ALTER TABLE `att_day_status`
    MODIFY `status` enum('approved_leave','day_off','absent','weekend','holiday','exam_duty')
           NOT NULL DEFAULT 'approved_leave';
