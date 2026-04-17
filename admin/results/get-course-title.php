<?php
/**
 * AJAX: Look up an existing course title by course code.
 *
 * Supports "suffix-stripping" so that entering a code like BEL-121E
 * will first try an exact match, then fall back to the base code BEL-121
 * (stripping a single trailing alphabetic character after a digit).
 *
 * Returns JSON: { found: bool, title: string|null, code: string|null }
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
if (!rm_can_view()) { http_response_code(403); echo '{"found":false}'; exit; }

header('Content-Type: application/json');

$raw_code = trim($_GET['code'] ?? '');
if ($raw_code === '') { echo '{"found":false}'; exit; }

/**
 * Strip a trailing single-letter variant suffix from a course code.
 * e.g. BEL-121E → BEL-121  |  CSE-101A → CSE-101  |  MAT-201 → MAT-201
 */
function base_course_code(string $code): string
{
    // If the code ends with one letter preceded by a digit, strip it.
    return preg_replace('/(\d)[A-Za-z]$/', '$1', $code);
}

function find_course_title(string $code): ?string
{
    $stmt = db()->prepare(
        'SELECT course_title
         FROM result_subjects
         WHERE UPPER(TRIM(course_code)) = UPPER(TRIM(?))
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['course_title'] : null;
}

// 1. Exact match
$title = find_course_title($raw_code);
if ($title !== null) {
    echo json_encode(['found' => true, 'title' => $title, 'code' => $raw_code]);
    exit;
}

// 2. Base-code fallback (strip trailing letter suffix)
$base = base_course_code($raw_code);
if ($base !== $raw_code) {
    $title = find_course_title($base);
    if ($title !== null) {
        echo json_encode(['found' => true, 'title' => $title, 'code' => $base]);
        exit;
    }
}

echo json_encode(['found' => false]);
