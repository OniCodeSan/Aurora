( function () {
    const root = document.getElementById( 'aurora-enterprise-dashboard' );
    if ( ! root ) {
        return;
    }

    const apiFetch = window.wp?.apiFetch;
    if ( ! apiFetch ) {
        root.innerHTML = '<p>wp-api-fetch non disponibile.</p>';
        return;
    }

    const state = {
        loading: true,
        data: null,
        error: null,
    };

    const render = () => {
        root.innerHTML = '';
        const container = document.createElement( 'div' );
        container.className = 'aurora-dashboard-grid';
        if ( state.loading ) {
            container.innerHTML = '<p>Caricamento dati…</p>';
        } else if ( state.error ) {
            container.innerHTML = `<div class="notice notice-error"><p>${ state.error }</p></div>`;
        } else if ( state.data ) {
            container.innerHTML = `
                <div class="aurora-card">
                    <h2>Code</h2>
                    <ul>
                        <li>Price: ${ state.data.queue.price }</li>
                        <li>Stock: ${ state.data.queue.stock }</li>
                        <li>Visibility: ${ state.data.queue.visibility }</li>
                        <li>Dead letter: ${ state.data.queue.dead }</li>
                    </ul>
                </div>
                <div class="aurora-card">
                    <h2>Ultimo rebuild</h2>
                    <p>Price: ${ state.data.lastRebuild.price || '—' }</p>
                    <p>Stock: ${ state.data.lastRebuild.stock || '—' }</p>
                    <p>Visibility: ${ state.data.lastRebuild.visibility || '—' }</p>
                </div>
                <div class="aurora-card">
                    <h2>Job recenti</h2>
                    <ul>
                        ${( state.data.logs || [] ).map( ( log ) => `<li><strong>${ log.indexer }</strong> – ${ log.message } (${ log.created_at })</li>` ).join( '' )}
                    </ul>
                </div>
                <div class="aurora-card">
                    <h2>Cron</h2>
                    <table class="aurora-cron-table">
                        <thead>
                            <tr><th>Job</th><th>Interval</th><th>Status</th><th>Ultimo run</th><th>Azioni</th></tr>
                        </thead>
                        <tbody>
                            ${ Object.entries( state.data.cron || {} ).map( ( [ key, cron ] ) => `
                                <tr data-cron-key="${ key }">
                                    <td>${ cron.label }</td>
                                    <td><input type="text" value="${ cron.interval }" class="aurora-cron-interval" /></td>
                                    <td>
                                        <span class="aurora-status aurora-status--${ cron.color }">${ cron.status_label }</span>
                                        <select class="aurora-cron-status">
                                            ${ Object.entries( state.data.cronStatuses || {} ).map( ( [ statusKey, status ] ) => `
                                                <option value="${ statusKey }" ${ cron.status === statusKey ? 'selected' : '' }>${ status.label }</option>
                                            ` ).join( '' ) }
                                        </select>
                                    </td>
                                    <td>${ cron.last_run || '—' }</td>
                                    <td><button class="button button-small aurora-cron-save">Salva</button></td>
                                </tr>
                            ` ).join( '' ) }
                        </tbody>
                    </table>
                </div>
                <div class="aurora-card">
                    <button class="button button-primary" id="aurora-rebuild-all">Rebuild manuale</button>
                </div>
            `;
        }
        root.appendChild( container );
        const rebuildBtn = document.getElementById( 'aurora-rebuild-all' );
        if ( rebuildBtn ) {
            rebuildBtn.addEventListener( 'click', () => {
                rebuildBtn.disabled = true;
                rebuildBtn.innerText = 'In esecuzione…';
                apiFetch( {
                    path: '/aurora/v1/rebuild',
                    method: 'POST',
                    headers: {
                        'X-WP-Nonce': auroraDashboard.nonce,
                    },
                } ).then( () => {
                    rebuildBtn.innerText = 'Avviato';
                } ).catch( ( error ) => {
                    window.alert( error.message || 'Errore rebuild' );
                } ).finally( () => {
                    rebuildBtn.disabled = false;
                } );
            } );
        }
        root.querySelectorAll( '.aurora-cron-save' ).forEach( ( button ) => {
            button.addEventListener( 'click', ( event ) => {
                const row = event.target.closest( 'tr[data-cron-key]' );
                const key = row?.dataset?.cronKey;
                if ( ! key ) {
                    return;
                }
                const intervalInput = row.querySelector( '.aurora-cron-interval' );
                const statusSelect = row.querySelector( '.aurora-cron-status' );
                button.disabled = true;
                apiFetch( {
                    path: '/aurora/v1/cron',
                    method: 'POST',
                    headers: { 'X-WP-Nonce': auroraDashboard.nonce },
                    data: {
                        key,
                        interval: intervalInput?.value || '',
                        status: statusSelect?.value || 'processed',
                    },
                } ).then( ( cronData ) => {
                    state.data.cron = cronData;
                    render();
                } ).catch( ( error ) => {
                    window.alert( error.message || 'Errore salvataggio cron' );
                } ).finally( () => {
                    button.disabled = false;
                } );
            } );
        } );
    };

    render();
    apiFetch( {
        url: auroraDashboard.restUrl,
        headers: {
            'X-WP-Nonce': auroraDashboard.nonce,
        },
    } ).then( ( response ) => {
        state.loading = false;
        state.data = response;
        render();
    } ).catch( ( error ) => {
        state.loading = false;
        state.error = error.message || 'Errore caricamento dashboard';
        render();
    } );
}() );
