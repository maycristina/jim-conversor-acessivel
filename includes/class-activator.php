<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JIMCA_Activator {

	public static function activate() {
		if ( class_exists( 'JIMCA_Post_Type' ) ) {
			JIMCA_Post_Type::get_instance()->register_post_type();
		}

		if ( false === get_option( 'jimca_settings' ) ) {
			add_option( 'jimca_settings', self::default_settings() );
		}

		flush_rewrite_rules();
	}

	public static function default_settings() {
		return array(
			'settings_version'       => 3,
			'allowed_types'          => array( 'pdf', 'docx', 'txt', 'md' ),
			'max_file_size_mb'       => 10,
			'delete_original_after'  => true,
			'include_images'         => true,
			'seo_enabled'            => true,
			'max_images'             => JIMCA_Limits::DEFAULT_MAX_IMAGES,
			'max_images_mb'          => JIMCA_Limits::DEFAULT_MAX_IMAGES_MB,
			'wporg_slug'             => JIMCA_PLUGIN_SLUG,
			'tts_default_rate'       => 1,
			'theme'                  => 'light',
		);
	}
}
