<?php
/**
 * Global Toast Notification System
 * Include this on every page. Call showToast(type, title, message) from JS.
 * Types: 'success', 'error', 'info', 'warning'
 */
?>
<!-- Toast Container -->
<div class="toast-container-global" id="globalToastContainer"></div>

<style>
.toast-container-global {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 99999;
    display: flex;
    flex-direction: column;
    gap: 10px;
    pointer-events: none;
}
.g-toast {
    pointer-events: auto;
    min-width: 340px;
    max-width: 480px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px 18px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.12);
    display: flex;
    align-items: flex-start;
    gap: 12px;
    transform: translateX(120%);
    animation: gToastIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    position: relative;
    overflow: hidden;
}
.g-toast.g-toast-dismiss {
    animation: gToastOut 0.3s ease-in forwards;
}
@keyframes gToastIn { to { transform: translateX(0); } }
@keyframes gToastOut { to { transform: translateX(120%); opacity: 0; } }

.g-toast-icon {
    width: 36px; height: 36px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; flex-shrink: 0;
}
.g-toast-success .g-toast-icon { background: rgba(16,185,129,0.12); color: #059669; }
.g-toast-error   .g-toast-icon { background: rgba(239,68,68,0.12);  color: #dc2626; }
.g-toast-info    .g-toast-icon { background: rgba(59,130,246,0.12);  color: #2563eb; }
.g-toast-warning .g-toast-icon { background: rgba(245,158,11,0.12); color: #d97706; }

.g-toast-body { flex: 1; min-width: 0; }
.g-toast-title { font-size: 0.9rem; font-weight: 600; color: #1e293b; margin-bottom: 2px; }
.g-toast-msg   { font-size: 0.8rem; color: #64748b; line-height: 1.4; }
.g-toast-close  {
    background: none; border: none; color: #94a3b8; font-size: 1.2rem;
    cursor: pointer; padding: 0; line-height: 1; flex-shrink: 0;
}
.g-toast-close:hover { color: #1e293b; }

.g-toast-progress {
    position: absolute; bottom: 0; left: 0; height: 3px;
    border-radius: 0 0 12px 12px;
    animation: gToastTimer 5s linear forwards;
}
.g-toast-success .g-toast-progress { background: #10b981; }
.g-toast-error   .g-toast-progress { background: #ef4444; }
.g-toast-info    .g-toast-progress { background: #3b82f6; }
.g-toast-warning .g-toast-progress { background: #f59e0b; }

@keyframes gToastTimer { from { width: 100%; } to { width: 0%; } }
</style>

<script>
function showToast(type, title, message, duration) {
    duration = duration || 5000;
    var container = document.getElementById('globalToastContainer');
    if (!container) return;
    var icons = { success:'fa-circle-check', error:'fa-circle-xmark', info:'fa-circle-info', warning:'fa-triangle-exclamation' };
    var el = document.createElement('div');
    el.className = 'g-toast g-toast-' + type;
    el.innerHTML =
        '<div class="g-toast-icon"><i class="fa-solid ' + (icons[type]||'fa-circle-info') + '"></i></div>' +
        '<div class="g-toast-body"><div class="g-toast-title">' + title + '</div><div class="g-toast-msg">' + message + '</div></div>' +
        '<button class="g-toast-close" onclick="dismissToast(this)">&times;</button>' +
        '<div class="g-toast-progress" style="animation-duration:' + duration + 'ms"></div>';
    container.appendChild(el);
    setTimeout(function() { dismissToast(el.querySelector('.g-toast-close')); }, duration);
}
function dismissToast(btn) {
    var toast = btn.closest ? btn.closest('.g-toast') : btn.parentElement;
    if (!toast || toast.classList.contains('g-toast-dismiss')) return;
    toast.classList.add('g-toast-dismiss');
    setTimeout(function() { if (toast.parentElement) toast.remove(); }, 350);
}
</script>
