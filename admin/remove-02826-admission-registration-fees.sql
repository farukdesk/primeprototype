-- ---------------------------------------------------------------------------
-- Safely remove specific invoice transactions for students whose ID starts
-- with "02826".
--
-- Scope (STRICTLY these two fee transactions only):
--   1. Admission Fee + Form Fee (BDT 500.00) + ID Card Fee (BDT 500.00)
--      -> stored as a single sfp_payments row with fee_type = 'admission'
--         (its amount already bundles the admission, form and ID card fees;
--          see admin/accounting/helpers.php acc_student_*_fee_amount()).
--   2. "Summer 2026 - Registration Fee"
--      -> sfp_payments row with fee_type = 'registration', linked to a
--         sfp_semester_fees row whose semester_label = 'Summer 2026'.
--
-- A single fee collection is stored across three linked tables (see
-- admin/accounting/helpers.php acc_collect_student_fee()):
--   * acc_vouchers       - the accounting receipt voucher (uses is_deleted /
--                          status for soft-delete & reversal)
--   * acc_voucher_items  - the debit/credit ledger lines for the voucher
--   * sfp_payments       - the student fee payment row that drives the
--                          "Paid / Outstanding" figures shown to the user.
--
-- IMPORTANT: The student "Paid / Outstanding" totals are computed only from
-- SUM(sfp_payments.amount) with NO check on voucher status (helpers.php).
-- Therefore reversing/soft-deleting the voucher alone will NOT make the fee
-- show as unpaid again -- the matching sfp_payments row MUST be removed too.
--
-- HOW TO RUN
--   Run the sections IN ORDER, inspecting output before moving on.
--   This script targets students whose ID starts with "028262" (LIKE '028262%').
--   To target ONE specific student instead, replace every occurrence of
--       s.student_id LIKE '028262%'
--   with
--       s.student_id = '02826...'   -- the exact full student id
-- ---------------------------------------------------------------------------


-- ===========================================================================
-- STEP 0 - FULL BACKUP FIRST (non-negotiable). Run this in your shell, NOT
-- inside the SQL client, before executing anything below:
--
--   mysqldump -u USER -p DBNAME \
--       acc_vouchers acc_voucher_items sfp_payments sfp_semester_fees students \
--       > backup_before_delete.sql
--
-- Keep this dump until you have verified the result in Step 4.
-- ===========================================================================


-- ===========================================================================
-- STEP 1 - PREVIEW (read-only; changes nothing).
-- ===========================================================================

-- 1a. Confirm the student set.
SELECT id, student_id, full_name
FROM students
WHERE student_id LIKE '028262%';

-- 1b. Preview the two target fee transactions.
--     Verify the row count and amounts match your expectation BEFORE continuing.
--     Watch the status / is_deleted columns: if any voucher is already
--     'reversed' or has a linked reversal (acc_vouchers.reversal_of), handle
--     those separately so you do not leave orphan reversal vouchers.
SELECT sp.id AS payment_id, sp.voucher_id, s.student_id, s.full_name,
       sp.fee_type, sf.semester_label, sp.amount,
       v.voucher_number, v.voucher_date, v.status, v.is_deleted, v.reversal_of
FROM sfp_payments sp
JOIN students s                ON s.id  = sp.student_id
JOIN acc_vouchers v            ON v.id  = sp.voucher_id
LEFT JOIN sfp_semester_fees sf ON sf.id = sp.semester_fee_id
WHERE s.student_id LIKE '028262%'
  AND ( sp.fee_type = 'admission'
     OR (sp.fee_type = 'registration' AND sf.semester_label = 'Summer 2026') );

-- 1c. (Safety) List any reversal vouchers that point at the target vouchers,
--     so they can be handled separately if present.
SELECT rv.id, rv.voucher_number, rv.status, rv.is_deleted, rv.reversal_of
FROM acc_vouchers rv
WHERE rv.reversal_of IN (
    SELECT sp.voucher_id
    FROM sfp_payments sp
    JOIN students s                ON s.id  = sp.student_id
    LEFT JOIN sfp_semester_fees sf ON sf.id = sp.semester_fee_id
    WHERE s.student_id LIKE '028262%'
      AND ( sp.fee_type = 'admission'
         OR (sp.fee_type = 'registration' AND sf.semester_label = 'Summer 2026') )
);


-- ===========================================================================
-- STEP 2 - Capture the target rows into a temporary set.
-- (TEMPORARY tables live only for the current connection/session, so keep
--  Steps 2-3 in the same session.)
-- ===========================================================================

DROP TEMPORARY TABLE IF EXISTS tmp_del;
CREATE TEMPORARY TABLE tmp_del AS
SELECT sp.id AS payment_id, sp.voucher_id
FROM sfp_payments sp
JOIN students s                ON s.id  = sp.student_id
LEFT JOIN sfp_semester_fees sf ON sf.id = sp.semester_fee_id
WHERE s.student_id LIKE '028262%'
  AND ( sp.fee_type = 'admission'
     OR (sp.fee_type = 'registration' AND sf.semester_label = 'Summer 2026') );

-- Sanity check: this count MUST match what you saw in Step 1b.
SELECT COUNT(*) AS rows_to_remove FROM tmp_del;


-- ===========================================================================
-- STEP 3 - RECOMMENDED SAFE METHOD: reversible soft-delete + payment removal.
--
-- Soft-deleting the voucher (is_deleted = 1) keeps the accounting audit trail
-- and can be undone by flipping is_deleted back to 0. Removing the matching
-- sfp_payments row is what actually makes the fees show as due again.
--
-- Run the statements below, inspect the affected row counts, then COMMIT
-- (or ROLLBACK if anything looks wrong).
-- ===========================================================================

START TRANSACTION;

UPDATE acc_vouchers
   SET is_deleted = 1
 WHERE id IN (SELECT voucher_id FROM tmp_del);

DELETE FROM sfp_payments
 WHERE id IN (SELECT payment_id FROM tmp_del);

-- Inspect the "Rows matched / Rows affected" reported above.
-- If correct:
COMMIT;
-- otherwise run instead:
-- ROLLBACK;


-- ===========================================================================
-- STEP 3 (ALTERNATIVE) - FULL HARD DELETE.
-- Use ONLY if you truly want the rows gone permanently AND Step 1c showed no
-- linked reversal vouchers. Order matters to avoid orphan rows.
-- (Leave this block commented out unless you deliberately choose it.)
-- ===========================================================================

-- START TRANSACTION;
-- DELETE FROM acc_voucher_items WHERE voucher_id IN (SELECT voucher_id FROM tmp_del);
-- DELETE FROM sfp_payments      WHERE id         IN (SELECT payment_id FROM tmp_del);
-- DELETE FROM acc_vouchers      WHERE id         IN (SELECT voucher_id FROM tmp_del);
-- COMMIT;


-- ===========================================================================
-- STEP 4 - VERIFY.
-- ===========================================================================

-- 4a. Re-run the Step 1b preview query. It should now return 0 rows.
SELECT sp.id AS payment_id, sp.voucher_id, s.student_id, s.full_name,
       sp.fee_type, sf.semester_label, sp.amount,
       v.voucher_number, v.voucher_date, v.status, v.is_deleted
FROM sfp_payments sp
JOIN students s                ON s.id  = sp.student_id
JOIN acc_vouchers v            ON v.id  = sp.voucher_id
LEFT JOIN sfp_semester_fees sf ON sf.id = sp.semester_fee_id
WHERE s.student_id LIKE '028262%'
  AND ( sp.fee_type = 'admission'
     OR (sp.fee_type = 'registration' AND sf.semester_label = 'Summer 2026') );

-- 4b. Open one affected student's account page in the app and confirm the
--     Admission fee and "Summer 2026 - Registration Fee" now show as
--     outstanding/unpaid, and the totals/ledger are correct.

DROP TEMPORARY TABLE IF EXISTS tmp_del;
