-- =============================================================================
-- Voucher Delete Workflow
-- =============================================================================
-- Adds a controlled deletion workflow for accounting vouchers.
--
--   * Super Admin           -> deletes immediately (status 'deleted')
--   * Group "Accounts"      -> raises a delete request   (status 'pending_dd')
--   * Group "DD Accounts"   -> reviews with a note       (-> 'pending_treasurer' / 'rejected')
--   * Group "Treasurer"     -> confirms with a note      (-> 'deleted' / 'rejected')
--
-- Every action is also written to the immutable `change_log` table, which has
-- no delete capability anywhere in the application.
--
-- Idempotent: safe to run more than once.
-- =============================================================================

-- ── Soft-delete audit columns on acc_vouchers ───────────────────────────────
ALTER TABLE `acc_vouchers`
  ADD COLUMN IF NOT EXISTS `deleted_by`         int(10) UNSIGNED DEFAULT NULL AFTER `is_deleted`,
  ADD COLUMN IF NOT EXISTS `deleted_at`         datetime         DEFAULT NULL AFTER `deleted_by`,
  ADD COLUMN IF NOT EXISTS `delete_reason`      text             DEFAULT NULL AFTER `deleted_at`,
  ADD COLUMN IF NOT EXISTS `delete_request_id`  int(10) UNSIGNED DEFAULT NULL AFTER `delete_reason`;

-- ── Delete requests / workflow tracker ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `acc_voucher_delete_requests` (
  `id`                int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `voucher_id`        int(10) UNSIGNED NOT NULL,
  `voucher_number`    varchar(30) NOT NULL,
  `voucher_snapshot`  mediumtext DEFAULT NULL COMMENT 'JSON snapshot of the voucher + line items at request time',
  `total_amount`      decimal(15,2) NOT NULL DEFAULT 0.00,
  `status`            enum('pending_dd','pending_treasurer','deleted','rejected') NOT NULL DEFAULT 'pending_dd',
  `reason`            text NOT NULL COMMENT 'Mandatory reason supplied by the requester',
  `attachment`        varchar(255) DEFAULT NULL COMMENT 'Optional supporting document',
  `requested_by`      int(10) UNSIGNED NOT NULL,
  `requested_at`      datetime NOT NULL DEFAULT current_timestamp(),
  `dd_user_id`        int(10) UNSIGNED DEFAULT NULL,
  `dd_note`           text DEFAULT NULL,
  `dd_at`             datetime DEFAULT NULL,
  `treasurer_user_id` int(10) UNSIGNED DEFAULT NULL,
  `treasurer_note`    text DEFAULT NULL,
  `treasurer_at`      datetime DEFAULT NULL,
  `rejected_by`       int(10) UNSIGNED DEFAULT NULL,
  `reject_note`       text DEFAULT NULL,
  `rejected_at`       datetime DEFAULT NULL,
  `created_at`        datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vdr_voucher`   (`voucher_id`),
  KEY `idx_vdr_status`    (`status`),
  KEY `idx_vdr_requester` (`requested_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Configurable workflow group names (defaults match the spec) ─────────────
INSERT INTO `acc_settings` (`setting_key`, `setting_value`) VALUES
  ('voucher_delete_group_accounts',  'Accounts'),
  ('voucher_delete_group_dd',        'DD Accounts'),
  ('voucher_delete_group_treasurer', 'Treasurer')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;
