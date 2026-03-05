(function () {
    const root = document.getElementById('aurora-dashboard-root');
    const notices = document.getElementById('aurora-dashboard-notices');
    if (!root || !notices) {
        return;
    }

    const cfg = window.auroraAdmin || {};
    const base = String(cfg.restBase || '/wp-json/aurora/v1/').replace(/\/?$/, '/');
    const routes = cfg.routes || {};
    const pageUrls = cfg.urls || {};
    const nonce = cfg.nonce || '';

    const state = {
        summary: null,
        runs: [],
        events: [],
        loading: false,
        locked: false,
        pendingAction: '',
        cooldowns: {},
        pollTimer: null,
    };

    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    }[char]));

    function setNotice(type, message) {
        notices.innerHTML = '<div class="notice notice-' + type + '"><p>' + esc(message) + '</p></div>';
    }

    function clearNotice() {
        notices.innerHTML = '';
    }

    function routePath(route) {
        const raw = String(route || '').trim();
        if (!raw) {
            return '/aurora/v1/dashboard/summary';
        }
        if (raw.indexOf('/aurora/v1/') === 0) {
            return raw;
        }
        return '/aurora/v1/' + raw.replace(/^\/+/, '');
    }

    async function request(method, route, body) {
        const path = routePath(route);
        const wpFetch = window.wp && window.wp.apiFetch;

        try {
            if (wpFetch) {
                return await wpFetch({
                    path,
                    method,
                    data: body || undefined,
                    headers: nonce ? { 'X-WP-Nonce': nonce } : {},
                });
            }

            const response = await window.fetch(base + path.replace(/^\/aurora\/v1\//, ''), {
                method,
                credentials: 'same-origin',
                headers: Object.assign(
                    { 'Content-Type': 'application/json' },
                    nonce ? { 'X-WP-Nonce': nonce } : {}
                ),
                body: body ? JSON.stringify(body) : undefined,
            });

            const payload = await response.json().catch(function () {
                return {};
            });

            if (!response.ok) {
                const error = new Error(payload.message || 'Request failed');
                error.code = payload.code || 'request_failed';
                error.status = response.status;
                error.data = payload.data || {};
                throw error;
            }

            return payload;
        } catch (error) {
            const err = error || new Error('Request failed');
            const status = Number(err.status || err?.data?.status || 0);
            err.status = status;
            throw err;
        }
    }

    function lockUi(message) {
        state.locked = true;
        root.classList.add('aurora-dashboard-locked');
        setNotice('error', message || 'Sessione scaduta. Ricarica la pagina.');
        stopPolling();
        render();
    }

    function statusClass(status) {
        if (status === 'ok') {
            return 'is-ok';
        }
        if (status === 'warn') {
            return 'is-warn';
        }
        return 'is-error';
    }

    function formatNumber(value) {
        if (value === null || value === undefined || value === '') {
            return '—';
        }
        const num = Number(value);
        return Number.isFinite(num) ? String(num) : esc(value);
    }

    function actionButtons() {
        const defs = [
            { key: 'tick_scheduler', label: 'Repricer tick' },
            { key: 'feed_enqueue', label: 'Feed enqueue' },
            { key: 'feed_run', label: 'Feed run' },
            { key: 'rebuild', label: 'Rebuild' },
            { key: 'sweep_leases', label: 'Sweep leases' },
        ];

        return defs.map(function (def) {
            const pending = state.pendingAction === def.key;
            const cooldown = Number(state.cooldowns[def.key] || 0);
            const disabled = state.locked || pending || cooldown > 0;
            const label = cooldown > 0 ? (def.label + ' (' + cooldown + 's)') : def.label;
            return '<button type="button" class="button button-secondary aurora-dashboard-action" data-action="' + def.key + '" ' + (disabled ? 'disabled' : '') + '>' + esc(label) + (pending ? '…' : '') + '</button>';
        }).join('');
    }

    function render() {
        const summary = state.summary || {};
        const kpis = summary.kpis || {};
        const alerts = Array.isArray(summary.alerts) ? summary.alerts : [];
        const runs = Array.isArray(state.runs) ? state.runs : [];
        const events = Array.isArray(state.events) ? state.events : [];

        const alertsHtml = alerts.length
            ? alerts.map(function (alert) {
                const sev = esc(alert.severity || 'info');
                return '<li class="aurora-alert aurora-alert-' + sev + '"><strong>' + esc(alert.code || 'alert') + '</strong> — ' + esc(alert.message || '') + '</li>';
            }).join('')
            : '<li class="aurora-empty">Nessun alert attivo.</li>';

        const runsRows = runs.length
            ? runs.map(function (run) {
                return '<tr>' +
                    '<td>' + esc(run.id || run.run_id || '—') + '</td>' +
                    '<td>' + esc(run.op_key || '—') + '</td>' +
                    '<td><span class="aurora-run-status status-' + esc(run.status || 'unknown') + '">' + esc(run.status || 'unknown') + '</span></td>' +
                    '<td>' + esc(run.created_at || '—') + '</td>' +
                    '<td>' + esc(run.finished_at || run.started_at || '—') + '</td>' +
                    '<td>' + esc(run.message || run.error || '—') + '</td>' +
                    '</tr>';
            }).join('')
            : '<tr><td colspan="6" class="aurora-empty">Nessun run disponibile.</td></tr>';

        const eventsRows = events.length
            ? events.map(function (event) {
                return '<tr>' +
                    '<td>' + esc(event.created_at || '—') + '</td>' +
                    '<td>' + esc(event.level || 'info') + '</td>' +
                    '<td>' + esc(event.event_key || 'event') + '</td>' +
                    '<td>' + esc(event.message || '—') + '</td>' +
                    '</tr>';
            }).join('')
            : '<tr><td colspan="4" class="aurora-empty">Nessun evento disponibile.</td></tr>';

        const lockedHtml = state.locked
            ? '<div class="aurora-lock-overlay"><div class="aurora-lock-card"><h3>Sessione scaduta</h3><p>Riapri il login per continuare.</p><a class="button button-primary" href="' + esc(window.location.href) + '">Ricarica pagina</a></div></div>'
            : '';

        root.innerHTML =
            '<div class="aurora-dashboard-shell">' +
                '<div class="aurora-dashboard-header">' +
                    '<div class="aurora-health-badge ' + statusClass(String(summary.status || 'error')) + '">Health: ' + esc(summary.status || 'unknown') + '</div>' +
                    '<div class="aurora-dashboard-header-actions">' +
                        '<a class="button" href="' + esc(pageUrls.systemStatus || '#') + '">System Status</a>' +
                        '<a class="button" href="' + esc(pageUrls.repricer || '#') + '">Repricer</a>' +
                        '<a class="button" href="' + esc(pageUrls.feed || '#') + '">Feed Hub</a>' +
                        '<button type="button" class="button" id="aurora-dashboard-refresh" ' + (state.loading || state.locked ? 'disabled' : '') + '>Refresh now</button>' +
                    '</div>' +
                '</div>' +

                '<div class="aurora-kpi-grid">' +
                    '<div class="aurora-kpi-card"><span class="label">Last tick</span><strong>' + esc(kpis.last_tick || '—') + '</strong></div>' +
                    '<div class="aurora-kpi-card"><span class="label">Enqueued</span><strong>' + formatNumber(kpis.enqueued) + '</strong></div>' +
                    '<div class="aurora-kpi-card"><span class="label">Past due</span><strong>' + formatNumber(kpis.past_due) + '</strong></div>' +
                    '<div class="aurora-kpi-card"><span class="label">Ops errors 24h</span><strong>' + formatNumber(kpis.ops_errors_24h) + '</strong></div>' +
                    '<div class="aurora-kpi-card"><span class="label">Last feed run</span><strong>' + esc(kpis.last_feed_run || '—') + '</strong></div>' +
                    '<div class="aurora-kpi-card"><span class="label">Last repricer run</span><strong>' + esc(kpis.last_repricer_run || '—') + '</strong></div>' +
                '</div>' +

                '<div class="aurora-card">' +
                    '<h2>Actions</h2>' +
                    '<p class="description">Trigger operazioni sicure con rate-limit server-side (5s).</p>' +
                    '<div class="aurora-action-list">' + actionButtons() + '</div>' +
                '</div>' +

                '<div class="aurora-grid-two">' +
                    '<div class="aurora-card"><h2>Alerts</h2><ul class="aurora-alerts-list">' + alertsHtml + '</ul></div>' +
                    '<div class="aurora-card"><h2>Events</h2><table class="widefat striped"><thead><tr><th>Time</th><th>Level</th><th>Event</th><th>Message</th></tr></thead><tbody>' + eventsRows + '</tbody></table></div>' +
                '</div>' +

                '<div class="aurora-card">' +
                    '<h2>Recent runs</h2>' +
                    '<table class="widefat striped"><thead><tr><th>ID</th><th>Op</th><th>Status</th><th>Created</th><th>Finished</th><th>Message</th></tr></thead><tbody>' + runsRows + '</tbody></table>' +
                '</div>' +
                lockedHtml +
            '</div>';

        bindActions();
    }

    function bindActions() {
        const refreshBtn = document.getElementById('aurora-dashboard-refresh');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () {
                loadAll(true);
            });
        }

        root.querySelectorAll('.aurora-dashboard-action').forEach(function (button) {
            button.addEventListener('click', function () {
                const action = String(button.getAttribute('data-action') || '');
                if (action) {
                    triggerAction(action);
                }
            });
        });
    }

    function startCooldown(action, seconds) {
        const safe = Math.max(1, Number(seconds || 1));
        state.cooldowns[action] = safe;
        const timer = window.setInterval(function () {
            const current = Number(state.cooldowns[action] || 0);
            if (current <= 1) {
                delete state.cooldowns[action];
                window.clearInterval(timer);
            } else {
                state.cooldowns[action] = current - 1;
            }
            render();
        }, 1000);
    }

    async function triggerAction(action) {
        if (state.locked || state.pendingAction) {
            return;
        }
        state.pendingAction = action;
        clearNotice();
        render();
        try {
            const response = await request('POST', routes.action || 'dashboard/action', { action: action });
            setNotice('success', response.message || 'Azione completata.');
            await loadAll(false);
        } catch (error) {
            if (error.status === 401 || error.status === 403) {
                lockUi('Sessione scaduta o permessi insufficienti.');
                return;
            }
            if (error.status === 429) {
                const retry = Number(error?.data?.retry_after || 5);
                startCooldown(action, retry);
                setNotice('warning', 'Rate limit attivo. Riprova tra ' + retry + ' secondi.');
            } else {
                setNotice('error', error.message || 'Errore eseguendo azione dashboard.');
            }
        } finally {
            state.pendingAction = '';
            render();
        }
    }

    async function loadAll(withSpinner) {
        if (state.locked) {
            return;
        }
        state.loading = Boolean(withSpinner);
        render();
        try {
            const [summaryResp, runsResp, eventsResp] = await Promise.all([
                request('GET', routes.summary || 'dashboard/summary'),
                request('GET', (routes.runs || 'dashboard/runs') + '?limit=20'),
                request('GET', (routes.events || 'dashboard/events') + '?limit=10'),
            ]);
            state.summary = summaryResp && summaryResp.summary ? summaryResp.summary : summaryResp;
            state.runs = Array.isArray(runsResp.runs) ? runsResp.runs : [];
            state.events = Array.isArray(eventsResp.events) ? eventsResp.events : [];
            clearNotice();
        } catch (error) {
            if (error.status === 401 || error.status === 403) {
                lockUi('Sessione scaduta o permessi insufficienti.');
                return;
            }
            setNotice('error', error.message || 'Errore caricando dashboard.');
        } finally {
            state.loading = false;
            render();
        }
    }

    function startPolling() {
        stopPolling();
        state.pollTimer = window.setInterval(function () {
            loadAll(false);
        }, 15000);
    }

    function stopPolling() {
        if (state.pollTimer) {
            window.clearInterval(state.pollTimer);
            state.pollTimer = null;
        }
    }

    loadAll(true);
    startPolling();
})();
