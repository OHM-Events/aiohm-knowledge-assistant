<?php
/**
 * Chat shortcode implementation - [aiohm_chat]
 * Provides public-facing AI chat interface
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIOHM_KB_Shortcode_Chat {

	public static function init() {
		add_shortcode( 'aiohm_chat', array( __CLASS__, 'render_chat_shortcode' ) );
	}

	public static function render_chat_shortcode( $atts ) {
		$all_settings = AIOHM_KB_Assistant::get_settings();
		$settings     = $all_settings['mirror_mode'] ?? array();

		if ( ! ( $all_settings['chat_enabled'] ?? false ) ) {
			$message = __( 'Chat is currently disabled.', 'aiohm-knowledge-assistant' );
			return '<div class="aiohm-chat-disabled"><p>' . $message . '</p></div>';
		}

		$atts = shortcode_atts(
			array(
				'title'           => $settings['business_name'] ?? __( 'Ask me anything', 'aiohm-knowledge-assistant' ),
				'placeholder'     => __( 'Type your question here...', 'aiohm-knowledge-assistant' ),
				'height'          => '400',
				'width'           => '100%',
				'theme'           => 'ohm-green',
				'position'        => 'inline',
				'show_branding'   => 'true',
				'welcome_message' => $settings['welcome_message'] ?? 'Hey there! I am your AI assistant. I can help answer questions about our services and content. Ask me anything!',
				'max_height'      => '600',
			),
			$atts,
			'aiohm_chat'
		);

		static $chat_counter = 0;
		++$chat_counter;
		$chat_id = 'aiohm-chat-' . $chat_counter;

		wp_enqueue_script( 'aiohm-chat-shortcode' );
		wp_enqueue_style( 'aiohm-chat' );

		// Prepare chat configuration
		$chat_config = array(
			'chat_id'     => $chat_id,
			'ajax_url'    => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'aiohm_chat_nonce' ),
			'chat_action' => 'aiohm_frontend_chat',
			'strings'     => array(
				'error' => __( 'Sorry, something went wrong. Please try again.', 'aiohm-knowledge-assistant' ),
			),
			'settings'    => $settings,
		);

		$output = '<div class="aiohm-chat-wrapper">';
		
		// Output configuration script right at the start
		$output .= '<script type="text/javascript">';
		$output .= 'window.aiohm_chat_config_' . $chat_counter . ' = ' . wp_json_encode( $chat_config ) . ';';
		$output .= '</script>';

		$output .= '<div class="aiohm-chat-container aiohm-chat-theme-' . esc_attr( $atts['theme'] ) . '" id="' . esc_attr( $chat_id ) . '"';

		$styles = array();
		if ( $atts['width'] !== '100%' ) {
			$styles[] = 'width: ' . esc_attr( $atts['width'] );
		}
		if ( ! empty( $settings['background_color'] ) ) {
			$styles[] = '--aiohm-secondary-color: ' . esc_attr( $settings['background_color'] );
		}
		if ( ! empty( $settings['primary_color'] ) ) {
			$styles[] = '--aiohm-primary-color: ' . esc_attr( $settings['primary_color'] );
		}
		if ( ! empty( $settings['text_color'] ) ) {
			$styles[] = '--aiohm-text-color: ' . esc_attr( $settings['text_color'] );
		}
		if ( ! empty( $styles ) ) {
			$output .= ' style="' . implode( '; ', $styles ) . '"';
		}

		$output .= '>';

		$output .= '<div class="aiohm-chat-header">';
		$output .= '<div class="aiohm-chat-title">' . esc_html( $atts['title'] ) . '</div>';
		$output .= '<div class="aiohm-chat-status">';
		$output .= '<span class="aiohm-status-indicator" data-status="ready"></span>';
		$output .= '<span class="aiohm-status-text">' . __( 'Ready', 'aiohm-knowledge-assistant' ) . '</span>';
		$output .= '</div>';
		$output .= '</div>';

		$output .= '<div class="aiohm-chat-messages" style="height: ' . esc_attr( $atts['height'] ) . 'px; max-height: ' . esc_attr( $atts['max_height'] ) . 'px;">';

		if ( ! empty( $atts['welcome_message'] ) ) {
			$output .= '<div class="aiohm-message aiohm-message-bot">';
			if ( ! empty( $settings['ai_avatar'] ) ) {
				$output .= '<div class="aiohm-message-avatar">' . AIOHM_KB_Core_Init::render_image(
					$settings['ai_avatar'],
					__( 'AI Avatar', 'aiohm-knowledge-assistant' ),
					array( 'style' => 'width:100%; height:100%; border-radius:50%; object-fit: cover;' )
				) . '</div>';
			} else {
				$output .= '<div class="aiohm-message-avatar"><div class="chat-avatar-default">AI</div></div>';
			}
			$output .= '<div class="aiohm-message-bubble"><div class="aiohm-message-content">' . esc_html( $atts['welcome_message'] ) . '</div></div>';
			$output .= '</div>';
		}

		$output .= '</div>';

		$output .= '<div class="aiohm-chat-input-container">';
		$output .= '<div class="aiohm-chat-input-wrapper">';
		$output .= '<textarea class="aiohm-chat-input" placeholder="' . esc_attr( $atts['placeholder'] ) . '" rows="1" style="background-color: #ffffff; color: #333333;"></textarea>';
		$output .= '<button type="button" class="aiohm-chat-send-btn" disabled>';
		$output .= '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="aiohm-send-icon">';
		$output .= '<path d="M3.714 3.048a.498.498 0 0 0-.683.627l2.843 7.627a.498.498 0 0 1 0 .396l-2.843 7.627a.498.498 0 0 0 .683.627l16.571-8.5a.498.498 0 0 0 0-.904L3.714 3.048z"/>';
		$output .= '<path d="m6 12 13-1"/>';
		$output .= '</svg>';
		$output .= '</button>';
		$output .= '</div>';
		$output .= '</div>';

		if ( $atts['show_branding'] === 'true' ) {
			$branding_text = '<span>' . __( 'Powered by', 'aiohm-knowledge-assistant' ) . ' <strong>AIOHM</strong></span>';
			$meeting_url   = $settings['meeting_button_url'] ?? '';

			if ( ! empty( $meeting_url ) ) {
				$output .= '<a href="' . esc_url( $meeting_url ) . '" class="aiohm-chat-branding aiohm-chat-footer-button" target="_blank">' . __( 'Book a Meeting', 'aiohm-knowledge-assistant' ) . '</a>';
			} else {
				$output .= '<div class="aiohm-chat-branding">' . $branding_text . '</div>';
			}
		}

		$output .= '</div>'; // aiohm-chat-container

		$output .= '</div>'; // aiohm-chat-wrapper

		return $output;
	}
}
