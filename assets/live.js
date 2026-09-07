/**
 * The Live chats console: the open threads on one side, one conversation on
 * the other. Both poll, a few seconds apart, and both pause while the tab is
 * hidden: a new request appears in the list without a reload, and the open
 * thread shows the visitor's lines as they arrive. Neither loop is ever in
 * flight twice. Every request carries the REST nonce; every string reaches
 * the page through textContent.
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
	// the list loop: its timer, whether a request is out, and what it last drew
	var listTimer = null;
	var listBusy = false;
	var listKey = '';
	// the thread loop: the id in the pane, where its transcript got to, the
	// manager, its timer, whether a request is out and one is wanted after it
	var current = 0;
	var since = 0;
	var manager = '';
	var threadTimer = null;
	var threadBusy = false;
	var threadPending = false;
	// this thread's loop is over (no such thread, or a refusal); the session is over (both loops off)
	var threadOver = false;
	var expired = false;
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

	/** One request, the nonce on every one; resolves to { ok, status, data }, with an empty data when the body is not JSON. Rejects only on a network error. */
	function request( url, method, body ) {
		return fetch( url, {
			method: method,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			credentials: 'same-origin',
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				return { ok: res.ok, status: res.status, data: data };
			}, function () {
				return { ok: res.ok, status: res.status, data: {} };
			} );
		} );
	}

	function withQuery( url, params ) {
		var pairs = Object.keys( params ).map( function ( key ) {
			return encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] );
		} );
		return url + ( url.indexOf( '?' ) > -1 ? '&' : '?' ) + pairs.join( '&' );
	}

	/**
	 * What a reply means for a loop: ok; expired (the nonce or the login
	 * lapsed, or live chat went off); gone (no such thread); retry (a server
	 * error, worth asking again); stop (any other refusal).
	 */
	function verdict( r ) {
		if ( r.ok ) {
			return 'ok';
		}
		if ( r.status === 401 || r.status === 403 || r.status === 503 ) {
			return 'expired';
		}
		if ( r.status === 404 ) {
			return 'gone';
		}
		return r.status >= 500 ? 'retry' : 'stop';
	}

	function note( message ) {
		if ( ui ) {
			ui.note.textContent = message || '';
		}
	}

	/** Minutes up to two hours, then hours up to two days, then days: the same words and rounding as the page's own list. */
	function waited( seconds ) {
		var minutes = Math.floor( ( parseInt( seconds, 10 ) || 0 ) / 60 );
		if ( minutes < 1 ) {
			return t.justNow;
		}
		if ( minutes < 120 ) {
			return t.waited.replace( '%s', String( minutes ) );
		}
		var hours = Math.round( minutes / 60 );
		if ( hours < 48 ) {
			return t.waitedHours.replace( '%s', String( hours ) );
		}
		return t.waitedDays.replace( '%s', String( Math.round( hours / 24 ) ) );
	}

	/** The session is over: both loops stop, the title is plain again, and the list says what to do. */
	function expire() {
		expired = true;
		clearTimeout( listTimer );
		clearTimeout( threadTimer );
		listTimer = null;
		threadTimer = null;
		document.title = baseTitle;
		list.textContent = '';
		list.appendChild( el( 'div', 'wsa-empty', t.expired ) );
		note( t.expired );
	}

	// ------------------------------------------------------------------- list
	function setTitle( rows ) {
		var waiting = rows.filter( function ( row ) {
			return row.status === 'waiting';
		} ).length;
		document.title = waiting ? '(' + waiting + ') ' + baseTitle : baseTitle;
	}

	/** What the list shows of each row, the seconds reduced to the words waited() makes of them, so a tick alone rebuilds nothing. */
	function keyOf( rows ) {
		return JSON.stringify( rows.map( function ( row ) {
			return [ row.id, row.status, row.first_question, row.unread, row.manager, waited( row.waiting_seconds ) ];
		} ) );
	}

	function markActive() {
		Array.prototype.forEach.call( list.querySelectorAll( '.wsa-live-item' ), function ( item ) {
			var active = parseInt( item.getAttribute( 'data-thread' ), 10 ) === current;
			item.classList.toggle( 'is-active', active );
			if ( active ) {
				item.setAttribute( 'aria-current', 'true' );
			} else {
				item.removeAttribute( 'aria-current' );
			}
		} );
	}

	/** Rebuilds the list from the server's rows, in the markup the page rendered, and keeps the keyboard where it was. */
	function renderList( rows ) {
		var focused = document.activeElement && list.contains( document.activeElement ) ? document.activeElement.getAttribute( 'data-thread' ) : null;
		list.textContent = '';
		if ( ! rows.length ) {
			list.appendChild( el( 'div', 'wsa-empty', t.empty ) );
		}
		rows.forEach( function ( row ) {
			var item = el( 'button', 'wsa-live-item' );
			item.type = 'button';
			item.setAttribute( 'data-thread', String( row.id ) );
			var top = el( 'span', 'wsa-live-item-top' );
			top.appendChild( el( 'span', 'wsa-pill is-' + row.status, t.states[ row.status ] || row.status ) );
			if ( row.unread > 0 ) {
				var badge = el( 'span', 'wsa-live-unread', String( row.unread ) );
				badge.setAttribute( 'aria-label', t.unread.replace( '%s', String( row.unread ) ) );
				top.appendChild( badge );
			}
			item.appendChild( top );
			item.appendChild( el( 'span', 'wsa-live-item-q', row.first_question || t.noQuestion ) );
			item.appendChild( el( 'span', 'wsa-live-item-meta', row.status === 'live' ? ( row.manager || '' ) : waited( row.waiting_seconds ) ) );
			list.appendChild( item );
		} );
		markActive();
		if ( focused ) {
			var again = list.querySelector( '.wsa-live-item[data-thread="' + focused + '"]' );
			if ( again ) {
				again.focus();
			}
		}
	}

	function scheduleList( delay ) {
		clearTimeout( listTimer );
		listTimer = setTimeout( pollList, delay );
	}

	/** Every INTERVAL ms, never twice at once, paused while the tab is hidden; the list is only redrawn when what it shows changed. */
	function pollList() {
		listTimer = null;
		if ( expired || document.hidden || listBusy ) {
			return;
		}
		listBusy = true;
		request( cfg.open, 'GET' )
			.then( function ( r ) {
				var what = verdict( r );
				if ( what === 'ok' ) {
					var rows = r.data.threads || [];
					var key = keyOf( rows );
					if ( key !== listKey ) {
						listKey = key;
						renderList( rows );
					}
					setTitle( rows );
				} else if ( what !== 'retry' ) {
					// the login or the nonce lapsed, live chat went off, or the route is gone: a reload is the answer
					expire();
				}
			}, function () {} )
			.finally( function () {
				listBusy = false;
				if ( ! expired ) {
					scheduleList( INTERVAL );
				}
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
		// Enter sends, Shift+Enter breaks the line, and an input method composing a character keeps Enter
		text.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' && ! e.shiftKey && ! e.isComposing ) {
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
		if ( ! id || expired ) {
			return;
		}
		current = id;
		since = 0;
		manager = '';
		threadOver = false;
		threadPending = false;
		clearTimeout( threadTimer );
		threadTimer = null;
		buildPane();
		markActive();
		pollThread();
	}

	function scheduleThread( delay ) {
		clearTimeout( threadTimer );
		threadTimer = setTimeout( pollThread, delay );
	}

	/**
	 * Every INTERVAL ms for the open thread, never twice at once: a poll
	 * wanted while one is out runs as soon as that one returns. Appends only
	 * lines beyond the last id seen, so two replies out of order cannot show
	 * a line twice.
	 */
	function pollThread() {
		threadTimer = null;
		if ( expired || ! current || threadOver || document.hidden ) {
			return;
		}
		if ( threadBusy ) {
			threadPending = true;
			return;
		}
		threadBusy = true;
		threadPending = false;
		var id = current;
		request( withQuery( cfg.poll, { id: id, since: since } ), 'GET' )
			.then( function ( r ) {
				if ( id !== current ) {
					return; // the manager moved on while this was in flight
				}
				var what = verdict( r );
				if ( what === 'expired' ) {
					expire();
					return;
				}
				if ( what === 'gone' || what === 'stop' ) {
					threadOver = true;
					note( what === 'gone' ? t.gone : ( ( r.data && r.data.message ) || t.failed ) );
					return;
				}
				if ( what === 'retry' ) {
					note( t.failed );
					return;
				}
				note( '' );
				// the state first: a manager line needs the name it carries
				setState( r.data.status, r.data.manager );
				var added = false;
				( r.data.messages || [] ).forEach( function ( m ) {
					var mid = parseInt( m.id, 10 ) || 0;
					if ( mid <= since ) {
						return;
					}
					since = mid;
					ui.log.appendChild( bubble( m ) );
					added = true;
				} );
				if ( added ) {
					ui.log.scrollTop = ui.log.scrollHeight;
				}
			}, function () {
				note( t.failed );
			} )
			.finally( function () {
				threadBusy = false;
				if ( expired ) {
					return;
				}
				if ( id !== current ) {
					// the pane moved to another thread meanwhile: its first poll was waiting on this one
					if ( current && threadPending ) {
						scheduleThread( 0 );
					}
					return;
				}
				if ( ! threadOver ) {
					scheduleThread( threadPending ? 0 : INTERVAL );
				}
			} );
	}

	/** A poll right now, ahead of the loop, or right after the one in flight: after an action, the line it produced should not wait three seconds. */
	function pollNow() {
		if ( threadBusy ) {
			threadPending = true;
			return;
		}
		scheduleThread( 0 );
	}

	/** Claim or close: one post, the state it returns, then the line it wrote. */
	function act( url, button ) {
		button.disabled = true;
		request( url, 'POST', { id: current } )
			.then( function ( r ) {
				if ( verdict( r ) === 'expired' ) {
					expire();
					return;
				}
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
				if ( verdict( r ) === 'expired' ) {
					expire();
					return;
				}
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
	// a tab shown again picks its loops up, and only the ones not already running or in flight
	document.addEventListener( 'visibilitychange', function () {
		if ( document.hidden || expired ) {
			return;
		}
		if ( ! listTimer && ! listBusy ) {
			pollList();
		}
		if ( current && ! threadTimer && ! threadBusy && ! threadOver ) {
			pollThread();
		}
	} );

	pollList();
	// the email links straight to one conversation: the page hands its id over on the root
	if ( parseInt( root.getAttribute( 'data-thread' ), 10 ) > 0 ) {
		openThread( parseInt( root.getAttribute( 'data-thread' ), 10 ) );
	}
} )();
