/**
 * Curio chat widget.
 *
 * No jQuery, no build step, no dependencies.
 *
 * The single most important thing in this file is that assistant replies are
 * turned into DOM nodes with document.createElement and textContent, and never
 * assigned to innerHTML. The widget this replaces did the opposite: it built an
 * HTML string out of the reply and appended it with jQuery. That meant any
 * markup in a knowledge base entry, in a visitor's own message, or in anything
 * a model was persuaded to echo back, executed as HTML on the page — a stored
 * cross-site scripting hole in a plugin whose entire job is to display text
 * from an untrusted source.
 */
( function () {
	'use strict';

	var config = window.curioConfig || {};
	var strings = config.strings || {};

	var root = document.getElementById( 'curio-widget' );
	if ( ! root || ! config.root ) {
		return;
	}

	var panel = root.querySelector( '.curio-panel' );
	var log = root.querySelector( '[data-curio-log]' );
	var form = root.querySelector( '[data-curio-form]' );
	var input = root.querySelector( '[data-curio-input]' );
	var sendButton = root.querySelector( '[data-curio-send]' );
	var typing = root.querySelector( '[data-curio-typing]' );
	var toggle = root.querySelector( '[data-curio-toggle]' );
	var closeButton = root.querySelector( '[data-curio-close]' );
	var expandButton = root.querySelector( '[data-curio-expand]' );
	var teaser = root.querySelector( '[data-curio-teaser]' );
	var teaserDismiss = root.querySelector( '[data-curio-teaser-dismiss]' );
	var resetButton = root.querySelector( '[data-curio-reset]' );
	var handoff = root.querySelector( '[data-curio-handoff]' );
	var announcer = root.querySelector( '[data-curio-status]' );

	if ( ! panel || ! log || ! form || ! input || ! toggle ) {
		return;
	}

	// The site owner can choose to offer a person from the start rather than
	// only after a decline, and PHP expresses that by rendering the row
	// already visible. Read once, before anything moves it.
	var handoffAlways = Boolean( handoff && ! handoff.hidden );

	var state = {
		open: false,
		expanded: false,
		busy: false,
		greeted: false,
		handoff: handoffAlways,
		token: '',
		history: [],
		messages: [],
		lastFocus: null
	};

	// ---------------------------------------------------------------------
	// Surviving a page load
	// ---------------------------------------------------------------------

	/**
	 * Where this site keeps the conversation.
	 *
	 * Keyed by the REST root, so two installations sharing an origin — which
	 * is what a multisite in subdirectories is — cannot read each other's
	 * conversations.
	 */
	var STORE_KEY = 'curio.chat.' + ( config.root || '' );

	/**
	 * sessionStorage rather than localStorage, and the difference is the whole
	 * decision: a conversation should survive a reload, a link followed and
	 * come back from, and a trip through a payment gateway — and it should not
	 * still be sitting there tomorrow on a shared machine.
	 */
	var STORE_TTL = 12 * 60 * 60 * 1000;

	/** How many bubbles are worth keeping. */
	var STORE_LIMIT = 40;

	var storageBroken = false;

	/**
	 * Read the saved conversation.
	 *
	 * Every access is wrapped. Touching sessionStorage throws outright in some
	 * privacy modes and when a browser has been told to block site data, and a
	 * chat widget that dies on load because of it is a worse fault than the one
	 * this was written to fix.
	 *
	 * @return {Object|null} Saved conversation, or null.
	 */
	function readStore() {
		if ( storageBroken ) {
			return null;
		}
		try {
			var raw = window.sessionStorage.getItem( STORE_KEY );
			if ( ! raw ) {
				return null;
			}
			var saved = JSON.parse( raw );
			if ( ! saved || saved.v !== 1 || ! Array.isArray( saved.messages ) ) {
				return null;
			}
			if ( ! saved.at || ( Date.now() - saved.at ) > STORE_TTL ) {
				return null;
			}
			return saved;
		} catch ( error ) {
			storageBroken = true;
			return null;
		}
	}

	/**
	 * Save the conversation as it currently stands.
	 */
	function writeStore() {
		if ( storageBroken ) {
			return;
		}
		if ( ! state.messages.length && ! state.open ) {
			clearStore();
			return;
		}
		try {
			window.sessionStorage.setItem( STORE_KEY, JSON.stringify( {
				v: 1,
				at: Date.now(),
				open: state.open,
				expanded: state.expanded,
				greeted: state.greeted,
				handoff: state.handoff,
				token: state.token,
				messages: state.messages.slice( -STORE_LIMIT ),
				history: state.history.slice( -( ( config.historyTurns || 4 ) * 2 ) )
			} ) );
		} catch ( error ) {
			// Out of quota, or refused. The conversation carries on in memory,
			// but the half-written snapshot already in storage must go: leaving
			// it there means the next page load restores a conversation that is
			// missing everything said after this point, which is worse than
			// restoring nothing at all.
			storageBroken = true;
			clearStore();
		}
	}

	/**
	 * Forget the saved conversation.
	 */
	function clearStore() {
		try {
			window.sessionStorage.removeItem( STORE_KEY );
		} catch ( error ) {
			// Nothing further to try. Reading is guarded too, so a store that
			// cannot be cleared is at worst ignored on the next load.
			storageBroken = true;
		}
	}

	/**
	 * Coerce a stored role back into one this widget will render.
	 *
	 * @param {string} role Saved role.
	 * @return {string} 'user', 'error' or 'assistant'.
	 */
	function normaliseRole( role ) {
		if ( role === 'user' ) {
			return 'user';
		}
		return role === 'error' ? 'error' : 'assistant';
	}

	// ---------------------------------------------------------------------
	// Safe rendering
	// ---------------------------------------------------------------------

	var SAFE_PROTOCOLS = [ 'http:', 'https:', 'mailto:', 'tel:' ];

	/**
	 * Resolve a link target, returning null for anything not plainly safe.
	 *
	 * `javascript:` is the obvious one. The reason this uses the URL parser
	 * rather than a regular expression is the unobvious ones: whitespace,
	 * control characters and mixed case all defeat naive prefix checks, and the
	 * browser's own parser is the only thing that agrees with the browser.
	 *
	 * @param {string} raw Candidate URL.
	 * @return {string|null} Absolute URL, or null.
	 */
	function safeUrl( raw ) {
		try {
			var url = new URL( String( raw ), window.location.href );
			return SAFE_PROTOCOLS.indexOf( url.protocol ) === -1 ? null : url.href;
		} catch ( error ) {
			return null;
		}
	}

	/**
	 * Render one line of light markdown into a parent node.
	 *
	 * Everything not recognised stays literal text. There is no fall-through
	 * path where unmatched input reaches the DOM as markup.
	 *
	 * @param {string}      line   Source line.
	 * @param {HTMLElement} parent Node to append into.
	 */
	function renderInline( line, parent ) {
		var pattern = /(\*\*[^*]+\*\*)|(\*[^*\n]+\*)|(`[^`\n]+`)|(\[[^\]\n]+\]\([^)\s]+\))/g;
		var cursor = 0;
		var match;

		while ( ( match = pattern.exec( line ) ) !== null ) {
			if ( match.index > cursor ) {
				parent.appendChild( document.createTextNode( line.slice( cursor, match.index ) ) );
			}

			var token = match[ 0 ];
			var node;

			if ( token.slice( 0, 2 ) === '**' ) {
				node = document.createElement( 'strong' );
				node.textContent = token.slice( 2, -2 );
			} else if ( token.charAt( 0 ) === '*' ) {
				node = document.createElement( 'em' );
				node.textContent = token.slice( 1, -1 );
			} else if ( token.charAt( 0 ) === '`' ) {
				node = document.createElement( 'code' );
				node.textContent = token.slice( 1, -1 );
			} else {
				var split = token.indexOf( '](' );
				var label = token.slice( 1, split );
				var href = safeUrl( token.slice( split + 2, -1 ) );

				if ( href ) {
					node = document.createElement( 'a' );
					node.textContent = label;
					node.setAttribute( 'href', href );
					node.setAttribute( 'rel', 'nofollow noopener' );
					node.setAttribute( 'target', '_blank' );
				} else {
					// An unsafe target degrades to its own label as plain text
					// rather than silently vanishing.
					node = document.createTextNode( label );
				}
			}

			parent.appendChild( node );
			cursor = match.index + token.length;
		}

		if ( cursor < line.length ) {
			parent.appendChild( document.createTextNode( line.slice( cursor ) ) );
		}
	}

	/**
	 * Render a whole reply into a container as paragraphs and lists.
	 *
	 * @param {string}      text      Reply text.
	 * @param {HTMLElement} container Node to append into.
	 */
	function renderText( text, container ) {
		var blocks = String( text ).replace( /\r\n?/g, '\n' ).split( /\n{2,}/ );

		blocks.forEach( function ( block ) {
			var lines = block.split( '\n' ).filter( function ( line ) {
				return line.trim() !== '';
			} );

			if ( ! lines.length ) {
				return;
			}

			var bulleted = lines.every( function ( line ) {
				return /^\s*[-*•]\s+/.test( line );
			} );

			if ( bulleted ) {
				var list = document.createElement( 'ul' );
				lines.forEach( function ( line ) {
					var item = document.createElement( 'li' );
					renderInline( line.replace( /^\s*[-*•]\s+/, '' ), item );
					list.appendChild( item );
				} );
				container.appendChild( list );
				return;
			}

			var paragraph = document.createElement( 'p' );
			lines.forEach( function ( line, index ) {
				if ( index > 0 ) {
					paragraph.appendChild( document.createElement( 'br' ) );
				}
				renderInline( line, paragraph );
			} );
			container.appendChild( paragraph );
		} );
	}

	// ---------------------------------------------------------------------
	// Messages
	// ---------------------------------------------------------------------

	/**
	 * Draw one message bubble into the log.
	 *
	 * Drawing and remembering are separate because a restored conversation has
	 * to be drawn without being remembered a second time.
	 *
	 * @param {string} text    Message text.
	 * @param {string} role    'user', 'assistant' or 'error'.
	 * @param {Array}  sources Optional source links.
	 * @return {HTMLElement} The bubble.
	 */
	function renderMessage( text, role, sources ) {
		var bubble = document.createElement( 'div' );
		bubble.className = 'curio-message curio-message-' + role;

		// Screen readers get told who is speaking; sighted users get the
		// alignment and colour that already say it.
		var label = document.createElement( 'span' );
		label.className = 'curio-visually-hidden';
		label.textContent = ( role === 'user' ? strings.youSaid : strings.assistantSaid ) || '';
		bubble.appendChild( label );

		renderText( text, bubble );

		if ( Array.isArray( sources ) && sources.length ) {
			var list = document.createElement( 'div' );
			list.className = 'curio-sources';

			sources.forEach( function ( source ) {
				var href = safeUrl( source && source.url );
				if ( ! href || ! source.title ) {
					return;
				}
				var link = document.createElement( 'a' );
				link.className = 'curio-source';
				link.setAttribute( 'href', href );
				link.setAttribute( 'rel', 'nofollow noopener' );
				link.setAttribute( 'target', '_blank' );
				link.textContent = source.title;
				list.appendChild( link );
			} );

			if ( list.childNodes.length ) {
				bubble.appendChild( list );
			}
		}

		log.appendChild( bubble );
		scrollToEnd();
		return bubble;
	}

	/**
	 * Add a message to the conversation, and remember it.
	 *
	 * @param {string} text    Message text.
	 * @param {string} role    'user', 'assistant' or 'error'.
	 * @param {Array}  sources Optional source links.
	 * @return {HTMLElement} The bubble.
	 */
	function addMessage( text, role, sources ) {
		var bubble = renderMessage( text, role, sources );

		state.messages.push( {
			role: normaliseRole( role ),
			text: String( text ),
			sources: Array.isArray( sources ) ? sources : []
		} );

		if ( state.messages.length > STORE_LIMIT ) {
			state.messages = state.messages.slice( -STORE_LIMIT );
		}

		writeStore();
		return bubble;
	}

	/**
	 * Put a saved conversation back on the screen.
	 *
	 * A visitor who reloads, follows a link and comes back, or is bounced
	 * through a payment gateway should find the conversation where they left
	 * it. Losing it is the complaint every chat widget collects, and the reason
	 * people give up rather than type the same question a second time.
	 */
	function restore() {
		var saved = readStore();
		if ( ! saved ) {
			return;
		}

		// Only what can actually be drawn is kept. Holding on to an entry that
		// rendered nothing would put state.messages permanently out of step
		// with the bubbles on screen — which is what decides whether to greet,
		// whether to ask before clearing, and whether to show the nudge — and
		// would write the same unusable entry back out on the next save.
		var restored = [];

		saved.messages.forEach( function ( message ) {
			if ( ! message || typeof message.text !== 'string' ) {
				return;
			}
			var role = normaliseRole( message.role );
			renderMessage( message.text, role, message.sources );
			restored.push( {
				role: role,
				text: message.text,
				sources: Array.isArray( message.sources ) ? message.sources : []
			} );
		} );

		state.messages = restored.slice( -STORE_LIMIT );
		state.history = Array.isArray( saved.history ) ? saved.history.slice() : [];
		state.token = typeof saved.token === 'string' ? saved.token : '';
		state.greeted = Boolean( saved.greeted ) || state.messages.length > 0;
		state.open = Boolean( saved.open );
		state.expanded = Boolean( saved.expanded );

		if ( saved.handoff && handoff ) {
			state.handoff = true;
			handoff.hidden = false;
		}
	}

	/**
	 * Empty the conversation and start again.
	 */
	function reset() {
		// Only worth asking about when there is something to lose. Somebody who
		// has read the greeting and nothing else should not have to dismiss a
		// dialog to be rid of it.
		if ( state.messages.length > 1 && strings.confirmReset && ! window.confirm( strings.confirmReset ) ) {
			return;
		}

		log.textContent = '';
		state.messages = [];
		state.history = [];
		state.greeted = false;
		state.handoff = handoffAlways;

		if ( handoff ) {
			handoff.hidden = ! handoffAlways;
		}

		clearStore();

		if ( announcer ) {
			announcer.textContent = strings.cleared || '';
		}

		greet();
		writeStore();

		input.value = '';
		resize();

		// Focus stays on the button that was pressed. Moving it to the composer
		// would be convenient for a mouse user and would also flush the status
		// message above in favour of announcing the textarea, which is the one
		// person who needed telling hearing nothing.
	}

	/**
	 * Say hello, once per conversation.
	 */
	function greet() {
		if ( state.greeted ) {
			return;
		}
		state.greeted = true;

		if ( strings.welcome ) {
			addMessage( strings.welcome, 'assistant' );
			state.history.push( { role: 'assistant', content: strings.welcome } );
		}
	}

	/**
	 * Offer the route to a person.
	 *
	 * Revealed the first time the assistant cannot answer, and left in place
	 * for the rest of the conversation. A decline is the moment a customer is
	 * most likely to give up, which is exactly the moment the site owner filled
	 * this destination in for.
	 */
	function showHandoff() {
		if ( ! handoff || state.handoff ) {
			return;
		}
		state.handoff = true;
		handoff.hidden = false;
		writeStore();
		scrollToEnd();
	}

	/**
	 * Keep the newest message in view.
	 */
	function scrollToEnd() {
		log.scrollTop = log.scrollHeight;
	}

	/**
	 * Show or hide the typing indicator and lock the composer.
	 *
	 * @param {boolean} busy Whether a request is in flight.
	 */
	function setBusy( busy ) {
		state.busy = busy;
		if ( typing ) {
			typing.hidden = ! busy;
		}
		// Disabling the element that currently has focus makes the browser drop
		// focus to <body>, which is outside the panel — and the Tab trap only
		// recognises the first and last focusable elements, so from <body> it
		// does nothing and the next Tab walks out of a dialog covering the
		// page. Move focus somewhere sensible first.
		var focused = document.activeElement;
		if ( busy && ( focused === sendButton || focused === resetButton ) ) {
			input.focus();
		}

		if ( sendButton ) {
			sendButton.disabled = busy;
		}
		// Clearing while a reply is in flight would empty the log and then have
		// the answer to the cleared question arrive in it a second later.
		if ( resetButton ) {
			resetButton.disabled = busy;
		}
		input.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		if ( busy ) {
			scrollToEnd();
		}
	}

	// ---------------------------------------------------------------------
	// Networking
	// ---------------------------------------------------------------------

	/**
	 * Build request headers, including the REST nonce for logged-in users.
	 *
	 * @return {Object} Header map.
	 */
	function headers() {
		var out = { 'Content-Type': 'application/json' };
		if ( config.nonce ) {
			out['X-WP-Nonce'] = config.nonce;
		}
		return out;
	}

	/**
	 * Obtain a session token, reusing the current one when possible.
	 *
	 * @param {boolean} force Fetch a new one even if we hold one.
	 * @return {Promise<string>} The token.
	 */
	function ensureToken( force ) {
		if ( state.token && ! force ) {
			return Promise.resolve( state.token );
		}
		return fetch( config.root + '/session', {
			method: 'POST',
			headers: headers(),
			credentials: 'same-origin'
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				state.token = ( data && data.token ) || '';
				writeStore();
				return state.token;
			} );
	}

	/**
	 * Send a question and render the reply.
	 *
	 * Retries exactly once on an expired session. A page that has been open in
	 * a background tab for three hours has a dead token, and the visitor should
	 * not have to discover that by being told to reload.
	 *
	 * @param {string}  question Visitor's question.
	 * @param {boolean} retried  Whether this is already the retry.
	 */
	function send( question, retried ) {
		setBusy( true );

		ensureToken( Boolean( retried ) )
			.then( function ( token ) {
				return fetch( config.root + '/message', {
					method: 'POST',
					headers: headers(),
					credentials: 'same-origin',
					body: JSON.stringify( {
						message: question,
						token: token,
						history: state.history.slice( -( ( config.historyTurns || 4 ) * 2 ) ),
						page_url: window.location.href
					} )
				} ).then( function ( response ) {
					return response.json().then( function ( data ) {
						return { ok: response.ok, status: response.status, data: data };
					} );
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok ) {
					if ( result.status === 403 && result.data && result.data.code === 'curio_session' && ! retried ) {
						setBusy( false );
						send( question, true );
						return;
					}
					setBusy( false );
					addMessage( ( result.data && result.data.message ) || strings.error || '', 'error' );
					// Something went wrong on the way to an answer, which is
					// precisely when a visitor wants a person instead.
					showHandoff();
					return;
				}

				setBusy( false );

				var answer = ( result.data && result.data.answer ) || '';
				addMessage( answer, 'assistant', result.data && result.data.sources );

				state.history.push( { role: 'assistant', content: answer } );
				trimHistory();
				writeStore();

				// The server says whether it had anything to ground that reply
				// in. It did not, so the visitor has just been told no.
				if ( result.data && result.data.declined ) {
					showHandoff();
				}
			} )
			.catch( function () {
				setBusy( false );
				addMessage(
					navigator.onLine === false
						? strings.offline || strings.error || ''
						: strings.error || '',
					'error'
				);
				showHandoff();
			} );
	}

	/**
	 * Keep only as many turns as the server will actually use.
	 */
	function trimHistory() {
		var limit = ( config.historyTurns || 4 ) * 2;
		if ( state.history.length > limit ) {
			state.history = state.history.slice( -limit );
		}
	}

	// ---------------------------------------------------------------------
	// Open, close, expand
	// ---------------------------------------------------------------------

	/**
	 * Reflect the current state on the root element.
	 */
	function paint() {
		var value = state.open ? ( state.expanded ? 'expanded' : 'open' ) : 'closed';
		root.setAttribute( 'data-curio-state', value );
		panel.hidden = ! state.open;
		toggle.setAttribute( 'aria-expanded', state.open ? 'true' : 'false' );
		// The launcher only ever opens now — it hides while the panel is up, and
		// the panel's own header button is what closes it. Labelling it "close
		// chat" while it is off screen would just be a lie waiting to be read
		// out by a screen reader.
		toggle.setAttribute( 'aria-label', strings.open || '' );

		if ( expandButton ) {
			expandButton.setAttribute(
				'aria-label',
				state.expanded ? strings.collapse || '' : strings.expand || ''
			);
		}

		writeStore();
		fitViewport();
	}

	// ---------------------------------------------------------------------
	// Fitting the visible screen on a phone
	// ---------------------------------------------------------------------

	/**
	 * Below 600px the stylesheet makes the panel the whole screen with
	 * `position: fixed; inset: 0`. That is the whole *layout* viewport, and on
	 * a phone the layout viewport is not what the visitor can see. When the
	 * keyboard comes up, Chrome and Safari leave the layout viewport alone,
	 * cover its lower half with keys, and pan the *visual* viewport down until
	 * the focused field sits just above them. So the header and the first
	 * bubbles slide off the top of the screen, the composer rests on the
	 * keyboard, and the visitor is looking at a cut-off answer above a field
	 * of nothing. That was the demo page on an Android phone, 18 September.
	 *
	 * The visualViewport API reports the part that is actually visible, so
	 * while the panel is open on a small screen it is pinned to that: the top
	 * follows the pan and the height follows the keyboard, as two custom
	 * properties the small-screen rules read. Everywhere else, and wherever
	 * the API is missing, the properties are absent and the CSS means "the
	 * whole screen", which is what it meant before.
	 */
	var viewport = window.visualViewport || null;
	var smallScreen = window.matchMedia ? window.matchMedia( '(max-width: 600px)' ) : null;

	function fitViewport() {
		if ( ! viewport || ! state.open || ! smallScreen || ! smallScreen.matches ) {
			root.style.removeProperty( '--curio-vv-top' );
			root.style.removeProperty( '--curio-vv-height' );
			return;
		}
		root.style.setProperty( '--curio-vv-top', Math.round( viewport.offsetTop ) + 'px' );
		root.style.setProperty( '--curio-vv-height', Math.round( viewport.height ) + 'px' );
	}

	if ( viewport ) {
		// The keyboard opening or closing changes the height; the log is
		// scrolled back to the newest bubble so what the visitor was reading
		// stays in view rather than disappearing under the keys.
		viewport.addEventListener( 'resize', function () {
			fitViewport();
			scrollToEnd();
		} );
		// A pan (the keyboard pushing the field into view, a pinch-zoom)
		// changes only where the visible part starts.
		viewport.addEventListener( 'scroll', fitViewport );
	}
	if ( smallScreen && typeof smallScreen.addEventListener === 'function' ) {
		smallScreen.addEventListener( 'change', fitViewport );
	}

	/**
	 * Open the panel.
	 */
	function open() {
		if ( state.open ) {
			return;
		}
		state.lastFocus = document.activeElement;
		state.open = true;
		paint();
		hideTeaser();

		if ( ! state.greeted ) {
			greet();
			// Warm the session up while the visitor is still reading the
			// greeting, so their first message does not wait on two round
			// trips instead of one.
			ensureToken( false ).catch( function () {} );
		}

		window.requestAnimationFrame( function () {
			input.focus();
			scrollToEnd();
		} );
	}

	/**
	 * Close the panel and return focus where it came from.
	 */
	function close() {
		if ( ! state.open ) {
			return;
		}
		state.open = false;
		state.expanded = false;
		paint();

		var target = state.lastFocus && document.contains( state.lastFocus ) ? state.lastFocus : toggle;
		if ( target && typeof target.focus === 'function' ) {
			target.focus();
		}
	}

	/**
	 * Switch between the corner panel and the full-page view.
	 */
	function toggleExpand() {
		state.expanded = ! state.expanded;
		paint();
		scrollToEnd();
		input.focus();
	}

	/**
	 * Hide the teaser bubble for the rest of this page view.
	 */
	function hideTeaser() {
		if ( teaser ) {
			teaser.hidden = true;
		}
	}

	// ---------------------------------------------------------------------
	// Events
	// ---------------------------------------------------------------------

	toggle.addEventListener( 'click', function () {
		if ( state.open ) {
			close();
		} else {
			open();
		}
	} );

	if ( closeButton ) {
		closeButton.addEventListener( 'click', close );
	}

	if ( expandButton ) {
		expandButton.addEventListener( 'click', toggleExpand );
	}

	if ( resetButton ) {
		resetButton.addEventListener( 'click', reset );
	}

	if ( teaserDismiss ) {
		teaserDismiss.addEventListener( 'click', hideTeaser );
	}

	if ( teaser ) {
		// Delayed so it does not compete with the page the visitor came to read,
		// and never shown to somebody who is already mid-conversation.
		window.setTimeout( function () {
			if ( ! state.open && ! state.messages.length ) {
				teaser.hidden = false;
			}
		}, 3500 );

		teaser.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '[data-curio-teaser-dismiss]' ) ) {
				return;
			}
			open();
		} );
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		submit();
	} );

	/**
	 * Validate and dispatch whatever is in the composer.
	 */
	function submit() {
		var question = input.value.trim();
		if ( ! question || state.busy ) {
			return;
		}
		if ( config.maxLength && question.length > config.maxLength ) {
			question = question.slice( 0, config.maxLength );
		}

		addMessage( question, 'user' );
		state.history.push( { role: 'user', content: question } );
		trimHistory();

		input.value = '';
		resize();
		send( question, false );
	}

	input.addEventListener( 'keydown', function ( event ) {
		// Enter sends; Shift+Enter writes a new line. The other way round
		// surprises everyone who has ever used a chat application.
		if ( event.key === 'Enter' && ! event.shiftKey ) {
			event.preventDefault();
			submit();
		}
	} );

	input.addEventListener( 'input', resize );

	/**
	 * Grow the composer with its content, up to the CSS maximum.
	 */
	function resize() {
		input.style.height = 'auto';
		input.style.height = Math.min( input.scrollHeight, 120 ) + 'px';
	}

	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key !== 'Escape' || ! state.open ) {
			return;
		}
		if ( state.expanded ) {
			toggleExpand();
			return;
		}
		close();
	} );

	// Keep focus inside the panel while it covers the page, and let it roam
	// freely when it is a corner widget somebody may want to tab past.
	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key !== 'Tab' || ! state.open || ! state.expanded ) {
			return;
		}

		var focusable = panel.querySelectorAll(
			'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])'
		);
		if ( ! focusable.length ) {
			return;
		}

		var first = focusable[ 0 ];
		var last = focusable[ focusable.length - 1 ];

		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	} );

	restore();
	paint();

	if ( state.open ) {
		// The log was filled while the panel was still hidden, so it has no
		// height to scroll yet. Focus is deliberately left alone: a page that
		// steals it on load takes the keyboard away from whatever the visitor
		// was actually doing.
		window.requestAnimationFrame( scrollToEnd );
	}
}() );
