/**
 * The Live chats console: the open threads on one side, one conversation on
 * the other. Both poll, a few seconds apart, and both pause while the tab is
 * hidden: a new request appears in the list without a reload, and the open
 * thread shows the visitor's lines as they arrive. Every request carries the
 * REST nonce; every string reaches the page through textContent.
 */
( function () {
	'use strict';

	var cfg = window.wsaLive;
	if ( ! cfg ) {
		return;
	}
	var root = document.getElementById( 'wsa-live' );
	var list = document.getElementById( 'wsa-live-list' );
	var pane = document.getElementById( 'wsa-live-pane' );
	if ( ! root || ! list || ! pane ) {
		return;
	}

	var t = cfg.i18n;
	var INTERVAL = parseInt( cfg.interval, 10 ) || 3000;
	var baseTitle = document.title;
	var lastList = '';
	var listTimer = null;
	// the thread in the pane, and where its transcript got to
	var current = 0;
	var since = 0;
	var manager = '';
	var threadTimer = null;
	var ui = null;

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

	function request( url, method, body ) {
		return fetch( url, {
			method: method,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			credentials: 'same-origin',
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				return { ok: res.ok, data: data };
			} );
		} );
	}

	function withQuery( url, params ) {
		var pairs = Object.keys( params ).map( function ( key ) {
			return encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] );
		} );
		return url + ( url.indexOf( '?' ) > -1 ? '&' : '?' ) + pairs.join( '&' );
	}

	function note( message ) {
		if ( ui ) {
			ui.note.textContent = message || '';
		}
	}

	function waited( seconds ) {
		var minutes = Math.floor( ( parseInt( seconds, 10 ) || 0 ) / 60 );
		return minutes < 1 ? t.justNow : t.waited.replace( '%s', String( minutes ) );
	}

	// ------------------------------------------------------------------- list
	/** Rebuilds the list from the server's rows, in the markup the page rendered. */
	function renderList( rows ) {
		list.textContent = '';
		if ( ! rows.length ) {
			list.appendChild( el( 'div', 'wsa-empty', t.empty ) );
		}
		rows.forEach( function ( row ) {
			var item = el( 'button', 'wsa-live-item' + ( row.id === current ? ' is-active' : '' ) );
			item.type = 'button';
			item.setAttribute( 'data-thread', String( row.id ) );
			var top = el( 'span', 'wsa-live-item-top' );
			top.appendChild( el( 'span', 'wsa-pill is-' + row.status, t.states[ row.status ] || row.status ) );
			if ( row.unread > 0 ) {
				top.appendChild( el( 'span', 'wsa-live-unread', String( row.unread ) ) );
			}
			item.appendChild( top );
			item.appendChild( el( 'span', 'wsa-live-item-q', row.first_question || t.noQuestion ) );
			item.appendChild( el( 'span', 'wsa-live-item-meta', row.status === 'live' ? ( row.manager || '' ) : waited( row.waiting_seconds ) ) );
			list.appendChild( item );
		} );
		var waiting = rows.filter( function ( row ) {
			return row.status === 'waiting';
		} ).length;
		document.title = waiting ? '(' + waiting + ') ' + baseTitle : baseTitle;
	}

	function markActive() {
		Array.prototype.forEach.call( list.querySelectorAll( '.wsa-live-item' ), function ( item ) {
			item.classList.toggle( 'is-active', parseInt( item.getAttribute( 'data-thread' ), 10 ) === current );
		} );
	}

	/** Every INTERVAL ms; the list is only rebuilt when the rows changed, so focus and scrolling are left alone. */
	function pollList() {
		listTimer = null;
		if ( document.hidden ) {
			return;
		}
		request( cfg.open, 'GET' )
			.then( function ( r ) {
				if ( ! r.ok ) {
					note( ( r.data && r.data.message ) || t.failed );
					return;
				}
				var key = JSON.stringify( r.data.threads || [] );
				if ( key !== lastList ) {
					lastList = key;
					renderList( r.data.threads || [] );
				}
			}, function () {
				note( t.failed );
			} )
			.finally( function () {
				listTimer = setTimeout( pollList, INTERVAL );
			} );
	}

	list.addEventListener( 'click', function ( e ) {
		var item = e.target.closest( '.wsa-live-item' );
		if ( item ) {
			openThread( parseInt( item.getAttribute( 'data-thread' ), 10 ) );
		}
	} );

	// ------------------------------------------------------------------- pane
	function buildPane() {
		pane.textContent = '';
		var head = el( 'div', 'wsa-live-pane-head' );
		var pill = el( 'span', 'wsa-pill', t.loading );
		var who = el( 'span', 'wsa-live-who' );
		var actions = el( 'div', 'wsa-live-pane-actions' );
		var claim = el( 'button', 'wsa-btn', t.claim );
		claim.type = 'button';
		claim.hidden = true;
		var close = el( 'button', 'wsa-btn is-ghost', t.close );
		close.type = 'button';
		close.hidden = true;
		actions.appendChild( claim );
		actions.appendChild( close );
		head.appendChild( pill );
		head.appendChild( who );
		head.appendChild( actions );

		var noteLine = el( 'p', 'wsa-live-note' );
		var log = el( 'div', 'wsa-live-log' );
		log.setAttribute( 'role', 'log' );
		log.setAttribute( 'aria-live', 'polite' );

		var form = el( 'form', 'wsa-live-form' );
		var text = el( 'textarea', 'fld' );
		text.rows = 2;
		text.disabled = true;
		text.placeholder = t.claimFirst;
		text.setAttribute( 'aria-label', t.reply );
		var send = el( 'button', 'wsa-btn', t.send );
		send.type = 'submit';
		send.disabled = true;
		form.appendChild( text );
		form.appendChild( send );

		pane.appendChild( head );
		pane.appendChild( noteLine );
		pane.appendChild( log );
		pane.appendChild( form );

		claim.addEventListener( 'click', function () {
			act( cfg.claim, claim );
		} );
		close.addEventListener( 'click', function () {
			act( cfg.close, close );
		} );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			reply();
		} );
		// Enter sends, Shift+Enter breaks the line
		text.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' && ! e.shiftKey ) {
				e.preventDefault();
				reply();
			}
		} );

		ui = { pill: pill, who: who, claim: claim, close: close, note: noteLine, log: log, text: text, send: send };
	}

	function bubble( m ) {
		var wrap = el( 'div', 'wsa-live-msg is-' + m.role );
		var label = m.role === 'manager' ? manager : ( m.role === 'user' ? t.visitor : ( m.role === 'assistant' ? t.assistant : '' ) );
		if ( label ) {
			wrap.appendChild( el( 'span', 'wsa-live-msg-name', label ) );
		}
		wrap.appendChild( el( 'div', 'wsa-live-msg-text', m.text ) );
		if ( m.at && m.role !== 'system' ) {
			// the stored datetime, clock only
			wrap.appendChild( el( 'span', 'wsa-live-msg-at', String( m.at ).slice( 11, 16 ) ) );
		}
		return wrap;
	}

	/** The pane follows the thread's state: who can be claimed, who can be closed, whether a reply is possible. */
	function setState( status, name ) {
		manager = name || '';
		ui.pill.textContent = t.states[ status ] || status;
		ui.pill.className = 'wsa-pill is-' + status;
		ui.who.textContent = manager;
		ui.claim.hidden = ! ( status === 'waiting' || status === 'missed' );
		ui.close.hidden = ! ( status === 'waiting' || status === 'live' );
		var canReply = status === 'live';
		ui.text.disabled = ! canReply;
		ui.send.disabled = ! canReply;
		ui.text.placeholder = ! canReply ? t.claimFirst : ( manager && manager !== cfg.me ? t.takeOver.replace( '%s', manager ) : t.reply );
	}

	function openThread( id ) {
		if ( ! id ) {
			return;
		}
		current = id;
		since = 0;
		manager = '';
		if ( threadTimer ) {
			clearTimeout( threadTimer );
			threadTimer = null;
		}
		buildPane();
		markActive();
		pollThread();
	}

	/** Every INTERVAL ms for the open thread, appending what arrived since the last poll. */
	function pollThread() {
		threadTimer = null;
		if ( ! current || document.hidden ) {
			return;
		}
		var id = current;
		request( withQuery( cfg.poll, { id: id, since: since } ), 'GET' )
			.then( function ( r ) {
				if ( id !== current ) {
					return; // the manager moved on while this was in flight
				}
				if ( ! r.ok ) {
					note( ( r.data && r.data.message ) || t.failed );
					return;
				}
				note( '' );
				// the state first: a manager line needs the name it carries
				setState( r.data.status, r.data.manager );
				var rows = r.data.messages || [];
				rows.forEach( function ( m ) {
					if ( m.id > since ) {
						since = m.id;
					}
					ui.log.appendChild( bubble( m ) );
				} );
				if ( rows.length ) {
					ui.log.scrollTop = ui.log.scrollHeight;
				}
			}, function () {
				note( t.failed );
			} )
			.finally( function () {
				if ( id === current && ! threadTimer ) {
					threadTimer = setTimeout( pollThread, INTERVAL );
				}
			} );
	}

	/** A poll right now, ahead of the loop: after an action, the line it produced should not wait three seconds. */
	function pollNow() {
		if ( threadTimer ) {
			clearTimeout( threadTimer );
			threadTimer = null;
		}
		pollThread();
	}

	/** Claim or close: one post, the state it returns, then the line it wrote. */
	function act( url, button ) {
		button.disabled = true;
		request( url, 'POST', { id: current } )
			.then( function ( r ) {
				if ( ! r.ok ) {
					note( ( r.data && r.data.message ) || t.failed );
					return;
				}
				setState( r.data.status, r.data.manager !== undefined ? r.data.manager : manager );
				pollNow();
			}, function () {
				note( t.failed );
			} )
			.finally( function () {
				button.disabled = false;
			} );
	}

	function reply() {
		var text = ui.text.value.trim();
		if ( ! text || ui.send.disabled ) {
			return;
		}
		ui.send.disabled = true;
		request( cfg.reply, 'POST', { id: current, text: text } )
			.then( function ( r ) {
				if ( ! r.ok ) {
					note( ( r.data && r.data.message ) || t.failed );
					return;
				}
				ui.text.value = '';
				pollNow();
			}, function () {
				note( t.failed );
			} )
			.finally( function () {
				ui.send.disabled = false;
				ui.text.focus();
			} );
	}

	// ------------------------------------------------------------------ start
	document.addEventListener( 'visibilitychange', function () {
		if ( document.hidden ) {
			return;
		}
		if ( ! listTimer ) {
			pollList();
		}
		if ( current && ! threadTimer ) {
			pollThread();
		}
	} );

	pollList();
	// the email links straight to one conversation: the page hands its id over on the root
	if ( parseInt( root.getAttribute( 'data-thread' ), 10 ) > 0 ) {
		openThread( parseInt( root.getAttribute( 'data-thread' ), 10 ) );
	}
} )();
