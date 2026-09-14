<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Telas de administração: upload de documentos e configurações do plugin.
 */
class JIMCA_Admin {

	const CAPABILITY_UPLOAD  = 'upload_files';
	const CAPABILITY_MANAGE  = 'manage_options';
	const MENU_SLUG          = 'jimca-conversor';
	const SETTINGS_OPTION    = 'jimca_settings';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_jimca_upload_document', array( $this, 'handle_upload' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
		add_action( 'admin_notices', array( $this, 'render_documents_list_banner' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'plugin_row_meta', array( $this, 'add_plugin_row_meta' ), 10, 2 );
	}

	/**
	 * Links extras na linha do plugin em Plugins > Plugins instalados.
	 *
	 * @param string[] $links Links já existentes na linha.
	 * @param string   $file  Plugin sendo renderizado naquela linha.
	 * @return string[]
	 */
	public function add_plugin_row_meta( $links, $file ) {
		if ( plugin_basename( JIMCA_PLUGIN_FILE ) !== $file ) {
			return $links;
		}

		// O tutorial é uma tela do wp-admin: só faz sentido para quem pode abri-la.
		if ( current_user_can( self::CAPABILITY_UPLOAD ) ) {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-tutorial' ) ),
				esc_html__( 'Tutorial', 'jim-conversor-acessivel' )
			);
		}

		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( JIMCA_REPO_URL ),
			esc_html__( 'Visite o repositório', 'jim-conversor-acessivel' )
		);

		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( JIMCA_SITE_URL ),
			esc_html__( 'Site do plugin', 'jim-conversor-acessivel' )
		);

		return $links;
	}

	/**
	 * Carrega CSS/JS só nas telas do próprio plugin — nunca no resto do
	 * wp-admin, para não pesar ou "vazar" estilo em outras telas.
	 *
	 * @param string $hook Hook suffix da tela atual, passado pelo WordPress.
	 */
	public function enqueue_admin_assets( $hook ) {
		unset( $hook ); // Não usamos o hook suffix; get_current_screen() já basta.
		$screen                 = get_current_screen();
		$documents_list_screen  = 'edit-' . JIMCA_Post_Type::POST_TYPE;
		$is_plugin_screen       = $screen && ( false !== strpos( $screen->id, self::MENU_SLUG ) || $documents_list_screen === $screen->id );

		if ( ! $is_plugin_screen ) {
			return;
		}

		wp_enqueue_style( 'jimca-admin', JIMCA_PLUGIN_URL . 'assets/css/admin.css', array(), JIMCA_VERSION );

		if ( self::MENU_SLUG === $screen->id || 'toplevel_page_' . self::MENU_SLUG === $screen->id ) {
			wp_enqueue_script( 'jimca-admin', JIMCA_PLUGIN_URL . 'assets/js/admin.js', array(), JIMCA_VERSION, true );
			wp_localize_script(
				'jimca-admin',
				'jimcaAdminI18n',
				array(
					/* translators: %s: nome do arquivo escolhido */
					'fileAdded'      => __( 'Arquivo adicionado: %s', 'jim-conversor-acessivel' ),
					'converting'     => __( 'Convertendo o documento…', 'jim-conversor-acessivel' ),
					'convertingHint' => __( 'Dependendo do tamanho do arquivo isso pode levar de alguns segundos a cerca de um minuto. Não feche nem atualize esta página.', 'jim-conversor-acessivel' ),
				)
			);
		}
	}

	public function register_menu() {
		add_menu_page(
			__( 'Jim - Conversor Acessível', 'jim-conversor-acessivel' ),
			__( 'Jim - Conversor Acessível', 'jim-conversor-acessivel' ),
			self::CAPABILITY_UPLOAD,
			self::MENU_SLUG,
			array( $this, 'render_upload_page' ),
			'dashicons-image-rotate-right',
			26
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Novo Documento', 'jim-conversor-acessivel' ),
			__( 'Novo Documento', 'jim-conversor-acessivel' ),
			self::CAPABILITY_UPLOAD,
			self::MENU_SLUG,
			array( $this, 'render_upload_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Configurações', 'jim-conversor-acessivel' ),
			__( 'Configurações', 'jim-conversor-acessivel' ),
			self::CAPABILITY_MANAGE,
			self::MENU_SLUG . '-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Tutorial', 'jim-conversor-acessivel' ),
			__( 'Tutorial', 'jim-conversor-acessivel' ),
			self::CAPABILITY_UPLOAD,
			self::MENU_SLUG . '-tutorial',
			array( $this, 'render_tutorial_page' )
		);
	}

	public static function get_settings() {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		return wp_parse_args( $settings, JIMCA_Activator::default_settings() );
	}

	/**
	 * Temas de leitura disponíveis para o documento convertido.
	 * Cada chave corresponde a uma classe CSS `jimca-theme-{chave}`
	 * (ver assets/css/frontend.css).
	 *
	 * @return array<string, string> Chave => rótulo traduzido.
	 */
	/**
	 * Limite de upload que vale de verdade: o menor entre o configurado em
	 * Configurações e o que o servidor realmente aceita (upload_max_filesize
	 * / post_max_size do PHP). Adiantar isso ao usuário evita que ele tente
	 * enviar um arquivo que o PHP vai recusar antes do WordPress ver.
	 *
	 * @return int Limite em bytes.
	 */
	public static function get_effective_max_bytes() {
		$settings   = self::get_settings();
		$plugin_max = (int) $settings['max_file_size_mb'] * MB_IN_BYTES;
		$server_max = (int) wp_max_upload_size();

		if ( $server_max > 0 ) {
			return min( $plugin_max, $server_max );
		}

		return $plugin_max;
	}

	public static function get_available_themes() {
		return array(
			'light' => __( 'Claro', 'jim-conversor-acessivel' ),
			'sepia' => __( 'Sépia', 'jim-conversor-acessivel' ),
			'dark'  => __( 'Escuro', 'jim-conversor-acessivel' ),
		);
	}

	/**
	 * Cabeçalho compartilhado das telas do plugin: logo e título da marca.
	 * A navegação entre "Novo Documento" e "Configurações" já existe no
	 * submenu nativo do wp-admin (barra lateral), então não é duplicada
	 * aqui — cada tela mostra seu próprio título logo abaixo (ver
	 * render_page_title()).
	 */
	private function render_admin_header() {
		?>
		<div class="jimca-admin-header">
			<div class="jimca-admin-header__logo">
				<img src="<?php echo esc_url( JIMCA_PLUGIN_URL . 'assets/images/logo.png' ); ?>" alt="" width="56" height="56">
			</div>
			<div class="jimca-admin-header__text">
				<h1><?php esc_html_e( 'Jim - Conversor Acessível', 'jim-conversor-acessivel' ); ?></h1>
				<p><?php esc_html_e( 'Documentos acessíveis, com leitura em voz alta, em poucos cliques.', 'jim-conversor-acessivel' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Título específico de cada tela do plugin, exibido logo abaixo do
	 * banner (ver render_admin_header()).
	 *
	 * @param string $title Título já traduzido da tela atual.
	 */
	private function render_page_title( $title ) {
		?>
		<h2 class="jimca-admin-page-title"><?php echo esc_html( $title ); ?></h2>
		<?php
	}

	/* ---------------------------------------------------------------
	 * Upload
	 * ------------------------------------------------------------- */

	public function render_upload_page() {
		if ( ! current_user_can( self::CAPABILITY_UPLOAD ) ) {
			wp_die( esc_html__( 'Você não tem permissão para acessar esta página.', 'jim-conversor-acessivel' ) );
		}

		$settings = self::get_settings();
		?>
		<div class="wrap">
			<?php
			$this->render_admin_header();
			$this->render_page_title( __( 'Novo Documento', 'jim-conversor-acessivel' ) );
			?>

			<div class="jimca-admin-card">
				<p><?php esc_html_e( 'Envie um arquivo PDF, DOCX ou TXT para gerar uma página acessível (com leitura em voz alta) e um shortcode para publicá-la.', 'jim-conversor-acessivel' ); ?></p>

				<div id="jimca-file-toast" class="jimca-admin-toast" role="status" aria-live="polite" hidden></div>

				<form id="jimca-upload-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'jimca_upload_document', 'jimca_upload_nonce' ); ?>
					<input type="hidden" name="action" value="jimca_upload_document">

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="jimca_title"><?php esc_html_e( 'Título do documento', 'jim-conversor-acessivel' ); ?></label></th>
							<td><input type="text" id="jimca_title" name="jimca_title" class="regular-text" required></td>
						</tr>
						<tr>
							<th scope="row"><label for="jimca_file"><?php esc_html_e( 'Arquivo', 'jim-conversor-acessivel' ); ?></label></th>
							<td>
								<input type="file" id="jimca_file" name="jimca_file" accept=".pdf,.docx,.txt" required>
								<p class="description">
									<?php
									printf(
										/* translators: 1: tipos permitidos, 2: tamanho máximo já formatado (ex.: "10 MB") */
										esc_html__( 'Tipos aceitos: %1$s. Tamanho máximo: %2$s.', 'jim-conversor-acessivel' ),
										esc_html( strtoupper( implode( ', ', $settings['allowed_types'] ) ) ),
										esc_html( size_format( self::get_effective_max_bytes() ) )
									);
									?>
								</p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Converter documento', 'jim-conversor-acessivel' ) ); ?>

					<?php
					/*
					 * Estado "convertendo": a conversão de um PDF grande pode levar
					 * quase um minuto, e sem isso o usuário fica sem nenhum retorno
					 * depois de clicar. O texto é preenchido pelo JS (ver
					 * assets/js/admin.js) justamente para que a mudança de conteúdo
					 * dentro da região aria-live seja anunciada por leitores de tela.
					 */
					?>
					<div id="jimca-upload-progress" class="jimca-admin-progress" role="status" aria-live="polite" hidden>
						<span class="jimca-admin-spinner" aria-hidden="true"></span>
						<span>
							<strong class="jimca-admin-progress__title" data-jimca-progress-title></strong>
							<span class="jimca-admin-progress__hint" data-jimca-progress-hint></span>
						</span>
					</div>
				</form>
			</div>

			<p class="jimca-admin-footer-link">
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . JIMCA_Post_Type::POST_TYPE ) ); ?>">
					<?php esc_html_e( 'Ver todos os documentos convertidos e seus shortcodes →', 'jim-conversor-acessivel' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	public function handle_upload() {
		if ( ! current_user_can( self::CAPABILITY_UPLOAD ) ) {
			wp_die( esc_html__( 'Você não tem permissão para enviar documentos.', 'jim-conversor-acessivel' ) );
		}

		$redirect = admin_url( 'admin.php?page=' . self::MENU_SLUG );

		/*
		 * Quando o arquivo enviado passa do `post_max_size` do PHP, o PHP
		 * descarta $_POST e $_FILES inteiros ANTES do WordPress rodar —
		 * inclusive o nonce. Sem este bloco, check_admin_referer() abaixo
		 * falharia e o usuário veria a tela genérica "Tem certeza de que
		 * deseja fazer isso?" em admin-post.php, sem nenhuma pista do que
		 * aconteceu. Detectamos o caso aqui e explicamos o problema.
		 */
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- não lemos nenhum valor de $_POST aqui; só detectamos que o PHP descartou a requisição inteira (e com ela o nonce) por exceder post_max_size, caso que precisa ser tratado antes da verificação do nonce.
		if ( 'POST' === $request_method && empty( $_POST ) && empty( $_FILES ) ) {
			$this->set_user_notice(
				'error',
				sprintf(
					/* translators: %s: limite de upload do servidor, já formatado (ex.: "2 MB") */
					esc_html__( 'O arquivo é grande demais para o servidor aceitar (limite atual: %s). Envie um arquivo menor ou peça à sua hospedagem para aumentar os limites "upload_max_filesize" e "post_max_size" do PHP.', 'jim-conversor-acessivel' ),
					esc_html( size_format( wp_max_upload_size() ) )
				)
			);

			wp_safe_redirect( $redirect );
			exit;
		}

		check_admin_referer( 'jimca_upload_document', 'jimca_upload_nonce' );

		try {
			$post_id = $this->process_upload();
			$this->set_user_notice(
				'success',
				sprintf(
					/* translators: %s: link para editar o documento */
					esc_html__( 'Documento convertido com sucesso! %s', 'jim-conversor-acessivel' ),
					'<a href="' . esc_url( get_edit_post_link( $post_id, '' ) ) . '">' . esc_html__( 'Ver documento e shortcode', 'jim-conversor-acessivel' ) . '</a>'
				)
			);
		} catch ( JIMCA_Converter_Exception $e ) {
			/*
			 * Sem esc_html() aqui: as mensagens desta exceção já são escapadas
			 * no ponto em que são lançadas. Escapar de novo transformaria um
			 * "&" em "&amp;amp;" na tela.
			 */
			$this->set_user_notice( 'error', $e->getMessage() );
		} catch ( \Throwable $e ) {
			/*
			 * \Throwable (e não só \Exception) porque as bibliotecas de
			 * leitura de PDF/DOCX podem lançar \Error (falta de memória em
			 * um arquivo muito pesado, extensão do PHP ausente, arquivo
			 * corrompido). Sem isso o erro viraria uma tela em branco ou
			 * um erro cru em admin-post.php, em vez de um aviso legível.
			 */
			$message = esc_html__( 'Ocorreu um erro inesperado ao processar o arquivo.', 'jim-conversor-acessivel' );

			// Para quem administra o site, mostramos também o detalhe técnico,
			// que é o que permite descobrir a causa real do problema.
			if ( current_user_can( self::CAPABILITY_MANAGE ) ) {
				$message .= ' <br><small><code>' . esc_html( $e->getMessage() ) . '</code></small>';
			}

			$this->set_user_notice( 'error', $message );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Guarda um aviso para exibir ao usuário atual depois do redirecionamento.
	 *
	 * @param string $type    'success' ou 'error'.
	 * @param string $message Mensagem já escapada.
	 */
	private function set_user_notice( $type, $message ) {
		set_transient(
			'jimca_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
	}

	/**
	 * @return int ID do post criado.
	 * @throws JIMCA_Converter_Exception
	 */
	private function process_upload() {
		// Nonce já verificado em handle_upload() antes de chamar este método;
		// verificamos de novo aqui (defesa em profundidade) para que este
		// método também seja seguro se um dia for chamado de outro lugar.
		check_admin_referer( 'jimca_upload_document', 'jimca_upload_nonce' );

		if ( empty( $_FILES['jimca_file'] ) || ! empty( $_FILES['jimca_file']['error'] ) ) {
			throw new JIMCA_Converter_Exception( esc_html__( 'Nenhum arquivo válido foi enviado.', 'jim-conversor-acessivel' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- o array $_FILES é validado abaixo via wp_check_filetype_and_ext() e wp_handle_upload(); o nome do arquivo é sanitizado explicitamente na linha seguinte.
		$file         = $_FILES['jimca_file'];
		$file['name'] = sanitize_file_name( wp_unslash( $file['name'] ) );
		$settings     = self::get_settings();
		$max_bytes    = self::get_effective_max_bytes();

		if ( $file['size'] > $max_bytes ) {
			throw new JIMCA_Converter_Exception(
				sprintf(
					/* translators: %s: tamanho máximo já formatado (ex.: "10 MB") */
					esc_html__( 'Arquivo maior que o limite de %s.', 'jim-conversor-acessivel' ),
					esc_html( size_format( $max_bytes ) )
				)
			);
		}

		$filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );

		if ( empty( $filetype['ext'] ) || ! in_array( $filetype['ext'], $settings['allowed_types'], true ) ) {
			throw new JIMCA_Converter_Exception( esc_html__( 'Tipo de arquivo não permitido.', 'jim-conversor-acessivel' ) );
		}

		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );
		$moved = wp_handle_upload( $file, array( 'test_form' => false ) );
		remove_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );

		if ( isset( $moved['error'] ) ) {
			throw new JIMCA_Converter_Exception( esc_html( $moved['error'] ) );
		}

		$this->protect_upload_dir( dirname( $moved['file'] ) );

		$extension = $filetype['ext'];
		$html      = '';

		/*
		 * Ler um PDF grande é a parte mais pesada do plugin: as bibliotecas
		 * de leitura montam em memória a estrutura inteira do arquivo
		 * (fontes embutidas, streams comprimidos, tabelas de referência),
		 * o que costuma custar bem mais que o texto extraído. Esta é a API
		 * do próprio WordPress para pedir o limite maior já previsto pelo
		 * site (WP_MAX_MEMORY_LIMIT, 256 MB por padrão) em tarefas de
		 * administração; sem ela o processo pode morrer no meio da
		 * conversão, sem chegar a lançar exceção.
		 */
		wp_raise_memory_limit( 'admin' );

		try {
			$html = JIMCA_Converter::convert( $moved['file'], $extension );
		} finally {
			if ( ! empty( $settings['delete_original_after'] ) && file_exists( $moved['file'] ) ) {
				wp_delete_file( $moved['file'] );
			}
		}

		$title = isset( $_POST['jimca_title'] ) ? sanitize_text_field( wp_unslash( $_POST['jimca_title'] ) ) : '';
		if ( '' === $title ) {
			$title = sanitize_text_field( $file['name'] );
		}

		/*
		 * Sem o terceiro argumento, preg_match_all() devolve a contagem sem
		 * montar o array com todas as palavras encontradas. Num livro de
		 * ~145 mil palavras esse array sozinho custava 8 MB de memória —
		 * medido — para produzir um número que a própria função já retorna.
		 */
		$word_count = (int) preg_match_all( '/\p{L}+/u', wp_strip_all_tags( $html ) );

		$post_id = wp_insert_post(
			array(
				'post_type'    => JIMCA_Post_Type::POST_TYPE,
				'post_title'   => $title,
				'post_content' => $html,
				'post_status'  => 'publish',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			throw new JIMCA_Converter_Exception( esc_html( $post_id->get_error_message() ) );
		}

		update_post_meta( $post_id, '_jimca_original_filename', sanitize_file_name( $file['name'] ) );
		update_post_meta( $post_id, '_jimca_original_type', $extension );
		update_post_meta( $post_id, '_jimca_word_count', $word_count );
		update_post_meta( $post_id, '_jimca_converted_at', current_time( 'mysql' ) );

		if ( empty( $settings['delete_original_after'] ) ) {
			update_post_meta( $post_id, '_jimca_original_url', esc_url_raw( $moved['url'] ) );
		}

		return $post_id;
	}

	/**
	 * Defesa em profundidade: só PDF/DOCX/TXT chegam a essa pasta (já
	 * validados antes do upload), mas escrevemos um .htaccess que nega
	 * a EXECUÇÃO de qualquer script (não o acesso a arquivos em geral —
	 * o PDF/DOCX/TXT original precisa continuar acessível quando a opção
	 * "apagar original" está desligada). Cobre o caso de outro
	 * plugin/config permitir no futuro um upload que não devia. Efeito
	 * real depende do servidor honrar .htaccess (Apache/LiteSpeed; não
	 * se aplica a Nginx). index.php evita listagem do diretório.
	 */
	private function protect_upload_dir( $dir ) {
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents(
				$htaccess,
				"<IfModule mod_authz_core.c>\n" .
				"    <FilesMatch \"\\.(php\\d?|phtml|pl|py|cgi|sh|asp|aspx)$\">\n" .
				"        Require all denied\n" .
				"    </FilesMatch>\n" .
				"</IfModule>\n" .
				"<IfModule !mod_authz_core.c>\n" .
				"    <FilesMatch \"\\.(php\\d?|phtml|pl|py|cgi|sh|asp|aspx)$\">\n" .
				"        Order allow,deny\n" .
				"        Deny from all\n" .
				"    </FilesMatch>\n" .
				"</IfModule>\n" .
				"Options -Indexes -ExecCGI\n"
			);
		}

		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	public function filter_upload_dir( $dirs ) {
		$dirs['subdir'] = '/jimca-documentos' . $dirs['subdir'];
		$dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
		$dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
		return $dirs;
	}

	public function render_admin_notices() {
		$key    = 'jimca_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! $notice ) {
			return;
		}

		delete_transient( $key );

		$class = ( 'error' === $notice['type'] ) ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . wp_kses_post( $notice['message'] ) . '</p></div>';
	}

	/**
	 * Banner com a marca do plugin acima da listagem nativa "Todos os
	 * Documentos" (tela do Custom Post Type). Só é exibido nessa tela
	 * específica — não duplicamos um título de página aqui porque o
	 * H1 nativo do WordPress ("Documentos Convertidos") já cumpre esse
	 * papel na listagem.
	 */
	public function render_documents_list_banner() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . JIMCA_Post_Type::POST_TYPE !== $screen->id ) {
			return;
		}

		$this->render_admin_header();
	}

	/* ---------------------------------------------------------------
	 * Tutorial
	 * ------------------------------------------------------------- */

	/**
	 * Página de apresentação do plugin: o que é, como usar, onde está o
	 * código, qual versão está instalada e quem assina o projeto.
	 */
	public function render_tutorial_page() {
		if ( ! current_user_can( self::CAPABILITY_UPLOAD ) ) {
			wp_die( esc_html__( 'Você não tem permissão para acessar esta página.', 'jim-conversor-acessivel' ) );
		}

		$upload_url    = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$documents_url = admin_url( 'edit.php?post_type=' . JIMCA_Post_Type::POST_TYPE );
		$settings_url  = admin_url( 'admin.php?page=' . self::MENU_SLUG . '-settings' );
		?>
		<div class="wrap">
			<?php
			$this->render_admin_header();
			$this->render_page_title( __( 'Tutorial', 'jim-conversor-acessivel' ) );
			?>

			<div class="jimca-admin-card">
				<h2><?php esc_html_e( 'O que é o Jim', 'jim-conversor-acessivel' ); ?></h2>
				<p>
					<?php esc_html_e( 'O Jim transforma um arquivo PDF, Word (.docx) ou TXT em uma página de leitura acessível dentro do seu site. Em vez de oferecer um arquivo para baixar, o conteúdo vira texto de verdade na página: quem usa leitor de tela consegue ler, quem precisa de letra maior pode aumentar, e quem prefere ouvir tem leitura em voz alta pelo próprio navegador.', 'jim-conversor-acessivel' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'Cada documento convertido fica guardado no seu WordPress e ganha um shortcode, que você cola em qualquer página ou post para publicá-lo.', 'jim-conversor-acessivel' ); ?>
				</p>
			</div>

			<div class="jimca-admin-card">
				<h2><?php esc_html_e( 'Como usar, passo a passo', 'jim-conversor-acessivel' ); ?></h2>
				<ol class="jimca-admin-steps">
					<li>
						<?php
						printf(
							/* translators: %s: link para a tela de envio */
							esc_html__( 'Abra %s e escolha o arquivo (PDF, DOCX ou TXT).', 'jim-conversor-acessivel' ),
							'<a href="' . esc_url( $upload_url ) . '"><strong>' . esc_html__( 'Novo Documento', 'jim-conversor-acessivel' ) . '</strong></a>'
						);
						?>
					</li>
					<li><?php esc_html_e( 'Dê um título ao documento e clique em "Converter documento". Arquivos grandes podem levar até cerca de um minuto.', 'jim-conversor-acessivel' ); ?></li>
					<li>
						<?php
						printf(
							/* translators: %s: link para a listagem de documentos */
							esc_html__( 'Ao terminar, o documento aparece em %s, com o shortcode dele na listagem.', 'jim-conversor-acessivel' ),
							'<a href="' . esc_url( $documents_url ) . '"><strong>' . esc_html__( 'Documentos', 'jim-conversor-acessivel' ) . '</strong></a>'
						);
						?>
					</li>
					<li><?php esc_html_e( 'Copie o shortcode e cole na página ou post onde o documento deve aparecer.', 'jim-conversor-acessivel' ); ?></li>
				</ol>

				<h3><?php esc_html_e( 'Shortcodes disponíveis', 'jim-conversor-acessivel' ); ?></h3>
				<table class="widefat striped jimca-admin-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Shortcode', 'jim-conversor-acessivel' ); ?></th>
							<th scope="col"><?php esc_html_e( 'O que faz', 'jim-conversor-acessivel' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code>[documento_acessivel id="123"]</code></td>
							<td><?php esc_html_e( 'Publica o documento convertido, com os controles de leitura.', 'jim-conversor-acessivel' ); ?></td>
						</tr>
						<tr>
							<td><code>[jimca_instalacoes]</code></td>
							<td><?php esc_html_e( 'Mostra quantas instalações ativas o plugin tem, segundo a API do WordPress.org.', 'jim-conversor-acessivel' ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="jimca-admin-card">
				<h2><?php esc_html_e( 'Os controles de leitura', 'jim-conversor-acessivel' ); ?></h2>
				<p><?php esc_html_e( 'Na página publicada, uma barra flutuante acompanha o leitor enquanto ele rola o documento:', 'jim-conversor-acessivel' ); ?></p>
				<ul class="jimca-admin-list">
					<li><?php esc_html_e( 'Tamanho do texto e tema de leitura (Claro, Sépia ou Escuro).', 'jim-conversor-acessivel' ); ?></li>
					<li><?php esc_html_e( 'Alto contraste.', 'jim-conversor-acessivel' ); ?></li>
					<li><?php esc_html_e( 'Escolha da voz, ouvir/pausar, velocidade e parar.', 'jim-conversor-acessivel' ); ?></li>
					<li><?php esc_html_e( 'Ocultar a barra, para quem quer a tela inteira só para o texto.', 'jim-conversor-acessivel' ); ?></li>
				</ul>
				<p class="description">
					<?php
					printf(
						/* translators: %s: link para as configurações */
						esc_html__( 'O tema padrão do site e a velocidade inicial da leitura ficam em %s. Cada leitor ainda pode trocar essas opções na hora, e a escolha dele vale só para o navegador dele.', 'jim-conversor-acessivel' ),
						'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Configurações', 'jim-conversor-acessivel' ) . '</a>'
					);
					?>
				</p>
			</div>

			<div class="jimca-admin-card">
				<h2><?php esc_html_e( 'Versão e atualizações', 'jim-conversor-acessivel' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: número da versão instalada */
						esc_html__( 'Versão instalada: %s.', 'jim-conversor-acessivel' ),
						'<strong>' . esc_html( JIMCA_VERSION ) . '</strong>'
					);
					?>
				</p>
				<p><?php esc_html_e( 'Depois que o plugin estiver publicado no diretório oficial do WordPress, as atualizações chegam pela tela de Plugins do seu site, como em qualquer outro plugin. Até lá, cada nova versão é publicada no repositório do projeto.', 'jim-conversor-acessivel' ); ?></p>
			</div>

			<div class="jimca-admin-card jimca-admin-card--author">
				<h2><?php esc_html_e( 'Autoria', 'jim-conversor-acessivel' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: nome da autora */
						esc_html__( 'Desenvolvido por %s. Software livre, sob licença GPL v2 ou posterior.', 'jim-conversor-acessivel' ),
						'<strong><a href="' . esc_url( JIMCA_AUTHOR_URL ) . '" target="_blank" rel="noopener noreferrer">Mayara Nascimento</a></strong>'
					);
					?>
				</p>
				<p class="jimca-admin-links">
					<a class="button" href="<?php echo esc_url( JIMCA_REPO_URL ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Visite o repositório', 'jim-conversor-acessivel' ); ?>
					</a>
					<a class="button" href="<?php echo esc_url( JIMCA_SITE_URL ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Site do plugin', 'jim-conversor-acessivel' ); ?>
					</a>
					<a class="button" href="<?php echo esc_url( JIMCA_REPO_URL . '/issues' ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Relatar um problema', 'jim-conversor-acessivel' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------
	 * Configurações
	 * ------------------------------------------------------------- */

	public function register_settings() {
		register_setting( 'jimca_settings_group', self::SETTINGS_OPTION, array( $this, 'sanitize_settings' ) );
	}

	public function sanitize_settings( $input ) {
		$defaults = JIMCA_Activator::default_settings();
		$output   = array();

		$allowed_types_input     = isset( $input['allowed_types'] ) ? (array) $input['allowed_types'] : array();
		$output['allowed_types'] = array_values( array_intersect( array( 'pdf', 'docx', 'txt' ), $allowed_types_input ) );
		if ( empty( $output['allowed_types'] ) ) {
			$output['allowed_types'] = $defaults['allowed_types'];
		}

		$output['max_file_size_mb']      = max( 1, min( 100, (int) ( $input['max_file_size_mb'] ?? $defaults['max_file_size_mb'] ) ) );
		$output['delete_original_after'] = ! empty( $input['delete_original_after'] );
		// O slug do WordPress.org não é mais um campo editável na tela de
		// Configurações (uso interno do shortcode [jimca_instalacoes]),
		// então sempre mantemos o valor padrão definido pelo plugin.
		$output['wporg_slug'] = $defaults['wporg_slug'];

		$rate                       = isset( $input['tts_default_rate'] ) ? (float) $input['tts_default_rate'] : $defaults['tts_default_rate'];
		$output['tts_default_rate'] = max( 0.5, min( 2, $rate ) );

		$valid_themes    = array_keys( self::get_available_themes() );
		$output['theme'] = ( isset( $input['theme'] ) && in_array( $input['theme'], $valid_themes, true ) )
			? $input['theme']
			: $defaults['theme'];

		return $output;
	}

	public function render_settings_page() {
		if ( ! current_user_can( self::CAPABILITY_MANAGE ) ) {
			wp_die( esc_html__( 'Você não tem permissão para acessar esta página.', 'jim-conversor-acessivel' ) );
		}

		$settings = self::get_settings();
		?>
		<div class="wrap">
			<?php
			$this->render_admin_header();
			$this->render_page_title( __( 'Configurações', 'jim-conversor-acessivel' ) );
			?>

			<div class="jimca-admin-card">
			<form method="post" action="options.php">
				<?php settings_fields( 'jimca_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Tipos de arquivo permitidos', 'jim-conversor-acessivel' ); ?></th>
						<td>
							<?php foreach ( array( 'pdf', 'docx', 'txt' ) as $type ) : ?>
								<label style="margin-right:1em;">
									<input type="checkbox" name="jimca_settings[allowed_types][]" value="<?php echo esc_attr( $type ); ?>"
										<?php checked( in_array( $type, $settings['allowed_types'], true ) ); ?>>
									<?php echo esc_html( strtoupper( $type ) ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jimca_max_size"><?php esc_html_e( 'Tamanho máximo (MB)', 'jim-conversor-acessivel' ); ?></label></th>
						<td><input type="number" id="jimca_max_size" name="jimca_settings[max_file_size_mb]" min="1" max="100" value="<?php echo esc_attr( $settings['max_file_size_mb'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Apagar arquivo original após converter', 'jim-conversor-acessivel' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="jimca_settings[delete_original_after]" value="1" <?php checked( ! empty( $settings['delete_original_after'] ) ); ?>>
								<?php esc_html_e( 'Recomendado: mantém apenas o HTML convertido, sem guardar o arquivo enviado.', 'jim-conversor-acessivel' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jimca_tts_rate"><?php esc_html_e( 'Velocidade padrão da leitura em voz alta', 'jim-conversor-acessivel' ); ?></label></th>
						<td><input type="number" id="jimca_tts_rate" name="jimca_settings[tts_default_rate]" min="0.5" max="2" step="0.1" value="<?php echo esc_attr( $settings['tts_default_rate'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="jimca_theme"><?php esc_html_e( 'Tema de leitura padrão', 'jim-conversor-acessivel' ); ?></label></th>
						<td>
							<select id="jimca_theme" name="jimca_settings[theme]">
								<?php foreach ( self::get_available_themes() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['theme'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Aplicado a todos os documentos convertidos. Cada leitor ainda pode trocar o tema na hora, pela barra de leitura — a escolha dele vale só para o navegador dele, sem alterar este padrão.', 'jim-conversor-acessivel' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			</div>
		</div>
		<?php
	}
}
