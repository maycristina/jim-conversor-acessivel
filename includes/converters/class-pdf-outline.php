<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Smalot\PdfParser\Document as PdfDocument;
use Smalot\PdfParser\Element\ElementArray;
use Smalot\PdfParser\Element\ElementMissing;
use Smalot\PdfParser\Header as PdfHeader;
use Smalot\PdfParser\Page as PdfPage;
use Smalot\PdfParser\PDFObject;

/**
 * Reads the PDF outline (the "bookmarks" PDF viewers show in the sidebar)
 * and the page where each entry starts.
 *
 * The headings of a converted PDF come from here: a PDF does not mark
 * headings in its text, it only places bigger or bold letters. The outline is
 * the only chapter structure the author left in it.
 *
 * smalot/pdfparser does not expose the outline, so it is walked here through
 * the PDF objects: Catalog > Outlines > First/Next (siblings) and First
 * (children), each item with a Title and a destination (Dest or A/D).
 */
class JIMCA_Pdf_Outline {

	/** Most entries read: guards against corrupted or huge outlines. */
	const MAX_ENTRIES = 2000;

	/** Deeper levels are treated as this one (h2 + 4 = h6). */
	const MAX_DEPTH = 4;

	/** @var PdfDocument */
	private $pdf;

	/** @var \SplObjectStorage Page => index (0, 1, ...). */
	private $page_index;

	/** @var array<string, mixed>|null Named destinations, read when first needed. */
	private $named = null;

	/** @var int */
	private $read = 0;

	/**
	 * @param PdfDocument $pdf
	 * @param PdfPage[]   $pages
	 * @return array<int, array<int, array{level: int, title: string}>>
	 *         Page index => entries starting on it, in order.
	 */
	public static function read( PdfDocument $pdf, array $pages ) {
		$reader = new self( $pdf, $pages );

		try {
			return $reader->collect();
		} catch ( \Exception $e ) {
			// A malformed outline does not stop the conversion: the text just has no headings.
			return array();
		}
	}

	private function __construct( PdfDocument $pdf, array $pages ) {
		$this->pdf        = $pdf;
		$this->page_index = new \SplObjectStorage();

		foreach ( array_values( $pages ) as $index => $page ) {
			$this->page_index[ $page ] = $index;
		}
	}

	private function collect() {
		$catalog = $this->catalog();
		if ( ! $catalog ) {
			return array();
		}

		$outlines = self::header_of( $catalog->getHeader()->get( 'Outlines' ) );
		if ( ! $outlines ) {
			return array();
		}

		$entries = array();
		$this->walk( $outlines->get( 'First' ), 0, $entries, array() );

		return $entries;
	}

	/**
	 * @param mixed $item    First item of an outline level.
	 * @param int   $level
	 * @param array $entries Result, filled by reference.
	 * @param array $visited Items already seen on this branch (avoids cycles).
	 */
	private function walk( $item, $level, array &$entries, array $visited ) {
		while ( $item instanceof PDFObject && $this->read < self::MAX_ENTRIES ) {
			$id = spl_object_id( $item );
			if ( isset( $visited[ $id ] ) ) {
				return;
			}
			$visited[ $id ] = true;
			++$this->read;

			$header = $item->getHeader();
			$title  = self::decode_text( $header->get( 'Title' ) );
			$page   = $this->destination_page( $header->get( 'Dest' ) );

			if ( null === $page ) {
				// No Dest: the destination is in a "go to" action (/A << /S /GoTo /D ... >>).
				$action = self::header_of( $header->get( 'A' ) );
				if ( $action ) {
					$page = $this->destination_page( $action->get( 'D' ) );
				}
			}

			if ( '' !== $title && null !== $page ) {
				$entries[ $page ][] = array(
					'level' => min( $level, self::MAX_DEPTH ),
					'title' => $title,
				);
			}

			$this->walk( $header->get( 'First' ), $level + 1, $entries, $visited );

			$item = $header->get( 'Next' );
		}
	}

	/**
	 * @param mixed $dest Array [page, /XYZ ...], destination name or dictionary with /D.
	 * @return int|null Page index.
	 */
	private function destination_page( $dest, $depth = 0 ) {
		if ( $depth > 3 || null === $dest || $dest instanceof ElementMissing ) {
			return null;
		}

		if ( $dest instanceof ElementArray ) {
			$items = $dest->getContent();
			$first = is_array( $items ) ? reset( $items ) : null;

			if ( $first instanceof PdfPage && isset( $this->page_index[ $first ] ) ) {
				return $this->page_index[ $first ];
			}

			return null;
		}

		$dictionary = self::header_of( $dest );
		if ( $dictionary ) {
			// Destination inside a dictionary with /D.
			return $this->destination_page( $dictionary->get( 'D' ), $depth + 1 );
		}

		// Named destination: look the name up in the PDF's destination table.
		$name = is_object( $dest ) && method_exists( $dest, 'getContent' ) ? (string) $dest->getContent() : '';

		if ( '' === $name ) {
			return null;
		}

		$named = $this->named_destinations();

		return isset( $named[ $name ] ) ? $this->destination_page( $named[ $name ], $depth + 1 ) : null;
	}

	/**
	 * Named destinations come from the Catalog's /Dests dictionary (PDF 1.1)
	 * or the /Names > /Dests tree (PDF 1.2+).
	 *
	 * @return array<string, mixed>
	 */
	private function named_destinations() {
		if ( null !== $this->named ) {
			return $this->named;
		}

		$this->named = array();
		$catalog     = $this->catalog();

		if ( ! $catalog ) {
			return $this->named;
		}

		$dests = self::header_of( $catalog->getHeader()->get( 'Dests' ) );
		if ( $dests ) {
			foreach ( $dests->getElements() as $name => $value ) {
				$this->named[ (string) $name ] = $value;
			}
		}

		$names = self::header_of( $catalog->getHeader()->get( 'Names' ) );
		if ( $names ) {
			$this->read_name_tree( $names->get( 'Dests' ), 0 );
		}

		return $this->named;
	}

	/**
	 * @param mixed $node
	 * @param int   $depth
	 */
	private function read_name_tree( $node, $depth ) {
		$header = self::header_of( $node );

		if ( ! $header || $depth > 20 || count( $this->named ) > 20000 ) {
			return;
		}

		$pairs  = $header->get( 'Names' );

		if ( $pairs instanceof ElementArray ) {
			$items = array_values( (array) $pairs->getContent() );
			$count = count( $items );

			for ( $i = 0; $i + 1 < $count; $i += 2 ) {
				$key = is_object( $items[ $i ] ) && method_exists( $items[ $i ], 'getContent' ) ? (string) $items[ $i ]->getContent() : '';
				if ( '' !== $key ) {
					$this->named[ $key ] = $items[ $i + 1 ];
				}
			}
		}

		$kids = $header->get( 'Kids' );
		if ( $kids instanceof ElementArray ) {
			foreach ( (array) $kids->getContent() as $kid ) {
				$this->read_name_tree( $kid, $depth + 1 );
			}
		}
	}

	/**
	 * The library hands a PDF dictionary over either as a separate object
	 * (reference "12 0 R") or inlined in its parent (<< ... >>).
	 *
	 * @param mixed $value
	 * @return PdfHeader|null
	 */
	private static function header_of( $value ) {
		if ( $value instanceof PDFObject ) {
			return $value->getHeader();
		}

		return $value instanceof PdfHeader ? $value : null;
	}

	/** @return PDFObject|null */
	private function catalog() {
		$catalogs = $this->pdf->getObjectsByType( 'Catalog' );
		$catalog  = is_array( $catalogs ) ? reset( $catalogs ) : null;

		return $catalog instanceof PDFObject ? $catalog : null;
	}

	/**
	 * PDF strings are UTF-16BE (with an FE FF mark) or PDFDocEncoding, which
	 * is close to Windows-1252.
	 *
	 * @param mixed $element
	 * @return string UTF-8 text with collapsed spaces.
	 */
	private static function decode_text( $element ) {
		if ( ! is_object( $element ) || ! method_exists( $element, 'getContent' ) ) {
			return '';
		}

		$raw = (string) $element->getContent();

		if ( 0 === strpos( $raw, "\xFE\xFF" ) ) {
			$text = mb_convert_encoding( substr( $raw, 2 ), 'UTF-8', 'UTF-16BE' );
		} elseif ( 0 === strpos( $raw, "\xEF\xBB\xBF" ) ) {
			$text = substr( $raw, 3 ); // PDF 2.0 allows UTF-8 with a BOM.
		} elseif ( mb_check_encoding( $raw, 'UTF-8' ) ) {
			$text = $raw;
		} else {
			$text = mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' );
		}

		// Some generators leave control characters (\0, \r) in titles.
		$text = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $text );

		return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	}
}
