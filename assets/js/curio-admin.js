/**
 * Curio settings screen.
 *
 * jQuery is present only because WordPress's colour picker and media modal are
 * jQuery components; everything this file writes itself is plain DOM. As on the
 * front end, no untrusted value is ever assigned to innerHTML — knowledge
 * entries are written by administrators but their *contents* came from web
 * pages and visitors, and a settings screen is exactly where a stored payload
 * would like to be opened by somebody with capabilities.
 */
( function ( $ ) {
	'use strict';

	var settings = window.curioAdmin || {};
	var strings = settings.strings || {};

	/**
	 * Call one of the plugin's REST routes.
	 *
	 * @param {string} path   Route path, e.g. '/knowledge'.
	 * @param {Object} config Fetch options; `data` is JSON encoded for you.
	 * @return {Promise<Object>} Decoded response body.
	 */
	function api( path, config ) {
		config = config || {};

		var options = {
			method: config.method || 'GET',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': settings.nonce
			},
			credentials: 'same-origin'
		};

		if ( config.data ) {
			options.body = JSON.stringify( config.data );
		}

		return fetch( settings.root + path, options )
			.then( function ( response ) {
				return response
					.json()
					.catch( function () {
						return {};
					} )
					.then( function ( body ) {
						if ( ! response.ok && ! body.message ) {
							body.message = strings.networkError;
						}
						body._ok = response.ok;
						return body;
					} );
			} )
			.catch( function () {
				// The request never arrived — offline, a dropped connection, a
				// proxy that gave up. Without this every caller's promise
				// rejects unhandled and the screen sits on "Saving…" forever,
				// which reads as "still working" rather than "it failed".
				return { _ok: false, message: strings.networkError };
			} );
	}

	/**
	 * Write a short status message next to a control.
	 *
	 * @param {Element} node  Feedback element.
	 * @param {string}  text  Message.
	 * @param {string}  tone  '', 'good' or 'bad'.
	 */
	function say( node, text, tone ) {
		if ( ! node ) {
			return;
		}
		node.textContent = text || '';
		node.classList.remove( 'curio-is-good', 'curio-is-bad' );
		if ( tone ) {
			node.classList.add( 'curio-is-' + tone );
		}
	}

	/**
	 * Substitute a single value into a translated string.
	 *
	 * The strings themselves live in PHP so translators can reach them; only
	 * the number is worked out here.
	 *
	 * @param {string} template Translated string containing %s.
	 * @param {string} value    Replacement.
	 * @return {string} The finished string.
	 */
	function format( template, value ) {
		return String( template || '' ).replace( '%s', value );
	}

	/**
	 * Build an element with text content in one call.
	 *
	 * @param {string} tag       Tag name.
	 * @param {string} className Class attribute.
	 * @param {string} text      Text content.
	 * @return {HTMLElement} The element.
	 */
	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined && text !== null ) {
			node.textContent = text;
		}
		return node;
	}

	// =====================================================================
	// Knowledge tab
	// =====================================================================

	function initKnowledge() {
		var form = document.querySelector( '[data-curio-entry-form]' );
		var list = document.querySelector( '[data-curio-list]' );

		if ( ! list ) {
			return;
		}

		var idField = document.querySelector( '[data-curio-entry-id]' );
		var titleField = document.getElementById( 'curio-entry-title' );
		var contentField = document.getElementById( 'curio-entry-content' );
		var keywordsField = document.getElementById( 'curio-entry-keywords' );
		var urlField = document.getElementById( 'curio-entry-url' );
		var resetButton = document.querySelector( '[data-curio-entry-reset]' );
		var entryFeedback = document.querySelector( '[data-curio-entry-feedback]' );

		var searchField = document.querySelector( '[data-curio-search]' );
		var sourceField = document.querySelector( '[data-curio-filter-source]' );
		var prevButton = document.querySelector( '[data-curio-prev]' );
		var nextButton = document.querySelector( '[data-curio-next]' );
		var pageLabel = document.querySelector( '[data-curio-page-label]' );

		var page = 1;
		var perPage = 20;
		var searchTimer = null;

		var sourceLabels = {
			manual: strings.sourceManual,
			post: strings.sourcePost,
			product: strings.sourceProduct
		};

		/**
		 * Fetch and draw the current page of entries.
		 */
		function load() {
			var query =
				'?page=' +
				page +
				'&per_page=' +
				perPage +
				'&search=' +
				encodeURIComponent( searchField ? searchField.value : '' ) +
				'&source_type=' +
				encodeURIComponent( sourceField ? sourceField.value : '' );

			api( '/knowledge' + query ).then( function ( data ) {
				render( data.items || [] );

				if ( pageLabel ) {
					pageLabel.textContent = String( page );
				}
				if ( prevButton ) {
					prevButton.disabled = page <= 1;
				}
				if ( nextButton ) {
					nextButton.disabled = ( data.items || [] ).length < perPage;
				}
			} );
		}

		/**
		 * Draw a page of entries.
		 *
		 * @param {Array} items Entry rows.
		 */
		function render( items ) {
			list.textContent = '';

			if ( ! items.length ) {
				list.appendChild( el( 'p', 'curio-empty', strings.empty ) );
				return;
			}

			items.forEach( function ( item ) {
				var row = el( 'div', 'curio-item' );
				var main = el( 'div', 'curio-item-main' );

				main.appendChild( el( 'p', 'curio-item-title', item.title || '—' ) );

				var body = String( item.content || '' );
				main.appendChild(
					el( 'p', 'curio-item-body', body.length > 220 ? body.slice( 0, 220 ) + '…' : body )
				);

				var meta = el( 'div', 'curio-item-meta' );
				meta.appendChild(
					el( 'span', 'curio-pill', sourceLabels[ item.source_type ] || item.source_type )
				);
				if ( item.keywords ) {
					meta.appendChild( el( 'span', 'curio-pill', format( strings.keywordsPill, item.keywords ) ) );
				}
				main.appendChild( meta );
				row.appendChild( main );

				var actions = el( 'div', 'curio-item-actions' );

				if ( item.source_type === 'manual' ) {
					var edit = el( 'button', 'button button-small', strings.edit );
					edit.type = 'button';
					edit.addEventListener( 'click', function () {
						fill( item );
					} );
					actions.appendChild( edit );
				}

				var remove = el( 'button', 'button button-small button-link-delete', strings.delete );
				remove.type = 'button';
				remove.addEventListener( 'click', function () {
					if ( ! window.confirm( strings.confirmDelete ) ) {
						return;
					}
					api( '/knowledge/' + parseInt( item.id, 10 ), { method: 'DELETE' } ).then( load );
				} );
				actions.appendChild( remove );

				row.appendChild( actions );
				list.appendChild( row );
			} );
		}

		/**
		 * Load an entry into the form for editing.
		 *
		 * @param {Object} item Entry row.
		 */
		function fill( item ) {
			idField.value = item.id || 0;
			titleField.value = item.title || '';
			contentField.value = item.content || '';
			keywordsField.value = ( item.keywords || '' ).split( ' ' ).join( ', ' );
			urlField.value = item.url || '';
			if ( resetButton ) {
				resetButton.hidden = false;
			}
			titleField.focus();
			titleField.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}

		/**
		 * Return the form to its "add new" state.
		 */
		function reset() {
			idField.value = 0;
			form.reset();
			if ( resetButton ) {
				resetButton.hidden = true;
			}
		}

		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				say( entryFeedback, strings.saving );

				api( '/knowledge', {
					method: 'POST',
					data: {
						id: parseInt( idField.value, 10 ) || 0,
						title: titleField.value,
						content: contentField.value,
						keywords: keywordsField.value,
						url: urlField.value
					}
				} ).then( function ( data ) {
					if ( ! data._ok ) {
						say( entryFeedback, data.message || strings.networkError, 'bad' );
						return;
					}
					say( entryFeedback, strings.saved, 'good' );
					reset();
					load();
				} );
			} );
		}

		if ( resetButton ) {
			resetButton.addEventListener( 'click', reset );
		}

		if ( searchField ) {
			searchField.addEventListener( 'input', function () {
				window.clearTimeout( searchTimer );
				searchTimer = window.setTimeout( function () {
					page = 1;
					load();
				}, 300 );
			} );
		}

		if ( sourceField ) {
			sourceField.addEventListener( 'change', function () {
				page = 1;
				load();
			} );
		}

		if ( prevButton ) {
			prevButton.addEventListener( 'click', function () {
				page = Math.max( 1, page - 1 );
				load();
			} );
		}

		if ( nextButton ) {
			nextButton.addEventListener( 'click', function () {
				page += 1;
				load();
			} );
		}

		// Arriving from "Answer this" on the Insights tab, with the visitor's
		// own wording already in the box.
		var params = new URLSearchParams( window.location.search );
		var prefill = params.get( 'curio_prefill' );
		if ( prefill && titleField ) {
			titleField.value = prefill;
			contentField.focus();
		}

		// Import, export, clear.
		var importField = document.querySelector( '[data-curio-import]' );
		var importFeedback = document.querySelector( '[data-curio-import-feedback]' );
		var importButton = document.querySelector( '[data-curio-import-run]' );
		var exportButton = document.querySelector( '[data-curio-export]' );

		if ( importButton ) {
			importButton.addEventListener( 'click', function () {
				say( importFeedback, strings.saving );
				api( '/knowledge/import', {
					method: 'POST',
					data: { json: importField.value }
				} ).then( function ( data ) {
					say( importFeedback, data.message || strings.networkError, data._ok ? 'good' : 'bad' );
					if ( data._ok ) {
						importField.value = '';
						load();
					}
				} );
			} );
		}

		if ( exportButton ) {
			exportButton.addEventListener( 'click', function () {
				api( '/knowledge/export' ).then( function ( data ) {
					var items = data.items || [];
					if ( ! items.length ) {
						say( importFeedback, strings.nothingToExport, 'bad' );
						return;
					}
					importField.value = JSON.stringify( items, null, 2 );
					importField.select();
					say( importFeedback, strings.copied, 'good' );
				} );
			} );
		}

		document.querySelectorAll( '[data-curio-clear]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				if ( ! window.confirm( strings.confirmClear ) ) {
					return;
				}
				api( '/knowledge/clear', {
					method: 'POST',
					data: { scope: button.getAttribute( 'data-curio-clear' ) }
				} ).then( function () {
					window.location.reload();
				} );
			} );
		} );

		load();
	}

	// =====================================================================
	// Sources tab: batched indexing
	// =====================================================================

	function initIndexing() {
		var button = document.querySelector( '[data-curio-reindex]' );
		if ( ! button ) {
			return;
		}

		var wrapper = document.querySelector( '[data-curio-progress]' );
		var fill = document.querySelector( '[data-curio-progress-fill]' );
		var label = document.querySelector( '[data-curio-progress-label]' );

		/**
		 * Update the bar.
		 *
		 * @param {Object} state Indexer state from the server.
		 */
		function paint( state ) {
			var total = state.total || 0;
			var done = state.done || 0;
			var percent = total > 0 ? Math.round( ( done / total ) * 100 ) : 100;

			if ( fill ) {
				fill.style.width = percent + '%';
			}
			if ( label ) {
				label.textContent = ( strings.progress || '%1$d / %2$d' )
					.replace( '%1$d', done )
					.replace( '%2$d', total );
			}
		}

		/**
		 * Work through the queue one batch at a time.
		 *
		 * Serially, and driven by the browser rather than by cron. A single
		 * request that indexes a whole site is a request that times out on
		 * shared hosting, and a cron job that does it is one the site owner
		 * cannot see the progress of.
		 */
		/**
		 * Stop, and say why.
		 *
		 * A batch that fails leaves `running` undefined, which looks exactly
		 * like "finished" — so without this a run that died a third of the way
		 * through reported 100% and a green "Indexing finished.", and the site
		 * owner went away believing content had been indexed that had not. An
		 * index that stops must say so.
		 *
		 * @param {string} message What to show.
		 */
		function fail( message ) {
			button.disabled = false;
			if ( label ) {
				label.classList.remove( 'curio-is-good' );
				label.classList.add( 'curio-is-bad' );
				label.textContent = message || strings.networkError;
			}
		}

		/**
		 * Stop, having got to the end.
		 */
		function finished() {
			button.disabled = false;
			if ( label ) {
				label.classList.remove( 'curio-is-bad' );
				label.classList.add( 'curio-is-good' );
				label.textContent = strings.indexDone;
			}
		}

		function step() {
			api( '/index/run', { method: 'POST', data: { size: 10 } } ).then( function ( state ) {
				if ( state._ok === false ) {
					fail( state.message );
					return;
				}

				paint( state );

				if ( state.running ) {
					window.setTimeout( step, 120 );
					return;
				}

				finished();
			} );
		}

		button.addEventListener( 'click', function () {
			button.disabled = true;
			if ( wrapper ) {
				wrapper.hidden = false;
			}
			if ( label ) {
				label.classList.remove( 'curio-is-good', 'curio-is-bad' );
				label.textContent = strings.indexing;
			}

			api( '/index/start', { method: 'POST' } ).then( function ( state ) {
				if ( state._ok === false ) {
					fail( state.message );
					return;
				}

				paint( state );

				if ( ! state.total ) {
					finished();
					return;
				}

				step();
			} );
		} );
	}

	// =====================================================================
	// Connection tab
	// =====================================================================

	function initConnection() {
		document.querySelectorAll( '[data-curio-key-row]' ).forEach( function ( row ) {
			var provider = row.getAttribute( 'data-curio-key-row' );
			var input = row.querySelector( '[data-curio-key-input]' );
			var feedback = row.querySelector( '[data-curio-key-feedback]' );
			var saveButton = row.querySelector( '[data-curio-key-save]' );
			var testButton = row.querySelector( '[data-curio-key-test]' );
			var deleteButton = row.querySelector( '[data-curio-key-delete]' );

			if ( saveButton ) {
				saveButton.addEventListener( 'click', function () {
					say( feedback, strings.saving );
					api( '/connection/key', {
						method: 'POST',
						data: { provider: provider, key: input.value }
					} ).then( function ( data ) {
						say( feedback, data.message || strings.networkError, data._ok ? 'good' : 'bad' );
						if ( data._ok ) {
							input.value = '';
							input.placeholder = data.masked || '';
						}
					} );
				} );
			}

			if ( testButton ) {
				testButton.addEventListener( 'click', function () {
					say( feedback, strings.testing );
					api( '/connection/test', {
						method: 'POST',
						data: { provider: provider }
					} ).then( function ( data ) {
						say( feedback, data.message || strings.networkError, data.ok ? 'good' : 'bad' );
					} );
				} );
			}

			if ( deleteButton ) {
				deleteButton.addEventListener( 'click', function () {
					api( '/connection/key', {
						method: 'DELETE',
						data: { provider: provider }
					} ).then( function () {
						window.location.reload();
					} );
				} );
			}
		} );

		var refresh = document.querySelector( '[data-curio-refresh-models]' );
		if ( refresh ) {
			refresh.addEventListener( 'click', function () {
				var select = document.querySelector( '[data-curio-model]' );
				var feedback = document.querySelector( '[data-curio-models-feedback]' );
				var chosen = document.querySelector( 'input[name$="[provider]"]:checked' );
				var provider = chosen ? chosen.value : '';

				say( feedback, strings.refreshing );

				api( '/models', { method: 'POST', data: { provider: provider } } ).then( function ( data ) {
					if ( ! data.ok ) {
						say( feedback, data.message || strings.networkError, 'bad' );
						return;
					}

					var current = select.value;
					select.textContent = '';

					Object.keys( data.models ).forEach( function ( id ) {
						var option = document.createElement( 'option' );
						option.value = id;
						option.textContent = data.models[ id ];
						if ( id === current ) {
							option.selected = true;
						}
						select.appendChild( option );
					} );

					say( feedback, data.message, 'good' );
				} );
			} );
		}

		var range = document.querySelector( '[data-curio-range]' );
		var rangeOut = document.querySelector( '[data-curio-range-out]' );
		if ( range && rangeOut ) {
			range.addEventListener( 'input', function () {
				rangeOut.textContent = range.value;
			} );
		}
	}

	// =====================================================================
	// Insights tab
	// =====================================================================

	function initInsights() {
		var button = document.querySelector( '[data-curio-clear-log]' );
		if ( ! button ) {
			return;
		}
		var feedback = document.querySelector( '[data-curio-log-feedback]' );

		button.addEventListener( 'click', function () {
			if ( ! window.confirm( strings.confirmClearLog ) ) {
				return;
			}
			api( '/log/clear', { method: 'POST' } ).then( function () {
				say( feedback, strings.saved, 'good' );
			} );
		} );
	}

	// =====================================================================
	// Appearance tab
	// =====================================================================

	function initAppearance() {
		var preview = document.querySelector( '[data-curio-preview]' );
		if ( ! preview ) {
			return;
		}

		var accentField = document.getElementById( 'curio-accent' );
		var accentTextField = document.getElementById( 'curio-accent-text' );
		var surfaceField = document.getElementById( 'curio-surface' );
		var textField = document.getElementById( 'curio-text-color' );
		var contrastNote = document.querySelector( '[data-curio-contrast]' );

		var radii = {
			sharp: [ '4px', '4px', '4px' ],
			soft: [ '16px', '14px', '10px' ],
			round: [ '24px', '20px', '999px' ]
		};

		/**
		 * Split a hex colour into channels, or return null.
		 *
		 * Null rather than black. Returning a colour for input that is not one
		 * meant the panel reported a contrast ratio it had computed for
		 * something else: type "rebeccapurple" and it certified 21:1, the
		 * figure for black, in green, while the preview beside it was purple.
		 * A contrast check that cannot read the colour has to say so.
		 *
		 * @param {string} hex Hex colour.
		 * @return {Array<number>|null} Red, green, blue — or null if unreadable.
		 */
		function rgb( hex ) {
			var value = String( hex || '' ).replace( '#', '' );
			if ( value.length === 3 ) {
				value = value[ 0 ] + value[ 0 ] + value[ 1 ] + value[ 1 ] + value[ 2 ] + value[ 2 ];
			}
			if ( ! /^[0-9a-f]{6}$/i.test( value ) ) {
				return null;
			}
			return [
				parseInt( value.slice( 0, 2 ), 16 ),
				parseInt( value.slice( 2, 4 ), 16 ),
				parseInt( value.slice( 4, 6 ), 16 )
			];
		}

		/**
		 * WCAG relative luminance.
		 *
		 * @param {string} hex Hex colour.
		 * @return {number} 0 to 1.
		 */
		function luminance( hex ) {
			var parsed = rgb( hex );
			if ( ! parsed ) {
				return null;
			}
			var channels = parsed.map( function ( value ) {
				var srgb = value / 255;
				return srgb <= 0.04045 ? srgb / 12.92 : Math.pow( ( srgb + 0.055 ) / 1.055, 2.4 );
			} );
			return 0.2126 * channels[ 0 ] + 0.7152 * channels[ 1 ] + 0.0722 * channels[ 2 ];
		}

		/**
		 * Contrast ratio between two colours, or null if either is unreadable.
		 *
		 * @param {string} one Hex colour.
		 * @param {string} two Hex colour.
		 * @return {number|null} 1 to 21, or null.
		 */
		function contrast( one, two ) {
			var a = luminance( one );
			var b = luminance( two );
			if ( a === null || b === null ) {
				return null;
			}
			return ( Math.max( a, b ) + 0.05 ) / ( Math.min( a, b ) + 0.05 );
		}

		/**
		 * Redraw the preview from whatever the form currently says.
		 */
		function paint() {
			var presetInput = document.querySelector( 'input[name$="[preset]"]:checked' );
			var preset = presetInput ? presetInput.value : 'light';
			var accent = ( accentField && accentField.value ) || '#1a1d21';

			var surface = '#ffffff';
			var text = '#1a1d21';

			if ( preset === 'dark' ) {
				surface = '#12161f';
				text = '#e9edf5';
			} else if ( preset === 'custom' ) {
				surface = ( surfaceField && surfaceField.value ) || surface;
				text = ( textField && textField.value ) || text;
			}

			var accentText = ( accentTextField && accentTextField.value ) || '';
			if ( ! accentText ) {
				var onWhite = contrast( accent, '#ffffff' );
				var onBlack = contrast( accent, '#111111' );
				// Half-typed input reaches this on every keystroke. White is the
				// safer guess while the accent is still unreadable, and the note
				// below says outright that nothing has been measured yet.
				accentText = ( null === onWhite || null === onBlack || onWhite >= onBlack ) ? '#ffffff' : '#111111';
			}

			preview.style.setProperty( '--curio-accent', accent );
			preview.style.setProperty( '--curio-accent-text', accentText );
			preview.style.setProperty( '--curio-surface', surface );
			preview.style.setProperty( '--curio-text', text );

			var radiusInput = document.querySelector( 'input[name$="[radius]"]:checked' );
			var radius = radii[ radiusInput ? radiusInput.value : 'soft' ] || radii.soft;
			preview.style.setProperty( '--curio-radius', radius[ 0 ] );
			preview.style.setProperty( '--curio-radius-bubble', radius[ 1 ] );
			preview.style.setProperty( '--curio-radius-field', radius[ 2 ] );

			var positionInput = document.querySelector( 'input[name$="[position]"]:checked' );
			preview.classList.toggle( 'curio-is-left', positionInput && positionInput.value === 'bottom-left' );

			// Telling somebody their brand colour fails contrast, at the moment
			// they pick it, is worth more than a paragraph about accessibility
			// in the readme.
			if ( contrastNote ) {
				var ratio = contrast( accent, accentText );

				if ( null === ratio ) {
					// Mid-keystroke, or a colour name the picker has not yet
					// resolved. Saying nothing beats asserting a number that
					// was measured against a colour nobody chose.
					say( contrastNote, strings.contrastUnknown, '' );
				} else {
					var rounded = String( Math.round( ratio * 10 ) / 10 );

					if ( ratio >= 4.5 ) {
						say( contrastNote, format( strings.contrastPass, rounded ), 'good' );
					} else if ( ratio >= 3 ) {
						say( contrastNote, format( strings.contrastLarge, rounded ), 'bad' );
					} else {
						say( contrastNote, format( strings.contrastFail, rounded ), 'bad' );
					}
				}
			}

			var titleField = document.getElementById( 'curio-business' );
			var titleOut = document.querySelector( '[data-curio-preview-title]' );
			if ( titleField && titleOut && titleField.value ) {
				titleOut.textContent = titleField.value;
			}
		}

		// The colour picker is a jQuery widget, so it is bound the jQuery way
		// and everything else is not.
		if ( $ && $.fn && $.fn.wpColorPicker ) {
			$( '.curio-color' ).wpColorPicker( {
				change: function () {
					window.setTimeout( paint, 20 );
				},
				clear: function () {
					window.setTimeout( paint, 20 );
				}
			} );
		}

		document.querySelectorAll( '[data-curio-appearance] input, [data-curio-appearance] select' ).forEach(
			function ( field ) {
				field.addEventListener( 'change', paint );
				field.addEventListener( 'input', paint );
			}
		);

		document.querySelectorAll( '[data-curio-swatch]' ).forEach( function ( swatch ) {
			swatch.addEventListener( 'click', function () {
				var colour = swatch.getAttribute( 'data-curio-swatch' );
				if ( $ && $.fn && $.fn.wpColorPicker ) {
					$( accentField ).wpColorPicker( 'color', colour );
				} else if ( accentField ) {
					accentField.value = colour;
				}
				paint();
			} );
		} );

		// Avatar picker.
		var chooseButton = document.querySelector( '[data-curio-avatar-choose]' );
		var clearButton = document.querySelector( '[data-curio-avatar-clear]' );
		var avatarInput = document.querySelector( '[data-curio-avatar-id]' );
		var avatarPreview = document.querySelector( '[data-curio-avatar-preview]' );
		var frame = null;

		if ( chooseButton && window.wp && window.wp.media ) {
			chooseButton.addEventListener( 'click', function ( event ) {
				event.preventDefault();

				if ( ! frame ) {
					frame = window.wp.media( {
						title: strings.chooseAvatar,
						button: { text: strings.useImage },
						library: { type: 'image' },
						multiple: false
					} );

					frame.on( 'select', function () {
						var attachment = frame.state().get( 'selection' ).first().toJSON();
						avatarInput.value = attachment.id;

						avatarPreview.textContent = '';
						var image = document.createElement( 'img' );
						image.src =
							attachment.sizes && attachment.sizes.thumbnail
								? attachment.sizes.thumbnail.url
								: attachment.url;
						image.alt = '';
						avatarPreview.appendChild( image );

						if ( clearButton ) {
							clearButton.hidden = false;
						}
					} );
				}

				frame.open();
			} );
		}

		if ( clearButton ) {
			clearButton.addEventListener( 'click', function () {
				avatarInput.value = '0';
				avatarPreview.textContent = '';
				clearButton.hidden = true;
			} );
		}

		paint();
	}

	// =====================================================================
	// Controls that only apply to some choices
	// =====================================================================

	/**
	 * Disable every control the current choices cannot reach.
	 *
	 * A row marked `data-curio-when="preset:custom"` is live only while the
	 * preset radio says `custom`; several values are separated with a pipe. The
	 * reasoning behind disabling rather than hiding is in
	 * `includes/admin/views/appearance.php`, beside the first rows to use it.
	 *
	 * Worth knowing here: disabled fields are not submitted, and the settings
	 * sanitiser leaves a declared-but-absent text field exactly as it was
	 * stored, so switching back to Custom finds the old colours still there.
	 * Checkboxes are the exception — absent means off — which is why the one
	 * gated checkbox is a sub-preference of the choice that gates it.
	 */
	function initConditionalFields() {
		var rows = document.querySelectorAll( '[data-curio-when]' );
		if ( ! rows.length ) {
			return;
		}

		// A field announced as "unavailable" with no reason given is a dead end
		// for anyone not looking at the greyed-out row. Tie the explanation to
		// the control so it is read out with it.
		rows.forEach( function ( row, index ) {
			var reason = row.querySelector( '.description' );
			if ( ! reason ) {
				return;
			}
			if ( ! reason.id ) {
				reason.id = 'curio-when-reason-' + index;
			}
			row.querySelectorAll( 'input, select, textarea' ).forEach( function ( control ) {
				control.setAttribute( 'aria-describedby', reason.id );
			} );
		} );

		/**
		 * Apply the rule on every marked row.
		 */
		function apply() {
			rows.forEach( function ( row ) {
				var rule = String( row.getAttribute( 'data-curio-when' ) ).split( ':' );
				var chosen = document.querySelector( 'input[name$="[' + rule[ 0 ] + ']"]:checked' );
				var wanted = String( rule[ 1 ] || '' ).split( '|' );
				var live = Boolean( chosen ) && wanted.indexOf( chosen.value ) !== -1;

				row.classList.toggle( 'curio-is-inactive', ! live );

				row.querySelectorAll( 'input, select, textarea, button' ).forEach( function ( control ) {
					control.disabled = ! live;
				} );
			} );
		}

		document.querySelectorAll( 'input[type="radio"]' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', apply );
		} );

		apply();
	}

	// =====================================================================

	document.addEventListener( 'DOMContentLoaded', function () {
		initKnowledge();
		initIndexing();
		initConnection();
		initInsights();
		initAppearance();

		// Last: the colour picker replaces its input with a whole widget, and
		// this has to disable what is actually on the page.
		initConditionalFields();
	} );
}( window.jQuery ) );
