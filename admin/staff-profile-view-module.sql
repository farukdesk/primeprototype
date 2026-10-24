-- ============================================================================
-- Employee Profile View – separate access module
-- Registers a stand-alone module so user groups can be granted read-only
-- access to employee profiles (staff-profiles/index.php, view.php, cv-pdf.php)
-- without the full Employee Profiles (staff-profile) module.
-- The module is also auto-registered at runtime by sp_ensure_view_module()
-- in admin/staff-profiles/sp-helpers.php, so running this file is optional.
-- ============================================================================

INSERT INTO modules (name, slug, description, icon, sort_order, is_active)
SELECT 'Employee Profile View',
       'staff-profile-view',
       'Read-only access to employee profiles (list, profile view and CV PDF download).',
       'fas fa-id-badge',
       0,
       1
WHERE NOT EXISTS (SELECT 1 FROM modules WHERE slug = 'staff-profile-view');
