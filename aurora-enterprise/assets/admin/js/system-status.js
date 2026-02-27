(() => {
  const root = document.getElementById('aurora-system-status-root');
  if (!root || !window.auroraSystemStatus) return;

  const apiBase = window.auroraSystemStatus.restBase || '';
  const nonce = window.auroraSystemStatus.nonce || '';
  const pollMs = window.auroraSystemStatus.pollMs || 5000;

  const state = {
    health: { status: 'loading', reasons: [] },
    queues: {},
    snapshots: {},
    feed: {},
    config: {},
    last_runs: [],
    error: '',
  };

  const esc = (v) => String(v ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

  const badge = (status) => {
    const cls = status === 'ERROR' ? 'aurora-badge error' : status === 'WARNING' ? 'aurora-badge warn' : 'aurora-badge ok';
    return `<span class="${cls}">${esc(status || 'UNKNOWN')}</span>`;
  };

  const renderRuns = () => {
    if (!Array.isArray(state.last_runs) || state.last_runs.length === 0) {
      return '<p class="aurora-muted">Nessuna run disponibile.</p>';
    }
    const rows = state.last_runs.map((r) => `
      <tr>
        <td>${esc(r.id)}</td>
        <td>${esc(r.op_key)}</td>
        <td>${esc(r.status)}</td>
        <td>${esc(r.started_at || '—')}</td>
        <td>${esc(r.finished_at || '—')}</td>
        <td>${esc(r.message || r.error || '—')}</td>
      </tr>
    `).join('');
    return `
      <table class="aurora-table aurora-run-table">
        <thead><tr><th>ID</th><th>Op</th><th>Status</th><th>Started</th><th>Finished</th><th>Msg</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    `;
  };

  const queueCard = () => {
    const q = state.queues || {};
    const channels = ['price','stock','visibility','feed','dead'];
    const rows = channels.map((ch) => {
      const v = q[ch] ?? 0;
      const warn = (ch === 'dead' && v > 0) || (ch !== 'dead' && v > 10000);
      return `<tr class="${warn ? 'aurora-row-warn' : ''}"><td>${ch}</td><td>${v}</td></tr>`;
    }).join('');
    return `
      <div class="aurora-card">
        <div class="aurora-card-h"><div class="aurora-card-title">Queues</div></div>
        <table class="aurora-table">
          <thead><tr><th>Channel</th><th>Pending/Dead</th></tr></thead>
          <tbody>${rows}</tbody>
        </table>
        <div class="aurora-actions">
          <button class="button button-primary" data-op="sweep_leases">Sweep leases</button>
        </div>
      </div>
    `;
  };

  const snapshotCard = () => {
    const snap = state.snapshots || {};
    const versions = snap.versions || {};
    const coverage = snap.coverage || {};
    const rows = Object.keys(versions).map((ch) => {
      const cov = coverage[ch] || {};
      return `<tr><td>${esc(ch)}</td><td>${esc(versions[ch])}</td><td>${esc(cov.distinct ?? 0)}</td></tr>`;
    }).join('');
    return `
      <div class="aurora-card">
        <div class="aurora-card-h">
          <div class="aurora-card-title">Snapshot cut</div>
          ${badge(snap.aligned ? 'HEALTHY' : 'WARNING')}
        </div>
        <table class="aurora-table">
          <thead><tr><th>Channel</th><th>Version</th><th>Distinct</th></tr></thead>
          <tbody>${rows}</tbody>
        </table>
        <div class="aurora-actions">
          <button class="button" data-op="rebuild" data-indexer="all">Rebuild all</button>
          <button class="button" data-op="rebuild" data-indexer="price">Rebuild price</button>
          <button class="button" data-op="rebuild" data-indexer="stock">Rebuild stock</button>
          <button class="button" data-op="rebuild" data-indexer="visibility">Rebuild visibility</button>
        </div>
      </div>
    `;
  };

  const feedCard = () => {
    const feed = state.feed || {};
    return `
      <div class="aurora-card">
        <div class="aurora-card-h"><div class="aurora-card-title">Last feed</div></div>
        <div class="aurora-kv">
          <div><strong>File:</strong> ${esc(feed.file_name || feed.file || '-')}</div>
          <div><strong>Rows:</strong> ${esc(feed.rows ?? '-')}</div>
          <div><strong>Snapshot v:</strong> ${esc(feed.snapshot_version ?? '-')}</div>
          <div><strong>UTC:</strong> ${esc(feed.generated_at_utc ?? '-')}</div>
          <div><strong>Size:</strong> ${esc(feed.size_bytes ?? '-')}</div>
        </div>
        <div class="aurora-actions">
          <button class="button" data-op="feed_enqueue">Enqueue feed</button>
          <button class="button button-primary" data-op="feed_run">Run feed</button>
        </div>
      </div>
    `;
  };

  const healthCard = () => {
    const h = state.health || { status: 'UNKNOWN', reasons: [] };
    const reasons = Array.isArray(h.reasons) && h.reasons.length
      ? `<ul class="aurora-reasons">${h.reasons.map(r => `<li>${esc(r)}</li>`).join('')}</ul>`
      : '<div class="aurora-muted">Nessun warning.</div>';
    return `
      <div class="aurora-card">
        <div class="aurora-card-h">
          <div class="aurora-card-title">Health</div>
          ${badge(h.status)}
        </div>
        ${reasons}
        <div class="aurora-muted">Aggiornato: ${esc(state.generated_at_utc || '-')}</div>
      </div>
    `;
  };

  const render = () => {
    if (state.error) {
      root.innerHTML = `<div class="aurora-notice error">${esc(state.error)}</div>`;
      return;
    }
    const cards = `
      <div class="aurora-grid">
        ${healthCard()}
        ${queueCard()}
        ${snapshotCard()}
        ${feedCard()}
      </div>
    `;
    const runs = `
      <div class="aurora-card">
        <div class="aurora-card-h"><div class="aurora-card-title">Last runs</div></div>
        ${renderRuns()}
      </div>
    `;
    root.innerHTML = cards + runs;

    root.querySelectorAll('[data-op]').forEach((btn) => {
      btn.addEventListener('click', async (e) => {
        const op = e.currentTarget.getAttribute('data-op');
        const indexer = e.currentTarget.getAttribute('data-indexer');
        const payload = {};
        if (op === 'rebuild' && indexer) payload.indexer = indexer;
        if (op === 'feed_run') { payload.batch = 25; payload.max_loops = 2000; }
        if (op === 'feed_enqueue') { payload.chunk_size = 1000; }
        btn.disabled = true;
        try {
          await trigger(op, payload);
          await refresh();
        } catch (err) {
          alert(err.message || String(err));
        } finally {
          btn.disabled = false;
        }
      });
    });
  };

  const getStatus = async () => {
    const res = await fetch(`${apiBase}system-status`, {
      headers: { 'X-WP-Nonce': nonce },
    });
    if (!res.ok) throw new Error(`GET system-status failed: ${res.status}`);
    return res.json();
  };

  const trigger = async (op_key, payload = {}) => {
    const res = await fetch(`${apiBase}trigger`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
      body: JSON.stringify({ op_key, payload }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      throw new Error(json?.message || `Trigger failed: ${res.status}`);
    }
    return json;
  };

  const refresh = async () => {
    try {
      const data = await getStatus();
      Object.assign(state, data);
      state.error = '';
    } catch (err) {
      state.error = err.message || String(err);
    } finally {
      render();
    }
  };

  refresh();
  setInterval(refresh, pollMs);
})();
