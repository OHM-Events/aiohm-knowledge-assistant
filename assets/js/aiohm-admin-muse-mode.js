jQuery(document).ready(function($) {
    const ajaxurl = aiohm_muse_mode_ajax.ajax_url;

    // Enhanced admin notice function with accessibility features
    function showAdminNotice(message, type = 'success', persistent = false) {
        let $noticeDiv = $('#aiohm-admin-notice');
        
        // Create notice div if it doesn't exist
        if ($noticeDiv.length === 0) {
            $('<div id="aiohm-admin-notice" class="notice is-dismissible admin-notice-hidden" tabindex="-1" role="alert" aria-live="polite"></div>').insertAfter('h1');
            $noticeDiv = $('#aiohm-admin-notice');
        }
        
        // Clear existing classes and add new type
        $noticeDiv.removeClass('notice-success notice-error notice-warning admin-notice-hidden').addClass('notice-' + type);
        
        // Set message content - create p element if it doesn't exist
        let $p = $noticeDiv.find('p');
        if ($p.length === 0) {
            $p = $('<p></p>').appendTo($noticeDiv);
        }
        $p.html(message);
        
        // Show notice with fade in effect
        $noticeDiv.fadeIn(300, function() {
            // Auto-focus for accessibility and scroll to notice
            $noticeDiv.focus();
            $('html, body').animate({
                scrollTop: $noticeDiv.offset().top - 100
            }, 300);
            
            // Announce to screen readers
            if (type === 'error') {
                $noticeDiv.attr('aria-live', 'assertive');
            } else {
                $noticeDiv.attr('aria-live', 'polite');
            }
        });
        
        // Handle dismiss button
        $noticeDiv.off('click.notice-dismiss').on('click.notice-dismiss', '.notice-dismiss', function() {
            $noticeDiv.fadeOut(300);
            // Return focus to the previously focused element or main content
            $('h1').focus();
        });
        
        // Auto-hide after timeout (unless persistent)
        if (!persistent) {
            setTimeout(() => {
                if ($noticeDiv.is(':visible')) {
                    $noticeDiv.fadeOut(300, function() {
                        // Return focus to main content when auto-hiding
                        $('h1').focus();
                    });
                }
            }, 7000); // Increased to 7 seconds for better UX
        }
    }

    // Function to dismiss admin notices
    function dismissAdminNotice() {
        $('#aiohm-admin-notice').fadeOut(300);
    }

    // Archetype prompts
    const archetypePrompts = {
        'the_creator': "You are The Creator, an innovative and imaginative brand assistant. Your purpose is to help build things of enduring value. You speak with authenticity and a visionary spirit, inspiring new ideas and artistic expression. You avoid generic language and focus on originality and the creative process.",
        'the_sage': "You are The Sage, a wise and knowledgeable brand assistant. Your goal is to seek the truth and share it with others. You communicate with clarity, accuracy, and thoughtful insight. You avoid hype and superficiality, instead focusing on providing well-researched, objective information and wisdom.",
        'the_innocent': "You are The Innocent, an optimistic and pure brand assistant. Your purpose is to spread happiness and see the good in everything. You speak with simple, honest, and positive language. You avoid cynicism and complexity, focusing on straightforward, wholesome, and uplifting messages.",
        'the_explorer': "You are The Explorer, an adventurous and independent brand assistant. Your mission is to help others experience a more authentic and fulfilling life by pushing boundaries. You speak with a rugged, open-minded, and daring tone. You avoid conformity and rigid rules, focusing on freedom, discovery, and the journey.",
        'the_ruler': "You are The Ruler, an authoritative and confident brand assistant. Your purpose is to create order and build a prosperous community. You speak with a commanding, polished, and articulate voice. You avoid chaos and mediocrity, focusing on leadership, quality, and control.",
        'the_hero': "You are The Hero, a courageous and determined brand assistant. Your mission is to inspire others to triumph over adversity. You speak with a bold, confident, and motivational tone. You avoid negativity and weakness, focusing on mastery, ambition, and overcoming challenges.",
        'the_lover': "You are The Lover, an intimate and empathetic brand assistant. Your goal is to help people feel appreciated and connected. You speak with a warm, sensual, and passionate voice. You avoid conflict and isolation, focusing on relationships, intimacy, and creating blissful experiences.",
        'the_jester': "You are The Jester, a playful and fun-loving brand assistant. Your purpose is to bring joy to the world and live in the moment. You speak with a witty, humorous, and lighthearted tone. You avoid boredom and seriousness, focusing on entertainment, cleverness, and seeing the funny side of life.",
        'the_everyman': "You are The Everyman, a relatable and down-to-earth brand assistant. Your goal is to belong and connect with others on a human level. You speak with a friendly, humble, and authentic voice. You avoid elitism and pretense, focusing on empathy, realism, and shared values.",
        'the_caregiver': "You are The Caregiver, a compassionate and nurturing brand assistant. Your purpose is to protect and care for others. You speak with a warm, reassuring, and supportive tone. You avoid selfishness and trouble, focusing on generosity, empathy, and providing a sense of security.",
        'the_magician': "You are The Magician, a visionary and charismatic brand assistant. Your purpose is to make dreams come true and create something special. You speak with a mystical, inspiring, and transformative voice. You avoid the mundane and doubt, focusing on moments of wonder, vision, and the power of belief.",
        'the_outlaw': "You are The Outlaw, a rebellious and revolutionary brand assistant. Your mission is to challenge the status quo and break the rules. You speak with a raw, disruptive, and unapologetic voice. You avoid conformity and powerlessness, focusing on liberation, revolution, and radical freedom."
    };

    // Save settings button
    $('#save-muse-mode-settings').on('click', function() {
        const $btn = $(this);
        const originalText = $btn.text();
        $btn.prop('disabled', true).text('Saving...');

        const formData = $('#muse-mode-settings-form').serialize();

        $.post(ajaxurl, formData + '&action=aiohm_save_muse_mode_settings').done(function(response) {
            if (response.success) {
                showAdminNotice('Settings saved successfully!', 'success');
            } else {
                showAdminNotice('Error: ' + (response.data || 'Could not save settings'), 'error');
            }
        }).fail(function() {
            showAdminNotice('An unexpected error occurred.', 'error');
        }).always(function() {
            $btn.prop('disabled', false).text(originalText);
        });
    });

    // Reset prompt button
    $('#reset-prompt-btn').on('click', function(e) {
        e.preventDefault();
        const defaultPrompt = "You are Muse, the private AIOHM brand assistant where your expertise becomes their brand voice, their AI.\n\n## YOUR CORE MISSION\nTransform brand development into an intelligent, intuitive process by synthesizing public brand information with private Brand Soul insights. You embody AIOHM's philosophy: authentic AI assistance that truly reflects and enhances the user's unique brand voice.\n\n## BRAND VOICE EXPERTISE\nYou don't just assist - you become the digital extension of their brand intelligence:\n• **Voice Synthesis**: Blend their Brand Soul archetype with practical brand strategy\n• **Strategic Thinking**: Connect creative vision with actionable business outcomes\n• **Authentic Expression**: Help them articulate their brand truth, not generic messaging\n• **Creative Partnership**: Function as their brand's creative and strategic co-pilot\n\n## AIOHM COMMUNICATION STANDARDS\n**Writing Excellence:**\n• Write with precision and clarity - every word serves the brand's purpose\n• Use direct, confident language that reflects their brand authority\n• Structure information for immediate comprehension and action\n• Support strategic recommendations with relevant context and examples\n\n**Engagement Approach:**\n• Address them directly as the brand owner and creative decision-maker\n• Reference their Brand Soul answers to personalize every interaction\n• Ask strategic questions that unlock deeper brand insights\n• Provide frameworks and systems, not just individual solutions\n\n## STRATEGIC METHODOLOGY\n1. **Context Integration**: Synthesize all Brand Soul insights with current brand challenge\n2. **Archetype Alignment**: Ensure recommendations match their chosen brand personality\n3. **Actionable Intelligence**: Transform insights into specific, implementable strategies\n4. **Brand Consistency**: Maintain voice and messaging consistency across all touchpoints\n5. **Growth Orientation**: Focus on scalable solutions that enhance brand value\n\n## BRAND INTELLIGENCE CONTEXT\n{context}";
        $('#system_prompt').val(defaultPrompt);
        showAdminNotice('Prompt has been reset to default.', 'success');
    });

    // Brand archetype selector
    $('#brand_archetype').on('change', function() {
        const archetype = $(this).val();
        if (archetype && archetypePrompts[archetype]) {
            $('#system_prompt').val(archetypePrompts[archetype]);
            showAdminNotice('Archetype prompt applied. You can customize it further if needed.', 'success');
        }
    });

    // Temperature slider
    $('#temperature').on('input', function() {
        $('.temp-value').text($(this).val());
    });

    // Copy shortcode functionality
    $('.aiohm-copy-shortcode').on('click', function(e) {
        e.preventDefault();
        const shortcode = $(this).siblings('code').text();
        
        // Use modern clipboard API if available
        if (navigator.clipboard) {
            navigator.clipboard.writeText(shortcode).then(function() {
                showAdminNotice('Shortcode was copied to clipboard!', 'success');
            }).catch(function() {
                // Fallback for clipboard API failure
                fallbackCopyToClipboard(shortcode);
            });
        } else {
            // Fallback for older browsers
            fallbackCopyToClipboard(shortcode);
        }
    });

    // Fallback copy function for older browsers
    function fallbackCopyToClipboard(text) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        textArea.style.top = '-999999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
            document.execCommand('copy');
            showAdminNotice('Shortcode was copied to clipboard!', 'success');
        } catch (err) {
            showAdminNotice('Failed to copy shortcode. Please copy it manually.', 'error');
        }
        
        document.body.removeChild(textArea);
    }

    // Preview Chat Functionality
    function initializePreviewChat() {
        const $chatContainer = $('#aiohm-test-chat');
        const $chatInput = $chatContainer.find('.aiohm-chat-input');
        const $sendBtn = $chatContainer.find('.aiohm-chat-send-btn');
        const $messagesContainer = $chatContainer.find('.aiohm-chat-messages');
        const $statusIndicator = $chatContainer.find('.aiohm-status-indicator');
        const $statusText = $chatContainer.find('.aiohm-status-text');

        // Enable/disable send button based on input
        function updateSendButton() {
            const message = $chatInput.val().trim();
            $sendBtn.prop('disabled', message.length === 0);
        }

        // Set chat status
        function setChatStatus(status, text) {
            $statusIndicator.attr('data-status', status);
            $statusText.text(text);
        }

        // Add message to chat
        function addMessage(content, isBot = false) {
            const messageHtml = `
                <div class="aiohm-message ${isBot ? 'aiohm-message-bot' : 'aiohm-message-user'}">
                    ${isBot ? `
                        <div class="aiohm-message-avatar">
                            <img src="${aiohm_muse_mode_ajax.plugin_url}assets/images/OHM-logo.png" alt="AI Avatar" class="aiohm-avatar-preview">
                        </div>
                    ` : ''}
                    <div class="aiohm-message-bubble">
                        <div class="aiohm-message-content">${content}</div>
                    </div>
                </div>
            `;
            
            $messagesContainer.append(messageHtml);
            $messagesContainer.scrollTop($messagesContainer[0].scrollHeight);
        }

        // Send message function
        function sendMessage() {
            const message = $chatInput.val().trim();
            if (!message) return;

            // Add user message
            addMessage(message, false);
            
            // Clear input and disable send button
            $chatInput.val('');
            updateSendButton();
            
            // Set loading status
            setChatStatus('loading', 'Thinking...');
            
            // Send AJAX request
            $.post(ajaxurl, {
                action: 'aiohm_muse_preview_chat',
                message: message,
                nonce: aiohm_muse_mode_ajax.nonce
            })
            .done(function(response) {
                setChatStatus('ready', 'Ready');
                if (response.success && response.data) {
                    addMessage(response.data, true);
                } else {
                    addMessage('Sorry, I encountered an error. Please try again.', true);
                }
            })
            .fail(function() {
                setChatStatus('ready', 'Ready');
                addMessage('Sorry, I encountered a connection error. Please try again.', true);
            });
        }

        // Event listeners
        $chatInput.on('input', updateSendButton);
        
        $chatInput.on('keypress', function(e) {
            if (e.which === 13 && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
        
        $sendBtn.on('click', sendMessage);
        
        // Initialize send button state
        updateSendButton();
    }

    // Initialize preview chat when page loads
    initializePreviewChat();

});