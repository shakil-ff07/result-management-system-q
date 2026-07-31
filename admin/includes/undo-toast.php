<?php
/**
 * Reusable Undo Toast Component
 * Include this at the bottom of any page that has delete actions.
 * Reads $_SESSION['undo_action'] to display and drive the countdown + AJAX restore.
 *
 * $_SESSION['undo_action'] format:
 *   ['record_id' => int, 'type' => 'student'|'class'|'students_bulk', 'name' => string]
 */

if (!isset($_SESSION['undo_action'])) return;

$undo      = $_SESSION['undo_action'];
$record_id = (int) $undo['record_id'];
$type      = htmlspecialchars($undo['type'] ?? 'item');
$name      = htmlspecialchars($undo['name'] ?? 'Record');

// Choose icon based on type
$icon = match($type) {
    'class'          => 'fa-school',
    'student'        => 'fa-user-graduate',
    'students_bulk'  => 'fa-users',
    default          => 'fa-trash',
};

$label = match($type) {
    'class'          => 'Class deleted.',
    'student'        => 'Student deleted.',
    'students_bulk'  => 'Students deleted.',
    default          => 'Record deleted.',
};
?>

<!-- ═══ UNDO TOAST ═══ -->
<style>
    #undoToast {
        position: fixed;
        bottom: 28px;
        right: 28px;
        z-index: 9999;
        min-width: 320px;
        max-width: 400px;
        background: rgba(15, 23, 42, 0.95);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 16px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255,255,255,0.05);
        padding: 0;
        overflow: hidden;
        transform: translateY(120px);
        opacity: 0;
        transition: transform 0.45s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.3s ease;
    }
    #undoToast.visible {
        transform: translateY(0);
        opacity: 1;
    }
    .undo-toast-body {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 16px 18px 14px;
    }
    .undo-icon-ring {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        background: rgba(239, 68, 68, 0.15);
        border: 1px solid rgba(239, 68, 68, 0.25);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        color: #f87171;
        font-size: 1.1rem;
    }
    .undo-toast-text {
        flex: 1;
        min-width: 0;
    }
    .undo-toast-label {
        font-size: 0.7rem;
        font-weight: 700;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        margin-bottom: 2px;
    }
    .undo-toast-name {
        font-size: 0.9rem;
        font-weight: 600;
        color: #e2e8f0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .undo-close-btn {
        background: none;
        border: none;
        color: #64748b;
        font-size: 1rem;
        cursor: pointer;
        padding: 4px;
        border-radius: 6px;
        transition: color 0.2s;
        flex-shrink: 0;
        line-height: 1;
    }
    .undo-close-btn:hover { color: #94a3b8; }

    .undo-action-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 18px 14px;
        gap: 10px;
    }
    .undo-countdown {
        font-size: 0.75rem;
        color: #64748b;
        font-weight: 500;
    }
    #undoActionBtn {
        background: linear-gradient(135deg, #f59e0b, #d97706);
        color: #0f172a;
        border: none;
        border-radius: 8px;
        padding: 7px 18px;
        font-size: 0.8rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        gap: 6px;
        letter-spacing: 0.3px;
        flex-shrink: 0;
    }
    #undoActionBtn:hover {
        background: linear-gradient(135deg, #fbbf24, #f59e0b);
        box-shadow: 0 4px 14px rgba(245,158,11,0.4);
        transform: scale(1.03);
    }
    #undoActionBtn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none;
    }
    .undo-progress-bar {
        height: 3px;
        background: rgba(255,255,255,0.06);
    }
    #undoProgress {
        height: 100%;
        background: linear-gradient(90deg, #f59e0b, #fbbf24);
        width: 100%;
        transition: width linear;
        border-radius: 0 0 16px 16px;
    }

    /* Success state */
    #undoToast.success .undo-icon-ring {
        background: rgba(16, 185, 129, 0.15);
        border-color: rgba(16, 185, 129, 0.25);
        color: #34d399;
    }
    #undoToast.success .undo-toast-name { color: #34d399; }

    @media (max-width: 480px) {
        #undoToast {
            left: 12px;
            right: 12px;
            min-width: unset;
            bottom: 16px;
        }
    }
</style>

<div id="undoToast" role="alert" aria-live="assertive">
    <div class="undo-toast-body">
        <div class="undo-icon-ring">
            <i class="fas <?= $icon ?>"></i>
        </div>
        <div class="undo-toast-text">
            <div class="undo-toast-label" id="undoToastLabel"><?= $label ?></div>
            <div class="undo-toast-name" title="<?= $name ?>"><?= $name ?></div>
        </div>
        <button class="undo-close-btn" onclick="dismissUndoToast()" title="Dismiss">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div class="undo-action-row">
        <span class="undo-countdown">Expires in <strong id="undoSecondsLeft">10</strong>s</span>
        <button id="undoActionBtn" onclick="executeUndo(<?= $record_id ?>)">
            <i class="fas fa-rotate-left"></i> Undo Deletion
        </button>
    </div>
    <div class="undo-progress-bar">
        <div id="undoProgress"></div>
    </div>
</div>

<script>
(function() {
    const UNDO_DURATION = 10; // seconds
    let _undoTimer = null;
    let _undoSecondsLeft = UNDO_DURATION;
    let _startTime = null;
    let _rafId = null;

    function showUndoToast() {
        const toast = document.getElementById('undoToast');
        if (!toast) return;
        // Slight delay so CSS transition is visible
        setTimeout(() => toast.classList.add('visible'), 80);
        _startTime = Date.now();
        tick();
    }

    function tick() {
        const elapsed = (Date.now() - _startTime) / 1000;
        const remaining = Math.max(0, UNDO_DURATION - elapsed);
        _undoSecondsLeft = Math.ceil(remaining);

        const pct = (remaining / UNDO_DURATION) * 100;
        const bar = document.getElementById('undoProgress');
        const counter = document.getElementById('undoSecondsLeft');

        if (bar) bar.style.width = pct + '%';
        if (counter) counter.textContent = _undoSecondsLeft;

        if (remaining > 0) {
            _rafId = requestAnimationFrame(tick);
        } else {
            dismissUndoToast();
        }
    }

    window.dismissUndoToast = function() {
        if (_rafId) cancelAnimationFrame(_rafId);
        const toast = document.getElementById('undoToast');
        if (toast) {
            toast.classList.remove('visible');
            setTimeout(() => toast.remove(), 400);
        }
        // Tell server to clear session via background fetch (fire-and-forget)
        fetch('api/clear_undo_session.php', { method: 'POST' }).catch(() => {});
    };

    window.executeUndo = function(recordId) {
        if (_rafId) cancelAnimationFrame(_rafId);
        const btn = document.getElementById('undoActionBtn');
        const label = document.getElementById('undoToastLabel');
        const icon = document.querySelector('#undoToast .undo-icon-ring i');

        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Restoring...'; }

        fetch('api/undo_deletion.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ record_id: recordId })
        })
        .then(r => r.json())
        .then(data => {
            const toast = document.getElementById('undoToast');
            const countdown = document.querySelector('.undo-countdown');
            const progressBar = document.getElementById('undoProgress');

            if (countdown) countdown.style.display = 'none';
            if (progressBar) progressBar.style.width = '100%';
            if (progressBar) progressBar.style.background = '#34d399';

            if (data.success) {
                if (toast) toast.classList.add('success');
                if (icon) { icon.className = 'fas fa-check-circle'; }
                if (label) label.textContent = 'Restored!';
                if (btn) { btn.innerHTML = '<i class="fas fa-check"></i> Done'; btn.style.background = '#10b981'; }

                const cleanUrl = new URL(window.location.href);
                cleanUrl.searchParams.delete('delete');
                cleanUrl.searchParams.delete('bulk_delete');

                // Reload the cleaned page after short success display
                setTimeout(() => location.replace(cleanUrl.toString()), 1400);
            } else {
                if (label) label.textContent = 'Restore failed!';
                if (btn) { btn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Error'; btn.style.background = '#dc2626'; btn.disabled = false; }
                alert(data.message || 'Could not restore the record. Please try again.');
            }
        })
        .catch(() => {
            const btn = document.getElementById('undoActionBtn');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-rotate-left"></i> Retry'; }
            alert('Network error. Please try again.');
        });
    };

    // Kick off toast on DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', showUndoToast);
    } else {
        showUndoToast();
    }
})();
</script>
