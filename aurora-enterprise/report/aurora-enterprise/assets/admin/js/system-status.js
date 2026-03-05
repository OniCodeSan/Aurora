(() => {
  const root = document.getElementById('aurora-system-status-root');
  const noticesRoot = document.getElementById('aurora-system-status-notices');
  const refreshBtn = document.getElementById('aurora-system-status-refresh');
  const toggleAutoBtn = document.getElementById('aurora-system-status-toggle-auto');
  const nextPollNode = document.getElementById('aurora-system-status-next-poll-seconds');
  const lastUpdateNode = document.getElementById('aurora-system-status-last-update');
  const overlay = document.getElementById('aurora-system-status-overlay');

  if (!root || !window.auroraSystemStatusUi) return;

  const cfg = window.auroraSystemStatusUi;
  const restConfig = (() => {
    const raw = String(cfg.restBase || '');
    try {
      const parsed = new URL(raw, window.location.href);
      const restRoute = parsed.searchParams.get('rest_route');
      if (restRoute !== null) {
        return {
          mode: 'query',
          endpointPath: parsed.pathname || '/',
          baseRoute: String(restRoute || '/aurora/v1/').replace(/\/?$/, '/'),
        };
      }
      return {
        mode: 'path',
        basePath: String(parsed.pathname || '/wp-json/').replace(/\/?$/, '/'),
      };
    } catch (e) {
      const hasQueryRoute = raw.includes('rest_route=');
      if (hasQueryRoute) {
        const parts = raw.replace(/^https?:\/\/[^/]+/i, '').split('?');
        const endpointPath = parts[0] || '/';
        const params = new URLSearchParams(parts[1] || '');
        return {
          mode: 'query',
          endpointPath,
          baseRoute: String(params.get('rest_route') || '/aurora/v1/').replace(/\/?$/, '/'),
        };
      }
      return {
        mode: 'path',
        basePath: raw.replace(/^https?:\/\/[^/]+/i, '').replace(/\/?$/, '/'),
      };
    }
  })();

  const buildRestUrl = (path) => {
    const cleanPath = String(path || '').replace(/^\/+/, '');
    if (restConfig.mode === 'query') {
      const q = new URLSearchParams();
      q.set('rest_route', `${restConfig.baseRoute}${cleanPath}`);
      return `${restConfig.endpointPath}?${q.toString()}`;
    }
    return `${restConfig.basePath}${cleanPath}`;
  };
  const nonce = String(cfg.nonce || '');
  const routes = cfg.routes || {};
  const basePollMs = Number(cfg.pollMs || 5000);
  const maxBackoffMs = Number(cfg.maxBackoffMs || 60000);

  const state = {
    data: null,
    autoRefresh: true,
    uiLocked: false,
    authFailures: 0,
    pollDelayMs: basePollMs,
    pollTimer: null,
    countdownTimer: null,
    nextPollAt: 0,
    prevErrors24h: null,
    filters: {
      range: '24h',
      op: '',
      severity: '',
    },
    detailsOpen: {
      alerts: true,
      lastIncidentRaw: false,
      diagnostics: false,
    },
  };

  const esc = (v) => String(v ?? '').replace(/[&<>\"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const route = (key) => String(routes[key] || '').replace(/^\/+/, '');

  const severityWeight = (sev) => {
    if (sev === 'ERROR') return 3;
    if (sev === 'WARN') return 2;
    return 1;
  };

  const severityClass = (sev) => {
    if (sev === 'ERROR') return 'is-error';
    if (sev === 'WARN') return 'is-warn';
    if (sev === 'HEALTHY' || sev === 'OK') return 'is-healthy';
    return 'is-info';
  };

  const setHealthBadge = (status) => {
    const node = document.getElementById('aurora-system-status-health-badge');
    if (!node) return;
    const normalized = String(status || 'WARN');
    node.textContent = normalized;
    node.className = 'aurora-severity-pill';
    node.classList.add(severityClass(normalized));
  };

  const tsToDate = (value) => {
    if (!value) return null;
    const d = new Date(String(value).replace(' ', 'T') + 'Z');
    return Number.isNaN(d.getTime()) ? null : d;
  };

  const formatDelta = (value) => {
    const d = tsToDate(value);
    if (!d) return 'n/a';
    const sec = Math.max(0, Math.floor((Date.now() - d.getTime()) / 1000));
    if (sec < 60) return `${sec}s fa`;
    const min = Math.floor(sec / 60);
    if (min < 60) return `${min}m fa`;
    const hr = Math.floor(min / 60);
    const rem = min % 60;
    return `${hr}h ${rem}m fa`;
  };

  const summarizeIncident = (rawText) => {
    const text = String(rawText || '').trim();
    if (!text) return '-';
    if (text.startsWith('Snapshot mismatch:')) {
      return 'Snapshot non allineati tra tabelle feed/snapshot.';
    }
    if (text.startsWith('No products selected')) {
      return 'Nessun prodotto eleggibile per l’operazione richiesta.';
    }
    if (text.length > 180) {
      return `${text.slice(0, 177)}...`;
    }
    return text;
  };

  const normalizeError = (status, payload) => {
    const code = payload?.code || 'request_failed';
    const retryAfter = Number(payload?.data?.retry_after || 0);
    let message = payload?.message || `Errore HTTP ${status}`;
    if (status === 401) message = 'Sessione scaduta. Devi accedere di nuovo.';
    if (status === 403) message = 'Permessi insufficienti o nonce non valido.';
    if (status === 429) message = 'Rate limit attivo. Attendi prima di riprovare.';
    return { status, code, retryAfter, message };
  };

  const showNotice = (type, message) => {
    if (!noticesRoot) return;
    noticesRoot.innerHTML = `<div class="notice notice-${type} is-dismissible"><p>${esc(message)}</p></div>`;
  };

  const clearNotice = () => {
    if (noticesRoot) noticesRoot.innerHTML = '';
  };

  const lockUi = (message) => {
    state.uiLocked = true;
    clearTimeout(state.pollTimer);
    clearInterval(state.countdownTimer);
    root.classList.add('aurora-system-status-locked');
    if (overlay) overlay.hidden = false;
    showNotice('error', message || 'Sessione scaduta. Apri login per continuare.');
  };

  const apiFetch = async (path, method, body, options = {}) => {
    if (state.uiLocked) {
      throw { status: 403, message: 'UI bloccata', retryAfter: 0 };
    }

    const doFetch = async (withNonce) => {
      const headers = { Accept: 'application/json' };
      if (withNonce && nonce) headers['X-WP-Nonce'] = nonce;
      if (body !== undefined) headers['Content-Type'] = 'application/json';
      const response = await fetch(buildRestUrl(path), {
        method: method || 'GET',
        credentials: 'same-origin',
        headers,
        body: body !== undefined ? JSON.stringify(body) : undefined,
      });
      let payload = null;
      try {
        payload = await response.json();
      } catch (e) {
        payload = null;
      }
      return { response, payload };
    };

    let { response, payload } = await doFetch(true);
    if (!response.ok) {
      let err = normalizeError(response.status, payload || {});
      const nonceErr = err.status === 403 && (err.code === 'rest_cookie_invalid_nonce' || err.code === 'aurora_rest_invalid_nonce');
      if (nonceErr) {
        const retry = await doFetch(false);
        if (retry.response.ok) return retry.payload;
        err = normalizeError(retry.response.status, retry.payload || {});
      }
      if (options.lockOnAuth && (err.status === 401 || err.status === 403)) {
        lockUi(err.message);
      }
      throw err;
    }
    return payload;
  };

  const setCountdown = () => {
    clearInterval(state.countdownTimer);
    if (!state.autoRefresh || state.uiLocked) {
      if (nextPollNode) nextPollNode.textContent = 'off';
      return;
    }
    state.countdownTimer = setInterval(() => {
      if (!nextPollNode) return;
      const sec = Math.max(0, Math.ceil((state.nextPollAt - Date.now()) / 1000));
      nextPollNode.textContent = `${sec}s`;
    }, 200);
  };

  const schedulePoll = () => {
    clearTimeout(state.pollTimer);
    if (!state.autoRefresh || state.uiLocked) return;
    state.nextPollAt = Date.now() + state.pollDelayMs;
    setCountdown();
    state.pollTimer = setTimeout(() => {
      refreshStatus();
    }, state.pollDelayMs);
  };

  const incidentsForRange = (items) => {
    const list = Array.isArray(items) ? items : [];
    const cutoffByRange = {
      '24h': 24 * 3600,
      '7d': 7 * 24 * 3600,
      '30d': 30 * 24 * 3600,
    };
    const cutoffSec = cutoffByRange[state.filters.range] || cutoffByRange['24h'];

    return list.filter((row) => {
      const ageSec = (() => {
        const d = tsToDate(row.created_at);
        if (!d) return Number.MAX_SAFE_INTEGER;
        return Math.floor((Date.now() - d.getTime()) / 1000);
      })();
      if (ageSec > cutoffSec) return false;
      if (state.filters.op && row.op_key !== state.filters.op) return false;
      if (state.filters.severity && row.severity !== state.filters.severity) return false;
      return true;
    });
  };

  const classifyHealth = (data) => {
    const health = String(data?.health?.status || 'WARN');
    if (health === 'FAIL') return 'ERROR';
    if (health === 'HEALTHY') return 'OK';
    return health;
  };

  const buildHero = (data) => {
    const health = classifyHealth(data);
    const errors24 = Number(data?.ops_errors?.filtered || 0);
    const totalBacklog = Number(data?.queue?.backlog_total || 0);
    const dead = Number(data?.queue?.dead || 0);
    const trend = state.prevErrors24h === null ? '—' : (errors24 > state.prevErrors24h ? '+' : (errors24 < state.prevErrors24h ? '-' : '='));

    return `
      <section class="aurora-status-hero">
        <div class="aurora-status-hero-head">
          <h2>System Health</h2>
          <span class="aurora-severity-pill ${severityClass(health)}">${esc(health)}</span>
        </div>
        <div class="aurora-status-hero-grid">
          <div><strong>Ops errors (24h):</strong> ${errors24} <span class="aurora-muted">(trend: ${trend})</span></div>
          <div><strong>Queue backlog:</strong> ${totalBacklog}</div>
          <div><strong>Dead queue items:</strong> ${dead}</div>
        </div>
        ${(health === 'WARN' || health === 'ERROR') ? `
          <div class="aurora-status-actions">
            <a class="button" href="#aurora-incidents">View incidents</a>
            <button class="button" id="aurora-run-diagnostics">Run diagnostics</button>
            <a class="button button-primary" href="admin.php?page=aurora-ops">Go to Ops</a>
          </div>
        ` : ''}
      </section>
    `;
  };

  const buildAlerts = (data) => {
    const alerts = [];
    const asPastDue = Number(data?.action_scheduler?.past_due || 0);
    if (asPastDue > 0) {
      alerts.push({
        id: 'as_past_due',
        severity: asPastDue > 20 ? 'ERROR' : 'WARN',
        title: 'Action Scheduler ha azioni in ritardo',
        details: `${asPastDue} past-due actions`,
        impact: 'I job asincroni potrebbero accumularsi e non partire in tempo.',
        actions: `<a class="button" href="admin.php?page=wc-status&tab=action-scheduler">Apri Action Scheduler</a><a class="button" href="admin.php?page=aurora-ops">Go to Ops</a>`,
      });
    }

    const dead = Number(data?.queue?.dead || 0);
    if (dead > 0) {
      alerts.push({
        id: 'dead_queue',
        severity: 'ERROR',
        title: 'Dead queue items presenti',
        details: `${dead} elementi in dead queue`,
        impact: 'Parte del flusso asincrono non sta completando correttamente.',
        actions: `<a class="button button-primary" href="admin.php?page=aurora-ops">Go to Ops</a>`,
      });
    }

    if (data?.config?.wp_cron_enabled) {
      const hidden = localStorage.getItem('aurora_hide_wpcron_info') === '1';
      if (!hidden) {
        alerts.push({
          id: 'wp_cron_info',
          severity: 'INFO',
          title: 'WP-Cron attivo (consigliato disattivarlo)',
          details: 'Per performance ottimali usa cron di sistema.',
          impact: 'Può introdurre ritardi nei job sotto carico.',
          actions: `<a class="button" href="https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/" target="_blank" rel="noopener">Guida setup cron</a><button class="button" id="aurora-hide-wpcron">Non mostrare più</button>`,
        });
      }
    }

    const sorted = alerts.sort((a, b) => severityWeight(b.severity) - severityWeight(a.severity));
    if (!sorted.length) {
      return '<section class="aurora-status-section"><h2>Critical Alerts</h2><p class="aurora-muted">Nessun alert critico attivo.</p></section>';
    }

    return `
      <section class="aurora-status-section">
        <h2>Critical Alerts</h2>
        <div class="aurora-alert-list">
          ${sorted.map((a) => `
            <article class="aurora-alert aurora-alert-${a.severity.toLowerCase()}">
              <div class="aurora-alert-head">
                <h3>${esc(a.title)}</h3>
                <span class="aurora-severity-pill ${severityClass(a.severity)}">${esc(a.severity)}</span>
              </div>
              <p><strong>Dettagli:</strong> ${esc(a.details)}</p>
              <p><strong>Impatto:</strong> ${esc(a.impact)}</p>
              <div class="aurora-status-actions">${a.actions}</div>
            </article>
          `).join('')}
        </div>
      </section>
    `;
  };

  const buildIncidents = (data) => {
    const incidents = incidentsForRange(data?.incidents?.items || []);
    const summary = data?.incidents?.summary || {};
    const uniqueOps = [...new Set((data?.incidents?.items || []).map((i) => i.op_key))];
    const lastIncident = incidents[0] || data?.incidents?.items?.[0] || null;

    return `
      <section class="aurora-status-section" id="aurora-incidents">
        <h2>Incidents (Ops Errors)</h2>
        <p class="aurora-muted">Filtered (24h) / Total</p>

        <div class="aurora-two-col">
          <div class="aurora-status-card">
            <h3>Summary</h3>
            <dl class="aurora-kv">
              <dt>Errors (24h)</dt><dd>${esc(summary.errors_24h || 0)}</dd>
              <dt>Unique ops impacted</dt><dd>${esc(summary.unique_ops_impacted || 0)} ${uniqueOps.length ? `(${esc(uniqueOps.join(', '))})` : ''}</dd>
              <dt>Last incident at</dt><dd>${esc(summary.last_incident_at || '-')}</dd>
              <dt>Most frequent op</dt><dd>${esc(summary.most_frequent_op || '-')}</dd>
            </dl>
          </div>
          <div class="aurora-status-card">
            <h3>Last Incident</h3>
            ${lastIncident ? `
              <dl class="aurora-kv">
                <dt>Op</dt><dd>${esc(lastIncident.op_key)}</dd>
                <dt>At</dt><dd>${esc(lastIncident.created_at)}</dd>
                <dt>Message</dt><dd class="aurora-incident-message">${esc(summarizeIncident(lastIncident.summary || lastIncident.raw || ''))}</dd>
                <dt>Impact</dt><dd class="aurora-incident-message">${esc(lastIncident.impact)}</dd>
              </dl>
              <div class="aurora-status-actions">
                <a class="button" href="admin.php?page=aurora-ops">View run log</a>
                <button class="button" id="aurora-copy-last-incident">Copy details</button>
              </div>
              <details id="aurora-last-incident-raw" ${state.detailsOpen.lastIncidentRaw ? 'open' : ''}>
                <summary>Dettaglio tecnico</summary>
                <pre class="aurora-code">${esc(lastIncident.raw || '')}</pre>
              </details>
            ` : '<p class="aurora-muted">Nessun incidente recente.</p>'}
          </div>
        </div>

        <div class="aurora-status-filters">
          <label>Time range
            <select id="aurora-filter-range">
              <option value="24h" ${state.filters.range === '24h' ? 'selected' : ''}>24h</option>
              <option value="7d" ${state.filters.range === '7d' ? 'selected' : ''}>7d</option>
              <option value="30d" ${state.filters.range === '30d' ? 'selected' : ''}>30d</option>
            </select>
          </label>
          <label>Op
            <select id="aurora-filter-op">
              <option value="">All</option>
              ${uniqueOps.map((op) => `<option value="${esc(op)}" ${state.filters.op === op ? 'selected' : ''}>${esc(op)}</option>`).join('')}
            </select>
          </label>
          <label>Severity
            <select id="aurora-filter-severity">
              <option value="">All</option>
              <option value="ERROR" ${state.filters.severity === 'ERROR' ? 'selected' : ''}>ERROR</option>
              <option value="WARN" ${state.filters.severity === 'WARN' ? 'selected' : ''}>WARN</option>
              <option value="INFO" ${state.filters.severity === 'INFO' ? 'selected' : ''}>INFO</option>
            </select>
          </label>
        </div>

        <table class="widefat striped aurora-incidents-table">
          <thead><tr><th>Time</th><th>Severity</th><th>Op</th><th>Summary</th><th>Link</th></tr></thead>
          <tbody>
            ${incidents.length ? incidents.map((it) => `
              <tr>
                <td>${esc(it.created_at || '-')}</td>
                <td><span class="aurora-severity-pill ${severityClass(it.severity)}">${esc(it.severity)}</span></td>
                <td>${esc(it.op_key || '-')}</td>
                <td class="aurora-incident-summary-cell" title="${esc(it.summary || it.raw || '-')}"><span class="aurora-incident-summary">${esc(summarizeIncident(it.summary || it.raw || '-'))}</span></td>
                <td><a href="admin.php?page=aurora-ops">Open</a></td>
              </tr>
            `).join('') : '<tr><td colspan="5" class="aurora-muted">Nessun incidente per i filtri selezionati.</td></tr>'}
          </tbody>
        </table>
      </section>
    `;
  };

  const buildQueue = (data) => {
    const q = data?.queue || {};
    return `
      <section class="aurora-status-section">
        <h2>Queue</h2>
        <div class="aurora-queue-grid">
          <article class="aurora-status-card">
            <h3>Backlog</h3>
            <p><strong>Items queued:</strong> ${esc(q.backlog_total ?? 0)}</p>
            <p><strong>Oldest age:</strong> ${q.oldest_pending_seconds === null ? 'n/a' : `${esc(q.oldest_pending_seconds)}s`}</p>
          </article>
          <article class="aurora-status-card">
            <h3>Dead items</h3>
            <p><strong>Dead:</strong> ${esc(q.dead ?? 0)}</p>
            <p><strong>Retryable:</strong> ${esc(q.retryable_dead ?? 0)}</p>
            <a class="button" href="admin.php?page=aurora-ops">Go to dead queue</a>
          </article>
          <article class="aurora-status-card">
            <h3>Leases</h3>
            <p><strong>Active leases:</strong> ${esc(q.active_leases ?? 0)}</p>
            <p><strong>Stale leases:</strong> ${esc(q.stale_leases ?? 0)}</p>
            <button class="button" id="aurora-run-sweep-leases">Run sweep leases</button>
          </article>
        </div>
      </section>
    `;
  };

  const buildTimestamps = (data) => {
    const ts = data?.last_run_timestamps || {};
    const rows = [
      ['Repricer tick', ts.repricer_tick],
      ['Repricer run', ts.repricer_run],
      ['Feed enqueue', ts.feed_enqueue],
      ['Feed run', ts.feed_run],
      ['Rebuild', ts.rebuild],
      ['Sweep leases', ts.sweep_leases],
    ];

    return `
      <section class="aurora-status-section">
        <h2>Last run timestamps</h2>
        <table class="widefat striped">
          <thead><tr><th>Task</th><th>Timestamp</th><th>Delta</th></tr></thead>
          <tbody>
            ${rows.map(([label, value]) => `<tr><td>${esc(label)}</td><td>${esc(value || '-')}</td><td>${esc(formatDelta(value))}</td></tr>`).join('')}
          </tbody>
        </table>
      </section>
    `;
  };

  const buildDiagnostics = (data) => {
    const diag = data?.diagnostics || {};
    const jsonDump = JSON.stringify(data, null, 2);
    return `
      <section class="aurora-status-section">
        <details id="aurora-diagnostics-details" ${state.detailsOpen.diagnostics ? 'open' : ''}>
          <summary><strong>Diagnostics (advanced)</strong></summary>
          <div class="aurora-status-card">
            <p><strong>recent_ops_errors:</strong> ${esc(diag.recent_ops_errors ?? 0)}</p>
            <p><strong>snapshot alignment summary:</strong> ${esc(diag.snapshot_alignment_raw || 'n/a')}</p>
            <div class="aurora-status-actions">
              <button class="button" id="aurora-download-status-json">Download status JSON</button>
            </div>
            <pre class="aurora-code">${esc(jsonDump)}</pre>
          </div>
        </details>
      </section>
    `;
  };

  const bindDetails = () => {
    const detailsBindings = [
      ['aurora-last-incident-raw', 'lastIncidentRaw'],
      ['aurora-diagnostics-details', 'diagnostics'],
      ['aurora-warnings-details', 'alerts'],
    ];
    detailsBindings.forEach(([id, key]) => {
      const node = document.getElementById(id);
      if (!node) return;
      node.addEventListener('toggle', () => {
        state.detailsOpen[key] = !!node.open;
      });
    });
  };

  const bindActions = () => {
    const runDiagnosticsBtn = document.getElementById('aurora-run-diagnostics');
    if (runDiagnosticsBtn) {
      runDiagnosticsBtn.addEventListener('click', () => {
        state.detailsOpen.diagnostics = true;
        refreshStatus();
      });
    }

    const sweepBtn = document.getElementById('aurora-run-sweep-leases');
    if (sweepBtn) {
      sweepBtn.addEventListener('click', async () => {
        sweepBtn.disabled = true;
        try {
          await apiFetch(route('sweepLeases'), 'POST', { channel: 'all' }, { lockOnAuth: true });
          showNotice('success', 'Sweep leases avviato.');
          await refreshStatus();
        } catch (err) {
          showNotice(err.status === 401 || err.status === 403 ? 'error' : 'warning', err.message || 'Errore sweep leases');
          if (err.status === 429) {
            const retry = err.retryAfter || 5;
            let remain = retry;
            const original = 'Run sweep leases';
            sweepBtn.textContent = `${original} (${remain}s)`;
            const timer = setInterval(() => {
              remain -= 1;
              if (remain <= 0) {
                clearInterval(timer);
                sweepBtn.disabled = false;
                sweepBtn.textContent = original;
                return;
              }
              sweepBtn.textContent = `${original} (${remain}s)`;
            }, 1000);
            return;
          }
        } finally {
          if (!sweepBtn.textContent.includes('(')) {
            sweepBtn.disabled = false;
          }
        }
      });
    }

    const copyIncidentBtn = document.getElementById('aurora-copy-last-incident');
    if (copyIncidentBtn) {
      copyIncidentBtn.addEventListener('click', async () => {
        const item = state.data?.incidents?.items?.[0];
        if (!item) return;
        const text = JSON.stringify(item, null, 2);
        try {
          await navigator.clipboard.writeText(text);
          showNotice('success', 'Dettagli copiati negli appunti.');
        } catch (err) {
          showNotice('warning', 'Impossibile copiare negli appunti.');
        }
      });
    }

    const filterRange = document.getElementById('aurora-filter-range');
    const filterOp = document.getElementById('aurora-filter-op');
    const filterSeverity = document.getElementById('aurora-filter-severity');
    const onFilter = () => {
      state.filters.range = filterRange ? filterRange.value : '24h';
      state.filters.op = filterOp ? filterOp.value : '';
      state.filters.severity = filterSeverity ? filterSeverity.value : '';
      render();
    };
    [filterRange, filterOp, filterSeverity].forEach((el) => {
      if (el) el.addEventListener('change', onFilter);
    });

    const hideWpCronBtn = document.getElementById('aurora-hide-wpcron');
    if (hideWpCronBtn) {
      hideWpCronBtn.addEventListener('click', () => {
        localStorage.setItem('aurora_hide_wpcron_info', '1');
        render();
      });
    }

    const downloadBtn = document.getElementById('aurora-download-status-json');
    if (downloadBtn) {
      downloadBtn.addEventListener('click', () => {
        const blob = new Blob([JSON.stringify(state.data || {}, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `aurora-system-status-${Date.now()}.json`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
      });
    }
  };

  const render = () => {
    const data = state.data;
    if (!data) {
      root.innerHTML = '<div class="aurora-status-section"><p class="aurora-muted">Caricamento...</p></div>';
      return;
    }

    const health = classifyHealth(data);
    setHealthBadge(health);
    if (lastUpdateNode) {
      lastUpdateNode.textContent = data.generated_at_utc || '-';
    }

    root.innerHTML = `
      ${buildHero(data)}
      ${buildAlerts(data)}
      ${buildIncidents(data)}
      ${buildQueue(data)}
      ${buildTimestamps(data)}
      ${buildDiagnostics(data)}
    `;

    bindDetails();
    bindActions();
  };

  const refreshStatus = async () => {
    try {
      const data = await apiFetch(route('status'), 'GET', undefined, { lockOnAuth: false });
      const prev = state.data;
      state.data = data;
      state.authFailures = 0;
      state.prevErrors24h = prev ? Number(prev?.ops_errors?.filtered || 0) : state.prevErrors24h;
      state.pollDelayMs = basePollMs;
      render();
      clearNotice();
    } catch (err) {
      if (err.status === 401 || err.status === 403) {
        state.authFailures += 1;
        if (state.authFailures >= 2) {
          lockUi(err.message || 'Sessione scaduta. Apri login per continuare.');
          return;
        }
      }
      if (!state.uiLocked) {
        showNotice(err.status === 401 || err.status === 403 ? 'error' : 'warning', err.message || 'Errore aggiornamento status');
      }
      if (err.status === 429 && err.retryAfter > 0) {
        state.pollDelayMs = Math.min(maxBackoffMs, err.retryAfter * 1000);
      } else {
        state.pollDelayMs = Math.min(maxBackoffMs, state.pollDelayMs * 2);
      }
    } finally {
      schedulePoll();
    }
  };

  if (refreshBtn) {
    refreshBtn.addEventListener('click', () => {
      state.pollDelayMs = basePollMs;
      refreshStatus();
    });
  }

  if (toggleAutoBtn) {
    toggleAutoBtn.addEventListener('click', () => {
      state.autoRefresh = !state.autoRefresh;
      toggleAutoBtn.dataset.state = state.autoRefresh ? 'on' : 'off';
      toggleAutoBtn.textContent = state.autoRefresh ? 'Auto-refresh: ON' : 'Auto-refresh: OFF';
      if (state.autoRefresh) {
        schedulePoll();
      } else {
        clearTimeout(state.pollTimer);
        setCountdown();
      }
    });
  }

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && state.autoRefresh && !state.uiLocked) {
      state.pollDelayMs = basePollMs;
      refreshStatus();
    }
  });

  refreshStatus();
})();
