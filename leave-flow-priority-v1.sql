-- Leave Management: flow seniority.
-- When a requester belongs to multiple user groups that each have an approval
-- flow (e.g. a Head who is also Faculty, a Dean, a Pro-VC), the flow of the
-- group with the LOWEST priority number is used.
-- Suggested scheme: Pro-VC = 10, Dean = 20, Head = 30, Faculty = 100.
-- Unranked groups default to 100 in the application.
CREATE TABLE IF NOT EXISTS leave_flow_priorities (
    requester_group_id INT UNSIGNED NOT NULL PRIMARY KEY,
    priority INT NOT NULL DEFAULT 100
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
