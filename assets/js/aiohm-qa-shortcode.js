/**
 * AIOHM Q&A Generator Shortcode Frontend Script
 * Handles the frontend Q&A generation functionality for the [aiohm_qa_generator] shortcode
 */

function initializeAIOHMQA() {
	if (typeof jQuery === 'undefined') {
		setTimeout(initializeAIOHMQA, 100);
		return;
	}
	
	jQuery(function($) {
		$( '.aiohm-qa-generator-container' ).each(
			function () {
				const containerId = $( this ).attr( 'id' );
				
				// Extract the counter from QA ID (aiohm-qa-1 -> 1)
				const qaCounter = containerId.replace('aiohm-qa-', '');
				const configVarName = 'aiohm_qa_config_' + qaCounter;
				const config = window[configVarName];

				if ( ! config) {
					return;
				}

				initQAGenerator( $( this ), config );
			}
		);

		function initQAGenerator($container, config) {
			const $btn     = $container.find( '.aiohm-qa-generate-btn' );
			const $results = $container.find( '.aiohm-qa-results' );

			$btn.on(
				'click',
				function () {
					generateQAPair( $btn, $results, config );
				}
			);
		}

		function generateQAPair($btn, $results, config) {
			const originalText = $btn.text();

			$btn.prop( 'disabled', true ).text( config.strings.generating );
			$results.html( '<div class="aiohm-qa-loading"><span class="aiohm-spinner"></span>' + config.strings.generating + '</div>' );

			$.post(
				config.ajax_url,
				{
					action: 'aiohm_generate_qa_pair',
					nonce: config.nonce
				}
			)
			.done(
				function (response) {
					if (response.success) {
						const question = response.data.qa_pair.question;
						const answer   = response.data.qa_pair.answer;

						$results.html(
							`
							<div class="aiohm-qa-sample-container">
							<div class="aiohm-qa-header">
							<span class="aiohm-qa-icon">💬</span>
							<h4>Sample Q & A from Your Knowledge Base</h4>
							</div>
							<div class="aiohm-qa-question">
							<div class="aiohm-qa-label">
								<span class="aiohm-qa-q-icon">❓</span>
								<strong>Question:</strong>
							</div>
							<div class="aiohm-qa-content">${question}</div>
							</div>
							<div class="aiohm-qa-answer">
							<div class="aiohm-qa-label">
								<span class="aiohm-qa-a-icon">💡</span>
								<strong>Answer:</strong>
							</div>
							<div class="aiohm-qa-content aiohm-qa-answer-formatted">${answer}</div>
							</div>
							</div>
							`
						);
					} else {
						$results.html( `<div class="aiohm-qa-error">⚠️ ${response.data.message || config.strings.error}</div>` );
					}
				}
			)
			.fail(
				function () {
					$results.html( '<div class="aiohm-qa-error">❌ ' + config.strings.server_error + '</div>' );
				}
			)
			.always(
				function () {
					$btn.prop( 'disabled', false ).text( originalText );
				}
			);
		}
	});
}

// Start initialization
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', initializeAIOHMQA);
} else {
	initializeAIOHMQA();
}