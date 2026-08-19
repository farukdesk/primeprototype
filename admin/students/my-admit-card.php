<?php
/**
 * Student Portal – My Admit Card
 * Students can view and download their admit card(s) if dues ≤ ৳500.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/../admit-card/helpers.php';

if (!is_portal_student()) {
    flash_set('error', 'You do not have permission to access this section.');
    redirect(APP_URL . '/index.php');
}

$user = auth_user();
$db   = db();

// Find the student record
$me_stmt = $db->prepare(
    'SELECT s.*,
            d.name AS dept_name,
            d.code AS dept_code,
            d.faculty_label AS dept_faculty_label,
            p.program_name,
            b.name AS batch_name
     FROM students s
     JOIN dept_departments d ON d.id = s.dept_id
     LEFT JOIN dept_academic_programs p ON p.id = s.program_id
     LEFT JOIN student_batches b ON b.id = s.batch_id
     WHERE s.portal_user_id = ?
     LIMIT 1'
);
$me_stmt->execute([$user['id']]);
$student = $me_stmt->fetch() ?: null;

if (!$student) {
    flash_set('error', 'No student profile is linked to your account. Please contact the administrator.');
    redirect(APP_URL . '/index.php');
}

$student_id = (int)$student['id'];
$page_title = 'My Admit Card';

// Find active admit cards for this student's dept+program.
// If any tokens are pre-seeded for a card (e.g. from bulk import) only show cards
// where this student has a token. Cards with no tokens at all (manually created)
// remain visible to all matching students.
//
// Enrollment restriction: students only see admit cards for courses they are
// actually enrolled (registered) in via a course offer:
//   - routine-linked cards: the student must be registered in at least one
//     course of the linked exam routine;
//   - cards whose courses reference offer subjects: the student must be
//     registered in at least one of those offer subjects;
//   - fully manual cards (no routine, no offer-subject links) stay visible
//     to all dept+program matching students;
//   - an admin override always makes the card visible.
$has_routine_col = false;
$has_subject_col = false;
try { $db->query('SELECT routine_id FROM ac_admit_cards LIMIT 1'); $has_routine_col = true; } catch (Throwable $e) {}
try { $db->query('SELECT offer_subject_id FROM ac_admit_card_courses LIMIT 1'); $has_subject_col = true; } catch (Throwable $e) {}

$params = [$student['dept_id'], $student['program_id'], $student_id];

$enroll_parts  = [];
$enroll_params = [];
if ($has_routine_col) {
    $enroll_parts[] = '(ac.routine_id IS NULL OR EXISTS (
            SELECT 1 FROM exam_routine_items i
            JOIN co_registrations r ON r.offer_subject_id = i.offer_subject_id
           WHERE i.routine_id = ac.routine_id AND r.student_id = ?))';
    $enroll_params[] = $student_id;
}
if ($has_subject_col) {
    $enroll_parts[] = '(NOT EXISTS (
            SELECT 1 FROM ac_admit_card_courses cc2
           WHERE cc2.admit_card_id = ac.id AND cc2.offer_subject_id IS NOT NULL)
        OR EXISTS (
            SELECT 1 FROM ac_admit_card_courses cc3
            JOIN co_registrations r3 ON r3.offer_subject_id = cc3.offer_subject_id
           WHERE cc3.admit_card_id = ac.id AND r3.student_id = ?))';
    $enroll_params[] = $student_id;
}

$enroll_sql = '';
if ($enroll_parts) {
    $enroll_sql = ' AND (EXISTS (SELECT 1 FROM ac_student_overrides ov
                                  WHERE ov.admit_card_id = ac.id AND ov.student_id = ?)
                     OR (' . implode(' AND ', $enroll_parts) . '))';
    $params = array_merge($params, [$student_id], $enroll_params);
}

$cards_stmt = $db->prepare(
    'SELECT ac.*,
            d.name AS dept_name,
            p.program_name,
            (SELECT COUNT(*) FROM ac_admit_card_courses cc WHERE cc.admit_card_id = ac.id) AS course_count
     FROM ac_admit_cards ac
     JOIN dept_departments d ON d.id = ac.dept_id
     JOIN dept_academic_programs p ON p.id = ac.program_id
     WHERE ac.is_active = 1
       AND ac.dept_id    = ?
       AND ac.program_id = ?
       AND (
           NOT EXISTS (SELECT 1 FROM ac_student_tokens t WHERE t.admit_card_id = ac.id)
           OR EXISTS  (SELECT 1 FROM ac_student_tokens t WHERE t.admit_card_id = ac.id AND t.student_id = ?)
       )' . $enroll_sql . '
     ORDER BY ac.created_at DESC'
);
$cards_stmt->execute($params);
$cards = $cards_stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold">
            <i class="fas fa-id-card me-2 text-primary"></i>My Admit Card
        </h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Home</a></li>
            <li class="breadcrumb-item active">My Admit Card</li>
        </ol></nav>
    </div>
</div>

<?php flash_show(); ?>

<?php if (empty($cards)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-6">
        <i class="fas fa-id-card fa-3x text-muted mb-3"></i>
        <h6 class="text-muted">No admit cards available</h6>
        <p class="text-muted small">There are currently no active admit cards for your program. Please check back later.</p>
    </div>
</div>
<?php else: ?>

<?php foreach ($cards as $card):
    $card_id  = (int)$card['id'];
    $access   = ac_check_access($card_id, $student_id);
    // Only the courses this student is registered for (routine-linked cards)
    $courses  = ac_get_courses_for_student($card_id, $student_id);
    $token    = $access['allowed'] ? ac_get_or_create_token($card_id, $student_id) : null;
    $verify_url = $token ? ac_verify_url($token) : null;
    $qr_img_url = $token ? APP_URL . '/admit-card/qr.php?url=' . urlencode($verify_url) : null;
?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
            <span class="fw-semibold fs-6"><?= h($card['exam_name']) ?></span>
            <span class="ms-2 badge bg-primary bg-opacity-10 text-primary"><?= h($card['semester']) ?></span>
        </div>
        <?php if ($access['allowed']): ?>
        <a href="<?= APP_URL ?>/admit-card/download.php?card=<?= $card_id ?>&student=<?= $student_id ?>"
           class="btn btn-primary btn-sm" target="_blank">
            <i class="fas fa-download me-1"></i> Download PDF
        </a>
        <?php else: ?>
        <button class="btn btn-secondary btn-sm" disabled title="Clear dues to download">
            <i class="fas fa-lock me-1"></i> Download Blocked
        </button>
        <?php endif; ?>
    </div>

    <?php if (!$access['allowed']): ?>
    <!-- Due warning banner -->
    <div class="px-4 pt-3">
        <div class="alert alert-danger d-flex align-items-start gap-3 mb-3" style="border-radius:10px;">
            <i class="fas fa-exclamation-triangle mt-1 flex-shrink-0"></i>
            <div>
                <strong>You have a current due of ৳<?= number_format($access['due'] ?? 0, 2) ?></strong>
                <div class="small mt-1">Please clear your outstanding dues to view or download your admit card.</div>
                <a href="<?= APP_URL ?>/students/my-finances.php" class="btn btn-sm btn-outline-danger mt-2">
                    <i class="fas fa-file-invoice-dollar me-1"></i> View My Finances
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($access['allowed']): ?>
    <div class="card-body px-4">
        <!-- Admit Card Preview -->
        <div style="border:1px solid #dee2e6;border-radius:8px;padding:20px;background:#fff;max-width:700px;">

            <!-- Header -->
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                <div style="width:100px;text-align:center;">
                    <img src="<?= APP_URL ?>/../assets/img/logo/logo-black.png"
                         alt="Prime University" style="width:70px;height:auto;" onerror="this.style.display='none'">
                </div>
                <div style="text-align:center;line-height:1.4;">
                    <strong style="font-size:17px;">Prime University</strong><br>
                    <span style="font-size:13px;"><?= h($card['dept_name']) ?></span><br>
                    <span style="font-size:13px;"><?= h($card['program_name']) ?></span>
                </div>
                <div style="width:100px;text-align:center;">
                    <?php if (!empty($student['photo'])):
                        $photo_abs = dirname(__DIR__) . '/uploads/students/photos/' . $student['photo'];
                        $photo_url = is_file($photo_abs)
                            ? APP_URL . '/uploads/students/photos/' . rawurlencode($student['photo'])
                            : null;
                    ?>
                        <?php if ($photo_url): ?>
                        <img src="<?= $photo_url ?>" style="width:75px;height:90px;object-fit:cover;border:1px solid #ddd;" alt="Photo">
                        <?php else: ?>
                        <div style="width:75px;height:90px;border:1px solid #ddd;display:flex;align-items:center;justify-content:center;font-size:11px;color:#999;">No Photo</div>
                        <?php endif; ?>
                    <?php else: ?>
                    <div style="width:75px;height:90px;border:1px solid #ddd;display:flex;align-items:center;justify-content:center;font-size:11px;color:#999;">No Photo</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Title -->
            <div style="text-align:center;margin:16px 0 12px;">
                <span style="font-size:18px;font-weight:bold;border:2px solid #000;padding:2px 20px;">Admit Card</span>
            </div>

            <!-- Student info table -->
            <table style="width:100%;border-collapse:collapse;text-align:center;font-size:13px;">
                <tbody>
                    <tr>
                        <td style="border:1px solid #000;padding:5px;width:15%;">Name</td>
                        <td style="border:1px solid #000;padding:5px;font-weight:bold;width:43%;"><?= h($student['full_name']) ?></td>
                        <td colspan="3" style="border:1px solid #000;padding:5px;font-weight:bold;"><?= h($card['exam_name']) ?></td>
                    </tr>
                    <tr>
                        <td style="border:1px solid #000;padding:5px;">ID No.</td>
                        <td style="border:1px solid #000;padding:5px;font-weight:bold;"><?= h($student['student_id']) ?></td>
                        <td colspan="2" style="border:1px solid #000;padding:5px;font-weight:bold;">
                            Batch: <?= h($card['batch_label'] ?? ($student['batch_name'] ?? '')) ?>
                        </td>
                        <td style="border:1px solid #000;padding:5px;font-weight:bold;"><?= h($card['semester']) ?></td>
                    </tr>
                    <tr style="font-weight:bold;font-size:13px;background:#f8f9fa;">
                        <td style="border:1px solid #000;padding:6px;">Course Code</td>
                        <td style="border:1px solid #000;padding:6px;">Course Title</td>
                        <td style="border:1px solid #000;padding:6px;">Date</td>
                        <td style="border:1px solid #000;padding:6px;">Time Slot</td>
                        <td style="border:1px solid #000;padding:6px;">Section</td>
                    </tr>
                    <?php if (empty($courses)): ?>
                    <tr>
                        <td colspan="5" style="border:1px solid #000;padding:8px;color:#888;">No courses listed</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($courses as $c): ?>
                    <tr>
                        <td style="border:1px solid #000;padding:5px;"><?= h($c['course_code']) ?></td>
                        <td style="border:1px solid #000;padding:5px;text-align:left;"><?= h($c['course_title']) ?></td>
                        <td style="border:1px solid #000;padding:5px;"><?= $c['exam_date'] ? date('d-m-Y', strtotime($c['exam_date'])) : '—' ?></td>
                        <td style="border:1px solid #000;padding:5px;"><?= h($c['time_slot'] ?? '—') ?></td>
                        <td style="border:1px solid #000;padding:5px;"><?= h($c['section'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Footer with QR -->
            <div style="margin-top:16px;display:flex;justify-content:space-between;align-items:flex-end;">
                <div style="font-size:11px;color:#555;max-width:420px;line-height:1.5;">
                    <em style="color:#444;">This is a digitally generated admit card. You can authenticate it by scanning the QR code.</em>
                </div>
                <div style="text-align:center;">
                    <img src="<?= h($qr_img_url) ?>" style="width:90px;height:90px;" alt="QR Code">
                    <div style="font-size:9px;color:#777;margin-top:2px;">Scan to verify</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card-footer bg-transparent px-4 py-2 text-muted small">
        <i class="fas fa-info-circle me-1"></i>
        <?= (int)$card['course_count'] ?> course<?= $card['course_count'] != 1 ? 's' : '' ?> &mdash;
        <?= $access['allowed'] ? 'You are eligible to download this admit card.' : 'Blocked due to outstanding dues.' ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
