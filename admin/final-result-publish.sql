-- ============================================================================
-- Final Result Publish – module registration
-- Run once. Registers the module so access can be granted in Module Access.
-- ============================================================================

INSERT INTO modules (name, slug, description, icon, sort_order, is_active)
SELECT 'Final Result Publish',
       'final-result-publish',
       'Bulk-publish final results (CGPA) from a CSV so they appear on the public certificate verification page.',
       'fas fa-award',
       0,
       1
WHERE NOT EXISTS (SELECT 1 FROM modules WHERE slug = 'final-result-publish');
