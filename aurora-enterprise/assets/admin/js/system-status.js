(function() {
    const root = document.getElementById('aurora-system-status-root');
    if (!root || !window.wp || !wp.apiFetch) {
        return;
    }
    const settings = window.auroraSystemStatus || {};
    const nonce = settings.nonce || '';
    const statusPath = settings.restPath || '/aurora/v1/system-status';
    const triggerSweepPath = settings.triggerSweepPath || '/aurora/v1/trigger/sweep-leases';

    const state = {
        health: { status: 'loading', reasons: [] },
        queues: {},
        snapshots: {},
        feed: {},
        config: {},
        last_runs: [],
        triggerLoading: false,
        triggerError: '',
        triggerMessage: '',
    };

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const renderRuns = () => {
        if (!Array.isArray(state.last_runs) || state.last_runs.length === 0) {
            return '<p>Nessuna run disponibile.</p>';
        }

        const rows = state.last_runs.map((run) => `
            <tr>
                <td>${escapeHtml(run.id)}</td>
                <td>${escapeHtml(run.op_key)}</td>
                <td>${escapeHtml(run.status)}</td>
                <td>${escapeHtml(run.started_at || '—')}</td>
                <td>${escapeHtml(run.finished_at || '—')}</td>
                <td>${escapeHtml(run.message || run.error || '—')}</td>
            </tr>
        `).join('');

        return `
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Run ID</th>
                        <th>Op</th>
                        <th>Status</th>
                        <th>Started</th>
                        <th>Finished</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    };

    const render = () => {
        root.innerHTML = `
            <div class="aurora-card">
                <h2>Operations</h2>
                <p>
                    <button class="button button-primary" id="aurora-trigger-sweep" ${state.triggerLoading ? 'disabled' : ''}>
                        ${state.triggerLoading ? 'Scheduling…' : 'Sweep leases'}
                    </button>
                </p>
                ${state.triggerMessage ? `<p><strong>${escapeHtml(state.triggerMessage)}</strong></p>` : ''}
                ${state.triggerError ? `<p style="color:#b32d2e;"><strong>${escapeHtml(state.triggerError)}</strong></p>` : ''}
            </div>
            <div class="aurora-card">
                <h2>System Health</h2>
                <p>Status: <strong>${state.health.status || 'loading'}</strong></p>
                ${state.health.reasons && state.health.reasons.length ? `<ul>${state.health.reasons.map(r => `<li>${r}</li>`).join('')}</ul>` : ''}
            </div>
            <div class="aurora-card">
                <h2>Queues</h2>
                <pre>${JSON.stringify(state.queues, null, 2)}</pre>
            </div>
            <div class="aurora-card">
                <h2>Snapshots</h2>
                <pre>${JSON.stringify(state.snapshots, null, 2)}</pre>
            </div>
            <div class="aurora-card">
                <h2>Feed</h2>
                <pre>${JSON.stringify(state.feed, null, 2)}</pre>
            </div>
            <div class="aurora-card">
                <h2>Ops Runs</h2>
                ${renderRuns()}
            </div>
        `;

        const triggerButton = document.getElementById('aurora-trigger-sweep');
        if (triggerButton) {
            triggerButton.addEventListener('click', triggerSweep);
        }
    };

    const fetchStatus = () => {
        wp.apiFetch({ path: statusPath, headers: { 'X-WP-Nonce': nonce } })
            .then((res) => { Object.assign(state, res); render(); })
            .catch((err) => console.error('Aurora system status error', err));
    };

    const triggerSweep = () => {
        if (state.triggerLoading) {
            return;
        }
        state.triggerLoading = true;
        state.triggerError = '';
        state.triggerMessage = '';
        render();

        wp.apiFetch({
            path: triggerSweepPath,
            method: 'POST',
            headers: { 'X-WP-Nonce': nonce },
        }).then((res) => {
            state.triggerMessage = `Run ${res.run_id} scheduled`;
            fetchStatus();
        }).catch((err) => {
            state.triggerError = err?.message || 'Unable to schedule sweep.';
        }).finally(() => {
            state.triggerLoading = false;
            render();
        });
    };

    render();
    fetchStatus();
    setInterval(fetchStatus, 5000);
})();
