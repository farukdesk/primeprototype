-- Undo the effects of the removed "Mark Old ERP Fees Paid (All)" button.
--
-- That button never created any sfp_payments rows. It only appended two lines
-- to sfp_packages.note:
--   1. 'Old ERP settled: Admission Fee + Form Fee + ID Card Fee + Summer 2026 Registration Fee.'
--   2. '[OLD_ERP_SETTLED:ADMISSION+FORM+ID+SUMMER2026_REG]'  (the marker)
--
-- The marker made the accounting helpers compute "virtual" paid credits for
-- Admission + Form + ID Card + one Registration fee, so those fees appeared
-- paid without any corresponding payment record ("paid without any trace").
--
-- This script removes both appended lines, restoring the affected packages to
-- their real, payment-backed balances. Any genuine note text that existed
-- before the button was pressed is preserved.
--
-- Review the SELECT below before running the UPDATE.

-- Preview the packages that will be affected:
-- SELECT id, student_id, note
-- FROM sfp_packages
-- WHERE note LIKE '%[OLD_ERP_SETTLED:ADMISSION+FORM+ID+SUMMER2026_REG]%';

UPDATE sfp_packages
SET note = NULLIF(
        TRIM(BOTH '\n' FROM
            TRIM(
                REPLACE(
                    REPLACE(
                        REPLACE(
                            REPLACE(
                                note,
                                '\n[OLD_ERP_SETTLED:ADMISSION+FORM+ID+SUMMER2026_REG]', ''
                            ),
                            '[OLD_ERP_SETTLED:ADMISSION+FORM+ID+SUMMER2026_REG]', ''
                        ),
                        '\nOld ERP settled: Admission Fee + Form Fee + ID Card Fee + Summer 2026 Registration Fee.', ''
                    ),
                    'Old ERP settled: Admission Fee + Form Fee + ID Card Fee + Summer 2026 Registration Fee.', ''
                )
            )
        ),
        ''
    )
WHERE note LIKE '%[OLD_ERP_SETTLED:ADMISSION+FORM+ID+SUMMER2026_REG]%';
