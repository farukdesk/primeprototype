-- Footer CMS Settings
-- Run this SQL to set up the cms_footer_settings table

CREATE TABLE IF NOT EXISTS `cms_footer_settings` (
    `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(100)     NOT NULL UNIQUE,
    `setting_value` TEXT,
    `created_at`    DATETIME         DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME         DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default values
INSERT INTO `cms_footer_settings` (`setting_key`, `setting_value`) VALUES
  ('about_text',        'Empowering future leaders through quality education, research and vibrant campus life since 1993.'),
  ('cta_text',          'Contact Us'),
  ('cta_url',           'contact.php'),
  ('col2_title',        'Quick Links'),
  ('col2_link_1_text',  'Admission'),
  ('col2_link_1_url',   'admission.php'),
  ('col2_link_2_text',  'Departments'),
  ('col2_link_2_url',   'department.php'),
  ('col2_link_3_text',  'Faculty'),
  ('col2_link_3_url',   'faculty-profile.php'),
  ('col2_link_4_text',  'Career Opportunities'),
  ('col2_link_4_url',   'jobs.php'),
  ('col2_link_5_text',  'Scholarships'),
  ('col2_link_5_url',   'scholarships-waivers.php'),
  ('col3_title',        'Student Services'),
  ('col3_link_1_text',  'Student Portal'),
  ('col3_link_1_url',   '#'),
  ('col3_link_2_text',  'Library'),
  ('col3_link_2_url',   'library.php'),
  ('col3_link_3_text',  'Results'),
  ('col3_link_3_url',   '#'),
  ('col3_link_4_text',  'Campus Map'),
  ('col3_link_4_url',   '#'),
  ('col3_link_5_text',  'Contact'),
  ('col3_link_5_url',   'contact.php'),
  ('contact_phone',     '01969-955566'),
  ('contact_email',     'info@primeuniversity.ac.bd'),
  ('contact_address',   '114/116, Mazar Rd, Dhaka-1216'),
  ('contact_address_url', 'https://maps.google.com/?q=Prime+University+Dhaka'),
  ('copyright_text',    'Prime University')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- Register module in modules table (super-admin only, no group access required)
INSERT IGNORE INTO `modules` (`name`, `slug`, `description`, `icon`, `sort_order`)
VALUES ('Footer Settings', 'cms-footer', 'Manage the public website footer content', 'fas fa-shoe-prints', 90);
