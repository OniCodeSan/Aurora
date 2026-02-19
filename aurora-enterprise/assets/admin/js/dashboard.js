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
        deadJobs: [],
        deadLoading: true,
        deadError: null,
        deadQueue: '',
        retrying: false,
    };

    const fetchDashboard = () => {
        return apiFetch( {
            url: auroraDashboard.restUrl,
            headers: {
                'X-WP-Nonce': auroraDashboard.nonce,
            },
        } ).then( ( response ) => {
            state.data = response;
            return response;
        } );
    };

    const fetchDeadJobs = ( queue = state.deadQueue ) => {
        state.deadLoading = true;
        state.deadError = null;
        state.deadQueue = queue;
        render();
        const qs = queue ? `?queue=${ encodeURIComponent( queue ) }` : '';
        return apiFetch( {
            path: `/aurora/v1/queue/dead${ qs }`,
            headers: { 'X-WP-Nonce': auroraDashboard.nonce },
        } ).then( ( response ) => {
            state.deadJobs = response?.jobs || [];
            state.deadLoading = false;
            render();
        } ).catch( ( error ) => {
            state.deadLoading = false;
            state.deadError = error.message || 'Errore caricamento dead-letter';
            render();
        } );
    };

    const retryDead = () => {
        if ( state.retrying ) {
            return;
        }
        state.retrying = true;
        render();
        apiFetch( {
            path: '/aurora/v1/queue/retry',
            method: 'POST',
            headers: { 'X-WP-Nonce': auroraDashboard.nonce },
            data: {
                queue: state.deadQueue,
                limit: 100,
            },
        } ).then( () => {
            return Promise.all( [ fetchDashboard(), fetchDeadJobs() ] );
        } ).catch( ( error ) => {
            window.alert( error.message || 'Errore retry dead-letter' );
        } ).finally( () => {
            state.retrying = false;
            render();
        } );
    };

    const render = () => {
        root.innerHTML = '';
        const container = document.createElement( 'div' );
        container.className = 'aurora-dashboard-grid';
        if ( state.loading ) {
            container.innerHTML = '<p>Caricamento dati…</p>';
            root.appendChild( container );
            return;
        }
        if ( state.error ) {
            container.innerHTML = `<div class="notice notice-error"><p>${ state.error }</p></div>`;
            root.appendChild( container );
            return;
        }
        if ( ! state.data ) {
            container.innerHTML = '<p>Nessun dato disponibile.</p>';
            root.appendChild( container );
            return;
        }

        const queueCard = `
            <div class="aurora-card">
                <h2>Code</h2>
                <ul>
                    <li>Price: ${ state.data.queue.price }</li>
                    <li>Stock: ${ state.data.queue.stock }</li>
                    <li>Visibility: ${ state.data.queue.visibility }</li>
                    <li>Feed: ${ state.data.queue.feed }</li>
                    <li>Dead letter: ${ state.data.queue.dead }</li>
                </ul>
            </div>
        `;

        const rebuildCard = `
            <div class="aurora-card">
                <h2>Ultimo rebuild</h2>
                <p>Price: ${ state.data.lastRebuild.price || '—' }</p>
                <p>Stock: ${ state.data.lastRebuild.stock || '—' }</p>
                <p>Visibility: ${ state.data.lastRebuild.visibility || '—' }</p>
                <button class="button button-primary" id="aurora-rebuild-all">Rebuild manuale</button>
            </div>
        `;

        const logsCard = `
            <div class="aurora-card">
                <h2>Job recenti</h2>
                <ul>
                    ${( state.data.logs || [] ).map( ( log ) => `<li><strong>${ log.indexer }</strong> – ${ log.message } (${ log.created_at })</li>` ).join( '' ) || '<li>Nessun log</li>'}
                </ul>
            </div>
        `;

        const cronRows = Object.entries( state.data.cron || {} ).map( ( [ key, cron ] ) => `
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
        ` ).join( '' );

        const cronCard = `
            <div class="aurora-card">
                <h2>Cron</h2>
                <table class="aurora-cron-table">
                    <thead>
                        <tr><th>Job</th><th>Interval</th><th>Status</th><th>Ultimo run</th><th>Azioni</th></tr>
                    </thead>
                    <tbody>${ cronRows }</tbody>
                </table>
            </div>
        `;

        const deadList = state.deadLoading
            ? '<p>Caricamento dead-letter…</p>'
            : state.deadError
                ? `<p class="error">${ state.deadError }</p>`
                : ( state.deadJobs.length
                    ? `<ul>${ state.deadJobs.map( ( job ) => `
                        <li>
                            <strong>${ job.queue }</strong> – ${ job.id }<br />
                            <em>${ job.failed_at || '' }</em><br />
                            <small>${ job.error || '' }</small>
                        </li>
                    ` ).join( '' ) }</ul>`
                    : '<p>Nessun job dead.</p>' );

        const deadCard = `
            <div class="aurora-card">
                <h2>Dead letter</h2>
                <label>
                    Queue
                    <select id="aurora-dead-queue">
                        <option value="">Tutte</option>
                        <option value="price" ${ state.deadQueue === 'price' ? 'selected' : '' }>Price</option>
                        <option value="stock" ${ state.deadQueue === 'stock' ? 'selected' : '' }>Stock</option>
                        <option value="visibility" ${ state.deadQueue === 'visibility' ? 'selected' : '' }>Visibility</option>
                        <option value="feed" ${ state.deadQueue === 'feed' ? 'selected' : '' }>Feed</option>
                    </select>
                </label>
                <button class="button" id="aurora-retry-dead" ${ state.retrying ? 'disabled' : '' }>
                    ${ state.retrying ? 'Retry in corso…' : 'Retry 100' }
                </button>
                ${ deadList }
            </div>
        `;

        container.innerHTML = [ queueCard, rebuildCard, logsCard, cronCard, deadCard ].join( '' );
        root.appendChild( container );
        attachHandlers();
    };

    const attachHandlers = () => {
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

        const queueSelect = document.getElementById( 'aurora-dead-queue' );
        if ( queueSelect ) {
            queueSelect.addEventListener( 'change', ( event ) => {
                const queue = event.target.value;
                fetchDeadJobs( queue );
            } );
        }

        const retryBtn = document.getElementById( 'aurora-retry-dead' );
        if ( retryBtn ) {
            retryBtn.addEventListener( 'click', retryDead );
        }
    };

    // bootstrap fetches
    Promise.all( [ fetchDashboard(), fetchDeadJobs() ] ).then( () => {
        state.loading = false;
        render();
    } ).catch( ( error ) => {
        state.loading = false;
        state.error = error.message || 'Errore caricamento dashboard';
        render();
    } );
}() );
