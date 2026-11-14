<?php
/**
 * Q&A Generator shortcode implementation - [aiohm_qa_generator]
 * Generates sample Q&A pairs from knowledge base content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIOHM_KB_Shortcode_QA {

	public static function init() {
		add_shortcode( 'aiohm_qa_generator', array( __CLASS__, 'render_qa_shortcode' ) );
		add_action( 'wp_ajax_aiohm_generate_qa_pair', array( __CLASS__, 'handle_generate_qa_ajax' ) );
		add_action( 'wp_ajax_nopriv_aiohm_generate_qa_pair', array( __CLASS__, 'handle_generate_qa_ajax' ) );
	}

	public static function render_qa_shortcode( $atts ) {
		// Check if Q&A shortcode is enabled (default to true if not set)
		$settings = AIOHM_KB_Assistant::get_settings();
		if ( ! ( $settings['enable_qa_shortcode'] ?? true ) ) {
			return '<!-- AIOHM Q&A Generator shortcode is disabled in settings -->';
		}

		$atts = shortcode_atts(
			array(
				'button_text'      => __( 'Generate Sample Q&A', 'aiohm-knowledge-assistant' ),
				'show_description' => 'false',
				'description'      => '',
			),
			$atts,
			'aiohm_qa_generator'
		);

		static $qa_counter = 0;
		++$qa_counter;
		$qa_id = 'aiohm-qa-' . $qa_counter;

		// Enqueue QA script and style
		wp_enqueue_script( 'aiohm-qa-shortcode' );
		wp_enqueue_style( 'aiohm-qa-shortcode' );

		$output  = '<div class="aiohm-qa-generator-wrapper">';
		
		// Output configuration script right at the start
		$output .= '<script type="text/javascript">';
		$output .= 'window.aiohm_qa_config_' . $qa_counter . ' = ' . wp_json_encode( array(
			'qa_id'    => $qa_id,
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'aiohm_qa_nonce' ),
			'strings'  => array(
				'generating'   => __( 'Generating...', 'aiohm-knowledge-assistant' ),
				'error'        => __( 'Failed to generate Q&A sample.', 'aiohm-knowledge-assistant' ),
				'server_error' => __( 'Server error occurred.', 'aiohm-knowledge-assistant' ),
			),
		) ) . ';';
		$output .= '</script>';
		
		$output .= '<div class="aiohm-qa-generator-container" id="' . esc_attr( $qa_id ) . '">';

		if ( $atts['show_description'] === 'true' ) {
			$output .= '<p class="aiohm-qa-description">' . esc_html( $atts['description'] ) . '</p>';
		}

		$output .= '<button type="button" class="aiohm-qa-generate-btn">' . esc_html( $atts['button_text'] ) . '</button>';
		$output .= '<div class="aiohm-qa-results"></div>';

		$output .= '</div>'; // .aiohm-qa-generator-container



		$output .= '</div>'; // .aiohm-qa-generator-wrapper

		return $output;
	}

	/**
	 * Handles the AJAX request for generating Q&A pairs.
	 */
	public static function handle_generate_qa_ajax() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'aiohm_qa_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed', 'aiohm-knowledge-assistant' ) ) );
			wp_die();
		}

		try {
			// Verify classes exist
			if ( ! class_exists( 'AIOHM_KB_RAG_Engine' ) ) {
				throw new Exception( 'RAG Engine class not found. Please check plugin installation.' );
			}
			if ( ! class_exists( 'AIOHM_KB_AI_GPT_Client' ) ) {
				throw new Exception( 'AI Client class not found. Please check plugin installation.' );
			}

			$rag_engine = new AIOHM_KB_RAG_Engine();
			$ai_client  = new AIOHM_KB_AI_GPT_Client();
			$settings   = AIOHM_KB_Assistant::get_settings();

			// Check if any AI provider is configured
			$has_api_key = ! empty( $settings['openai_api_key'] ) ||
							! empty( $settings['gemini_api_key'] ) ||
							! empty( $settings['claude_api_key'] ) ||
							! empty( $settings['shareai_api_key'] ) ||
							( function_exists( 'aiohm_kb_is_wp_ai_connector_configured' ) &&
								( aiohm_kb_is_wp_ai_connector_configured( 'openai' ) ||
								  aiohm_kb_is_wp_ai_connector_configured( 'gemini' ) ||
								  aiohm_kb_is_wp_ai_connector_configured( 'claude' ) ) ) ||
							! empty( get_option( 'connectors_ai_openai_api_key' ) ) ||
							! empty( get_option( 'connectors_ai_google_api_key' ) ) ||
							! empty( get_option( 'connectors_ai_gemini_api_key' ) ) ||
							! empty( get_option( 'connectors_ai_anthropic_api_key' ) ) ||
							! empty( get_option( 'connectors_ai_claude_api_key' ) ) ||
							( defined( 'OPENAI_API_KEY' ) && OPENAI_API_KEY ) ||
							( defined( 'GOOGLE_API_KEY' ) && GOOGLE_API_KEY ) ||
							( defined( 'GEMINI_API_KEY' ) && GEMINI_API_KEY ) ||
							( defined( 'ANTHROPIC_API_KEY' ) && ANTHROPIC_API_KEY ) ||
							( defined( 'CLAUDE_API_KEY' ) && CLAUDE_API_KEY ) ||
							getenv( 'OPENAI_API_KEY' ) ||
							getenv( 'GOOGLE_API_KEY' ) ||
							getenv( 'GEMINI_API_KEY' ) ||
							getenv( 'ANTHROPIC_API_KEY' ) ||
							getenv( 'CLAUDE_API_KEY' );

			if ( ! $has_api_key ) {
				throw new Exception( 'No AI provider API key configured. Please add an API key in settings.' );
			}

			$random_chunk = $rag_engine->get_random_chunk();
			if ( ! $random_chunk ) {
				throw new Exception( 'Your knowledge base is empty. Please scan some content first.' );
			}

			// Use the AI model selected in Mirror Mode, fallback to default provider
			$mirror_model = $settings['mirror_mode']['ai_model'] ?? '';
			if ( ! empty( $mirror_model ) ) {
				$model = $mirror_model;
			} else {
				// Fallback to default provider if no Mirror Mode model is set
				$default_provider = $settings['default_ai_provider'] ?? 'openai';
				switch ( $default_provider ) {
					case 'gemini':
						$model = 'gemini-pro';
						break;
					case 'claude':
						$model = 'claude-3-sonnet';
						break;
					case 'shareai':
						$model = 'shareai-llama4:17b-scout-16e-instruct-fp16';
						break;
					case 'openai':
					default:
						$model = 'gpt-3.5-turbo';
						break;
				}
			}

			$question_prompt = "Based on the following text, what is a likely user question? Only return the question itself, without any preamble.\n\nCONTEXT:\n" . $random_chunk;
			$question        = $ai_client->get_chat_completion( $question_prompt, '', 0.7, $model );
			$answer_prompt   = "You are a helpful assistant. Answer the following question based on the provided context.\n\nCONTEXT:\n{$random_chunk}\n\nQUESTION:\n{$question}";
			$answer          = $ai_client->get_chat_completion( $answer_prompt, '', 0.2, $model );

			// Format the answer with proper HTML formatting
			$formatted_answer = AIOHM_KB_Core_Init::format_qa_answer( trim( $answer ) );

			wp_send_json_success(
				array(
					'qa_pair' => array(
						'question' => trim( str_replace( '"', '', $question ) ),
						'answer'   => $formatted_answer,
					),
				)
			);
		} catch ( Exception $e ) {
			// Log the error for debugging

			// translators: %s is the Q&A generation error message
			wp_send_json_error( array( 'message' => sprintf( __( 'Failed to generate Q&A pair: %s', 'aiohm-knowledge-assistant' ), $e->getMessage() ) ) );
		}

		wp_die();
	}
}
