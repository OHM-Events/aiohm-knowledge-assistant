jQuery(document).ready(function($){
    let noticeTimer;
    
    // Enhanced admin notice function with accessibility features
    function showAdminNotice(message, type = 'success', persistent = false) {
        clearTimeout(noticeTimer);
        let $noticeDiv = $('#aiohm-admin-notice');
        
        // Create notice div if it doesn't exist
        if ($noticeDiv.length === 0) {
            $('<div id="aiohm-admin-notice" class="notice is-dismissible" tabindex="-1" role="alert" aria-live="polite"><p></p></div>').insertAfter('h1');
            $noticeDiv = $('#aiohm-admin-notice');
        }

        // Clear existing classes and add new type
        $noticeDiv.removeClass('notice-success notice-error notice-warning aiohm-hidden').addClass('notice-' + type);

        // Set message content
        let $p = $noticeDiv.find('p');
        if ($p.length === 0) {
            $p = $('<p></p>').appendTo($noticeDiv);
        }
        $p.html(message);

        // Show notice with fade in effect and scroll to it
        $noticeDiv.show().removeClass('aiohm-hidden');

        // Scroll to the notice for better visibility
        $('html, body').animate({
            scrollTop: $noticeDiv.offset().top - 100
        }, 300, function() {
            // Auto-focus for accessibility after scroll completes
            $noticeDiv.focus();

            // Announce to screen readers
            if (type === 'error') {
                $noticeDiv.attr('aria-live', 'assertive');
            } else {
                $noticeDiv.attr('aria-live', 'polite');
            }
        });
        
        // Handle dismiss button (make sure to add it if it doesn't exist)
        if ($noticeDiv.find('.notice-dismiss').length === 0) {
            $noticeDiv.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');
        }

        $noticeDiv.off('click.notice-dismiss').on('click.notice-dismiss', '.notice-dismiss', function() {
            $noticeDiv.fadeOut(300);
            // Return focus to the previously focused element or main content
            $('h1').focus();
        });

        // Auto-hide after timeout (unless persistent)
        if (!persistent) {
            noticeTimer = setTimeout(() => {
                if ($noticeDiv.is(':visible')) {
                    $noticeDiv.fadeOut(300, function() {
                        // Return focus to main content when auto-hiding
                        $('h1').focus();
                    });
                }
            }, 7000); // Increased to 7 seconds for better UX
        }
    }

    // Show/Hide API Key functionality
    $('.aiohm-show-hide-key').on('click', function(){
        const $input = $('#' + $(this).data('target'));
        const type = $input.attr('type');
        $input.attr('type', type === 'password' ? 'text' : 'password');
    });

    // Debounce helper for input-driven model refresh
    function debounce(fn, delay) {
        let timer;
        return function() {
            const context = this;
            const args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function() {
                fn.apply(context, args);
            }, delay);
        };
    }

    // Save API Key functionality
    $('.aiohm-save-api-key').on('click', function(){
        const $btn = $(this);
        const targetId = $btn.data('target');
        const keyType = $btn.data('type');
        const originalText = $btn.text();
        const apiKey = $('#' + targetId).val();


        if (!apiKey.trim()) {
            showAdminNotice('Please enter an API key before saving.', 'warning');
            return;
        }

        // Check if AJAX object exists
        if (typeof aiohm_admin_settings_ajax === 'undefined') {
            showAdminNotice('JavaScript configuration error: AJAX data not loaded', 'error');
            return;
        }

        // Check if ajaxurl is available (use the localized version if standard ajaxurl is not available)
        const ajax_url = (typeof ajaxurl !== 'undefined') ? ajaxurl : aiohm_admin_settings_ajax.ajax_url;
        if (!ajax_url) {
            showAdminNotice('JavaScript configuration error: AJAX URL not available', 'error');
            return;
        }

        $btn.prop('disabled', true).text('Saving...');

        // Map key types to setting names
        const settingMap = {
            'shareai': 'shareai_api_key',
            'openai': 'openai_api_key',
            'gemini': 'gemini_api_key',
            'claude': 'claude_api_key',
            'groq': 'groq_api_key',
            'ollama': 'private_llm_server_url'
        };

        const settingName = settingMap[keyType];
        if (!settingName) {
            showAdminNotice('Unknown API key type: ' + keyType, 'error');
            $btn.prop('disabled', false).text(originalText);
            return;
        }

        const postData = {
            action: 'aiohm_save_individual_api_key',
            nonce: aiohm_admin_settings_ajax.nonce,
            api_type: keyType,
            setting_name: settingName,
            api_value: apiKey
        };

        $.post(ajax_url, postData)
        .done(function(response) {
            if (response.success) {
                if (keyType === 'ollama') {
                    showAdminNotice('Ollama server URL saved successfully!', 'success');
                } else {
                    showAdminNotice('API key saved successfully!', 'success');
                }
            } else {
                const errorMessage = response.data?.message || response.data || (keyType === 'ollama' ? 'Failed to save Ollama settings.' : 'Failed to save API key.');
                showAdminNotice(errorMessage, 'error');
            }
        })
        .fail(function(xhr, status, error) {
            showAdminNotice('Failed to save API key: ' + error + '. Please try again.', 'error');
        })
        .always(function() {
            $btn.prop('disabled', false).text(originalText);
        });
    });

    // API Key Testing functionality
    $('.aiohm-test-api-key').on('click', function(){
        const $btn = $(this);
        const targetId = $btn.data('target');
        const keyType = $btn.data('type');
        const originalText = $btn.text();


        // Check if AJAX object exists
        if (typeof aiohm_admin_settings_ajax === 'undefined') {
            showAdminNotice('JavaScript configuration error: AJAX data not loaded', 'error');
            return;
        }

        // Check if ajaxurl is available (use the localized version if standard ajaxurl is not available)
        const ajax_url = (typeof ajaxurl !== 'undefined') ? ajaxurl : aiohm_admin_settings_ajax.ajax_url;
        if (!ajax_url) {
            showAdminNotice('JavaScript configuration error: AJAX URL not available', 'error');
            return;
        }

        let postData = {
            action: keyType === 'ollama' ? 'aiohm_test_ollama_connection' : 'aiohm_check_api_key',
            nonce: aiohm_admin_settings_ajax.nonce,
            key_type: keyType
        };

        if (keyType === 'ollama') {
            const serverUrl = $('#private_llm_server_url').val();
            const model = $('#private_llm_model').val();
            
            if (!serverUrl) {
                showAdminNotice('Please enter a server URL before testing.', 'warning');
                return;
            }
            
            postData.server_url = serverUrl;
            postData.model = model;
        } else {
            const apiKey = $('#' + targetId).val();
            
            if (!apiKey) {
                showAdminNotice('Please enter an API key before testing.', 'warning');
                return;
            }
            
            postData.api_key = apiKey;
        }

        $btn.prop('disabled', true).html('<span class="spinner is-active spinner-with-margin"></span>');

        $.post(ajax_url, postData)
        .done(function(response) {
            if (response.success) {
                showAdminNotice(response.data.message, 'success');
            } else {
                const errorMessage = response.data?.message || response.data || 'An unknown error occurred.';
                showAdminNotice(errorMessage, 'error');
            }
        })
        .fail(function(xhr, status, error) {
            showAdminNotice('A server error occurred: ' + error + '. Please try again.', 'error');
        })
        .always(function() {
            $btn.prop('disabled', false).text(originalText);
        });
    });

    const fetchOllamaModels = function(serverUrl) {
        const ajax_url = (typeof ajaxurl !== 'undefined') ? ajaxurl : aiohm_admin_settings_ajax.ajax_url;
        if (!ajax_url) {
            showAdminNotice('JavaScript configuration error: AJAX URL not available', 'error');
            return;
        }

        const $modelNotice = $('#ollama-model-notice');
        $modelNotice.text('Detecting Ollama models...');

        $.post(ajax_url, {
            action: 'aiohm_test_ollama_connection',
            nonce: aiohm_admin_settings_ajax.nonce,
            server_url: serverUrl,
            fetch_models: 1
        })
        .done(function(response) {
            if (!response.success) {
                $modelNotice.html('<span class="aiohm-error-message">' + (response.data?.message || response.data || 'Failed to detect Ollama models.') + '</span>');
                return;
            }

            const models = response.data.models || [];
            const $modelSelect = $('#private_llm_model');
            const currentModel = $modelSelect.val();

            if (models.length === 0) {
                $modelSelect.empty();
                $modelNotice.html('<span class="aiohm-warning-message">No Ollama models were found on the local server. Install one using <code>ollama pull &lt;model&gt;</code>, then click Detect Local again.</span>');
                return;
            }

            $modelSelect.empty();
            models.forEach(function(model) {
                const selected = currentModel && currentModel === model ? ' selected' : '';
                $modelSelect.append('<option value="' + model + '"' + selected + '>' + model + '</option>');
            });
            if (!models.includes(currentModel)) {
                $modelSelect.prop('selectedIndex', 0);
            }
            $modelNotice.html('<span class="aiohm-success-message">Detected ' + models.length + ' Ollama model(s). Select one and save.</span>');
        })
        .fail(function(xhr, status, error) {
            $modelNotice.html('<span class="aiohm-error-message">Ollama detection failed: ' + error + '</span>');
        });
    };

    $('.aiohm-detect-ollama').on('click', function() {
        const $urlField = $('#private_llm_server_url');
        if ($urlField.length) {
            const url = $urlField.val().trim() || 'http://localhost:11434';
            $urlField.val(url).trigger('change');
            fetchOllamaModels(url);
        }
    });

    $('#private_llm_server_url').on('input', debounce(function() {
        const url = $(this).val().trim();
        if (url) {
            fetchOllamaModels(url);
        }
    }, 450));

	// AI Usage Statistics functionality
	function loadUsageStats() {
	    if (typeof aiohm_admin_settings_ajax === 'undefined') {
	        showUsageError();
	        return;
	    }

	    const ajax_url = (typeof ajaxurl !== 'undefined') ? ajaxurl : aiohm_admin_settings_ajax.ajax_url;
        $.post(ajax_url, {
            action: 'aiohm_get_usage_stats',
            nonce: aiohm_admin_settings_ajax.nonce
        })
        .done(function(response) {
            if (response.success && response.data) {
                updateUsageDisplay(response.data);
            } else {
                showUsageError();
            }
        })
        .fail(function() {
            showUsageError();
        });
    }

    function updateUsageDisplay(data) {
        // Update main stats cards
        $('#total-tokens-30d').text(formatNumber(data.total_tokens_30d || 0));
        $('#tokens-today').text(formatNumber(data.tokens_today || 0));
        $('#estimated-cost').text('$' + (data.estimated_cost || '0.00'));
        
        // Update breakdown table
        const providers = ['openai', 'gemini', 'claude', 'shareai'];
        providers.forEach(provider => {
            const providerData = data.providers?.[provider] || {};
            $('#' + provider + '-tokens').text(formatNumber(providerData.tokens || 0));
            $('#' + provider + '-requests').text(formatNumber(providerData.requests || 0));
            $('#' + provider + '-cost').text('$' + (providerData.cost || '0.00'));
        });
    }

    function showUsageError() {
        $('.stat-value, .tokens-count, .requests-count, .cost-estimate').text('Error');
    }

    function formatNumber(num) {
        if (num === 0) return '0';
        if (num < 1000) return num.toString();
        if (num < 1000000) return (num / 1000).toFixed(1) + 'K';
        return (num / 1000000).toFixed(1) + 'M';
    }

    // Refresh usage stats button
    $('#refresh-usage-stats').on('click', function() {
        const $btn = $(this);
        const originalHtml = $btn.html();
        
        $btn.prop('disabled', true).html('<span class="spinner is-active spinner-with-margin"></span> Loading...');
        
        loadUsageStats();
        
        setTimeout(() => {
            $btn.prop('disabled', false).html(originalHtml);
        }, 2000);
    });

	// Load usage stats on page load without blocking settings controls.
	if (typeof aiohm_admin_settings_ajax !== 'undefined') {
	    loadUsageStats();
	} else {
	    showUsageError();
	}

    // Server preset selection functionality
    const $serverPreset = $('#server_preset');
    const $serverUrl = $('#private_llm_server_url');
    
    // Server presets
    const serverPresets = {
        'localhost': 'http://localhost:11434',
        'servbay': 'https://ollama.servbay.host/',
        'custom': ''
    };
    
    // Initialize preset selection based on current URL
    function initializeServerPreset() {
        const currentUrl = $serverUrl.val();
        let selectedPreset = 'custom';
        
        for (const [preset, url] of Object.entries(serverPresets)) {
            if (url && currentUrl === url) {
                selectedPreset = preset;
                break;
            }
        }
        
        $serverPreset.val(selectedPreset);
        updateServerUrlField(selectedPreset);
    }
    
    // Update server URL field based on preset selection
    function updateServerUrlField(preset) {
        if (preset === 'custom') {
            $serverUrl.prop('readonly', false).attr('placeholder', 'http://your-server.com:8080');
        } else if (preset === 'localhost') {
            $serverUrl.prop('readonly', false).attr('placeholder', 'http://localhost:11434');
            // Set default if empty
            if (!$serverUrl.val()) {
                $serverUrl.val(serverPresets[preset]);
            }
        } else {
            $serverUrl.prop('readonly', true).val(serverPresets[preset]);
        }
    }
    
    // Handle preset selection change
    $serverPreset.on('change', function() {
        const selectedPreset = $(this).val();
        updateServerUrlField(selectedPreset);

        if (selectedPreset === 'localhost') {
            // Only set default if field is empty
            if (!$serverUrl.val()) {
                $serverUrl.val(serverPresets[selectedPreset]).trigger('input');
            }
        } else if (selectedPreset === 'servbay') {
            $serverUrl.val(serverPresets[selectedPreset]).trigger('input');
        }
    });

    // Initialize on page load
    if ($serverPreset.length && $serverUrl.length) {
        initializeServerPreset();
    }

    // Save Settings functionality
    $(document).off('click.aiohmSaveSettings', '.aiohm-save-privacy-consent').on('click.aiohmSaveSettings', '.aiohm-save-privacy-consent', function(e) {
        e.preventDefault();
        const $btn = $(this);
        const originalText = $btn.text();
        const consentValue = $('#external_api_consent').is(':checked') ? '1' : '0';
        const selectedProvider = $('.aiohm-provider-selection input[type="radio"]:checked:not(:disabled)').val()
            || $('input[name="aiohm_kb_settings[default_ai_provider]"]:checked:not(:disabled)').val();

        if (typeof aiohm_admin_settings_ajax === 'undefined') {
            showAdminNotice('JavaScript configuration error: AJAX data not loaded', 'error');
            return;
        }

        // Disable button and show loading state
        $btn.prop('disabled', true).text('Saving...');

        const ajax_url = (typeof ajaxurl !== 'undefined') ? ajaxurl : aiohm_admin_settings_ajax.ajax_url;
        const saveSetting = function(settingKey, settingValue) {
            return $.post(ajax_url, {
                action: 'aiohm_save_setting',
                nonce: aiohm_admin_settings_ajax.nonce,
                setting_key: settingKey,
                setting_value: settingValue
            }).then(function(response) {
                if (response && response.success) {
                    return response;
                }

                return $.Deferred().reject(response && response.data ? response.data : 'Unknown error').promise();
            });
        };

        const saveProvider = selectedProvider
            ? saveSetting('default_ai_provider', selectedProvider)
            : $.Deferred().resolve().promise();

        saveProvider
        .then(function() {
            return saveSetting('external_api_consent', consentValue);
        })
        .done(function() {
            const savedParts = selectedProvider ? 'Default provider and privacy settings saved.' : 'Privacy settings saved.';
            showAdminNotice(savedParts, 'success');
            $('.aiohm-save-default-provider').slideUp(300);
            $('.aiohm-provider-option').removeClass('loading success error');
        })
        .fail(function(error) {
            const message = typeof error === 'string' ? error : 'Unknown error';
            showAdminNotice('Error saving settings: ' + message, 'error');
        })
        .always(function() {
            // Reset button state
            $btn.prop('disabled', false).text(originalText);
        });
    });

    // Backward-compatible direct privacy save support.
    $('.aiohm-save-privacy-only').on('click', function(e) {
        e.preventDefault();
        const $btn = $(this);
        const originalText = $btn.text();
        const consentValue = $('#external_api_consent').is(':checked') ? '1' : '0';

        $btn.prop('disabled', true).text('Saving...');

        const ajax_url = (typeof ajaxurl !== 'undefined') ? ajaxurl : aiohm_admin_settings_ajax.ajax_url;
        $.post(ajax_url, {
            action: 'aiohm_save_setting',
            nonce: aiohm_admin_settings_ajax.nonce,
            setting_key: 'external_api_consent',
            setting_value: consentValue
        })
        .done(function(response) {
            if (response.success) {
                if (consentValue === '1') {
                    showAdminNotice('Privacy consent enabled - External API calls are now allowed.', 'success');
                } else {
                    showAdminNotice('Privacy consent disabled - External API calls are now blocked.', 'success');
                }
            } else {
                showAdminNotice('Error saving privacy settings: ' + (response.data || 'Unknown error'), 'error');
            }
        })
        .fail(function(xhr, status, error) {
            showAdminNotice('Failed to save privacy settings: ' + error + '. Please try again.', 'error');
        })
        .always(function() {
            // Reset button state
            $btn.prop('disabled', false).text(originalText);
        });
    });
});
