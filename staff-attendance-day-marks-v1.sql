-- Staff Attendance: per-day admin quick marks (Absent / Weekend / Holiday) — v1
--
-- Extends att_day_status.status so a super admin / module editor can click a
-- date on a staff member's calendar (staff-attendance/staff.php) and mark that
-- single day as Absent, Weekend or Holiday for THAT staff member only, in
-- addition to the existing Approved Leave / Day Off marks.
--
-- Safe to run once on an existing database.

ALTER TABLE att_day_status
  MODIFY COLUMN status ENUM('approved_leave','day_off','absent','weekend','holiday')
  NOT NULL DEFAULT 'approved_leave';
