<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts one PDF or DOCX outside the upload request, with progress.
 *
 * Many hosts end any request after 30 or 60 seconds (nginx answers "504
 * Gateway Time-out"; LiteSpeed simply stops PHP), and a large book takes
 * minutes. So the browser drives the conversion:
 *
 * 1. `start` checks and stores the file and creates a job;
 * 2. `run` converts for at most STEP_SECONDS and saves where it stopped; the
 *    browser calls it again until the job is done. A PDF is converted page
 *    by page across these steps; other formats in one step;
 * 3. `status` reports the progress while a step runs.
 *
 * Automatic feedback when the server stops a step:
 * - a fatal error (memory, time) is recorded by a shutdown function, in the
 *   job and in the activity log;
 * - a step killed without warning leaves the job where the last step saved
 *   it: the next `run` resumes from there, and after MAX_RETRIES attempts
 *   stuck on the same page the job fails with an explanation;
 * - a job nobody drives anymore (the tab was closed) is marked as stopped by
 *   reap_abandoned() and logged.
 */
class JIMCA_Conversion_Job {

	const AJAX_START  = 'jimca_job_start';
	const AJAX_RUN    = 'jimca_job_run';
	const AJAX_STATUS = 'jimca_job_status';

	/** Jobs live in transients; a day is enough for the browser to read the result. */
	const TTL = DAY_IN_SECONDS;

	/** IDs of the jobs not finished yet, for reap_abandoned(). */
	const INDEX_OPTION = 'jimca_jobs';

	/** Longest step, in seconds: well under the 30 to 60 s many hosts allow per request. */
	const STEP_SECONDS = 20;

	/** Steps that may start on the same page before the job gives up. */
	const MAX_RETRIES = 3;

	/** A running job untouched for this long has been abandoned (seconds). */
	const ABANDONED_AFTER = 600;

	/** Progress is written at most this often (seconds): every page would mean thousands of writes. */
	const SAVE_EVERY = 1.0;

	/** @var array|null Job of the step running in this request (read by the shutdown function). */
	private static $running = null;

	/** @var float Last time the progress was saved. */
	private static $last_save = 0.0;

	public static function register_hooks() {
		add_action( 'wp_ajax_' . self::AJAX_START, array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_' . self::AJAX_RUN, array( __CLASS__, 'ajax_run' ) );
		add_action( 'wp_ajax_' . self::AJAX_STATUS, array( __CLASS__, 'ajax_status' ) );
		add_action( 'admin_init', array( __CLASS__, 'reap_abandoned' ) );
	}

	/**
	 * Step 1: checks and stores the file, creates the job.
	 */
	public static function ajax_start() {
		self::assert_can_upload();
		check_ajax_referer( 'jimca_upload_document', 'jimca_upload_nonce' );

		$admin = JIMCA_Admin::get_instance();

		try {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP upload array, validated in collect_uploaded_files() and receive_file().
			$files = $admin->collect_uploaded_files( isset( $_FILES['jimca_file'] ) ? $_FILES['jimca_file'] : array(), 1 );

			if ( 1 !== count( $files ) ) {
				throw new JIMCA_Converter_Exception( esc_html__( 'Send one file at a time.', 'jim-conversor-acessivel' ) );
			}

			$received = $admin->receive_file( $files[0] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $admin->record_failure( $e ) ) );
		}

		$job = array(
			'id'           => wp_generate_password( 20, false ),
			'user'         => get_current_user_id(),
			'status'       => 'queued',
			'received'     => $received,
			'title'        => isset( $_POST['jimca_title'] ) ? sanitize_text_field( wp_unslash( $_POST['jimca_title'] ) ) : '',
			'remove_pages' => ! empty( $_POST['jimca_remove_pages'] ),
			'stage'        => 'queued',
			'done'         => 0,
			'total'        => 0,
			'images'       => 0,
			'updated'      => time(),
			'started'      => 0.0,
			'lock_until'   => 0,
			'resume_page'  => 0,
			'step_limit'   => 0,
			'step_page'    => -1,
			'retries'      => 0,
			'pdf_state'    => array(),
			'image_state'  => array(),
			'html_bytes'   => 0,
			'message'      => '',
		);

		self::save( $job );
		self::index_add( $job['id'] );

		wp_send_json_success( array( 'job' => $job['id'] ) );
	}

	/**
	 * Step 2: converts for at most STEP_SECONDS. The browser calls it again
	 * while the answer says the job is still running.
	 */
	public static function ajax_run() {
		self::assert_can_upload();
		check_ajax_referer( 'jimca_upload_document', 'jimca_upload_nonce' );

		$job = self::load_own();

		if ( in_array( $job['status'], array( 'done', 'failed' ), true ) || (int) $job['lock_until'] > time() ) {
			wp_send_json_success( self::public_view( $job ) );
		}

		// A step that starts where the previous one started means that step was killed.
		$job['retries']   = ( (int) $job['step_page'] === (int) $job['resume_page'] ) ? (int) $job['retries'] + 1 : 0;
		$job['step_page'] = (int) $job['resume_page'];

		if ( $job['retries'] >= self::MAX_RETRIES ) {
			$job = self::fail(
				$job,
				'upload.stuck',
				sprintf(
					/* translators: %d: page number */
					esc_html__( 'The server stopped the conversion several times around page %d without reporting an error, even in short steps. That part of the file may be too heavy for this hosting (often because of large images). Try again with images turned off in Settings, or split the PDF.', 'jim-conversor-acessivel' ),
					max( (int) $job['done'], (int) $job['resume_page'] ) + 1
				)
			);
			self::discard_job_files( $job );
			wp_send_json_success( self::public_view( $job ) );
		}

		// The proxy may give up on this request; the step must still finish and save.
		ignore_user_abort( true );
		JIMCA_Limits::raise_for_conversion();

		if ( 'queued' === $job['status'] ) {
			$job['status']  = 'running';
			$job['stage']   = 'reading';
			$job['started'] = microtime( true );
			JIMCA_Admin::log_start( $job['received'] );
		}

		// Each retry halves the step, in case this host ends requests sooner than expected.
		$job['step_limit'] = $job['retries'] > 0 && $job['step_limit'] > 0 ? max( 2, (int) floor( $job['step_limit'] / 2 ) ) : self::step_seconds();
		$job['lock_until'] = time() + $job['step_limit'] + 30;
		$job['updated']    = time();
		self::save( $job );

		self::$running = $job;
		register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );

		try {
			if ( 'pdf' === $job['received']['ext'] ) {
				$job = self::run_pdf_step( $job );
			} else {
				$job = self::run_single_step( $job );
			}
		} catch ( \Throwable $e ) {
			$admin = JIMCA_Admin::get_instance();

			// Images saved by this step are not in the job yet.
			if ( $admin->image_store_in_progress ) {
				$admin->image_store_in_progress->discard();
			}

			$job            = self::$running;
			$job['message'] = $admin->record_failure( $e );
			$job            = self::fail( $job, '', $job['message'] );
			self::discard_job_files( $job );
		}

		self::$running     = null;
		$job['lock_until'] = 0;
		self::save( $job );

		if ( in_array( $job['status'], array( 'done', 'failed' ), true ) ) {
			self::index_remove( $job['id'] );
		}

		wp_send_json_success( self::public_view( $job ) );
	}

	/**
	 * One step of a PDF: pages until the step deadline, appended to a part
	 * file next to the upload. The last step builds the document.
	 *
	 * @param array $job
	 * @return array
	 */
	private static function run_pdf_step( array $job ) {
		$admin    = JIMCA_Admin::get_instance();
		$settings = JIMCA_Admin::get_settings();
		$store    = null;

		if ( ! empty( $settings['include_images'] ) ) {
			$store = new JIMCA_Image_Store();
			$store->import_state( $job['image_state'] );
		}

		$admin->image_store_in_progress = $store;

		JIMCA_Converter::assert_php_extensions( 'pdf' );

		$converter = new JIMCA_Pdf_Converter(
			$store,
			array(
				'page_markers' => ! $job['remove_pages'],
				'progress'     => array( __CLASS__, 'on_progress' ),
			)
		);

		$part = $converter->convert_part( $job['received']['file'], $job['pdf_state'], microtime( true ) + (int) $job['step_limit'] );
		$file = self::part_file( $job );

		// A step killed after writing but before saving the job would leave a
		// duplicate: cut the file back to what the job knows about.
		self::truncate( $file, (int) $job['html_bytes'] );
		file_put_contents( $file, $part['html'], FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temporary file in the plugin's own upload folder.
		clearstatcache( true, $file );

		$job                = self::$running;
		$job['html_bytes']  = (int) filesize( $file );
		$job['image_state'] = $store ? $store->export_state() : array();
		$job['updated']     = time();

		if ( ! $part['done'] ) {
			$job['pdf_state']   = $part['state'];
			$job['resume_page'] = (int) $part['state']['next_page'];
			return $job;
		}

		$html = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- temporary file in the plugin's own upload folder.
		wp_delete_file( $file );
		JIMCA_Admin::cleanup_original( $job['received'] );

		$post_id = $admin->create_document( $job['received'], wp_kses_post( JIMCA_Pdf_Converter::finish( $html ) ), $store, $job['title'], (float) $job['started'] );

		$job['status']    = 'done';
		$job['post_id']   = $post_id;
		$job['pdf_state'] = array();
		$admin->notify_converted( $post_id );

		return $job;
	}

	/**
	 * DOCX, TXT and MD convert in one step.
	 *
	 * @param array $job
	 * @return array
	 */
	private static function run_single_step( array $job ) {
		$admin   = JIMCA_Admin::get_instance();
		$post_id = $admin->convert_received( $job['received'], $job['title'], $job['remove_pages'], array( __CLASS__, 'on_progress' ) );

		$job            = self::$running;
		$job['status']  = 'done';
		$job['post_id'] = $post_id;
		$job['updated'] = time();
		$admin->notify_converted( $post_id );

		return $job;
	}

	/**
	 * Step 3: progress, polled by the browser while a step runs.
	 */
	public static function ajax_status() {
		self::assert_can_upload();
		check_ajax_referer( 'jimca_upload_document', 'jimca_upload_nonce' );

		wp_send_json_success( self::public_view( self::load_own() ) );
	}

	/**
	 * Marks as stopped the jobs nobody drives anymore (the conversion page was
	 * closed, or the browser lost the connection), so the log says what
	 * happened instead of showing a start without an end. Runs at most every
	 * five minutes, on admin screens.
	 */
	public static function reap_abandoned() {
		if ( wp_doing_ajax() || get_transient( 'jimca_jobs_reaped' ) ) {
			return;
		}

		set_transient( 'jimca_jobs_reaped', 1, 5 * MINUTE_IN_SECONDS );

		foreach ( array_keys( (array) get_option( self::INDEX_OPTION, array() ) ) as $id ) {
			$job = get_transient( 'jimca_job_' . $id );

			if ( ! is_array( $job ) ) {
				self::index_remove( $id );
				continue;
			}

			if ( time() - (int) $job['updated'] < self::ABANDONED_AFTER || (int) $job['lock_until'] > time() ) {
				continue;
			}

			self::fail(
				$job,
				'upload.abandoned',
				sprintf(
					/* translators: 1: file name, 2: page number */
					esc_html__( 'The conversion of "%1$s" stopped at page %2$d: the conversion page was closed or lost the connection before it finished. Send the file again and keep the page open until the end.', 'jim-conversor-acessivel' ),
					esc_html( $job['received']['name'] ),
					(int) $job['done']
				)
			);
			self::discard_job_files( $job );
			self::index_remove( $id );
		}
	}

	/**
	 * Called by the converter: saves the progress, at most once per second.
	 *
	 * @param string $stage  'reading' or 'pages'.
	 * @param int    $done   Pages done.
	 * @param int    $total  Pages in the file (0 = not known yet).
	 * @param int    $images Images included so far.
	 */
	public static function on_progress( $stage, $done, $total, $images ) {
		if ( null === self::$running ) {
			return;
		}

		self::$running['stage']   = (string) $stage;
		self::$running['done']    = (int) $done;
		self::$running['total']   = (int) $total;
		self::$running['images']  = (int) $images;
		self::$running['updated'] = time();

		$now = microtime( true );

		if ( $now - self::$last_save >= self::SAVE_EVERY ) {
			self::$last_save = $now;

			// Live progress only: where the next step resumes is saved at the end of a step.
			$saved = get_transient( 'jimca_job_' . self::$running['id'] );

			if ( is_array( $saved ) ) {
				foreach ( array( 'stage', 'done', 'total', 'images', 'updated' ) as $key ) {
					$saved[ $key ] = self::$running[ $key ];
				}
				self::save( $saved );
			}
		}
	}

	/**
	 * PHP is ending while a step is still running: a fatal error (memory or
	 * time limit) cut it short. Records why, so the screen and the log say
	 * what happened instead of nothing.
	 */
	public static function on_shutdown() {
		if ( null === self::$running ) {
			return;
		}

		$job   = self::$running;
		$error = error_get_last();
		$fatal = $error && in_array( $error['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true );
		$text  = $fatal ? (string) $error['message'] : '';

		if ( false !== stripos( $text, 'Allowed memory size' ) ) {
			$message = sprintf(
				/* translators: %s: PHP memory limit, e.g. "256M" */
				esc_html__( 'The conversion stopped because PHP ran out of memory (memory_limit: %s). Try a smaller file, turn off images in Settings, or ask your host for more memory.', 'jim-conversor-acessivel' ),
				esc_html( (string) ini_get( 'memory_limit' ) )
			);
		} elseif ( false !== stripos( $text, 'Maximum execution time' ) ) {
			$message = sprintf(
				/* translators: %s: PHP time limit in seconds */
				esc_html__( 'The conversion stopped because it reached the PHP time limit (max_execution_time: %s s). Try a smaller file, turn off images in Settings, or ask your host for a longer limit.', 'jim-conversor-acessivel' ),
				esc_html( (string) ini_get( 'max_execution_time' ) )
			);
		} else {
			/*
			 * No PHP error: the server ended the request (some hosts stop PHP
			 * after a fixed time). The job stays as the last step saved it, and
			 * the browser's next `run` resumes from there.
			 */
			$saved = get_transient( 'jimca_job_' . $job['id'] );

			if ( is_array( $saved ) ) {
				$saved['lock_until'] = 0;
				self::save( $saved );
			}

			self::$running = null;
			return;
		}

		$admin = JIMCA_Admin::get_instance();

		// Images already sent to the Media Library belong to a document that will not exist.
		if ( $admin->image_store_in_progress ) {
			$admin->image_store_in_progress->discard();
		}

		self::fail( $job, 'upload.fatal', $message, array( 'php_error' => $text ) );
		self::discard_job_files( $job );
		self::index_remove( $job['id'] );
		self::$running = null;
	}

	/**
	 * @param array  $job
	 * @param string $event   Log event; '' when the failure was already logged.
	 * @param string $message Already escaped.
	 * @param array  $context Extra log details.
	 * @return array The failed job.
	 */
	private static function fail( array $job, $event, $message, array $context = array() ) {
		$job['status']     = 'failed';
		$job['message']    = $message;
		$job['updated']    = time();
		$job['lock_until'] = 0;
		self::save( $job );

		if ( '' !== $event ) {
			JIMCA_Log::add(
				'error',
				$event,
				sprintf( '"%s": %s', sanitize_file_name( $job['received']['name'] ), wp_strip_all_tags( $message ) ),
				array_merge(
					array(
						'stage' => $job['stage'],
						'page'  => $job['done'],
						'pages' => $job['total'],
					),
					$context
				)
			);
		}

		if ( (int) $job['user'] === get_current_user_id() ) {
			JIMCA_Admin::get_instance()->flash_notice( 'error', $message );
		}

		return $job;
	}

	/**
	 * Removes what a failed job leaves behind: the part file, the images
	 * already saved and the uploaded original (when Settings delete it).
	 *
	 * @param array $job
	 */
	private static function discard_job_files( array $job ) {
		$file = self::part_file( $job );

		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}

		if ( ! empty( $job['image_state']['attachment_ids'] ) ) {
			foreach ( (array) $job['image_state']['attachment_ids'] as $attachment_id ) {
				wp_delete_attachment( (int) $attachment_id, true );
			}
		}

		JIMCA_Admin::cleanup_original( $job['received'] );
	}

	/**
	 * What the browser needs to show. File paths stay on the server.
	 *
	 * @param array $job
	 * @return array
	 */
	private static function public_view( array $job ) {
		return array(
			'status'   => $job['status'],
			'stage'    => $job['stage'],
			'done'     => (int) $job['done'],
			'total'    => (int) $job['total'],
			'images'   => (int) $job['images'],
			'message'  => $job['message'],
			'busy'     => (int) $job['lock_until'] > time(),
			'redirect' => admin_url( 'admin.php?page=' . JIMCA_Admin::MENU_SLUG ),
		);
	}

	/** @return int Seconds per step: STEP_SECONDS, or less when PHP allows less. */
	private static function step_seconds() {
		$max_time = (int) ini_get( 'max_execution_time' );
		$seconds  = $max_time > 0 ? (int) max( 5, min( self::STEP_SECONDS, $max_time / 2 ) ) : self::STEP_SECONDS;

		/**
		 * Length of each conversion step, in seconds. Lower it on hosts that
		 * end requests sooner than usual.
		 *
		 * @param int $seconds
		 */
		return max( 1, (int) apply_filters( 'jimca_conversion_step_seconds', $seconds ) );
	}

	/**
	 * @param array $job
	 * @return string Path of the HTML converted so far.
	 */
	private static function part_file( array $job ) {
		return $job['received']['file'] . '.jimca-part.html';
	}

	/**
	 * @param string $file
	 * @param int    $bytes
	 */
	private static function truncate( $file, $bytes ) {
		if ( ! file_exists( $file ) ) {
			return;
		}

		clearstatcache( true, $file );

		if ( filesize( $file ) > $bytes ) {
			$handle = fopen( $file, 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- temporary file in the plugin's own upload folder.
			if ( $handle ) {
				ftruncate( $handle, $bytes );
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
			}
		}
	}

	private static function assert_can_upload() {
		if ( ! current_user_can( JIMCA_Admin::CAPABILITY_UPLOAD ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You do not have permission to upload documents.', 'jim-conversor-acessivel' ) ), 403 );
		}
	}

	/**
	 * The job named in the request, if it belongs to the current user.
	 *
	 * @return array
	 */
	private static function load_own() {
		$id  = isset( $_POST['job'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_POST['job'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the callers check the nonce.
		$job = '' !== $id ? get_transient( 'jimca_job_' . $id ) : false;

		if ( ! is_array( $job ) || (int) $job['user'] !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'This conversion was not found. Reload the page and send the file again.', 'jim-conversor-acessivel' ) ), 404 );
		}

		return $job;
	}

	/** @param array $job */
	private static function save( array $job ) {
		set_transient( 'jimca_job_' . $job['id'], $job, self::TTL );
	}

	/** @param string $id */
	private static function index_add( $id ) {
		$index        = (array) get_option( self::INDEX_OPTION, array() );
		$index[ $id ] = time();
		update_option( self::INDEX_OPTION, $index, false );
	}

	/** @param string $id */
	private static function index_remove( $id ) {
		$index = (array) get_option( self::INDEX_OPTION, array() );

		if ( isset( $index[ $id ] ) ) {
			unset( $index[ $id ] );
			update_option( self::INDEX_OPTION, $index, false );
		}
	}
}
