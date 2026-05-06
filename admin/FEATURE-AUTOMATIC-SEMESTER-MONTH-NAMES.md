# Automatic Semester Names and Month Names Feature

## Overview
This feature automates the generation of semester names and month names in the student fee breakdown section.

## Features

### 1. Automatic Semester Name Generation
When setting the label for the first semester in a student's account, the system can automatically generate semester names for all remaining semesters based on the student's semester type.

**Semester Types:**
- **Bi-semester**: Spring and Fall only (typically 8 semesters for a 4-year program)
  - Sequence: Spring → Fall → Spring → Fall...
  - Example: Spring 2026, Fall 2026, Spring 2027, Fall 2027...
  - Note: Total number of semesters depends on the program configuration

- **Tri-semester**: Spring, Summer, Fall (typically 12 semesters for a 4-year program)
  - Sequence: Spring → Summer → Fall → Spring...
  - Example: Spring 2026, Summer 2026, Fall 2026, Spring 2027...
  - Note: Total number of semesters depends on the program configuration

**How to Use:**
1. Navigate to a student account at `/admin/student-accounts/view.php`
2. Click the edit icon next to the first semester (Semester #1)
3. Enter the semester label (e.g., "Spring 2026")
4. Check the "Auto-fill remaining semesters" checkbox
5. Click Save
6. The system will automatically generate labels for all remaining semesters based on the student's semester type

### 2. Month Name Display
The month-wise breakdown now displays month names alongside month numbers for better clarity.

**Format:** `Month 1 (January)`, `Month 2 (February)`, etc.

**Configuration:**
The starting month can be configured in the Course Fees Settings:
1. Navigate to `/admin/course-fees/settings.php`
2. Select the "Semester Start Month" from the dropdown
3. Click Save Settings

The month names will be calculated based on the configured start month. For example:
- If start month is January: Month 1 (January), Month 2 (February), Month 3 (March)...
- If start month is June: Month 1 (June), Month 2 (July), Month 3 (August)...

## Database Changes

### New Migration: `admin/course-fees-start-month.sql`
Adds a `start_month` column to the `cf_settings` table.

```sql
ALTER TABLE `cf_settings`
    ADD COLUMN IF NOT EXISTS `start_month` TINYINT UNSIGNED DEFAULT 1
        COMMENT 'Starting month (1-12) for the semester (1=January, 6=June, etc.)'
        AFTER `form_id_fee`;
```

**Installation:**
Run this SQL migration file to enable the feature:
```bash
mysql -u [username] -p [database] < admin/course-fees-start-month.sql
```

## Code Changes

### Files Modified:
1. **admin/course-fees/settings.php**
   - Added `start_month` field to settings form
   - Added month dropdown for semester start month selection

2. **admin/student-accounts/helpers.php**
   - Added `sfp_generate_semester_names()` function
   - Added `sfp_get_month_name()` function

3. **admin/student-accounts/set-semester-label.php**
   - Added auto-fill logic for semester names
   - Checks if semester #1 and auto_fill is enabled
   - Generates and saves semester names for all semesters

4. **admin/student-accounts/view.php**
   - Updated to display month names in month-wise breakdown
   - Added auto-fill checkbox to semester label modal
   - Added JavaScript to show/hide auto-fill checkbox based on semester number

## Helper Functions

### `sfp_generate_semester_names($semester_type, $first_semester, $total_semesters)`
Generates semester names based on semester type.

**Parameters:**
- `$semester_type` (string): 'bi_semester' or 'trimester'
- `$first_semester` (string): The first semester name (e.g., "Spring 2026")
- `$total_semesters` (int): Total number of semesters

**Returns:** Array of semester names

**Example:**
```php
$names = sfp_generate_semester_names('trimester', 'Spring 2026', 12);
// Returns: ['Spring 2026', 'Summer 2026', 'Fall 2026', 'Spring 2027', ...]
```

### `sfp_get_month_name($month_num, $start_month)`
Returns the month name for a given month number based on the start month.

**Parameters:**
- `$month_num` (int): Month number within the semester (1-12)
- `$start_month` (int): Starting month of the semester (1-12, where 1=January)

**Returns:** Month name (string)

**Example:**
```php
$name = sfp_get_month_name(1, 6); // Returns "June"
$name = sfp_get_month_name(2, 6); // Returns "July"
```

## User Interface

### Course Fees Settings Page
- New dropdown field: "Semester Start Month"
- Allows selection of start month (January through December)
- Help text: "Starting month for the semester. Used to display month names in the student fee breakdown."

### Student Account View Page
- Month-wise breakdown now shows: "Month 1 (January)" format
- Semester label edit modal includes auto-fill checkbox (only for Semester #1)
- Auto-fill checkbox is checked by default for better UX

## Requirements
- Student must have `semester_type` field set in the `students` table
- Package must have `total_semesters` configured
- Valid semester label format: "[Season] [Year]" (e.g., "Spring 2026")
- Valid seasons: spring, summer, fall (case-insensitive)

## Notes
- The auto-fill feature only appears when editing Semester #1
- Auto-fill respects the student's semester type from their profile
- Month names wrap around after December (e.g., Month 8 starting from June = January)
- The feature is backwards compatible - existing semester labels are not affected
