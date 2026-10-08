<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Suggested privacy-policy text for the site owner.
 *
 * Shows up under Settings > Privacy > "Policy Guide" (WordPress core screen),
 * where the owner can copy it into the site's privacy policy page.
 */
class JIMCA_Privacy {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init() {
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	public function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content( 'Jim - Accessible Converter', wp_kses_post( wpautop( $this->content(), false ) ) );
	}

	/**
	 * @return string HTML.
	 */
	private function content() {
		$html  = '<h2>' . esc_html__( 'Documents converted with Jim', 'jim-conversor-acessivel' ) . '</h2>';
		$html .= '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text. Review it and adjust it to how you use the plugin.', 'jim-conversor-acessivel' ) . '</p>';

		$html .= '<h3>' . esc_html__( 'What this site stores', 'jim-conversor-acessivel' ) . '</h3>';
		$html .= '<p>' . esc_html__( 'Files that administrators upload are converted on this server. By default the original file is deleted after conversion; the converted text, its images (in the Media Library) and details such as file name, type, word count and conversion date stay in the site database. The plugin does not collect personal data about visitors and does not set cookies.', 'jim-conversor-acessivel' ) . '</p>';
		$html .= '<p>' . esc_html__( 'The plugin keeps a short activity log (last 200 entries) for administrators, with file names, times, memory use and errors. It never records document text. It can be cleared or exported from the plugin settings.', 'jim-conversor-acessivel' ) . '</p>';

		$html .= '<h3>' . esc_html__( 'What stays in the reader\'s browser', 'jim-conversor-acessivel' ) . '</h3>';
		$html .= '<p>' . esc_html__( 'To remember each reader\'s choices, the reading controls and the highlighter use the browser\'s local storage, per document: text size, theme, contrast, voice, reading speed, whether the toolbar is hidden, and the passages the reader highlighted. This data never leaves the reader\'s device and can be deleted by clearing the browser\'s site data.', 'jim-conversor-acessivel' ) . '</p>';
		$html .= '<p>' . esc_html__( 'Reading aloud uses the browser\'s built-in speech feature. The plugin does not send the text anywhere. Depending on the browser and the voice chosen, the browser itself may process speech on its vendor\'s servers.', 'jim-conversor-acessivel' ) . '</p>';

		$html .= '<h3>' . esc_html__( 'Services the site may contact', 'jim-conversor-acessivel' ) . '</h3>';
		$html .= '<p>' . esc_html__( 'WordPress.org: if the [jimca_instalacoes] shortcode is used, the server asks api.wordpress.org for this plugin\'s install count (only the plugin name is sent; the answer is cached for 12 hours). Visitors\' browsers do not contact it.', 'jim-conversor-acessivel' ) . '</p>';
		$html .= '<p>' . esc_html__( 'AI providers (OpenRouter, Anthropic, OpenAI, Google Gemini, DeepSeek): if an administrator presses a provider\'s "Test connection" button, the server sends it one fixed test sentence. If the AI tutor is on, each question a visitor asks, the last turns of that visitor\'s conversation and passages of the document are sent to the provider chosen by the site, which writes the answer. The visitor\'s IP address is not sent; it is kept only as a salted hash, for one hour, to limit the number of questions. The conversation stays in the visitor\'s browser until the tab is closed. Voice questions use the browser\'s speech recognition, which in some browsers sends the audio to the browser vendor.', 'jim-conversor-acessivel' ) . '</p>';

		$html .= '<p>' . esc_html__( 'If the site uses its own AI service ("Other" provider, such as an open-source model on its own server or an AI gateway), the data above goes to the address set by the site instead; when that model runs on the site\'s own server, it does not leave it.', 'jim-conversor-acessivel' ) . '</p>';
		$html .= '<p>' . esc_html__( 'To answer repeated questions instantly, the tutor may keep its answers on this site, per document. With each saved answer it keeps only the weight of each word of the question, not the question text, and nothing that identifies the visitor. Saved answers are discarded when the document or the tutor settings change.', 'jim-conversor-acessivel' ) . '</p>';

		return $html;
	}
}
