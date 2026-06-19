/**
 * Shared Knowledge Base admin actions (enrich, reindex, pipeline progress).
 */
(function (global) {
    'use strict';

    var DEFAULT_LIMIT = 100;
    var _pollTimer = null;

    function proxyPost(action, body) {
        var opts = { method: 'POST', headers: { 'Content-Type': 'application/json' } };
        if (body) opts.body = JSON.stringify(body);
        return fetch('admin_api_proxy.php?action=' + encodeURIComponent(action), opts)
            .then(function (r) {
                if (!r.ok) throw new Error('Server returned ' + r.status);
                return r.json();
            });
    }

    function proxyGet(action) {
        return fetch('admin_api_proxy.php?action=' + encodeURIComponent(action))
            .then(function (r) {
                if (!r.ok) throw new Error('Server returned ' + r.status);
                return r.json();
            });
    }

    function setButtonLoading(btn, loadingHtml) {
        if (!btn) return function () {};
        btn.disabled = true;
        btn._kbOrigHtml = btn._kbOrigHtml || btn.innerHTML;
        btn.innerHTML = loadingHtml;
        return function restore() {
            btn.disabled = false;
            btn.innerHTML = btn._kbOrigHtml;
        };
    }

    function showKbResult(title, message, isError) {
        if (typeof showToast === 'function') {
            showToast(isError ? 'error' : 'success', title, message, 8000);
        } else {
            alert((isError ? '❌ ' : '✅ ') + title + '\n' + message);
        }
    }

    function getSelectedScrapedIds() {
        var checked = document.querySelectorAll('.bulk-select:checked');
        return Array.from(checked).map(function (c) { return parseInt(c.value, 10); }).filter(function (id) {
            return !isNaN(id) && id > 0;
        });
    }

    function ensurePipelineModal() {
        var el = document.getElementById('kbPipelineModal');
        if (el) return el;
        el = document.createElement('div');
        el.id = 'kbPipelineModal';
        el.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:10000;align-items:center;justify-content:center;';
        el.innerHTML = '<div style="background:#fff;border-radius:12px;padding:24px;max-width:480px;width:90%;box-shadow:0 20px 50px rgba(0,0,0,0.25);">' +
            '<h3 style="margin:0 0 12px;font-size:1.1rem;"><i class="fa-solid fa-layer-group"></i> KB Pipeline</h3>' +
            '<p id="kbPipelinePhase" style="margin:0 0 8px;color:#475569;">Starting…</p>' +
            '<div style="background:#e2e8f0;border-radius:8px;height:12px;overflow:hidden;margin:12px 0;">' +
            '<div id="kbPipelineBar" style="height:100%;width:0%;background:linear-gradient(90deg,#0369a1,#7c3aed);transition:width 0.3s;"></div></div>' +
            '<p id="kbPipelineDetail" style="font-size:0.85rem;color:#64748b;margin:0 0 16px;">—</p>' +
            '<button type="button" class="btn btn-secondary btn-sm" id="kbPipelineCancelBtn">Cancel</button>' +
            '</div>';
        document.body.appendChild(el);
        el.style.display = 'flex';
        document.getElementById('kbPipelineCancelBtn').onclick = function () {
            proxyPost('pipeline_cancel', {}).then(function () {
                document.getElementById('kbPipelinePhase').textContent = 'Cancelling…';
            });
        };
        return el;
    }

    function hidePipelineModal() {
        var el = document.getElementById('kbPipelineModal');
        if (el) el.style.display = 'none';
        if (_pollTimer) {
            clearInterval(_pollTimer);
            _pollTimer = null;
        }
    }

    function updatePipelineUI(job) {
        var phase = document.getElementById('kbPipelinePhase');
        var bar = document.getElementById('kbPipelineBar');
        var detail = document.getElementById('kbPipelineDetail');
        if (!phase) return;
        var pct = job.progress_pct || 0;
        bar.style.width = pct + '%';
        phase.textContent = 'Phase: ' + (job.phase || job.status || '…');
        var parts = [];
        if (job.enriched != null) parts.push('Enriched: ' + job.enriched);
        if (job.failed != null) parts.push('Failed: ' + job.failed);
        if (job.remaining_pending != null) parts.push('Pending left: ' + job.remaining_pending);
        if (job.rounds) parts.push('Batches: ' + job.rounds);
        detail.textContent = parts.join(' · ') || 'Working…';
    }

    function pollPipelineUntilDone(onDone) {
        ensurePipelineModal();
        document.getElementById('kbPipelineModal').style.display = 'flex';
        if (_pollTimer) clearInterval(_pollTimer);
        _pollTimer = setInterval(function () {
            proxyGet('pipeline_status').then(function (job) {
                updatePipelineUI(job);
                if (job.status === 'done') {
                    hidePipelineModal();
                    showKbResult('Pipeline complete',
                        'Enriched ' + (job.enriched || 0) + ', index vectors: ' + ((job.index && job.index.count) || '—'),
                        false);
                    if (onDone) onDone(true, job);
                } else if (job.status === 'cancelled') {
                    hidePipelineModal();
                    showKbResult('Cancelled', 'Pipeline stopped.', false);
                    if (onDone) onDone(false, job);
                } else if (job.status === 'error') {
                    hidePipelineModal();
                    showKbResult('Pipeline error', job.detail || 'Unknown error', true);
                    if (onDone) onDone(false, job);
                }
            }).catch(function (e) {
                hidePipelineModal();
                showKbResult('Pipeline poll failed', e.message, true);
                if (onDone) onDone(false, null);
            });
        }, 2000);
    }

    function startPipelineJob(btn, payload, options) {
        options = options || {};
        var restore = setButtonLoading(btn, '<i class="fa-solid fa-spinner fa-spin"></i> Starting…');
        return proxyPost('pipeline_start', payload)
            .then(function (d) {
                if (d.status === 'error') throw new Error(d.detail || 'Could not start pipeline');
                restore();
                pollPipelineUntilDone(function (ok) {
                    if (ok && options.reloadOnSuccess) location.reload();
                });
                return d;
            })
            .catch(function (e) {
                restore();
                showKbResult('Start failed', e.message, true);
                throw e;
            });
    }

    function enrichKB(btn, options) {
        options = options || {};
        var payload = { enrich: true, reindex: false, batch_size: options.limit || DEFAULT_LIMIT };
        if (options.scraped_ids && options.scraped_ids.length) {
            payload.scraped_ids = options.scraped_ids;
            return startPipelineJob(btn, payload, options);
        }
        var restore = setButtonLoading(btn, '<i class="fa-solid fa-spinner fa-spin"></i> Enriching…');
        return proxyPost('enrich_kb', {
            only_pending: options.only_pending !== false,
            limit: options.limit || DEFAULT_LIMIT,
            use_llm: options.use_llm !== false,
            scraped_ids: options.scraped_ids,
        })
            .then(function (d) {
                if (d.status === 'error') throw new Error(d.detail || 'Enrichment failed');
                showKbResult('Enrichment complete',
                    'Processed ' + (d.processed || 0) + ': ' + (d.enriched || 0) + ' enriched.', false);
                if (options.reloadOnSuccess) location.reload();
                return d;
            })
            .catch(function (e) { showKbResult('Enrichment failed', e.message, true); throw e; })
            .finally(function () { restore(); });
    }

    function enrichAllPendingKB(btn, options) {
        options = options || {};
        if (!confirm('Run LLM enrichment on ALL pending pages, then optionally reindex?')) return Promise.resolve();
        return startPipelineJob(btn, {
            enrich: true,
            reindex: !!options.reindex,
            batch_size: options.limit || DEFAULT_LIMIT,
            max_rounds: options.max_rounds || 200,
        }, options);
    }

    function enrichSelectedKB(btn, options) {
        var ids = (options && options.scraped_ids) || getSelectedScrapedIds();
        if (!ids.length) {
            showKbResult('No selection', 'Select at least one row.', true);
            return Promise.resolve();
        }
        return startPipelineJob(btn, { enrich: true, reindex: false, scraped_ids: ids }, options || {});
    }

    function reindexKB(btn, options) {
        options = options || {};
        var restore = setButtonLoading(btn, '<i class="fa-solid fa-spinner fa-spin"></i> Rebuilding…');
        return proxyPost('reindex_kb')
            .then(function (d) {
                if (options.reloadConfig !== false) {
                    return proxyPost('reload_config').catch(function () {}).then(function () { return d; });
                }
                return d;
            })
            .then(function (d) {
                var msg = (d.count || 0) + ' vectors (' + (d.entity_chunks || 0) + ' entity, ' +
                    (d.scraped_chunks || 0) + ' scraped, ' + (d.campus_chunks || 0) + ' campus)';
                showKbResult('Index rebuilt', msg, false);
                if (options.reloadOnSuccess) location.reload();
                return d;
            })
            .catch(function (e) { showKbResult('Reindex failed', e.message, true); throw e; })
            .finally(function () { restore(); });
    }

    function enrichAndReindexKB(btn, options) {
        options = options || {};
        var payload = {
            enrich: true,
            reindex: true,
            batch_size: options.limit || DEFAULT_LIMIT,
            max_rounds: options.max_rounds || 200,
        };
        if (options.scraped_ids && options.scraped_ids.length) {
            payload.scraped_ids = options.scraped_ids;
        }
        if (!options.scraped_ids || !options.scraped_ids.length) {
            if (!confirm('Enrich ALL pending pages and rebuild the search index?')) return Promise.resolve();
        }
        return startPipelineJob(btn, payload, options);
    }

    function reEnrichPage(scrapedId, btn) {
        return enrichKB(btn, { scraped_ids: [scrapedId], reloadOnSuccess: true });
    }

    function fetchEnrichStatus() {
        return proxyGet('enrich_kb_status');
    }

    global.enrichKB = enrichKB;
    global.enrichAllPendingKB = enrichAllPendingKB;
    global.enrichSelectedKB = enrichSelectedKB;
    global.reindexKB = reindexKB;
    global.enrichAndReindexKB = enrichAndReindexKB;
    global.reEnrichPage = reEnrichPage;
    global.fetchEnrichStatus = fetchEnrichStatus;
    global.getSelectedScrapedIds = getSelectedScrapedIds;
    global.cancelPipeline = function () { return proxyPost('pipeline_cancel', {}); };
})(typeof window !== 'undefined' ? window : this);
