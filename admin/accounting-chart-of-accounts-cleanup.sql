-- =============================================================================
-- Chart of Accounts cleanup — student fee collection
-- =============================================================================
--
-- WHY THIS SCRIPT EXISTS
-- ----------------------
-- The base chart of accounts (acc_accounts ids 1–29) is a clean, standard COA:
--   1000s = Assets, 2000s = Liabilities, 3000s = Equity,
--   4000s = Income,  5000s = Expenses.
-- The accounting engine posts every student fee to one of these standard income
-- accounts via acc_default_income_code_for_fee_type():
--       admission / form_fee / id_card_fee  -> 4200  (Admission Fees)
--       registration / semester_tuition /
--       fixed_fee / english_fee             -> 4100  (Tuition Fees)
--       other                               -> 4700  (Miscellaneous Income)
--
-- Eight extra income accounts (ids 30–37) were later created by hand while
-- experimenting. They carry invalid/random codes ('02', '2324', '12000',
-- '324324', '34324', '4354', '23444', '3020000'), an income account ('12000')
-- mis-used as a "Student Accounts" grouping header, and they DUPLICATE income
-- accounts that already exist. They pollute the Chart of Accounts screen, the
-- income-account dropdowns and the per-fee-type settings, and they are NOT used
-- by the automatic collection logic (which resolves to the 41xx/42xx/47xx
-- accounts above). They are the source of the "messy" chart.
--
-- WHAT THIS SCRIPT DOES (safe, reversible inside the transaction)
-- ---------------------------------------------------------------
--   1. Repoints any already-posted voucher lines that credited a junk account
--      to the matching STANDARD income account (so reported revenue is
--      preserved and consolidated, never lost). The mapping mirrors the
--      engine's own default fee-type -> account mapping, so history and future
--      postings stay consistent.
--   2. Detaches the junk accounts from the bogus "Student Accounts" parent.
--   3. Deactivates (is_active = 0) the eight junk accounts. They are NOT
--      hard-deleted: that matches the app's own soft-delete convention
--      (account-delete.php) and keeps every historical foreign-key intact.
--      Once inactive they disappear from the Chart of Accounts, the income
--      dropdowns and every report (all of which filter is_active = 1).
--   4. Re-points any per-fee-type income setting (acc_settings
--      'income_account_*') that still references a junk code back to the
--      correct standard code, so no fee type can post into a retired account.
--
-- This script is idempotent: re-running it has no further effect.
--
-- HOW TO REVIEW BEFORE RUNNING (optional)
-- ---------------------------------------
--   SELECT vi.account_id, a.code, a.name, COUNT(*) AS lines, SUM(vi.credit_amount) credit
--   FROM acc_voucher_items vi
--   JOIN acc_accounts a ON a.id = vi.account_id
--   WHERE a.code IN ('02','2324','12000','324324','34324','4354','23444','3020000')
--   GROUP BY vi.account_id, a.code, a.name;
-- =============================================================================

START TRANSACTION;

-- Resolve the standard income account ids by their (unique) codes so the script
-- works regardless of the auto-increment ids on any given install.
SET @acc_tuition   := (SELECT id FROM acc_accounts WHERE code = '4100' LIMIT 1); -- Tuition Fees
SET @acc_admission := (SELECT id FROM acc_accounts WHERE code = '4200' LIMIT 1); -- Admission Fees
SET @acc_form_sale := (SELECT id FROM acc_accounts WHERE code = '4600' LIMIT 1); -- Form Sale Revenue
SET @acc_misc      := (SELECT id FROM acc_accounts WHERE code = '4700' LIMIT 1); -- Miscellaneous Income

-- 1) Consolidate any posted voucher lines from the junk accounts into the
--    standard income account they duplicate. Guarded by IS NOT NULL so the
--    script is a no-op when a target account is missing.

-- Admission Fees (4200): Admission Fee, Student Admission Fee, ID Card Fee,
--                        Student Admission Form Fee
UPDATE acc_voucher_items
   SET account_id = @acc_admission
 WHERE @acc_admission IS NOT NULL
   AND account_id IN (
        SELECT id FROM acc_accounts WHERE code IN ('02','34324','2324','4354')
   );

-- Tuition Fees (4100): Student Registration Fee, Student Monthly Fee
UPDATE acc_voucher_items
   SET account_id = @acc_tuition
 WHERE @acc_tuition IS NOT NULL
   AND account_id IN (
        SELECT id FROM acc_accounts WHERE code IN ('324324','23444')
   );

-- Form Sale Revenue (4600): Admission Form Sale
UPDATE acc_voucher_items
   SET account_id = @acc_form_sale
 WHERE @acc_form_sale IS NOT NULL
   AND account_id IN (
        SELECT id FROM acc_accounts WHERE code = '3020000'
   );

-- Miscellaneous Income (4700): the mis-used "Student Accounts" header
--                              (only matters if it ever received a posting)
UPDATE acc_voucher_items
   SET account_id = @acc_misc
 WHERE @acc_misc IS NOT NULL
   AND account_id IN (
        SELECT id FROM acc_accounts WHERE code = '12000'
   );

-- 2) Detach the junk children from the bogus "Student Accounts" parent.
UPDATE acc_accounts
   SET parent_id = NULL
 WHERE code IN ('3020000','02','324324','34324','2324','4354','23444');

-- 3) Deactivate (soft-delete) the eight junk accounts.
UPDATE acc_accounts
   SET is_active  = 0,
       updated_at = NOW()
 WHERE code IN ('3020000','12000','02','324324','34324','2324','4354','23444');

-- 4) Repair any per-fee-type income mapping that still points at a junk code,
--    sending each fee type back to its correct standard income account.
UPDATE acc_settings
   SET setting_value = '4200'
 WHERE setting_key IN ('income_account_admission','income_account_form_fee','income_account_id_card_fee')
   AND setting_value IN ('3020000','12000','02','324324','34324','2324','4354','23444');

UPDATE acc_settings
   SET setting_value = '4100'
 WHERE setting_key IN ('income_account_registration','income_account_semester_tuition','income_account_fixed_fee','income_account_english_fee')
   AND setting_value IN ('3020000','12000','02','324324','34324','2324','4354','23444');

UPDATE acc_settings
   SET setting_value = '4700'
 WHERE setting_key = 'income_account_other'
   AND setting_value IN ('3020000','12000','02','324324','34324','2324','4354','23444');

COMMIT;

-- =============================================================================
-- Post-run verification (run manually, should all look clean)
-- =============================================================================
-- Active income accounts that remain (should only be the 41xx–47xx set):
--   SELECT code, name, is_active FROM acc_accounts WHERE type = 'income' ORDER BY code;
--
-- No fee-type setting should reference a retired account:
--   SELECT s.setting_key, s.setting_value, a.is_active
--   FROM acc_settings s LEFT JOIN acc_accounts a ON a.code = s.setting_value
--   WHERE s.setting_key LIKE 'income_account_%';
-- =============================================================================
