-- ---------------------------------------------------------------------------
-- Old ERP payment method
--
-- Adds support for recording fees that were already collected in the previous
-- (old) ERP so they can be marked as paid in this system, capturing the
-- original payment date, amount and receipt number.
--
-- The new payment_method value is `old_erp`. These columns may previously have
-- been defined as ENUM('cash','bank','mobile_banking'); converting them to
-- VARCHAR keeps every existing value and allows the `old_erp` value without
-- further enum maintenance. Run this once against the application database.
-- ---------------------------------------------------------------------------

ALTER TABLE sfp_payments
    MODIFY payment_method VARCHAR(20) NOT NULL DEFAULT 'cash';

ALTER TABLE adm_admission_fee_payments
    MODIFY payment_method VARCHAR(20) NOT NULL DEFAULT 'cash';
