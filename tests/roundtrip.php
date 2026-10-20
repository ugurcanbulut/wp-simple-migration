<?php
/**
 * Round-trip check: push a post (files, then post) as if from another site, assert, roll back.
 * Usage (from WP root): php -d zend.assertions=1 -d assert.exception=1 wp-content/plugins/simple-migration/tests/roundtrip.php [post_id]
 * DB changes run in a transaction and are rolled back; uploads go to a temp dir.
 */
PHP_SAPI === 'cli' || exit;
define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
require dirname( __DIR__, 4 ) . '/wp-load.php';
ini_set( 'display_errors', 1 ); // WP turns it off
// Wrapped in a function: save_post hooks call setup_postdata(), which clobbers globals like $id.
( function () use ( $argv ) {
global $wpdb;

// Pure helpers: attachment ID rewriting in block markup and Elementor data.
$map = fn( $i ) => [ 5 => 50, 7 => 70 ][ $i ] ?? $i;
assert( sm_media_refs( '<!-- wp:image {"id":5,"sizeSlug":"large"} --><img class="wp-image-5"><!-- /wp:image --><!-- wp:navigation-link {"id":7} /-->', $map )
	=== '<!-- wp:image {"id":50,"sizeSlug":"large"} --><img class="wp-image-50"><!-- /wp:image --><!-- wp:navigation-link {"id":7} /-->', 'block refs' );
assert( sm_elementor_refs( [ [ 'id' => 'a1b2', 'settings' => [ 'image' => [ 'url' => 'x.jpg', 'id' => 5 ], 'gallery' => [ [ 'id' => 7, 'url' => 'y.jpg' ] ] ] ] ], $map )
	=== [ [ 'id' => 'a1b2', 'settings' => [ 'image' => [ 'url' => 'x.jpg', 'id' => 50 ], 'gallery' => [ [ 'id' => 70, 'url' => 'y.jpg' ] ] ] ] ], 'elementor refs' );

$id = (int) ( $argv[1] ?? $wpdb->get_var( "SELECT p.ID FROM $wpdb->posts p JOIN $wpdb->postmeta m ON m.post_id = p.ID AND m.meta_key = '_thumbnail_id' WHERE p.post_status = 'publish' AND p.post_type = 'post' ORDER BY p.ID DESC LIMIT 1" ) );
$tmp = sys_get_temp_dir() . '/sm-test-' . getmypid();

$fake         = 'https://sender.example';
$sender_files = []; // files the test writes into the real uploads dir, removed at the end
$wpdb->query( 'START TRANSACTION' );
try {
	// Seed term meta (this site has none) on the post's first term, then export.
	$src_term = wp_get_object_terms( $id, get_object_taxonomies( get_post_type( $id ) ) )[0] ?? null;
	if ( $src_term ) {
		update_term_meta( $src_term->term_id, 'sm_test_color', 'red' );
		update_term_meta( $src_term->term_id, 'sm_test_banner', home_url( '/wp-content/uploads/banner.jpg' ) );
	}

	// An Elementor post, exported with "convert to standard content" on.
	[ $img, $img2 ] = array_map( 'intval', $wpdb->get_col( "SELECT p.ID FROM $wpdb->posts p JOIN $wpdb->postmeta m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_metadata' WHERE p.post_type = 'attachment' AND p.post_mime_type = 'image/jpeg' AND m.meta_value LIKE '%medium%' ORDER BY p.ID DESC LIMIT 2" ) );
	$img2_medium = wp_get_attachment_image_url( $img2, 'medium' ); // a resized variant, referenced only by URL
	$found       = function ( $text ) {
		$ids = [];
		sm_url_refs( $text, function ( $i ) use ( &$ids ) { $ids[] = $i; } );
		return $ids;
	};
	assert( $found( wp_json_encode( [ 'url' => $img2_medium ] ) ) === [ $img2 ], 'JSON-escaped URL' );
	assert( $found( "<img srcset=\"$img2_medium 300w, https://x.example/a.jpg 600w\">" ) === [ $img2 ], 'srcset URL' );
	assert( $found( "background-image:url($img2_medium)" ) === [ $img2 ], 'CSS url()' );
	$el  = wp_insert_post( [ 'post_title' => 'sm flatten test', 'post_type' => 'post', 'post_status' => 'publish', 'post_content' => 'stale classic content' ] );
	$w   = fn( $type, $settings ) => [ 'id' => substr( md5( $type ), 0, 7 ), 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => [] ];
	update_post_meta( $el, '_elementor_edit_mode', 'builder' );
	update_post_meta( $el, '_wp_page_template', 'elementor_header_footer' );
	update_post_meta( $el, '_elementor_data', wp_slash( wp_json_encode( [ [ 'id' => 'c0ntain', 'elType' => 'container', 'settings' => [], 'elements' => [
		$w( 'heading', [ 'title' => 'Buying Land', 'header_size' => 'h2' ] ),
		$w( 'image', [ 'image' => [ 'id' => $img, 'url' => wp_get_attachment_url( $img ) ], 'image_size' => 'large' ] ),
		$w( 'icon', [ 'selected_icon' => [ 'value' => 'fas fa-star', 'library' => 'fa-solid' ] ] ),
		$w( 'text-editor', [ 'editor' => "<p><img src=\"$img2_medium\" alt=\"pasted\"></p>" ] ),
		$w( 'video', [ 'youtube_url' => 'https://www.youtube.com/watch?v=XHOmBV4js_E' ] ),
	] ] ] ) ) );

	// Transport stand-in for HTTP: rename the site to $fake, and receive into a temp uploads dir.
	$tmp_uploads = fn( $d ) => array_merge( $d, [ 'basedir' => $tmp, 'path' => $tmp . $d['subdir'], 'baseurl' => home_url( '/sm-test' ), 'url' => home_url( '/sm-test' . $d['subdir'] ) ] );
	$requests    = [];
	$send        = function ( $action, $data ) use ( $fake, $tmp_uploads, &$requests ) {
		$data = json_decode( str_replace( str_replace( '/', '\\/', home_url() ), str_replace( '/', '\\/', $fake ), wp_json_encode( $data ) ), true );
		if ( 'sm_receive' === $action ) {
			// Same-site test: suffix term slugs so the receiver creates (then updates) them instead of keeping its own.
			foreach ( $data['terms'] as &$t ) {
				$t['slug']  .= '-smt';
				$t['parent'] = $t['parent'] ? $t['parent'] . '-smt' : '';
			}
			unset( $t );
			$data['assign'] = array_map( fn( $slugs ) => array_map( fn( $s ) => "$s-smt", $slugs ), $data['assign'] );
			assert( ! array_filter( $data['attachments'], fn( $a ) => isset( $a['data'] ) ), 'post request carries no file data' );
		}
		$requests[] = $action;
		add_filter( 'upload_dir', $tmp_uploads );
		$r = 'sm_receive_media' === $action ? sm_import_media( $data ) : sm_import( $data );
		remove_filter( 'upload_dir', $tmp_uploads );
		return $r;
	};

	// Same steps the admin page runs: each file in its own request, then the post.
	$push = function ( $post_id, $flatten ) use ( $send ) {
		foreach ( sm_export( $post_id, $flatten )['attachments'] as $a ) {
			$r = $send( 'sm_receive_media', sm_media_payload( $a['ID'] ) );
			assert( ! is_wp_error( $r ), 'file upload: ' . ( is_wp_error( $r ) ? $r->get_error_message() : '' ) );
		}
		return $send( 'sm_receive', sm_export( $post_id, $flatten ) );
	};

	$res1  = $push( $id, false );
	$files = count( $requests ) - 1;
	assert( end( $requests ) === 'sm_receive' && count( array_unique( $requests ) ) <= 2, 'one request per file, then the post' );
	// Simulate a receiver that died while generating image sizes: the re-send must regenerate them.
	if ( $crashed = (int) get_post_meta( $res1['id'], '_thumbnail_id', true ) ) {
		delete_post_meta( $crashed, '_wp_attachment_metadata' );
	}
	$res2 = $push( $id, false ); // re-send must update, not duplicate
	assert( ! is_wp_error( $res1 ), is_wp_error( $res1 ) ? $res1->get_error_message() : '' );
	$new = $res1['id'];
	assert( $new !== $id && $res2['id'] === $new, 'second import updates same post' );
	assert( get_post_meta( $new, '_sm_source', true ) === "$fake|$id" );

	$src = get_post( $id );
	$dst = get_post( $new );
	assert( $dst->post_title === $src->post_title && $dst->post_author === $src->post_author );
	assert( ! str_contains( $dst->post_content, 'sender.example' ), 'content URLs rewritten' );
	foreach ( get_post_meta( $new ) as $k => $vals ) {
		assert( str_starts_with( $k, '_sm_source' ) || ! str_contains( implode( $vals ), 'sender.example' ), "meta $k URLs rewritten" );
		assert( str_starts_with( $k, '_sm_source' ) || count( $vals ) === count( get_post_meta( $id, $k ) ), "meta $k count" );
	}

	// What the side-by-side view reads: the copy is listed with its source and the sender's modified time.
	assert( get_post_meta( $new, '_sm_source_modified', true ) === $src->post_modified_gmt, 'sender modified time stored' );
	$listed = array_column( sm_list_posts( $src->post_type )['posts'], null, 'id' )[ $new ];
	assert( $listed['source'] === "$fake|$id" && $listed['source_modified'] === strtotime( "$src->post_modified_gmt UTC" ), 'copy listed with source' );
	assert( ! metadata_exists( 'post', $new, '_pingme' ) && ! metadata_exists( 'post', $new, '_encloseme' ), 'no ping/enclosure markers copied' );

	if ( $old_thumb = (int) get_post_thumbnail_id( $id ) ) {
		$thumb = (int) get_post_meta( $new, '_thumbnail_id', true );
		assert( $thumb && $thumb !== $old_thumb, 'thumbnail remapped' );
		assert( get_post_meta( $thumb, '_sm_source', true ) === "$fake|$old_thumb" );
		assert( is_file( get_attached_file( $thumb ) ), 'thumbnail file uploaded' );
		assert( ! empty( wp_get_attachment_metadata( $thumb )['sizes'] ), 'image sizes regenerated on retry' );
	}
	// ACF image/file/gallery fields (and _thumbnail_id) must point at migrated attachments.
	foreach ( sm_export( $id )['id_keys'] as $k ) {
		foreach ( array_filter( (array) get_post_meta( $new, $k, true ) ) as $i ) {
			assert( str_starts_with( get_post_meta( $i, '_sm_source', true ), "$fake|" ), "id meta $k=$i remapped" );
		}
	}
	// Every Elementor media reference must now point at a migrated attachment.
	sm_elementor_refs( json_decode( get_post_meta( $new, '_elementor_data', true ) ?: '[]', true ), function ( $i ) use ( $fake ) {
		assert( str_starts_with( get_post_meta( $i, '_sm_source', true ), "$fake|" ), "elementor media $i remapped" );
		return $i;
	} );

	foreach ( get_object_taxonomies( $src->post_type ) as $tax ) {
		$want = array_map( fn( $s ) => "$s-smt", wp_get_object_terms( $id, $tax, [ 'fields' => 'slugs' ] ) );
		assert( $want == wp_get_object_terms( $new, $tax, [ 'fields' => 'slugs' ] ), "terms $tax" );
	}
	if ( $src_term ) {
		$dst_term = get_term_by( 'slug', "$src_term->slug-smt", $src_term->taxonomy );
		assert( get_term_meta( $dst_term->term_id, 'sm_test_color' ) === [ 'red' ], 'term meta copied once' );
		assert( get_term_meta( $dst_term->term_id, 'sm_test_banner', true ) === home_url( '/sm-test/banner.jpg' ), 'term meta URL rewritten to receiver uploads' );
		assert( get_term_meta( $dst_term->term_id, '_sm_source', true ) === "$fake|term|$src_term->term_id" );
		if ( $src_term->parent ) {
			assert( get_term( $dst_term->parent )->slug === get_term( $src_term->parent )->slug . '-smt', 'term parent linked' );
		}
	}
	// Elementor flattening: standard content, no Elementor data, image migrated and remapped.
	$fr  = $push( $el, true );
	$fc  = get_post_field( 'post_content', $fr['id'] );
	$fid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_sm_source' AND meta_value = %s", "$fake|$img" ) );
	assert( str_contains( $fc, '<h2>Buying Land</h2>' ) && str_contains( $fc, "wp-image-$fid" ) && $fid !== $img, 'flattened content with remapped image' );
	assert( str_contains( $fc, '<p>https://www.youtube.com/watch?v=XHOmBV4js_E</p>' ), 'video becomes an embeddable URL' );
	assert( ! str_contains( $fc, 'elementor' ) && ! str_contains( $fc, 'stale' ), 'no Elementor markup or stale content' );
	assert( ! array_filter( array_keys( get_post_meta( $fr['id'] ) ), fn( $k ) => str_starts_with( $k, '_elementor' ) ), 'no Elementor meta' );
	assert( get_post_meta( $fr['id'], '_wp_page_template', true ) === '', 'Elementor template dropped' );
	assert( isset( sm_export( $el )['meta']['_elementor_data'] ), 'toggle off keeps Elementor data' );
	// The URL-only image was found via its resized URL, uploaded, and its link points at the receiver's copy.
	$fid2 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_sm_source' AND meta_value = %s", "$fake|$img2" ) );
	assert( $fid2 && is_file( get_attached_file( $fid2 ) ), 'URL-only image uploaded' );
	assert( str_contains( $fc, home_url( '/sm-test/' ) . _wp_relative_upload_path( preg_replace( '#^.*?/uploads/#', '', $img2_medium ) ) ), 'URL-only image link rewritten to receiver' );

	// Image edited in WP on the sender (crop → foo-e1764412279817.jpg, sizes named after it), after the
	// receiver already holds the unedited copy: the re-send must swap in the edited file under the same ID.
	$img3   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM $wpdb->posts p JOIN $wpdb->postmeta m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_metadata' WHERE p.post_type = 'attachment' AND p.post_mime_type = 'image/jpeg' AND m.meta_value LIKE '%%medium%%' AND m.meta_value NOT LIKE '%%original_image%%' AND p.ID NOT IN (%d, %d) ORDER BY p.ID DESC LIMIT 1", $img, $img2 ) );
	$orig3  = get_attached_file( $img3 );
	$ep     = fn( $suffix ) => preg_replace( '/(\.\w+)$/', "-e1764412279817$suffix\$1", $orig3 );
	$p3     = wp_insert_post( [ 'post_title' => 'sm edited image test', 'post_type' => 'post', 'post_status' => 'publish', 'post_content' => '<img src="' . wp_get_attachment_url( $img3 ) . "\" class=\"wp-image-$img3\">" ] );
	$r3     = $push( $p3, false );
	$copy3  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_sm_source' AND meta_value = %s", "$fake|$img3" ) );
	$before = get_attached_file( $copy3 );
	copy( $orig3, $sender_files[] = $ep( '' ) );
	copy( $orig3, $sender_files[] = $ep( '-300x200' ) );
	update_attached_file( $img3, $ep( '' ) );
	wp_update_attachment_metadata( $img3, [ 'original_image' => basename( $orig3 ) ] + wp_get_attachment_metadata( $img3 ) );
	$edited_size_url = wp_upload_dir()['baseurl'] . '/' . _wp_relative_upload_path( $ep( '-300x200' ) );
	wp_update_post( [ 'ID' => $p3, 'post_content' => "<img src=\"$edited_size_url\">" ] ); // URL only, as pasted/converted content has
	assert( sm_export_attachment( $img3 )['rel'] === _wp_relative_upload_path( $ep( '' ) ), 'edited file is what gets sent' );
	$r3b    = $push( $p3, false );
	$after  = get_attached_file( $copy3 );
	assert( $r3b['id'] === $r3['id'] && (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_sm_source' AND meta_value = %s", "$fake|$img3" ) ) === $copy3, 'same post and attachment IDs' );
	assert( str_contains( basename( $after ), '-e1764412279817' ) && is_file( $after ) && ! is_file( $before ), 'edited file replaced the old copy, old files removed' );
	preg_match( '#/sm-test/[^"]+#', get_post_field( 'post_content', $r3b['id'] ), $link );
	assert( preg_match( '#-e1764412279817-\d+x\d+\.jpe?g$#', $link[0] ) && ! empty( wp_get_attachment_metadata( $copy3 )['sizes'] ), 'content links the edited size; receiver generated edited sizes' );
	$sizes = array_column( wp_get_attachment_metadata( $copy3 )['sizes'], 'file' );
	assert( (bool) array_filter( $sizes, fn( $f ) => str_starts_with( $f, basename( $ep( '' ), '.jpg' ) ) ), 'receiver sizes are named after the edited file' );

	echo "OK post $id → $new, $files files sent separately, warnings: " . wp_json_encode( $res1['warnings'] ) . "\n";
} finally {
	$wpdb->query( 'ROLLBACK' );
	exec( 'rm -rf ' . escapeshellarg( $tmp ) );
	array_map( 'unlink', array_filter( $sender_files, 'is_file' ) );
}
} )();
