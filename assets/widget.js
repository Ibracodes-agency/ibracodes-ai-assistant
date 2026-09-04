/**
 * Shop Agent chat widget.
 *
 * The markup here mirrors the design file (docs: Shop Agent Widget.dc.html)
 * element for element, because the stylesheet is lifted from it verbatim. If a
 * class or nesting level changes here, it has to change there too.
 *
 * Self-injecting and dependency-free: it builds its own DOM, owns every class
 * under the wsa- prefix, and assumes nothing about the host theme. Product text
 * is written with textContent; the only innerHTML is WooCommerce's own price
 * markup and our own inline icons.
 */
( function () {
	'use strict';

	var cfg = window.wsaConfig;
	if ( ! cfg || ! cfg.endpoint ) {
		return;
	}

	var t = cfg.i18n;
	var history = [];
	var thread = '';
	var busy = false;
	var opened = false;
	var SEEN_KEY = 'wsa-seen';
	var STORE_KEY = 'wsa-chat';
	var KEEP = 16;

	var ICON = {
		chat: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 12c0 4.1-3.8 7.4-8.5 7.4-1 0-2-.15-2.9-.42L4.4 20.5l1.1-3.3C4.1 15.85 3.5 14 3.5 12c0-4.1 3.8-7.4 8.5-7.4s8.5 3.3 8.5 7.4Z"></path><path d="M8.8 11.9h.01M12 11.9h.01M15.2 11.9h.01"></path></svg>',
		close: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"></path></svg>',
		send: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13M12.5 6.5L19 12l-6.5 5.5"></path></svg>',
	};

	// ---------------------------------------------------------------- helpers
	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	function scrollDown() {
		body.scrollTop = body.scrollHeight;
	}

	/** Storage throws in some privacy modes; a missing dot is not worth a crash. */
	function seen( write ) {
		try {
			if ( write ) {
				window.localStorage.setItem( SEEN_KEY, '1' );
				return true;
			}
			return window.localStorage.getItem( SEEN_KEY ) === '1';
		} catch ( e ) {
			return true;
		}
	}

	/**
	 * The conversation belongs to the tab, not the page: following a product
	 * card or the site menu must not reset it, and the server thread token has
	 * to travel with it so transcripts stay one thread. sessionStorage ends
	 * with the tab, which is as long as a visitor expects a chat to last.
	 */
	function restore() {
		try {
			var saved = JSON.parse( window.sessionStorage.getItem( STORE_KEY ) || 'null' );
			if ( saved && Array.isArray( saved.messages ) ) {
				history = saved.messages;
				thread = typeof saved.thread === 'string' ? saved.thread : '';
			}
		} catch ( e ) {}
	}

	function persist() {
		try {
			window.sessionStorage.setItem( STORE_KEY, JSON.stringify( { thread: thread, messages: history.slice( -KEEP ) } ) );
		} catch ( e ) {}
	}

	// ------------------------------------------------------------------- DOM
	var root = el( 'div', 'wsa-root' );
	root.setAttribute( 'data-position', cfg.position === 'left' ? 'left' : 'right' );
	if ( cfg.accent ) {
		root.style.setProperty( '--wsa-accent', cfg.accent );
	}

	var launcher = el( 'button', 'wsa-launcher' );
	launcher.type = 'button';
	launcher.setAttribute( 'aria-label', t.open );
	launcher.innerHTML = ICON.chat;
	// both inner spans are optional per the design: no label collapses the pill
	// back to a 56px circle
	if ( cfg.launcherLabel ) {
		launcher.appendChild( el( 'span', 'wsa-launcher-label', cfg.launcherLabel ) );
	}
	if ( ! seen() ) {
		var dot = el( 'span', 'wsa-launcher-dot' );
		dot.setAttribute( 'aria-hidden', 'true' );
		launcher.appendChild( dot );
	}

	var panel = el( 'div', 'wsa-panel' );
	panel.setAttribute( 'role', 'dialog' );
	panel.setAttribute( 'aria-modal', 'false' );
	panel.setAttribute( 'aria-label', cfg.title || t.conversation );
	panel.hidden = true;

	var head = el( 'div', 'wsa-head' );
	var headText = el( 'div', 'wsa-head-text' );
	headText.appendChild( el( 'span', 'wsa-title', cfg.title ) );
	if ( cfg.subtitle ) {
		headText.appendChild( el( 'span', 'wsa-subtitle', cfg.subtitle ) );
	}
	var close = el( 'button', 'wsa-close' );
	close.type = 'button';
	close.setAttribute( 'aria-label', t.close );
	close.innerHTML = ICON.close;
	head.appendChild( headText );
	head.appendChild( close );

	var body = el( 'div', 'wsa-body' );
	body.setAttribute( 'role', 'log' );
	body.setAttribute( 'aria-live', 'polite' );

	var form = el( 'form', 'wsa-form' );
	var input = el( 'input', 'wsa-input' );
	input.type = 'text';
	input.placeholder = t.placeholder;
	input.setAttribute( 'aria-label', t.placeholder );
	input.autocomplete = 'off';
	var send = el( 'button', 'wsa-send' );
	send.type = 'submit';
	send.setAttribute( 'aria-label', t.send );
	send.innerHTML = ICON.send;
	form.appendChild( input );
	form.appendChild( send );

	panel.appendChild( head );
	panel.appendChild( body );
	panel.appendChild( form );
	root.appendChild( panel );
	root.appendChild( launcher );

	// --------------------------------------------------------------- messages
	function addMessage( role, text ) {
		var wrap = el( 'div', 'wsa-msg is-' + role );
		wrap.appendChild( el( 'div', 'wsa-msg-text', text ) );
		body.appendChild( wrap );
		scrollDown();
		return wrap;
	}

	function addTyping() {
		var wrap = el( 'div', 'wsa-msg wsa-typing is-assistant' );
		wrap.setAttribute( 'aria-label', t.thinking );
		var bubble = el( 'div', 'wsa-msg-text' );
		bubble.appendChild( el( 'span' ) );
		bubble.appendChild( el( 'span' ) );
		bubble.appendChild( el( 'span' ) );
		wrap.appendChild( bubble );
		body.appendChild( wrap );
		scrollDown();
		return wrap;
	}

	function addChips( chips, onPick ) {
		if ( ! chips || ! chips.length ) {
			return;
		}
		var row = el( 'div', 'wsa-chips' );
		chips.forEach( function ( label ) {
			var chip = el( 'button', 'wsa-chip', label );
			chip.type = 'button';
			chip.addEventListener( 'click', function () {
				row.remove();
				onPick( label );
			} );
			row.appendChild( chip );
		} );
		body.appendChild( row );
		scrollDown();
	}

	function addProducts( products ) {
		if ( ! products || ! products.length ) {
			return;
		}
		var list = el( 'div', 'wsa-cards' );

		products.forEach( function ( p ) {
			var card = el( 'div', 'wsa-card' );

			var media = el( 'a', 'wsa-card-media' );
			media.href = p.url;
			var img = el( 'img' );
			img.src = p.image;
			img.alt = p.name;
			img.loading = 'lazy';
			media.appendChild( img );
			if ( p.on_sale ) {
				media.appendChild( el( 'span', 'wsa-badge', t.onSale ) );
			}

			var info = el( 'div', 'wsa-card-info' );
			var name = el( 'a', 'wsa-card-name', p.name );
			name.href = p.url;
			info.appendChild( name );

			// out of stock reads above the price, per the design
			if ( ! p.in_stock ) {
				info.appendChild( el( 'div', 'wsa-card-stock', t.outOfStock ) );
			}

			var price = el( 'div', 'wsa-card-price' );
			// WooCommerce's own price markup, rendered server-side
			price.innerHTML = p.price_html;
			info.appendChild( price );

			var action;
			if ( p.can_add ) {
				// WooCommerce's own classes: its script upgrades this to an ajax
				// add and appends its "View cart" link, and without that script
				// it is still a working link
				action = el( 'a', 'wsa-card-btn add_to_cart_button ajax_add_to_cart', t.addToCart );
				action.href = p.add_url;
				action.setAttribute( 'data-product_id', p.id );
				action.setAttribute( 'data-quantity', '1' );
				action.rel = 'nofollow';
			} else {
				action = el( 'a', 'wsa-card-btn is-ghost', t.viewProduct );
				action.href = p.url;
			}
			info.appendChild( action );

			card.appendChild( media );
			card.appendChild( info );
			list.appendChild( card );
		} );

		body.appendChild( list );
		scrollDown();
	}

	function addHandoff() {
		if ( ! cfg.handoff || ! cfg.handoff.url ) {
			return;
		}
		var row = el( 'div', 'wsa-chips' );
		var link = el( 'a', 'wsa-chip is-handoff', cfg.handoff.label );
		link.href = cfg.handoff.url;
		row.appendChild( link );
		body.appendChild( row );
		scrollDown();
	}

	// ------------------------------------------------------------------- send
	function setBusy( state ) {
		busy = state;
		root.classList.toggle( 'is-busy', state );
		send.disabled = state;
	}

	function ask( text ) {
		if ( busy || ! text ) {
			return;
		}
		setBusy( true );
		input.value = '';
		addMessage( 'user', text );
		history.push( { role: 'user', text: text } );
		persist();

		var typing = addTyping();

		fetch( cfg.endpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				messages: history.slice( -10 ).map( function ( m ) {
					return { role: m.role, text: m.text };
				} ),
				thread: thread,
				device: window.matchMedia( '(max-width: 480px)' ).matches ? 'mobile' : 'desktop',
			} ),
		} )
			.then( function ( res ) {
				return res.json().then( function ( data ) {
					return { ok: res.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				typing.remove();
				if ( ! result.ok ) {
					// the server's message is the useful one (rate limited,
					// unavailable); fall back only if it sent none
					addMessage( 'assistant', ( result.data && result.data.message ) || t.error );
					addHandoff();
					return;
				}
				var data = result.data;
				if ( data.thread ) {
					thread = data.thread;
				}
				addMessage( 'assistant', data.reply );
				history.push( { role: 'assistant', text: data.reply, products: data.products || [], chips: data.chips || [], handoff: !! data.handoff } );
				persist();
				addProducts( data.products );
				if ( data.handoff ) {
					addHandoff();
				}
				addChips( data.chips, ask );
			} )
			.catch( function () {
				typing.remove();
				addMessage( 'assistant', t.error );
				addHandoff();
			} )
			.finally( function () {
				setBusy( false );
				input.focus();
			} );
	}

	// ------------------------------------------------------------------ open
	/** Rebuilds a restored conversation; only the last answer's chips are still open offers. */
	function replay() {
		history.forEach( function ( m, i ) {
			addMessage( m.role, m.text );
			if ( m.role === 'assistant' ) {
				addProducts( m.products );
				if ( m.handoff ) {
					addHandoff();
				}
				if ( i === history.length - 1 ) {
					addChips( m.chips, ask );
				}
			}
		} );
	}

	function open() {
		panel.hidden = false;
		root.classList.add( 'is-open' );
		seen( true );
		var dotNode = launcher.querySelector( '.wsa-launcher-dot' );
		if ( dotNode ) {
			dotNode.remove();
		}
		if ( ! opened ) {
			opened = true;
			if ( cfg.welcome ) {
				addMessage( 'assistant', cfg.welcome );
			}
			if ( history.length ) {
				replay();
			} else {
				addChips( cfg.chips, ask );
			}
		}
		input.focus();
	}

	function shut() {
		panel.hidden = true;
		root.classList.remove( 'is-open' );
		launcher.focus();
	}

	launcher.addEventListener( 'click', open );
	close.addEventListener( 'click', shut );
	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		ask( input.value.trim() );
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && root.classList.contains( 'is-open' ) ) {
			shut();
		}
	} );

	/**
	 * WooCommerce fires added_to_cart on its own jQuery bus after a successful
	 * ajax add. Listening beats wiring our own click handler: it only fires when
	 * the item really landed in the cart, and it is silent when jQuery or the
	 * WooCommerce script is absent.
	 */
	function watchCart() {
		if ( ! window.jQuery || ! cfg.cartEndpoint ) {
			return;
		}
		window.jQuery( document.body ).on( 'added_to_cart', function ( e, fragments, hash, button ) {
			// only count adds that came from a card inside this widget
			if ( ! thread || ! button || ! button.closest || ! button.closest( '.wsa-card' ) ) {
				return;
			}
			fetch( cfg.cartEndpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				keepalive: true,
				body: JSON.stringify( { thread: thread } ),
			} ).catch( function () {} );
		} );
	}

	function mount() {
		document.body.appendChild( root );
		watchCart();
	}

	restore();
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
} )();
