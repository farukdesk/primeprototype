-- Leave Management: optional "Makeup Class Schedule Plan" for Faculty leave requests.
-- Run once against the application database.
ALTER TABLE leave_requests
    ADD COLUMN makeup_plan TEXT NULL AFTER reason;
