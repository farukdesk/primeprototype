<?php
/**
 * Front-end include: Site Popup
 * Shows a configurable popup to first-time visitors (localStorage-gated).
 * Include this file once per page, after the <body> opens.
 */

$_popup = get_popup_settings();

// Do nothing if popup is disabled or settings unavailable
if (empty($_popup) || ($_popup['is_active'] ?? '0') !== '1') return;

$_pt_type         = $_popup['popup_type']    ?? 'text';
$_pt_title        = $_popup['title']         ?? '';
$_pt_content      = $_popup['content']       ?? '';
$_pt_image        = $_popup['image']         ?? '';
$_pt_image_alt    = $_popup['image_alt']     ?? '';
$_pt_image_link   = $_popup['image_link']    ?? '';
$_pt_btn_text     = $_popup['btn_text']      ?? '';
$_pt_btn_url      = $_popup['btn_url']       ?? '';
$_pt_btn_target   = ($_popup['btn_target'] ?? '_self') === '_blank' ? '_blank' : '_self';
$_pt_delay        = max(0, (int)($_popup['delay_seconds'] ?? 1));
$_pt_expire_hours = max(1, (int)($_popup['expire_hours']  ?? 12));

// Upload URL for popup images (stored under admin/uploads/popup/)
// basename() prevents any path-traversal sequences in the stored filename
$_pt_image_url = ADMIN_UPLOAD_URL . '/popup/' . basename($_pt_image);
?>
<!-- ── Site Popup ── -->
<div id="pu-popup-overlay" style="
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,.55);
    z-index:99999;
    align-items:center;
    justify-content:center;
    padding:16px;
    opacity:0;
    transition:opacity .3s ease;
" aria-modal="true" role="dialog" aria-labelledby="pu-popup-title">

    <div id="pu-popup-box" style="
        background:#fff;
        border-radius:14px;
        max-width:<?= $_pt_type === 'image' && $_pt_image ? '700px' : '560px' ?>;
        width:100%;
        max-height:90vh;
        overflow-y:auto;
        box-shadow:0 20px 60px rgba(0,0,0,.3);
        position:relative;
        transform:translateY(30px);
        transition:transform .35s cubic-bezier(.22,.68,0,1.2);
    ">

        <!-- Close button -->
        <button id="pu-popup-close"
                aria-label="Close popup"
                style="
                    position:absolute;
                    top:12px;right:14px;
                    background:#f1f3f9;
                    border:none;cursor:pointer;
                    width:32px;height:32px;
                    border-radius:50%;
                    display:flex;align-items:center;justify-content:center;
                    font-size:16px;color:#555;
                    z-index:1;
                    transition:background .15s;
                "
                onmouseover="this.style.background='#e0e4f0'"
                onmouseout="this.style.background='#f1f3f9'">
            &#x2715;
        </button>

        <?php if ($_pt_title !== ''): ?>
        <div style="padding:22px 50px 0 24px;">
            <h5 id="pu-popup-title" style="
                margin:0;
                font-family:'Inter',sans-serif;
                font-weight:700;
                font-size:1.15rem;
                color:#1a1f36;
            "><?= fh($_pt_title) ?></h5>
        </div>
        <?php endif; ?>

        <?php if ($_pt_type === 'image' && $_pt_image): ?>
        <!-- Image popup -->
        <div style="padding:20px 24px 24px;">
            <?php if ($_pt_image_link): ?>
            <a href="<?= fh($_pt_image_link) ?>" target="_blank" rel="noopener">
            <?php endif; ?>
                <img src="<?= fh($_pt_image_url) ?>"
                     alt="<?= fh($_pt_image_alt) ?>"
                     style="width:100%;border-radius:10px;display:block;">
            <?php if ($_pt_image_link): ?>
            </a>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <!-- Text popup -->
        <div style="
            padding:20px 24px 4px;
            font-family:'Inter',sans-serif;
            font-size:.925rem;
            line-height:1.65;
            color:#444;
        ">
            <?= $_pt_content /* already stored/entered as trusted HTML by admin */ ?>
        </div>
        <?php endif; ?>

        <?php if ($_pt_btn_text !== '' && $_pt_btn_url !== ''): ?>
        <!-- Action button -->
        <div style="padding:0 24px 24px;margin-top:8px;">
            <a href="<?= fh($_pt_btn_url) ?>"
               target="<?= fh($_pt_btn_target) ?>"
               <?= $_pt_btn_target === '_blank' ? 'rel="noopener"' : '' ?>
               style="
                   display:inline-block;
                   background:#002147;
                   color:#fff;
                   text-decoration:none;
                   padding:11px 26px;
                   border-radius:8px;
                   font-family:'Inter',sans-serif;
                   font-size:.9rem;
                   font-weight:600;
                   transition:background .15s;
               "
               onmouseover="this.style.background='#4f8ef7'"
               onmouseout="this.style.background='#002147'">
                <?= fh($_pt_btn_text) ?>
            </a>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
(function () {
    var STORAGE_KEY   = 'pu_popup_seen';
    var EXPIRE_MS     = <?= $_pt_expire_hours ?> * 60 * 60 * 1000;
    var DELAY_MS      = <?= $_pt_delay ?> * 1000;
    var overlay       = document.getElementById('pu-popup-overlay');
    var box           = document.getElementById('pu-popup-box');
    var closeBtn      = document.getElementById('pu-popup-close');

    if (!overlay || !box) return;

    function shouldShow() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return true;
            var ts = parseInt(raw, 10);
            return isNaN(ts) || (Date.now() - ts) >= EXPIRE_MS;
        } catch (e) {
            return true; // localStorage unavailable – always show
        }
    }

    function markSeen() {
        try { localStorage.setItem(STORAGE_KEY, String(Date.now())); } catch (e) {}
    }

    function showPopup() {
        overlay.style.display = 'flex';
        // Trigger transition on next frame
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                overlay.style.opacity = '1';
                box.style.transform   = 'translateY(0)';
            });
        });
        markSeen();
    }

    function hidePopup() {
        overlay.style.opacity   = '0';
        box.style.transform     = 'translateY(30px)';
        setTimeout(function () { overlay.style.display = 'none'; }, 320);
    }

    if (!shouldShow()) return;

    // Show after delay
    setTimeout(showPopup, DELAY_MS > 0 ? DELAY_MS : 0);

    // Close via button (once: true – popup is only shown once per page load)
    closeBtn.addEventListener('click', hidePopup, { once: true });

    // Close on overlay click (outside the box)
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) hidePopup();
    });

    // Close on Escape key (once: true – no listener accumulation)
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') hidePopup();
    }, { once: true });
}());
</script>
<!-- ── /Site Popup ── -->
