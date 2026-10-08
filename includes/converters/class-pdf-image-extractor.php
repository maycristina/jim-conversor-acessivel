<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Smalot\PdfParser\Page as PdfPage;
use Smalot\PdfParser\PDFObject;

/**
 * Extracts the images of a PDF page in a format browsers show (JPEG or PNG).
 *
 * In a PDF an image is an "XObject" whose pixels are compressed by one or
 * more filters. smalot/pdfparser undoes the general filters (FlateDecode,
 * LZW...) but not the image ones. Handled here:
 *
 * - DCTDecode: the bytes ARE a JPEG file; they are saved as they are.
 * - Raw pixels (after FlateDecode): a PNG is built from them (8-bit RGB, gray
 *   and CMYK; 1, 2 and 4-bit gray; palettes).
 * - JPXDecode (JPEG 2000, which almost no browser shows): converted to JPEG
 *   when PHP has the Imagick extension with JPEG 2000 support (see
 *   jpx_to_jpeg()); otherwise left out with the reason "jpx".
 *
 * Left out and counted as unsupported: fax images (CCITT/JBIG2), special
 * color spaces (Separation, DeviceN, Lab) and images inlined in the page
 * content (BI … EI).
 */
class JIMCA_Pdf_Image_Extractor {

	/**
	 * Images smaller than this on either side are almost always decoration:
	 * bullets, rules, rounded corners, spacer pixels. Without this cut, a PDF
	 * from a word processor would fill the document with empty "images".
	 */
	const MIN_SIDE = 32;

	/**
	 * Most pixels of a raw image. 40 megapixels is a professional camera
	 * photo; above that, building the PNG in memory exceeds what ordinary
	 * hosting allows.
	 */
	const MAX_PIXELS = 40000000;

	/** Color spaces whose pixels can be read directly. */
	private static $supported_color_spaces = array( 'DeviceRGB', 'DeviceGray', 'DeviceCMYK', 'CalRGB', 'CalGray', 'ICCBased' );

	/** @var array<string, bool> Images already seen on earlier pages. */
	private $seen = array();

	/** @var array<string, int> Images found but not converted, by reason (see JIMCA_Limits::reason_labels()). */
	private $skip_reasons = array();

	/** @var string Reason of the last refusal in read_pixels(), read by to_png(). */
	private $pixel_reason = '';

	/** @var \Smalot\PdfParser\Document|null To find an object's number (see raw_indexed_lookup()). */
	private $document;

	/** @var string|null Path of the PDF, read only when a palette needs it. */
	private $file_path;

	/** @var \SplObjectStorage|null Image => [number, generation] of its object. */
	private $object_numbers = null;

	/** @var string|null File bytes, read when first needed. */
	private $raw = null;

	/**
	 * @param \Smalot\PdfParser\Document|null $document
	 * @param string|null                     $file_path
	 */
	public function __construct( $document = null, $file_path = null ) {
		$this->document  = $document;
		$this->file_path = $file_path;
	}

	/** Maximum nesting of groups (Form XObjects). */
	const MAX_FORM_DEPTH = 6;

	/**
	 * @param PdfPage $page
	 * @return string[] Bytes (JPEG or PNG) of each new image on the page, in drawing order.
	 */
	public function extract( PdfPage $page ) {
		$images = array();

		foreach ( $this->drawn_images( $page ) as $xobject ) {
			/*
			 * A logo repeated on every page is the same object on all of them.
			 * It appears once, on the first page that draws it; repeating it on
			 * every page would only get in the way of reading.
			 */
			$key = md5( (string) $xobject->getContent() );
			if ( isset( $this->seen[ $key ] ) ) {
				continue;
			}
			$this->seen[ $key ] = true;

			$binary = $this->to_web_image( $xobject );

			if ( null === $binary ) {
				continue;
			}

			$images[] = $binary;
		}

		return $images;
	}

	/**
	 * Images the page actually draws, in drawing order.
	 *
	 * The page resource list (getXObjects()) does not tell: LibreOffice, Word
	 * and other generators declare ALL the document's images on every page and
	 * draw only that page's ones. So the page content is read and each
	 * "/Name Do" (draw object) command is followed, including inside groups
	 * (Form XObjects), where many generators keep images.
	 *
	 * @param PdfPage $page
	 * @return PDFObject[]
	 */
	private function drawn_images( PdfPage $page ) {
		try {
			$content  = self::page_content( $page );
			$xobjects = $page->getXObjects();
		} catch ( \Exception $e ) {
			return array();
		}

		if ( null === $content ) {
			// Unreadable content: the declared images are better than none.
			return array_values(
				array_filter(
					$xobjects,
					function ( $xobject ) {
						return self::is_image( $xobject );
					}
				)
			);
		}

		$images = array();
		$this->collect_drawn( $content, $xobjects, $images, 0, array() );

		return $images;
	}

	/**
	 * @param string $content  Drawing operators (uncompressed stream).
	 * @param array  $xobjects Name => object, from the resources in force.
	 * @param array  $images   Result, filled by reference.
	 * @param int    $depth
	 * @param array  $visited  Groups already opened on this branch (avoids cycles).
	 */
	private function collect_drawn( $content, array $xobjects, array &$images, $depth, array $visited ) {
		$this->count_inline_images( $content );

		// "/Im12 Do": object name followed by the Do operator.
		if ( ! preg_match_all( '#/([^\s/\[\]()<>{}%]+)\s+Do\b#', $content, $matches ) ) {
			return;
		}

		foreach ( $matches[1] as $name ) {
			if ( ! isset( $xobjects[ $name ] ) ) {
				continue;
			}

			$xobject = $xobjects[ $name ];

			if ( self::is_image( $xobject ) ) {
				$images[] = $xobject;
				continue;
			}

			if ( ! $xobject instanceof PDFObject || $depth >= self::MAX_FORM_DEPTH ) {
				continue;
			}

			$id = spl_object_id( $xobject );
			if ( isset( $visited[ $id ] ) ) {
				continue;
			}

			// Group (Form XObject): it has its own content and sometimes its own resources.
			$header = $xobject->getHeader();
			if ( 'Form' !== self::name_of( $header->get( 'Subtype' ) ) ) {
				continue;
			}

			$inner = self::xobjects_of( $header->get( 'Resources' ) );

			$this->collect_drawn(
				(string) $xobject->getContent(),
				null === $inner ? $xobjects : $inner,
				$images,
				$depth + 1,
				$visited + array( $id => true )
			);
		}
	}

	/**
	 * Images inlined in the content ("BI <dictionary> ID <pixels> EI"), used
	 * by some generators for small figures, are not converted. They are
	 * counted, so the notice says an image was left out instead of implying
	 * there was none. Those smaller than MIN_SIDE are decoration and do not
	 * count.
	 *
	 * @param string $content
	 */
	private function count_inline_images( $content ) {
		if ( false === strpos( $content, 'BI' ) || ! preg_match_all( '/(?<![A-Za-z])BI\s(.{0,400}?)\sID[\s]/s', $content, $matches ) ) {
			return;
		}

		foreach ( $matches[1] as $dict ) {
			$width  = preg_match( '#/(?:W|Width)\s+(\d+)#', $dict, $w ) ? (int) $w[1] : 0;
			$height = preg_match( '#/(?:H|Height)\s+(\d+)#', $dict, $h ) ? (int) $h[1] : 0;

			if ( $width >= self::MIN_SIDE && $height >= self::MIN_SIDE ) {
				$this->skip( 'inline' );
			}
		}
	}

	/**
	 * Page content: one stream, or a list of streams that form one text when
	 * joined.
	 *
	 * @param PdfPage $page
	 * @return string|null
	 */
	private static function page_content( PdfPage $page ) {
		$contents = $page->get( 'Contents' );

		if ( ! is_object( $contents ) || ! method_exists( $contents, 'getContent' ) ) {
			return null;
		}

		$value = $contents->getContent();

		if ( is_array( $value ) ) {
			$text = '';
			foreach ( $value as $section ) {
				if ( is_object( $section ) && method_exists( $section, 'getContent' ) ) {
					$text .= "\n" . $section->getContent();
				}
			}
			return $text;
		}

		return is_string( $value ) ? $value : null;
	}

	/**
	 * @param mixed $resources Resource dictionary.
	 * @return array|null Its /XObject table (name => object); null when there is none.
	 */
	private static function xobjects_of( $resources ) {
		if ( $resources instanceof PDFObject ) {
			$resources = $resources->getHeader();
		}

		if ( ! $resources instanceof \Smalot\PdfParser\Header || ! $resources->has( 'XObject' ) ) {
			return null;
		}

		$table = $resources->get( 'XObject' );
		if ( $table instanceof PDFObject ) {
			$table = $table->getHeader();
		}

		return $table instanceof \Smalot\PdfParser\Header ? $table->getElements() : null;
	}

	/**
	 * An image is any object with /Subtype /Image. /Type /XObject is optional
	 * in the specification and Ghostscript (behind many "PDF printers") does
	 * not write it; without it smalot/pdfparser does not create an
	 * XObject\Image, so checking the class alone would miss those images.
	 *
	 * @param mixed $object
	 * @return bool
	 */
	private static function is_image( $object ) {
		if ( $object instanceof \Smalot\PdfParser\XObject\Image ) {
			return true;
		}

		return $object instanceof PDFObject && 'Image' === self::name_of( $object->getHeader()->get( 'Subtype' ) );
	}

	/**
	 * @param mixed $element
	 * @return string
	 */
	private static function name_of( $element ) {
		return is_object( $element ) && method_exists( $element, 'getContent' ) ? ltrim( (string) $element->getContent(), '/' ) : '';
	}

	/** @return int */
	public function get_unsupported_count() {
		return array_sum( $this->skip_reasons );
	}

	/**
	 * @return array<string, int> Reason => number of images left out.
	 */
	public function get_skip_reasons() {
		return $this->skip_reasons;
	}

	/**
	 * @return array What must survive to the next step of a conversion done in several requests.
	 */
	public function export_state() {
		return array(
			'seen'         => $this->seen,
			'skip_reasons' => $this->skip_reasons,
		);
	}

	/** @param array $state From export_state(). */
	public function import_state( array $state ) {
		$this->seen         = isset( $state['seen'] ) ? (array) $state['seen'] : array();
		$this->skip_reasons = isset( $state['skip_reasons'] ) ? (array) $state['skip_reasons'] : array();
	}

	/** @param string $reason Reason code. */
	private function skip( $reason ) {
		$this->skip_reasons[ $reason ] = ( isset( $this->skip_reasons[ $reason ] ) ? $this->skip_reasons[ $reason ] : 0 ) + 1;
	}

	/**
	 * @param PDFObject $image
	 * @return string|null JPEG/PNG bytes, or null when the image is skipped.
	 */
	private function to_web_image( PDFObject $image ) {
		$details = $image->getDetails();
		$width   = isset( $details['Width'] ) ? (int) $details['Width'] : 0;
		$height  = isset( $details['Height'] ) ? (int) $details['Height'] : 0;

		// Masks (clipping and transparency) are not content images.
		if ( ! empty( $details['ImageMask'] ) && 'false' !== $details['ImageMask'] ) {
			return null;
		}

		if ( $width < self::MIN_SIDE || $height < self::MIN_SIDE ) {
			return null;
		}

		$filters = isset( $details['Filter'] ) ? array_values( (array) $details['Filter'] ) : array();
		$content = (string) $image->getContent();

		if ( in_array( 'DCTDecode', $filters, true ) ) {
			return $this->to_jpeg( $content, $filters );
		}

		if ( in_array( 'JPXDecode', $filters, true ) ) {
			if ( $width * $height > self::MAX_PIXELS ) {
				$this->skip( 'pixels_max' );
				return null;
			}

			$jpeg = self::jpx_to_jpeg( $content );

			if ( null === $jpeg ) {
				$this->skip( 'jpx' );
			}

			return $jpeg;
		}

		foreach ( array( 'CCITTFaxDecode' => 'ccitt', 'JBIG2Decode' => 'jbig2' ) as $unsupported_filter => $reason ) {
			if ( in_array( $unsupported_filter, $filters, true ) ) {
				$this->skip( $reason );
				return null;
			}
		}

		return $this->to_png( $image, $content, $details, $width, $height );
	}

	/**
	 * Can this server read JPEG 2000? Only with PHP's Imagick extension and an
	 * ImageMagick built with OpenJPEG (the "JP2" format).
	 *
	 * @return bool
	 */
	public static function can_decode_jpx() {
		static $can = null;

		if ( null === $can ) {
			$can = false;

			if ( class_exists( 'Imagick' ) ) {
				try {
					$can = array() !== \Imagick::queryFormats( 'JP2' );
				} catch ( \Throwable $e ) {
					$can = false;
				}
			}
		}

		return $can;
	}

	/**
	 * Converts a JPEG 2000 image to JPEG with Imagick.
	 *
	 * ImageMagick uses memory outside PHP's memory_limit, so it is capped here
	 * (256 MB of memory, 1 GB of temporary disk): a bigger image fails and is
	 * left out without bringing the site down.
	 *
	 * @param string $content Stream bytes (.jp2 file or .j2k codestream).
	 * @return string|null JPEG bytes, or null when it could not be converted.
	 */
	private static function jpx_to_jpeg( $content ) {
		if ( '' === $content || ! self::can_decode_jpx() ) {
			return null;
		}

		// A bare codestream starts with SOC+SIZ (FF 4F FF 51); anything else is the JP2 container.
		$hint = 0 === strpos( $content, "\xFF\x4F\xFF\x51" ) ? 'j2k' : 'jp2';

		try {
			\Imagick::setResourceLimit( \Imagick::RESOURCETYPE_MEMORY, 256 * MB_IN_BYTES );
			\Imagick::setResourceLimit( \Imagick::RESOURCETYPE_DISK, 1024 * MB_IN_BYTES );

			$image = new \Imagick();
			$image->readImageBlob( $content, 'image.' . $hint );
			$image->setIteratorIndex( 0 );

			if ( \Imagick::COLORSPACE_CMYK === $image->getImageColorspace() ) {
				$image->transformImageColorspace( \Imagick::COLORSPACE_SRGB );
			}

			// JPEG has no transparency: a transparent background becomes white.
			$image->setImageBackgroundColor( 'white' );
			$flat = $image->mergeImageLayers( \Imagick::LAYERMETHOD_FLATTEN );
			$image->clear();

			$flat->setImageFormat( 'jpeg' );
			$flat->setImageCompressionQuality( 85 );
			$flat->stripImage();
			$jpeg = $flat->getImageBlob();
			$flat->clear();
		} catch ( \Throwable $e ) {
			return null;
		}

		return 0 === strpos( $jpeg, "\xFF\xD8" ) ? $jpeg : null;
	}

	/**
	 * @param string   $content Stream content, possibly still ASCII85/ASCIIHex encoded.
	 * @param string[] $filters
	 * @return string|null
	 */
	private function to_jpeg( $content, array $filters ) {
		/*
		 * A JPEG wrapped in ASCII85 or ASCIIHex (common in script-generated
		 * PDFs) should be unwrapped by smalot/pdfparser, but its ASCII85
		 * decoder rejects the "z" shortcut (four zero bytes), which is valid
		 * and appears in any JPEG. So that step is redone here when the bytes
		 * do not start with the JPEG signature (FF D8).
		 */
		if ( 0 !== strpos( $content, "\xFF\xD8" ) ) {
			foreach ( $filters as $filter ) {
				if ( 'DCTDecode' === $filter ) {
					break;
				}
				if ( 'ASCII85Decode' === $filter ) {
					$content = self::decode_ascii85( $content );
				} elseif ( 'ASCIIHexDecode' === $filter ) {
					$content = self::decode_ascii_hex( $content );
				}
			}
		}

		if ( 0 !== strpos( $content, "\xFF\xD8" ) ) {
			$this->skip( 'jpeg' );
			return null;
		}

		return $content;
	}

	/**
	 * Builds a PNG from the raw pixels.
	 *
	 * Written by hand rather than with GD: GD is not always installed, and
	 * copying pixel by pixel into a GD image is slow on large images. The
	 * PDF's 8-bit RGB or gray pixels already have the layout of PNG rows.
	 *
	 * @param PDFObject $image
	 * @param string   $content Raw pixels, after FlateDecode.
	 * @param array    $details Image dictionary.
	 * @param int      $width
	 * @param int      $height
	 * @return string|null
	 */
	private function to_png( PDFObject $image, $content, array $details, $width, $height ) {
		$this->pixel_reason = '';
		$decoded            = $this->read_pixels( $content, $details, $width, $height, $image );

		if ( null === $decoded ) {
			$this->skip( '' !== $this->pixel_reason ? $this->pixel_reason : 'pixels' );
			return null;
		}

		list( $pixels, $channels ) = $decoded;

		if ( 4 === $channels ) {
			$pixels   = self::cmyk_to_rgb( $pixels );
			$channels = 3;
		}

		$alpha = $this->read_soft_mask( $image, $width, $height );

		if ( null !== $alpha ) {
			$pixels = self::add_alpha( $pixels, $alpha, $channels );
			++$channels;
		}

		return self::encode_png( $pixels, $width, $height, $channels );
	}

	/**
	 * Logos and icons with a transparent background come as two images: the
	 * colors and a grayscale "SMask" giving each pixel's opacity. Without the
	 * mask, a round logo shows as a filled rectangle.
	 *
	 * @param PDFObject $image
	 * @param int      $width
	 * @param int      $height
	 * @return string|null One opacity byte per pixel, or null when there is no usable mask.
	 */
	private function read_soft_mask( PDFObject $image, $width, $height ) {
		try {
			$mask = $image->getHeader()->get( 'SMask' );
		} catch ( \Exception $e ) {
			return null;
		}

		if ( ! self::is_image( $mask ) ) {
			return null;
		}

		$details = $mask->getDetails();

		if ( (int) ( isset( $details['Width'] ) ? $details['Width'] : 0 ) !== $width || (int) ( isset( $details['Height'] ) ? $details['Height'] : 0 ) !== $height ) {
			return null;
		}

		$decoded = $this->read_pixels( (string) $mask->getContent(), $details, $width, $height );

		return ( null !== $decoded && 1 === $decoded[1] ) ? $decoded[0] : null;
	}

	/**
	 * Reads the pixels of an uncompressed image, always as 8 bits per channel.
	 *
	 * @param string        $content Stream content, after FlateDecode.
	 * @param array         $details Image dictionary.
	 * @param int           $width
	 * @param int           $height
	 * @param PDFObject|null $image   Needed for palette images (the palette lives in the object).
	 * @return array{0: string, 1: int}|null Pixels and number of channels (1, 3 or 4).
	 */
	private function read_pixels( $content, array $details, $width, $height, $image = null ) {
		$color_space = $this->color_space_name( isset( $details['ColorSpace'] ) ? $details['ColorSpace'] : '' );
		$bits        = isset( $details['BitsPerComponent'] ) ? (int) $details['BitsPerComponent'] : 8;

		if ( $width * $height > self::MAX_PIXELS ) {
			$this->pixel_reason = 'pixels_max';
			return null;
		}

		// Image filters the library does not undo: the content is not raw pixels.
		$filters = isset( $details['Filter'] ) ? (array) $details['Filter'] : array();
		if ( array_intersect( $filters, array( 'DCTDecode', 'JPXDecode', 'CCITTFaxDecode', 'JBIG2Decode' ) ) ) {
			return null;
		}

		$params = isset( $details['DecodeParms'] ) && is_array( $details['DecodeParms'] ) ? $details['DecodeParms'] : array();

		$detail = sprintf(
			'color space %1$s, %2$d bits, filters %3$s',
			'' !== $color_space ? $color_space : '(not identified)',
			$bits,
			$filters ? implode( '+', array_map( 'strval', $filters ) ) : 'none'
		);

		if ( 'Indexed' === $color_space ) {
			$indexed = $image ? $this->read_indexed( $image, $content, $params, $width, $height, $bits ) : null;
			if ( null === $indexed && '' === $this->pixel_reason ) {
				$this->pixel_reason = 'pixels:palette image (Indexed) that could not be read, ' . $detail;
			}
			return $indexed;
		}

		if ( 8 !== $bits ) {
			// Fewer than 8 bits: gray only (scanned pages, black-and-white drawings).
			if ( ! in_array( $color_space, array( 'DeviceGray', 'CalGray' ), true ) || ! in_array( $bits, array( 1, 2, 4 ), true ) ) {
				$this->pixel_reason = 'pixels:unsupported bit depth or color, ' . $detail;
				return null;
			}

			$samples = self::unpack_samples( $content, $params, $width, $height, $bits );
			if ( null === $samples ) {
				$this->pixel_reason = 'pixels:pixel data of unexpected size, ' . $detail;
				return null;
			}

			// Scale 0..(2^bits - 1) to 0..255; /Decode [1 0] inverts (common in 1-bit scans).
			$max    = ( 1 << $bits ) - 1;
			$invert = self::decode_inverted( $details );
			$map    = array();
			for ( $v = 0; $v <= $max; $v++ ) {
				$level          = (int) round( $v * 255 / $max );
				$map[ chr( $v ) ] = chr( $invert ? 255 - $level : $level );
			}

			return array( strtr( $samples, $map ), 1 );
		}

		if ( ! in_array( $color_space, self::$supported_color_spaces, true ) ) {
			$this->pixel_reason = 'pixels:unsupported color space, ' . $detail;
			return null;
		}

		$pixels = $width * $height;

		/*
		 * The channel count comes from the data size, not the color space
		 * name: for ICCBased it lives inside the color profile, which the
		 * library does not expose reliably. With a PNG predictor, each row
		 * has one extra filter byte.
		 */
		$channels  = 0;
		$predicted = isset( $params['Predictor'] ) && (int) $params['Predictor'] >= 10;
		foreach ( array( 1, 3, 4 ) as $candidate ) {
			$expected = $predicted ? $height * ( $width * $candidate + 1 ) : $pixels * $candidate;
			if ( strlen( $content ) >= $expected && strlen( $content ) < $expected + $width * $candidate ) {
				$channels = $candidate;
				break;
			}
		}

		if ( 0 === $channels ) {
			$this->pixel_reason = 'pixels:pixel data of unexpected size (' . strlen( $content ) . ' bytes for ' . $width . 'x' . $height . '), ' . $detail;
			return null;
		}

		if ( $predicted ) {
			$content = self::undo_png_predictor( $content, $width * $channels, $height, $channels );
			if ( null === $content ) {
				$this->pixel_reason = 'pixels:invalid PNG predictor, ' . $detail;
				return null;
			}
		}

		$content = substr( $content, 0, $pixels * $channels );

		/*
		 * /Decode [1 0] inverts the values. Ghostscript writes transparency
		 * masks (SMask) this way: without inverting, a logo's transparent
		 * background turns opaque and the drawing disappears.
		 */
		if ( 1 === $channels && self::decode_inverted( $details ) ) {
			$content = strtr( $content, self::inversion_map() );
		}

		return array( $content, $channels );
	}

	/**
	 * @param array $details
	 * @return bool Whether the first channel's /Decode goes from 1 to 0.
	 */
	private static function decode_inverted( array $details ) {
		if ( ! isset( $details['Decode'] ) || ! is_array( $details['Decode'] ) || count( $details['Decode'] ) < 2 ) {
			return false;
		}

		$decode = array_values( $details['Decode'] );

		return (float) $decode[0] > (float) $decode[1];
	}

	/** @return array<string, string> Byte => 255 - byte. */
	private static function inversion_map() {
		static $map = null;

		if ( null === $map ) {
			$map = array();
			for ( $v = 0; $v < 256; $v++ ) {
				$map[ chr( $v ) ] = chr( 255 - $v );
			}
		}

		return $map;
	}

	/**
	 * Palette image: each pixel is the number of a color in the lookup table
	 * kept in the ColorSpace: [/Indexed base highest_index table].
	 *
	 * @return array{0: string, 1: int}|null
	 */
	private function read_indexed( PDFObject $image, $content, array $params, $width, $height, $bits ) {
		if ( ! in_array( $bits, array( 1, 2, 4, 8 ), true ) ) {
			return null;
		}

		/*
		 * The image's /ColorSpace is the list [/Indexed base highest_index
		 * table] written in its own dictionary, or a reference to another
		 * object holding that list. The second case is common in PDFs made by
		 * Microsoft Word: the library keeps the list items as the header
		 * elements of that object.
		 */
		$space  = $image->getHeader()->get( 'ColorSpace' );
		$holder = $image;
		$items  = array();

		if ( $space instanceof PDFObject ) {
			$holder = $space;
			$inner  = $space->getHeader()->get( 'ColorSpace' );

			if ( $inner instanceof \Smalot\PdfParser\Element\ElementArray ) {
				$items = array_values( (array) $inner->getContent() );
			} else {
				$items = array_values( $space->getHeader()->getElements() );
			}
		} elseif ( $space instanceof \Smalot\PdfParser\Element\ElementArray ) {
			$items = array_values( (array) $space->getContent() );
		} else {
			$this->pixel_reason = 'pixels:palette: the color space is not an [/Indexed …] list (' . ( is_object( $space ) ? get_class( $space ) : gettype( $space ) ) . ')';
			return null;
		}

		if ( count( $items ) < 4 ) {
			$this->pixel_reason = 'pixels:palette: incomplete Indexed color space (' . count( $items ) . ' items)';
			return null;
		}

		// Base color of the table: gray, RGB or CMYK (ICCBased gives the channel count in /N).
		$base       = $items[1];
		$components = 0;
		$base_name  = self::name_of( $base );

		if ( in_array( $base_name, array( 'DeviceGray', 'CalGray' ), true ) ) {
			$components = 1;
		} elseif ( in_array( $base_name, array( 'DeviceRGB', 'CalRGB' ), true ) ) {
			$components = 3;
		} elseif ( 'DeviceCMYK' === $base_name ) {
			$components = 4;
		} elseif ( $base instanceof \Smalot\PdfParser\Element\ElementArray ) {
			$parts = array_values( (array) $base->getContent() );
			if ( isset( $parts[1] ) && $parts[1] instanceof PDFObject ) {
				$components = (int) self::name_of( $parts[1]->getHeader()->get( 'N' ) );
			}
		} elseif ( $base instanceof PDFObject ) {
			/*
			 * The base color is a reference to another object: the ICC profile
			 * itself (with /N) or the list [/ICCBased profile] / [/CalRGB …].
			 */
			$components = (int) self::name_of( $base->getHeader()->get( 'N' ) );

			if ( ! $components ) {
				$parts = array_values( $base->getHeader()->getElements() );
				$kind  = isset( $parts[0] ) ? self::name_of( $parts[0] ) : '';

				if ( in_array( $kind, array( 'DeviceGray', 'CalGray' ), true ) ) {
					$components = 1;
				} elseif ( in_array( $kind, array( 'DeviceRGB', 'CalRGB' ), true ) ) {
					$components = 3;
				} elseif ( 'DeviceCMYK' === $kind ) {
					$components = 4;
				} elseif ( isset( $parts[1] ) && $parts[1] instanceof PDFObject ) {
					$components = (int) self::name_of( $parts[1]->getHeader()->get( 'N' ) );
				}
			}
		}

		if ( ! in_array( $components, array( 1, 3, 4 ), true ) ) {
			$this->pixel_reason = 'pixels:palette: unrecognized base color (' . ( '' !== $base_name ? $base_name : get_class( $base ) ) . ')';
			return null;
		}

		$hival  = (int) ( is_object( $items[2] ) && method_exists( $items[2], 'getContent' ) ? $items[2]->getContent() : 0 );
		$needed = ( $hival + 1 ) * $components;

		/*
		 * The table usually comes in a stream, which the library returns
		 * intact. When it is a string inside the dictionary, smalot/pdfparser
		 * treats it as text (UTF-16, HTML entities) and corrupts the bytes, so
		 * it is read straight from the file. Otherwise the colors would come
		 * out wrong, which is worse than not showing the image.
		 */
		if ( $items[3] instanceof PDFObject ) {
			$lookup = (string) $items[3]->getContent();
		} else {
			$lookup = (string) $this->raw_indexed_lookup( $holder );
		}

		if ( $hival < 0 || $hival > 255 || strlen( $lookup ) < $needed ) {
			$this->pixel_reason = 'pixels:palette: color table missing or short (' . strlen( $lookup ) . ' of ' . $needed . ' bytes, base ' . $base_name . ', table ' . ( is_object( $items[3] ) ? get_class( $items[3] ) : gettype( $items[3] ) ) . ')';
			return null;
		}

		$samples = self::unpack_samples( $content, $params, $width, $height, $bits );
		if ( null === $samples ) {
			$this->pixel_reason = 'pixels:palette: pixel data of unexpected size (' . strlen( $content ) . ' bytes for ' . $width . 'x' . $height . ' at ' . $bits . ' bits)';
			return null;
		}

		$out_channels = 1 === $components ? 1 : 3;
		$map          = array();

		for ( $i = 0; $i <= 255; $i++ ) {
			$index = min( $i, $hival );
			$entry = substr( $lookup, $index * $components, $components );
			if ( 4 === $components ) {
				$entry = self::cmyk_to_rgb( $entry );
			}
			$map[ chr( $i ) ] = $entry;
		}

		return array( strtr( $samples, $map ), $out_channels );
	}

	/**
	 * Reads, from the PDF file bytes, the color table written as a string in
	 * the image dictionary: [/Indexed base highest_index <hex>] or
	 * [/Indexed base highest_index (literal)].
	 *
	 * An image dictionary is never compressed inside an "object stream"
	 * (streams cannot be), so it is always readable in the file as
	 * "N 0 obj << ... >> stream".
	 *
	 * @param PDFObject $image Object holding the [/Indexed …]: the image or the color space object.
	 * @return string|null
	 */
	private function raw_indexed_lookup( PDFObject $image ) {
		if ( ! $this->document || ! $this->file_path ) {
			return null;
		}

		if ( null === $this->object_numbers ) {
			$this->object_numbers = new \SplObjectStorage();
			foreach ( $this->document->getObjects() as $key => $object ) {
				if ( preg_match( '/^(\d+)_(\d+)$/', (string) $key, $m ) ) {
					$this->object_numbers[ $object ] = array( (int) $m[1], (int) $m[2] );
				}
			}
		}

		if ( ! isset( $this->object_numbers[ $image ] ) ) {
			return null;
		}

		if ( null === $this->raw ) {
			$this->raw = (string) file_get_contents( $this->file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local uploaded file, read only for an inline palette.
		}

		list( $number, $generation ) = $this->object_numbers[ $image ];

		// The last definition of the object wins (incremental updates append new versions at the end).
		if ( ! preg_match_all( '/(?<![0-9])' . $number . '\s+' . $generation . '\s+obj\b/', $this->raw, $found, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$start = end( $found[0] )[1];
		$end   = strpos( $this->raw, 'stream', $start );
		$last  = strpos( $this->raw, 'endobj', $start );
		if ( false !== $last && ( false === $end || $last < $end ) ) {
			$end = $last; // Object without a stream (a color space list).
		}
		$dict = substr( $this->raw, $start, false === $end ? 4096 : min( $end - $start, 1048576 ) );

		if ( ! preg_match( '#/Indexed\s*(?:/[^\s/\[\]<(]+|\[[^\]]*\])\s*\d+\s*(<[0-9A-Fa-f\s]*>|\()#', $dict, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$token = $m[1][0];
		if ( '<' === $token[0] ) {
			$hex = preg_replace( '/[^0-9A-Fa-f]/', '', $token );
			return (string) hex2bin( strlen( $hex ) % 2 ? $hex . '0' : $hex );
		}

		return self::read_literal_string( $dict, $m[1][1] );
	}

	/**
	 * PDF literal string "( ... )" with the escapes of the specification:
	 * \n \r \t \b \f \( \) \\, octal \ddd, escaped line breaks and balanced
	 * parentheses.
	 *
	 * @param string $data
	 * @param int    $offset Position of the opening "(".
	 * @return string|null
	 */
	private static function read_literal_string( $data, $offset ) {
		$out    = '';
		$depth  = 0;
		$length = strlen( $data );
		$escape = array(
			'n' => "\n",
			'r' => "\r",
			't' => "\t",
			'b' => "\x08",
			'f' => "\x0C",
			'(' => '(',
			')' => ')',
			'\\' => '\\',
		);

		for ( $i = $offset; $i < $length; $i++ ) {
			$char = $data[ $i ];

			if ( '\\' === $char ) {
				$next = isset( $data[ $i + 1 ] ) ? $data[ $i + 1 ] : '';
				if ( isset( $escape[ $next ] ) ) {
					$out .= $escape[ $next ];
					++$i;
				} elseif ( preg_match( '/^[0-7]{1,3}/', substr( $data, $i + 1, 3 ), $octal ) ) {
					$out .= chr( octdec( $octal[0] ) & 0xFF );
					$i   += strlen( $octal[0] );
				} elseif ( "\r" === $next || "\n" === $next ) {
					$i += ( "\r" === $next && isset( $data[ $i + 2 ] ) && "\n" === $data[ $i + 2 ] ) ? 2 : 1;
				}
				continue;
			}

			if ( '(' === $char ) {
				if ( $depth++ > 0 ) {
					$out .= $char;
				}
				continue;
			}

			if ( ')' === $char ) {
				if ( --$depth === 0 ) {
					return $out;
				}
				$out .= $char;
				continue;
			}

			$out .= $char;
		}

		return null;
	}

	/**
	 * Unpacks 1, 2, 4 or 8-bit samples of one channel to one byte per sample
	 * (value 0..2^bits-1). Each row starts on a new byte, as the
	 * specification requires.
	 *
	 * @return string|null
	 */
	private static function unpack_samples( $content, array $params, $width, $height, $bits ) {
		$row_bytes = (int) ceil( $width * $bits / 8 );

		if ( isset( $params['Predictor'] ) && (int) $params['Predictor'] >= 10 ) {
			$content = self::undo_png_predictor( $content, $row_bytes, $height, 1 );
			if ( null === $content ) {
				return null;
			}
		}

		if ( strlen( $content ) < $row_bytes * $height ) {
			return null;
		}

		if ( 8 === $bits ) {
			return substr( $content, 0, $width * $height );
		}

		// Byte => the samples it holds, built once.
		$per_byte = 8 / $bits;
		$mask     = ( 1 << $bits ) - 1;
		$table    = array();
		for ( $byte = 0; $byte < 256; $byte++ ) {
			$chunk = '';
			for ( $k = $per_byte - 1; $k >= 0; $k-- ) {
				$chunk .= chr( ( $byte >> ( $k * $bits ) ) & $mask );
			}
			$table[ chr( $byte ) ] = $chunk;
		}

		$out = '';
		for ( $y = 0; $y < $height; $y++ ) {
			$row  = strtr( substr( $content, $y * $row_bytes, $row_bytes ), $table );
			$out .= substr( $row, 0, $width );
		}

		return $out;
	}

	/**
	 * Interleaves an opacity byte after each pixel.
	 *
	 * @param string $pixels
	 * @param string $alpha    One byte per pixel.
	 * @param int    $channels Color channels (1 or 3).
	 * @return string
	 */
	private static function add_alpha( $pixels, $alpha, $channels ) {
		$out   = '';
		$count = strlen( $alpha );

		for ( $i = 0; $i < $count; $i++ ) {
			$out .= substr( $pixels, $i * $channels, $channels ) . $alpha[ $i ];
		}

		return $out;
	}

	/**
	 * The ColorSpace may be a name ("DeviceRGB") or a list
	 * (["ICCBased", {profile}], ["Indexed", base, highest, palette]).
	 *
	 * @param mixed $value
	 * @return string
	 */
	private function color_space_name( $value ) {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		return is_string( $value ) ? ltrim( $value, '/' ) : '';
	}

	/**
	 * Undoes the PNG row filters (FlateDecode predictor >= 10), returning the
	 * pixels without each row's filter byte.
	 *
	 * @param string $data
	 * @param int    $row_bytes Pixel bytes per row (without the filter byte).
	 * @param int    $height
	 * @param int    $bpp       Bytes per pixel.
	 * @return string|null
	 */
	private static function undo_png_predictor( $data, $row_bytes, $height, $bpp ) {
		$out   = '';
		$prior = str_repeat( "\0", $row_bytes );

		for ( $y = 0; $y < $height; $y++ ) {
			$offset = $y * ( $row_bytes + 1 );
			$type   = ord( $data[ $offset ] );
			$row    = substr( $data, $offset + 1, $row_bytes );

			if ( strlen( $row ) !== $row_bytes ) {
				return null;
			}

			switch ( $type ) {
				case 0:
					break;
				case 1: // Sub.
					for ( $i = $bpp; $i < $row_bytes; $i++ ) {
						$row[ $i ] = chr( ( ord( $row[ $i ] ) + ord( $row[ $i - $bpp ] ) ) & 0xFF );
					}
					break;
				case 2: // Up.
					for ( $i = 0; $i < $row_bytes; $i++ ) {
						$row[ $i ] = chr( ( ord( $row[ $i ] ) + ord( $prior[ $i ] ) ) & 0xFF );
					}
					break;
				case 3: // Average.
					for ( $i = 0; $i < $row_bytes; $i++ ) {
						$left      = $i >= $bpp ? ord( $row[ $i - $bpp ] ) : 0;
						$row[ $i ] = chr( ( ord( $row[ $i ] ) + ( ( $left + ord( $prior[ $i ] ) ) >> 1 ) ) & 0xFF );
					}
					break;
				case 4: // Paeth.
					for ( $i = 0; $i < $row_bytes; $i++ ) {
						$a  = $i >= $bpp ? ord( $row[ $i - $bpp ] ) : 0;
						$b  = ord( $prior[ $i ] );
						$c  = $i >= $bpp ? ord( $prior[ $i - $bpp ] ) : 0;
						$p  = $a + $b - $c;
						$pa = abs( $p - $a );
						$pb = abs( $p - $b );
						$pc = abs( $p - $c );
						$pr = ( $pa <= $pb && $pa <= $pc ) ? $a : ( $pb <= $pc ? $b : $c );

						$row[ $i ] = chr( ( ord( $row[ $i ] ) + $pr ) & 0xFF );
					}
					break;
				default:
					return null;
			}

			$out  .= $row;
			$prior = $row;
		}

		return $out;
	}

	/**
	 * Naive CMYK to RGB conversion. Without the color profile the colors are
	 * not exactly the printed ones, but the image is recognizable, which is
	 * what matters to the reader.
	 *
	 * @param string $data
	 * @return string
	 */
	private static function cmyk_to_rgb( $data ) {
		$rgb    = '';
		$length = strlen( $data );

		for ( $i = 0; $i + 3 < $length; $i += 4 ) {
			$k    = 255 - ord( $data[ $i + 3 ] );
			$rgb .= chr( (int) ( ( 255 - ord( $data[ $i ] ) ) * $k / 255 ) )
				. chr( (int) ( ( 255 - ord( $data[ $i + 1 ] ) ) * $k / 255 ) )
				. chr( (int) ( ( 255 - ord( $data[ $i + 2 ] ) ) * $k / 255 ) );
		}

		return $rgb;
	}

	/**
	 * @param string $pixels   8-bit pixels, row by row.
	 * @param int    $width
	 * @param int    $height
	 * @param int    $channels 1 (gray), 2 (gray + alpha), 3 (RGB) or 4 (RGB + alpha).
	 * @return string PNG file.
	 */
	private static function encode_png( $pixels, $width, $height, $channels ) {
		$row_bytes = $width * $channels;
		$raw       = '';

		for ( $y = 0; $y < $height; $y++ ) {
			$raw .= "\0" . substr( $pixels, $y * $row_bytes, $row_bytes );
		}

		// PNG color types: 0 gray, 4 gray + alpha, 2 RGB, 6 RGB + alpha.
		$color_types = array(
			1 => 0,
			2 => 4,
			3 => 2,
			4 => 6,
		);
		$color_type  = $color_types[ $channels ];
		$ihdr       = pack( 'NNCCCCC', $width, $height, 8, $color_type, 0, 0, 0 );

		return "\x89PNG\r\n\x1a\n"
			. self::png_chunk( 'IHDR', $ihdr )
			. self::png_chunk( 'IDAT', gzcompress( $raw, 6 ) )
			. self::png_chunk( 'IEND', '' );
	}

	private static function png_chunk( $type, $data ) {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	}

	/**
	 * @param string $data
	 * @return string
	 */
	private static function decode_ascii85( $data ) {
		$data = preg_replace( '/\s+/', '', $data );

		if ( 0 === strpos( $data, '<~' ) ) {
			$data = substr( $data, 2 );
		}

		$end = strpos( $data, '~>' );
		if ( false !== $end ) {
			$data = substr( $data, 0, $end );
		}

		$out    = '';
		$tuple  = array();
		$length = strlen( $data );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $data[ $i ];

			if ( 'z' === $char && empty( $tuple ) ) {
				$out .= "\0\0\0\0";
				continue;
			}

			$tuple[] = ord( $char ) - 33;

			if ( 5 === count( $tuple ) ) {
				$out  .= self::ascii85_tuple( $tuple, 4 );
				$tuple = array();
			}
		}

		if ( ! empty( $tuple ) ) {
			$missing = 5 - count( $tuple );
			$tuple   = array_pad( $tuple, 5, 84 );
			$out    .= self::ascii85_tuple( $tuple, 4 - $missing );
		}

		return $out;
	}

	private static function ascii85_tuple( array $tuple, $bytes ) {
		$value = 0;
		foreach ( $tuple as $digit ) {
			$value = $value * 85 + $digit;
		}

		return substr( pack( 'N', $value & 0xFFFFFFFF ), 0, $bytes );
	}

	/**
	 * @param string $data
	 * @return string
	 */
	private static function decode_ascii_hex( $data ) {
		$data = preg_replace( '/[^0-9A-Fa-f]/', '', strstr( $data . '>', '>', true ) );

		if ( strlen( $data ) % 2 ) {
			$data .= '0';
		}

		return (string) hex2bin( $data );
	}
}
