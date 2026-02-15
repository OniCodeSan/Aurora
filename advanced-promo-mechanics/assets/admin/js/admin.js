( function () {
    const meta = document.querySelector('[data-apm-meta]');
    if ( meta ) {
        const tabButtons = meta.querySelectorAll('[data-apm-tab-target]');
        const panels = meta.querySelectorAll('[data-apm-tab]');
        const typeSelect = meta.querySelector('[data-apm-type-select]');
        const actionGroups = meta.querySelectorAll('[data-type-scope]');

        const switchTab = ( target ) => {
            tabButtons.forEach( ( btn ) => {
                const isActive = btn.dataset.apmTabTarget === target;
                btn.classList.toggle( 'is-active', isActive );
                btn.setAttribute( 'aria-selected', isActive );
            } );
            panels.forEach( ( panel ) => {
                panel.classList.toggle( 'is-active', panel.dataset.apmTab === target );
            } );
        };

        tabButtons.forEach( ( btn ) => {
            btn.addEventListener( 'click', () => switchTab( btn.dataset.apmTabTarget ) );
        } );

        const toggleActions = () => {
            if ( ! typeSelect ) {
                return;
            }
            const current = typeSelect.value;
            actionGroups.forEach( ( group ) => {
                const scope = ( group.dataset.typeScope || '' ).split( ',' ).map( ( v ) => v.trim() ).filter( Boolean );
                if ( scope.length === 0 ) {
                    group.classList.remove( 'is-hidden' );
                } else {
                    group.classList.toggle( 'is-hidden', ! scope.includes( current ) );
                }
            } );
        };

        typeSelect?.addEventListener( 'change', toggleActions );
        toggleActions();
    }

    const postsFilter = document.querySelector( '#posts-filter' );
    const apmData = window.apmAdmin || {};
    const i18n = apmData.i18n || {};
    const getText = ( key, fallback ) => ( i18n[ key ] ? i18n[ key ] : fallback );

    if ( postsFilter && document.body.classList.contains( 'edit-php' ) && document.body.classList.contains( 'post-type-product' ) ) {
        const ensureHidden = ( name ) => {
            let field = postsFilter.querySelector( `input[name="${ name }"]` );
            if ( ! field ) {
                field = document.createElement( 'input' );
                field.type = 'hidden';
                field.name = name;
                postsFilter.appendChild( field );
            }
            return field;
        };

        const hiddenCategory = ensureHidden( 'apm_bulk_category' );
        const hiddenCsv = ensureHidden( 'apm_bulk_csv' );
        const hiddenToken = ensureHidden( 'apm_bulk_token' );

        if ( apmData.selectionToken ) {
            hiddenToken.value = apmData.selectionToken;
        }

        const resetBulkSelectors = () => {
            [ 'bulk-action-selector-top', 'bulk-action-selector-bottom' ].forEach( ( id ) => {
                const select = document.getElementById( id );
                if ( select ) {
                    select.value = '-1';
                }
            } );
        };

        const assignSelectionToken = ( token ) => {
            hiddenToken.value = token || '';
            apmData.selectionToken = token || '';
        };

        const getSelectedAction = () => {
            const selectors = [ 'bulk-action-selector-top', 'bulk-action-selector-bottom' ];
            for ( const id of selectors ) {
                const select = document.getElementById( id );
                if ( select && select.value && select.value !== '-1' ) {
                    return select.value;
                }
            }
            return '';
        };

        const runSelectAllCommand = () => {
            const noticeStart = getText( 'selectAllStart', 'Sto preparando la selezione globale…' );
            window.alert( noticeStart );

            const masterToggles = document.querySelectorAll( '#cb-select-all-1, #cb-select-all-2' );
            masterToggles.forEach( ( toggle ) => {
                toggle.checked = true;
                toggle.dispatchEvent( new Event( 'click' ) );
            } );
            const rowCheckboxes = postsFilter.querySelectorAll( 'tbody .check-column input[type="checkbox"]' );
            rowCheckboxes.forEach( ( checkbox ) => {
                checkbox.checked = true;
            } );

            if ( ! apmData.ajaxUrl || ! apmData.nonce ) {
                window.alert( getText( 'selectAllError', 'Impossibile completare la selezione globale. Riprova.' ) );
                resetBulkSelectors();
                return;
            }

            const payload = new FormData();
            payload.append( 'action', 'apm_select_all_command' );
            payload.append( 'nonce', apmData.nonce );
            const currentFilters = new FormData( postsFilter );
            currentFilters.forEach( ( value, key ) => {
                if ( value !== null ) {
                    payload.append( key, value );
                }
            } );

            fetch( apmData.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: payload,
            } )
                .then( ( response ) => response.json() )
                .then( ( data ) => {
                    if ( ! data?.success ) {
                        throw new Error( data?.data?.message || getText( 'selectAllError', 'Impossibile completare la selezione globale. Riprova.' ) );
                    }
                    assignSelectionToken( data.data.token );
                    const readyTemplate = getText( 'selectAllReady', 'Selezione globale pronta: %d prodotti. Ora scegli un’azione Aurora.' );
                    const readyMessage = readyTemplate.replace( '%d', data.data.count ?? 0 );
                    window.alert( readyMessage );
                } )
                .catch( ( error ) => {
                    window.alert( error.message || getText( 'selectAllError', 'Impossibile completare la selezione globale. Riprova.' ) );
                } )
                .finally( () => {
                    resetBulkSelectors();
                } );
        };

        postsFilter.addEventListener( 'submit', ( event ) => {
            const action = getSelectedAction();
            if ( ! action || ! action.startsWith( 'aurora_' ) ) {
                return;
            }

            if ( action === 'aurora_select_all_command' ) {
                event.preventDefault();
                runSelectAllCommand();
                return;
            }

            if ( action === 'aurora_change_category' ) {
                const cat = window.prompt( getText( 'categoryPrompt', 'Inserisci l\'ID della categoria di destinazione' ) );
                if ( cat === null ) {
                    event.preventDefault();
                    return;
                }
                hiddenCategory.value = cat;
            } else {
                hiddenCategory.value = '';
            }

            if ( action === 'aurora_export_csv' ) {
                const cols = window.prompt( getText( 'csvPrompt', 'Campi CSV separati da virgola (es. ID,post_title,price,sku)' ) );
                if ( cols === null ) {
                    event.preventDefault();
                    return;
                }
                hiddenCsv.value = cols;
            } else {
                hiddenCsv.value = '';
            }
        } );
    }

    const maybeTriggerCsvDownload = () => {
        const params = new URLSearchParams( window.location.search );
        if ( ! params.has( 'apm_csv_download' ) ) {
            return;
        }
        let csvUrl = params.get( 'apm_csv_download' ) || '';
        try {
            csvUrl = decodeURIComponent( csvUrl );
        } catch ( error ) {
            // Ignore decoding issues, keep original value.
        }
        if ( ! csvUrl || ! document.body ) {
            return;
        }
        const link = document.createElement( 'a' );
        link.href = csvUrl;
        link.setAttribute( 'download', '' );
        link.style.display = 'none';
        document.body.appendChild( link );
        link.click();
        link.remove();

        params.delete( 'apm_csv_download' );
        const newQuery = params.toString();
        const newUrl = `${ window.location.origin }${ window.location.pathname }${ newQuery ? `?${ newQuery }` : '' }${ window.location.hash }`;
        window.history.replaceState( {}, document.title, newUrl );
    };

    maybeTriggerCsvDownload();
}() );
