<!-- Reusable Delete / Action Confirmation Modal -->
<style>
    #deleteConfirmModal .modal-content {
        border: none;
        border-radius: 20px;
        overflow: hidden;
    }
    [data-theme="dark"] #deleteConfirmModal .modal-content {
        background: #1a2234 !important;
        color: #e2e8f0;
    }
    [data-theme="dark"] #deleteConfirmModal .modal-body p { color: #94a3b8 !important; }
    [data-theme="dark"] #deleteConfirmModal .btn-dm-cancel {
        background: #1e293b !important;
        border-color: rgba(255,255,255,0.12) !important;
        color: #94a3b8 !important;
    }
    [data-theme="dark"] #deleteConfirmModal .btn-dm-cancel:hover {
        background: #334155 !important;
        color: #e2e8f0 !important;
    }
    #deleteConfirmModal .modal-dialog { animation: dmSlide 0.28s cubic-bezier(0.34,1.56,0.64,1); }
    @keyframes dmSlide {
        from { opacity: 0; transform: translateY(24px) scale(0.96); }
        to   { opacity: 1; transform: translateY(0) scale(1); }
    }
    /* Backdrop blur */
    #deleteConfirmModal.show ~ .modal-backdrop { backdrop-filter: blur(4px); }
</style>

<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content shadow-lg">
            <div class="modal-body" style="padding:40px 30px 20px;text-align:center;">
                <div id="dmIconRing"
                    style="width:72px;height:72px;border-radius:50%;background:#fee2e2;display:inline-flex;align-items:center;justify-content:center;margin-bottom:20px;box-shadow:0 8px 24px rgba(220,53,69,0.25);transition:background 0.2s;">
                    <i id="dmIcon" class="fas fa-trash-alt" style="font-size:1.8rem;color:#dc3545;"></i>
                </div>
                <h5 class="fw-bold mb-2" id="deleteModalTitle">Delete Item?</h5>
                <p class="text-muted mb-0" style="font-size:0.9rem;line-height:1.5;" id="deleteModalMsg">This action cannot be undone.</p>
            </div>
            <div class="modal-footer" style="border-top:none;padding:10px 30px 30px;justify-content:center;gap:12px;">
                <button type="button" class="btn btn-dm-cancel" data-bs-dismiss="modal"
                    style="border-radius:12px;padding:10px 24px;font-weight:600;border:1.5px solid #dee2e6;color:#495057;background:white;transition:all 0.2s;">
                    <i class="fas fa-times me-1"></i> Cancel
                </button>
                <button type="button" id="deleteConfirmBtn" class="btn"
                    style="border-radius:12px;padding:10px 24px;font-weight:600;background:linear-gradient(135deg,#dc3545,#b02a37);color:white;border:none;box-shadow:0 4px 14px rgba(220,53,69,0.35);transition:all 0.2s;">
                    <i class="fas fa-trash me-1" id="dmBtnIcon"></i>
                    <span id="dmBtnLabel">Delete</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    // Internal state
    let _dmHref   = null;
    let _dmFormId = null;
    let _dmHiddenField = null;
    let _dmBsModal = null;

    /**
     * showDeleteModal(href, title, message)
     *   For single-record href deletions (existing usage — unchanged API).
     */
    function showDeleteModal(href, title, message) {
        _dmHref       = href;
        _dmFormId     = null;
        _dmHiddenField = null;

        document.getElementById('deleteModalTitle').textContent  = title   || 'Delete Item?';
        document.getElementById('deleteModalMsg').innerHTML      = message || 'This action cannot be undone.';
        document.getElementById('dmIcon').className              = 'fas fa-trash-alt';
        document.getElementById('dmIcon').style.color            = '#dc3545';
        document.getElementById('dmIconRing').style.background   = '#fee2e2';
        document.getElementById('dmIconRing').style.boxShadow    = '0 8px 24px rgba(220,53,69,0.25)';
        document.getElementById('dmBtnLabel').textContent        = 'Delete';
        document.getElementById('dmBtnIcon').className          = 'fas fa-trash me-1';

        _dmBsModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
        _dmBsModal.show();
    }

    /**
     * showActionModal(formId, hiddenFieldId, title, message, btnLabel, icon, color)
     *   For form-submit confirmations (e.g. bulk delete).
     *   color: hex/rgb for icon & ring tint.
     */
    function showActionModal(formId, hiddenFieldId, title, message, btnLabel, icon, color) {
        _dmHref        = null;
        _dmFormId      = formId;
        _dmHiddenField = hiddenFieldId;
        const tint     = color || '#dc3545';
        const rgb      = tint; // use as-is for shadow

        document.getElementById('deleteModalTitle').textContent  = title   || 'Confirm Action';
        document.getElementById('deleteModalMsg').innerHTML      = message || 'Are you sure?';
        document.getElementById('dmIcon').className              = 'fas ' + (icon || 'fa-trash-alt');
        document.getElementById('dmIcon').style.color            = tint;
        document.getElementById('dmIconRing').style.background   = tint + '22';
        document.getElementById('dmIconRing').style.boxShadow    = '0 8px 24px ' + tint + '44';
        document.getElementById('dmBtnLabel').textContent        = btnLabel || 'Confirm';
        document.getElementById('dmBtnIcon').className          = 'fas ' + (icon || 'fa-check') + ' me-1';
        document.getElementById('deleteConfirmBtn').style.background = 'linear-gradient(135deg,' + tint + ',#b02a37)';

        _dmBsModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
        _dmBsModal.show();
    }

    document.getElementById('deleteConfirmBtn').addEventListener('click', function () {
        if (_dmHref) {
            window.location.href = _dmHref;
        } else if (_dmFormId && _dmHiddenField) {
            document.getElementById(_dmHiddenField).value = '1';
            document.getElementById(_dmFormId).submit();
        }
        if (_dmBsModal) _dmBsModal.hide();
    });
</script>