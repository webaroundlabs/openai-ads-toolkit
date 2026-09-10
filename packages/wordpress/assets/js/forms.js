/**
 * The browser half of the deduplication bridge.
 *
 * Contact Form 7 and Elementor Forms both submit over AJAX, so the server has
 * already recorded the conversion by the time the page hears about it. The
 * server puts the event id it used into the form's own JSON response; this
 * script reads it back and fires the Pixel event with that same id.
 *
 * Same Pixel ID, same event name, same event id on both sides - so OpenAI
 * matches the two reports instead of counting two conversions.
 *
 * Nothing here throws into the host page. A measurement failure must never
 * interfere with the confirmation message a visitor is waiting to see.
 */
(function () {
	'use strict';

	function measure( payload ) {
		if ( ! payload || ! payload.event || ! payload.event_id ) {
			return;
		}

		if ( typeof window.oaiq !== 'function' ) {
			// The Pixel is switched off, blocked, or consent was refused. The
			// server-side event still stands on its own.
			return;
		}

		var options = { event_id: payload.event_id };

		if ( payload.custom_event_name ) {
			options.custom_event_name = payload.custom_event_name;
		}

		try {
			window.oaiq( 'measure', payload.event, payload.data || {}, options );
		} catch ( e ) {
			// Deliberately swallowed.
		}
	}

	/*
	 * Contact Form 7 dispatches this on the document only after the mail has
	 * actually been sent - the same boundary the server used.
	 */
	document.addEventListener( 'wpcf7mailsent', function ( event ) {
		var response = event && event.detail ? event.detail.apiResponse : null;

		if ( response ) {
			measure( response.openai_ads );
		}
	} );

	/*
	 * Elementor Pro emits a jQuery event on the document. It is only available
	 * where jQuery is, which on an Elementor site it always is.
	 */
	if ( window.jQuery ) {
		window.jQuery( document ).on( 'submit_success', function ( event, response ) {
			if ( response && response.data ) {
				measure( response.data.openai_ads );
			}
		} );
	}
})();
