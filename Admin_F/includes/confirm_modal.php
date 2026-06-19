<?php
/**
 * Reusable Confirmation Modal for destructive actions.
 * Usage: <button onclick="showConfirmModal({title, message, confirmText, formId})">
 * Set confirmText to the word user must type (e.g. 'DELETE').
 * Set formId to the ID of the hidden <form> that should be submitted on confirm.
 */
?>
<!-- Confirmation Modal Overlay -->
<div class="confirm-modal-overlay" id="confirmModalOverlay" style="display:none;">
    <div class="confirm-modal-box">
        <button class="confirm-modal-close" onclick="hideConfirmModal()">&times;</button>
        <div class="confirm-modal-icon">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <h3 id="confirmModalTitle">Are you sure?</h3>
        <p id="confirmModalMessage" class="confirm-modal-msg">This action cannot be undone.</p>
        <div class="confirm-modal-input-group" id="confirmModalInputGroup">
            <label>Type <strong id="confirmModalWord">DELETE</strong> to confirm:</label>
            <input type="text" id="confirmModalInput" autocomplete="off" spellcheck="false" placeholder="">
        </div>
        <div class="confirm-modal-actions">
            <button class="confirm-modal-btn cancel" onclick="hideConfirmModal()">Cancel</button>
            <button class="confirm-modal-btn danger" id="confirmModalSubmit" disabled>Confirm</button>
        </div>
    </div>
</div>

<style>
.confirm-modal-overlay {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.45); backdrop-filter: blur(4px);
    z-index: 100000; display: flex; align-items: center; justify-content: center;
    padding: 20px;
}
.confirm-modal-box {
    background: #fff; border-radius: 16px; max-width: 440px; width: 100%;
    padding: 32px; position: relative; text-align: center;
    box-shadow: 0 20px 60px rgba(0,0,0,0.18);
    animation: cmFadeIn 0.25s ease-out;
}
@keyframes cmFadeIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }

.confirm-modal-close {
    position: absolute; top: 12px; right: 16px;
    background: none; border: none; font-size: 1.5rem; color: #94a3b8;
    cursor: pointer; line-height: 1;
}
.confirm-modal-close:hover { color: #1e293b; }

.confirm-modal-icon {
    width: 56px; height: 56px; border-radius: 50%;
    background: #fef2f2; color: #dc2626;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.5rem; margin: 0 auto 16px;
}
.confirm-modal-box h3 { font-size: 1.15rem; font-weight: 700; color: #1e293b; margin: 0 0 8px; }
.confirm-modal-msg { font-size: 0.88rem; color: #64748b; margin: 0 0 20px; line-height: 1.5; }

.confirm-modal-input-group { text-align: left; margin-bottom: 20px; }
.confirm-modal-input-group label { font-size: 0.82rem; color: #475569; display: block; margin-bottom: 6px; }
.confirm-modal-input-group input {
    width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 8px;
    font-size: 0.9rem; color: #1e293b; transition: border-color 0.2s;
}
.confirm-modal-input-group input:focus { outline: none; border-color: #dc2626; }

.confirm-modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
.confirm-modal-btn {
    padding: 10px 22px; border-radius: 8px; font-size: 0.88rem; font-weight: 600;
    border: none; cursor: pointer; transition: all 0.2s;
}
.confirm-modal-btn.cancel { background: #f1f5f9; color: #475569; }
.confirm-modal-btn.cancel:hover { background: #e2e8f0; }
.confirm-modal-btn.danger {
    background: #dc2626; color: #fff; opacity: 0.5; pointer-events: none;
}
.confirm-modal-btn.danger:not(:disabled) { opacity: 1; pointer-events: auto; }
.confirm-modal-btn.danger:not(:disabled):hover { background: #b91c1c; }
</style>

<script>
var _cmFormId = null;
var _cmWord = 'DELETE';

function showConfirmModal(opts) {
    var overlay = document.getElementById('confirmModalOverlay');
    document.getElementById('confirmModalTitle').innerHTML = opts.title || 'Are you sure?';
    document.getElementById('confirmModalMessage').innerHTML = opts.message || 'This action cannot be undone.';
    _cmWord = (opts.confirmText || 'DELETE').toUpperCase();
    document.getElementById('confirmModalWord').textContent = _cmWord;
    _cmFormId = opts.formId || null;
    var input = document.getElementById('confirmModalInput');
    input.value = '';
    input.placeholder = 'Type ' + _cmWord + ' here';
    document.getElementById('confirmModalSubmit').disabled = true;
    overlay.style.display = 'flex';
    setTimeout(function() { input.focus(); }, 100);
}

function hideConfirmModal() {
    document.getElementById('confirmModalOverlay').style.display = 'none';
    _cmFormId = null;
}

document.getElementById('confirmModalInput').addEventListener('input', function() {
    var btn = document.getElementById('confirmModalSubmit');
    btn.disabled = this.value.toUpperCase() !== _cmWord;
});

document.getElementById('confirmModalSubmit').addEventListener('click', function() {
    if (_cmFormId) {
        var form = document.getElementById(_cmFormId);
        if (form) form.submit();
    }
    hideConfirmModal();
});

// Close on overlay click
document.getElementById('confirmModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) hideConfirmModal();
});

// Close on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') hideConfirmModal();
});
</script>
