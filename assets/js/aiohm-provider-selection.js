/**
 * AIOHM AI Provider Selection JavaScript
 * Handles the visual provider selection interface
 */

(function($) {
    'use strict';

    class AIOHM_ProviderSelection {
        constructor() {
            this.init();
        }

        init() {
            this.bindEvents();
            this.updateSelection();
        }

        bindEvents() {
            // Handle provider option clicks
            $(document).on('click', '.aiohm-provider-option', (e) => {
                const $option = $(e.currentTarget);
                const $radio = $option.find('input[type="radio"]');
                
                // Don't allow clicking disabled options
                if ($option.hasClass('disabled') || $radio.is(':disabled')) {
                    e.preventDefault();
                    this.showNotice('This provider requires an API key. Please configure it in the sections below.', 'warning');
                    return;
                }
                
                // Update selection
                $('.aiohm-provider-option').removeClass('selected');
                $option.addClass('selected');
                $radio.prop('checked', true);
                
                // Show save button with slide animation
                $('.aiohm-save-default-provider').slideDown(300);
            });

            // Handle save button
            $(document).on('click', '.aiohm-save-default-provider', (e) => {
                e.preventDefault();
                this.saveSelection();
            });

            // Update selection on page load
            this.updateSelection();
        }

        updateSelection() {
            // Mark the currently selected provider - try multiple selectors for robustness
            let $checkedRadios = $('.aiohm-provider-selection input[type="radio"]:checked');
            
            // Fallback selector if the main one doesn't work
            if ($checkedRadios.length === 0) {
                $checkedRadios = $('input[name="aiohm_kb_settings[default_ai_provider]"]:checked');
            }
            
            $checkedRadios.each(function() {
                $(this).closest('.aiohm-provider-option').addClass('selected');
            });
        }

        saveSelection() {
            const $button = $('.aiohm-save-default-provider');
            const originalText = $button.text();
            
            // Give a small delay to ensure radio button state is updated
            setTimeout(() => {
                // Check if any provider is selected - try multiple selectors for robustness
                let selectedProvider = $('.aiohm-provider-selection input[type="radio"]:checked:not(:disabled)');
                
                // Fallback selector if the main one doesn't work
                if (selectedProvider.length === 0) {
                    selectedProvider = $('input[name="aiohm_kb_settings[default_ai_provider]"]:checked:not(:disabled)');
                }
                
                // Also check for visually selected options as another fallback
                if (selectedProvider.length === 0) {
                    const $selectedOption = $('.aiohm-provider-option.selected:not(.disabled)');
                    if ($selectedOption.length > 0) {
                        selectedProvider = $selectedOption.find('input[type="radio"]');
                        // Force check the radio button if it's not checked
                        selectedProvider.prop('checked', true);
                    }
                }
                
                if (selectedProvider.length === 0) {
                    this.showNotice('Please select an AI provider before saving.', 'warning');
                    return;
                }
                
                this.performSave(selectedProvider, $button, originalText);
            }, 50);
        }
        
        performSave(selectedProvider, $button, originalText) {
            // Show loading state
            $button.text('Saving...').prop('disabled', true);
            $('.aiohm-provider-option').addClass('loading');

            // Use the existing aiohm_save_setting AJAX action
            const ajax_url = (typeof ajaxurl !== 'undefined')
                ? ajaxurl
                : ((typeof aiohm_admin_settings_ajax !== 'undefined' && aiohm_admin_settings_ajax.ajax_url)
                    ? aiohm_admin_settings_ajax.ajax_url
                    : ((typeof aiohm_admin_modes_data !== 'undefined' && aiohm_admin_modes_data.ajax_url) ? aiohm_admin_modes_data.ajax_url : ''));
            const nonce = (typeof aiohm_admin_settings_ajax !== 'undefined' && aiohm_admin_settings_ajax.nonce)
                || (typeof aiohm_admin_modes_data !== 'undefined' && aiohm_admin_modes_data.nonce)
                || '';

            $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'aiohm_save_setting',
                    nonce: nonce,
                    setting_key: 'default_ai_provider',
                    setting_value: selectedProvider.val()
                },
                success: (response) => {
                    if (response.success) {
                        // Show success state
                        $('.aiohm-provider-option.selected').addClass('success').removeClass('loading');
                        $button.text('Saved!').removeClass('aiohm-shareai-btn').addClass('button-primary');
                        
                        // Hide save button after delay
                        setTimeout(() => {
                            $button.slideUp(300);
                            $('.aiohm-provider-option').removeClass('success loading');
                            $button.text(originalText).css('background', '');
                        }, 2000);
                        
                        // Show success notice
                        this.showNotice('Provider settings saved successfully!', 'success');
                    } else {
                        this.handleSaveError(response.data || 'Unknown error occurred');
                    }
                },
                error: (xhr, status, error) => {
                    this.handleSaveError(`Network error: ${error}`);
                },
                complete: () => {
                    $button.prop('disabled', false);
                }
            });
        }

        handleSaveError(message) {
            // Show error state
            $('.aiohm-provider-option').removeClass('loading').addClass('error');
            $('.aiohm-save-default-provider').text('Save Selection').css('background', '');
            
            // Show error notice
            this.showNotice(`Error saving settings: ${message}`, 'error');
            
            // Remove error state after delay
            setTimeout(() => {
                $('.aiohm-provider-option').removeClass('error');
            }, 3000);
        }

        showNotice(message, type = 'success') {
            // Remove existing notices
            $('.aiohm-provider-notice').remove();
            
            // Create notice
            const notice = $(`
                <div class="aiohm-provider-notice notice notice-${type} is-dismissible">
                    <p>${message}</p>
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text">Dismiss this notice.</span>
                    </button>
                </div>
            `);
            
            // Insert notice in proper admin notice area
            const $adminNoticesArea = $('.wrap h1').first();
            if ($adminNoticesArea.length > 0) {
                $adminNoticesArea.after(notice);
            } else {
                // Fallback to settings form if no proper admin area found
                $('.aiohm-settings-form').prepend(notice);
            }
            
            // Handle dismiss
            notice.find('.notice-dismiss').on('click', () => {
                notice.fadeOut(() => notice.remove());
            });
            
            // Auto-dismiss success and warning notices
            if (type === 'success' || type === 'warning') {
                setTimeout(() => {
                    notice.fadeOut(() => notice.remove());
                }, type === 'success' ? 5000 : 7000);
            }
        }
    }

    // Initialize when document is ready
    $(document).ready(() => {
        new AIOHM_ProviderSelection();
    });

})(jQuery);
