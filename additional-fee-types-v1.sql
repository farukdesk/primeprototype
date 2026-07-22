-- ═══════════════════════════════════════════════════════════════════════
-- Additional payment fee types – v1
-- ═══════════════════════════════════════════════════════════════════════
-- Adds 17 new variable-amount additional / service fee heads to the
-- sfp_payments.fee_type ENUM so they can be collected from the Collect
-- Payment > Additional Payment form:
--
--   transcript_fee, testimonial_fee, syllabus_sale, remedial_course_fee,
--   re_registration_fee, re_exam_fee, re_admission_fee,
--   provisional_certificate_fee, original_certificate_fee,
--   miscellaneous_fee, library_late_fine, late_fine,
--   id_card_replacement_fee, english_language_fee,
--   convocation_registration_fee, appeared_certificate_fee,
--   advocateship_training_fee
--
-- NOTE: id_card_replacement_fee and english_language_fee are deliberately
-- separate from the scheduled one-time 'id_card_fee' and 'english_fee'
-- heads, so collecting them never distorts the fee-schedule dues.
--
-- All new heads default to income account 4700 (Miscellaneous Income);
-- each can be remapped individually in Accounting Settings via the
-- income_account_<fee_type> setting keys.
--
-- SAFETY CHECKLIST (run on a staging copy first, and take a backup):
--   1. mysqldump the sfp_payments table before running.
--   2. Verify the live fee_type ENUM matches the list below before running
--      (phpMyAdmin > sfp_payments > Structure, or):
--        SHOW COLUMNS FROM sfp_payments LIKE 'fee_type';
--      If the live list differs, APPEND the new values to the LIVE list
--      instead of replacing it with this one.
--   3. New values are APPENDED at the end of the ENUM so existing stored
--      values keep their positions (metadata-only change on MySQL/MariaDB).
-- ═══════════════════════════════════════════════════════════════════════

ALTER TABLE `sfp_payments`
  MODIFY `fee_type` ENUM(
    'admission','form_fee','id_card_fee','registration','semester_tuition',
    'fixed_fee','english_fee','retake_fee','improvement_fee',
    'special_exam_midterm','special_exam_final','other','project_fee',
    'transcript_fee','testimonial_fee','syllabus_sale','remedial_course_fee',
    're_registration_fee','re_exam_fee','re_admission_fee',
    'provisional_certificate_fee','original_certificate_fee',
    'miscellaneous_fee','library_late_fine','late_fine',
    'id_card_replacement_fee','english_language_fee',
    'convocation_registration_fee','appeared_certificate_fee',
    'advocateship_training_fee'
  ) NOT NULL;

-- ═══════════════════════════════════════════════════════════════════════
-- VERIFICATION
-- ═══════════════════════════════════════════════════════════════════════
-- a) Confirm the ENUM now carries 30 values:
--      SHOW COLUMNS FROM sfp_payments LIKE 'fee_type';
--
-- b) Confirm no rows were altered (this is a metadata-only change):
--      SELECT fee_type, COUNT(*) FROM sfp_payments GROUP BY fee_type;
--      -- expected: identical counts to before the migration.
--
-- NOTES
--   • Run this BEFORE collecting any of the new fee types; on strict-mode
--     MySQL an unknown ENUM value is rejected and the payment would fail.
--   • Rollback (only safe while no payments use the new types):
--     re-run the MODIFY with the previous 13-value list.
