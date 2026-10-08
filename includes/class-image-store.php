<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Saves the images of a converted document and returns the <img> markup
 * pointing to them.
 *
 * Each image becomes a Media Library attachment (thumbnails, alt text, the
 * site's own backups and CDN), linked to the document by post_parent. Files,
 * not `data:` URIs inside post_content: wp_kses_post() strips `data:` from any
 * src, and a book with dozens of figures would turn into a post_content of
 * several MB, loaded whole on every view and every saved revision.
 *
 * Attachments are deleted with the document (see
 * JIMCA_Post_Type::delete_document_images()). Documents converted by older
 * versions keep their images in uploads/jimca-documentos/imagens/{token}/,
 * removed by delete_dir().
 */
class JIMCA_Image_Store {

	/** Folder under uploads/ used by older versions for document images. */
	const BASE_SUBDIR = 'jimca-documentos/imagens';

	/**
	 * Hard ceiling of images per document, whatever the settings say. Guards
	 * against PDFs made of thousands of tiny image objects (patterns, sliced
	 * watermarks) that would become thousands of files and requests.
	 */
	const MAX_IMAGES = 300;

	/** Largest image file saved, in bytes. */
	const MAX_IMAGE_BYTES = 15 * MB_IN_BYTES;

	/**
	 * Types every browser shows. BMP and TIFF come from older Word files and
	 * are not shown everywhere: they are converted to PNG when PHP's GD can
	 * (see normalize()).
	 *
	 * @var array<int, string> IMAGETYPE_* constant => extension.
	 */
	private static $web_types = array(
		IMAGETYPE_JPEG => 'jpg',
		IMAGETYPE_PNG  => 'png',
		IMAGETYPE_GIF  => 'gif',
		IMAGETYPE_WEBP => 'webp',
	);

	/** @var array<string, string> Extension => MIME type. */
	private static $mime_types = array(
		'jpg'  => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
	);

	/** @var int[] Attachments created for this document. */
	private $attachment_ids = array();

	/** @var array<string, string> md5 of the bytes => markup already built. */
	private $saved = array();

	/** @var int Images saved. */
	private $count = 0;

	/** @var int Images saved without a description from the original file. */
	private $missing_alt = 0;

	/** @var int Images left out (unsupported type, too large, over a limit). */
	private $skipped = 0;

	/** @var array<string, int> Reason code (see JIMCA_Limits::reason_labels()) => images left out for it. */
	private $skip_reasons = array();

	/** @var int Total bytes of the images saved. */
	private $total_bytes = 0;

	/** @var array{reason: string, page: int}|null Where extraction stopped on a limit, if it did. */
	private $stopped = null;

	/**
	 * @throws JIMCA_Converter_Exception When WordPress cannot write to uploads/.
	 */
	public function __construct() {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			throw new JIMCA_Converter_Exception( esc_html( $uploads['error'] ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/**
	 * Saves an image and returns its <img>. An image repeated in the document
	 * is saved once; repeats reuse the first file and markup.
	 *
	 * @param string      $binary       Image bytes (JPEG, PNG, GIF, WebP, BMP).
	 * @param string|null $alt          Description from the original file: null = none
	 *                                  given; '' = marked as decorative there.
	 * @param string      $fallback_alt Text used when $alt is null.
	 * @return string <img> markup, or '' when the image was left out.
	 */
	public function add( $binary, $alt, $fallback_alt ) {
		$hash = md5( $binary );

		if ( isset( $this->saved[ $hash ] ) ) {
			return $this->saved[ $hash ];
		}

		$stop = $this->stop_reason();

		if ( '' !== $stop ) {
			$this->skip( $stop );
			return '';
		}

		$image = $this->normalize( $binary );

		if ( null === $image ) {
			$this->skip( 'invalid' );
			return '';
		}

		if ( strlen( $image['binary'] ) > self::MAX_IMAGE_BYTES ) {
			$this->skip( 'too_big' );
			return '';
		}

		if ( $this->total_bytes + strlen( $image['binary'] ) > JIMCA_Limits::max_images_bytes() ) {
			$this->skip( 'limit_total' );
			return '';
		}

		++$this->count;
		$filename = sprintf( 'jim-imagem-%s-%03d.%s', substr( $hash, 0, 8 ), $this->count, $image['ext'] );

		$attachment_id = $this->create_attachment( $filename, $image, $alt, $fallback_alt );

		if ( ! $attachment_id ) {
			--$this->count;
			$this->skip( 'write' );
			return '';
		}

		$this->total_bytes     += strlen( $image['binary'] );
		$this->attachment_ids[] = $attachment_id;

		$attributes = array(
			'class'   => 'jimca-image wp-image-' . $attachment_id,
			'src'     => wp_get_attachment_url( $attachment_id ),
			'width'   => $image['width'],
			'height'  => $image['height'],
			'loading' => 'lazy',
		);

		if ( null === $alt ) {
			/*
			 * The original file does not describe the image. An empty alt
			 * would hide it from screen readers as if it were decoration,
			 * which is worse than saying it exists. The fallback text locates
			 * the image, and the marker keeps the player from reading it aloud
			 * and lets the notice count the images still to be described.
			 */
			$attributes['alt']                     = $fallback_alt;
			$attributes['data-jimca-alt-missing'] = '1';
			++$this->missing_alt;
		} else {
			$attributes['alt'] = trim( preg_replace( '/\s+/u', ' ', $alt ) );
		}

		$markup = '<img';
		foreach ( $attributes as $name => $value ) {
			$markup .= ' ' . $name . '="' . esc_attr( $value ) . '"';
		}
		$markup .= '>';

		$this->saved[ $hash ] = $markup;

		return $markup;
	}

	/**
	 * Checks the real image type from the bytes (never from a file name) and
	 * converts to PNG what browsers cannot show.
	 *
	 * @param string $binary
	 * @return array{binary: string, ext: string, width: int, height: int}|null
	 */
	private function normalize( $binary ) {
		$info = @getimagesizefromstring( $binary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid bytes raise a warning; the false result is handled.

		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
			return null;
		}

		if ( isset( self::$web_types[ $info[2] ] ) ) {
			return array(
				'binary' => $binary,
				'ext'    => self::$web_types[ $info[2] ],
				'width'  => (int) $info[0],
				'height' => (int) $info[1],
			);
		}

		if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagepng' ) ) {
			return null;
		}

		$gd = @imagecreatefromstring( $binary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- formats GD cannot read raise a warning; the false result is handled.

		if ( false === $gd ) {
			return null;
		}

		ob_start();
		imagepng( $gd );
		$png = ob_get_clean();
		imagedestroy( $gd );

		return array(
			'binary' => $png,
			'ext'    => 'png',
			'width'  => (int) $info[0],
			'height' => (int) $info[1],
		);
	}

	/**
	 * Writes the file to uploads/ and registers it in the Media Library.
	 *
	 * @param string      $filename
	 * @param array       $image        See normalize().
	 * @param string|null $alt          Description from the file (null = none).
	 * @param string      $fallback_alt
	 * @return int Attachment ID, or 0 on failure.
	 */
	private function create_attachment( $filename, array $image, $alt, $fallback_alt ) {
		$upload = wp_upload_bits( $filename, null, $image['binary'] );

		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return 0;
		}

		$title = ( is_string( $alt ) && '' !== trim( $alt ) ) ? $alt : $fallback_alt;

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => self::$mime_types[ $image['ext'] ],
				'post_title'     => wp_html_excerpt( sanitize_text_field( $title ), 120 ),
				'post_status'    => 'inherit',
			),
			$upload['file'],
			0,
			true
		);

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			wp_delete_file( $upload['file'] );
			return 0;
		}

		// This mark is how the attachment is found again when its document is deleted.
		update_post_meta( $attachment_id, '_jimca_attachment', 1 );

		if ( is_string( $alt ) && '' !== trim( $alt ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', trim( preg_replace( '/\s+/u', ' ', $alt ) ) );
		}

		/*
		 * Only the "thumbnail" and "medium" sizes: each extra size is one more
		 * resize of the whole image in memory, which adds up over a book with
		 * dozens of figures. The document and the lightbox use the original.
		 */
		add_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'limit_sizes' ) );
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
		remove_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'limit_sizes' ) );

		return (int) $attachment_id;
	}

	/**
	 * @param array $sizes Sizes WordPress was about to generate.
	 * @return array Thumbnail and medium only.
	 */
	public static function limit_sizes( $sizes ) {
		return array_intersect_key( (array) $sizes, array_flip( array( 'thumbnail', 'medium' ) ) );
	}

	/**
	 * Why image extraction must stop now ('' = go on). Checked before each
	 * image is decoded, so once a limit is hit no more images are decoded,
	 * which is where the CPU and memory cost is.
	 *
	 * @return string Reason code, see JIMCA_Limits::reason_labels().
	 */
	public function stop_reason() {
		if ( $this->count >= JIMCA_Limits::max_images() || $this->count >= self::MAX_IMAGES ) {
			return 'limit_count';
		}

		if ( $this->total_bytes >= JIMCA_Limits::max_images_bytes() ) {
			return 'limit_total';
		}

		return JIMCA_Limits::resource_stop_reason();
	}

	/**
	 * Records where extraction stopped, so the notice can say from which page
	 * on images were not read.
	 *
	 * @param string $reason
	 * @param int    $page
	 */
	public function note_stopped( $reason, $page ) {
		if ( null === $this->stopped ) {
			$this->stopped = array(
				'reason' => $reason,
				'page'   => (int) $page,
			);
		}
	}

	/**
	 * @param string $reason Reason code.
	 * @param int    $count
	 */
	private function skip( $reason, $count = 1 ) {
		$this->skipped                 += $count;
		$this->skip_reasons[ $reason ]  = ( isset( $this->skip_reasons[ $reason ] ) ? $this->skip_reasons[ $reason ] : 0 ) + $count;
	}

	/**
	 * Links the attachments to the document (post_parent), which only exists
	 * once the conversion is over.
	 *
	 * @param int $post_id
	 */
	public function attach_to( $post_id ) {
		foreach ( $this->attachment_ids as $attachment_id ) {
			wp_update_post(
				array(
					'ID'          => $attachment_id,
					'post_parent' => (int) $post_id,
				)
			);
		}
	}

	/**
	 * Deletes a document's attachments with their files and thumbnails.
	 *
	 * @param int $post_id
	 */
	public static function delete_attachments( $post_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'post_parent'    => (int) $post_id,
				'meta_key'       => '_jimca_attachment', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only this plugin's attachments of one document.
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);

		foreach ( $ids as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/** @return int */
	public function get_count() {
		return $this->count;
	}

	/** @return int */
	public function get_missing_alt_count() {
		return $this->missing_alt;
	}

	/**
	 * Counts images the converter found but could not extract, by reason,
	 * for the notice.
	 *
	 * @param array<string, int> $reasons Reason code => count.
	 */
	public function add_skipped( array $reasons ) {
		foreach ( $reasons as $reason => $count ) {
			$this->skip( (string) $reason, max( 0, (int) $count ) );
		}
	}

	/**
	 * Image report of the document, stored in post meta and shown in the
	 * notice and the "Conversion details" box.
	 *
	 * @return array{included: int, missing_alt: int, skipped: int, reasons: array<string,int>, stopped: array|null, limits: array}
	 */
	public function get_report() {
		return array(
			'included'    => $this->count,
			'missing_alt' => $this->missing_alt,
			'skipped'     => $this->skipped,
			'reasons'     => $this->skip_reasons,
			'stopped'     => $this->stopped,
			'limits'      => array(
				'max_images' => JIMCA_Limits::max_images(),
				'max_mb'     => (int) round( JIMCA_Limits::max_images_bytes() / MB_IN_BYTES ),
			),
		);
	}

	/** @return int */
	public function get_skipped_count() {
		return $this->skipped;
	}

	/**
	 * Escaped HTML of a document's image report. Each cause of images left
	 * out is spelled out, so the administrator knows what happened and what
	 * to do about it.
	 *
	 * @param mixed $report get_report() result stored in post meta.
	 * @param bool  $limits Include the limits in force at conversion time.
	 * @return string
	 */
	public static function render_report( $report, $limits = false ) {
		if ( ! is_array( $report ) || ( empty( $report['included'] ) && empty( $report['skipped'] ) && empty( $report['stopped'] ) ) ) {
			return '';
		}

		$html = '<ul class="jimca-image-report">';

		if ( ! empty( $report['included'] ) ) {
			$html .= '<li>' . sprintf(
				/* translators: %s: number of images */
				esc_html( _n( '%s image included.', '%s images included.', (int) $report['included'], 'jim-conversor-acessivel' ) ),
				esc_html( number_format_i18n( (int) $report['included'] ) )
			) . '</li>';
		}

		if ( ! empty( $report['missing_alt'] ) ) {
			$html .= '<li><strong>' . sprintf(
				/* translators: %s: number of images without a description */
				esc_html( _n( '%s image has no description: edit the document and describe it, so that screen reader users know what it shows.', '%s images have no description: edit the document and describe them, so that screen reader users know what they show.', (int) $report['missing_alt'], 'jim-conversor-acessivel' ) ),
				esc_html( number_format_i18n( (int) $report['missing_alt'] ) )
			) . '</strong></li>';
		}

		if ( ! empty( $report['stopped'] ) && is_array( $report['stopped'] ) ) {
			$html .= '<li><strong>' . sprintf(
				/* translators: 1: page number, 2: reason */
				esc_html__( 'Image reading stopped at page %1$d. Reason: %2$s', 'jim-conversor-acessivel' ),
				(int) $report['stopped']['page'],
				esc_html( JIMCA_Limits::reason_label( (string) $report['stopped']['reason'] ) )
			) . '</strong> ' . esc_html__( 'The whole text was converted; only the images from the following pages were left out.', 'jim-conversor-acessivel' ) . '</li>';
		}

		if ( ! empty( $report['reasons'] ) && is_array( $report['reasons'] ) ) {
			$html .= '<li>' . esc_html__( 'Images that could not be included, and the cause for each group:', 'jim-conversor-acessivel' ) . '<ul>';

			foreach ( $report['reasons'] as $code => $count ) {
				$html .= '<li>' . sprintf(
					/* translators: 1: number of images, 2: explanation of the reason */
					esc_html__( '%1$s: %2$s', 'jim-conversor-acessivel' ),
					'<strong>' . esc_html( number_format_i18n( (int) $count ) ) . '</strong>',
					esc_html( JIMCA_Limits::reason_label( (string) $code ) )
				) . '</li>';
			}

			$html .= '</ul></li>';
		}

		if ( $limits && ! empty( $report['limits'] ) ) {
			$html .= '<li>' . sprintf(
				/* translators: 1: maximum number of images, 2: maximum total size in MB */
				esc_html__( 'Limits used in this conversion: up to %1$d images and %2$d MB in total.', 'jim-conversor-acessivel' ),
				(int) $report['limits']['max_images'],
				(int) $report['limits']['max_mb']
			) . '</li>';
		}

		return $html . '</ul>';
	}

	/**
	 * Deletes everything saved so far, for a conversion that failed after
	 * some images were already extracted.
	 */
	public function discard() {
		foreach ( $this->attachment_ids as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
		$this->attachment_ids = array();
		$this->skip_reasons   = array();
		$this->total_bytes    = 0;
		$this->stopped        = null;
		$this->saved       = array();
		$this->count       = 0;
		$this->missing_alt = 0;
	}

	/**
	 * @return array What must survive to the next step of a conversion done in
	 *               several requests (see JIMCA_Conversion_Job).
	 */
	public function export_state() {
		return array(
			'attachment_ids' => $this->attachment_ids,
			'saved'          => $this->saved,
			'count'          => $this->count,
			'missing_alt'    => $this->missing_alt,
			'skipped'        => $this->skipped,
			'skip_reasons'   => $this->skip_reasons,
			'total_bytes'    => $this->total_bytes,
			'stopped'        => $this->stopped,
		);
	}

	/** @param array $state From export_state(). */
	public function import_state( array $state ) {
		foreach ( array_keys( $this->export_state() ) as $key ) {
			if ( array_key_exists( $key, $state ) ) {
				$this->$key = $state[ $key ];
			}
		}
	}

	/**
	 * Deletes the image folder of a document converted by an older version.
	 * The path comes from post meta, so it must be exactly one folder inside
	 * uploads/jimca-documentos/imagens/ before anything is deleted.
	 *
	 * @param string $relative_dir Path relative to uploads/.
	 */
	public static function delete_dir( $relative_dir ) {
		if ( ! is_string( $relative_dir ) || ! preg_match( '#^' . preg_quote( self::BASE_SUBDIR, '#' ) . '/[a-z0-9]+$#', $relative_dir ) ) {
			return;
		}

		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . $relative_dir;

		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}

		// Already emptied above. When WordPress cannot remove it (file method other than "direct"), an empty folder is left, which is harmless.
		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			$wp_filesystem->rmdir( $dir );
		}
	}
}
