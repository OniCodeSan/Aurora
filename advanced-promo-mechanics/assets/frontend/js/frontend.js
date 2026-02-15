( function () {
    document.addEventListener( 'change', function ( event ) {
        if ( event.target.matches( '.apm-gift-choice' ) ) {
            event.target.form?.submit();
        }
    } );
}() );
