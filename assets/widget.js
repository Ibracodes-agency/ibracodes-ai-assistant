/**
 * IbraCodes AI Assistant chat widget.
 *
 * The markup here mirrors the design file (docs/widget-design.dc.html)
 * element for element, because the stylesheet is lifted from it verbatim. If a
 * class or nesting level changes here, it has to change there too. The one
 * element the design file does not have is the footer under the input: the
 * privacy note and the maker's mark.
 *
 * Self-injecting and dependency-free: it builds its own DOM, owns every class
 * it renders, from `wsa-root` down, and assumes nothing about the host theme. Product text
 * is written with textContent; the only innerHTML is WooCommerce's own price
 * markup and our own inline icons.
 *
 * Live mode: once a person is asked for, the AI is off and the thread is
 * polled for the person's lines. Every line the widget shows in that mode,
 * the system lines included, comes from the server; nothing is synthesised
 * here, so the owner's texts are the only texts.
 */
( function () {
	'use strict';

	var cfg = window.ibraaiConfig;
	if ( ! cfg || ! cfg.endpoint ) {
		return;
	}

	var t = cfg.i18n;
	var history = [];
	var thread = '';
	var busy = false;
	var opened = false;
	// who has the thread: ai (the default; also once a chat ended or nobody
	// came), waiting (a person was asked for), live (one is in)
	var live = { status: 'ai', manager: '', since: 0 };
	var pollTimer = null;
	var polling = null;
	var SEEN_KEY = 'ibraai-seen';
	var STORE_KEY = 'ibraai-chat';
	// entries kept across pages: a live session's lines count too, and forty
	// covers a long chat while the store stays small; the model only ever
	// sees the last ten visitor and assistant turns anyway
	var KEEP = 40;
	// the interval is the server's; the fallback only covers a config without one
	var POLL = parseInt( cfg.livePoll, 10 ) || 4000;

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
				if ( saved.live && ( saved.live.status === 'waiting' || saved.live.status === 'live' ) ) {
					live = { status: saved.live.status, manager: String( saved.live.manager || '' ), since: parseInt( saved.live.since, 10 ) || 0 };
				}
			}
		} catch ( e ) {}
	}

	function persist() {
		try {
			window.sessionStorage.setItem( STORE_KEY, JSON.stringify( { thread: thread, messages: history.slice( -KEEP ), live: live } ) );
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
	/** The dot on the launcher: something the visitor has not seen yet. Opening the panel clears it. */
	function showDot() {
		if ( launcher.querySelector( '.wsa-launcher-dot' ) ) {
			return;
		}
		var dot = el( 'span', 'wsa-launcher-dot' );
		dot.setAttribute( 'aria-hidden', 'true' );
		launcher.appendChild( dot );
	}
	if ( ! seen() ) {
		showDot();
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

	var foot = el( 'div', 'wsa-foot' );
	if ( cfg.privacyNote ) {
		foot.appendChild( el( 'p', 'wsa-note', cfg.privacyNote ) );
	}
	if ( cfg.brand && cfg.brand.url ) {
		var brand = el( 'a', 'wsa-brand' );
		brand.href = cfg.brand.url;
		brand.target = '_blank';
		brand.rel = 'noopener';
		var mark = el( 'img' );
		mark.src = cfg.brand.logo;
		mark.alt = '';
		mark.width = 52;
		mark.height = 8;
		// text first, so the reading order is the credit and then the mark
		brand.appendChild( el( 'span', '', cfg.brand.label ) );
		brand.appendChild( mark );
		foot.appendChild( brand );
	}

	panel.appendChild( head );
	panel.appendChild( body );
	panel.appendChild( form );
	if ( foot.childNodes.length ) {
		panel.appendChild( foot );
	}
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

	/** A person's line, their name above it. */
	function addManager( name, text ) {
		var wrap = el( 'div', 'wsa-msg is-manager' );
		if ( name ) {
			wrap.appendChild( el( 'div', 'wsa-msg-name', name ) );
		}
		wrap.appendChild( el( 'div', 'wsa-msg-text', text ) );
		body.appendChild( wrap );
		scrollDown();
	}

	/** One muted line between states: waiting, joined, missed, closed. */
	function addSystem( text ) {
		body.appendChild( el( 'div', 'wsa-system', text ) );
		scrollDown();
	}

	function setPlaceholder( text ) {
		input.placeholder = text;
		input.setAttribute( 'aria-label', text );
	}

	// ------------------------------------------------------------------- live
	function inLive() {
		return live.status === 'waiting' || live.status === 'live';
	}

	function liveAvailable() {
		return !! ( cfg.liveEndpoint && cfg.liveMessageEndpoint );
	}

	/** A person is expected or present: the AI is off, the input stays open, the thread is polled. */
	function enterLive( status ) {
		live.status = status === 'live' ? 'live' : 'waiting';
		persist();
		startPolling();
	}

	/** The AI has the thread again, whether the chat ended or nobody came; the manager and the placeholder go with the mode. */
	function leaveLive() {
		stopPolling();
		live.status = 'ai';
		live.manager = '';
		setPlaceholder( t.placeholder );
		persist();
	}

	function stopPolling() {
		if ( pollTimer ) {
			clearTimeout( pollTimer );
			pollTimer = null;
		}
	}

	function schedule( delay ) {
		stopPolling();
		pollTimer = setTimeout( poll, delay );
	}

	function startPolling() {
		if ( ! pollTimer && liveAvailable() && thread && inLive() ) {
			schedule( 0 );
		}
	}

	/**
	 * One request for the lines since the last one. Shared by the loop and by
	 * a refused visitor line, and never in flight twice, so no row is appended
	 * twice. Resolves to what happened: ok, slow (rate limited), gone (a
	 * deliberate refusal: live chat is off or the token failed) or retry (a
	 * network error, a server error, a body that is not JSON).
	 */
	function fetchPoll() {
		if ( polling ) {
			return polling;
		}
		var url = cfg.liveEndpoint + ( cfg.liveEndpoint.indexOf( '?' ) > -1 ? '&' : '?' ) + 'thread=' + encodeURIComponent( thread ) + '&since=' + live.since;
		polling = fetch( url, { method: 'GET' } )
			.then( function ( res ) {
				return res.json().then( function ( data ) {
					if ( res.ok ) {
						applyPoll( data );
						return 'ok';
					}
					if ( res.status === 429 ) {
						return 'slow';
					}
					// only a deliberate refusal ends live mode; a 500 or a 502 is a hiccup
					return data && ( data.code === 'ibraai_live_off' || data.code === 'ibraai_bad_token' ) ? 'gone' : 'retry';
				} );
			} )
			.catch( function () {
				return 'retry';
			} )
			.finally( function () {
				polling = null;
			} );
		return polling;
	}

	/** The loop: every POLL ms, three times that after a 429, paused while the tab is hidden and resumed on visibilitychange. */
	function poll() {
		pollTimer = null;
		if ( ! inLive() || ! thread || document.hidden ) {
			return;
		}
		fetchPoll().then( function ( outcome ) {
			if ( outcome === 'gone' ) {
				leaveLive();
				return;
			}
			if ( inLive() ) {
				schedule( outcome === 'slow' ? POLL * 3 : POLL );
			}
		} );
	}

	/**
	 * Appends the lines a poll returned to the history, and to the log once
	 * the panel has been built, then follows the state it reports. A line
	 * that lands while the panel is closed lights the launcher.
	 */
	function applyPoll( data ) {
		live.manager = data.manager ? String( data.manager ) : '';
		var arrived = false;
		var lastSystem = null;
		( data.messages || [] ).forEach( function ( m ) {
			var id = parseInt( m.id, 10 ) || 0;
			if ( id <= live.since || ( m.role !== 'manager' && m.role !== 'system' ) ) {
				return;
			}
			live.since = id;
			arrived = true;
			if ( m.role === 'manager' ) {
				history.push( { role: 'manager', name: live.manager, text: m.text } );
				if ( opened ) {
					addManager( live.manager, m.text );
				}
			} else {
				lastSystem = { role: 'system', text: m.text };
				history.push( lastSystem );
				if ( opened ) {
					addSystem( m.text );
				}
			}
		} );
		// nobody came: the missed line points at the contact option, so the
		// chip goes right under it, and replays with it
		if ( data.status === 'missed' && lastSystem ) {
			lastSystem.handoff = true;
			if ( opened ) {
				addHandoff();
			}
		}
		if ( arrived && ! root.classList.contains( 'is-open' ) ) {
			showDot();
		}
		if ( data.status === 'live' ) {
			live.status = 'live';
			setPlaceholder( ( t.writeTo || '%s' ).replace( '%s', live.manager ) );
			persist();
		} else if ( data.status === 'waiting' ) {
			live.status = 'waiting';
			setPlaceholder( t.placeholder );
			persist();
		} else {
			// the chat ended, or nobody came: the line saying so came with this
			// poll, and the AI answers again from here. A missed thread also
			// gets the contact option with each of its answers, from the server
			leaveLive();
		}
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden ) {
			startPolling();
		}
	} );

	// ------------------------------------------------------------------- send
	function setBusy( state ) {
		busy = state;
		root.classList.toggle( 'is-busy', state );
		send.disabled = state;
	}

	function parse( res ) {
		return res.json().then( function ( data ) {
			return { ok: res.ok, status: res.status, data: data };
		} );
	}

	function ask( text ) {
		if ( busy || ! text ) {
			return;
		}
		setBusy( true );
		input.value = '';
		addMessage( 'user', text );
		var entry = { role: 'user', text: text };
		history.push( entry );
		persist();

		( inLive() ? sendLive( entry, false ) : askAi( entry, false ) ).finally( function () {
			setBusy( false );
			input.focus();
		} );
	}

	/**
	 * The AI's turn. A 409 with ibraai_live_owned means a person has the thread:
	 * the widget switches modes and hands them the line instead, once.
	 */
	function askAi( entry, retried ) {
		var typing = addTyping();
		var payload = {
			// a person's lines, the system lines and what the visitor wrote to
			// the person are not the model's conversation
			messages: history.filter( function ( m ) {
				return ( m.role === 'user' || m.role === 'assistant' ) && ! m.live;
			} ).slice( -10 ).map( function ( m ) {
				return { role: m.role, text: m.text };
			} ),
			thread: thread,
			page: parseInt( cfg.pageId, 10 ) || 0,
			device: window.matchMedia( '(max-width: 480px)' ).matches ? 'mobile' : 'desktop',
		};

		return fetch( cfg.endpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( payload ),
		} )
			.then( parse )
			.then( function ( result ) {
				typing.remove();
				if ( ! result.ok ) {
					if ( result.status === 409 && result.data && result.data.code === 'ibraai_live_owned' && liveAvailable() && ! retried ) {
						enterLive( result.data.data && result.data.data.status );
						return sendLive( entry, true );
					}
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
				if ( data.live === 'waiting' && liveAvailable() ) {
					// the waiting line arrives with the first poll; nothing is written here
					enterLive( 'waiting' );
				}
				addChips( data.chips, ask );
			} )
			.catch( function () {
				typing.remove();
				addMessage( 'assistant', t.error );
				addHandoff();
			} );
	}

	/**
	 * A line to the person on the thread. No typing indicator: nobody is
	 * composing on the AI's behalf. Once stored, the entry is marked live, so
	 * it never travels to the model as a visitor turn. A 409 means the AI has
	 * the thread again (the chat ended between two polls): one poll picks up
	 * the closing line and the state, then the line goes wherever the state
	 * says, once.
	 */
	function sendLive( entry, retried ) {
		return fetch( cfg.liveMessageEndpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { thread: thread, text: entry.text } ),
		} )
			.then( parse )
			.then( function ( result ) {
				if ( result.ok ) {
					entry.live = true;
					persist();
					startPolling();
					return;
				}
				if ( result.status === 409 && ! retried ) {
					return fetchPoll().then( function () {
						return inLive() ? sendLive( entry, true ) : askAi( entry, true );
					} );
				}
				addMessage( 'assistant', ( result.data && result.data.message ) || t.error );
			} )
			.catch( function () {
				addMessage( 'assistant', t.error );
			} );
	}

	// ------------------------------------------------------------------ open
	/** Rebuilds a restored conversation; only the last answer's chips are still open offers. Live mode picks up where it was. */
	function replay() {
		history.forEach( function ( m, i ) {
			if ( m.role === 'manager' ) {
				addManager( m.name, m.text );
				return;
			}
			if ( m.role === 'system' ) {
				addSystem( m.text );
				if ( m.handoff ) {
					addHandoff();
				}
				return;
			}
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
		if ( live.status === 'live' ) {
			setPlaceholder( ( t.writeTo || '%s' ).replace( '%s', live.manager ) );
		}
		startPolling();
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
		// a live session restored from the last page keeps listening before the panel is opened
		startPolling();
	}

	restore();
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
} )();
