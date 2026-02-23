(function() {
    const root = document.getElementById('aurora-system-status-root');
    if (!root || !window.wp || !wp.apiFetch) {
        return;
    }
    const settings = window.auroraSystemStatus || {};
    const nonce = settings.nonce || '';
    const path = settings.restPath || 'aurora/v1/system-status';

    const state = {
        health: { status: 'loading', reasons: [] },
        queues: {},
        snapshots: {},
        feed: {},
        config: {},
        last_runs: [],
    };

    const render = () => {
        root.innerHTML = `
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
        `;
    };

    const fetchStatus = () => {
        wp.apiFetch({ path, headers: { 'X-WP-Nonce': nonce } })
            .then((res) => { Object.assign(state, res); render(); })
            .catch((err) => console.error('Aurora system status error', err));
    };

    render();
    fetchStatus();
    setInterval(fetchStatus, 10000);
})();
