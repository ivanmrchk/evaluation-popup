/* Surge Evaluation Popup */
( function () {
	'use strict';

	var root = document.getElementById( 'surge-eval-popup' );

	if ( ! root ) {
		return;
	}

	var config = {};

	try {
		config = JSON.parse( root.getAttribute( 'data-config' ) || '{}' );
	} catch ( e ) {
		return;
	}

	var KEY_DISMISSED = 'surgeEvalDismissedUntil';
	var KEY_SUBMITTED = 'surgeEvalSubmittedUntil';
	var KEY_SESSION = 'surgeEvalSessionDismissed';
	var KEY_GEO = 'surgeEvalGeo';
	var DAY = 86400000;

	var params = new URLSearchParams( window.location.search );
	var preview = params.get( 'surge_eval_preview' ) === '1';
	var testIp = params.get( 'surge_eval_test_ip' ) || '';
	var testing = preview || testIp !== '';

	var dialog = root.querySelector( '.surge-eval__dialog' );
	var form = root.querySelector( 'form' );
	var errorBox = root.querySelector( '.surge-eval__error' );
	var formView = root.querySelector( '[data-surge-eval-form-view]' );
	var successView = root.querySelector( '[data-surge-eval-success-view]' );
	var submitButton = form.querySelector( 'button[type="submit"]' );

	var isOpen = false;
	var openedAt = 0;
	var lastFocus = null;
	var eligible = false;

	function store( type ) {
		try {
			return window[ type ];
		} catch ( e ) {
			return null;
		}
	}

	function getItem( key, type ) {
		var s = store( type || 'localStorage' );
		try {
			return s ? s.getItem( key ) : null;
		} catch ( e ) {
			return null;
		}
	}

	function setItem( key, value, type ) {
		var s = store( type || 'localStorage' );
		try {
			if ( s ) {
				s.setItem( key, value );
			}
		} catch ( e ) {}
	}

	function removeItem( key, type ) {
		var s = store( type || 'localStorage' );
		try {
			if ( s ) {
				s.removeItem( key );
			}
		} catch ( e ) {}
	}

	if ( params.get( 'surge_eval_reset' ) === '1' ) {
		removeItem( KEY_DISMISSED );
		removeItem( KEY_SUBMITTED );
		removeItem( KEY_GEO );
		removeItem( KEY_SESSION, 'sessionStorage' );
	}

	function suppressed() {
		var now = Date.now();
		if ( getItem( KEY_SESSION, 'sessionStorage' ) ) {
			return true;
		}
		if ( Number( getItem( KEY_SUBMITTED ) || 0 ) > now ) {
			return true;
		}
		return Number( getItem( KEY_DISMISSED ) || 0 ) > now;
	}

	function isMobile() {
		return window.matchMedia( '(max-width: 767px), (pointer: coarse)' ).matches;
	}

	function focusables() {
		return Array.prototype.filter.call(
			dialog.querySelectorAll( 'a[href], button, input:not([type="hidden"]):not([tabindex="-1"])' ),
			function ( el ) {
				return ! el.disabled && el.offsetParent !== null;
			}
		);
	}

	function open( isAuto ) {
		if ( isOpen ) {
			return;
		}
		isOpen = true;
		if ( ! successView.hidden ) {
			successView.hidden = true;
			formView.hidden = false;
			form.reset();
		}
		openedAt = Date.now();
		lastFocus = document.activeElement;
		root.hidden = false;
		document.body.classList.add( 'surge-eval-locked' );
		// Next frame so the transition runs.
		window.requestAnimationFrame( function () {
			root.classList.add( 'is-open' );
			var first = form.querySelector( 'input[name="name"]' );
			( isMobile() ? dialog : first || dialog ).focus( { preventScroll: true } );
		} );
		track( 'surge_eval_popup_open', { trigger: isAuto ? 'auto' : 'manual' } );
	}

	function close() {
		if ( ! isOpen ) {
			return;
		}
		isOpen = false;
		root.classList.remove( 'is-open' );
		document.body.classList.remove( 'surge-eval-locked' );
		window.setTimeout( function () {
			root.hidden = true;
		}, 250 );

		if ( successView.hidden && ! testing ) {
			setItem( KEY_SESSION, '1', 'sessionStorage' );
			if ( config.dismissDays > 0 ) {
				setItem( KEY_DISMISSED, String( Date.now() + config.dismissDays * DAY ) );
			}
		}

		if ( lastFocus && lastFocus.focus ) {
			lastFocus.focus();
		}
	}

	function track( event, data ) {
		window.dataLayer = window.dataLayer || [];
		var payload = { event: event };
		Object.keys( data || {} ).forEach( function ( k ) {
			payload[ k ] = data[ k ];
		} );
		window.dataLayer.push( payload );
	}

	// Close handlers.
	root.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '[data-surge-eval-close]' ) ) {
			e.preventDefault();
			close();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( ! isOpen ) {
			return;
		}
		if ( e.key === 'Escape' ) {
			close();
			return;
		}
		if ( e.key === 'Tab' ) {
			var items = focusables();
			if ( ! items.length ) {
				return;
			}
			var first = items[ 0 ];
			var last = items[ items.length - 1 ];
			if ( e.shiftKey && ( document.activeElement === first || document.activeElement === dialog ) ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		}
	} );

	// Manual openers always work, regardless of location or suppression.
	document.addEventListener( 'click', function ( e ) {
		var opener = e.target.closest( '.surge-eval-open, a[href="#surge-eval"]' );
		if ( opener ) {
			e.preventDefault();
			open( false );
		}
	} );

	// Phone formatting as the visitor types.
	var phoneInput = form.querySelector( 'input[name="phone"]' );
	phoneInput.addEventListener( 'input', function () {
		var d = phoneInput.value.replace( /\D/g, '' );
		if ( d.length === 11 && d.charAt( 0 ) === '1' ) {
			d = d.slice( 1 );
		}
		d = d.slice( 0, 10 );
		if ( d.length > 6 ) {
			phoneInput.value = '(' + d.slice( 0, 3 ) + ') ' + d.slice( 3, 6 ) + '-' + d.slice( 6 );
		} else if ( d.length > 3 ) {
			phoneInput.value = '(' + d.slice( 0, 3 ) + ') ' + d.slice( 3 );
		} else {
			phoneInput.value = d;
		}
	} );

	var zipInput = form.querySelector( 'input[name="zip"]' );
	zipInput.addEventListener( 'input', function () {
		zipInput.value = zipInput.value.replace( /\D/g, '' ).slice( 0, 5 );
	} );

	function showError( message, fields ) {
		errorBox.textContent = message;
		errorBox.hidden = ! message;
		[ 'name', 'phone', 'zip' ].forEach( function ( name ) {
			var input = form.querySelector( 'input[name="' + name + '"]' );
			if ( fields && fields[ name ] ) {
				input.setAttribute( 'aria-invalid', 'true' );
			} else {
				input.removeAttribute( 'aria-invalid' );
			}
		} );
	}

	function validate( data ) {
		var fields = {};
		if ( data.name.trim().length < 2 ) {
			fields.name = 'Please enter your name.';
		}
		if ( data.phone.replace( /\D/g, '' ).replace( /^1(?=\d{10}$)/, '' ).length !== 10 ) {
			fields.phone = 'Please enter a valid 10-digit phone number.';
		}
		if ( ! /^\d{5}$/.test( data.zip ) ) {
			fields.zip = 'Please enter a 5-digit ZIP code.';
		}
		return fields;
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();

		var data = {
			name: form.elements.name.value,
			phone: form.elements.phone.value,
			zip: form.elements.zip.value,
			website: form.elements.website.value,
			elapsed: Date.now() - openedAt,
			page_url: window.location.href,
			referrer: document.referrer
		};

		var fields = validate( data );
		var keys = Object.keys( fields );
		if ( keys.length ) {
			showError( fields[ keys[ 0 ] ], fields );
			form.querySelector( 'input[name="' + keys[ 0 ] + '"]' ).focus();
			return;
		}

		showError( '' );
		submitButton.disabled = true;
		var label = submitButton.textContent;
		submitButton.textContent = 'Sending…';

		var headers = { 'Content-Type': 'application/json' };
		if ( config.nonce ) {
			headers[ 'X-WP-Nonce' ] = config.nonce;
		}

		fetch( config.submitUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: JSON.stringify( data )
		} )
			.then( function ( res ) {
				return res.json().then( function ( json ) {
					return { ok: res.ok, json: json };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok || ! result.json.success ) {
					var err = result.json || {};
					showError( err.message || 'Something went wrong. Please call us instead.', err.data && err.data.fields );
					return;
				}

				if ( result.json.title ) {
					successView.querySelector( '[data-surge-eval-success-title]' ).textContent = result.json.title;
				}
				if ( result.json.message ) {
					successView.querySelector( '[data-surge-eval-success-message]' ).textContent = result.json.message;
				}

				formView.hidden = true;
				successView.hidden = false;
				dialog.focus();

				if ( ! testing && config.submittedDays > 0 ) {
					setItem( KEY_SUBMITTED, String( Date.now() + config.submittedDays * DAY ) );
				}

				track( 'surge_eval_popup_submit', { zip: data.zip } );
			} )
			.catch( function () {
				showError( 'Network error. Please try again or call us.' );
			} )
			.then( function () {
				submitButton.disabled = false;
				submitButton.textContent = label;
			} );
	} );

	// ---- Auto-open logic ----

	function armTriggers() {
		if ( ! eligible || suppressed() ) {
			return;
		}
		if ( isMobile() && ! config.mobile && ! testing ) {
			return;
		}

		var trigger = testing ? 'immediate' : config.trigger;
		var fired = false;

		function fire() {
			if ( fired || isOpen ) {
				return;
			}
			fired = true;
			open( true );
		}

		if ( trigger === 'manual' ) {
			return;
		}

		if ( trigger === 'immediate' ) {
			fire();
			return;
		}

		if ( trigger === 'delay' || trigger === 'delay_or_exit' ) {
			window.setTimeout( fire, ( config.delay || 0 ) * 1000 );
		}

		if ( trigger === 'exit' || trigger === 'delay_or_exit' ) {
			document.addEventListener( 'mouseout', function ( e ) {
				if ( ! e.relatedTarget && e.clientY <= 0 ) {
					fire();
				}
			} );
		}

		if ( trigger === 'scroll' ) {
			var onScroll = function () {
				var doc = document.documentElement;
				var max = doc.scrollHeight - window.innerHeight;
				var pct = max > 0 ? ( window.scrollY / max ) * 100 : 100;
				if ( pct >= config.scroll ) {
					window.removeEventListener( 'scroll', onScroll );
					fire();
				}
			};
			window.addEventListener( 'scroll', onScroll, { passive: true } );
			onScroll();
		}
	}

	function requestGeo( withNonce ) {
		var url = new URL( config.geoUrl, window.location.href );
		if ( preview ) {
			url.searchParams.set( 'preview', '1' );
		}
		if ( testIp ) {
			url.searchParams.set( 'test_ip', testIp );
		}
		url.searchParams.set( '_', String( Date.now() ) );

		var headers = {};
		if ( withNonce && config.nonce ) {
			headers[ 'X-WP-Nonce' ] = config.nonce;
		}

		return fetch( url.toString(), { credentials: 'same-origin', headers: headers } ).then( function ( res ) {
			// A stale nonce yields 403; retry anonymously.
			if ( res.status === 403 && withNonce ) {
				return requestGeo( false );
			}
			return res.json();
		} );
	}

	function checkEligibility() {
		if ( ! config.geo && ! testing ) {
			return Promise.resolve( true );
		}

		if ( ! testing && ! config.nonce ) {
			try {
				var cached = JSON.parse( getItem( KEY_GEO ) || 'null' );
				if ( cached && cached.exp > Date.now() ) {
					return Promise.resolve( !! cached.eligible );
				}
			} catch ( e ) {}
		}

		return requestGeo( true ).then( function ( json ) {
			if ( json && json.debug && window.console ) {
				window.console.info( '[Surge Evaluation Popup] geo check', json.debug );
			}
			var ok = !! ( json && json.eligible );
			if ( ! testing && ! config.nonce ) {
				setItem( KEY_GEO, JSON.stringify( { eligible: ok, exp: Date.now() + 12 * 3600000 } ) );
			}
			return ok;
		} );
	}

	function start() {
		// Skip the network call entirely when the popup would be suppressed anyway.
		if ( ! testing && ( suppressed() || config.trigger === 'manual' || ( isMobile() && ! config.mobile ) ) ) {
			return;
		}

		checkEligibility()
			.then( function ( ok ) {
				eligible = ok;
				armTriggers();
			} )
			.catch( function () {} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
