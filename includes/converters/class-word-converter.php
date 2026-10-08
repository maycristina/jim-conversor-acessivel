<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PhpOffice\PhpWord\IOFactory;

class JIMCA_Word_Converter implements JIMCA_Converter_Interface {

	/** @var JIMCA_Image_Store|null null when images are turned off in Settings. */
	private $image_store;

	/** @var array<string, string> md5 of the image => description (see JIMCA_Docx_Image_Descriptions). */
	private $descriptions = array();

	/** @var int Numbers the fallback text of images without a description. */
	private $image_number = 0;

	/**
	 * @param JIMCA_Image_Store|null $image_store
	 */
	public function __construct( $image_store = null ) {
		$this->image_store = $image_store;
	}

	public function convert( $file_path ) {
		if ( ! class_exists( IOFactory::class ) ) {
			throw new JIMCA_Converter_Exception(
				esc_html__( 'The phpoffice/phpword library was not found. Run "composer install" in the plugin folder.', 'jim-conversor-acessivel' )
			);
		}

		// The fixed copy can only go after save(): phpword reads the images from the .docx ("zip://") at that point.
		$fixed_path = $this->fix_package( $file_path );
		$tmp_html   = wp_tempnam( 'jimca-word-' );

		/*
		 * phpword still raises PHP 8 deprecation notices (e.g. null passed to
		 * htmlspecialchars() for an empty heading). They do not change the
		 * result, but with WP_DEBUG on, the printed notice breaks the redirect
		 * after the conversion. Only notices from vendor/ are silenced, and
		 * only while the file is read.
		 */
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- restored in the finally block below.
			static function ( $errno, $errstr, $errfile ) {
				return false !== strpos( wp_normalize_path( (string) $errfile ), '/vendor/' );
			},
			E_DEPRECATED | E_USER_DEPRECATED
		);

		try {
			try {
				$phpWord = IOFactory::load( null !== $fixed_path ? $fixed_path : $file_path, 'Word2007' );
				$writer  = IOFactory::createWriter( $phpWord, 'HTML' );
			} catch ( \Exception $e ) {
				throw new JIMCA_Converter_Exception(
					sprintf(
						/* translators: %s: original error message */
						esc_html__( 'Could not read the Word document: %s', 'jim-conversor-acessivel' ),
						esc_html( $e->getMessage() )
					)
				);
			}

			$writer->save( $tmp_html );
			$full_html = file_get_contents( $tmp_html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temporary file.
		} finally {
			restore_error_handler();

			foreach ( array( $tmp_html, $fixed_path ) as $tmp ) {
				if ( null !== $tmp && file_exists( $tmp ) ) {
					wp_delete_file( $tmp );
				}
			}
		}

		if ( false === $full_html || '' === trim( (string) $full_html ) ) {
			throw new JIMCA_Converter_Exception( esc_html__( 'The Word document seems to be empty.', 'jim-conversor-acessivel' ) );
		}

		if ( $this->image_store ) {
			$this->descriptions = JIMCA_Docx_Image_Descriptions::read( $file_path );
		}

		return $this->extract_body( $full_html );
	}

	/**
	 * Fixes, in a temporary copy of the .docx, markup that phpword misreads:
	 * `<w:tblHeader w:val="0"/>` marks a row that is NOT a header (common in
	 * Google Docs exports), but phpword only checks that the tag exists, so
	 * every cell would become a <th> announced as a header by screen readers.
	 *
	 * @param string $file_path
	 * @return string|null Path of the fixed copy, or null when nothing needs fixing.
	 */
	private function fix_package( $file_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return null;
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $file_path ) ) {
			return null;
		}

		$xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();

		if ( false === $xml ) {
			return null;
		}

		$fixed = preg_replace( '#<w:tblHeader\s+w:val="(?:0|false|off)"\s*/>#i', '', $xml );

		if ( null === $fixed || $fixed === $xml ) {
			return null;
		}

		$copy = wp_tempnam( 'jimca-docx-' );

		if ( ! $copy || ! copy( $file_path, $copy ) ) {
			return null;
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $copy ) || ! $zip->addFromString( 'word/document.xml', $fixed ) ) {
			wp_delete_file( $copy );
			return null;
		}

		$zip->close();

		return $copy;
	}

	/**
	 * Removes the cell colors phpword writes. Word's "auto" shading becomes
	 * `bgcolor="#auto"`, which browsers read as dark red (#a00000), and any
	 * fixed cell color clashes with the reading themes (dark, sepia, high
	 * contrast). Cells follow the theme instead.
	 *
	 * Header cells get `scope="col"` so screen readers tie each cell to its
	 * column.
	 *
	 * @param \DOMDocument $dom
	 */
	private function clean_table_cells( \DOMDocument $dom ) {
		foreach ( array( 'td', 'th' ) as $tag ) {
			foreach ( $dom->getElementsByTagName( $tag ) as $cell ) {
				$cell->removeAttribute( 'bgcolor' );
				$cell->removeAttribute( 'color' );

				if ( 'th' === $tag && ! $cell->hasAttribute( 'scope' ) ) {
					$cell->setAttribute( 'scope', 'col' );
				}
			}
		}
	}

	/**
	 * phpword writes a whole HTML page; only the <body> content goes to post_content.
	 */
	private function extract_body( $full_html ) {
		$dom = new \DOMDocument();

		$previous_setting = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $full_html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_setting );

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );

		if ( null === $body ) {
			return wp_strip_all_tags( $full_html );
		}

		$this->replace_images( $dom );
		$this->clean_table_cells( $dom );

		$inner_html = '';
		foreach ( $body->childNodes as $child ) {
			$inner_html .= $dom->saveHTML( $child );
		}

		return $inner_html;
	}

	/**
	 * phpword embeds each image as a base64 `data:` URI, which wp_kses_post()
	 * strips, leaving an empty <img>. Each image is saved as a file instead
	 * and the <img> points to it, with the alt text written in Word.
	 *
	 * @param \DOMDocument $dom
	 */
	private function replace_images( \DOMDocument $dom ) {
		// Copied first: the live NodeList would skip items as <img> elements are removed.
		$images = iterator_to_array( $dom->getElementsByTagName( 'img' ) );

		foreach ( $images as $img ) {
			$markup = '';
			$src    = $img->getAttribute( 'src' );

			if ( $this->image_store && preg_match( '#^data:image/[a-z0-9.+-]+;base64,(.+)$#is', $src, $matches ) ) {
				$binary = base64_decode( $matches[1], true );

				if ( false !== $binary ) {
					$hash = md5( $binary );
					++$this->image_number;

					$markup = $this->image_store->add(
						$binary,
						isset( $this->descriptions[ $hash ] ) ? $this->descriptions[ $hash ] : null,
						sprintf(
							/* translators: %d: number of the image in the document */
							__( 'Image %d of the document (no description)', 'jim-conversor-acessivel' ),
							$this->image_number
						)
					);
				}
			}

			$new_img = '' === $markup ? null : $this->import_img( $dom, $markup );

			if ( null === $new_img ) {
				$img->parentNode->removeChild( $img );
				continue;
			}

			$img->parentNode->replaceChild( $new_img, $img );
		}
	}

	/**
	 * Turns the markup returned by JIMCA_Image_Store into a node of this document.
	 *
	 * @param \DOMDocument $dom
	 * @param string       $markup
	 * @return \DOMNode|null
	 */
	private function import_img( \DOMDocument $dom, $markup ) {
		$tmp      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$tmp->loadHTML( '<?xml encoding="utf-8" ?>' . $markup );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$node = $tmp->getElementsByTagName( 'img' )->item( 0 );

		return $node ? $dom->importNode( $node, true ) : null;
	}
}
