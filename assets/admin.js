( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.sqa-analyze' );
		if ( ! button ) {
			return;
		}

		var row = button.closest( 'tr' ).nextElementSibling;
		if ( ! row || ! row.classList.contains( 'sqa-analysis-row' ) ) {
			return;
		}

		var box = row.querySelector( '.sqa-analysis' );

		// Second click toggles an analysis that is already loaded.
		if ( box.dataset.loaded ) {
			row.hidden = ! row.hidden;
			return;
		}

		row.hidden = false;
		box.innerHTML = '<p><span class="spinner is-active sqa-spinner"></span>' + sqaAdmin.analyzing + '</p>';
		button.disabled = true;

		var body = new URLSearchParams();
		body.append( 'action', 'sqa_explain' );
		body.append( '_ajax_nonce', sqaAdmin.nonce );
		body.append( 'fingerprint', button.dataset.fingerprint );

		fetch( sqaAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				box.innerHTML = json && json.data && json.data.html ? json.data.html : '<p>' + sqaAdmin.failed + '</p>';
				if ( json && json.success ) {
					box.dataset.loaded = '1';
				}
			} )
			.catch( function () {
				box.innerHTML = '<p>' + sqaAdmin.failed + '</p>';
			} )
			.finally( function () {
				button.disabled = false;
			} );
	} );
} )();
