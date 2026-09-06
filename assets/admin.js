/**
 * The buttons that call the plugin's own admin routes: Test connection, so a
 * key is verified at setup and not from a customer complaint, and Rebuild
 * index. Plus the question before a lead is deleted, since the lead takes
 * its conversation with it.
 */
( function () {
	'use strict';

	if ( ! window.wsaAdmin ) {
		return;
	}

	/** Posts to an admin route and writes the reply next to the button. */
	function wire( buttonId, resultId, endpoint, busyLabel, payload ) {
		var button = document.getElementById( buttonId );
		var result = document.getElementById( resultId );
		if ( ! button || ! result || ! endpoint ) {
			return;
		}
		var idleLabel = button.textContent;

		button.addEventListener( 'click', function () {
			button.disabled = true;
			button.textContent = busyLabel;
			result.textContent = '';
			result.className = 'wsa-test-result';

			fetch( endpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': wsaAdmin.nonce,
				},
				credentials: 'same-origin',
				body: JSON.stringify( payload() ),
			} )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( data ) {
					result.textContent = data.message || '';
					result.className = 'wsa-test-result ' + ( data.ok ? 'is-ok' : 'is-bad' );
				} )
				.catch( function () {
					result.textContent = wsaAdmin.failed;
					result.className = 'wsa-test-result is-bad';
				} )
				.finally( function () {
					button.disabled = false;
					button.textContent = idleLabel;
				} );
		} );
	}

	wire( 'wsa-test', 'wsa-test-result', wsaAdmin.endpoint, wsaAdmin.testing, function () {
		var keyField = document.getElementById( 'wsa-key' );
		var modelField = document.getElementById( 'wsa-model' );
		return { key: keyField ? keyField.value : '', model: modelField ? modelField.value : '' };
	} );
	wire( 'wsa-rebuild', 'wsa-rebuild-result', wsaAdmin.rebuildEndpoint, wsaAdmin.rebuilding, function () {
		return {};
	} );

	Array.prototype.forEach.call( document.querySelectorAll( '[data-wsa-confirm]' ), function ( button ) {
		button.addEventListener( 'click', function ( e ) {
			if ( ! window.confirm( button.getAttribute( 'data-wsa-confirm' ) ) ) {
				e.preventDefault();
			}
		} );
	} );
} )();
