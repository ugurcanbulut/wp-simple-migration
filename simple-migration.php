<?php
/**
 * Plugin Name: Simple Migration
 * Description: Send selected posts (with author, terms, meta and attachments) to another site running this plugin.
 * Version: 1.0.0
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

const SM_SKIP_META = [ '_edit_lock', '_edit_last', '_sm_source', '_elementor_css', '_elementor_element_cache', '_sm_sent' ];

register_activation_hook( __FILE__, function () {
	add_option( 'sm_key', wp_generate_password( 32, false ) );
} );

/* ---------- Admin page: Tools → Simple Migration ---------- */

add_action( 'admin_menu', function () {
	add_management_page( 'Simple Migration', 'Simple Migration', 'manage_options', 'simple-migration', 'sm_page' );
} );

add_action( 'admin_init', function () {
	register_setting( 'simple-migration', 'sm_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
} );

function sm_post_types(): array {
	return array_filter( get_post_types( [ 'show_ui' => true ], 'objects' ), fn( $t ) => 'attachment' !== $t->name && ! preg_match( '/^(wp_|acf-)/', $t->name ) );
}

function sm_page() {
	?>
	<div class="wrap" id="sm">
		<h1>Simple Migration</h1>
		<style>
			#sm [hidden] { display: none !important; } /* .button sets display, which beats [hidden] */
			#sm-rows tr { cursor: pointer; }
			#sm-log { max-height: 320px; overflow: auto; }
			#sm-log .sm-fail { color: #d63638; }
			#sm-log .sm-warn { color: #996800; }
			#sm-progress progress { width: 100%; max-width: 640px; height: 20px; vertical-align: middle; }
			.sm-ok { color: #008a20; } .sm-err { color: #d63638; }
			.sm-badge { margin-left: 6px; padding: 1px 6px; border-radius: 3px; background: #f0f0f1; color: #50575e; font-size: 11px; }
		</style>

		<h2>1. Connect to the receiver site</h2>
		<p>Install Simple Migration on the other site and copy the key from its <em>Tools → Simple Migration</em> page.</p>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="sm-url">Receiver URL</label></th>
				<td><input id="sm-url" type="url" class="regular-text" placeholder="https://example.com" value="<?php echo esc_attr( get_option( 'sm_target_url' ) ); ?>"></td>
			</tr>
			<tr>
				<th><label for="sm-target-key">Receiver key</label></th>
				<td>
					<input id="sm-target-key" class="regular-text code" autocomplete="off" value="<?php echo esc_attr( get_option( 'sm_target_key' ) ); ?>">
					<button type="button" class="button" id="sm-connect">Connect</button>
					<p id="sm-status" aria-live="polite"></p>
				</td>
			</tr>
		</table>

		<h2>2. Pick posts</h2>
		<div class="tablenav top">
			<div class="alignleft actions">
				<label class="screen-reader-text" for="sm-type">Post type</label>
				<select id="sm-type">
					<?php foreach ( sm_post_types() as $t ) : ?>
						<option value="<?php echo esc_attr( $t->name ); ?>"><?php echo esc_html( $t->labels->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="screen-reader-text" for="sm-search">Search</label>
				<input type="search" id="sm-search" placeholder="Search…">
			</div>
			<div class="tablenav-pages">
				<span class="displaying-num" id="sm-total"></span>
				<button type="button" class="button" id="sm-prev" aria-label="Previous page">‹</button>
				<span id="sm-pageinfo"></span>
				<button type="button" class="button" id="sm-next" aria-label="Next page">›</button>
			</div>
		</div>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<td class="manage-column check-column"><input type="checkbox" id="sm-all" aria-label="Select all on this page"></td>
					<th>Title</th><th>Status</th><th>Author</th><th>Date</th><th>Last sent here</th>
				</tr>
			</thead>
			<tbody id="sm-rows"></tbody>
		</table>
		<p>
			<strong id="sm-count">0 selected</strong> ·
			<button type="button" class="button-link" id="sm-select-matching">Select all matching</button> ·
			<button type="button" class="button-link" id="sm-clear">Clear selection</button>
		</p>

		<h2>3. Send</h2>
		<p>
			<label><input type="checkbox" id="sm-flatten"> Convert Elementor posts to standard WordPress content</label><br>
			<span class="description">Elementor layouts are sent as plain content (headings, text, images, links) and Elementor data is dropped, so the receiver doesn't need Elementor. Design and styling are not kept. Posts marked <em>Elementor</em> above are affected.</span>
		</p>
		<p>
			<button type="button" class="button button-primary button-hero" id="sm-send" disabled>Send selected posts</button>
			<button type="button" class="button" id="sm-stop" hidden>Stop after current post</button>
		</p>
		<p id="sm-progress" hidden><progress max="1" value="0"></progress> <span id="sm-progress-text"></span></p>
		<ol id="sm-log"></ol>

		<hr>
		<h2>Receiving posts on this site</h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'simple-migration' ); ?>
			<p>
				<label for="sm_key">This site's key</label><br>
				<input id="sm_key" name="sm_key" class="regular-text code" value="<?php echo esc_attr( get_option( 'sm_key' ) ); ?>">
				<?php submit_button( 'Save key', 'secondary', 'submit', false ); ?>
			</p>
			<p class="description">Paste this into the sending site. Anyone with it can add posts and users here. Clear it to stop receiving.</p>
		</form>
	</div>
	<script>
	( () => {
		const $ = ( id ) => document.getElementById( id );
		const nonce = <?php echo wp_json_encode( wp_create_nonce( 'sm' ) ); ?>;
		const selected = new Set();
		const titles = new Map(); // id → title, for log lines when a post fails before its title is known
		let page = 1, pages = 1, connected = false, stop = false, sending = false, debounce;

		const call = ( action, data = {} ) => {
			const body = new FormData();
			body.append( 'action', action );
			body.append( '_ajax_nonce', nonce );
			Object.entries( data ).forEach( ( [ k, v ] ) => body.append( k, v ) );
			return fetch( ajaxurl, { method: 'POST', body, credentials: 'same-origin' } )
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
		};
		const status = ( ok, msg ) => { $( 'sm-status' ).className = ok ? 'sm-ok' : 'sm-err'; $( 'sm-status' ).textContent = msg; };
		const refresh = () => {
			const n = selected.size;
			$( 'sm-count' ).textContent = n + ' selected';
			$( 'sm-send' ).disabled = sending || ! connected || ! n;
			$( 'sm-send' ).textContent = n ? `Send ${ n } post${ n > 1 ? 's' : '' }` : 'Send selected posts';
			const boxes = [ ...$( 'sm-rows' ).querySelectorAll( 'input' ) ];
			$( 'sm-all' ).checked = boxes.length > 0 && boxes.every( ( b ) => b.checked );
		};
		const filters = () => ( { type: $( 'sm-type' ).value, s: $( 'sm-search' ).value } );

		async function connect() {
			connected = false;
			status( true, 'Checking connection…' );
			refresh();
			const r = await call( 'sm_connect', { url: $( 'sm-url' ).value, key: $( 'sm-target-key' ).value } );
			connected = r.success;
			status( r.success, r.success ? `✓ Connected to “${ r.data.name }” (${ r.data.url }). Max request size there: ${ r.data.limit }.` : '✗ ' + r.data );
			load();
		}

		function row( p ) {
			const tr = document.createElement( 'tr' );
			titles.set( p.id, p.title );
			const th = Object.assign( document.createElement( 'th' ), { className: 'check-column', scope: 'row' } );
			const cb = Object.assign( document.createElement( 'input' ), { type: 'checkbox', value: p.id, checked: selected.has( p.id ) } );
			cb.setAttribute( 'aria-label', 'Select ' + p.title );
			cb.onchange = () => { cb.checked ? selected.add( p.id ) : selected.delete( p.id ); refresh(); };
			th.append( cb );
			tr.append( th, ...[ p.title, p.status, p.author, p.date, p.sent ].map( ( t ) => Object.assign( document.createElement( 'td' ), { textContent: t } ) ) );
			if ( p.builder ) { tr.children[ 1 ].append( Object.assign( document.createElement( 'span' ), { className: 'sm-badge', textContent: 'Elementor' } ) ); }
			tr.onclick = ( e ) => { if ( e.target !== cb ) { cb.click(); } };
			return tr;
		}

		async function load() {
			const r = await call( 'sm_posts', { ...filters(), page } );
			if ( ! r.success ) { return; }
			pages = Math.max( 1, r.data.pages );
			$( 'sm-rows' ).replaceChildren( ...r.data.rows.map( row ) );
			if ( ! r.data.rows.length ) { $( 'sm-rows' ).innerHTML = '<tr><td colspan="6">No posts found.</td></tr>'; }
			$( 'sm-total' ).textContent = r.data.total + ' items';
			$( 'sm-pageinfo' ).textContent = `${ page } of ${ pages }`;
			$( 'sm-prev' ).disabled = page <= 1;
			$( 'sm-next' ).disabled = page >= pages;
			$( 'sm-select-matching' ).textContent = `Select all ${ r.data.total } matching`;
			refresh();
		}

		$( 'sm-connect' ).onclick = connect;
		$( 'sm-type' ).onchange = () => { page = 1; load(); };
		$( 'sm-search' ).oninput = () => { clearTimeout( debounce ); debounce = setTimeout( () => { page = 1; load(); }, 300 ); };
		$( 'sm-prev' ).onclick = () => { page--; load(); };
		$( 'sm-next' ).onclick = () => { page++; load(); };
		$( 'sm-all' ).onchange = () => {
			const on = $( 'sm-all' ).checked;
			$( 'sm-rows' ).querySelectorAll( 'input' ).forEach( ( b ) => {
				b.checked = on;
				on ? selected.add( +b.value ) : selected.delete( +b.value );
			} );
			refresh();
		};
		$( 'sm-clear' ).onclick = () => { selected.clear(); load(); };
		$( 'sm-select-matching' ).onclick = async () => {
			const r = await call( 'sm_posts', { ...filters(), ids: 1 } );
			if ( r.success ) { r.data.forEach( ( id ) => selected.add( id ) ); load(); }
		};
		$( 'sm-stop' ).onclick = () => { stop = true; $( 'sm-stop' ).disabled = true; };
		window.addEventListener( 'beforeunload', ( e ) => { if ( sending ) { e.preventDefault(); } } );

		$( 'sm-send' ).onclick = async () => {
			const ids = [ ...selected ];
			let ok = 0, fail = 0;
			sending = true; stop = false;
			$( 'sm-stop' ).hidden = false; $( 'sm-stop' ).disabled = false;
			$( 'sm-progress' ).hidden = false;
			$( 'sm-log' ).replaceChildren();
			refresh();
			const flatten = $( 'sm-flatten' ).checked ? 1 : '';
			const bar = $( 'sm-progress' ).querySelector( 'progress' );
			for ( const [ i, id ] of ids.entries() ) {
				if ( stop ) { break; }
				const li = document.createElement( 'li' );
				$( 'sm-log' ).append( li );
				let title = titles.get( id ) || '#' + id, error = null, warnings = [], result;
				const step = ( label, done ) => {
					li.textContent = `… ${ title }: ${ label }`;
					$( 'sm-progress-text' ).textContent = `Post ${ i + 1 } of ${ ids.length }: ${ title } (${ label })`;
					bar.value = ( i + done ) / ids.length;
				};

				step( 'preparing', 0 );
				const plan = await call( 'sm_plan', { id, flatten } );
				if ( plan.success ) {
					title = plan.data.title;
					const files = plan.data.files;
					for ( const [ j, f ] of files.entries() ) {
						step( `file ${ j + 1 } of ${ files.length }`, ( j + 1 ) / ( files.length + 2 ) );
						const r = await call( 'sm_send_file', { id: f.id } );
						if ( ! r.success ) { warnings.push( `${ f.name } not sent: ${ r.data }` ); }
					}
					step( 'post', ( files.length + 1 ) / ( files.length + 2 ) );
					result = await call( 'sm_send', { id, flatten } );
					result.success ? warnings.push( ...result.data.warnings ) : ( error = result.data );
				} else {
					error = plan.data;
				}

				if ( error ) {
					fail++;
					li.className = 'sm-fail';
					li.textContent = `✗ ${ title }: ${ error }`;
				} else {
					ok++;
					selected.delete( id );
					li.replaceChildren( '✓ ' + title + ' — ', Object.assign( document.createElement( 'a' ), { href: result.data.edit, target: '_blank', textContent: 'edit on receiver' } ) );
					if ( warnings.length ) { li.className = 'sm-warn'; li.append( ' — ' + warnings.join( '; ' ) ); }
				}
				bar.value = ( i + 1 ) / ids.length;
			}
			$( 'sm-progress-text' ).textContent = `${ stop ? 'Stopped' : 'Done' }: ${ ok } sent, ${ fail } failed.` + ( fail ? ' Failed posts stay selected, so you can retry.' : '' );
			sending = false;
			$( 'sm-stop' ).hidden = true;
			load();
		};

		$( 'sm-url' ).value && $( 'sm-target-key' ).value ? connect() : load();
	} )();
	</script>
	<?php
}

/* ---------- Shared: find/replace attachment IDs in content and Elementor data ---------- */

function sm_media_refs( string $html, callable $cb ): string {
	$html = preg_replace_callback( '/\bwp-image-(\d+)/', fn( $m ) => 'wp-image-' . $cb( (int) $m[1] ), $html );
	return preg_replace_callback(
		'#<!-- wp:(?:core/)?(?:image|cover|video|audio|file|media-text) \{.*?\} /?-->#s',
		fn( $m ) => preg_replace_callback( '/"(id|mediaId)":(\d+)/', fn( $n ) => "\"$n[1]\":" . $cb( (int) $n[2] ), $m[0] ),
		$html
	);
}

function sm_elementor_refs( $data, callable $cb ) {
	if ( ! is_array( $data ) ) {
		return $data;
	}
	if ( isset( $data['id'], $data['url'] ) && is_numeric( $data['id'] ) ) {
		$data['id'] = $cb( (int) $data['id'] );
	}
	foreach ( $data as $k => $v ) {
		if ( is_array( $v ) ) {
			$data[ $k ] = sm_elementor_refs( $v, $cb );
		}
	}
	return $data;
}

/* ---------- Sender ---------- */

function sm_ajax_guard(): void {
	check_ajax_referer( 'sm' );
	current_user_can( 'manage_options' ) || wp_send_json_error( 'Not allowed.', 403 );
}

// Calls the receiver's admin-ajax (not the REST API, which sites often lock down for logged-out requests).
function sm_remote( string $action, ?array $payload = null ) {
	$res = wp_remote_post( get_option( 'sm_target_url' ) . '/wp-admin/admin-ajax.php?action=' . $action, [
		'timeout' => 300,
		'headers' => [ 'Content-Type' => 'application/json', 'X-SM-Key' => get_option( 'sm_target_key' ) ],
		'body'    => wp_json_encode( $payload ),
	] );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'sm', 'Could not reach the receiver: ' . $res->get_error_message() );
	}
	$code = wp_remote_retrieve_response_code( $res );
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( ! is_array( $body ) ) {
		$text = mb_substr( trim( wp_strip_all_tags( wp_remote_retrieve_body( $res ) ) ), 0, 400 );
		return new WP_Error( 'sm', $code >= 500
			? "Receiver crashed (HTTP $code)" . ( $text ? ": $text" : '' ) . '. Check the receiver’s PHP error log.'
			: "Unexpected reply (HTTP $code). Is Simple Migration installed and active on the receiver?" );
	}
	return empty( $body['success'] ) ? new WP_Error( 'sm', (string) $body['data'] ) : $body['data'];
}

add_action( 'wp_ajax_sm_connect', function () {
	sm_ajax_guard();
	update_option( 'sm_target_url', untrailingslashit( esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) ) ) );
	update_option( 'sm_target_key', sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) ) );
	$r = sm_remote( 'sm_ping' );
	if ( is_wp_error( $r ) ) {
		wp_send_json_error( $r->get_error_message() );
	}
	if ( untrailingslashit( $r['url'] ) === untrailingslashit( home_url() ) ) {
		wp_send_json_error( 'That URL is this site. Enter the other site’s URL.' );
	}
	wp_send_json_success( $r );
} );

add_action( 'wp_ajax_sm_posts', function () {
	sm_ajax_guard();
	$type = sanitize_key( $_POST['type'] ?? '' );
	isset( sm_post_types()[ $type ] ) || wp_send_json_error( 'Unknown post type.' );
	$args = [
		'post_type'   => $type,
		'post_status' => [ 'publish', 'future', 'draft', 'pending', 'private' ],
		's'           => sanitize_text_field( wp_unslash( $_POST['s'] ?? '' ) ),
		'orderby'     => 'date',
		'order'       => 'DESC',
	];
	if ( ! empty( $_POST['ids'] ) ) {
		wp_send_json_success( get_posts( $args + [ 'fields' => 'ids', 'posts_per_page' => -1 ] ) );
	}
	$q      = new WP_Query( $args + [ 'posts_per_page' => 20, 'paged' => max( 1, (int) ( $_POST['page'] ?? 1 ) ) ] );
	$target = get_option( 'sm_target_url' );
	wp_send_json_success( [
		'total' => $q->found_posts,
		'pages' => $q->max_num_pages,
		'rows'  => array_map( function ( $p ) use ( $target ) {
			$sent = ( get_post_meta( $p->ID, '_sm_sent', true ) ?: [] )[ $target ] ?? 0;
			return [
				'id'     => $p->ID,
				'builder' => sm_is_elementor( $p->ID ),
				'title'  => html_entity_decode( get_the_title( $p ) ?: '(no title)' ),
				'status' => get_post_status_object( $p->post_status )->label,
				'author' => get_the_author_meta( 'display_name', $p->post_author ),
				'date'   => get_the_date( '', $p ),
				'sent'   => $sent ? human_time_diff( $sent ) . ' ago' : '—',
			];
		}, $q->posts ),
	] );
} );

// Runs one sender step and always answers JSON: stray output (e.g. PHP notices from widgets rendered
// during Elementor conversion) is discarded, and errors become readable messages.
function sm_ajax_step( callable $fn ): void {
	sm_ajax_guard();
	set_time_limit( 0 );
	add_filter( 'wp_php_error_message', fn( $msg, $e ) => "PHP fatal on this site: {$e['message']} in {$e['file']}:{$e['line']}", 10, 2 );
	ob_start();
	try {
		$r = $fn( (int) ( $_POST['id'] ?? 0 ), ! empty( $_POST['flatten'] ) );
	} catch ( Throwable $e ) {
		$r = new WP_Error( 'sm', 'Error on this site: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
	}
	ob_end_clean();
	is_wp_error( $r ) ? wp_send_json_error( $r->get_error_message() ) : wp_send_json_success( $r );
}

// The browser sends a post in short steps (plan → each file → post), so no request runs into a proxy timeout.
add_action( 'wp_ajax_sm_plan', fn() => sm_ajax_step( function ( $id, $flatten ) {
	if ( ! get_post( $id ) ) {
		return new WP_Error( 'sm', 'Post not found.' );
	}
	return [
		'title' => html_entity_decode( get_the_title( $id ) ?: "#$id" ),
		'files' => array_map( fn( $a ) => [ 'id' => $a['ID'], 'name' => basename( $a['rel'] ) ], sm_export( $id, $flatten )['attachments'] ),
	];
} ) );

add_action( 'wp_ajax_sm_send_file', fn() => sm_ajax_step( fn( $id ) => sm_remote( 'sm_receive_media', sm_media_payload( $id ) ) ) );

add_action( 'wp_ajax_sm_send', fn() => sm_ajax_step( function ( $id, $flatten ) {
	$r = sm_remote( 'sm_receive', sm_export( $id, $flatten ) );
	if ( ! is_wp_error( $r ) ) {
		$sent = get_post_meta( $id, '_sm_sent', true ) ?: [];
		$sent[ get_option( 'sm_target_url' ) ] = time();
		update_post_meta( $id, '_sm_sent', $sent );
	}
	return $r;
} ) );

function sm_media_payload( int $id ): array {
	$a = sm_export_attachment( $id );
	return [
		'source'     => home_url(),
		'author'     => sm_export_user( (int) get_post_field( 'post_author', $id ) ),
		'attachment' => $a + [ 'data' => base64_encode( file_get_contents( wp_upload_dir()['basedir'] . '/' . $a['rel'] ) ) ],
	];
}

function sm_export( int $id, bool $flatten = false ): array {
	$post    = get_post( $id, ARRAY_A );
	$meta    = array_diff_key( get_post_meta( $id ), array_flip( SM_SKIP_META ) );
	$att     = get_children( [ 'post_parent' => $id, 'post_type' => 'attachment', 'fields' => 'ids' ] );
	$collect = function ( $i ) use ( &$att ) {
		$att[] = $i;
		return $i;
	};
	// Collect Elementor media before a conversion drops its data: carousels, galleries and backgrounds
	// don't render as <img class="wp-image-N">, but their files must still reach the receiver.
	$elementor = $meta['_elementor_data'][0] ?? '';
	if ( is_array( $d = json_decode( $elementor, true ) ) ) {
		sm_elementor_refs( $d, $collect );
	}
	if ( $flatten && sm_is_elementor( $id ) ) {
		$post['post_content'] = sm_flatten_elementor( $id );
		$meta                 = array_filter( $meta, fn( $v, $k ) => ! str_starts_with( (string) $k, '_elementor' )
			&& ! ( '_wp_page_template' === $k && str_starts_with( (string) $v[0], 'elementor' ) ), ARRAY_FILTER_USE_BOTH );
	}

	$att[] = $meta['_thumbnail_id'][0] ?? 0;
	sm_media_refs( $post['post_content'], $collect );
	sm_url_refs( implode( ' ', array_merge( [ $post['post_content'], $post['post_excerpt'], $elementor ], ...array_values( $meta ) ) ), $collect );
	$id_keys = array_merge( [ '_thumbnail_id' ], sm_acf_media_keys( $meta, $collect ) );

	$terms  = [];
	$assign = [];
	$term   = function ( $t ) use ( $collect ) {
		$meta = array_diff_key( get_term_meta( $t->term_id ), array_flip( SM_SKIP_META ) );
		return [
			'id'          => $t->term_id,
			'taxonomy'    => $t->taxonomy,
			'slug'        => $t->slug,
			'name'        => $t->name,
			'description' => $t->description,
			'parent'      => $t->parent ? get_term( $t->parent )->slug : '',
			'meta'        => $meta,
			'id_keys'     => sm_acf_media_keys( $meta, $collect ),
		];
	};
	foreach ( get_object_taxonomies( $post['post_type'] ) as $tax ) {
		$assign[ $tax ] = [];
		foreach ( wp_get_object_terms( $id, $tax ) as $t ) {
			foreach ( array_reverse( get_ancestors( $t->term_id, $tax, 'taxonomy' ) ) as $a ) {
				$terms[ $a ] ??= $term( get_term( $a, $tax ) ); // parents first so the receiver can link them
			}
			$terms[ $t->term_id ] ??= $term( $t );
			$assign[ $tax ][]       = $t->slug;
		}
	}
	$att = array_filter( array_unique( array_map( 'intval', $att ) ), fn( $i ) => 'attachment' === get_post_type( $i ) );

	return [
		'source'      => home_url(),
		'uploads_url' => wp_upload_dir()['baseurl'],
		'post'        => $post,
		'meta'        => $meta,
		'id_keys'     => $id_keys,
		'terms'       => array_values( $terms ),
		'assign'      => $assign,
		'attachments' => array_values( array_filter( array_map( 'sm_export_attachment', $att ) ) ),
		'author'      => sm_export_user( (int) $post['post_author'] ),
	];
}

function sm_export_user( int $id ): ?array {
	$u = get_userdata( $id );
	return $u ? [
		'user_login'    => $u->user_login,
		'user_email'    => $u->user_email,
		'user_pass'     => $u->user_pass, // hash, so the user keeps their password
		'user_nicename' => $u->user_nicename,
		'user_url'      => $u->user_url,
		'display_name'  => $u->display_name,
		'first_name'    => $u->first_name,
		'last_name'     => $u->last_name,
		'description'   => $u->description,
		'role'          => $u->roles[0] ?? '',
	] : null;
}

// Media referenced only by URL (pasted HTML, carousels, CSS backgrounds, plain-URL custom fields),
// including resized variants like foo-300x200.jpg and WP's foo-scaled.jpg originals.
function sm_url_refs( string $text, callable $collect ): void {
	$base = wp_upload_dir()['baseurl'];
	// The extension must end the URL, so dotted names (Screenshot-at-1.18.36-PM.jpg) aren't cut at ".18".
	preg_match_all( '#' . preg_quote( $base, '#' ) . '/([^\s"\'<>()\\\\]+?)(?:-\d+x\d+)?(\.[a-z][a-z0-9]{1,4})(?=[\s"\'<>()?\#&\\\\]|$)#i', str_replace( '\/', '/', $text ), $m, PREG_SET_ORDER );
	foreach ( array_unique( array_map( fn( $x ) => $x[1] . $x[2], $m ) ) as $file ) {
		$ext = strrchr( $file, '.' );
		$id  = attachment_url_to_postid( "$base/$file" ) ?: attachment_url_to_postid( $base . '/' . substr( $file, 0, -strlen( $ext ) ) . "-scaled$ext" );
		$id && $collect( $id );
	}
}

function sm_is_elementor( int $id ): bool {
	return class_exists( '\Elementor\Plugin' ) && 'builder' === get_post_meta( $id, '_elementor_edit_mode', true );
}

// Renders the Elementor layout and keeps only plain content markup: wrappers, styling, scripts and icons are dropped.
function sm_flatten_elementor( int $id ): string {
	// Video widgets render via JS (no embed in the HTML): emit the URL on its own line so WP auto-embeds it.
	$video = function ( $content, $widget ) {
		if ( 'video' !== $widget->get_name() ) {
			return $content;
		}
		$s   = $widget->get_settings_for_display();
		$url = 'hosted' === $s['video_type'] ? ( $s['hosted_url']['url'] ?? '' ) : ( $s[ $s['video_type'] . '_url' ] ?? '' );
		return $url ? '<p>' . esc_url( $url ) . '</p>' : '';
	};
	add_filter( 'elementor/widget/render_content', $video, 10, 2 );
	$html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $id );
	remove_filter( 'elementor/widget/render_content', $video, 10 );

	$html = preg_replace( '#<(style|script|noscript|svg|template|form)\b.*?</\1>#is', '', $html );
	$tags = array_fill_keys( [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tr', 'th', 'td' ], [] );
	$html = wp_kses( $html, $tags + [
		'a'      => [ 'href' => true, 'target' => true, 'rel' => true ],
		'img'    => [ 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'class' => true ], // class keeps wp-image-N for media migration
		'iframe' => [ 'src' => true, 'width' => true, 'height' => true, 'allowfullscreen' => true ],
	] );
	do { // drop tags left empty by stripping (icon <i>, icon-only links, spacer paragraphs)
		$html = preg_replace( '#<(i|b|strong|em|u|p|a|figure|li|ul|ol)\b[^>]*>\s*</\1>#', '', $html, -1, $n );
	} while ( $n );
	// Elementor's indentation would become <br>s via wpautop: collapse it, then break only around blocks.
	// ponytail: collapses whitespace inside <pre> too; handle separately if code blocks matter.
	$html = preg_replace( '/\s+/', ' ', $html );
	$html = preg_replace( '#\s*(<(?:h[1-6]|p|ul|ol|blockquote|figure|table|hr|iframe)\b[^>]*>)#', "\n\n$1", $html );
	$html = preg_replace( '#(</(?:h[1-6]|p|ul|ol|blockquote|figure|table|iframe)>|<img\b[^>]*>)\s*#', "$1\n\n", $html );
	$html = preg_replace( [ '#(<a\b[^>]*>)\s+#', '#\s+</a>#' ], [ '$1', '</a>' ], $html );
	return trim( preg_replace( "/\n{3,}/", "\n\n", $html ) );
}

// ACF image/file/gallery fields store attachment IDs; each value has a sibling "_key" => "field_xxx".
function sm_acf_media_keys( array $meta, callable $collect ): array {
	$keys = [];
	foreach ( function_exists( 'acf_get_field' ) ? $meta : [] as $k => $v ) {
		$ref = $meta[ "_$k" ][0] ?? '';
		if ( str_starts_with( $ref, 'field_' ) && ( $f = acf_get_field( $ref ) ) && in_array( $f['type'], [ 'image', 'file', 'gallery' ], true ) ) {
			$keys[] = (string) $k;
			array_map( $collect, (array) maybe_unserialize( $v[0] ) );
		}
	}
	return $keys;
}

function sm_export_attachment( int $id ): ?array {
	// Send what the site's URLs are named after. Normally that's the pre-"-scaled" original, so the receiver
	// regenerates identical names. An image edited in WP (foo-scaled-e1764412279817.jpg) is referenced by the
	// edited file and its sizes, so send the edit itself.
	$file = get_attached_file( $id );
	$orig = wp_get_original_image_path( $id );
	if ( $orig && preg_replace( '/-scaled(\.\w+)$/', '$1', (string) $file ) === $orig ) {
		$file = $orig;
	}
	if ( ! $file || ! is_readable( $file ) ) {
		return null;
	}
	$a   = get_post( $id );
	$rel = _wp_relative_upload_path( $file );
	return [
		'ID'          => $id,
		'rel'         => $rel,
		'post_parent' => $a->post_parent,
		'title'       => $a->post_title,
		'caption'     => $a->post_excerpt,
		'description' => $a->post_content,
		'date'        => $a->post_date,
		'alt'         => get_post_meta( $id, '_wp_attachment_image_alt', true ),
	];
}

/* ---------- Receiver ---------- */

// No login on the receiver: requests are authenticated by the shared key.
add_action( 'wp_ajax_nopriv_sm_ping', 'sm_ajax_ping' );
add_action( 'wp_ajax_sm_ping', 'sm_ajax_ping' );
add_action( 'wp_ajax_nopriv_sm_receive', 'sm_ajax_receive' );
add_action( 'wp_ajax_nopriv_sm_receive_media', 'sm_ajax_receive' );
add_action( 'wp_ajax_sm_receive_media', 'sm_ajax_receive' );
add_action( 'wp_ajax_sm_receive', 'sm_ajax_receive' );

function sm_ajax_ping() {
	sm_receiver_auth();
	wp_send_json_success( [ 'name' => get_bloginfo( 'name' ), 'url' => home_url(), 'limit' => ini_get( 'post_max_size' ) ] );
}

function sm_ajax_receive() {
	sm_receiver_auth();
	$p = json_decode( file_get_contents( 'php://input' ), true );
	is_array( $p ) || wp_send_json_error( 'The post arrived empty, probably larger than the receiver’s post_max_size (' . ini_get( 'post_max_size' ) . ').', 413 );
	set_time_limit( 0 );
	// Uncatchable fatals (memory, timeouts) go through WP's fatal handler: make it say what broke.
	add_filter( 'wp_php_error_message', fn( $msg, $e ) => "PHP fatal on receiver: {$e['message']} in {$e['file']}:{$e['line']}", 10, 2 );
	ob_start(); // PHP notices from other plugins' save hooks would corrupt the JSON reply
	try {
		$r = 'sm_receive_media' === $_GET['action'] ? sm_import_media( $p ) : sm_import( $p );
	} catch ( Throwable $e ) {
		$r = new WP_Error( 'sm', 'Receiver error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
	}
	ob_end_clean();
	is_wp_error( $r ) ? wp_send_json_error( $r->get_error_message(), 500 ) : wp_send_json_success( $r );
}

function sm_receiver_auth(): void {
	$key = (string) get_option( 'sm_key' );
	if ( '' === $key || ! hash_equals( $key, (string) ( $_SERVER['HTTP_X_SM_KEY'] ?? '' ) ) ) {
		wp_send_json_error( '' === $key ? 'Receiving is turned off on that site (its key is empty).' : 'Wrong key. Copy it again from Tools → Simple Migration on the receiver.', 403 );
	}
}

function sm_find( string $source ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_sm_source' AND meta_value = %s LIMIT 1", $source ) );
}

function sm_import_user( ?array $u ): int {
	if ( ! $u ) {
		return 0;
	}
	if ( $user = get_user_by( 'email', $u['user_email'] ) ?: get_user_by( 'login', $u['user_login'] ) ) {
		return $user->ID;
	}
	$fields = array_diff_key( $u, [ 'user_pass' => 1, 'role' => 1 ] );
	$id     = wp_insert_user( $fields + [
		'user_pass' => wp_generate_password( 24 ),
		'role'      => get_role( $u['role'] ) ? $u['role'] : get_option( 'default_role' ),
	] );
	if ( is_wp_error( $id ) ) {
		return 0;
	}
	global $wpdb;
	$wpdb->update( $wpdb->users, [ 'user_pass' => $u['user_pass'] ], [ 'ID' => $id ] );
	clean_user_cache( $id );
	return $id;
}

// Meta is written raw: mirrors the sender exactly, and avoids the slash round-trip that
// breaks on objects (unserialized as __PHP_Incomplete_Class, re-serialized unchanged).
function sm_write_meta( string $type, int $id, array $meta, array $id_keys, callable $cb, callable $rw ): void {
	global $wpdb;
	$table = _get_meta_table( $type );
	$col   = "{$type}_id";
	$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE $col = %d AND meta_key <> '_sm_source'", $id ) );
	foreach ( $meta as $k => $vals ) {
		foreach ( $vals as $v ) {
			$v = is_serialized( $v ) ? @unserialize( $v, [ 'allowed_classes' => false ] ) : $v;
			if ( in_array( (string) $k, $id_keys, true ) ) {
				$v = is_array( $v ) ? array_map( $cb, $v ) : ( is_numeric( $v ) ? $cb( (int) $v ) : $v );
			}
			if ( '_elementor_data' === $k && is_string( $v ) && is_array( $d = json_decode( $v, true ) ) ) {
				$v = wp_json_encode( sm_elementor_refs( $d, $cb ) );
			}
			$wpdb->insert( $table, [ $col => $id, 'meta_key' => $k, 'meta_value' => maybe_serialize( $rw( $v ) ) ] );
		}
	}
	wp_cache_delete( $id, "{$type}_meta" );
}

function sm_import_media( array $p ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_raise_memory_limit( 'image' );
	kses_remove_filters();

	$a  = $p['attachment'];
	$id = sm_find( "{$p['source']}|{$a['ID']}" );
	// Upload when new, or when the copy here is a different file than the sender now sends (imported before
	// edited-image support, or re-edited on the sender since). Names compare without -N/-scaled suffixes.
	$stem = fn( $path ) => preg_replace( '/(-\d+)?(-scaled)?\.\w+$/', '', basename( (string) $path ) );
	if ( $id && $stem( get_attached_file( $id ) ) === $stem( $a['rel'] ) ) {
		$file = null;
	} else {
		$sub  = preg_match( '#^(\d{4}/\d{2})/[^/]+$#', $a['rel'], $m ) ? $m[1] : null;
		$file = wp_upload_bits( basename( $a['rel'] ), null, base64_decode( $a['data'] ), $sub );
		if ( $file['error'] ) {
			return new WP_Error( 'sm', $file['error'] );
		}
	}
	if ( $file && $id ) { // replace in place: same ID, so posts already linking to it stay linked
		wp_delete_attachment_files( $id, wp_get_attachment_metadata( $id ), get_post_meta( $id, '_wp_attachment_backup_sizes', true ), get_attached_file( $id ) );
		update_attached_file( $id, $file['file'] );
		wp_update_post( [ 'ID' => $id, 'post_mime_type' => $file['type'] ] );
		delete_post_meta( $id, '_wp_attachment_metadata' );
		delete_post_meta( $id, '_wp_attachment_backup_sizes' );
	} elseif ( $file ) {
		$id = wp_insert_attachment( wp_slash( [
			'post_title'     => $a['title'],
			'post_excerpt'   => $a['caption'],
			'post_content'   => $a['description'],
			'post_date'      => $a['date'],
			'post_author'    => sm_import_user( $p['author'] ),
			'post_mime_type' => $file['type'],
		] ), $file['file'] );
		update_post_meta( $id, '_sm_source', "{$p['source']}|{$a['ID']}" ); // before resizing, the step most likely to run out of memory
		update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $a['alt'] ) );
	}
	// New, or an earlier attempt died while generating sizes: (re)generate them.
	if ( ! wp_get_attachment_metadata( $id ) ) {
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, get_attached_file( $id ) ) );
	}
	return [ 'id' => $id ];
}

function sm_import( array $p ) {
	kses_remove_filters(); // the request has no logged-in user; keep content exactly as sent

	$src      = $p['source'];
	$up       = wp_upload_dir();
	$warnings = [];
	$author   = sm_import_user( $p['author'] );
	if ( $p['author'] && ! $author ) {
		$warnings[] = "could not create user {$p['author']['user_login']}";
	}

	// Files arrived in earlier sm_receive_media requests; a missing one already failed there and was reported.
	$map     = [];
	$renames = [];
	foreach ( $p['attachments'] as $a ) {
		if ( ! $id = sm_find( "$src|{$a['ID']}" ) ) {
			continue;
		}
		$map[ $a['ID'] ] = $id;
		// Filename collided on the receiver (foo.jpg → foo-1.jpg): rewrite references incl. sizes and -scaled.
		$old = preg_replace( '/\.[^.\/]+$/', '', $a['rel'] );
		$new = preg_replace( '/\.[^.\/]+$/', '', _wp_relative_upload_path( wp_get_original_image_path( $id ) ?: get_attached_file( $id ) ) );
		if ( $old !== $new ) {
			$renames[ '#(?<=/)' . str_replace( '/', '\\\\?/', preg_quote( $old, '#' ) ) . '((?:-scaled)?(?:-\d+x\d+)?\.\w+)#' ] = $new;
		}
	}

	$cb    = fn( $i ) => $map[ $i ] ?? $i;
	$pairs = [];
	foreach ( [ $p['uploads_url'] => $up['baseurl'], $src => home_url() ] as $from => $to ) {
		$pairs[ $from ]                          = $to;
		$pairs[ str_replace( '/', '\/', $from ) ] = str_replace( '/', '\/', $to ); // JSON-escaped (Elementor, blocks)
	}
	$rw = function ( $v ) use ( &$rw, $pairs, $renames ) {
		if ( is_array( $v ) ) {
			return array_map( $rw, $v );
		}
		if ( ! is_string( $v ) ) {
			return $v;
		}
		$v = strtr( $v, $pairs );
		foreach ( $renames as $re => $new ) {
			$v = preg_replace_callback( $re, fn( $m ) => ( str_contains( $m[0], '\/' ) ? str_replace( '/', '\/', $new ) : $new ) . $m[1], $v );
		}
		return $v;
	};

	// Post: update if this source post was migrated before, otherwise create.
	$sp       = $p['post'];
	$existing = sm_find( "$src|{$sp['ID']}" );
	$pid      = wp_insert_post( wp_slash( [
		'ID'             => $existing,
		'post_author'    => $author,
		'post_parent'    => $sp['post_parent'] ? sm_find( "$src|{$sp['post_parent']}" ) : 0,
		'post_content'   => $rw( sm_media_refs( $sp['post_content'], $cb ) ),
		'post_excerpt'   => $rw( $sp['post_excerpt'] ),
	] + array_intersect_key( $sp, array_flip( [
		'post_title', 'post_name', 'post_status', 'post_type', 'post_date', 'post_date_gmt',
		'post_password', 'comment_status', 'ping_status', 'menu_order',
	] ) ) ), true );
	if ( is_wp_error( $pid ) ) {
		return $pid;
	}
	update_post_meta( $pid, '_sm_source', "$src|{$sp['ID']}" ); // set first, so a retry after a crash updates instead of duplicating

	sm_write_meta( 'post', $pid, $p['meta'], $p['id_keys'], $cb, $rw );

	// Terms: create missing ones; update only terms an earlier migration created, never the receiver's own.
	foreach ( $p['terms'] as $t ) {
		if ( ! taxonomy_exists( $t['taxonomy'] ) ) {
			continue;
		}
		$key    = "$src|term|{$t['id']}";
		$term   = get_term_by( 'slug', $t['slug'], $t['taxonomy'] );
		$parent = $t['parent'] ? get_term_by( 'slug', $t['parent'], $t['taxonomy'] ) : null;
		$args   = wp_slash( [ 'name' => $t['name'], 'slug' => $t['slug'], 'description' => $rw( $t['description'] ), 'parent' => $parent->term_id ?? 0 ] );
		if ( ! $term ) {
			$res = wp_insert_term( $args['name'], $t['taxonomy'], $args );
		} elseif ( get_term_meta( $term->term_id, '_sm_source', true ) === $key ) {
			$res = wp_update_term( $term->term_id, $t['taxonomy'], $args );
		} else {
			continue;
		}
		if ( is_wp_error( $res ) ) {
			$warnings[] = "term {$t['slug']}: " . $res->get_error_message();
			continue;
		}
		sm_write_meta( 'term', $res['term_id'], $t['meta'], $t['id_keys'], $cb, $rw );
		update_term_meta( $res['term_id'], '_sm_source', $key );
	}
	foreach ( $p['assign'] as $tax => $slugs ) {
		if ( taxonomy_exists( $tax ) ) {
			wp_set_object_terms( $pid, $slugs, $tax );
		} elseif ( $slugs ) {
			$warnings[] = "taxonomy $tax missing";
		}
	}

	foreach ( $p['attachments'] as $a ) {
		if ( (int) $a['post_parent'] === (int) $sp['ID'] && isset( $map[ $a['ID'] ] ) ) {
			wp_update_post( [ 'ID' => $map[ $a['ID'] ], 'post_parent' => $pid ] );
		}
	}

	if ( class_exists( '\Elementor\Plugin' ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}

	return [ 'id' => $pid, 'warnings' => $warnings, 'edit' => admin_url( "post.php?post=$pid&action=edit" ) ];
}
