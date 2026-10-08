<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activity log for conversions and AI calls, shown in Settings.
 *
 * What it records: file name and type, size, time, peak memory, word and image
 * counts, the cause of each image left out, errors (including PHP running out
 * of memory or time during a conversion), AI connection tests, tutor errors and
 * the tutor's daily limit. What it never records: the text of the documents,
 * the AI keys, or what readers ask the tutor.
 *
 * Storage: one option (no autoload) holding the last MAX_ENTRIES lines. Older
 * lines are dropped as new ones arrive, so the log can never grow without limit.
 */
class JIMCA_Log {

	const OPTION        = 'jimca_log';
	const MAX_ENTRIES   = 200;
	const MAX_MESSAGE   = 400;
	const MAX_CONTEXT   = 200;
	const AJAX_FETCH    = 'jimca_log_fetch';
	const AJAX_CLEAR    = 'jimca_log_clear';
	const EXPORT_ACTION = 'jimca_log_export';
	const NONCE         = 'jimca_log';

	/** Context keys that are never stored, whatever their value. */
	const FORBIDDEN_KEYS = '/key|token|secret|password|authorization|content|text|html/i';

	/**
	 * @return string[] Valid levels.
	 */
	public static function levels() {
		return array( 'info', 'warning', 'error' );
	}

	/**
	 * Adds one line to the log.
	 *
	 * @param string               $level   info|warning|error.
	 * @param string               $event   Short machine-friendly name, e.g. "upload.done".
	 * @param string               $message Human-readable line (no HTML).
	 * @param array<string, mixed> $context Small scalar details (file, ms, MB…).
	 */
	public static function add( $level, $event, $message, array $context = array() ) {
		$level   = in_array( $level, self::levels(), true ) ? $level : 'info';
		$entries = self::all();
		$last    = end( $entries );
		$id      = $last ? (int) $last['id'] + 1 : 1;

		$entries[] = array(
			'id'      => $id,
			'time'    => gmdate( 'Y-m-d H:i:s' ),
			'level'   => $level,
			'event'   => sanitize_key( str_replace( '.', '_', (string) $event ) ),
			'message' => self::clean( $message, self::MAX_MESSAGE ),
			'context' => self::clean_context( $context ),
		);

		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}

		update_option( self::OPTION, $entries, false );
	}

	/**
	 * @return array<int, array{id: int, time: string, level: string, event: string, message: string, context: array}>
	 */
	public static function all() {
		$entries = get_option( self::OPTION, array() );

		return is_array( $entries ) ? array_values( $entries ) : array();
	}

	/**
	 * Entries newer than a given id (for the live view).
	 *
	 * @param int $since Last id the browser already has.
	 * @return array
	 */
	public static function since( $since ) {
		$since = (int) $since;

		return array_values(
			array_filter(
				self::all(),
				static function ( $entry ) use ( $since ) {
					return (int) $entry['id'] > $since;
				}
			)
		);
	}

	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Memory and time for a block of work, as context values.
	 *
	 * @param float $started microtime( true ) taken before the work.
	 * @return array{ms: int, peak_mb: float}
	 */
	public static function metrics( $started ) {
		return array(
			'ms'      => (int) round( ( microtime( true ) - (float) $started ) * 1000 ),
			'peak_mb' => round( memory_get_peak_usage( true ) / MB_IN_BYTES, 1 ),
		);
	}

	/**
	 * Plain text from a message that may carry HTML or entities.
	 *
	 * @param mixed $value  Anything printable.
	 * @param int   $length Maximum length.
	 * @return string
	 */
	private static function clean( $value, $length ) {
		$text = wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );

		if ( function_exists( 'mb_substr' ) && mb_strlen( $text ) > $length ) {
			return mb_substr( $text, 0, $length - 1 ) . '…';
		}

		return $text;
	}

	/**
	 * Keeps only small scalar values under safe key names.
	 *
	 * @param array $context Raw details.
	 * @return array
	 */
	private static function clean_context( array $context ) {
		$clean = array();

		foreach ( $context as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key || preg_match( self::FORBIDDEN_KEYS, $key ) || ! is_scalar( $value ) ) {
				continue;
			}

			$clean[ $key ] = is_string( $value ) ? self::clean( $value, self::MAX_CONTEXT ) : $value;
		}

		return $clean;
	}

	/* ---------------------------------------------------------------
	 * Admin endpoints: live view, clear, export.
	 * ------------------------------------------------------------- */

	public static function register_hooks() {
		add_action( 'wp_ajax_' . self::AJAX_FETCH, array( __CLASS__, 'ajax_fetch' ) );
		add_action( 'wp_ajax_' . self::AJAX_CLEAR, array( __CLASS__, 'ajax_clear' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( __CLASS__, 'export_csv' ) );
	}

	private static function authorize_ajax() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( JIMCA_Admin::CAPABILITY_MANAGE ) ) {
			wp_send_json_error( null, 403 );
		}
	}

	public static function ajax_fetch() {
		self::authorize_ajax();

		$since = isset( $_POST['since'] ) ? absint( $_POST['since'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in authorize_ajax().

		wp_send_json_success( array( 'entries' => self::since( $since ) ) );
	}

	public static function ajax_clear() {
		self::authorize_ajax();
		self::clear();
		wp_send_json_success();
	}

	public static function export_csv() {
		if ( ! current_user_can( JIMCA_Admin::CAPABILITY_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'jim-conversor-acessivel' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="jim-log-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- writes the download to the response.
		fputcsv( $out, array( 'id', 'time_utc', 'level', 'event', 'message', 'details' ) );

		foreach ( self::all() as $entry ) {
			$details = array();
			foreach ( $entry['context'] as $key => $value ) {
				$details[] = $key . '=' . $value;
			}

			// Spreadsheets run cells that start with = + - @: a leading apostrophe makes them plain text.
			$safe = static function ( $text ) {
				return preg_match( '/^[=+\-@]/', $text ) ? "'" . $text : $text;
			};

			fputcsv( $out, array( $entry['id'], $entry['time'], $entry['level'], $entry['event'], $safe( $entry['message'] ), $safe( implode( '; ', $details ) ) ) );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- writes the download to the response.
		exit;
	}
}
