-- ---------------------------------------------------------------------------
-- Split admission fee into three collection heads
--
-- Previously the admission-day fees (Admission Fee, Form Fee and ID Card Fee)
-- were collected together under a single `admission` fee_type. They are now
-- collected as three separate heads, so two new fee_type values are required:
--   * form_fee     – the one-time form fee
--   * id_card_fee  – the one-time ID card fee
--
-- Existing rows keep the `admission` value (they represent the bundled
-- collection and are split back out at reporting time). Run this once against
-- the application database.
-- ---------------------------------------------------------------------------

ALTER TABLE sfp_payments
    MODIFY `fee_type` ENUM(
        'admission',
        'form_fee',
        'id_card_fee',
        'registration',
        'semester_tuition',
        'fixed_fee',
        'english_fee',
        'other'
    ) NOT NULL;
