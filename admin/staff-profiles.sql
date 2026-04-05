-- Staff Profiles Migration
-- Run once to set up staff department list, staff profile tables, modules, and permissions.

-- 1. Staff departments (admin-managed list)
CREATE TABLE IF NOT EXISTS `staff_departments` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(150) NOT NULL,
    `type`       ENUM('administrative','educational') NOT NULL,
    `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Seed default Administrative departments
INSERT IGNORE INTO `staff_departments` (`name`, `type`, `sort_order`) VALUES
('HR',       'administrative', 1),
('Register', 'administrative', 2),
('IT',       'administrative', 3);

-- 3. Staff profiles
CREATE TABLE IF NOT EXISTS `staff_profiles` (
    `id`                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`                    INT UNSIGNED NOT NULL,
    `photo`                      VARCHAR(255) DEFAULT NULL,
    `employee_id`                VARCHAR(100) DEFAULT NULL,
    `department_type`            ENUM('administrative','educational') DEFAULT NULL,
    `staff_dept_id`              INT UNSIGNED DEFAULT NULL,
    `designation`                VARCHAR(200) DEFAULT NULL,
    `emergency_contact_name`     VARCHAR(150) DEFAULT NULL,
    `emergency_contact_relation` VARCHAR(100) DEFAULT NULL,
    `emergency_contact_address`  TEXT DEFAULT NULL,
    `created_at`                 DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                 DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sp_user` (`user_id`),
    CONSTRAINT `fk_sp_user` FOREIGN KEY (`user_id`)       REFERENCES `users`(`id`)             ON DELETE CASCADE,
    CONSTRAINT `fk_sp_dept` FOREIGN KEY (`staff_dept_id`) REFERENCES `staff_departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Insert Staff Profile module
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `parent_id`, `sort_order`, `is_active`)
VALUES ('Staff Profile', 'staff-profile', 'General staff self-service profile management', 'fas fa-id-badge', NULL, 91, 1);

-- 5. Insert Staff Departments module
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `parent_id`, `sort_order`, `is_active`)
VALUES ('Staff Departments', 'staff-departments', 'Manage the administrative/educational department list for staff', 'fas fa-sitemap', NULL, 92, 1);

-- 6. Insert General Staff user group
INSERT IGNORE INTO `user_groups` (`name`, `description`, `is_super`, `is_active`)
VALUES ('General Staff', 'General administrative and educational staff members', 0, 1);

-- 7. Grant General Staff group access to Staff Profile (view + edit own profile)
INSERT IGNORE INTO `group_module_access` (`group_id`, `module_id`, `can_view`, `can_create`, `can_edit`, `can_delete`)
SELECT ug.id, m.id, 1, 0, 1, 0
FROM `user_groups` ug, `modules` m
WHERE ug.name = 'General Staff' AND m.slug = 'staff-profile';
