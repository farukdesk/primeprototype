-- ---------------------------------------------------------------------------
-- Old ERP payments: "memo" voucher status
--
-- Payments collected with the `old_erp` method were already recorded (and
-- already counted) in the previous ERP. We still create a receipt voucher so
-- the student's invoice/receipt and outstanding dues stay correct, but that
-- voucher must NOT be counted again in this system's books or collection
-- reports (neither per-staff nor overall).
--
-- Every financial and collection report in the accounting module only counts
-- vouchers whose status is `posted`. Adding a new `memo` status lets us flag
-- these Old ERP receipts so they are transparently excluded from all of those
-- totals while remaining viewable as a normal receipt.
--
-- Run this once against the application database.
-- ---------------------------------------------------------------------------

ALTER TABLE acc_vouchers
    MODIFY status ENUM('posted','reversed','memo') NOT NULL DEFAULT 'posted';

-- Reclassify any receipts already recorded for Old ERP payments so the amounts
-- already counted in the old ERP stop being double-counted here. Reversed
-- vouchers are left untouched.
UPDATE acc_vouchers v
JOIN sfp_payments p ON p.voucher_id = v.id
SET v.status = 'memo'
WHERE p.payment_method = 'old_erp'
  AND v.status = 'posted'
  AND v.is_deleted = 0;

UPDATE acc_vouchers v
JOIN adm_admission_fee_payments a ON a.voucher_id = v.id
SET v.status = 'memo'
WHERE a.payment_method = 'old_erp'
  AND v.status = 'posted'
  AND v.is_deleted = 0;
