/**
 * The settings screen's "Send a test event" button.
 *
 * Posts to admin-ajax with the nonce the screen was rendered with and shows
 * whatever the server says about it. This is not measurement: the event goes
 * out in the API's validation mode, so nothing is recorded and no conversion
 * can be invented by clicking it.
 */
(function () {
	'use strict';

	var config = window.openaiAdsTest;
	var button = document.getElementById( 'openai-ads-test' );
	var out = document.getElementById( 'openai-ads-test-result' );

	if ( ! config || ! button || ! out ) {
		return;
	}

	button.addEventListener( 'click', function () {
		out.textContent = config.testing;

		var body = new FormData();
		body.append( 'action', config.action );
		body.append( '_wpnonce', config.nonce );

		fetch( config.url, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( r ) { out.textContent = r.data && r.data.message ? r.data.message : ''; } )
			.catch( function () { out.textContent = config.failed; } );
	} );
})();
