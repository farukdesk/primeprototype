    </main><!-- /#content -->
</div><!-- /#main-wrapper -->

<!-- Bootstrap 5 Bundle (includes Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        var sidebar = document.getElementById('sidebar');
        if (window.innerWidth <= 768 && sidebar.classList.contains('open')) {
            if (!sidebar.contains(e.target) && !e.target.closest('.toggle-btn')) {
                sidebar.classList.remove('open');
            }
        }
    });
</script>

<?php if (!empty($_SESSION['user_id'])): ?>
<!-- ── Inactivity auto-logout warning ──────────────────────────────────────── -->
<div class="modal fade" id="idleWarningModal" data-bs-backdrop="static" data-bs-keyboard="false"
     tabindex="-1" aria-labelledby="idleWarningLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:14px;">
            <div class="modal-header" style="background:#fff8e1;border-bottom:1px solid #f0e6c8;">
                <h5 class="modal-title" id="idleWarningLabel">
                    <i class="fas fa-clock me-2" style="color:#e6a817;"></i>Are you still there?
                </h5>
            </div>
            <div class="modal-body">
                <p class="mb-2">You are about to be logged out because of inactivity.</p>
                <p class="mb-3">Logging out in <strong><span id="idleCountdown"><?= (int)IDLE_WARNING_SECS ?></span></strong> seconds&hellip;</p>
                <div class="progress" style="height:6px;">
                    <div class="progress-bar bg-warning" id="idleProgress" style="width:100%;transition:width 1s linear;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <a href="<?= APP_URL ?>/logout.php" class="btn btn-outline-secondary">
                    <i class="fas fa-sign-out-alt me-1"></i>Log out now
                </a>
                <button type="button" class="btn btn-primary" id="idleStayBtn">
                    <i class="fas fa-check me-1"></i>Stay logged in
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var IDLE_TIMEOUT_MS = <?= (int)IDLE_TIMEOUT ?> * 1000;   // total inactivity allowed
    var WARNING_SECS    = <?= (int)IDLE_WARNING_SECS ?>;     // countdown length
    var PING_URL        = '<?= APP_URL ?>/session-ping.php';
    var LOGOUT_URL      = '<?= APP_URL ?>/logout.php?timeout=1';
    var PING_INTERVAL   = 60 * 1000;                          // sync with server at most once/min
    var LS_KEY          = 'pu_admin_last_activity';           // cross-tab activity sync

    var lastActivity  = Date.now();
    var lastPing      = Date.now();
    var warningShown  = false;
    var countdownTimer = null;

    var modalEl = document.getElementById('idleWarningModal');
    var modal   = new bootstrap.Modal(modalEl);

    function saveActivity(ts) {
        try { localStorage.setItem(LS_KEY, String(ts)); } catch (e) { /* private mode */ }
    }
    saveActivity(lastActivity);

    function getLastActivity() {
        // Merge with activity from other tabs
        try {
            var v = parseInt(localStorage.getItem(LS_KEY) || '0', 10);
            if (v > lastActivity) lastActivity = v;
        } catch (e) { /* ignore */ }
        return lastActivity;
    }

    function ping() {
        lastPing = Date.now();
        fetch(PING_URL + '?action=ping', { credentials: 'same-origin' })
            .then(function (r) { if (r.status === 401) doLogout(); })
            .catch(function () { /* network hiccup – ignore */ });
    }

    function doLogout() {
        window.location.href = LOGOUT_URL;
    }

    function recordActivity() {
        if (warningShown) return; // while warning is open, only the buttons count
        lastActivity = Date.now();
        saveActivity(lastActivity);
        if (Date.now() - lastPing > PING_INTERVAL) ping();
    }

    function showWarning() {
        warningShown = true;
        var remaining = WARNING_SECS;
        var countEl   = document.getElementById('idleCountdown');
        var progEl    = document.getElementById('idleProgress');
        countEl.textContent = remaining;
        progEl.style.width  = '100%';
        modal.show();

        countdownTimer = setInterval(function () {
            // Activity in another tab? Dismiss the warning quietly.
            if (Date.now() - getLastActivity() < IDLE_TIMEOUT_MS - WARNING_SECS * 1000) {
                hideWarning();
                return;
            }
            remaining--;
            countEl.textContent = Math.max(remaining, 0);
            progEl.style.width  = Math.max((remaining / WARNING_SECS) * 100, 0) + '%';
            if (remaining <= 0) {
                clearInterval(countdownTimer);
                doLogout();
            }
        }, 1000);
    }

    function hideWarning() {
        warningShown = false;
        clearInterval(countdownTimer);
        modal.hide();
    }

    document.getElementById('idleStayBtn').addEventListener('click', function () {
        lastActivity = Date.now();
        saveActivity(lastActivity);
        ping();
        hideWarning();
    });

    ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'].forEach(function (evt) {
        window.addEventListener(evt, recordActivity, { passive: true });
    });

    // Watchdog – checks once a second whether the warning should appear
    setInterval(function () {
        var idleFor = Date.now() - getLastActivity();
        if (!warningShown && idleFor >= IDLE_TIMEOUT_MS - WARNING_SECS * 1000) {
            showWarning();
        }
    }, 1000);
})();
</script>
<?php endif; ?>
</body>
</html>
