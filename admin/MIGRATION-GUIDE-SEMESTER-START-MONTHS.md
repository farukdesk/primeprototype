# Migration Guide: Bi-Semester and Tri-Semester Start Months

## Overview

This migration adds support for separate start months for bi-semester and tri-semester programs in the Course Fees module.

## Problem Statement

Previously, the system had a single `start_month` field that was used for all programs. However, bi-semester programs (2 semesters per year) and tri-semester programs (3 semesters per year) typically have different starting months. For example:

- **Bi-semester programs**: May start in January (month 1) or June (month 6)
- **Tri-semester programs**: May start in January (month 1), May (month 5), or September (month 9)

## Solution

The migration splits the single `start_month` field into two separate fields:

1. `bi_semester_start_month` - Starting month for bi-semester programs (≤8 semesters)
2. `tri_semester_start_month` - Starting month for tri-semester programs (>8 semesters)

## Migration Steps

### 1. Run the SQL Migration

Execute the following SQL file to update the database schema:

```bash
admin/course-fees-start-month-v2.sql
```

This migration will:
- Add a new `bi_semester_start_month` field
- Add a new `tri_semester_start_month` field
- Set both new fields to the existing `start_month` value (if they are NULL)
- Preserve the original `start_month` field for backward compatibility

### 2. Configure Start Months

After running the migration, configure the start months in the Course Fees settings:

1. Navigate to: **Admin Panel → Course Fees → Settings**
2. Scroll to the semester start month section
3. Set the **Bi-Semester Start Month** (e.g., January for Spring, June for Summer)
4. Set the **Tri-Semester Start Month** (e.g., January, May, or September)
5. Click **Save Settings**

## How It Works

The system automatically determines which start month to use based on the `total_semesters` value:

- **Bi-semester programs** (`total_semesters` ≤ 8): Uses `bi_semester_start_month`
- **Tri-semester programs** (`total_semesters` > 8): Uses `tri_semester_start_month`

This logic is implemented in:
- `admin/student-accounts/view.php` - For displaying monthly fee breakdowns

## Backward Compatibility

The migration maintains backward compatibility:

1. The original `start_month` field is **preserved** (not removed)
2. The new `bi_semester_start_month` and `tri_semester_start_month` fields are added alongside it
3. If the new fields are not found (e.g., migration not run), the code falls back to the legacy `start_month` field
4. Existing `start_month` values are automatically copied to both new fields during migration

## Files Modified

1. **admin/course-fees-start-month-v2.sql** - SQL migration file
2. **admin/course-fees/settings.php** - Settings page with two start month dropdowns
3. **admin/student-accounts/view.php** - Updated to use semester-specific start months

## Example Configuration

### Bi-Semester Example
- Start Month: **January** (month 1)
- Semesters: Spring (Jan-May), Fall (Jun-Dec)
- Used for: 4-year programs with 8 semesters

### Tri-Semester Example
- Start Month: **January** (month 1)
- Semesters: Spring (Jan-Apr), Summer (May-Aug), Fall (Sep-Dec)
- Used for: 4-year programs with 12 semesters

## Support

If you encounter any issues during migration, please contact the system administrator or refer to the error messages in the settings page.
