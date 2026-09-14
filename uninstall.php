<?php
/**
 * Executado quando o plugin é removido (não apenas desativado) pelo admin do WordPress.
 * Remove os documentos convertidos, seus metadados e as opções do plugin.
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

delete_option( 'jimca_settings' );

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- limpeza única na desinstalação; não há API dedicada para apagar transients por prefixo, e cache de objeto não se aplica aqui (o plugin está sendo removido).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_jimca_active_installs_' ) . '%'
	)
);
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- limpeza única na desinstalação; não há API dedicada para apagar transients por prefixo, e cache de objeto não se aplica aqui (o plugin está sendo removido).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_timeout_jimca_active_installs_' ) . '%'
	)
);
