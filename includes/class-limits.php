<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Limits that protect the server during a conversion.
 *
 * Images are the heaviest part of a conversion: each one is decoded, saved
 * and gets thumbnails. Without a ceiling, a PDF with hundreds of figures runs
 * PHP out of memory or time and the request dies (a blank page, or the whole
 * site on small hosting).
 *
 * The number and total size of images are settings. The time and memory
 * guards are not: they follow whatever the server's PHP allows.
 */
class JIMCA_Limits {

	/**
	 * Default number of images per document (hard ceiling:
	 * JIMCA_Image_Store::MAX_IMAGES). It can be generous because the time and
	 * memory guards (resource_stop_reason()) stop extraction before PHP hits its
	 * limits; this count is not what protects the server. For reference, a
	 * 5 MB book with 55 images peaks at about 88 MB and takes about 9 s.
	 */
	const DEFAULT_MAX_IMAGES = 100;

	/** Default total size of a document's images, in MB. */
	const DEFAULT_MAX_IMAGES_MB = 60;

	/** Defaults of earlier versions; sites still on them move to the new ones (see JIMCA_Admin::get_settings()). */
	const LEGACY_MAX_IMAGES    = 40;
	const LEGACY_MAX_IMAGES_MB = 30;

	/** Share of PHP's max_execution_time after which image extraction stops. */
	const TIME_FRACTION = 0.6;

	/** Share of PHP's memory_limit after which image extraction stops. */
	const MEMORY_FRACTION = 0.7;

	/**
	 * @return int
	 */
	public static function max_images() {
		$settings = JIMCA_Admin::get_settings();
		$value    = isset( $settings['max_images'] ) ? (int) $settings['max_images'] : self::DEFAULT_MAX_IMAGES;

		return max( 1, min( JIMCA_Image_Store::MAX_IMAGES, $value ) );
	}

	/**
	 * @return int Total bytes allowed for a document's images.
	 */
	public static function max_images_bytes() {
		$settings = JIMCA_Admin::get_settings();
		$value    = isset( $settings['max_images_mb'] ) ? (int) $settings['max_images_mb'] : self::DEFAULT_MAX_IMAGES_MB;

		return max( 1, min( 500, $value ) ) * MB_IN_BYTES;
	}

	/**
	 * Is PHP close to its time or memory limit?
	 *
	 * @return string '' (go on), 'limit_time' or 'limit_memory'.
	 */
	public static function resource_stop_reason() {
		$max_time = (int) ini_get( 'max_execution_time' );

		if ( $max_time > 0 ) {
			$start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : 0.0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- numeric value set by PHP itself, cast to float.
			if ( $start > 0 && ( microtime( true ) - $start ) > $max_time * self::TIME_FRACTION ) {
				return 'limit_time';
			}
		}

		$memory_limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );

		if ( $memory_limit > 0 && memory_get_usage( true ) > $memory_limit * self::MEMORY_FRACTION ) {
			return 'limit_memory';
		}

		return '';
	}

	/**
	 * Asks PHP for more time and memory for a conversion, when the server
	 * allows it. Servers that block these functions keep their own limits, and
	 * the guards above still apply.
	 */
	public static function raise_for_conversion() {
		wp_raise_memory_limit( 'admin' );

		if ( function_exists( 'set_time_limit' ) && 0 !== (int) ini_get( 'max_execution_time' ) ) {
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- only during a conversion started by an administrator (large books take more than 30 s); where the function is disabled it only warns and the server's limit stays.
			@set_time_limit( 300 );
		}
	}

	/**
	 * Why an image was left out, as shown in the notice after a conversion and
	 * in the document's "Conversion details" box.
	 *
	 * @return array<string, string> Reason code => translated explanation.
	 */
	public static function reason_labels() {
		return array(
			'jpx'          => __( 'JPEG 2000 (JPXDecode filter): this server cannot decode this format. It works when PHP has the Imagick extension with JPEG 2000 support; ask your host to enable it and convert the file again.', 'jim-conversor-acessivel' ),
			'ccitt'        => __( 'CCITT fax (CCITTFaxDecode filter): common in black-and-white scanned PDFs; the plugin does not decode this format.', 'jim-conversor-acessivel' ),
			'jbig2'        => __( 'JBIG2 (JBIG2Decode filter): common in scanned PDFs; the plugin does not decode this format.', 'jim-conversor-acessivel' ),
			'inline'       => __( 'Image embedded directly in the page content (BI…EI operator): the plugin only reads images that the PDF stores as separate objects.', 'jim-conversor-acessivel' ),
			'pixels'       => __( 'Unsupported pixel format: the plugin reads 8-bit RGB, gray and CMYK, 1/2/4-bit gray and palette images; other color or depth combinations are left out.', 'jim-conversor-acessivel' ),
			'pixels_max'   => __( 'Image with more than 40 million pixels: too large to convert without exhausting the server\'s memory.', 'jim-conversor-acessivel' ),
			'jpeg'         => __( 'Corrupted JPEG, or with an extra encoding the plugin could not undo.', 'jim-conversor-acessivel' ),
			'invalid'      => __( 'Invalid image file or unknown type (not JPEG, PNG, GIF or WebP, and it could not be converted).', 'jim-conversor-acessivel' ),
			'too_big'      => __( 'Image larger than 15 MB.', 'jim-conversor-acessivel' ),
			'limit_count'  => __( 'Image limit per document reached (adjustable in Settings › Image limits).', 'jim-conversor-acessivel' ),
			'limit_total'  => __( 'Total image size limit for the document reached (adjustable in Settings › Image limits).', 'jim-conversor-acessivel' ),
			'limit_time'   => __( 'PHP execution time almost used up: image extraction was stopped so the server does not go down. Raise max_execution_time or upload the PDF in parts.', 'jim-conversor-acessivel' ),
			'limit_memory' => __( 'PHP memory almost used up: image extraction was stopped so the server does not go down. Raise memory_limit or upload the PDF in parts.', 'jim-conversor-acessivel' ),
			'write'        => __( 'Failed to write to the Media Library (folder permission or no disk space).', 'jim-conversor-acessivel' ),
		);
	}

	/**
	 * @param string $code
	 * @return string
	 */
	public static function reason_label( $code ) {
		$labels = self::reason_labels();
		$detail = '';

		// Some codes carry a technical detail: "pixels:color space X, 16 bits".
		if ( false !== strpos( $code, ':' ) ) {
			list( $code, $detail ) = explode( ':', $code, 2 );
		}

		$label = isset( $labels[ $code ] ) ? $labels[ $code ] : $code;

		if ( '' !== $detail ) {
			/* translators: %s: technical detail (color space, bits and filters of the image) */
			$label .= ' ' . sprintf( __( 'Technical detail: %s.', 'jim-conversor-acessivel' ), $detail );
		}

		return $label;
	}
}
