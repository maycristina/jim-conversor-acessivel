<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the alt text the author wrote for each image of a .docx (Word: right
 * click the image > "Edit Alt Text").
 *
 * phpword loads the images but drops this description, and its HTML does not
 * say which internal file each <img> came from. The link is the content: the
 * description is indexed by the md5 of the image bytes, the same bytes phpword
 * embeds in the HTML.
 */
class JIMCA_Docx_Image_Descriptions {

	const NS_WP   = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
	const NS_A    = 'http://schemas.openxmlformats.org/drawingml/2006/main';
	const NS_R    = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	const NS_V    = 'urn:schemas-microsoft-com:vml';
	const NS_O    = 'urn:schemas-microsoft-com:office:office';
	const NS_ADEC = 'http://schemas.microsoft.com/office/drawing/2017/decorative';

	/**
	 * @param string $file_path Path of the .docx.
	 * @return array<string, string> md5 of the image bytes => description;
	 *                               '' = marked as decorative.
	 */
	public static function read( $file_path ) {
		$descriptions = array();

		if ( ! class_exists( 'ZipArchive' ) ) {
			return $descriptions;
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $file_path ) ) {
			return $descriptions;
		}

		$document = $zip->getFromName( 'word/document.xml' );
		$rels     = $zip->getFromName( 'word/_rels/document.xml.rels' );

		if ( false === $document || false === $rels ) {
			$zip->close();
			return $descriptions;
		}

		$targets = self::read_relationships( $rels );

		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// LIBXML_NONET: the XML comes from an uploaded file; never fetch anything from the network because of it.
		$loaded = $dom->loadXML( $document, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			$zip->close();
			return $descriptions;
		}

		$xpath = new \DOMXPath( $dom );
		$xpath->registerNamespace( 'wp', self::NS_WP );
		$xpath->registerNamespace( 'a', self::NS_A );
		$xpath->registerNamespace( 'r', self::NS_R );
		$xpath->registerNamespace( 'v', self::NS_V );
		$xpath->registerNamespace( 'o', self::NS_O );
		$xpath->registerNamespace( 'adec', self::NS_ADEC );

		// Current format (DrawingML): <wp:inline> or <wp:anchor>.
		foreach ( $xpath->query( '//wp:inline | //wp:anchor' ) as $drawing ) {
			$doc_pr = $xpath->query( 'wp:docPr', $drawing )->item( 0 );
			$blip   = $xpath->query( './/a:blip', $drawing )->item( 0 );

			if ( ! $doc_pr instanceof \DOMElement || ! $blip instanceof \DOMElement ) {
				continue;
			}

			$embed = $blip->getAttributeNS( self::NS_R, 'embed' );

			if ( $xpath->query( './/adec:decorative[@val="1" or @val="true"]', $doc_pr )->length > 0 ) {
				$description = '';
			} else {
				$description = trim( $doc_pr->getAttribute( 'descr' ) );
				if ( '' === $description ) {
					$description = trim( $doc_pr->getAttribute( 'title' ) );
				}
				if ( '' === $description ) {
					// No description: record nothing, so the image gets the fallback text.
					continue;
				}
			}

			self::remember( $descriptions, $zip, $targets, $embed, $description );
		}

		// Older format (VML), from Word 2003/2007 documents.
		foreach ( $xpath->query( '//v:shape[v:imagedata]' ) as $shape ) {
			$imagedata   = $xpath->query( 'v:imagedata', $shape )->item( 0 );
			$description = trim( $shape->getAttribute( 'alt' ) );

			if ( '' === $description && $imagedata instanceof \DOMElement ) {
				$description = trim( $imagedata->getAttributeNS( self::NS_O, 'title' ) );
			}

			if ( '' === $description || ! $imagedata instanceof \DOMElement ) {
				continue;
			}

			self::remember( $descriptions, $zip, $targets, $imagedata->getAttributeNS( self::NS_R, 'id' ), $description );
		}

		$zip->close();

		return $descriptions;
	}

	/**
	 * @param array<string, string> $descriptions
	 * @param \ZipArchive           $zip
	 * @param array<string, string> $targets
	 * @param string                $relationship_id
	 * @param string                $description
	 */
	private static function remember( array &$descriptions, \ZipArchive $zip, array $targets, $relationship_id, $description ) {
		if ( '' === $relationship_id || ! isset( $targets[ $relationship_id ] ) ) {
			return;
		}

		$bytes = $zip->getFromName( $targets[ $relationship_id ] );

		if ( false === $bytes ) {
			return;
		}

		$hash = md5( $bytes );

		// The same image used twice: the first non-empty description wins.
		if ( ! isset( $descriptions[ $hash ] ) || '' === $descriptions[ $hash ] ) {
			$descriptions[ $hash ] = $description;
		}
	}

	/**
	 * @param string $xml Content of word/_rels/document.xml.rels.
	 * @return array<string, string> Relationship id => path inside the zip.
	 */
	private static function read_relationships( $xml ) {
		$targets  = array();
		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $dom->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return $targets;
		}

		foreach ( $dom->getElementsByTagName( 'Relationship' ) as $relationship ) {
			if ( 'External' === $relationship->getAttribute( 'TargetMode' ) ) {
				continue;
			}

			$target = $relationship->getAttribute( 'Target' );
			// Targets are relative to word/ ("media/image1.png") or absolute ("/word/media/...").
			$target = ( 0 === strpos( $target, '/' ) ) ? ltrim( $target, '/' ) : 'word/' . $target;

			$targets[ $relationship->getAttribute( 'Id' ) ] = $target;
		}

		return $targets;
	}
}
