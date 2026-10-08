<?php
/**
 * Runs when the plugin is deleted (not just deactivated): removes the
 * converted documents, their images, the plugin options and its transients.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$jimca_post_type = 'jimca_documento';

$jimca_post_ids = get_posts(
	array(
		'post_type'      => $jimca_post_type,
		'post_status'    => 'any',
		'numberposts'    => -1,
		'fields'         => 'ids',
	)
);

foreach ( $jimca_post_ids as $jimca_post_id ) {
	wp_delete_post( $jimca_post_id, true );
}

$jimca_attachment_ids = get_posts(
	array(
		'post_type'      => 'attachment',
		'post_status'    => 'any',
		'meta_key'       => '_jimca_attachment', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time cleanup on uninstall.
		'fields'         => 'ids',
		'posts_per_page' => -1,
	)
);

foreach ( $jimca_attachment_ids as $jimca_attachment_id ) {
	wp_delete_attachment( $jimca_attachment_id, true );
}

/*
 * Image folder used by older versions (see JIMCA_Image_Store::delete_dir()).
 * The plugin is not loaded here, so the hook that deletes each document's
 * images does not run: the whole folder goes at once.
 */
$jimca_uploads    = wp_upload_dir( null, false );
$jimca_images_dir = trailingslashit( $jimca_uploads['basedir'] ) . 'jimca-documentos/imagens';

if ( is_dir( $jimca_images_dir ) ) {
	$jimca_items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $jimca_images_dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $jimca_items as $jimca_item ) {
		if ( $jimca_item->isDir() && ! $jimca_item->isLink() ) {
			rmdir( $jimca_item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- one-time cleanup on uninstall, inside the plugin's own folder.
		} else {
			wp_delete_file( $jimca_item->getPathname() );
		}
	}

	rmdir( $jimca_images_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- one-time cleanup on uninstall, inside the plugin's own folder.
}

delete_option( 'jimca_settings' );
delete_option( 'jimca_ai' );
delete_option( 'jimca_tutor' );
delete_option( 'jimca_log' );
delete_option( 'jimca_jobs' );

global $wpdb;

// Transients: notices, conversion jobs, tutor question counters, install count.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time cleanup on uninstall; WordPress has no API to delete transients by prefix.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_jimca_' ) . '%'
	)
);
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time cleanup on uninstall; WordPress has no API to delete transients by prefix.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_timeout_jimca_' ) . '%'
	)
);
