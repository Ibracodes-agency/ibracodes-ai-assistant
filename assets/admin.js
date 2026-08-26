/** Test connection: verify the key at setup, not from a customer complaint. */
( function () {
	'use strict';

	var button = document.getElementById( 'wsa-test' );
	var result = document.getElementById( 'wsa-test-result' );
	if ( ! button || ! result || ! window.wsaAdmin ) {
		return;
	}

	button.addEventListener( 'click', function () {
		var keyField = document.getElementById( 'wsa-key' );
		var modelField = document.getElementById( 'wsa-model' );

		button.disabled = true;
		button.textContent = wsaAdmin.testing;
		result.textContent = '';
		result.className = 'wsa-test-result';

		fetch( wsaAdmin.endpoint, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': wsaAdmin.nonce,
			},
			credentials: 'same-origin',
			body: JSON.stringify( {
				key: keyField ? keyField.value : '',
				model: modelField ? modelField.value : '',
			} ),
		} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( data ) {
				result.textContent = data.message || '';
				result.className = 'wsa-test-result ' + ( data.ok ? 'is-ok' : 'is-bad' );
			} )
			.catch( function () {
				result.textContent = 'Request failed.';
				result.className = 'wsa-test-result is-bad';
			} )
			.finally( function () {
				button.disabled = false;
				button.textContent = wsaAdmin.test;
			} );
	} );
} )();
