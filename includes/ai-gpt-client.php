<?php
/**
 * AI client for multi-provider API requests
 * Handles OpenAI, Google Gemini, Claude, and ShareAI integrations
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'aiohm_kb_normalize_wp_ai_provider_id' ) ) {
	/**
	 * Normalize WordPress Connector provider IDs to AIOHM provider IDs.
	 *
	 * @param string $provider_id Connector or provider ID.
	 * @param array  $connector   Optional connector metadata.
	 * @return string Normalized provider ID.
	 */
	function aiohm_kb_normalize_wp_ai_provider_id( $provider_id, $connector = array() ) {
		if ( null === $provider_id || '' === (string) $provider_id ) {
			return '';
		}

		$haystack_parts = array( (string) $provider_id );
		if ( is_array( $connector ) ) {
			foreach ( array( 'name', 'description' ) as $field ) {
				if ( ! empty( $connector[ $field ] ) && is_string( $connector[ $field ] ) ) {
					$haystack_parts[] = $connector[ $field ];
				}
			}
		}

		$haystack = strtolower( implode( ' ', $haystack_parts ) );
		if ( false !== strpos( $haystack, 'google' ) || false !== strpos( $haystack, 'gemini' ) ) {
			return 'gemini';
		}
		if ( false !== strpos( $haystack, 'anthropic' ) || false !== strpos( $haystack, 'claude' ) ) {
			return 'claude';
		}
		if ( false !== strpos( $haystack, 'openai' ) || false !== strpos( $haystack, 'gpt' ) ) {
			return 'openai';
		}
		if ( false !== strpos( $haystack, 'groq' ) ) {
			return 'groq';
		}
		if ( false !== strpos( $haystack, 'shareai' ) ) {
			return 'shareai';
		}
		if ( false !== strpos( $haystack, 'ollama' ) ) {
			return 'ollama';
		}

		return sanitize_key( $provider_id );
	}
}

if ( ! function_exists( 'aiohm_kb_get_wp_connector_api_key' ) ) {
	/**
	 * Get a configured API key from WordPress 7.0 Connectors.
	 *
	 * @param string $provider AIOHM provider ID.
	 * @return string API key if configured, empty string otherwise.
	 */
	function aiohm_kb_get_wp_connector_api_key( $provider ) {
		$provider       = aiohm_kb_normalize_wp_ai_provider_id( $provider );
		$env_names      = array();
		$constant_names = array();
		$option_names   = array();

		$defaults = array(
			'openai' => array(
				'env'     => array( 'OPENAI_API_KEY' ),
				'const'   => array( 'OPENAI_API_KEY' ),
				'options' => array( 'connectors_ai_openai_api_key' ),
			),
			'gemini' => array(
				'env'     => array( 'GOOGLE_API_KEY', 'GEMINI_API_KEY' ),
				'const'   => array( 'GOOGLE_API_KEY', 'GEMINI_API_KEY' ),
				'options' => array( 'connectors_ai_google_api_key', 'connectors_ai_gemini_api_key' ),
			),
			'claude' => array(
				'env'     => array( 'ANTHROPIC_API_KEY', 'CLAUDE_API_KEY' ),
				'const'   => array( 'ANTHROPIC_API_KEY', 'CLAUDE_API_KEY' ),
				'options' => array( 'connectors_ai_anthropic_api_key', 'connectors_ai_claude_api_key' ),
			),
		);

		if ( isset( $defaults[ $provider ] ) ) {
			$env_names      = array_merge( $env_names, $defaults[ $provider ]['env'] );
			$constant_names = array_merge( $constant_names, $defaults[ $provider ]['const'] );
			$option_names   = array_merge( $option_names, $defaults[ $provider ]['options'] );
		}

		if ( function_exists( 'wp_get_connectors' ) ) {
			foreach ( wp_get_connectors() as $connector_id => $connector ) {
				if ( $provider !== aiohm_kb_normalize_wp_ai_provider_id( $connector_id, $connector ) ) {
					continue;
				}

				$auth = isset( $connector['authentication'] ) && is_array( $connector['authentication'] )
					? $connector['authentication']
					: array();

				if ( ! empty( $auth['env_var_name'] ) ) {
					$env_names[] = $auth['env_var_name'];
				}
				if ( ! empty( $auth['constant_name'] ) ) {
					$constant_names[] = $auth['constant_name'];
				}
				if ( ! empty( $auth['setting_name'] ) ) {
					$option_names[] = $auth['setting_name'];
				}
			}
		}

		foreach ( array_unique( array_filter( $env_names ) ) as $env_name ) {
			$value = getenv( $env_name );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		foreach ( array_unique( array_filter( $constant_names ) ) as $constant_name ) {
			if ( defined( $constant_name ) && is_string( constant( $constant_name ) ) && '' !== trim( constant( $constant_name ) ) ) {
				return trim( constant( $constant_name ) );
			}
		}

		foreach ( array_unique( array_filter( $option_names ) ) as $option_name ) {
			$value = get_option( $option_name, '' );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		$legacy_credentials = get_option( 'wp_ai_client_provider_credentials', array() );
		if ( is_array( $legacy_credentials ) ) {
			$legacy_ids = array(
				'openai' => array( 'openai' ),
				'gemini' => array( 'google', 'gemini' ),
				'claude' => array( 'anthropic', 'claude' ),
			);

			foreach ( $legacy_ids[ $provider ] ?? array() as $legacy_id ) {
				if ( ! empty( $legacy_credentials[ $legacy_id ] ) && is_string( $legacy_credentials[ $legacy_id ] ) ) {
					return trim( $legacy_credentials[ $legacy_id ] );
				}
			}
		}

		return '';
	}
}

if ( ! function_exists( 'aiohm_kb_is_wp_ai_connector_configured' ) ) {
	/**
	 * Check whether a WordPress 7.0 Connector provider is configured.
	 *
	 * @param string $provider AIOHM provider ID.
	 * @return bool True when the provider has a connector API key.
	 */
	function aiohm_kb_is_wp_ai_connector_configured( $provider ) {
		return '' !== aiohm_kb_get_wp_connector_api_key( $provider );
	}
}

class AIOHM_KB_AI_GPT_Client {

	private $settings;
	private $openai_api_key;
	private $gemini_api_key;
	private $claude_api_key;
	private $shareai_api_key;
	private $groq_api_key;
	private $ollama_server_url;
	private $ollama_model;

	public function __construct( $settings = null ) {
		if ( $settings === null ) {
			$this->settings = AIOHM_KB_Assistant::get_settings();
		} else {
			$this->settings = $settings;
		}
		$this->openai_api_key  = $this->settings['openai_api_key'] ?? '';
		$this->gemini_api_key  = $this->settings['gemini_api_key'] ?? '';
		$this->claude_api_key  = $this->settings['claude_api_key'] ?? '';
		if ( empty( $this->openai_api_key ) ) {
			$this->openai_api_key = aiohm_kb_get_wp_connector_api_key( 'openai' );
		}
		if ( empty( $this->gemini_api_key ) ) {
			$this->gemini_api_key = aiohm_kb_get_wp_connector_api_key( 'gemini' );
		}
		if ( empty( $this->claude_api_key ) ) {
			$this->claude_api_key = aiohm_kb_get_wp_connector_api_key( 'claude' );
		}
		$this->shareai_api_key = $this->settings['shareai_api_key'] ?? '';
		$this->groq_api_key    = $this->settings['groq_api_key'] ?? '';

		// Ollama settings - check both pro_features and legacy settings
		if (isset($this->settings['pro_features']['ollama_server_url'])) {
			$this->ollama_server_url = $this->settings['pro_features']['ollama_server_url'];
		} else {
			$this->ollama_server_url = $this->settings['private_llm_server_url'] ?? '';
		}

		if (isset($this->settings['pro_features']['ollama_model'])) {
			$this->ollama_model = $this->settings['pro_features']['ollama_model'];
		} else {
			$this->ollama_model = $this->settings['private_llm_model'] ?? 'llama3.2:latest';
		}
	}

	/**
	 * Check if API key is properly configured for the given provider
	 *
	 * @param string $provider The AI provider (openai, gemini, claude, shareai, groq, ollama)
	 * @return bool True if API key is configured
	 */
	public function is_api_key_configured( $provider ) {
		switch ( $provider ) {
			case 'openai':
				return ! empty( $this->openai_api_key );
			case 'gemini':
				return ! empty( $this->gemini_api_key );
			case 'claude':
				return ! empty( $this->claude_api_key );
			case 'shareai':
				return ! empty( $this->shareai_api_key );
			case 'groq':
				return ! empty( $this->groq_api_key );
			case 'ollama':
				return ! empty( $this->ollama_server_url );
			default:
				return false;
		}
	}

	/**
	 * Check rate limit for API calls with IP-based fallback
	 *
	 * @param string $provider The AI provider
	 * @return bool True if within rate limit
	 */
	private function check_rate_limit( $provider ) {
		$user_id      = get_current_user_id();
		$user_ip      = $this->get_client_ip();
		$max_requests = 100;

		$user_key   = "aiohm_rate_limit_{$provider}_user_{$user_id}";
		$user_count = get_transient( $user_key );

		$ip_key   = "aiohm_rate_limit_{$provider}_ip_" . md5( $user_ip );
		$ip_count = get_transient( $ip_key );

		if ( $user_count === false ) {
			set_transient( $user_key, 1, HOUR_IN_SECONDS );
			$user_count = 1;
		}

		if ( $ip_count === false ) {
			set_transient( $ip_key, 1, HOUR_IN_SECONDS );
			$ip_count = 1;
		}

		if ( $user_count >= $max_requests || $ip_count >= $max_requests ) {
			return false;
		}

		set_transient( $user_key, $user_count + 1, HOUR_IN_SECONDS );
		set_transient( $ip_key, $ip_count + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Check if user has consented to ShareAI external service
	 * WordPress.org compliance requirement
	 *
	 * @return bool True if user has consented
	 */
	private function has_shareai_consent() {
		// Check if AIOHM_DISABLE_REMOTE is defined to force disable
		if ( defined( 'AIOHM_DISABLE_REMOTE' ) && AIOHM_DISABLE_REMOTE ) {
			return false;
		}

		// Check for user consent setting - use the main external_api_consent setting
		$consent = $this->settings['external_api_consent'] ?? false;
		return (bool) $consent;
	}

	/**
	 * Get client IP address securely
	 *
	 * @return string Client IP address
	 */
	private function get_client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '127.0.0.1';

		if ( defined( 'AIOHM_TRUST_PROXY_HEADERS' ) && AIOHM_TRUST_PROXY_HEADERS === true ) {
			$trusted_headers = array(
				'HTTP_CF_CONNECTING_IP',     // Cloudflare
				'HTTP_X_FORWARDED_FOR',      // Load balancer/proxy (most common)
				'HTTP_X_REAL_IP',            // Nginx proxy
				'HTTP_CLIENT_IP',             // Less common proxy header
			);

			foreach ( $trusted_headers as $header ) {
				if ( ! empty( $_SERVER[ $header ] ) ) {
					$header_ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );

					if ( strpos( $header_ip, ',' ) !== false ) {
						$header_ip = trim( explode( ',', $header_ip )[0] );
					}

					if ( filter_var( $header_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
						$ip = $header_ip;
						break;
					}
				}
			}
		}

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = '127.0.0.1';
		}

		return $ip;
	}

	private function sanitize_text_for_json( $text ) {
		if ( is_string( $text ) ) {
			return mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		}
		return $text;
	}

	/**
	 * Sanitize error messages to prevent API key exposure
	 *
	 * @param string $error_message Raw error message
	 * @param string $context Context for logging
	 * @return string Safe error message for display
	 */
	private function get_safe_error_message( $error_message, $context = 'API' ) {
		if ( strpos( strtolower( $error_message ), 'unauthorized' ) !== false ||
			strpos( strtolower( $error_message ), 'invalid' ) !== false ) {
			return __( 'API authentication failed. Please check your API key configuration.', 'aiohm-knowledge-assistant' );
		}

		if ( strpos( strtolower( $error_message ), 'rate limit' ) !== false ||
			strpos( strtolower( $error_message ), 'quota' ) !== false ) {
			return __( 'API rate limit exceeded. Please try again later.', 'aiohm-knowledge-assistant' );
		}

		if ( strpos( strtolower( $error_message ), 'timeout' ) !== false ) {
			return __( 'Request timeout. Please try again.', 'aiohm-knowledge-assistant' );
		}

		return __( 'AI service temporarily unavailable. Please try again later.', 'aiohm-knowledge-assistant' );
	}

	/**
	 * Sanitize data for logging to prevent API key exposure
	 *
	 * @param mixed $data Data to sanitize
	 * @return mixed Sanitized data
	 */
	private function sanitize_for_log( $data ) {
		if ( is_string( $data ) ) {
			$patterns = array(
				'/sk-[a-zA-Z0-9_-]{20,}/'                => 'OPENAI_API_KEY_HIDDEN',
				'/pk-[a-zA-Z0-9_-]{20,}/'                => 'OPENAI_PUBLIC_KEY_HIDDEN',
				'/sk-ant-[a-zA-Z0-9_-]{90,}/'            => 'CLAUDE_API_KEY_HIDDEN',
				'/AIza[a-zA-Z0-9_-]{35}/'                => 'GEMINI_API_KEY_HIDDEN',
				'/[a-zA-Z0-9_-]{32,}/'                   => 'POTENTIAL_API_KEY_HIDDEN',
				'/"(api[_-]?key|apikey|key|token|secret)"\s*:\s*"[^"]{10,}"/' => '"$1":"API_KEY_HIDDEN"',
				'/Authorization:\s*Bearer\s+[^\s]{10,}/' => 'Authorization: Bearer API_KEY_HIDDEN',
				'/Authorization:\s*[a-zA-Z0-9_-]{20,}/'  => 'Authorization: API_KEY_HIDDEN',
				'/(password|token|secret|key)["\']?\s*[:=]\s*["\'][^"\']{5,}["\']/' => '$1: "SENSITIVE_DATA_HIDDEN"',
				'/[A-Za-z0-9+\/]{50,}={0,2}/'            => 'BASE64_DATA_HIDDEN',
				'/api[_-]?key=([a-zA-Z0-9_-]{20,})/'     => 'api_key=API_KEY_HIDDEN',
				'/\b[a-zA-Z0-9+\/]{40,}\b/'              => 'LONG_TOKEN_HIDDEN',
			);

			foreach ( $patterns as $pattern => $replacement ) {
				$data = preg_replace( $pattern, $replacement, $data );
			}

			if ( strlen( $data ) > 2000 ) {
				$data = substr( $data, 0, 1997 ) . '...';
			}

			return $data;
		} elseif ( is_array( $data ) ) {
			return array_map( array( $this, 'sanitize_for_log' ), $data );
		}
		return $data;
	}

	public function generate_embeddings( $text ) {
		$text = trim( $text );
		if ( empty( $text ) ) {
			throw new Exception( 'Cannot generate embeddings for empty text.' );
		}
		
		// Ensure minimum content length for meaningful embeddings
		if ( strlen( $text ) < 10 ) {
			$text = $text . ' This is a short content item that provides basic information.';
		}

		$provider = $this->settings['default_ai_provider'] ?? 'openai';

		switch ( $provider ) {
			case 'gemini':
				return $this->generate_gemini_embeddings( $text );
			case 'claude':
				return $this->generate_claude_embeddings( $text );
			case 'shareai':
				return $this->generate_shareai_embeddings( $text );
			case 'ollama':
				return $this->generate_ollama_embeddings( $text );
			case 'openai':
			default:
				return $this->generate_openai_embeddings( $text );
		}
	}

	private function generate_openai_embeddings( $text ) {
		if ( empty( $this->openai_api_key ) ) {
			throw new Exception( 'OpenAI API key is required for embeddings.' );
		}

		$url  = 'https://api.openai.com/v1/embeddings';
		$data = array(
			'model' => 'text-embedding-ada-002',
			'input' => $this->sanitize_text_for_json( $text ),
		);

		$body = json_encode( $data );
		if ( $body === false ) {
			throw new Exception( 'Failed to JSON-encode embedding request. Content may contain invalid characters.' );
		}

		$response = $this->make_http_request( $url, $body, 'openai' );

		if ( isset( $response['data'][0]['embedding'] ) ) {
			return $response['data'][0]['embedding'];
		} else {
			$error_message = $response['error']['message'] ?? 'Invalid embedding response from OpenAI API.';
			throw new Exception( esc_html( $error_message ) );
		}
	}

	private function generate_gemini_embeddings( $text ) {
		if ( empty( $this->gemini_api_key ) ) {
			throw new Exception( 'Gemini API key is required for embeddings.' );
		}

		$url  = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent';
		$data = array(
			'content' => array(
				'parts' => array(
					array( 'text' => $this->sanitize_text_for_json( $text ) ),
				),
			),
		);

		$body = json_encode( $data );
		if ( $body === false ) {
			throw new Exception( 'Failed to JSON-encode embedding request. Content may contain invalid characters.' );
		}

		$response = $this->make_http_request( $url, $body, 'gemini' );

		if ( isset( $response['embedding']['values'] ) ) {
			return $response['embedding']['values'];
		} else {
			$error_message = $response['error']['message'] ?? 'Invalid embedding response from Gemini API.';
			throw new Exception( esc_html( $error_message ) );
		}
	}

	private function generate_claude_embeddings( $text ) {
		// Claude API does not currently have a dedicated embedding endpoint.
		// This functionality is not supported.
		throw new Exception( 'Claude does not support embeddings. Please select a different provider for embedding generation.' );
	}

	private function generate_shareai_embeddings( $text ) {
		// ShareAI API does not currently have a dedicated embedding endpoint.
		// This functionality is not supported.
		throw new Exception( 'ShareAI does not support embeddings. Please select a different provider for embedding generation.' );
	}


	public function get_chat_completion( $system_message, $user_message, $temperature = 0.7, $model = 'gpt-3.5-turbo' ) {
		$default_provider = $this->settings['default_ai_provider'] ?? 'openai';
		if ( function_exists( 'aiohm_kb_normalize_wp_ai_provider_id' ) ) {
			$default_provider = aiohm_kb_normalize_wp_ai_provider_id( $default_provider );
		}

		if ( strpos( $model, 'gemini' ) === 0 || ( ( empty( $model ) || 'gpt-3.5-turbo' === $model ) && ( 'gemini' === $default_provider || 'google' === $default_provider ) ) ) {
			return $this->get_gemini_chat_completion( $system_message, $user_message, $temperature, $model );
		}

		if ( strpos( $model, 'claude' ) === 0 || ( ( empty( $model ) || 'gpt-3.5-turbo' === $model ) && ( 'claude' === $default_provider || 'anthropic' === $default_provider ) ) ) {
			return $this->get_claude_chat_completion( $system_message, $user_message, $temperature, $model );
		}

		if ( strpos( $model, 'groq' ) === 0 || ( ( empty( $model ) || 'gpt-3.5-turbo' === $model ) && 'groq' === $default_provider ) ) {
			return $this->get_groq_chat_completion( $system_message, $user_message, $temperature, $model );
		}

		if ( strpos( $model, 'shareai' ) === 0 || $default_provider === 'shareai' ) {
			return $this->get_shareai_chat_completion( $system_message, $user_message, $temperature, $model );
		}

		if ( $model === 'ollama' || $default_provider === 'ollama' ) {
			return $this->get_ollama_chat_completion( $system_message, $user_message, $temperature, $model );
		}

		return $this->get_openai_chat_completion( $system_message, $user_message, $temperature, $model );
	}

	private function get_gemini_chat_completion( $system_message, $user_message, $temperature, $model ) {
		if ( empty( $this->gemini_api_key ) ) {
			throw new Exception( 'Gemini API key is required for chat completions.' );
		}

		// Map UI model names to actual Gemini API model names
		$gemini_model_map = array(
			'gemini-pro'            => 'gemini-3.5-flash',
			'gemini-1.5-flash'      => 'gemini-3.5-flash',
			'gemini-1.5-pro'        => 'gemini-2.5-pro',
			'gemini-2.5-flash'      => 'gemini-3.5-flash',
			'gemini-2.5-pro'        => 'gemini-2.5-pro',
			'gemini-3.5-flash'      => 'gemini-3.5-flash',
			'gemini-3.5-flash-lite' => 'gemini-3.5-flash-lite',
			'gemini-3.7-flash'      => 'gemini-3.7-flash',
		);

		$actual_model = $gemini_model_map[ $model ] ?? 'gemini-3.5-flash';
		$url          = "https://generativelanguage.googleapis.com/v1/models/{$actual_model}:generateContent";

		$combined_prompt = "You are an AI assistant that must answer questions using ONLY the provided context below. If the context contains relevant information, you MUST use it.\n\n" .
							"CONTEXT TO USE FOR ANSWERING:\n" .
							"====================\n" .
							$system_message .
							"\n====================\n\n" .
							'QUESTION: ' . $user_message .
							"\n\nIMPORTANT: Answer the question using the context above. If the context mentions anything relevant to the question, use that information. Do not claim you don't have information when context is clearly provided above.";

		$data     = array( 'contents' => array( array( 'parts' => array( array( 'text' => $combined_prompt ) ) ) ) );
		try {
			$response = $this->make_http_request( $url, json_encode( $data ), 'gemini' );
		} catch ( Exception $e ) {
			// Fallback to gemini-3.5-flash-lite if the primary model hits a rate limit or error
			if ( $actual_model !== 'gemini-3.5-flash-lite' && ( strpos( $e->getMessage(), '429' ) !== false || strpos( $e->getMessage(), 'Quota' ) !== false || strpos( $e->getMessage(), '404' ) !== false ) ) {
				$fallback_url = 'https://generativelanguage.googleapis.com/v1/models/gemini-3.5-flash-lite:generateContent';
				$response     = $this->make_http_request( $fallback_url, json_encode( $data ), 'gemini' );
			} else {
				throw $e;
			}
		}

		if ( isset( $response['candidates'][0]['content']['parts'][0]['text'] ) ) {
			$content          = $response['candidates'][0]['content']['parts'][0]['text'];
			$estimated_tokens = ( strlen( $combined_prompt ) + strlen( $content ) ) / 4;
			if ( class_exists( 'AIOHM_KB_Core_Init' ) ) {
				AIOHM_KB_Core_Init::log_ai_usage( 'gemini', intval( $estimated_tokens ), 0 );
			}
			return $content;
		} else {
			throw new Exception( 'Invalid chat response from Gemini API.' );
		}
	}

	private function get_claude_chat_completion( $system_message, $user_message, $temperature, $model ) {
		if ( empty( $this->claude_api_key ) ) {
			throw new Exception( 'Claude API key is required for chat completions.' );
		}

		$claude_model_map = array(
			'claude-3-sonnet' => 'claude-3-sonnet-20240229',
			'claude-3-haiku'  => 'claude-3-haiku-20240307',
			'claude-3-opus'   => 'claude-3-opus-20240229',
		);

		$actual_model = $claude_model_map[ $model ] ?? 'claude-3-sonnet-20240229';
		$url          = 'https://api.anthropic.com/v1/messages';

		$data = array(
			'model'       => $actual_model,
			'max_tokens'  => 4000,
			'temperature' => floatval( $temperature ),
			'system'      => $this->sanitize_text_for_json( $system_message ),
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => $this->sanitize_text_for_json( $user_message ),
				),
			),
		);

		$body = json_encode( $data );
		if ( $body === false ) {
			throw new Exception( 'Failed to JSON-encode chat request.' );
		}

		$response = $this->make_http_request( $url, $body, 'claude' );

		if ( isset( $response['content'][0]['text'] ) ) {
			$content          = $response['content'][0]['text'];
			$estimated_tokens = ( strlen( $system_message ) + strlen( $user_message ) + strlen( $content ) ) / 4;
			if ( class_exists( 'AIOHM_KB_Core_Init' ) ) {
				AIOHM_KB_Core_Init::log_ai_usage( 'claude', intval( $estimated_tokens ), 0 );
			}
			return $content;
		} else {
			$error_message = $response['error']['message'] ?? 'Invalid chat response from Claude API.';
			throw new Exception( esc_html( $this->get_safe_error_message( $error_message, 'Claude' ) ) );
		}
	}

	private function get_shareai_chat_completion( $system_message, $user_message, $temperature, $model ) {
		// WordPress.org compliance: Check for ShareAI consent
		if ( ! $this->has_shareai_consent() ) {
			throw new Exception( 'ShareAI service requires user consent. Please enable it in Settings and review our Terms of Service.' );
		}

		if ( empty( $this->shareai_api_key ) ) {
			throw new Exception( 'ShareAI API key is required for chat completions.' );
		}

		if ( ! $this->check_rate_limit( 'shareai' ) ) {
			throw new Exception( 'ShareAI API rate limit exceeded. Please try again later.' );
		}

		$shareai_model = $this->settings['shareai_model'] ?? 'llama4:17b-scout-16e-instruct-fp16';
		if ( strpos( $model, 'shareai-' ) === 0 ) {
			$shareai_model = str_replace( 'shareai-', '', $model );
		}

		$url = 'https://api.shareai.now/api/v1/chat/completions';

		$combined_message = $system_message . "\n\n" . 'User question: ' . $user_message;

		$data = array(
			'model'       => $shareai_model,
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => $this->sanitize_text_for_json( $combined_message ),
				),
			),
			'temperature' => floatval( $temperature ),
			'max_tokens'  => 4000,
		);

		$body = json_encode( $data );
		if ( $body === false ) {
			throw new Exception( 'Failed to JSON-encode chat request.' );
		}

		try {
			$response = $this->make_http_request( $url, $body, 'shareai' );
		} catch ( Exception $e ) {
			throw new Exception( esc_html( $this->get_safe_error_message( $e->getMessage(), 'ShareAI' ) ) );
		}

		if ( isset( $response['choices'][0]['message']['content'] ) ) {
			$content          = $response['choices'][0]['message']['content'];
			$estimated_tokens = ( strlen( $combined_message ) + strlen( $content ) ) / 4;
			if ( class_exists( 'AIOHM_KB_Core_Init' ) ) {
				AIOHM_KB_Core_Init::log_ai_usage( 'shareai', intval( $estimated_tokens ), 0 );
			}
			return $content;
		} elseif ( isset( $response['message'] ) ) {
			$message = $response['message'];

			$final_content = '';
			if ( is_string( $message ) ) {
				$decoded = json_decode( $message, true );
				if ( $decoded !== null && isset( $decoded['content'] ) ) {
					$final_content = $decoded['content'];
				} else {
					$final_content = $message;
				}
			} elseif ( is_array( $message ) && isset( $message['content'] ) ) {
				$final_content = $message['content'];
			} else {
				$final_content = is_string( $message ) ? $message : json_encode( $message );
			}

			$estimated_tokens = ( strlen( $combined_message ) + strlen( $final_content ) ) / 4;
			if ( class_exists( 'AIOHM_KB_Core_Init' ) ) {
				AIOHM_KB_Core_Init::log_ai_usage( 'shareai', intval( $estimated_tokens ), 0 );
			}
			return $final_content;
		} elseif ( isset( $response['response'] ) ) {
			$content          = $response['response'];
			$estimated_tokens = ( strlen( $combined_message ) + strlen( $content ) ) / 4;
			if ( class_exists( 'AIOHM_KB_Core_Init' ) ) {
				AIOHM_KB_Core_Init::log_ai_usage( 'shareai', intval( $estimated_tokens ), 0 );
			}
			return $content;
		} elseif ( isset( $response['content'] ) ) {
			$content          = $response['content'];
			$estimated_tokens = ( strlen( $combined_message ) + strlen( $content ) ) / 4;
			if ( class_exists( 'AIOHM_KB_Core_Init' ) ) {
				AIOHM_KB_Core_Init::log_ai_usage( 'shareai', intval( $estimated_tokens ), 0 );
			}
			return $content;
		} elseif ( isset( $response['text'] ) ) {
			$content          = $response['text'];
			$estimated_tokens = ( strlen( $combined_message ) + strlen( $content ) ) / 4;
			if ( class_exists( 'AIOHM_KB_Core_Init' ) ) {
				AIOHM_KB_Core_Init::log_ai_usage( 'shareai', intval( $estimated_tokens ), 0 );
			}
			return $content;
		} elseif ( isset( $response['data']['text'] ) ) {
			$content          = $response['data']['text'];
			$estimated_tokens = ( strlen( $combined_message ) + strlen( $content ) ) / 4;
			if ( class_exists( 'AIOHM_KB_Core_Init' ) ) {
				AIOHM_KB_Core_Init::log_ai_usage( 'shareai', intval( $estimated_tokens ), 0 );
			}
			return $content;
		} else {
			$error_message = $response['error']['message'] ?? 'Invalid chat response from ShareAI API.';
			throw new Exception( esc_html( $error_message ) );
		}
	}

	private function get_groq_chat_completion( $system_message, $user_message, $temperature, $model ) {
		if ( empty( $this->groq_api_key ) ) {
			throw new Exception( 'Groq API key is required for chat completions.' );
		}

		// Map UI model names to actual Groq API model names
		$groq_model_map = array(
			'groq-llama-3.3-70b-versatile' => 'llama-3.3-70b-versatile',
			'groq-llama-3.1-70b-versatile' => 'llama-3.3-70b-versatile', // Fallback for deprecated model
		);
		$actual_model = $groq_model_map[ $model ] ?? 'llama-3.3-70b-versatile';

		$url  = 'https://api.groq.com/openai/v1/chat/completions';
		$data = array(
			'model'       => $actual_model,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => $this->sanitize_text_for_json( $system_message ),
				),
				array(
					'role'    => 'user',
					'content' => $this->sanitize_text_for_json( $user_message ),
				),
			),
			'temperature' => floatval( $temperature ),
		);

		$body = json_encode( $data );
		if ( $body === false ) {
			throw new Exception( 'Failed to JSON-encode chat request.' );
		}

		$response = $this->make_http_request( $url, $body, 'groq' );

		if ( isset( $response['choices'][0]['message']['content'] ) ) {
			$content       = $response['choices'][0]['message']['content'];
			$total_tokens  = $response['usage']['total_tokens'] ?? 0;

			// Log usage for Groq
			if ( $total_tokens > 0 ) {
				AIOHM_KB_Core_Init::log_ai_usage( 'groq', intval( $total_tokens ), 0 );
			}
			return $content;
		} else {
			$error_message = $response['error']['message'] ?? 'Invalid chat response from Groq API.';
			throw new Exception( esc_html( $error_message ) );
		}
	}

	private function get_openai_chat_completion( $system_message, $user_message, $temperature, $model ) {
		if ( empty( $this->openai_api_key ) ) {
			throw new Exception( 'OpenAI API key is required for chat completions.' );
		}

		$url  = 'https://api.openai.com/v1/chat/completions';
		$data = array(
			'model'       => $model,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => $this->sanitize_text_for_json( $system_message ),
				),
				array(
					'role'    => 'user',
					'content' => $this->sanitize_text_for_json( $user_message ),
				),
			),
			'temperature' => floatval( $temperature ),
		);

		$body = json_encode( $data );
		if ( $body === false ) {
			throw new Exception( 'Failed to JSON-encode chat request.' );
		}

		$response = $this->make_http_request( $url, $body, 'openai' );

		if ( isset( $response['choices'][0]['message']['content'] ) ) {
			$content       = $response['choices'][0]['message']['content'];
			$tokens_used   = $response['usage']['total_tokens'] ?? intval( ( strlen( $system_message ) + strlen( $user_message ) + strlen( $content ) ) / 4 );
			$cost_estimate = $this->estimate_openai_cost( $model, $tokens_used );
			if ( class_exists( 'AIOHM_KB_Core_Init' ) ) {
				AIOHM_KB_Core_Init::log_ai_usage( 'openai', $tokens_used, $cost_estimate );
			}
			return $content;
		} else {
			$error_message = $response['error']['message'] ?? 'Invalid chat response from OpenAI API.';
			throw new Exception( esc_html( $error_message ) );
		}
	}

	private function estimate_openai_cost( $model, $tokens ) {
		$pricing = array(
			'gpt-4'             => 0.03,
			'gpt-4-turbo'       => 0.01,
			'gpt-3.5-turbo'     => 0.002,
			'gpt-3.5-turbo-16k' => 0.004,
		);

		$rate = $pricing[ $model ] ?? 0.002;
		return ( $tokens / 1000000 ) * $rate;
	}

	private function make_http_request( $url, $body, $api_type ) {
		if ( $api_type !== 'ollama' && ! $this->settings['external_api_consent'] ) {
			throw new Exception( esc_html__( 'External API calls require user consent. Please enable this option in settings.', 'aiohm-knowledge-assistant' ) );
		}

		$headers = array( 'Content-Type' => 'application/json' );
		if ( $api_type === 'openai' ) {
			$headers['Authorization'] = 'Bearer ' . $this->openai_api_key;
		} elseif ( $api_type === 'gemini' ) {
			$headers['x-goog-api-key'] = $this->gemini_api_key;
		} elseif ( $api_type === 'claude' ) {
			$headers['Authorization']     = 'Bearer ' . $this->claude_api_key;
			$headers['anthropic-version'] = '2023-06-01';
		} elseif ( $api_type === 'groq' ) {
			$headers['Authorization'] = 'Bearer ' . $this->groq_api_key;
		} elseif ( $api_type === 'shareai' ) {
			$headers['Authorization'] = 'Bearer ' . $this->shareai_api_key;
		}

		$response = wp_remote_post(
			$url,
			array(
				'headers' => $headers,
				'body'    => $body,
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			throw new Exception( esc_html( $this->get_safe_error_message( $error_message, $api_type . ' HTTP' ) ) );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		$decoded_response = json_decode( $response_body, true );

		if ( $response_code !== 200 ) {
			$error_message = $decoded_response['error']['message'] ?? 'API request failed with status ' . $response_code;
			throw new Exception(
				sprintf(
				/* translators: %1$d: HTTP response code, %2$s: error message */
					esc_html__( 'API Error (%1$d): %2$s', 'aiohm-knowledge-assistant' ),
					intval( $response_code ),
					esc_html( $error_message )
				)
			);
		}

		return $decoded_response;
	}

	public function test_api_connection() {
		try {
			$this->generate_embeddings( 'test' );
			return array( 'success' => true );
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	public function test_gemini_api_connection() {
		if ( empty( $this->gemini_api_key ) ) {
			return array(
				'success' => false,
				'error'   => 'Gemini API key is missing.',
			);
		}

		try {
			// Use a simple direct API call for testing
			$url = 'https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent';

			$data = array(
				'contents' => array(
					array( 'parts' => array( array( 'text' => 'Say hello' ) ) ),
				),
			);

			$response = wp_remote_post(
				$url,
				array(
					'headers' => array(
						'Content-Type'   => 'application/json',
						'x-goog-api-key' => $this->gemini_api_key,
					),
					'body'    => json_encode( $data ),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				throw new Exception( 'Connection error: ' . $response->get_error_message() );
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );

			if ( $response_code !== 200 ) {
				$decoded       = json_decode( $response_body, true );
				$error_message = $decoded['error']['message'] ?? 'API request failed';
				throw new Exception( $error_message );
			}

			return array( 'success' => true );
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	public function test_claude_api_connection() {
		if ( empty( $this->claude_api_key ) ) {
			return array(
				'success' => false,
				'error'   => 'Claude API key is missing.',
			);
		}
		try {
			$this->get_chat_completion( 'Test prompt', "Say 'hello'.", 0.5, 'claude-3-sonnet' );
			return array( 'success' => true );
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	public function test_shareai_api_connection() {
		// WordPress.org compliance: Check for ShareAI consent
		if ( ! $this->has_shareai_consent() ) {
			return array(
				'success' => false,
				'error'   => 'ShareAI service requires user consent. Please enable it in Settings.',
			);
		}

		if ( empty( $this->shareai_api_key ) ) {
			return array(
				'success' => false,
				'error'   => 'ShareAI API key is missing.',
			);
		}

		// Check if this looks like a base64 encoded string (which would be wrong)
		if ( strlen( $this->shareai_api_key ) > 200 && preg_match( '/^[A-Za-z0-9+\/=]+$/', $this->shareai_api_key ) ) {
		}

		// Check if key has any unusual characters that might indicate corruption
		if ( preg_match( '/[^A-Za-z0-9\-_.]/', $this->shareai_api_key ) ) {
		}

		// First test basic connectivity without auth
		$basic_test = wp_remote_get( 'https://api.shareai.now/api/v1/', array( 'timeout' => 10 ) );
		if ( is_wp_error( $basic_test ) ) {
		} else {
		}

		// Use the key info endpoint to test connection without consuming tokens
		$key_info_url = 'https://api.shareai.now/api/v1/iam/key/info';

		try {
			$response = wp_remote_get(
				$key_info_url,
				array(
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $this->shareai_api_key,
					),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'error'   => 'Connection error: ' . $response->get_error_message(),
				);
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );

			if ( $response_code !== 200 ) {
				// If key info endpoint fails, fall back to minimal chat test
				return $this->test_shareai_fallback_connection();
			}

			$decoded = json_decode( $response_body, true );
			if ( $decoded === null ) {
				return $this->test_shareai_fallback_connection();
			}

			// Check for valid key info response and extract usage information
			$usage_info = $this->extract_shareai_usage_from_response( $decoded );

			if ( isset( $decoded['valid'] ) && $decoded['valid'] === true ) {
				$message = 'ShareAI connection successful - API key is valid';
				if ( $usage_info['has_usage'] ) {
					$message .= sprintf(
						' | Tokens used: %d | Requests: %d',
						$usage_info['tokens_used'],
						$usage_info['requests_made']
					);
					if ( $usage_info['quota_remaining'] !== null ) {
						$message .= sprintf( ' | Quota remaining: %s', $usage_info['quota_remaining'] );
					}
				}
				return array(
					'success'  => true,
					'message'  => $message,
					'key_info' => $decoded,
					'usage'    => $usage_info,
				);
			} elseif ( isset( $decoded['status'] ) && $decoded['status'] === 'active' ) {
				$message = 'ShareAI connection successful - API key is active';
				if ( $usage_info['has_usage'] ) {
					$message .= sprintf(
						' | Tokens used: %d | Requests: %d',
						$usage_info['tokens_used'],
						$usage_info['requests_made']
					);
				}
				return array(
					'success'  => true,
					'message'  => $message,
					'key_info' => $decoded,
					'usage'    => $usage_info,
				);
			} elseif ( isset( $decoded['keyId'] ) || isset( $decoded['key_id'] ) || isset( $decoded['id'] ) ) {
				// Key info returned some identifier, consider it successful
				$message = 'ShareAI connection successful - Key info retrieved';
				if ( $usage_info['has_usage'] ) {
					$message .= sprintf(
						' | Tokens used: %d | Requests: %d',
						$usage_info['tokens_used'],
						$usage_info['requests_made']
					);
				}
				return array(
					'success'  => true,
					'message'  => $message,
					'key_info' => $decoded,
					'usage'    => $usage_info,
				);
			} elseif ( isset( $decoded['error'] ) ) {
				return array(
					'success' => false,
					'error'   => 'ShareAI API Error: ' . $decoded['error'],
				);
			} else {
				// If key info doesn't work as expected, fall back to minimal chat test
				return $this->test_shareai_fallback_connection();
			}
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'error'   => 'Connection test failed: ' . $e->getMessage(),
			);
		}
	}

	/**
	 * Extract usage information from ShareAI API response
	 *
	 * @param array $decoded The decoded API response
	 * @return array Usage information
	 */
	private function extract_shareai_usage_from_response( $decoded ) {
		$usage_info = array(
			'has_usage'       => false,
			'tokens_used'     => 0,
			'requests_made'   => 0,
			'quota_remaining' => null,
			'quota_limit'     => null,
			'reset_date'      => null,
		);

		// Parse different possible response formats for usage data
		if ( isset( $decoded['usage'] ) ) {
			$usage                         = $decoded['usage'];
			$usage_info['has_usage']       = true;
			$usage_info['tokens_used']     = $usage['tokens'] ?? $usage['total_tokens'] ?? 0;
			$usage_info['requests_made']   = $usage['requests'] ?? $usage['total_requests'] ?? 0;
			$usage_info['quota_remaining'] = $usage['remaining'] ?? $usage['quota_remaining'] ?? null;
			$usage_info['quota_limit']     = $usage['limit'] ?? $usage['quota_limit'] ?? null;
			$usage_info['reset_date']      = $usage['reset_date'] ?? $usage['quota_reset'] ?? null;
		} elseif ( isset( $decoded['tokens'] ) || isset( $decoded['requests'] ) ) {
			// Direct usage fields in root
			$usage_info['has_usage']       = true;
			$usage_info['tokens_used']     = $decoded['tokens'] ?? $decoded['total_tokens'] ?? 0;
			$usage_info['requests_made']   = $decoded['requests'] ?? $decoded['total_requests'] ?? 0;
			$usage_info['quota_remaining'] = $decoded['remaining'] ?? $decoded['quota_remaining'] ?? null;
			$usage_info['quota_limit']     = $decoded['limit'] ?? $decoded['quota_limit'] ?? null;
		}

		return $usage_info;
	}

	private function test_shareai_fallback_connection() {

		// Fallback to minimal chat test if key info endpoint doesn't work
		$test_url       = 'https://api.shareai.now/api/v1/chat/completions';
		$selected_model = $this->settings['shareai_model'] ?? 'llama4:17b-scout-16e-instruct-fp16';

		$test_data = array(
			'model'      => $selected_model,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => 'Hi',
				),
			),
			'max_tokens' => 10, // Minimal tokens to reduce consumption
		);

		try {
			$response = wp_remote_post(
				$test_url,
				array(
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $this->shareai_api_key,
					),
					'body'    => json_encode( $test_data ),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'error'   => 'Connection error: ' . $response->get_error_message(),
				);
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );

			if ( $response_code !== 200 ) {
				return array(
					'success' => false,
					'error'   => 'HTTP ' . $response_code . ': ' . $response_body,
				);
			}

			$decoded = json_decode( $response_body, true );
			if ( $decoded === null ) {
				return array(
					'success' => false,
					'error'   => 'Invalid JSON response: ' . json_last_error_msg(),
				);
			}

			// Check for any valid response format
			if ( isset( $decoded['choices'][0]['message']['content'] ) ||
				isset( $decoded['message'] ) ||
				isset( $decoded['response'] ) ||
				isset( $decoded['content'] ) ||
				isset( $decoded['text'] ) ) {
				return array(
					'success' => true,
					'message' => 'ShareAI connection successful (fallback test)',
				);
			} else {
				return array(
					'success' => true,
					'message' => 'ShareAI connection successful (unknown format)',
				);
			}
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'error'   => 'Fallback connection test failed: ' . $e->getMessage(),
			);
		}
	}

	/**
	 * Get ShareAI usage information without consuming tokens
	 *
	 * @return array Usage information or error
	 */
	public function get_shareai_usage_info() {
		if ( empty( $this->shareai_api_key ) ) {
			return array(
				'success' => false,
				'error'   => 'ShareAI API key is missing.',
			);
		}

		$key_info_url = 'https://api.shareai.now/api/v1/iam/key/info';

		try {
			$response = wp_remote_get(
				$key_info_url,
				array(
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $this->shareai_api_key,
					),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'error'   => 'Connection error: ' . $response->get_error_message(),
				);
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );

			if ( $response_code !== 200 ) {
				return array(
					'success' => false,
					'error'   => 'HTTP ' . $response_code . ': ' . $response_body,
				);
			}

			$decoded = json_decode( $response_body, true );
			if ( $decoded === null ) {
				return array(
					'success' => false,
					'error'   => 'Invalid JSON response: ' . json_last_error_msg(),
				);
			}

			// Extract usage information from the response
			$usage_info = array(
				'success'         => true,
				'tokens_used'     => 0,
				'requests_made'   => 0,
				'quota_remaining' => null,
				'quota_limit'     => null,
				'reset_date'      => null,
			);

			// Parse different possible response formats for usage data
			if ( isset( $decoded['usage'] ) ) {
				$usage                         = $decoded['usage'];
				$usage_info['tokens_used']     = $usage['tokens'] ?? $usage['total_tokens'] ?? 0;
				$usage_info['requests_made']   = $usage['requests'] ?? $usage['total_requests'] ?? 0;
				$usage_info['quota_remaining'] = $usage['remaining'] ?? $usage['quota_remaining'] ?? null;
				$usage_info['quota_limit']     = $usage['limit'] ?? $usage['quota_limit'] ?? null;
				$usage_info['reset_date']      = $usage['reset_date'] ?? $usage['quota_reset'] ?? null;
			} elseif ( isset( $decoded['tokens'] ) || isset( $decoded['requests'] ) ) {
				// Direct usage fields in root
				$usage_info['tokens_used']     = $decoded['tokens'] ?? $decoded['total_tokens'] ?? 0;
				$usage_info['requests_made']   = $decoded['requests'] ?? $decoded['total_requests'] ?? 0;
				$usage_info['quota_remaining'] = $decoded['remaining'] ?? $decoded['quota_remaining'] ?? null;
				$usage_info['quota_limit']     = $decoded['limit'] ?? $decoded['quota_limit'] ?? null;
			}

			$usage_info['raw_response'] = $decoded;

			return $usage_info;

		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'error'   => 'Failed to get usage info: ' . $e->getMessage(),
			);
		}
	}


	/**
	 * Get the current AI provider based on settings
	 *
	 * @return string Current provider name
	 */
	public function get_current_provider() {
		$provider = $this->settings['default_ai_provider'] ?? ($this->settings['ai_provider'] ?? 'openai');
		if ( function_exists( 'aiohm_kb_normalize_wp_ai_provider_id' ) ) {
			$provider = aiohm_kb_normalize_wp_ai_provider_id( $provider );
		}

		// Map old provider names to current ones
		$provider_mapping = array(
			'gpt'       => 'openai',
			'openai'    => 'openai',
			'gemini'    => 'google',
			'google'    => 'google',
			'claude'    => 'anthropic',
			'anthropic' => 'anthropic',
			'shareai'   => 'shareai',
		);

		return $provider_mapping[ $provider ] ?? 'openai';
	}

	/**
	 * Generate response with enhanced options for document processing
	 *
	 * @param string $prompt The prompt to send
	 * @param array  $options Additional options (max_tokens, temperature, etc.)
	 * @return string AI response
	 */
	public function generate_response( $prompt, $options = array() ) {
		$max_tokens  = $options['max_tokens'] ?? 4000;
		$temperature = $options['temperature'] ?? 0.7;
		$model       = $options['model'] ?? null;

		// Use appropriate model based on provider
		if ( ! $model ) {
			$provider = $this->get_current_provider();
			switch ( $provider ) {
				case 'openai':
					$model = 'gpt-4';
					break;
				case 'gemini':
				case 'google':
					$model = 'gemini-2.5-flash';
					break;
				case 'anthropic':
					$model = 'claude-3-sonnet-20240229';
					break;
				default:
					$model = 'gemini-2.5-flash';
			}
		}

		return $this->get_chat_completion( '', $prompt, $temperature, $model );
	}

	/**
	 * Test Groq API connection
	 *
	 * @return array Result with success status and message
	 */
	public function test_groq_api_connection() {
		if ( empty( $this->groq_api_key ) ) {
			return array(
				'success' => false,
				'error'   => 'Groq API key is missing.',
			);
		}

		try {
			// Use a simple direct API call for testing
			$url = 'https://api.groq.com/openai/v1/chat/completions';

			$data = array(
				'model' => 'llama-3.3-70b-versatile',
				'messages' => array(
					array( 'role' => 'user', 'content' => 'Say hello in one word' ),
				),
				'max_tokens' => 5,
			);

			$response = wp_remote_post(
				$url,
				array(
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $this->groq_api_key,
					),
					'body'    => json_encode( $data ),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'error'   => 'Connection error: ' . $response->get_error_message(),
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );

			if ( $status_code === 200 ) {
				$decoded = json_decode( $body, true );
				if ( isset( $decoded['choices'] ) && ! empty( $decoded['choices'] ) ) {
					return array(
						'success' => true,
						'message' => 'Groq connection successful!',
					);
				}
			}

			$decoded = json_decode( $body, true );
			$error_message = $decoded['error']['message'] ?? 'Unknown API error';

			return array(
				'success' => false,
				'error'   => $error_message,
			);

		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'error'   => 'Connection test failed: ' . $e->getMessage(),
			);
		}
	}

	/**
	 * Process file with AI (future enhancement for direct file upload)
	 *
	 * @param string $file_path Path to file
	 * @param string $prompt Processing instructions
	 * @return array Response with content and metadata
	 */
	public function process_file_with_ai( $file_path, $prompt ) {
		// For now, this is a placeholder for future direct file upload support
		// Most AI providers are adding document upload capabilities

		$provider = $this->get_current_provider();

		// Future implementation would handle direct file uploads to:
		// - OpenAI's file upload API
		// - Gemini's document processing
		// - Claude's document analysis

		throw new Exception( 'Direct file processing not yet implemented. Use text extraction approach.' );
	}

	/**
	 * Ollama chat completion
	 */
	private function get_ollama_chat_completion( $system_message, $user_message, $temperature, $model ) {
		if ( empty( $this->ollama_server_url ) ) {
			throw new Exception( 'Ollama server URL is required for chat completions.' );
		}

		$base_url = rtrim( $this->ollama_server_url, '/' );
		$url = $base_url . '/api/generate';
		$prompt = $system_message . "\n\n" . $user_message;

		$data = array(
			'model' => $this->ollama_model,
			'prompt' => $this->sanitize_text_for_json( $prompt ),
			'stream' => false
		);

		$body = wp_json_encode( $data );
		if ( $body === false ) {
			throw new Exception( 'Failed to JSON-encode chat request.' );
		}

		$response = $this->make_http_request( $url, $body, 'ollama' );

		if ( isset( $response['response'] ) ) {
			$content = $response['response'];
			$estimated_tokens = ( strlen( $prompt ) + strlen( $content ) ) / 4;
			if ( class_exists( 'AIOHM_KB_Core_Init' ) ) {
				AIOHM_KB_Core_Init::log_ai_usage( 'ollama', intval( $estimated_tokens ), 0 );
			}
			return $content;
		} else {
			$error_message = $response['error'] ?? 'Invalid chat response from Ollama server.';
			throw new Exception( esc_html( $error_message ) );
		}
	}

	/**
	 * Generate Ollama embeddings
	 */
	private function generate_ollama_embeddings( $text ) {
		if ( empty( $this->ollama_server_url ) ) {
			throw new Exception( 'Ollama server URL is required for embeddings.' );
		}

		$base_url = rtrim( $this->ollama_server_url, '/' );
		$url = $base_url . '/api/embeddings';

		$data = array(
			'model' => $this->ollama_model,
			'prompt' => $this->sanitize_text_for_json( $text )
		);

		$body = wp_json_encode( $data );
		if ( $body === false ) {
			throw new Exception( 'Failed to JSON-encode embedding request. Content may contain invalid characters.' );
		}

		$response = $this->make_http_request( $url, $body, 'ollama' );

		if ( isset( $response['embedding'] ) ) {
			return $response['embedding'];
		} else {
			$normalized_text = strtolower( trim( $this->sanitize_text_for_json( $text ) ) );
			$hash = hash( 'sha256', $normalized_text );
			$mock_embedding = array();

			for ( $i = 0; $i < 1536; $i++ ) {
				$mock_embedding[] = (float) ( hexdec( substr( $hash, $i % 64, 1 ) ) / 15.0 - 0.5 );
			}

			return $mock_embedding;
		}
	}

	/**
	 * Test Ollama API connection
	 */
	public function test_ollama_api_connection() {
		if ( empty( $this->ollama_server_url ) ) {
			return array( 'success' => false, 'error' => 'Ollama server URL is missing.' );
		}

		try {
			// Test using Ollama's correct API endpoint
			$base_url = rtrim( $this->ollama_server_url, '/' );
			$url = $base_url . '/api/generate';

			$data = array(
				'model' => $this->ollama_model,
				'prompt' => 'Say hello',
				'stream' => false
			);

			$body = wp_json_encode( $data );
			$response = $this->make_http_request( $url, $body, 'ollama' );

			if ( isset( $response['response'] ) ) {
				return array( 'success' => true, 'message' => 'Connected successfully' );
			} else {
				return array( 'success' => false, 'error' => 'No response from server' );
			}
		} catch ( Exception $e ) {
			return array( 'success' => false, 'error' => 'Failed to connect: ' . $e->getMessage() );
		}
	}
}
