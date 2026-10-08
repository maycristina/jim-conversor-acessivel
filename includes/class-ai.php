<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connection to the AI providers used by the tutor (see JIMCA_Tutor).
 *
 * Providers: OpenRouter, Claude (Anthropic), OpenAI, Gemini (Google),
 * DeepSeek, and "Other": any service that speaks the OpenAI Chat Completions
 * format at an address the administrator sets. That covers self-hosted
 * open-source models (Ollama, vLLM, LM Studio, LocalAI, llama.cpp server,
 * Hugging Face TGI) and AI gateways (LiteLLM, Portkey, Cloudflare AI
 * Gateway...). Each provider has its own key and model; one of them is the
 * active provider.
 *
 * Why direct HTTP calls and not each vendor's SDK or the WordPress AI Client:
 * the plugin runs on PHP 7.4+ and WordPress 6.x, where neither exists, and
 * the official SDKs would add heavy Composer dependencies. Requests go
 * through the WordPress HTTP API (wp_remote_post).
 *
 * Where data goes: a fixed test sentence to the provider whose "Test
 * connection" an administrator presses, and the tutor's questions to the
 * active provider. Nothing is sent otherwise. See readme.txt, "Privacy".
 *
 * How the keys are protected:
 * - They live in the `jimca_ai` option, apart from the general settings and
 *   without autoload.
 * - Each key is encrypted (libsodium `secretbox`) with a key derived from the
 *   wp-config.php salts. Someone who copies only the database cannot read
 *   them; changing the salts means typing the keys again.
 * - The settings fields are password inputs that never come pre-filled; a key
 *   is never written to the HTML (only "ending in ••••1234").
 * - Only users who can manage site options (manage_options) can change them.
 *
 * Honest limit: PHP must be able to decrypt a key to use it, so anyone with
 * the whole server (files and database) can obtain it. The encryption only
 * protects against a database-only leak (backups, dumps).
 */
class JIMCA_AI {

	const OPTION           = 'jimca_ai';
	const DEFAULT_PROVIDER = 'openrouter';
	const TEST_ACTION      = 'jimca_ai_test';
	const TEST_NONCE       = 'jimca_ai_test';

	/** Version of the Anthropic Messages API sent in the "anthropic-version" header. */
	const ANTHROPIC_VERSION = '2023-06-01';

	/**
	 * Everything that changes from one provider to another.
	 *
	 * - format: "openai" (Chat Completions: OpenRouter, OpenAI, Gemini's
	 *   OpenAI-compatible endpoint, DeepSeek) or "anthropic" (Messages API).
	 * - tokens: name of the reply-limit field ("max_completion_tokens" on
	 *   OpenAI, which refuses "max_tokens" on its newer models).
	 * - model: default model, which the administrator can change.
	 *
	 * @return array<string, array{label: string, host: string, endpoint: string, format: string, tokens: string, model: string, keys_url: string}>
	 */
	public static function provider_specs() {
		return array(
			'openrouter' => array(
				'label'    => 'OpenRouter',
				'host'     => 'openrouter.ai',
				'endpoint' => 'https://openrouter.ai/api/v1/chat/completions', // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- deliberate: works on PHP 7.4+ and WordPress 6.x, where the WordPress AI Client does not exist (see class comment).
				'format'   => 'openai',
				'tokens'   => 'max_tokens',
				'model'    => 'openrouter/free',
				'keys_url' => 'https://openrouter.ai/keys',
			),
			'claude'     => array(
				'label'    => 'Claude (Anthropic)',
				'host'     => 'api.anthropic.com',
				'endpoint' => 'https://api.anthropic.com/v1/messages', // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- deliberate: works on PHP 7.4+ and WordPress 6.x, where the WordPress AI Client does not exist (see class comment).
				'format'   => 'anthropic',
				'tokens'   => 'max_tokens',
				'model'    => 'claude-opus-5-5',
				'keys_url' => 'https://console.anthropic.com/settings/keys',
			),
			'openai'     => array(
				'label'    => 'OpenAI',
				'host'     => 'api.openai.com',
				'endpoint' => 'https://api.openai.com/v1/chat/completions', // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- deliberate: works on PHP 7.4+ and WordPress 6.x, where the WordPress AI Client does not exist (see class comment).
				'format'   => 'openai',
				'tokens'   => 'max_completion_tokens',
				'model'    => 'gpt-6-luna',
				'keys_url' => 'https://platform.openai.com/api-keys',
			),
			'gemini'     => array(
				'label'    => 'Gemini (Google)',
				'host'     => 'generativelanguage.googleapis.com',
				'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions', // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- deliberate: works on PHP 7.4+ and WordPress 6.x, where the WordPress AI Client does not exist (see class comment).
				'format'   => 'openai',
				'tokens'   => 'max_tokens',
				'model'    => 'gemini-3.8-flash',
				'keys_url' => 'https://aistudio.google.com/apikey',
			),
			'deepseek'   => array(
				'label'    => 'DeepSeek',
				'host'     => 'api.deepseek.com',
				'endpoint' => 'https://api.deepseek.com/chat/completions', // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- deliberate: works on PHP 7.4+ and WordPress 6.x, where the WordPress AI Client does not exist (see class comment).
				'format'   => 'openai',
				'tokens'   => 'max_tokens',
				'model'    => 'deepseek-flash',
				'keys_url' => 'https://platform.deepseek.com/api_keys',
			),
			// Address, name and model come from the settings (see spec()).
			'custom'     => array(
				'label'    => __( 'Other (OpenAI-compatible)', 'jim-conversor-acessivel' ),
				'host'     => '',
				'endpoint' => '',
				'format'   => 'openai',
				'tokens'   => 'max_tokens',
				'model'    => '',
				'keys_url' => '',
			),
		);
	}

	/** @return array<string, string> Code => provider name. */
	public static function providers() {
		return wp_list_pluck( self::provider_specs(), 'label' );
	}

	/**
	 * @param string $provider
	 * @return array{label: string, host: string, endpoint: string, format: string, tokens: string, model: string, keys_url: string}
	 */
	public static function spec( $provider ) {
		$specs = self::provider_specs();
		$spec  = isset( $specs[ $provider ] ) ? $specs[ $provider ] : $specs[ self::DEFAULT_PROVIDER ];

		if ( 'custom' === $provider ) {
			$entry            = self::get_settings()['providers']['custom'];
			$spec['endpoint'] = $entry['endpoint'];
			$spec['host']     = (string) wp_parse_url( $entry['endpoint'], PHP_URL_HOST );

			if ( '' !== $entry['name'] ) {
				$spec['label'] = $entry['name'];
			}
		}

		return $spec;
	}

	/** @return string Name of the active provider, as readers should see it. */
	public static function active_label() {
		return self::spec( self::get_settings()['provider'] )['label'];
	}

	/**
	 * Is a provider entry usable? The listed providers need a key; "Other"
	 * needs an address and a model, and a key only if its server asks for one
	 * (a local Ollama does not).
	 *
	 * @param string $code
	 * @param array  $entry Provider entry of the settings.
	 * @return bool
	 */
	private static function entry_ready( $code, array $entry ) {
		if ( 'custom' === $code ) {
			return '' !== $entry['endpoint'] && '' !== $entry['model'];
		}

		return '' !== $entry['key'];
	}

	/**
	 * Address of an OpenAI-compatible chat endpoint. A base address such as
	 * "http://localhost:11434/v1" gets "/chat/completions" added.
	 *
	 * @param string $url
	 * @return string '' when it is not a valid http(s) address.
	 */
	private static function normalize_endpoint( $url ) {
		$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );

		if ( '' === $url || ! wp_parse_url( $url, PHP_URL_HOST ) ) {
			return '';
		}

		$url = untrailingslashit( $url );

		return preg_match( '#/chat/completions$#', $url ) ? $url : $url . '/chat/completions';
	}

	/**
	 * Stored settings, with one entry per provider.
	 *
	 * @return array{enabled: bool, provider: string, providers: array<string, array{key: string, hint: string, model: string}>}
	 */
	public static function get_settings() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$settings = array(
			'enabled'   => ! empty( $stored['enabled'] ),
			'provider'  => isset( $stored['provider'] ) && isset( self::provider_specs()[ $stored['provider'] ] ) ? $stored['provider'] : self::DEFAULT_PROVIDER,
			'providers' => array(),
		);

		// 2.0.0 stored a single OpenRouter key at the top level: it becomes the OpenRouter entry.
		if ( ! isset( $stored['providers'] ) && isset( $stored['key'] ) ) {
			$stored['providers'] = array(
				'openrouter' => array(
					'key'   => (string) $stored['key'],
					'hint'  => isset( $stored['hint'] ) ? (string) $stored['hint'] : '',
					'model' => isset( $stored['model'] ) ? (string) $stored['model'] : '',
				),
			);
			$settings['provider'] = 'openrouter';
		}

		foreach ( self::provider_specs() as $code => $spec ) {
			$entry = isset( $stored['providers'][ $code ] ) && is_array( $stored['providers'][ $code ] ) ? $stored['providers'][ $code ] : array();

			$settings['providers'][ $code ] = array(
				'key'      => isset( $entry['key'] ) ? (string) $entry['key'] : '',
				'hint'     => isset( $entry['hint'] ) ? (string) $entry['hint'] : '',
				'model'    => ! empty( $entry['model'] ) ? (string) $entry['model'] : $spec['model'],
				'name'     => isset( $entry['name'] ) ? (string) $entry['name'] : '',
				'endpoint' => isset( $entry['endpoint'] ) ? (string) $entry['endpoint'] : '',
			);
		}

		return $settings;
	}

	/**
	 * Is there a stored key for this provider (even if it can't be decrypted yet)?
	 *
	 * @param string|null $provider null = the active provider.
	 * @return bool
	 */
	public static function has_key( $provider = null ) {
		$settings = self::get_settings();
		$provider = null === $provider ? $settings['provider'] : $provider;

		return isset( $settings['providers'][ $provider ] ) && '' !== $settings['providers'][ $provider ]['key'];
	}

	/**
	 * Is the assistant on, with a usable provider?
	 *
	 * @return bool
	 */
	public static function is_active() {
		$settings = self::get_settings();
		$provider = $settings['provider'];

		if ( ! $settings['enabled'] ) {
			return false;
		}

		return 'custom' === $provider
			? self::entry_ready( 'custom', $settings['providers']['custom'] )
			: '' !== self::get_key( $provider );
	}

	/**
	 * A key in plain text, for the plugin's internal use. Never print it.
	 *
	 * @param string|null $provider null = the active provider.
	 * @return string '' when there is no key or it cannot be decrypted
	 *                (salts changed, corrupted data).
	 */
	public static function get_key( $provider = null ) {
		$settings = self::get_settings();
		$provider = null === $provider ? $settings['provider'] : $provider;

		if ( ! isset( $settings['providers'][ $provider ] ) ) {
			return '';
		}

		$stored = $settings['providers'][ $provider ]['key'];

		if ( '' === $stored || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return '';
		}

		$raw = base64_decode( $stored, true );

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::secret() );

		return false === $plain ? '' : $plain;
	}

	/**
	 * The encryption key comes from the site salts, not from the database.
	 *
	 * @return string 32 bytes.
	 */
	private static function secret() {
		return sodium_crypto_generichash( wp_salt( 'secure_auth' ) . 'jimca-ai', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * @param string $plain
	 * @return string Encrypted text in base64, or '' if it was not possible.
	 */
	private static function encrypt( $plain ) {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return '';
		}

		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::secret() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- packs encrypted bytes to store as text; it is not obfuscation.
	}

	/**
	 * Validates and stores the Settings form (register_setting callback).
	 *
	 * Expected input: enabled, provider and, per provider,
	 * providers[code][model|api_key|clear_key].
	 *
	 * @param mixed $input Submitted data.
	 * @return array Option value.
	 */
	public static function sanitize( $input ) {
		/*
		 * WordPress can run this callback twice for one save (update_option
		 * falls back to add_option when the stored value equals the default),
		 * feeding the second call with the first call's result. That result
		 * has no "api_key" fields, so without this guard the new keys are lost.
		 */
		static $last_result = null;

		if ( null !== $last_result && $input === $last_result ) {
			return $last_result;
		}

		$current = self::get_settings();
		$input   = is_array( $input ) ? $input : array();
		$output  = $current;

		if ( isset( $input['provider'] ) && isset( self::provider_specs()[ $input['provider'] ] ) ) {
			$output['provider'] = $input['provider'];
		}

		$submitted = isset( $input['providers'] ) && is_array( $input['providers'] ) ? $input['providers'] : array();

		foreach ( self::provider_specs() as $code => $spec ) {
			$fields = isset( $submitted[ $code ] ) && is_array( $submitted[ $code ] ) ? $submitted[ $code ] : array();
			$entry  = $current['providers'][ $code ];

			if ( 'custom' === $code ) {
				if ( isset( $fields['name'] ) ) {
					$entry['name'] = mb_substr( sanitize_text_field( (string) $fields['name'] ), 0, 60 );
				}

				if ( isset( $fields['endpoint'] ) ) {
					$endpoint = trim( (string) $fields['endpoint'] );
					$entry['endpoint'] = self::normalize_endpoint( $endpoint );

					if ( '' !== $endpoint && '' === $entry['endpoint'] ) {
						add_settings_error(
							self::OPTION,
							'jimca_ai_endpoint',
							__( 'Other provider: the address was not saved. Use a full http:// or https:// address, such as http://localhost:11434/v1.', 'jim-conversor-acessivel' ),
							'error'
						);
					}
				}
			}

			// Model names look like "vendor/name", "name-1.5" or "vendor/name:free"; anything else is refused.
			$model = isset( $fields['model'] ) ? trim( (string) $fields['model'] ) : $entry['model'];

			if ( '' === $model ) {
				$entry['model'] = $spec['model'];
			} elseif ( strlen( $model ) <= 100 && preg_match( '#^[A-Za-z0-9._\-]+(/[A-Za-z0-9._\-]+)*(:[A-Za-z0-9._\-]+)?$#', $model ) ) {
				$entry['model'] = $model;
			} else {
				add_settings_error(
					self::OPTION,
					'jimca_ai_model_format_' . $code,
					sprintf(
						/* translators: %s: provider name */
						__( '%s: the model was not changed. Use only letters, numbers and the symbols . _ - / : (no spaces).', 'jim-conversor-acessivel' ),
						$spec['label']
					),
					'error'
				);
			}

			if ( ! empty( $fields['clear_key'] ) ) {
				$entry['key']  = '';
				$entry['hint'] = '';
			}

			// options.php already strips the escape slashes from submitted data.
			$new_key = isset( $fields['api_key'] ) ? trim( (string) $fields['api_key'] ) : '';

			if ( '' !== $new_key ) {
				if ( ! preg_match( '/^[A-Za-z0-9_\-.:]{16,300}$/', $new_key ) ) {
					add_settings_error(
						self::OPTION,
						'jimca_ai_key_format_' . $code,
						sprintf(
							/* translators: %s: provider name */
							__( '%s: the key was not saved. It must be 16 to 300 characters long, with only letters, numbers and the symbols _ - . : (no spaces).', 'jim-conversor-acessivel' ),
							$spec['label']
						),
						'error'
					);
				} else {
					$encrypted = self::encrypt( $new_key );

					if ( '' === $encrypted ) {
						add_settings_error(
							self::OPTION,
							'jimca_ai_key_crypto',
							__( 'The key was not saved: this server lacks the encryption (libsodium) needed to store it safely.', 'jim-conversor-acessivel' ),
							'error'
						);
					} else {
						$entry['key']  = $encrypted;
						$entry['hint'] = substr( $new_key, -4 );
					}
				}
			}

			$output['providers'][ $code ] = $entry;
		}

		// Without a usable active provider the assistant cannot stay on.
		$output['enabled'] = ! empty( $input['enabled'] ) && self::entry_ready( $output['provider'], $output['providers'][ $output['provider'] ] );

		$last_result = $output;

		return $output;
	}

	/**
	 * Sends a chat request to a provider.
	 *
	 * @param array<int, array{role: string, content: string}> $messages   Chat messages ("system", "user", "assistant").
	 * @param int                                              $max_tokens Reply limit.
	 * @param string|null                                      $provider   null = the active provider.
	 * @return array{content: string, model: string, status: int}|WP_Error
	 */
	public static function request( array $messages, $max_tokens = 300, $provider = null ) {
		$call = self::build_call( $messages, $max_tokens, $provider );

		if ( is_wp_error( $call ) ) {
			return $call;
		}

		list( $spec, $model, $headers, $body ) = $call;

		$response = wp_remote_post(
			$spec['endpoint'],
			array(
				'timeout' => 60,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'jimca_ai_http', $spec['label'] . ': ' . $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status || ! is_array( $data ) || isset( $data['error'] ) ) {
			return new WP_Error(
				'jimca_ai_api',
				sprintf(
					/* translators: 1: provider name, 2: HTTP status code, 3: message returned by the service */
					__( '%1$s answered with an error (HTTP %2$d): %3$s', 'jim-conversor-acessivel' ),
					$spec['label'],
					$status,
					self::error_message( $data )
				)
			);
		}

		$content = self::reply_text( $data, $spec['format'] );

		if ( null === $content ) {
			return new WP_Error(
				'jimca_ai_empty',
				sprintf(
					/* translators: %s: provider name */
					__( '%s answered without any reply.', 'jim-conversor-acessivel' ),
					$spec['label']
				)
			);
		}

		return array(
			'content' => $content,
			'model'   => isset( $data['model'] ) && is_string( $data['model'] ) ? $data['model'] : $model,
			'status'  => $status,
		);
	}

	/**
	 * Endpoint, headers and body of a chat request, in the provider's format.
	 *
	 * @param array<int, array{role: string, content: string}> $messages
	 * @param int                                              $max_tokens
	 * @param string|null                                      $provider null = the active provider.
	 * @return array{0: array, 1: string, 2: array, 3: array}|WP_Error Spec, model, headers, body.
	 */
	private static function build_call( array $messages, $max_tokens, $provider ) {
		$settings = self::get_settings();
		$provider = null === $provider ? $settings['provider'] : $provider;
		$spec     = self::spec( $provider );
		$key      = self::get_key( $provider );

		if ( 'custom' === $provider && ! self::entry_ready( 'custom', $settings['providers']['custom'] ) ) {
			return new WP_Error( 'jimca_ai_no_endpoint', __( 'Other provider: set the address and the model first.', 'jim-conversor-acessivel' ) );
		}

		if ( '' === $key && 'custom' !== $provider ) {
			return new WP_Error(
				'jimca_ai_no_key',
				sprintf(
					/* translators: %s: provider name */
					__( '%s: there is no usable API key. Save the key again.', 'jim-conversor-acessivel' ),
					$spec['label']
				)
			);
		}

		$model      = $settings['providers'][ $provider ]['model'];
		$max_tokens = max( 1, (int) $max_tokens );

		if ( 'anthropic' === $spec['format'] ) {
			// The Messages API takes the system prompt as a separate field.
			$system = array();
			$chat   = array();

			foreach ( $messages as $message ) {
				if ( 'system' === $message['role'] ) {
					$system[] = $message['content'];
				} else {
					$chat[] = $message;
				}
			}

			$body = array(
				'model'      => $model,
				'max_tokens' => $max_tokens,
				'messages'   => $chat,
			);

			if ( $system ) {
				$body['system'] = implode( "\n\n", $system );
			}

			$headers = array(
				'x-api-key'         => $key,
				'anthropic-version' => self::ANTHROPIC_VERSION,
				'Content-Type'      => 'application/json',
			);
		} else {
			$body = array(
				'model'         => $model,
				$spec['tokens'] => $max_tokens,
				'messages'      => $messages,
			);

			$headers = array( 'Content-Type' => 'application/json' );

			// Self-hosted servers often need no key.
			if ( '' !== $key ) {
				$headers['Authorization'] = 'Bearer ' . $key;
			}
		}

		return array( $spec, $model, $headers, $body );
	}

	/**
	 * Can this server stream replies? It needs PHP's cURL extension: the
	 * WordPress HTTP API only returns a response once it is complete.
	 *
	 * @return bool
	 */
	public static function can_stream() {
		return function_exists( 'curl_init' );
	}

	/**
	 * Sends a chat request and hands over the reply piece by piece as the
	 * model writes it, so the reader starts reading in about a second instead
	 * of waiting for the whole answer.
	 *
	 * Uses cURL directly because the WordPress HTTP API cannot read a response
	 * while it arrives. Same endpoints, keys and formats as request().
	 *
	 * @param array<int, array{role: string, content: string}> $messages
	 * @param int                                              $max_tokens
	 * @param callable                                         $on_text  Receives each new piece of text.
	 * @param string|null                                      $provider null = the active provider.
	 * @return array{content: string, model: string}|WP_Error
	 */
	public static function stream( array $messages, $max_tokens, $on_text, $provider = null ) {
		$call = self::build_call( $messages, $max_tokens, $provider );

		if ( is_wp_error( $call ) ) {
			return $call;
		}

		list( $spec, $model, $headers, $body ) = $call;

		$body['stream'] = true;
		$lines          = array();

		foreach ( $headers as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}

		$status  = 0;
		$buffer  = '';
		$content = '';
		$raw     = '';
		$all     = '';
		$format  = $spec['format'];

		// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming needs cURL; the WordPress HTTP API cannot read a response while it arrives.
		$curl = curl_init( $spec['endpoint'] );
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
				CURLOPT_HTTPHEADER     => $lines,
				CURLOPT_TIMEOUT        => 120,
				CURLOPT_CONNECTTIMEOUT => 15,
				CURLOPT_HEADERFUNCTION => static function ( $handle, $header ) use ( &$status ) {
					if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $header, $match ) ) {
						$status = (int) $match[1];
					}
					return strlen( $header );
				},
				CURLOPT_WRITEFUNCTION  => static function ( $handle, $chunk ) use ( &$status, &$buffer, &$content, &$raw, &$all, &$model, $format, $on_text ) {
					if ( 200 !== $status ) {
						$raw .= $chunk;
						return strlen( $chunk );
					}

					if ( strlen( $all ) < MB_IN_BYTES ) {
						$all .= $chunk;
					}

					$buffer .= $chunk;

					// Server-sent events: one "data: {...}" per line.
					while ( false !== ( $end = strpos( $buffer, "\n" ) ) ) {
						$line   = trim( substr( $buffer, 0, $end ) );
						$buffer = substr( $buffer, $end + 1 );

						if ( 0 !== strpos( $line, 'data:' ) ) {
							continue;
						}

						$event = json_decode( trim( substr( $line, 5 ) ), true );

						if ( ! is_array( $event ) ) {
							continue;
						}

						if ( isset( $event['model'] ) && is_string( $event['model'] ) ) {
							$model = $event['model'];
						} elseif ( isset( $event['message']['model'] ) && is_string( $event['message']['model'] ) ) {
							$model = $event['message']['model'];
						}

						if ( isset( $event['error'] ) ) {
							$raw = wp_json_encode( $event );
							continue;
						}

						$piece = 'anthropic' === $format
							? ( isset( $event['delta']['text'] ) && is_string( $event['delta']['text'] ) ? $event['delta']['text'] : '' )
							: ( isset( $event['choices'][0]['delta']['content'] ) && is_string( $event['choices'][0]['delta']['content'] ) ? $event['choices'][0]['delta']['content'] : '' );

						if ( '' !== $piece ) {
							$content .= $piece;
							call_user_func( $on_text, $piece );
						}
					}

					return strlen( $chunk );
				},
			)
		);

		$ok    = curl_exec( $curl );
		$error = curl_error( $curl );
		curl_close( $curl );
		// phpcs:enable WordPress.WP.AlternativeFunctions

		// Some compatible servers ignore "stream" and send the whole reply at once.
		if ( 200 === $status && '' === $content && '' !== trim( $all ) ) {
			$whole = json_decode( $all, true );

			if ( is_array( $whole ) && ! isset( $whole['error'] ) ) {
				$content = (string) self::reply_text( $whole, $format );

				if ( isset( $whole['model'] ) && is_string( $whole['model'] ) ) {
					$model = $whole['model'];
				}

				if ( '' !== $content ) {
					call_user_func( $on_text, $content );
				}
			} elseif ( is_array( $whole ) ) {
				$raw = $all;
			}
		}

		if ( false === $ok && '' === $content ) {
			return new WP_Error( 'jimca_ai_http', $spec['label'] . ': ' . $error );
		}

		if ( 200 !== $status || ( '' === $content && '' !== $raw ) ) {
			return new WP_Error(
				'jimca_ai_api',
				sprintf(
					/* translators: 1: provider name, 2: HTTP status code, 3: message returned by the service */
					__( '%1$s answered with an error (HTTP %2$d): %3$s', 'jim-conversor-acessivel' ),
					$spec['label'],
					$status,
					self::error_message( json_decode( $raw, true ) )
				)
			);
		}

		if ( '' === $content ) {
			return new WP_Error(
				'jimca_ai_empty',
				sprintf(
					/* translators: %s: provider name */
					__( '%s answered without any reply.', 'jim-conversor-acessivel' ),
					$spec['label']
				)
			);
		}

		return array(
			'content' => $content,
			'model'   => $model,
		);
	}

	/**
	 * Text of the reply, in either format.
	 *
	 * @param array  $data   Decoded JSON body.
	 * @param string $format "openai" or "anthropic".
	 * @return string|null null when the reply has no message at all.
	 */
	private static function reply_text( array $data, $format ) {
		if ( 'anthropic' === $format ) {
			if ( ! isset( $data['content'] ) || ! is_array( $data['content'] ) ) {
				return null;
			}

			// The reply is a list of blocks (thinking, text...): only the text blocks are the answer.
			$text = '';

			foreach ( $data['content'] as $block ) {
				if ( is_array( $block ) && isset( $block['type'], $block['text'] ) && 'text' === $block['type'] && is_string( $block['text'] ) ) {
					$text .= $block['text'];
				}
			}

			return $text;
		}

		if ( empty( $data['choices'] ) || ! is_array( $data['choices'] ) ) {
			return null;
		}

		return isset( $data['choices'][0]['message']['content'] ) && is_string( $data['choices'][0]['message']['content'] )
			? $data['choices'][0]['message']['content']
			: '';
	}

	/**
	 * Error message sent by the service. All five use `error.message`;
	 * Gemini sometimes wraps the body in a list.
	 *
	 * @param mixed $data Decoded JSON body.
	 * @return string
	 */
	private static function error_message( $data ) {
		if ( is_array( $data ) && isset( $data[0] ) && is_array( $data[0] ) ) {
			$data = $data[0];
		}

		if ( is_array( $data ) && isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
			return $data['error']['message'];
		}

		if ( is_array( $data ) && isset( $data['error'] ) && is_string( $data['error'] ) ) {
			return $data['error'];
		}

		return __( 'unexpected reply', 'jim-conversor-acessivel' );
	}

	/* ---------------------------------------------------------------
	 * "Test connection" button
	 * ------------------------------------------------------------- */

	public static function register_hooks() {
		add_action( 'admin_post_' . self::TEST_ACTION, array( __CLASS__, 'handle_test' ) );
	}

	/**
	 * Sends a fixed one-line prompt (no document text) to the chosen provider
	 * and reports the result as an admin notice on the Settings screen.
	 */
	public static function handle_test() {
		if ( ! current_user_can( JIMCA_Admin::CAPABILITY_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'jim-conversor-acessivel' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::TEST_NONCE );

		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : self::DEFAULT_PROVIDER;

		if ( ! isset( self::provider_specs()[ $provider ] ) ) {
			$provider = self::DEFAULT_PROVIDER;
		}

		$started = microtime( true );

		// 1024 tokens: models that reason before answering need room to reach the word "OK".
		$result = self::request(
			array(
				array(
					'role'    => 'user',
					'content' => 'Reply with the single word OK.',
				),
			),
			1024,
			$provider
		);

		$metrics = JIMCA_Log::metrics( $started );

		if ( is_wp_error( $result ) ) {
			JIMCA_Log::add(
				'error',
				'ai.test',
				$result->get_error_message(),
				array(
					'provider' => $provider,
					'ms'       => $metrics['ms'],
				)
			);
			$notice = array( 'error', esc_html( $result->get_error_message() ) );
		} else {
			JIMCA_Log::add(
				'info',
				'ai.test',
				'Connection test succeeded.',
				array(
					'provider' => $provider,
					'model'    => $result['model'],
					'ms'       => $metrics['ms'],
				)
			);
			$notice = array(
				'success',
				sprintf(
					/* translators: 1: provider name, 2: name of the model that answered */
					esc_html__( '%1$s: connection working. Model that answered: %2$s.', 'jim-conversor-acessivel' ),
					esc_html( self::spec( $provider )['label'] ),
					'<code>' . esc_html( $result['model'] ) . '</code>'
				),
			);
		}

		JIMCA_Admin::get_instance()->flash_notice( $notice[0], $notice[1] );

		wp_safe_redirect( admin_url( 'admin.php?page=' . JIMCA_Admin::MENU_SLUG . '-settings#jimca-ai' ) );
		exit;
	}
}
