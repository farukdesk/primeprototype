-- Invigilation Duty Swap / Substitute requests — v1
--
-- Lets a faculty member request another faculty to either SWAP a duty slot or
-- act as a SUBSTITUTE (cover) for one of their invigilation slots. The target
-- faculty must accept before anything changes; on acceptance the ei_slots
-- assignment is updated automatically so every screen that reads ei_slots
-- (exam-invigilation views, remuneration bills, attendance, the duty page)
-- reflects the change.
--
-- Safe to run multiple times: all statements are idempotent.

CREATE TABLE IF NOT EXISTS `ivd_swap_requests` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_type` ENUM('swap','substitute') NOT NULL
        COMMENT 'swap = exchange two duty slots; substitute = target covers requester seat',
    `status` ENUM('pending','accepted','rejected','cancelled') NOT NULL DEFAULT 'pending',

    -- Requester side
    `requester_faculty_id` INT(10) UNSIGNED NOT NULL COMMENT 'FK ei_faculty.id (initiator)',
    `requester_slot_id`    INT(10) UNSIGNED NOT NULL COMMENT 'FK ei_slots.id (requester duty)',
    `requester_position`   TINYINT(1) NOT NULL COMMENT '1 or 2 — seat held by requester on their slot',

    -- Target side
    `target_faculty_id` INT(10) UNSIGNED NOT NULL COMMENT 'FK ei_faculty.id (must accept)',
    `target_slot_id`    INT(10) UNSIGNED DEFAULT NULL COMMENT 'FK ei_slots.id (swap only)',
    `target_position`   TINYINT(1) DEFAULT NULL COMMENT '1 or 2 — seat held by target on their slot (swap only)',

    `message` VARCHAR(500) DEFAULT NULL,
    `responded_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
    `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

    PRIMARY KEY (`id`),
    KEY `idx_ivd_swap_requester` (`requester_faculty_id`, `status`),
    KEY `idx_ivd_swap_target` (`target_faculty_id`, `status`),
    KEY `idx_ivd_swap_req_slot` (`requester_slot_id`),
    KEY `idx_ivd_swap_tgt_slot` (`target_slot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
