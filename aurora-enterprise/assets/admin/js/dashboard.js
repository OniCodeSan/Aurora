(function () {
    const root = document.getElementById('aurora-enterprise-dashboard');
    if (!root) {
        return;
    }

    const apiFetch = window.wp?.apiFetch;
    if (!apiFetch) {
        root.innerHTML = '<div class="notice notice-error"><p>wp-api-fetch non disponibile.</p></div>';
        return;
    }

    const cfg = window.auroraDashboard || {};
    const esc = (value) =>
        String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        }[char]));

    const state = {
        loading: true,
        error: '',
        data: null,
        feedIntegrations: null,
        feedSaving: false,
        rebuilding: false,
        feedForm: {
            amazon: {
                seller_id: '',
                marketplace_id: '',
                client_id: '',
                client_secret: '',
                refresh_token: '',
            },
            ebay: {
                merchant_id: '',
                site_id: '',
                app_id: '',
                dev_id: '',
                cert_id: '',
                user_token: '',
            },
        },
    };

    const headers = () => ({
        'X-WP-Nonce': cfg.nonce || '',
    });

    const fillFeedForm = (integrations) => {
        const amazon = integrations?.amazon || {};
        const ebay = integrations?.ebay || {};
        state.feedForm = {
            amazon: {
                seller_id: String(amazon.seller_id || ''),
                marketplace_id: String(amazon.marketplace_id || ''),
                client_id: String(amazon.client_id || ''),
                client_secret: '',
                refresh_token: '',
            },
            ebay: {
                merchant_id: String(ebay.merchant_id || ''),
                site_id: String(ebay.site_id || ''),
                app_id: String(ebay.app_id || ''),
                dev_id: String(ebay.dev_id || ''),
                cert_id: '',
                user_token: '',
            },
        };
    };

    const fetchDashboard = () =>
        apiFetch({
            path: cfg.dashboardPath || '/aurora/v1/ops-ui-status',
            headers: headers(),
        }).then((response) => {
            state.data = response || {};
            state.error = '';
            return response;
        });

    const fetchFeedIntegrations = () =>
        apiFetch({
            path: cfg.feedIntegrationsPath || '/aurora/v1/feed/integrations',
            headers: headers(),
        }).then((response) => {
            state.feedIntegrations = response?.integrations || null;
            fillFeedForm(state.feedIntegrations);
            return response;
        });

    const bindInput = (id, section, key) => {
        const input = document.getElementById(id);
        if (!input) {
            return;
        }
        input.addEventListener('input', (event) => {
            state.feedForm[section][key] = String(event.target.value || '');
        });
    };

    const saveIntegrations = () => {
        if (state.feedSaving) {
            return;
        }
        state.feedSaving = true;
        render();

        apiFetch({
            path: cfg.feedIntegrationsPath || '/aurora/v1/feed/integrations',
            method: 'POST',
            headers: headers(),
            data: {
                integrations: state.feedForm,
            },
        }).then((response) => {
            state.feedIntegrations = response?.integrations || null;
            fillFeedForm(state.feedIntegrations);
            window.alert('Connessioni merchant salvate.');
        }).catch((error) => {
            window.alert(error?.message || 'Errore salvataggio connessioni merchant');
        }).finally(() => {
            state.feedSaving = false;
            render();
        });
    };

    const runRebuild = () => {
        if (state.rebuilding) {
            return;
        }
        state.rebuilding = true;
        render();

        apiFetch({
            path: cfg.rebuildPath || '/aurora/v1/trigger/rebuild',
            method: 'POST',
            headers: headers(),
            data: { indexer: 'all' },
        }).then(() => {
            window.alert('Rebuild avviato.');
            return fetchDashboard();
        }).catch((error) => {
            window.alert(error?.message || 'Errore rebuild');
        }).finally(() => {
            state.rebuilding = false;
            render();
        });
    };

    const attachHandlers = () => {
        const refreshButton = document.getElementById('aurora-refresh-dashboard');
        if (refreshButton) {
            refreshButton.addEventListener('click', () => {
                state.loading = true;
                render();
                fetchDashboard().catch((error) => {
                    state.error = error?.message || 'Errore caricamento status';
                }).finally(() => {
                    state.loading = false;
                    render();
                });
            });
        }

        const rebuildButton = document.getElementById('aurora-run-rebuild');
        if (rebuildButton) {
            rebuildButton.addEventListener('click', runRebuild);
        }

        bindInput('aurora-amz-seller-id', 'amazon', 'seller_id');
        bindInput('aurora-amz-marketplace-id', 'amazon', 'marketplace_id');
        bindInput('aurora-amz-client-id', 'amazon', 'client_id');
        bindInput('aurora-amz-client-secret', 'amazon', 'client_secret');
        bindInput('aurora-amz-refresh-token', 'amazon', 'refresh_token');
        bindInput('aurora-ebay-merchant-id', 'ebay', 'merchant_id');
        bindInput('aurora-ebay-site-id', 'ebay', 'site_id');
        bindInput('aurora-ebay-app-id', 'ebay', 'app_id');
        bindInput('aurora-ebay-dev-id', 'ebay', 'dev_id');
        bindInput('aurora-ebay-cert-id', 'ebay', 'cert_id');
        bindInput('aurora-ebay-user-token', 'ebay', 'user_token');

        const saveButton = document.getElementById('aurora-save-feed-integrations');
        if (saveButton) {
            saveButton.addEventListener('click', saveIntegrations);
        }
    };

    const render = () => {
        const data = state.data || {};
        const queue = data.queue || {};
        const errors = data.ops_errors || {};
        const incidents = data.incidents || {};
        const summary = incidents.summary || {};
        const lastError = data.last_error || {};
        const timestamps = data.last_run_timestamps || {};
        const runs = Array.isArray(data.recent_runs) ? data.recent_runs : [];

        const feed = state.feedIntegrations || {};
        const amazon = feed.amazon || {};
        const ebay = feed.ebay || {};

        const loadingHtml = state.loading ? '<div class="aurora-muted">Caricamento...</div>' : '';
        const errorHtml = state.error
            ? `<div class="notice notice-error inline"><p>${esc(state.error)}</p></div>`
            : '';

        root.innerHTML = `
            ${errorHtml}
            <div class="aurora-dashboard-toolbar">
                <button class="button" id="aurora-refresh-dashboard">Refresh now</button>
                ${loadingHtml}
            </div>
            <div class="aurora-dashboard-grid">
                <div class="aurora-card">
                    <h2>System health</h2>
                    <p><strong>Status:</strong> ${esc(data.health?.status || 'WARN')}</p>
                    <p><strong>Queue backlog:</strong> ${Number(queue.backlog_total || 0)}</p>
                    <p><strong>Dead queue:</strong> ${Number(queue.dead || 0)}</p>
                </div>
                <div class="aurora-card">
                    <h2>Incidents</h2>
                    <p><strong>Errori (24h):</strong> ${Number(summary.errors_24h || errors.filtered || 0)}</p>
                    <p><strong>Op impattate:</strong> ${Number(summary.unique_ops_impacted || 0)}</p>
                    <p><strong>Ultimo incidente:</strong> ${esc(summary.last_incident_at || '-')}</p>
                </div>
                <div class="aurora-card">
                    <h2>Ultimo errore</h2>
                    <p><strong>Op:</strong> ${esc(lastError.op_key || '-')}</p>
                    <p><strong>At:</strong> ${esc(lastError.created_at || '-')}</p>
                    <p class="aurora-meta-note">${esc(lastError.message || 'Nessun errore recente.')}</p>
                </div>
                <div class="aurora-card">
                    <h2>Azioni</h2>
                    <button class="button button-primary" id="aurora-run-rebuild" ${state.rebuilding ? 'disabled' : ''}>
                        ${state.rebuilding ? 'Avvio rebuild...' : 'Avvia rebuild'}
                    </button>
                    <p class="aurora-meta-note">Feed enqueue: ${esc(timestamps.feed_enqueue || '-')}</p>
                    <p class="aurora-meta-note">Feed run: ${esc(timestamps.feed_run || '-')}</p>
                </div>
            </div>

            <div class="aurora-card aurora-card--wide">
                <h2>Connessioni feed marketplace</h2>
                <p class="aurora-meta-note">Configura API merchant per Amazon ed eBay. I segreti salvati vengono mostrati solo mascherati.</p>
                <div class="aurora-marketplace-grid">
                    <div class="aurora-marketplace-col">
                        <h3>Amazon</h3>
                        <label>Seller ID <input type="text" id="aurora-amz-seller-id" value="${esc(state.feedForm.amazon.seller_id)}" /></label>
                        <label>Marketplace ID <input type="text" id="aurora-amz-marketplace-id" value="${esc(state.feedForm.amazon.marketplace_id)}" /></label>
                        <label>Client ID <input type="text" id="aurora-amz-client-id" value="${esc(state.feedForm.amazon.client_id)}" /></label>
                        <label>Client secret <input type="password" id="aurora-amz-client-secret" value="" placeholder="${amazon.has_client_secret ? 'gia configurato' : 'inserisci secret'}" /></label>
                        <label>Refresh token <input type="password" id="aurora-amz-refresh-token" value="" placeholder="${amazon.has_refresh_token ? 'gia configurato' : 'inserisci token'}" /></label>
                        <p class="aurora-meta-note">Secret: ${esc(amazon.client_secret || '-')} | Token: ${esc(amazon.refresh_token || '-')}</p>
                    </div>
                    <div class="aurora-marketplace-col">
                        <h3>eBay</h3>
                        <label>Merchant ID <input type="text" id="aurora-ebay-merchant-id" value="${esc(state.feedForm.ebay.merchant_id)}" /></label>
                        <label>Site ID <input type="text" id="aurora-ebay-site-id" value="${esc(state.feedForm.ebay.site_id)}" /></label>
                        <label>App ID <input type="text" id="aurora-ebay-app-id" value="${esc(state.feedForm.ebay.app_id)}" /></label>
                        <label>Dev ID <input type="text" id="aurora-ebay-dev-id" value="${esc(state.feedForm.ebay.dev_id)}" /></label>
                        <label>Cert ID <input type="password" id="aurora-ebay-cert-id" value="" placeholder="${ebay.has_cert_id ? 'gia configurato' : 'inserisci cert'}" /></label>
                        <label>User token <input type="password" id="aurora-ebay-user-token" value="" placeholder="${ebay.has_user_token ? 'gia configurato' : 'inserisci token'}" /></label>
                        <p class="aurora-meta-note">Cert: ${esc(ebay.cert_id || '-')} | Token: ${esc(ebay.user_token || '-')}</p>
                    </div>
                </div>
                <div class="aurora-actions">
                    <button class="button button-primary" id="aurora-save-feed-integrations" ${state.feedSaving ? 'disabled' : ''}>
                        ${state.feedSaving ? 'Salvataggio...' : 'Salva connessioni API feed'}
                    </button>
                    <span class="aurora-meta-note">Ultimo aggiornamento: ${esc(feed.updated_at || '-')}</span>
                </div>
            </div>

            <div class="aurora-card aurora-card--wide">
                <h2>Run recenti</h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Op</th>
                            <th>Status</th>
                            <th>Creato</th>
                            <th>Messaggio</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${runs.length ? runs.slice(0, 10).map((run) => `
                            <tr>
                                <td>${Number(run.id || 0)}</td>
                                <td>${esc(run.op_key || '-')}</td>
                                <td>${esc(run.status || '-')}</td>
                                <td>${esc(run.created_at || '-')}</td>
                                <td>${esc(run.message || run.error || '-')}</td>
                            </tr>
                        `).join('') : '<tr><td colspan="5">Nessun run recente.</td></tr>'}
                    </tbody>
                </table>
            </div>
        `;

        attachHandlers();
    };

    render();

    Promise.allSettled([fetchDashboard(), fetchFeedIntegrations()]).then((results) => {
        const dashboardFail = results[0]?.status === 'rejected';
        if (dashboardFail) {
            state.error = results[0].reason?.message || 'Errore caricamento status';
        }
        state.loading = false;
        render();
    });
})();
