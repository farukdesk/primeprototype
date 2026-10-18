-- ---------------------------------------------------------------------------
-- Allow one old-ERP receipt number to cover several fee heads
--
-- `sfp_payments` previously carried a single-column UNIQUE key
-- (`uq_sfpp_txn`) on `transaction_number`. That guaranteed a globally unique
-- receipt/transaction number per payment.
--
-- The Old-ERP bulk merge (admin/accounting/old-erp-bulk-merge.php) intentionally
-- allows the same historical receipt number to repeat across rows, because one
-- old-ERP admission receipt commonly bundles several fee heads (Admission,
-- Registration, Form, ID Card). The merge bypasses the application-level
-- duplicate check, but the single-column unique key still rejected the second
-- (and later) fee head of the same receipt with:
--   SQLSTATE[23000]: 1062 Duplicate entry '<receipt>' for key 'uq_sfpp_txn'
--
-- Replace the single-column unique key with a composite UNIQUE key on
-- (`transaction_number`, `fee_type`). This lets one receipt number appear once
-- per fee head, while still preventing an exact duplicate (same receipt number
-- AND same fee head) from being inserted twice. Regular (non-merge) payment
-- collection continues to enforce a globally unique transaction number at the
-- application level via acc_transaction_number_exists().
--
-- NULL `transaction_number` values (cash payments) are unaffected: MySQL allows
-- multiple rows with NULL in a unique key. Run this once against the
-- application database.
-- ---------------------------------------------------------------------------

ALTER TABLE `sfp_payments`
    DROP INDEX `uq_sfpp_txn`,
    ADD UNIQUE KEY `uq_sfpp_txn` (`transaction_number`, `fee_type`);
