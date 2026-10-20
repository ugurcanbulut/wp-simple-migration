<?php
/**
 * Plugin Name: Simple Migration
 * Description: Send selected posts (with author, terms, meta and attachments) to another site running this plugin.
 * Version: 1.1.0
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

const SM_VERSION   = '1.1.0';
const SM_SKIP_META = [
	'_edit_lock', '_edit_last', '_elementor_css', '_elementor_element_cache',
	'_pingme', '_encloseme', // would make the receiver ping/fetch external sites
	'_sm_source', '_sm_source_modified', '_sm_sent',
];

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

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'tools_page_simple-migration' !== $hook ) {
		return;
	}
	$assets = plugin_dir_url( __FILE__ ) . 'assets/';
	wp_enqueue_style( 'simple-migration', $assets . 'admin.css', [ 'dashicons' ], SM_VERSION );
	wp_enqueue_script( 'simple-migration', $assets . 'admin.js', [], SM_VERSION, true );
	wp_add_inline_script( 'simple-migration', 'window.smConfig = ' . wp_json_encode( [
		'ajax'  => admin_url( 'admin-ajax.php' ),
		'nonce' => wp_create_nonce( 'sm' ),
		'home'  => home_url(),
		'url'   => get_option( 'sm_target_url' ),
		'key'   => get_option( 'sm_target_key' ),
	] ) . ';', 'before' );
} );

function sm_post_types(): array {
	return array_filter( get_post_types( [ 'show_ui' => true ], 'objects' ), fn( $t ) => 'attachment' !== $t->name && ! preg_match( '/^(wp_|acf-)/', $t->name ) );
}

function sm_page() {
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	?>
	<div class="wrap sm">
		<header class="sm-header">
			<div>
				<h1 class="sm-title">Simple Migration</h1>
				<p class="sm-subtitle">Send posts from <strong><?php echo esc_html( $host ); ?></strong> to another WordPress site.</p>
			</div>
			<div class="sm-connection" id="sm-connection" aria-live="polite"></div>
		</header>
		<hr class="wp-header-end">

		<section class="sm-card sm-connect" id="sm-connect-card" aria-labelledby="sm-connect-title">
			<h2 id="sm-connect-title" class="sm-card-title">Connect a receiving site</h2>
			<form class="sm-connect-form" id="sm-connect-form">
				<p class="sm-field">
					<label for="sm-url">Site URL</label>
					<input id="sm-url" type="url" placeholder="https://example.com" required>
				</p>
				<p class="sm-field">
					<label for="sm-target-key">Key</label>
					<input id="sm-target-key" class="code" autocomplete="off" spellcheck="false" required>
				</p>
				<p class="sm-field sm-field--action">
					<button type="submit" class="button button-primary" id="sm-connect">Connect</button>
					<button type="button" class="button" id="sm-connect-cancel" hidden>Cancel</button>
				</p>
			</form>
			<p class="sm-help">Install Simple Migration on the receiving site and copy the key from its <em>Tools → Simple Migration</em> page.</p>
			<p class="sm-notice sm-notice--error" id="sm-connect-error" role="alert" hidden></p>
		</section>

		<details class="sm-card sm-health" id="sm-health" hidden>
			<summary>
				<span class="sm-card-title">Health check</span>
				<span class="sm-health-summary" id="sm-health-summary"></span>
			</summary>
			<table class="sm-health-table">
				<thead><tr><th scope="col">Check</th><th scope="col">This site</th><th scope="col">Receiver</th></tr></thead>
				<tbody id="sm-health-rows"></tbody>
			</table>
			<p class="sm-help">During a migration, Simple Migration raises memory to 1&nbsp;GB, lifts the time limit and turns on WordPress import mode on the receiver. This applies to its own requests only: nothing in <code>wp-config.php</code> or <code>php.ini</code> is changed. Values above already include it.</p>
		</details>

		<div class="sm-toolbar">
			<label for="sm-type">Post type</label>
			<select id="sm-type">
				<?php foreach ( sm_post_types() as $t ) : ?>
					<option value="<?php echo esc_attr( $t->name ); ?>"><?php echo esc_html( $t->labels->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button sm-icon-button" id="sm-refresh"><span class="dashicons dashicons-update" aria-hidden="true"></span>Refresh</button>
		</div>

		<div class="sm-panes">
			<section class="sm-card sm-pane" aria-labelledby="sm-source-title">
				<header class="sm-pane-head">
					<h2 class="sm-card-title" id="sm-source-title">This site</h2>
					<span class="sm-host"><?php echo esc_html( $host ); ?></span>
				</header>
				<div class="sm-filters" id="sm-source-filters" role="group" aria-label="Filter this site’s posts"></div>
				<label class="screen-reader-text" for="sm-source-search">Search this site’s posts</label>
				<input type="search" class="sm-search" id="sm-source-search" placeholder="Search by title, slug or ID">
				<table class="sm-list">
					<thead><tr>
						<td class="sm-col-check"><input type="checkbox" id="sm-all" aria-label="Select all shown posts"></td>
						<th scope="col">Post</th>
						<th scope="col" class="sm-col-state">On receiver</th>
					</tr></thead>
					<tbody id="sm-source-rows"></tbody>
				</table>
				<nav class="sm-pager" id="sm-source-pager" aria-label="This site’s posts pages"></nav>
			</section>

			<section class="sm-card sm-pane" aria-labelledby="sm-target-title">
				<header class="sm-pane-head">
					<h2 class="sm-card-title" id="sm-target-title">Receiver</h2>
					<span class="sm-host" id="sm-target-host">Not connected</span>
				</header>
				<div class="sm-filters" id="sm-target-filters" role="group" aria-label="Filter the receiver’s posts"></div>
				<label class="screen-reader-text" for="sm-target-search">Search the receiver’s posts</label>
				<input type="search" class="sm-search" id="sm-target-search" placeholder="Search by title, slug or ID">
				<table class="sm-list">
					<thead><tr>
						<th scope="col">Post</th>
						<th scope="col" class="sm-col-state">Origin</th>
						<td class="sm-col-action"></td>
					</tr></thead>
					<tbody id="sm-target-rows"></tbody>
				</table>
				<nav class="sm-pager" id="sm-target-pager" aria-label="Receiver’s posts pages"></nav>
			</section>
		</div>

		<div class="sm-actionbar" id="sm-actionbar">
			<div class="sm-selection">
				<strong id="sm-count">No posts selected</strong>
				<button type="button" class="button-link" id="sm-select-filter"></button>
				<button type="button" class="button-link" id="sm-clear" hidden>Clear</button>
			</div>
			<label class="sm-switch" title="Elementor layouts are sent as plain content (headings, text, images, links). Design and styling are not kept, and the receiver doesn’t need Elementor.">
				<input type="checkbox" id="sm-flatten">
				<span>Convert Elementor to standard content</span>
			</label>
			<div class="sm-progress" id="sm-progress" hidden>
				<progress id="sm-bar" max="1" value="0"></progress>
				<span id="sm-progress-text" aria-live="polite"></span>
			</div>
			<button type="button" class="button" id="sm-stop" hidden>Stop after this post</button>
			<button type="button" class="button button-primary" id="sm-send" disabled>Send</button>
		</div>

		<section class="sm-card sm-activity" id="sm-activity" aria-labelledby="sm-activity-title" hidden>
			<h2 class="sm-card-title" id="sm-activity-title">Activity</h2>
			<ol class="sm-log" id="sm-log"></ol>
		</section>

		<details class="sm-card sm-receive">
			<summary><span class="sm-card-title">Receiving posts on this site</span></summary>
			<form method="post" action="options.php" class="sm-receive-form">
				<?php settings_fields( 'simple-migration' ); ?>
				<p class="sm-field">
					<label for="sm_key">This site’s key</label>
					<input id="sm_key" name="sm_key" class="code" value="<?php echo esc_attr( get_option( 'sm_key' ) ); ?>" autocomplete="off" spellcheck="false">
				</p>
				<?php submit_button( 'Save key', 'secondary', 'submit', false ); ?>
			</form>
			<p class="sm-help">Paste this key into the sending site. Whoever has it can add posts and users here, so keep it private. Clear it to stop receiving.</p>
		</details>
	</div>
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

/* ---------- Shared: limits, environment report, post lists ---------- */

// Raised for Simple Migration's own requests only, so it's temporary by construction: nothing in
// wp-config.php, php.ini or .htaccess changes. Hosts that lock limits (php_admin_value) keep theirs,
// and the health check shows the effective values.
function sm_boost(): void {
	add_filter( 'simple_migration_memory_limit', fn() => '1024M', 0 ); // later filters can change it
	wp_raise_memory_limit( 'simple_migration' );
	set_time_limit( 0 );
}

function sm_env(): array {
	sm_boost();
	$editor = _wp_image_editor_choose( [ 'mime_type' => 'image/jpeg', 'methods' => [ 'resize' ] ] );
	return [
		'name'     => get_bloginfo( 'name' ),
		'url'      => home_url(),
		'admin'    => admin_url(),
		'version'  => SM_VERSION,
		'wp'       => get_bloginfo( 'version' ),
		'php'      => PHP_VERSION,
		'memory'   => ini_get( 'memory_limit' ),
		'time'     => ini_get( 'max_execution_time' ),
		'post_max' => ini_get( 'post_max_size' ),
		'editor'   => $editor ? str_replace( 'WP_Image_Editor_', '', $editor ) : 'none',
	];
}

// ponytail: whole list in one response (a few hundred bytes per post); page it server-side past ~20k posts.
function sm_list_posts( string $type ): array {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT p.ID, p.post_title, p.post_name, p.post_status, p.post_date, p.post_modified_gmt, p.post_author,
			MAX( CASE WHEN m.meta_key = '_sm_source' THEN m.meta_value END ) AS source,
			MAX( CASE WHEN m.meta_key = '_sm_source_modified' THEN m.meta_value END ) AS source_modified,
			MAX( CASE WHEN m.meta_key = '_elementor_edit_mode' THEN m.meta_value END ) AS builder
		FROM $wpdb->posts p
		LEFT JOIN $wpdb->postmeta m ON m.post_id = p.ID AND m.meta_key IN ( '_sm_source', '_sm_source_modified', '_elementor_edit_mode' )
		WHERE p.post_type = %s AND p.post_status IN ( 'publish', 'future', 'draft', 'pending', 'private' )
		GROUP BY p.ID ORDER BY p.post_date DESC",
		$type
	) );
	$authors   = wp_list_pluck( get_users( [ 'include' => array_unique( wp_list_pluck( $rows, 'post_author' ) ) ?: [ 0 ], 'fields' => [ 'ID', 'display_name' ] ] ), 'display_name', 'ID' );
	$elementor = class_exists( '\Elementor\Plugin' );
	$time      = fn( $gmt ) => $gmt && '0000-00-00 00:00:00' !== $gmt ? strtotime( "$gmt UTC" ) : 0;
	return [
		'type_exists' => post_type_exists( $type ),
		'posts'       => array_map( fn( $r ) => [
			'id'              => (int) $r->ID,
			'title'           => html_entity_decode( $r->post_title ) ?: '(no title)',
			'slug'            => urldecode( $r->post_name ),
			'status'          => get_post_status_object( $r->post_status )->label ?? $r->post_status,
			'date'            => mysql2date( get_option( 'date_format' ), $r->post_date ),
			'modified'        => $time( $r->post_modified_gmt ),
			'author'          => $authors[ $r->post_author ] ?? '',
			'builder'         => $elementor && 'builder' === $r->builder,
			'source'          => $r->source,
			'source_modified' => $time( $r->source_modified ),
		], $rows ),
	];
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
	wp_send_json_success( [ 'sender' => sm_env(), 'receiver' => $r ] );
} );

// Both sides' posts of one type, for the side-by-side view. Matching happens in the browser.
add_action( 'wp_ajax_sm_lists', fn() => sm_ajax_step( function () {
	$type = sanitize_key( $_POST['type'] ?? '' );
	if ( ! isset( sm_post_types()[ $type ] ) ) {
		return new WP_Error( 'sm', 'Unknown post type.' );
	}
	$receiver = get_option( 'sm_target_url' ) ? sm_remote( 'sm_list', [ 'type' => $type ] ) : null;
	return [
		'sender'   => sm_list_posts( $type ),
		'receiver' => is_wp_error( $receiver ) ? [ 'error' => $receiver->get_error_message() ] : $receiver,
	];
} ) );

// Runs one sender step and always answers JSON: stray output (e.g. PHP notices from widgets rendered
// during Elementor conversion) is discarded, and errors become readable messages.
function sm_ajax_step( callable $fn ): void {
	sm_ajax_guard();
	sm_boost();
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

add_action( 'wp_ajax_sm_send', fn() => sm_ajax_step( fn( $id, $flatten ) => sm_remote( 'sm_receive', sm_export( $id, $flatten ) ) ) );

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
add_action( 'wp_ajax_nopriv_sm_list', 'sm_ajax_list' );
add_action( 'wp_ajax_sm_list', 'sm_ajax_list' );

function sm_ajax_ping() {
	sm_receiver_auth();
	wp_send_json_success( sm_env() );
}

function sm_ajax_list() {
	sm_receiver_auth();
	$p = json_decode( file_get_contents( 'php://input' ), true );
	wp_send_json_success( sm_list_posts( sanitize_key( $p['type'] ?? '' ) ) );
}

function sm_ajax_receive() {
	sm_receiver_auth();
	$p = json_decode( file_get_contents( 'php://input' ), true );
	is_array( $p ) || wp_send_json_error( 'The post arrived empty, probably larger than the receiver’s post_max_size (' . ini_get( 'post_max_size' ) . ').', 413 );
	sm_boost();
	defined( 'WP_IMPORTING' ) || define( 'WP_IMPORTING', true ); // core and plugins skip pings, enclosures and similar extras
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
	$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE $col = %d AND meta_key NOT IN ( '_sm_source', '_sm_source_modified' )", $id ) );
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
	update_post_meta( $pid, '_sm_source_modified', $sp['post_modified_gmt'] ); // lets the sender tell "changed since sent"

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
