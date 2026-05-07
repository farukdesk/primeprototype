# Student Fee Package Architecture

## Overview

The student fee management system uses a **snapshot architecture** to protect students from retroactive fee changes. This document explains how fees flow through the system and why changes to course fee structures don't affect existing students.

## Architecture Layers

### 1. Course Fee Structure (`cf_programs`)
**Purpose:** Master templates for fee structures

- **Location:** `admin/course-fees/` module
- **Database:** `cf_programs`, `cf_degree_types`, `cf_settings`, `cf_admission_requirements`
- **Who Uses:** Administrators managing fee structures
- **Impact:** Changes only affect **new students**

**Key Fields:**
```
- standard_tuition_full
- tuition_per_semester
- admission_fees
- fixed_institutional_fees
- english_course_fee
- safety_net_cap
- safety_net_per_semester
- attendance_requirement
- safety_net_gpa_threshold
```

**Global Settings (`cf_settings`):**
```
- reg_fee_per_semester  → Snapshotted to each student
- form_id_fee           → Snapshotted to each student
- admission_fee_base    → Reference only
```

### 2. Student Fee Packages (`sfp_packages`)
**Purpose:** Snapshotted fee obligations for each student

- **Location:** `admin/student-accounts/` module
- **Database:** `sfp_packages`, `sfp_semester_fees`, `sfp_semester_scholarships`
- **Who Uses:** Accounts office managing student accounts
- **Impact:** Isolated per student - changes don't affect other students

**What Gets Snapshotted:**
When a student account is created, **ALL** fee constants from `cf_programs` AND `cf_settings` are copied:

```sql
INSERT INTO sfp_packages (
    -- From cf_programs:
    standard_tuition_full,
    tuition_per_semester,
    admission_fees,
    fixed_institutional_fees,
    english_course_fee,
    safety_net_cap,
    safety_net_per_semester,
    attendance_requirement,
    safety_net_gpa_threshold,
    
    -- From cf_settings (CRITICAL):
    reg_fee_per_semester,    -- Snapshotted as of creation
    form_id_fee,             -- Snapshotted as of creation
    
    -- Derived values:
    monthly_fixed_fee,
    monthly_english_fee,
    months_per_semester,
    ...
)
```

### 3. Fee Collections (`sfp_payments`)
**Purpose:** Track actual cash receipts

- **Location:** `admin/accounting/` module
- **Database:** `sfp_payments`, `acc_vouchers`, `acc_voucher_items`
- **Who Uses:** Accounts office collecting payments
- **Impact:** Records transactions against student's package

**Fee Types:**
- `admission` - One-time admission day payment
- `registration` - Per-semester registration fee
- `semester_tuition` - Tuition for specific semester
- `fixed_fee` - Portion of fixed institutional fees
- `english_fee` - Portion of English course fee
- `other` - Miscellaneous charges

## How the Snapshot Works

### Creating a Student Account

**File:** `admin/student-accounts/create.php`

```php
// 1. Fetch current global settings
$cf_settings = $db->query('SELECT reg_fee_per_semester, form_id_fee FROM cf_settings')->fetch();

// 2. Create package with ALL fees snapshotted
INSERT INTO sfp_packages (
    student_id,
    cf_program_id,  // Reference to source program (nullable)
    // ... all fee constants copied from cf_programs ...
    reg_fee_per_semester,  // From cf_settings
    form_id_fee,           // From cf_settings
    assigned_by,
    created_at
)

// 3. Generate per-semester fee rows
sfp_generate_semester_fees($package_id, $total_semesters, $tuition_per_semester);
```

### Fee Calculations

**File:** `admin/accounting/helpers.php`

```php
// ❌ WRONG: Reading from global settings
$cf = $db->query('SELECT reg_fee_per_semester FROM cf_settings')->fetch();
$reg_fee = $cf['reg_fee_per_semester'];

// ✅ CORRECT: Reading from student's package
$reg_fee = (float)($pkg['reg_fee_per_semester'] ?? 0.0);
```

**Functions that read from package:**
- `acc_student_fee_summary()` - Builds complete obligation summary
- `acc_total_outstanding()` - Calculates remaining balance
- `acc_collect_student_fee()` - Records payments

## Manual Adjustments

### Per-Student Tuition Changes
**File:** `admin/student-accounts/update-tuition.php`

Tuition can be manually adjusted per semester (e.g., for fee changes after 4 months).

```php
// Update single semester's tuition
UPDATE sfp_semester_fees 
SET tuition_fee = ? 
WHERE id = ?

// Recalculate scholarship amounts proportionally
sfp_recalculate_semester($sf_id);
```

### Scholarships & Discounts
**Files:** `admin/student-accounts/add-scholarship.php`, `apply-scholarship.php`

Scholarships can be:
1. **Policy-based** - Automatically applied from `sc_policies`
2. **Manual** - Custom discounts with supporting documents

Applied to:
- Tuition (via `sfp_semester_scholarships`)
- Fixed fees (via `applies_to_fixed` flag)
- English fees (via `applies_to_english` flag)

## Important: What NOT to Do

### ❌ Never Read Global Settings for Student Calculations

```php
// This will cause retroactive changes!
$cf = $db->query('SELECT reg_fee_per_semester FROM cf_settings WHERE id = 1')->fetch();
$reg_due = $cf['reg_fee_per_semester'] * $num_semesters;
```

### ✅ Always Read from Student's Package

```php
// This protects the student
$reg_fee = (float)($pkg['reg_fee_per_semester'] ?? 0.0);
$reg_due = $reg_fee * $num_semesters;
```

### ❌ Never Update sfp_packages Globally

```php
// This would break the snapshot architecture!
UPDATE sfp_packages SET reg_fee_per_semester = 1500 WHERE id IN (...)
```

### ✅ Only Update Individual Packages When Necessary

```php
// Only for specific cases with proper justification
UPDATE sfp_packages 
SET reg_fee_per_semester = ? 
WHERE id = ? 
-- With change log entry explaining why
```

## Migration Guide

### Adding New Fee Fields

When adding a new fee type that should be snapshotted:

1. **Add column to `sfp_packages`**
```sql
ALTER TABLE sfp_packages 
ADD COLUMN new_fee_field DECIMAL(10,2) NOT NULL DEFAULT 0.00
COMMENT 'Description of the fee';
```

2. **Backfill existing packages** (if applicable)
```sql
UPDATE sfp_packages 
SET new_fee_field = (SELECT new_fee_field FROM cf_settings WHERE id = 1)
WHERE new_fee_field = 0.00;
```

3. **Update `student-accounts/create.php`**
```php
$new_fee = $cf_settings ? (float)$cf_settings['new_fee_field'] : 0.0;

// Add to INSERT statement
INSERT INTO sfp_packages (..., new_fee_field, ...)
VALUES (..., ?, ...)
```

4. **Update calculation functions**
```php
// Use from package, not global settings
$new_fee = (float)($pkg['new_fee_field'] ?? 0.0);
```

5. **Update UI display** (`student-accounts/view.php`)
```php
$constants = [
    // ...
    'New Fee' => sfp_money($pkg['new_fee_field']),
];
```

## Testing Checklist

When making changes to fee structures:

- [ ] Create a test student account before fee changes
- [ ] Note all fee values for the test student
- [ ] Change fee structures in `cf_programs` or `cf_settings`
- [ ] Verify test student's fees remain unchanged
- [ ] Create a new student account after fee changes
- [ ] Verify new student has updated fees
- [ ] Verify fee calculations use package values
- [ ] Check payment collection works correctly
- [ ] Test fee invoice/statement generation

## Key Files Reference

### Core Modules
- `admin/course-fees/` - Master fee templates
- `admin/student-accounts/` (formerly `student-fee-package/`) - Student packages
- `admin/accounting/` - Payment collection and calculations

### Critical Files
- `admin/student-fee-package.sql` - Package schema definition
- `admin/fix-registration-fee-snapshot.sql` - Registration fee fix migration
- `admin/student-accounts/create.php` - Package creation logic
- `admin/student-accounts/helpers.php` - Package helper functions
- `admin/accounting/helpers.php` - Fee calculation functions
- `admin/accounting/collect-payment.php` - Payment collection

### Documentation
- `admin/STUDENT-FEE-ARCHITECTURE.md` - This file
- Comments in SQL schema files

## Change History

### 2026-05-07: Registration Fee Snapshot Fix
**Issue:** `reg_fee_per_semester` and `form_id_fee` were read from global `cf_settings` instead of being snapshotted, causing retroactive changes to existing students.

**Fix:** 
- Added `reg_fee_per_semester` and `form_id_fee` to `sfp_packages`
- Updated all calculation functions to read from package
- Backfilled existing packages with current values
- Added warnings in Course Fees UI

**Files Changed:**
- `admin/fix-registration-fee-snapshot.sql`
- `admin/student-accounts/create.php`
- `admin/accounting/helpers.php`
- `admin/student-accounts/view.php`
- `admin/course-fees/edit.php`

## Support & Questions

For questions about the fee architecture or to report issues:

1. Check this documentation first
2. Review the SQL schema comments
3. Examine the change_log table for historical context
4. Contact the development team with specific questions

---

**Last Updated:** 2026-05-07  
**Maintained By:** Development Team
