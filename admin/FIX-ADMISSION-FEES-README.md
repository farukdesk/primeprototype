# Fix: Admission Fee Not Showing on Student Accounts View Page

## Problem
The "Admission Fee (one-time)" is not displaying on the student-accounts/view.php page at:
- Fee Constants Snapshot card
- Semester breakdown table footer

## Root Cause
The `admission_fees` column may be missing from the `sfp_packages` table in the live database, even though it's defined in the schema file (`admin/student-fee-package.sql`).

## Solution

### Step 1: Run the Migration
Execute the migration file to add the `admission_fees` column if it doesn't exist:

```bash
mysql -u [username] -p [database_name] < admin/fix-admission-fees-column.sql
```

Or from MySQL prompt:
```sql
USE [database_name];
SOURCE admin/fix-admission-fees-column.sql;
```

This migration will:
- Check if the `admission_fees` column exists in `sfp_packages`
- Add it if missing (INT UNSIGNED NOT NULL DEFAULT 0)
- Skip if it already exists (safe to run multiple times)

### Step 2: Verify the Column Exists
```sql
DESCRIBE sfp_packages;
```

Look for the `admission_fees` column in the output.

### Step 3: Update Existing Records (if needed)
If you have existing student accounts with missing admission fee data, you can update them based on the program they're linked to:

```sql
UPDATE sfp_packages p
JOIN cf_programs cp ON cp.id = p.cf_program_id
SET p.admission_fees = cp.admission_fees
WHERE p.admission_fees = 0 OR p.admission_fees IS NULL;
```

## Code Changes Made

The following files have been updated with defensive null coalescing operators to prevent errors if the column is missing or NULL:

1. **admin/student-accounts/view.php**
   - Line 36: `$admission_fee = (float)($pkg['admission_fees'] ?? 0);`
   - Line 153: `'Admission Fee (one-time)' => sfp_money((float)($pkg['admission_fees'] ?? 0))`

2. **admin/student-accounts/statement.php**
   - Line 62: `$admission_fee = (float)($pkg['admission_fees'] ?? 0);`

3. **admin/student-accounts/create.php**
   - Line 23: `'admission_fees' => (int)($prog['admission_fees'] ?? 0)`

## Testing

After applying the fix:

1. Navigate to a student account: `/admin/student-accounts/view.php?id=X`
2. Check the "Fee Constants Snapshot" card - "Admission Fee (one-time)" should be visible
3. Scroll to the semester breakdown table footer
4. Verify "Admission Fee (one-time) →" row is visible with the correct amount
5. Verify "Grand Total (incl. Admission Fee) →" shows the correct total

## Files Changed
- `admin/fix-admission-fees-column.sql` (new migration file)
- `admin/student-accounts/view.php`
- `admin/student-accounts/statement.php`
- `admin/student-accounts/create.php`

## Related Schema Files
- `admin/student-fee-package.sql` - Original schema with admission_fees column
- `admin/course-fees-v4.sql` - Course fees schema with admission_fees in cf_programs
