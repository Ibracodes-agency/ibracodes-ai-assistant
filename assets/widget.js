/**
 * Shop Agent chat widget.
 *
 * Self-injecting and dependency-free: it builds its own DOM, owns every class
 * under the wsa- prefix, and never assumes anything about the host theme.
 * Product text is written with textContent; the only innerHTML is WooCommerce's
 * own price markup, which the server produced.
 */
(function () {
	'use strict';

	var cfg = window.wsaConfig;
	if ( ! cfg || ! cfg.endpoint ) {
		return;
	}

	var t = cfg.i18n;
	var history = [];
	var busy = false;
	var opened = false;

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

	// ------------------------------------------------------------------- DOM
	var root = el( 'div', 'wsa-root' );
	root.setAttribute( 'data-position', cfg.position === 'left' ? 'left' : 'right' );
	if ( cfg.accent ) {
		root.style.setProperty( '--wsa-accent', cfg.accent );
	}

	var launcher = el( 'button', 'wsa-launcher' );
	launcher.type = 'button';
	launcher.setAttribute( 'aria-label', t.open );
	launcher.innerHTML =
		'<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
		'<path fill="currentColor" d="M12 3c-4.97 0-9 3.36-9 7.5 0 2.3 1.25 4.36 3.2 5.73-.13 1.1-.6 2.2-1.4 3.06a.5.5 0 0 0 .45.84c1.9-.36 3.36-1.2 4.3-1.92.78.16 1.6.25 2.45.25 4.97 0 9-3.36 9-7.96C21 6.36 16.97 3 12 3z"/>' +
		'</svg>';

	var panel = el( 'div', 'wsa-panel' );
	panel.setAttribute( 'role', 'dialog' );
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
	close.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7 2.9 18.3 9.2 12 2.9 5.7l1.4-1.4L10.6 10.6l6.3-6.3z"/></svg>';
	head.appendChild( headText );
	head.appendChild( close );

	var body = el( 'div', 'wsa-body' );
	body.setAttribute( 'role', 'log' );
	body.setAttribute( 'aria-live', 'polite' );
	body.setAttribute( 'aria-label', t.conversation );

	var form = el( 'form', 'wsa-form' );
	var input = el( 'input', 'wsa-input' );
	input.type = 'text';
	input.placeholder = t.placeholder;
	input.setAttribute( 'aria-label', t.placeholder );
	input.autocomplete = 'off';
	var send = el( 'button', 'wsa-send' );
	send.type = 'submit';
	send.setAttribute( 'aria-label', t.send );
	send.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M3.4 20.4 21 12 3.4 3.6 3.4 10.2 15 12 3.4 13.8z"/></svg>';
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
		var wrap = el( 'div', 'wsa-msg is-assistant wsa-typing' );
		wrap.setAttribute( 'aria-label', t.thinking );
		wrap.innerHTML = '<div class="wsa-msg-text"><span></span><span></span><span></span></div>';
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

			var link = el( 'a', 'wsa-card-media' );
			link.href = p.url;
			var img = el( 'img' );
			img.src = p.image;
			img.alt = p.name;
			img.loading = 'lazy';
			link.appendChild( img );
			if ( p.on_sale ) {
				link.appendChild( el( 'span', 'wsa-badge', t.onSale ) );
			}

			var info = el( 'div', 'wsa-card-info' );
			var name = el( 'a', 'wsa-card-name', p.name );
			name.href = p.url;
			info.appendChild( name );

			var price = el( 'div', 'wsa-card-price' );
			// WooCommerce's own price markup, rendered server-side
			price.innerHTML = p.price_html;
			info.appendChild( price );

			if ( ! p.in_stock ) {
				info.appendChild( el( 'span', 'wsa-card-stock', t.outOfStock ) );
			}

			var action;
			if ( p.can_add ) {
				// WooCommerce's own classes: its script upgrades this to an ajax
				// add, and without that script it is still a working link
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

			card.appendChild( link );
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
	function ask( text ) {
		if ( busy || ! text ) {
			return;
		}
		busy = true;
		root.classList.add( 'is-busy' );
		send.disabled = true;
		input.value = '';
		addMessage( 'user', text );
		history.push( { role: 'user', text: text } );

		var typing = addTyping();

		fetch( cfg.endpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { messages: history.slice( -10 ) } ),
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
				addMessage( 'assistant', data.reply );
				history.push( { role: 'assistant', text: data.reply } );
				addProducts( data.products );
				addChips( data.chips, ask );
			} )
			.catch( function () {
				typing.remove();
				addMessage( 'assistant', t.error );
				addHandoff();
			} )
			.finally( function () {
				busy = false;
				root.classList.remove( 'is-busy' );
				send.disabled = false;
				input.focus();
			} );
	}

	// ------------------------------------------------------------------ open
	function open() {
		panel.hidden = false;
		root.classList.add( 'is-open' );
		if ( ! opened ) {
			opened = true;
			if ( cfg.welcome ) {
				addMessage( 'assistant', cfg.welcome );
			}
			addChips( cfg.chips, ask );
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

	function mount() {
		document.body.appendChild( root );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
} )();
