<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registra o Custom Post Type usado para armazenar cada documento convertido.
 *
 * Usamos um CPT (em vez de uma tabela própria) para reaproveitar wp_posts/wp_postmeta,
 * revisões, exportação/backup nativos do WordPress e a tela de listagem do admin.
 */
class JIMCA_Post_Type {

	const POST_TYPE = 'jimca_documento';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'add_shortcode_column' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_shortcode_column' ), 10, 2 );
	}

	public function register_post_type() {
		$labels = array(
			'name'               => __( 'Documentos Acessíveis', 'jim-conversor-acessivel' ),
			'singular_name'      => __( 'Documento Acessível', 'jim-conversor-acessivel' ),
			'add_new_item'       => __( 'Adicionar Novo Documento', 'jim-conversor-acessivel' ),
			'edit_item'          => __( 'Editar Documento', 'jim-conversor-acessivel' ),
			'all_items'          => __( 'Todos os Documentos', 'jim-conversor-acessivel' ),
			'search_items'       => __( 'Buscar Documentos', 'jim-conversor-acessivel' ),
			'not_found'          => __( 'Nenhum documento convertido ainda.', 'jim-conversor-acessivel' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => $labels,
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'jimca-conversor',
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				// Bloqueia o "Adicionar Novo" nativo do WP: documentos só podem ser
				// criados pela tela de upload/conversão, nunca em branco pelo editor.
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'supports'        => array( 'title', 'editor', 'revisions' ),
				'has_archive'     => false,
				'rewrite'         => false,
				'show_in_rest'    => false,
			)
		);
	}

	public function add_shortcode_column( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'title' === $key ) {
				$new_columns['jimca_shortcode'] = __( 'Shortcode', 'jim-conversor-acessivel' );
			}
		}

		return $new_columns;
	}

	public function render_shortcode_column( $column, $post_id ) {
		if ( 'jimca_shortcode' !== $column ) {
			return;
		}

		echo '<input type="text" readonly onclick="this.select();" class="jimca-shortcode-input" style="width:100%;max-width:220px;" value="' . esc_attr( '[documento_acessivel id="' . $post_id . '"]' ) . '">';
	}

	public function add_meta_boxes() {
		add_meta_box(
			'jimca_documento_info',
			__( 'Informações da conversão', 'jim-conversor-acessivel' ),
			array( $this, 'render_info_meta_box' ),
			self::POST_TYPE,
			'side',
			'high'
		);
	}

	public function render_info_meta_box( $post ) {
		$original_filename = get_post_meta( $post->ID, '_jimca_original_filename', true );
		$original_type     = get_post_meta( $post->ID, '_jimca_original_type', true );
		$word_count        = get_post_meta( $post->ID, '_jimca_word_count', true );
		$converted_at      = get_post_meta( $post->ID, '_jimca_converted_at', true );

		echo '<p><strong>' . esc_html__( 'Arquivo original:', 'jim-conversor-acessivel' ) . '</strong><br>' . esc_html( $original_filename ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Tipo:', 'jim-conversor-acessivel' ) . '</strong> ' . esc_html( strtoupper( $original_type ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Palavras:', 'jim-conversor-acessivel' ) . '</strong> ' . esc_html( number_format_i18n( (int) $word_count ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Convertido em:', 'jim-conversor-acessivel' ) . '</strong><br>' . esc_html( $converted_at ) . '</p>';

		echo '<hr>';
		echo '<p><strong>' . esc_html__( 'Shortcode:', 'jim-conversor-acessivel' ) . '</strong></p>';
		echo '<input type="text" readonly onclick="this.select();" style="width:100%" value="' . esc_attr( '[documento_acessivel id="' . $post->ID . '"]' ) . '">';
	}
}
