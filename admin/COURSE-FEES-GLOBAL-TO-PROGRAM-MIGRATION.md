# Course Fees: Global Settings → Per-Program Settings Migration

## Overview

This migration moves fee constants from **global settings** (`cf_settings` table) to **per-program settings** (`cf_programs` table). This allows each program to have different admission fees, registration fees, form fees, and start months.

## What Changed

### Before (Global Settings)
All programs shared the same fee constants:
- `admission_fee_base` - One-time admission fee
- `reg_fee_per_semester` - Registration fee per semester
- `reg_fee_total` - Total registration fees across all semesters
- `form_id_fee` - Admission form + ID card fee (legacy combined)
- `id_card_fee` - ID card fee only
- `admission_form_fee` - Admission form fee only
- `bi_semester_start_month` - Starting month for bi-semester programs
- `tri_semester_start_month` - Starting month for tri-semester programs

These were defined in `cf_settings` table and applied to ALL programs.

### After (Per-Program Settings)
Each program can now have its own fee constants. The same fields have been added to the `cf_programs` table, allowing:
- BBA program: ৳10,000 admission fee, ৳1,000 registration/semester
- CSE program: ৳12,000 admission fee, ৳1,200 registration/semester
- MBA program: Different fees entirely

## Migration Steps

### 1. Database Migration
Run the SQL migration to add the new columns and copy existing global values:

```bash
mysql -u [user] -p [database] < admin/course-fees-move-global-to-program.sql
```

This will:
1. Add 8 new columns to `cf_programs` table
2. Copy current global settings to ALL existing programs (backward compatible)
3. Keep the `cf_settings` table for truly global settings (page title, session label, disclaimer)

### 2. Update Programs
After migration, edit each program individually in `admin/course-fees/edit.php` to set program-specific fees.

### 3. Settings Page Changes
The Settings page (`admin/course-fees/settings.php`) now only contains:
- **Page Title** - Title shown on public calculator
- **Session Label** - e.g. "Summer 2026"
- **Disclaimer** - Text shown at bottom of calculator
- **Published** - Whether calculator is visible to public
- **Degree Type Visibility** - Show/hide degree types

Fee constants have been **removed** from the settings page.

## Impact on Student Fee Snapshots

The snapshot architecture remains **unchanged and protected**:

### Student Fee Package Creation
When a student account is created (`admin/student-accounts/create.php`):
1. Fee constants are read from the **selected program** (`cf_programs`)
2. These values are **snapshotted** to `sfp_packages` table
3. Student's fees are **frozen** and will NOT change even if program fees are updated later

### Backward Compatibility
Existing student packages are **NOT affected**:
- Students who enrolled before this migration keep their original fees
- Fee calculations still read from `sfp_packages`, not from global settings or programs
- No retroactive changes to student accounts

## Public Calculator Updates

The public fee calculator (`course-fees-calculator.php`):
- Now passes per-program fee constants to JavaScript
- Falls back to global settings if a program doesn't define specific fees
- Displays program-specific fees in the calculator UI

### JavaScript API
Before:
```javascript
// Global fees for all programs
GLOBAL_FEES.ADM_FEE_BASE
GLOBAL_FEES.REG_FEE_SEM
GLOBAL_FEES.FORM_ID_FEE
```

After:
```javascript
// Per-program fees with global fallback
CONSTANTS['bba'].ADM_FEE_BASE       || GLOBAL_FEES.ADM_FEE_BASE
CONSTANTS['bba'].REG_FEE_SEM        || GLOBAL_FEES.REG_FEE_SEM
CONSTANTS['bba'].FORM_ID_FEE        || GLOBAL_FEES.FORM_ID_FEE
```

## Files Modified

### Database
- `admin/course-fees-move-global-to-program.sql` - Migration script

### Admin Panel
- `admin/course-fees/create.php` - Added fee fields to create form
- `admin/course-fees/edit.php` - Added fee fields to edit form
- `admin/course-fees/settings.php` - Removed fee fields, kept global settings only
- `admin/course-fees/index.php` - Updated display to show note about per-program fees
- `admin/student-accounts/create.php` - Updated to read fees from program, not global settings

### Public Pages
- `course-fees-calculator.php` - Updated to include per-program fees in JS constants

## Testing Checklist

- [ ] Run database migration successfully
- [ ] Create a new program with custom fees
- [ ] Edit existing program and update fees
- [ ] Verify settings page shows only global settings (no fees)
- [ ] Create a student account and verify fees are snapshotted from program
- [ ] View public calculator and verify per-program fees display correctly
- [ ] Verify existing students still have their original fees (no retroactive changes)

## Rollback Plan

If needed, the changes can be rolled back by:
1. Reverting to previous code version
2. **NOT** running the database migration
3. Old code will continue reading from `cf_settings` global values

Note: Once the migration is run and programs are edited, rolling back will require manually restoring the `cf_settings` table values.

## Support

If you encounter issues:
1. Check that the migration script ran successfully
2. Verify all programs have fee values set (not NULL)
3. Check browser console for JavaScript errors in calculator
4. Ensure student account creation still snapshots fees correctly

## Future Enhancements

Potential improvements:
- Bulk edit tool to update fees for multiple programs at once
- Fee history tracking for auditing changes
- Fee templates to quickly apply common fee structures
- Import/export fee configurations
