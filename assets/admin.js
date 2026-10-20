/* Simple Migration admin: connect, compare both sites side by side, send in short steps. */
( () => {
	'use strict';

	const cfg = window.smConfig;
	const $ = ( id ) => document.getElementById( id );
	const PER_PAGE = 25;
	const REDUCED_MOTION = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	const FILTERS = {
		source: [ [ 'all', 'All' ], [ 'new', 'Not on receiver' ], [ 'changed', 'Changed since sent' ], [ 'synced', 'On receiver' ], [ 'conflict', 'Slug taken' ] ],
		target: [ [ 'all', 'All' ], [ 'linked', 'From this site' ], [ 'local', 'Receiver only' ], [ 'other', 'From another site' ] ],
	};

	const state = {
		connected: false,
		env: null, // { sender, receiver } from the health check
		type: $( 'sm-type' ).value,
		sender: [],
		receiver: [],
		receiverError: null,
		typeMissing: false,
		loading: false,
		selected: new Set(),
		rowStatus: new Map(), // sender id → { kind: 'sending' | 'failed', text }
		sending: false,
		stop: false,
		panes: { source: { filter: 'all', q: '', page: 1 }, target: { filter: 'all', q: '', page: 1 } },
	};

	/* ---------- Small DOM helpers ---------- */

	function el( tag, props = {}, ...children ) {
		const node = document.createElement( tag );
		for ( const [ k, v ] of Object.entries( props ) ) {
			if ( k.startsWith( 'aria-' ) || k.startsWith( 'data-' ) ) {
				node.setAttribute( k, v );
			} else {
				node[ k ] = v;
			}
		}
		node.append( ...children.filter( ( c ) => c !== null && c !== undefined && c !== false ) );
		return node;
	}
	const icon = ( name ) => el( 'span', { className: `dashicons dashicons-${ name }`, 'aria-hidden': 'true' } );
	const plural = ( n, word ) => `${ n } ${ word }${ n === 1 ? '' : 's' }`;

	/* ---------- Server calls ---------- */

	function call( action, data = {} ) {
		const body = new FormData();
		body.append( 'action', action );
		body.append( '_ajax_nonce', cfg.nonce );
		Object.entries( data ).forEach( ( [ k, v ] ) => body.append( k, v ) );
		return fetch( cfg.ajax, { method: 'POST', body, credentials: 'same-origin' } )
			.then( async ( r ) => {
				const text = await r.text();
				try {
					return JSON.parse( text );
				} catch {
					const detail = text.replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim().slice( 0, 300 );
					return { success: false, data: `No valid reply from this site (HTTP ${ r.status }): ${ detail || 'empty response, probably a server timeout' }` };
				}
			} )
			.catch( ( e ) => ( { success: false, data: 'Request failed: ' + e.message } ) );
	}

	/* ---------- Matching ---------- */

	const senderById = () => new Map( state.sender.map( ( p ) => [ p.id, p ] ) );

	// Copies carry "_sm_source = <sender home>|<sender id>", so pairs are exact, not guessed by title.
	function match() {
		const prefix = cfg.home + '|';
		const bySource = new Map();
		const bySlug = new Map();
		const senders = senderById();
		for ( const r of state.receiver ) {
			r.from = null;
			if ( r.source ) {
				r.from = r.source.startsWith( prefix ) ? Number( r.source.slice( prefix.length ) ) : 'other';
			}
			if ( typeof r.from === 'number' ) {
				bySource.set( r.from, r );
			}
			if ( r.slug && ! bySlug.has( r.slug ) ) {
				bySlug.set( r.slug, r );
			}
			r.state = typeof r.from === 'number' ? 'linked' : r.from || 'local';
			r.partner = typeof r.from === 'number' ? senders.get( r.from ) || null : null;
		}
		for ( const s of state.sender ) {
			s.copy = bySource.get( s.id ) || null;
			s.twin = ! s.copy && s.slug ? bySlug.get( s.slug ) || null : null;
			if ( ! state.connected || state.receiverError ) {
				s.state = null;
			} else if ( s.copy ) {
				s.state = s.modified > ( s.copy.source_modified || s.copy.modified ) ? 'changed' : 'synced';
			} else {
				s.state = s.twin ? 'conflict' : 'new';
			}
		}
	}

	/* ---------- Pane rendering ---------- */

	const list = ( pane ) => ( pane === 'source' ? state.sender : state.receiver );

	function visible( pane ) {
		const { filter, q } = state.panes[ pane ];
		const needle = q.trim().toLowerCase();
		return list( pane ).filter( ( p ) => ( filter === 'all' || p.state === filter ) &&
			( ! needle || p.title.toLowerCase().includes( needle ) || p.slug.toLowerCase().includes( needle ) || String( p.id ) === needle ) );
	}

	function renderFilters( pane ) {
		const counts = {};
		list( pane ).forEach( ( p ) => ( counts[ p.state ] = ( counts[ p.state ] || 0 ) + 1 ) );
		const enabled = state.connected && ! state.receiverError;
		$( `sm-${ pane }-filters` ).replaceChildren( ...FILTERS[ pane ]
			.filter( ( [ key ] ) => key === 'all' || ( enabled && ( key !== 'other' || counts.other ) ) ) // "another site" only when relevant
			.map( ( [ key, label ] ) => {
				const n = key === 'all' ? list( pane ).length : counts[ key ] || 0;
				const button = el( 'button', {
					type: 'button',
					className: 'sm-filter',
					disabled: key !== 'all' && ( ! enabled || ! n ) && state.panes[ pane ].filter !== key,
					'aria-pressed': String( state.panes[ pane ].filter === key ),
				}, label, el( 'span', { className: 'sm-filter-count' }, String( n ) ) );
				button.addEventListener( 'click', () => {
					state.panes[ pane ].filter = key;
					state.panes[ pane ].page = 1;
					render( pane );
				} );
				return button;
			} ) );
	}

	function titleCell( p, extra ) {
		const meta = [ `#${ p.id }`, p.status, p.date, p.author ].filter( Boolean ).join( ' · ' );
		return el( 'td', {},
			el( 'span', { className: 'sm-post-title', title: p.title }, p.title, p.builder ? el( 'span', { className: 'sm-tag' }, 'Elementor' ) : null ),
			el( 'span', { className: 'sm-post-meta', title: meta }, meta ),
			extra
		);
	}

	function pill( kind, iconName, text, opts = {} ) {
		const props = { className: `sm-pill sm-pill--${ kind }` };
		if ( opts.title ) {
			props.title = opts.title;
		}
		if ( ! opts.onClick ) {
			return el( 'span', props, icon( iconName ), text );
		}
		const button = el( 'button', { ...props, type: 'button', 'aria-label': opts.label || text }, icon( iconName ), text );
		button.addEventListener( 'click', opts.onClick );
		return button;
	}

	function sourcePill( p ) {
		const busy = state.rowStatus.get( p.id );
		if ( busy?.kind === 'sending' ) {
			return pill( 'sending', 'update', 'Sending…' );
		}
		if ( busy?.kind === 'failed' ) {
			return pill( 'failed', 'dismiss', 'Failed', { title: busy.text } );
		}
		switch ( p.state ) {
			case 'synced':
				return pill( 'synced', 'yes-alt', 'On receiver', { onClick: () => locate( 'target', p.copy.id ), label: `On receiver as #${ p.copy.id }. Show it in the receiver list` } );
			case 'changed':
				return pill( 'changed', 'edit', 'Changed since sent', { onClick: () => locate( 'target', p.copy.id ), title: 'Edited here after it was last sent. Send again to update the receiver’s copy.', label: 'Changed since sent. Show the receiver’s copy' } );
			case 'conflict':
				return pill( 'conflict', 'warning', 'Slug taken', {
					onClick: () => locate( 'target', p.twin.id ),
					title: `The receiver already has “${ p.twin.title }” (#${ p.twin.id }) with this slug, not sent from here. Sending creates a second post.`,
					label: 'Slug taken on receiver. Show the post that has it',
				} );
			case 'new':
				return pill( 'new', 'minus', 'Not on receiver' );
			default:
				return '';
		}
	}

	function targetPill( r ) {
		if ( r.state === 'linked' ) {
			return r.partner
				? pill( 'linked', 'admin-links', `From this site`, { onClick: () => locate( 'source', r.partner.id ), title: `Copy of #${ r.from } here`, label: `Copy of #${ r.from } on this site. Show the original` } )
				: pill( 'linked', 'admin-links', 'From this site', { title: `Copy of #${ r.from }, which is no longer here (deleted or a different post type)` } );
		}
		if ( r.state === 'other' ) {
			return pill( 'other', 'migrate', 'From another site', { title: `Sent from ${ r.source.split( '|' )[ 0 ] }` } );
		}
		return pill( 'local', 'location', 'Receiver only' );
	}

	// Hovering or focusing a row highlights its partner in the other list.
	function pairWith( tr, otherPane, otherId ) {
		if ( ! otherId ) {
			return;
		}
		const partner = () => document.querySelector( `#sm-${ otherPane }-rows tr[data-id="${ otherId }"]` );
		const on = () => partner()?.classList.add( 'is-paired' );
		const off = () => partner()?.classList.remove( 'is-paired' );
		tr.addEventListener( 'mouseenter', on );
		tr.addEventListener( 'mouseleave', off );
		tr.addEventListener( 'focusin', on );
		tr.addEventListener( 'focusout', off );
	}

	function sourceRow( p ) {
		const selected = state.selected.has( p.id );
		const cb = el( 'input', { type: 'checkbox', checked: selected, disabled: state.sending, 'aria-label': `Select ${ p.title }` } );
		const tr = el( 'tr', { className: selected ? 'is-selected' : '', 'data-id': p.id },
			el( 'td', { className: 'sm-col-check' }, cb ),
			titleCell( p ),
			el( 'td', { className: 'sm-col-state' }, sourcePill( p ) )
		);
		const toggle = ( on ) => {
			on ? state.selected.add( p.id ) : state.selected.delete( p.id );
			tr.classList.toggle( 'is-selected', on );
			renderSelectAll();
			renderActionbar();
		};
		cb.addEventListener( 'change', () => toggle( cb.checked ) );
		tr.addEventListener( 'click', ( e ) => {
			if ( state.sending || e.target.closest( 'input, button, a' ) ) {
				return;
			}
			cb.checked = ! cb.checked;
			toggle( cb.checked );
		} );
		pairWith( tr, 'target', ( p.copy || p.twin )?.id );
		return tr;
	}

	function targetRow( r ) {
		const edit = el( 'a', {
			className: 'sm-edit-link',
			href: `${ state.env.receiver.admin }post.php?post=${ r.id }&action=edit`,
			target: '_blank',
			rel: 'noopener',
			'aria-label': `Edit “${ r.title }” on the receiver (opens in a new tab)`,
		}, icon( 'external' ) );
		const tr = el( 'tr', { 'data-id': r.id }, titleCell( r ), el( 'td', { className: 'sm-col-state' }, targetPill( r ) ), el( 'td', { className: 'sm-col-action' }, edit ) );
		pairWith( tr, 'source', r.partner?.id );
		return tr;
	}

	function emptyRow( cols, iconName, text, action ) {
		return el( 'tr', { className: 'sm-empty' }, el( 'td', { colSpan: cols }, icon( iconName ), text, action ? el( 'br' ) : null, action ) );
	}

	function button( text, onClick, className = 'button' ) {
		const b = el( 'button', { type: 'button', className }, text );
		b.addEventListener( 'click', onClick );
		return b;
	}

	function skeleton( pane ) {
		$( `sm-${ pane }-rows` ).replaceChildren( ...Array.from( { length: 6 }, () =>
			el( 'tr', { className: 'sm-skeleton', 'aria-hidden': 'true' }, el( 'td', { colSpan: 3 }, el( 'span' ), el( 'span' ) ) ) ) );
		$( `sm-${ pane }-rows` ).closest( 'table' ).setAttribute( 'aria-busy', 'true' );
		$( `sm-${ pane }-pager` ).replaceChildren();
	}

	function clearFilters( pane ) {
		state.panes[ pane ] = { filter: 'all', q: '', page: 1 };
		$( `sm-${ pane }-search` ).value = '';
		render( pane );
	}

	function render( pane ) {
		const tbody = $( `sm-${ pane }-rows` );
		tbody.closest( 'table' ).removeAttribute( 'aria-busy' );
		renderFilters( pane );
		if ( pane === 'target' ) {
			$( 'sm-target-host' ).textContent = state.connected ? new URL( state.env.receiver.url ).host : 'Not connected';
			renderTargetWarning();
			if ( ! state.connected ) {
				tbody.replaceChildren( emptyRow( 3, 'admin-site-alt3', 'Connect a receiving site to compare its posts with yours.' ) );
				$( 'sm-target-pager' ).replaceChildren();
				return;
			}
			if ( state.receiverError ) {
				tbody.replaceChildren( emptyRow( 3, 'warning', `Couldn’t load the receiver’s posts: ${ state.receiverError }`, button( 'Try again', () => loadLists() ) ) );
				$( 'sm-target-pager' ).replaceChildren();
				return;
			}
		}

		const items = visible( pane );
		const ps = state.panes[ pane ];
		const pages = Math.max( 1, Math.ceil( items.length / PER_PAGE ) );
		ps.page = Math.min( Math.max( 1, ps.page ), pages );
		const shown = items.slice( ( ps.page - 1 ) * PER_PAGE, ps.page * PER_PAGE );
		const cols = 3;

		if ( ! list( pane ).length ) {
			const label = $( 'sm-type' ).selectedOptions[ 0 ].textContent.toLowerCase();
			tbody.replaceChildren( emptyRow( cols, 'admin-post', pane === 'source' ? `No ${ label } on this site.` : `No ${ label } on the receiver yet.` ) );
		} else if ( ! shown.length ) {
			tbody.replaceChildren( emptyRow( cols, 'search', 'Nothing matches these filters.', button( 'Clear filters', () => clearFilters( pane ) ) ) );
		} else {
			tbody.replaceChildren( ...shown.map( pane === 'source' ? sourceRow : targetRow ) );
		}
		renderPager( pane, items.length, pages );
		if ( pane === 'source' ) {
			renderSelectAll();
			renderActionbar();
		}
	}

	function renderPager( pane, total, pages ) {
		const ps = state.panes[ pane ];
		const from = total ? ( ps.page - 1 ) * PER_PAGE + 1 : 0;
		const to = Math.min( total, ps.page * PER_PAGE );
		const go = ( delta ) => () => {
			ps.page += delta;
			render( pane );
		};
		const prev = button( '‹', go( -1 ) );
		const next = button( '›', go( 1 ) );
		prev.disabled = ps.page <= 1;
		next.disabled = ps.page >= pages;
		prev.setAttribute( 'aria-label', 'Previous page' );
		next.setAttribute( 'aria-label', 'Next page' );
		$( `sm-${ pane }-pager` ).replaceChildren(
			el( 'span', {}, total ? `${ from }–${ to } of ${ total }` : '' ),
			pages > 1 ? el( 'span', { className: 'sm-pager-buttons' }, prev, next ) : ''
		);
	}

	function renderTargetWarning() {
		let note = $( 'sm-target-warning' );
		if ( ! note ) {
			note = el( 'p', { id: 'sm-target-warning', className: 'sm-notice sm-notice--warn', role: 'status' } );
			note.style.margin = '0 20px';
			$( 'sm-target-search' ).before( note );
		}
		note.hidden = ! ( state.connected && state.typeMissing );
		note.textContent = 'This post type isn’t registered on the receiver. Posts you send are stored, but won’t appear in its admin until the same post type exists there.';
	}

	function renderSelectAll() {
		const boxes = [ ...$( 'sm-source-rows' ).querySelectorAll( 'input[type="checkbox"]' ) ];
		const checked = boxes.filter( ( b ) => b.checked ).length;
		$( 'sm-all' ).checked = boxes.length > 0 && checked === boxes.length;
		$( 'sm-all' ).indeterminate = checked > 0 && checked < boxes.length;
		$( 'sm-all' ).disabled = state.sending || ! boxes.length;
	}

	function renderActionbar() {
		const n = state.selected.size;
		const ps = state.panes.source;
		const items = visible( 'source' );
		const missing = items.filter( ( p ) => ! state.selected.has( p.id ) ).length;
		const scope = ps.filter !== 'all' ? ` in “${ FILTERS.source.find( ( [ k ] ) => k === ps.filter )[ 1 ] }”` : ps.q ? ' matching' : '';
		$( 'sm-count' ).textContent = n ? `${ plural( n, 'post' ) } selected` : 'No posts selected';
		$( 'sm-select-filter' ).textContent = `Select all ${ items.length }${ scope }`;
		$( 'sm-select-filter' ).hidden = state.sending || ! missing;
		$( 'sm-clear' ).hidden = state.sending || ! n;
		$( 'sm-send' ).disabled = state.sending || ! state.connected || ! n;
		$( 'sm-send' ).textContent = n ? `Send ${ plural( n, 'post' ) }` : 'Send';
		$( 'sm-flatten' ).disabled = state.sending;
	}

	function renderAll() {
		match();
		render( 'source' );
		render( 'target' );
	}

	// Brings a row into view in the other list (resetting its filters if they hide it) and flashes it.
	function locate( pane, id ) {
		const ps = state.panes[ pane ];
		if ( ! visible( pane ).some( ( p ) => p.id === id ) ) {
			ps.filter = 'all';
			ps.q = '';
			$( `sm-${ pane }-search` ).value = '';
		}
		const index = visible( pane ).findIndex( ( p ) => p.id === id );
		if ( index < 0 ) {
			return;
		}
		ps.page = Math.floor( index / PER_PAGE ) + 1;
		render( pane );
		const tr = document.querySelector( `#sm-${ pane }-rows tr[data-id="${ id }"]` );
		tr.scrollIntoView( { block: 'nearest', behavior: REDUCED_MOTION ? 'auto' : 'smooth' } );
		tr.classList.remove( 'is-flash' );
		void tr.offsetWidth; // restart the animation
		tr.classList.add( 'is-flash' );
		( tr.querySelector( 'button, a, input' ) || tr ).focus( { preventScroll: true } );
	}

	/* ---------- Connection & health ---------- */

	const toBytes = ( v ) => {
		v = String( v ?? '' ).trim();
		if ( v === '-1' ) {
			return Infinity;
		}
		return parseFloat( v ) * ( { K: 1024, M: 1024 ** 2, G: 1024 ** 3 }[ v.slice( -1 ).toUpperCase() ] || 1 );
	};
	const formatBytes = ( b ) => {
		if ( Number.isNaN( b ) ) {
			return '';
		}
		if ( b === Infinity ) {
			return 'Unlimited';
		}
		return b >= 1024 ** 3 ? `${ +( b / 1024 ** 3 ).toFixed( 1 ) } GB` : `${ Math.round( b / 1024 ** 2 ) } MB`;
	};

	// [ label, value(env), warning(env, otherEnv, side) ]
	const CHECKS = [
		// Sites on 1.0 report no version (and none of the fields below), so they read as "1.0 or older".
		[ 'Simple Migration', ( e ) => e.version || '1.0 or older',
			( e, o ) => ( e.version || '1.0' ).localeCompare( o.version || '1.0', undefined, { numeric: true } ) < 0 && `Older than the other site. Update to ${ o.version }.` ],
		[ 'WordPress', ( e ) => e.wp ],
		[ 'PHP', ( e ) => e.php ],
		[ 'Memory during migration', ( e ) => formatBytes( toBytes( e.memory ) ),
			( e ) => toBytes( e.memory ) < 512 * 1024 ** 2 && 'The host keeps this low. Large images may fail to resize; ask the host to allow more memory.' ],
		[ 'Time limit during migration', ( e ) => ( e.time === undefined ? '' : +e.time ? `${ e.time } seconds` : 'None' ),
			( e ) => +e.time > 0 && +e.time < 120 && 'The host enforces a short limit. Very large files may time out.' ],
		// Files travel base64-encoded in JSON (≈ 4/3 the size), so the usable size is ~74% of post_max_size.
		[ 'Largest file it can receive', ( e ) => formatBytes( toBytes( e.post_max ) * 0.74 ),
			( e, o, side ) => side === 'receiver' && toBytes( e.post_max ) * 0.74 < 32 * 1024 ** 2 && 'Raise post_max_size on this site to receive bigger files.' ],
		[ 'Image processing', ( e ) => ( e.editor === 'none' ? 'Unavailable' : e.editor || '' ),
			( e, o, side ) => side === 'receiver' && e.editor === 'none' && 'No image library, so thumbnails can’t be generated.' ],
	];

	function renderHealth() {
		const { sender, receiver } = state.env;
		let warnings = 0;
		const cell = ( env, other, side, value, warn ) => {
			const message = warn && warn( env, other, side );
			warnings += message ? 1 : 0;
			return el( 'td', {},
				el( 'span', { className: 'sm-health-value' }, value( env ) || '—' ),
				message ? el( 'span', { className: 'sm-health-note' }, icon( 'warning' ), message ) : null );
		};
		$( 'sm-health-rows' ).replaceChildren( ...CHECKS.map( ( [ label, value, warn ] ) => el( 'tr', {},
			el( 'th', { scope: 'row' }, label ),
			cell( sender, receiver, 'sender', value, warn ),
			cell( receiver, sender, 'receiver', value, warn ) ) ) );
		const summary = $( 'sm-health-summary' );
		summary.className = `sm-health-summary ${ warnings ? 'is-warn' : 'is-ok' }`;
		summary.replaceChildren( icon( warnings ? 'warning' : 'yes-alt' ), warnings ? ` ${ plural( warnings, 'thing' ) } to look at` : ' Both sites look good' );
		$( 'sm-health' ).hidden = false;
		$( 'sm-health' ).open = warnings > 0;
	}

	function renderConnection() {
		document.querySelector( '.wrap.sm' ).classList.toggle( 'is-connected', state.connected );
		const chip = $( 'sm-connection' );
		if ( ! state.connected ) {
			chip.replaceChildren();
			return;
		}
		const r = state.env.receiver;
		chip.replaceChildren(
			el( 'span', { className: 'sm-chip' },
				el( 'span', { className: 'sm-chip-dot', 'aria-hidden': 'true' } ),
				el( 'span', {}, 'Connected to ', el( 'strong', {}, r.name || new URL( r.url ).host ) ),
				el( 'span', { className: 'sm-chip-host' }, new URL( r.url ).host ) ),
			button( 'Change', () => showConnectCard( true ), 'button-link' )
		);
	}

	function showConnectCard( cancellable ) {
		$( 'sm-connect-card' ).hidden = false;
		$( 'sm-connect-cancel' ).hidden = ! cancellable;
		$( 'sm-url' ).focus();
	}

	async function connect( silent = false ) {
		const submit = $( 'sm-connect' );
		submit.disabled = true;
		submit.textContent = 'Connecting…';
		$( 'sm-connect-error' ).hidden = true;
		if ( silent ) {
			$( 'sm-connection' ).replaceChildren( el( 'span', { className: 'sm-chip' }, icon( 'update' ), 'Checking connection…' ) );
		}
		const r = await call( 'sm_connect', { url: $( 'sm-url' ).value.trim(), key: $( 'sm-target-key' ).value.trim() } );
		submit.disabled = false;
		submit.textContent = 'Connect';
		state.connected = r.success;
		if ( r.success ) {
			state.env = r.data;
			$( 'sm-connect-card' ).hidden = true;
			renderHealth();
		} else {
			$( 'sm-connect-error' ).textContent = r.data;
			$( 'sm-connect-error' ).hidden = false;
			$( 'sm-health' ).hidden = true;
			showConnectCard( false );
		}
		renderConnection();
		loadLists();
	}

	/* ---------- Data loading ---------- */

	let loadToken = 0;
	async function loadLists( { quiet = false } = {} ) {
		const token = ++loadToken; // ignore replies to superseded loads (fast type switching)
		state.loading = true;
		$( 'sm-refresh' ).classList.add( 'is-busy' );
		if ( ! quiet ) {
			skeleton( 'source' );
			if ( state.connected ) {
				skeleton( 'target' );
			}
		}
		const r = await call( 'sm_lists', { type: state.type } );
		if ( token !== loadToken ) {
			return;
		}
		state.loading = false;
		$( 'sm-refresh' ).classList.remove( 'is-busy' );
		if ( ! r.success ) {
			$( 'sm-source-rows' ).replaceChildren( emptyRow( 3, 'warning', `Couldn’t load posts: ${ r.data }`, button( 'Try again', () => loadLists() ) ) );
			return;
		}
		state.sender = r.data.sender.posts;
		const receiver = state.connected ? r.data.receiver : null;
		state.receiverError = receiver?.error || null;
		state.receiver = receiver?.posts || [];
		state.typeMissing = !! receiver && ! receiver.error && ! receiver.type_exists;
		renderAll();
	}

	/* ---------- Sending ---------- */

	function logEntry( title ) {
		const iconNode = icon( 'update' );
		const text = el( 'span', {}, title );
		const detail = el( 'span', { className: 'sm-log-detail' } );
		const li = el( 'li', { className: 'is-busy' }, iconNode, el( 'span', {}, text, detail ) );
		$( 'sm-log' ).prepend( li );
		return {
			detail: ( t ) => ( detail.textContent = t ),
			done: ( kind, iconName, content, extra ) => {
				li.className = `is-${ kind }`;
				iconNode.className = `dashicons dashicons-${ iconName }`;
				text.replaceChildren( ...[].concat( content ) );
				detail.textContent = extra || '';
			},
		};
	}

	function setProgress( text, value ) {
		$( 'sm-progress-text' ).textContent = text;
		$( 'sm-bar' ).value = value;
	}

	// Mirror a successful send locally so indicators update immediately; a quiet reload follows the batch.
	function recordCopy( post, result ) {
		const copy = state.receiver.find( ( r ) => r.id === result.id );
		const fields = { source: `${ cfg.home }|${ post.id }`, source_modified: post.modified };
		if ( copy ) {
			Object.assign( copy, fields );
		} else {
			state.receiver.unshift( { ...post, ...fields, id: result.id, builder: false, modified: Math.floor( Date.now() / 1000 ) } );
		}
	}

	async function send() {
		const ids = [ ...state.selected ];
		const flatten = $( 'sm-flatten' ).checked ? 1 : '';
		const posts = senderById();
		let ok = 0, fail = 0;

		state.sending = true;
		state.stop = false;
		$( 'sm-activity' ).hidden = false;
		$( 'sm-progress' ).hidden = false;
		$( 'sm-stop' ).hidden = false;
		$( 'sm-stop' ).disabled = false;
		$( 'sm-stop' ).textContent = 'Stop after this post';
		renderAll();

		for ( const [ i, id ] of ids.entries() ) {
			if ( state.stop ) {
				break;
			}
			const post = posts.get( id ) || { id, title: `#${ id }` };
			let title = post.title, error = null, result = null;
			const warnings = [];
			const entry = logEntry( title );
			const step = ( label, done ) => {
				entry.detail( label );
				setProgress( `Post ${ i + 1 } of ${ ids.length } · ${ title } · ${ label }`, ( i + done ) / ids.length );
			};
			state.rowStatus.set( id, { kind: 'sending' } );
			render( 'source' );

			step( 'Preparing', 0 );
			const plan = await call( 'sm_plan', { id, flatten } );
			if ( plan.success ) {
				title = plan.data.title;
				const files = plan.data.files;
				for ( const [ j, f ] of files.entries() ) {
					step( `File ${ j + 1 } of ${ files.length }: ${ f.name }`, ( j + 1 ) / ( files.length + 2 ) );
					const r = await call( 'sm_send_file', { id: f.id } );
					if ( ! r.success ) {
						warnings.push( `${ f.name } not sent: ${ r.data }` );
					}
				}
				step( 'Saving post', ( files.length + 1 ) / ( files.length + 2 ) );
				const r = await call( 'sm_send', { id, flatten } );
				if ( r.success ) {
					result = r.data;
					warnings.push( ...r.data.warnings );
				} else {
					error = r.data;
				}
			} else {
				error = plan.data;
			}

			if ( error ) {
				fail++;
				state.rowStatus.set( id, { kind: 'failed', text: error } );
				entry.done( 'fail', 'dismiss', title, error );
			} else {
				ok++;
				state.selected.delete( id );
				state.rowStatus.delete( id );
				recordCopy( { ...post, title }, result );
				const link = el( 'a', { href: result.edit, target: '_blank', rel: 'noopener' }, 'Edit on receiver' );
				entry.done( warnings.length ? 'warn' : 'ok', warnings.length ? 'warning' : 'yes-alt', [ title, ' · ', link ], warnings.join( ' · ' ) );
			}
			setProgress( '', ( i + 1 ) / ids.length );
			renderAll();
		}

		const summary = [ `${ plural( ok, 'post' ) } sent` ];
		if ( fail ) {
			summary.push( `${ fail } failed (still selected, so you can retry)` );
		}
		setProgress( `${ state.stop ? 'Stopped' : 'Done' }: ${ summary.join( ', ' ) }.`, state.stop ? $( 'sm-bar' ).value : 1 );
		state.sending = false;
		$( 'sm-stop' ).hidden = true;
		renderAll();
		loadLists( { quiet: true } );
	}

	/* ---------- Wiring ---------- */

	$( 'sm-connect-form' ).addEventListener( 'submit', ( e ) => {
		e.preventDefault();
		connect();
	} );
	$( 'sm-connect-cancel' ).addEventListener( 'click', () => {
		$( 'sm-connect-card' ).hidden = true;
		$( 'sm-connect-error' ).hidden = true;
	} );
	$( 'sm-type' ).addEventListener( 'change', () => {
		state.type = $( 'sm-type' ).value;
		state.selected.clear();
		state.rowStatus.clear();
		[ 'source', 'target' ].forEach( ( pane ) => {
			state.panes[ pane ] = { filter: 'all', q: '', page: 1 };
			$( `sm-${ pane }-search` ).value = '';
		} );
		loadLists();
	} );
	$( 'sm-refresh' ).addEventListener( 'click', () => loadLists() );
	[ 'source', 'target' ].forEach( ( pane ) => {
		$( `sm-${ pane }-search` ).addEventListener( 'input', ( e ) => {
			state.panes[ pane ].q = e.target.value;
			state.panes[ pane ].page = 1;
			render( pane );
		} );
	} );
	$( 'sm-all' ).addEventListener( 'change', ( e ) => {
		$( 'sm-source-rows' ).querySelectorAll( 'tr[data-id]' ).forEach( ( tr ) => {
			const id = Number( tr.dataset.id );
			e.target.checked ? state.selected.add( id ) : state.selected.delete( id );
		} );
		render( 'source' );
	} );
	$( 'sm-select-filter' ).addEventListener( 'click', () => {
		visible( 'source' ).forEach( ( p ) => state.selected.add( p.id ) );
		render( 'source' );
	} );
	$( 'sm-clear' ).addEventListener( 'click', () => {
		state.selected.clear();
		render( 'source' );
	} );
	$( 'sm-send' ).addEventListener( 'click', send );
	$( 'sm-stop' ).addEventListener( 'click', () => {
		state.stop = true;
		$( 'sm-stop' ).disabled = true;
		$( 'sm-stop' ).textContent = 'Stopping…';
	} );
	window.addEventListener( 'beforeunload', ( e ) => {
		if ( state.sending ) {
			e.preventDefault();
		}
	} );

	/* ---------- Start ---------- */

	$( 'sm-url' ).value = cfg.url || '';
	$( 'sm-target-key' ).value = cfg.key || '';
	if ( cfg.url && cfg.key ) {
		$( 'sm-connect-card' ).hidden = true;
		connect( true );
	} else {
		renderAll();
		loadLists();
	}
} )();
