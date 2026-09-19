/**
 * Agentic Chat Interface
 *
 * Multi-instance: each .agentic-chat-container[data-agent-id] (or #agentic-chat)
 * boots its own isolated state. Forms use class .agentic-chat-form; lookups are
 * scoped to the root so shortcode + modal (or multiple shortcodes) work together.
 */
(function() {
    'use strict';

    // Uninitialized chat forms must not native-submit (page reload).
    document.addEventListener('submit', function (e) {
        var target = e.target;
        if (!target || !target.matches) return;
        if (target.matches('.agentic-chat-form, #agentic-chat-form') && !target._agenticInitialized) {
            e.preventDefault();
        }
    });

    function bootChatRoot(root) {
        if (!root || root._agenticChatBooted) {
            return;
        }
        root._agenticChatBooted = true;

        function q(sel) {
            return root.querySelector(sel);
        }
        function qid(id) {
            return root.querySelector('#' + id);
        }

    // Get current agent from data attribute (supports both admin template ID and shortcode dynamic ID)
    const chatContainer = root;
    const currentAgentId = chatContainer ? chatContainer.dataset.agentId || 'default' : 'default';

    // Page context — combines explicit data-context attribute with auto-detected page info.
    // Sent with every message so the agent knows where the visitor is and how they arrived.
    const explicitContext = chatContainer ? chatContainer.dataset.context || '' : '';
    const autoContext = [
        'Page: ' + document.title,
        'URL: ' + location.pathname,
        document.referrer ? 'Referrer: ' + document.referrer : ''
    ].filter(Boolean).join(' | ');
    const pageContext = (explicitContext ? explicitContext + '\n' : '') + autoContext;

    // State - keyed by agent
    let conversationHistory = [];
    let sessionId = localStorage.getItem(`agentic_session_${currentAgentId}`) || generateUUID();
    let isProcessing = false;
    let totalTokens = 0;
    let totalCost = 0;
    let pendingImage = null; // { dataUrl, mimeType, name }
    let turnstileWidgetId = null; // Cloudflare Turnstile widget ID
    let turnstileToken    = '';   // Latest token from invisible Turnstile callback
    let handoffContextSent = false; // Guards handoff_from/handoff_context to the first message only

    // TTS output state (binary audio streaming via Web Audio API)
    let ttsAudioCtx = null;
    let ttsCurrentSource = null;
    let ttsPending = false; // true while waiting for TTS audio so typing indicator stays visible

    // Slash command state
    let slashPaletteActive = false;
    let slashPaletteIndex = 0;
    let debugMode = false;

    // Store session ID for this agent
    localStorage.setItem(`agentic_session_${currentAgentId}`, sessionId);

    // Elements
    const form = q('.agentic-chat-form') || qid('agentic-chat-form');
    const input = q('.agentic-chat-input') || q('textarea') || qid('agentic-input');
    const messages = q('.agentic-chat-messages') || qid('agentic-messages');
    const sendBtn = q('.agentic-send-btn') || qid('agentic-send');
    const typingIndicator = q('.agentic-typing-indicator') || qid('agentic-typing');
    const clearBtn = q('.agentic-clear-chat') || qid('agentic-clear-chat');
    const stats = q('.agentic-stats') || qid('agentic-stats');
    const agentSelect = q('.agentic-agent-dropdown') || qid('agentic-agent-select');

    // Initialize
    function init() {
        if (!form) return;
        if (form._agenticInitialized) return;
        form._agenticInitialized = true;

        form.addEventListener('submit', handleSubmit);
        input.addEventListener('keydown', handleKeydown);
        input.addEventListener('input', handleInputChange);
        input.addEventListener('input', autoResize);
        initSlashPalette();
        if (clearBtn) {
            clearBtn.addEventListener('click', clearConversation);
        }

        // New Chat button (header)
        const newChatBtn = chatContainer ? chatContainer.querySelector('.agentic-new-chat-btn') : null;
        if (newChatBtn) {
            newChatBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                clearConversation();
            });
        }

        // Minimize button (header)
        const minimizeBtn = chatContainer ? chatContainer.querySelector('.agentic-minimize-btn') : null;
        if (minimizeBtn) {
            minimizeBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                chatContainer.classList.toggle('agentic-chat-minimized');
            });
            // Also allow clicking the header itself to expand when minimized
            const header = chatContainer.querySelector('.agentic-chat-header');
            if (header) {
                header.addEventListener('click', function(e) {
                    if (chatContainer.classList.contains('agentic-chat-minimized')) {
                        chatContainer.classList.remove('agentic-chat-minimized');
                    }
                });
            }
        }

        // Handle agent switching
        if (agentSelect) {
            agentSelect.addEventListener('change', handleAgentSwitch);
        }

        // Handle suggested prompt clicks
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('agentic-prompt-btn') && root.contains(e.target)) {
                const prompt = e.target.getAttribute('data-prompt');
                if (prompt && input) {
                    if (/\[[^\]]+\]/.test(prompt)) {
                        // Prompt has a [placeholder] - let the user fill it in first.
                        input.value = prompt;
                        input.focus();
                        autoResize();
                    } else {
                        // One-click send for a snappy first experience.
                        input.value = prompt;
                        autoResize();
                        var _f = form;
                        if (_f && typeof _f.requestSubmit === 'function') { _f.requestSubmit(); }
                        else if (_f) { _f.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true })); }
                        else { input.focus(); }
                    }
                }
            }
        });

        // Handle copy button clicks on code blocks
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('agentic-copy-btn') && root.contains(e.target)) {
                var btn = e.target;
                var code = btn.nextElementSibling.textContent;
                var onCopied = function() {
                    btn.textContent = agenticChat.i18n.copied;
                    setTimeout(function() { btn.textContent = agenticChat.i18n.copy; }, 2000);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(code).then(onCopied).catch(function() {
                        fallbackCopy(code, onCopied);
                    });
                } else {
                    fallbackCopy(code, onCopied);
                }
            }
        });

        // Handle agent delegation button clicks — P0 Basic Multi-Agent Orchestration.
        // Now passes richer handoff context (last few turns + optional reasoning summary)
        // so the receiving agent has real shared understanding instead of just the last message.
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('agentic-delegate-btn') && root.contains(e.target)) {
                var agentId = e.target.dataset.agent;
                var handoffContext = '';

                // Collect last 4 turns for meaningful shared context (user + assistant).
                var recentTurns = conversationHistory.slice(-4);
                if (recentTurns.length > 0) {
                    handoffContext = 'Previous conversation context (handoff from another agent):\n';
                    recentTurns.forEach(function(turn) {
                        // Avoid a leading "user:"/"assistant:" line — Chat_Security's injection-pattern
                        // scanner treats that shape as a role-override attempt and blocks the message.
                        var roleLabel = turn.role === 'user' ? 'Previous user turn' : 'Previous agent turn';
                        handoffContext += roleLabel + ': ' + (turn.content || '').substring(0, 800) + '\n\n';
                    });
                    handoffContext = handoffContext.trim();
                }

                var base = (typeof agenticChat !== 'undefined' && agenticChat.adminUrl)
                    ? agenticChat.adminUrl
                    : '/wp-admin/';

                var url = base + 'admin.php?page=agentic-chat'
                    + '&agent=' + encodeURIComponent(agentId);

                if (handoffContext) {
                    // Pass via initial_message for backward compat + new handoff_context for richer data.
                    url += '&initial_message=' + encodeURIComponent(handoffContext)
                        + '&handoff_from=' + encodeURIComponent(
                            (typeof currentAgent !== 'undefined' && currentAgent) ? currentAgent : 'previous-agent'
                        );
                } else {
                    // Fallback to old simple last-message behavior.
                    var lastUserMsg = '';
                    for (var i = conversationHistory.length - 1; i >= 0; i--) {
                        if (conversationHistory[i].role === 'user') {
                            lastUserMsg = conversationHistory[i].content;
                            break;
                        }
                    }
                    url += '&initial_message=' + encodeURIComponent(lastUserMsg);
                }

                window.location.href = url;
            }
        });

        // Handle message action bar — copy response, thumbs up/down, regenerate
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.agentic-action-btn');
            if (!btn) return;

            var msgDiv = btn.closest('.agentic-message-agent');
            if (!msgDiv) return;

            // ── Copy response ──────────────────────────────────────────
            if (btn.classList.contains('agentic-action-copy')) {
                var contentDiv = msgDiv.querySelector('.agentic-message-content');
                var text = contentDiv ? (contentDiv.innerText || contentDiv.textContent) : '';
                var originalTitle = btn.title;
                var doConfirm = function() {
                    btn.classList.add('agentic-action-done');
                    btn.title = 'Copied!';
                    setTimeout(function() {
                        btn.classList.remove('agentic-action-done');
                        btn.title = originalTitle;
                    }, 2000);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(doConfirm).catch(function() { fallbackCopy(text, doConfirm); });
                } else {
                    fallbackCopy(text, doConfirm);
                }
            }

            // ── Thumbs up / down ───────────────────────────────────────
            if (btn.classList.contains('agentic-action-thumb')) {
                var thumb = btn.dataset.thumb; // 'up' or 'down'
                var allThumbs = msgDiv.querySelectorAll('.agentic-action-thumb');
                var alreadyActive = btn.classList.contains('agentic-action-active');

                allThumbs.forEach(function(t) { t.classList.remove('agentic-action-active'); });

                if (!alreadyActive) {
                    btn.classList.add('agentic-action-active');
                    // Fire-and-forget feedback — no UI dependency on the response.
                    if (typeof agenticChat !== 'undefined' && agenticChat.restUrl) {
                        var fd = new FormData();
                        fd.append('session_id', sessionId);
                        fd.append('thumb', thumb);
                        fetch(agenticChat.restUrl + 'feedback', {
                            method: 'POST',
                            headers: { 'X-WP-Nonce': agenticChat.nonce },
                            body: fd,
                        }).catch(function() {});
                    }
                }
            }

            // ── Regenerate ─────────────────────────────────────────────
            if (btn.classList.contains('agentic-action-regenerate')) {
                if (isProcessing) return;

                // Find the last user message in history.
                var lastUserMsg = '';
                for (var i = conversationHistory.length - 1; i >= 0; i--) {
                    if (conversationHistory[i].role === 'user') {
                        lastUserMsg = conversationHistory[i].content;
                        break;
                    }
                }
                if (!lastUserMsg) return;

                // Remove the last assistant turn from history so it isn't sent as context.
                if (conversationHistory.length && conversationHistory[conversationHistory.length - 1].role === 'assistant') {
                    conversationHistory.pop();
                }

                // Remove the message bubble from the DOM.
                msgDiv.remove();

                sendMessage(lastUserMsg);
            }
        });

        function fallbackCopy(text, callback) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            try { document.execCommand('copy'); callback(); } catch(e) {}
            document.body.removeChild(ta);
        }

        // Voice input (Web Speech API — graceful degradation)
        if (typeof agenticChat === 'undefined' || agenticChat.audio === '1') {
            initVoiceInput();
        }

        // TTS output (text-to-speech read-aloud toggle)
        if (typeof agenticChat !== 'undefined' && agenticChat.tts === '1') {
            initTTS();
        }

        // File/image upload
        if (typeof agenticChat === 'undefined' || agenticChat.vision === '1') {
            initFileUpload();
        } else {
            // Hide attach button when vision is disabled
            const attachBtn = qid('agentic-attach-btn');
            if (attachBtn) attachBtn.style.display = 'none';
        }

        // Load saved conversation for current agent
        loadConversation();

        // Initialize history panel
        initHistory();
        fetchStatus();

        // Initialize consent banner if required
        initConsent();

        // Initialize Cloudflare Turnstile if required
        initTurnstile();

        // Reveal chat container (hidden initially to prevent FOUC)
        if (chatContainer) {
            chatContainer.style.opacity = '1';
        }

        // Agent delegation: if an initial_message was passed via URL param, pre-fill and auto-submit.
        if (typeof agenticChat !== 'undefined' && agenticChat.initialMessage && input) {
            input.value = agenticChat.initialMessage;
            autoResize();
            setTimeout(function() {
                sendMessage(agenticChat.initialMessage);
            }, 600);
        }

        // P0 Basic Multi-Agent Orchestration: show professional handoff banner when applicable.
        if (typeof agenticChat !== 'undefined' && agenticChat.handoffFrom && chatContainer) {
            var banner = document.createElement('div');
            banner.className = 'agentic-handoff-banner';
            const handoffText = (agenticChat.i18n && agenticChat.i18n.handoffBanner)
                ? agenticChat.i18n.handoffBanner
                : 'Handed off from %s. Context from the previous agent has been shared.';
            banner.innerHTML = '<span class="dashicons dashicons-migrate"></span> ' +
                escapeHtml(handoffText).replace('%s', '<strong>' + escapeHtml(agenticChat.handoffFrom) + '</strong>');
            chatContainer.insertBefore(banner, chatContainer.firstChild);
        }
    }

    // Consent banner
    function initConsent() {
        if (typeof agenticChat === 'undefined' || agenticChat.consentEnabled !== '1') return;

        // Already accepted — cookie present.
        if (document.cookie.split(';').some(c => c.trim().startsWith('agentic_consent_given=1'))) return;

        const banner = qid('agentic-consent-banner');
        const textEl = qid('agentic-consent-text');
        const acceptBtn = qid('agentic-consent-accept');
        if (!banner || !acceptBtn) return;

        if (textEl) textEl.textContent = agenticChat.consentText || '';
        banner.style.display = '';

        // Disable input until consent is given.
        if (input) input.disabled = true;
        if (sendBtn) sendBtn.disabled = true;

        acceptBtn.addEventListener('click', function() {
            document.cookie = 'agentic_consent_given=1;path=/;max-age=31536000;SameSite=Lax';
            banner.style.display = 'none';
            if (input) { input.disabled = false; input.focus(); }
            if (sendBtn) sendBtn.disabled = false;
        });
    }

    // Cloudflare Turnstile initialization
    function initTurnstile() {
        if (typeof agenticChat === 'undefined' || !agenticChat.turnstileSiteKey) return;

        const container = qid('agentic-turnstile');
        if (!container) return;

        // Turnstile script loads async; wait for it to be ready.
        function renderWidget() {
            if (typeof turnstile !== 'undefined') {
                turnstileWidgetId = turnstile.render(container, {
                    sitekey:           agenticChat.turnstileSiteKey,
                    appearance:        'invisible',
                    callback:          function(token) { turnstileToken = token; },
                    'expired-callback': function()     { turnstileToken = ''; },
                    'error-callback':   function()     { turnstileToken = ''; },
                });
            } else {
                setTimeout(renderWidget, 200);
            }
        }
        renderWidget();
    }

    // Voice input via Web Speech API
    function initVoiceInput() {
        const voiceBtn = qid('agentic-voice-btn');
        if (!voiceBtn) return;

        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

        // Always show the button — handle unsupported/insecure at click time
        voiceBtn.style.display = '';

        function disableVoiceBtn( reason ) {
            voiceBtn.classList.add('agentic-voice-disabled');
            voiceBtn.setAttribute('disabled', 'disabled');
            voiceBtn.title = reason;
        }

        if (!SpeechRecognition) {
            disableVoiceBtn( 'Voice input requires HTTPS and a supported browser (Chrome, Edge, or Safari).' );
            return;
        }

        let recognition = null;
        let isListening = false;

        voiceBtn.addEventListener('click', function() {
            if (isListening) {
                recognition.stop();
                return;
            }

            recognition = new SpeechRecognition();
            recognition.lang = document.documentElement.lang || 'en-US';
            recognition.interimResults = true;
            recognition.continuous = true;
            recognition.maxAlternatives = 1;

            const existingText = input.value;

            recognition.onstart = function() {
                isListening = true;
                voiceBtn.classList.add('agentic-voice-active');
                input.placeholder = 'Listening...';
            };

            recognition.onresult = function(event) {
                let interim = '';
                let final = '';
                for (let i = 0; i < event.results.length; i++) {
                    if (event.results[i].isFinal) {
                        final += event.results[i][0].transcript;
                    } else {
                        interim += event.results[i][0].transcript;
                    }
                }
                // Show interim results live, append final when done
                input.value = existingText + (existingText ? ' ' : '') + (final || interim);
                autoResize();
            };

            recognition.onend = function() {
                isListening = false;
                voiceBtn.classList.remove('agentic-voice-active');
                input.placeholder = input.getAttribute('data-original-placeholder') || 'Type your message...';
                input.focus();
            };

            recognition.onerror = function(event) {
                isListening = false;
                voiceBtn.classList.remove('agentic-voice-active');
                input.placeholder = input.getAttribute('data-original-placeholder') || 'Type your message...';
                if (event.error === 'not-allowed') {
                    disableVoiceBtn( 'Microphone access denied. HTTPS is required — reload the page over https://.' );
                } else if (event.error !== 'aborted' && event.error !== 'no-speech') {
                    console.warn('Speech recognition error:', event.error);
                }
            };

            // Save original placeholder
            if (!input.getAttribute('data-original-placeholder')) {
                input.setAttribute('data-original-placeholder', input.placeholder);
            }

            recognition.start();
        });
    }

    // File/image upload
    function initFileUpload() {
        const attachBtn = qid('agentic-attach-btn');
        const fileInput = qid('agentic-file-input');
        const previewWrap = qid('agentic-image-preview');
        const previewImg = qid('agentic-preview-img');
        const removeBtn = qid('agentic-remove-image');
        const MAX_SIZE = 5 * 1024 * 1024; // 5 MB

        if (!attachBtn || !fileInput) return;

        attachBtn.addEventListener('click', function() {
            fileInput.click();
        });

        fileInput.addEventListener('change', function() {
            const file = fileInput.files[0];
            if (!file) return;

            if (!file.type.startsWith('image/')) {
                agenticUI.toast('Only image files are supported (JPEG, PNG, GIF, WebP).', 'warning');
                fileInput.value = '';
                return;
            }

            if (file.size > MAX_SIZE) {
                agenticUI.toast('Image must be under 5 MB.', 'warning');
                fileInput.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                pendingImage = {
                    dataUrl: e.target.result,
                    mimeType: file.type,
                    name: file.name
                };
                if (previewWrap && previewImg) {
                    previewImg.src = e.target.result;
                    previewWrap.style.display = 'flex';
                }
                // Adjust attach button style
                attachBtn.classList.add('agentic-attach-active');
            };
            reader.readAsDataURL(file);
        });

        if (removeBtn) {
            removeBtn.addEventListener('click', function() {
                clearPendingImage();
            });
        }
    }

    function clearPendingImage() {
        pendingImage = null;
        const fileInput = qid('agentic-file-input');
        const previewWrap = qid('agentic-image-preview');
        const attachBtn = qid('agentic-attach-btn');
        if (fileInput) fileInput.value = '';
        if (previewWrap) previewWrap.style.display = 'none';
        if (attachBtn) attachBtn.classList.remove('agentic-attach-active');
    }

    // Handle agent switch from dropdown
    function handleAgentSwitch(e) {
        const newAgentId = e.target.value;
        
        // Check if "Load more..." was selected
        if (newAgentId === 'load-more') {
            const adminUrl = (typeof agenticChat !== 'undefined' && agenticChat.adminAgentsUrl)
                ? agenticChat.adminAgentsUrl
                : '/wp-admin/admin.php?page=agentic-agents';
            window.open(adminUrl, '_blank');
            // Reset the select to the previously selected agent so chat doesn't switch
            if (e && e.target) {
                // Reset to the agent that was active for this chat (don't disrupt the conversation)
                e.target.value = currentAgentId;
            }
            return;
        }
        
        // Save selected agent to cookie (read server-side to avoid redirect flash)
        document.cookie = 'agentic_last_agent=' + encodeURIComponent(newAgentId) + ';path=/;max-age=31536000;SameSite=Lax';
        
        // Reload page with new agent (simplest approach for full state reset)
        const url = new URL(window.location.href);
        url.searchParams.set('agent', newAgentId);
        window.location.href = url.toString();
    }

    // Handle form submission
    async function handleSubmit(e) {
        e.preventDefault();

        const message = input.value.trim();
        if ((!message && !pendingImage) || isProcessing) return;

        // Close palette if open.
        closeSlashPalette();

        // Intercept slash commands.
        if (message.startsWith('/') && !pendingImage) {
            const parts = message.slice(1).split(/\s+/);
            const cmdName = parts[0].toLowerCase();
            const cmdArgs = parts.slice(1);
            const cmd = getSlashCommand(cmdName);
            if (cmd) {
                input.value = '';
                input.style.height = 'auto';
                await executeSlashCommand(cmd, cmdArgs);
                return;
            }
        }

        // Capture image before clearing
        const imageData = pendingImage ? { ...pendingImage } : null;

        // Add user message to UI (with optional image)
        addMessage(message, 'user', {}, imageData);

        // Clear input and image
        input.value = '';
        input.style.height = 'auto';
        clearPendingImage();

        // Add to history
        conversationHistory.push({ role: 'user', content: message || 'Describe this image.' });
        saveConversation();

        // Send to agent
        await sendMessage(message || 'Describe this image.', imageData);
    }

    // Handle keyboard shortcuts
    function handleKeydown(e) {
        if (slashPaletteActive) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                movePaletteSelection(1);
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                movePaletteSelection(-1);
                return;
            }
            if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                selectPaletteItem();
                return;
            }
            if (e.key === 'Escape') {
                e.preventDefault();
                closeSlashPalette();
                return;
            }
        }
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form.dispatchEvent(new Event('submit'));
        }
    }

    // React to input changes for slash palette.
    function handleInputChange() {
        const val = input.value;
        if (val.startsWith('/') && !val.includes(' ')) {
            const query = val.slice(1).toLowerCase();
            openSlashPalette(query);
        } else {
            closeSlashPalette();
        }
    }

    // Auto-resize textarea
    function autoResize() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 150) + 'px';
    }

    // Add message to UI
    function addMessage(content, role, meta = {}, imageData = null) {
        const div = document.createElement('div');
        div.className = `agentic-message agentic-message-${role}`;

        // Show attached image
        if (imageData && imageData.dataUrl) {
            const imgEl = document.createElement('img');
            imgEl.src = imageData.dataUrl;
            imgEl.className = 'agentic-chat-image';
            imgEl.alt = imageData.name || 'Attached image';
            div.appendChild(imgEl);
        }

        const contentDiv = document.createElement('div');
        contentDiv.className = 'agentic-message-content';
        if (content) {
            contentDiv.innerHTML = role === 'agent' ? renderMarkdown(content) : escapeHtml(content);
        }

        div.appendChild(contentDiv);

        // Add meta info for agent messages
        if (role === 'agent' && (meta.tools || meta.cached)) {
            const metaDiv = document.createElement('div');
            metaDiv.className = 'agentic-message-meta';
            
            if (meta.cached) {
                metaDiv.innerHTML += `<span class="agentic-cached-indicator" title="Response served from cache">⚡ cached</span>`;
            }

            div.appendChild(metaDiv);

            // Tool tags omitted — internal names are not meaningful to users.

            // Show proposal card for pending user-space changes
            if (meta.proposal) {
                const proposalDiv = renderProposalCard(meta.proposal);
                div.appendChild(proposalDiv);
            }
        }

        // Action bar (copy, thumbs up/down, regenerate) — agent messages only
        if (role === 'agent') {
            const actionsDiv = document.createElement('div');
            actionsDiv.className = 'agentic-message-actions';
            actionsDiv.innerHTML =
                '<button class="agentic-action-btn agentic-action-copy" title="Copy response" aria-label="Copy response">' +
                    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>' +
                '</button>' +
                '<button class="agentic-action-btn agentic-action-thumb" data-thumb="up" title="Good response" aria-label="Good response">' +
                    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3z"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg>' +
                '</button>' +
                '<button class="agentic-action-btn agentic-action-thumb" data-thumb="down" title="Bad response" aria-label="Bad response">' +
                    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3z"/><path d="M17 2h2.67A2.31 2.31 0 0 1 22 4v7a2.31 2.31 0 0 1-2.33 2H17"/></svg>' +
                '</button>' +
                '<button class="agentic-action-btn agentic-action-regenerate" title="Regenerate response" aria-label="Regenerate response">' +
                    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.17"/></svg>' +
                '</button>';
            div.appendChild(actionsDiv);
        }

        messages.appendChild(div);
        // Scroll so the top of the new message is visible within the chat container.
        // For short messages this matches scrolling to bottom; for long replies
        // the user reads from the start rather than landing mid-message.
        messages.scrollTop = div.offsetTop - messages.offsetTop;
        return div;
    }

    // Send message to API
    async function sendMessage(message, imageData = null, retryCount = 0) {
        isProcessing = true;
        sendBtn.disabled = true;
        typingIndicator.style.display = 'flex';

        const typingText = qid('agentic-typing-text');
        if (typingText) typingText.textContent = agenticChat.i18n.thinking;

        // After 20s with no response, hint that the model may be warming up.
        const warmupTimer = setTimeout(() => {
            if (typingText) typingText.textContent = agenticChat.i18n.warmingUp;
        }, 20000);

        // Abort the fetch if PHP takes longer than 310s.
        const controller = new AbortController();
        const abortTimer = setTimeout(() => controller.abort(), 310000);

        try {
            const payload = {
                message: message,
                session_id: sessionId,
                agent_id: currentAgentId,
                history: conversationHistory.slice(-20), // Last 20 messages for context
                page_context: pageContext,
                deployment_context: 'admin_chat'
            };

            // P0 Basic Multi-Agent Orchestration: pass handoff context on first message only —
            // agenticChat.handoffFrom/handoffContext are static for the page's lifetime, so
            // without this guard every subsequent message would resend the same handoff blob.
            if (!handoffContextSent && typeof agenticChat !== 'undefined') {
                if (agenticChat.handoffFrom) {
                    payload.handoff_from = agenticChat.handoffFrom;
                }
                if (agenticChat.handoffContext) {
                    payload.handoff_context = agenticChat.handoffContext;
                }
            }
            handoffContextSent = true;

            // Attach image as base64
            if (imageData && imageData.dataUrl) {
                payload.image = imageData.dataUrl;
            }

            // Attach Turnstile token if required (stored by invisible widget callback).
            // If the token hasn't arrived yet (widget still resolving after a reset),
            // wait up to 3s for it before proceeding.
            if (typeof agenticChat !== 'undefined' && agenticChat.turnstileSiteKey) {
                if (!turnstileToken) {
                    await new Promise((resolve) => {
                        const deadline = Date.now() + 3000;
                        const poll = setInterval(() => {
                            if (turnstileToken || Date.now() >= deadline) {
                                clearInterval(poll);
                                resolve();
                            }
                        }, 100);
                    });
                }
                if (!turnstileToken) {
                    addMessage(agenticChat.i18n.errorTurnstile, 'agent');
                    return;
                }
                payload.turnstile_token = turnstileToken;
            }

            payload.stream = true;

            const response = await fetch(agenticChat.restUrl + 'chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': agenticChat.nonce
                },
                body: JSON.stringify(payload),
                signal: controller.signal
            });

            const contentType = response.headers.get('Content-Type') || '';

            if (contentType.includes('text/event-stream') && response.body) {
                // --- SSE streaming path ---
                const streamBubble = addMessage('', 'agent');
                const contentDiv = streamBubble ? streamBubble.querySelector('.agentic-message-content') : null;
                let accText = '';
                let sseBuf = '';
                const dec = new TextDecoder();
                const reader = response.body.getReader();

                try {
                    while (true) {
                        const { done, value } = await reader.read();
                        if (done) break;
                        sseBuf += dec.decode(value, { stream: true });

                        let sep;
                        while ((sep = sseBuf.indexOf('\n\n')) !== -1) {
                            const frame = sseBuf.slice(0, sep);
                            sseBuf = sseBuf.slice(sep + 2);
                            for (const line of frame.split('\n')) {
                                if (!line.startsWith('data:')) continue;
                                const json = line.slice(5).trim();
                                let evt;
                                try { evt = JSON.parse(json); } catch (e) { continue; }

                                if (evt.type === 'live') {
                                    accText += evt.token || '';
                                    if (contentDiv) {
                                        contentDiv.innerHTML = renderMarkdown(accText);
                                        if (streamBubble) {
                                            messages.scrollTop = streamBubble.offsetTop - messages.offsetTop;
                                        }
                                    }
                                } else if (evt.type === 'tool_start') {
                                    if (typingText) typingText.textContent = '⚙️ ' + (evt.name || '');
                                } else if (evt.type === 'tool_end') {
                                    if (typingText) typingText.textContent = agenticChat.i18n.thinking;
                                } else if (evt.type === 'end') {
                                    const finalText = evt.response || accText;
                                    if (contentDiv && evt.response) {
                                        contentDiv.innerHTML = renderMarkdown(finalText);
                                    }
                                    conversationHistory.push({ role: 'assistant', content: finalText });
                                    saveConversation();
                                    totalTokens += evt.tokens_used || 0;
                                    totalCost += evt.cost || 0;
                                    updateStats();
                                    if (evt.error && streamBubble) {
                                        streamBubble.classList.add('agentic-message-error');
                                    }

                                    // P0 Observability: show collapsible reasoning + steps card (progressive disclosure)
                                    if (streamBubble && (evt.reasoning || (evt.tools_used && evt.tools_used.length) || (evt.iterations && evt.iterations > 1))) {
                                        const card = document.createElement('details');
                                        card.className = 'agentic-reasoning-card';
                                        card.style.marginTop = '8px';
                                        card.style.fontSize = '13px';

                                        const summary = document.createElement('summary');
                                        summary.textContent = (agenticChat.i18n && agenticChat.i18n.reasoningSummary) || 'Agent reasoning & steps';
                                        summary.style.cursor = 'pointer';
                                        summary.style.color = '#2271b1';
                                        card.appendChild(summary);

                                        const body = document.createElement('div');
                                        body.style.marginTop = '6px';
                                        body.style.padding = '8px';
                                        body.style.background = 'rgba(0,0,0,0.03)';
                                        body.style.borderRadius = '6px';

                                        if (evt.reasoning) {
                                            const r = document.createElement('div');
                                            r.style.marginBottom = '6px';
                                            r.innerHTML = '<strong>' + escapeHtml((agenticChat.i18n && agenticChat.i18n.reasoningThinking) || 'Thinking:') + '</strong> ' + escapeHtml(evt.reasoning);
                                            body.appendChild(r);
                                        }
                                        if (evt.tools_used && evt.tools_used.length) {
                                            const t = document.createElement('div');
                                            t.innerHTML = '<strong>' + escapeHtml((agenticChat.i18n && agenticChat.i18n.reasoningTools) || 'Tools used:') + '</strong> ' + evt.tools_used.map(escapeHtml).join(', ');
                                            body.appendChild(t);
                                        }
                                        if (evt.iterations && evt.iterations > 1) {
                                            const i = document.createElement('div');
                                            i.textContent = ((agenticChat.i18n && agenticChat.i18n.reasoningIterations) || 'Iterations: %s').replace('%s', evt.iterations);
                                            body.appendChild(i);
                                        }
                                        card.appendChild(body);
                                        streamBubble.appendChild(card);
                                    }
                                // Pending confirmation -> render approve/reject buttons (streaming parity).
                                if (streamBubble && evt.pending_proposal && evt.proposal) {
                                    streamBubble.appendChild(renderProposalCard(evt.proposal));
                                }
                                } else if (evt.type === 'error') {
                                    const errText = evt.message || agenticChat.i18n.errorGeneric;
                                    if (contentDiv) contentDiv.innerHTML = renderMarkdown(errText);
                                }
                            }
                        }
                    }
                } finally {
                    reader.releaseLock();
                }
            } else {
                // --- Non-streaming fallback (security errors, pre-flight failures) ---
                const data = await response.json();

                // Retry on 503 / model-busy (up to 3 times with countdown).
                if (data.error && data.retriable && retryCount < 3) {
                    const wait = data.retry_after || 10;
                    clearTimeout(warmupTimer);
                    clearTimeout(abortTimer);
                    isProcessing = false;
                    sendBtn.disabled = false;
                    typingIndicator.style.display = 'none';
                    let remaining = wait;
                    const retryMsg = addMessage(agenticChat.i18n.retryBusy.replace('%s', remaining), 'agent');
                    const countEl = retryMsg ? retryMsg.querySelector('.agentic-message-content') : null;
                    const tick = setInterval(() => {
                        remaining--;
                        if (countEl) countEl.textContent = agenticChat.i18n.retryBusy.replace('%s', remaining);
                        if (remaining <= 0) {
                            clearInterval(tick);
                            if (retryMsg) retryMsg.remove();
                            sendMessage(message, imageData, retryCount + 1);
                        }
                    }, 1000);
                    return;
                }

                if (data.error) {
                    const isQuota = response.status === 429 || response.status === 402;
                    const msg = addMessage(data.response || agenticChat.i18n.errorGeneric, 'agent');
                    if (isQuota && msg) msg.classList.add('agentic-quota-error');
                    if (isQuota) setLed('credits', 'error', 'Credits: exhausted \u2014 add credits or use your own API key');
                } else {
                    conversationHistory.push({ role: 'assistant', content: data.response });
                    saveConversation();
                    totalTokens += data.tokens_used || 0;
                    totalCost += data.cost || 0;
                    updateStats();
                    const meta = {
                        tokens: data.tokens_used,
                        cost: data.cost,
                        tools: data.tools_used,
                        cached: data.cached || false,
                        proposal: data.pending_proposal ? data.proposal : null
                    };
                    const ttsActive = typeof agenticChat !== 'undefined' && agenticChat.tts === '1' &&
                        localStorage.getItem('agentic_tts_enabled') === '1';
                    if (ttsActive) {
                        ttsPending = true;
                        if (typingText) typingText.textContent = agenticChat.i18n.preparingAudio;
                        playTTSResponse(data.response, meta);
                    } else {
                        addMessage(data.response, 'agent', meta);
                    }
                }
            }
        } catch (error) {
            console.error('Chat error:', error);
            setLed('ai', 'error', 'AI service: connection failed');
            const msg = error.name === 'AbortError'
                ? agenticChat.i18n.errorTimeout
                : agenticChat.i18n.errorConnection;
            addMessage(msg, 'agent');
        } finally {
            clearTimeout(warmupTimer);
            clearTimeout(abortTimer);
            isProcessing = false;
            sendBtn.disabled = false;
            fetchStatus();
            if (typingText) typingText.textContent = agenticChat.i18n.thinking;
            // Keep typing indicator visible while TTS audio is loading; playTTSResponse hides it.
            if (!ttsPending) {
                typingIndicator.style.display = 'none';
            }
            // Reset Turnstile so a fresh token is issued for the next message.
            if (turnstileWidgetId !== null && typeof turnstile !== 'undefined') {
                turnstileToken = '';
                turnstile.reset(turnstileWidgetId);
            }
        }
    }

    // Update stats display
    function updateStats() {
        if (typeof agenticChat !== 'undefined' && agenticChat.costs !== '1') {
            stats.style.display = 'none';
            return;
        }
        stats.innerHTML = `Tokens: ${totalTokens.toLocaleString()} | Cost: $${totalCost.toFixed(4)}`;
        
        // Save stats to localStorage
        localStorage.setItem(`agentic_stats_${currentAgentId}`, JSON.stringify({
            tokens: totalTokens,
            cost: totalCost
        }));
    }

    // Save conversation to localStorage (per-agent)
    function saveConversation() {
        localStorage.setItem(`agentic_history_${currentAgentId}`, JSON.stringify(conversationHistory));
    }

    // Drop a restored conversation only when the server DEFINITIVELY has no
    // matching session (e.g. history pruned, different device/DB). Prevents a
    // local 'ghost' chat that never appears in the history panel. Never clears
    // on a network/parse error, so a real conversation is never lost.
    async function reconcileRestoredConversation() {
        if (!conversationHistory.length || !sessionId) return;
        if (typeof agenticChat === 'undefined' || !agenticChat.restUrl) return;
        try {
            const url = agenticChat.restUrl + 'sessions?agent_id=' + encodeURIComponent(currentAgentId) + '&limit=100';
            const resp = await fetch(url, { headers: { 'X-WP-Nonce': agenticChat.nonce } });
            if (!resp.ok) return;
            const data = await resp.json();
            if (!data || !Array.isArray(data.sessions)) return;
            const known = data.sessions.some(sess => sess && sess.session_id === sessionId);
            if (known) return;
            // Ghost conversation: reset to a clean slate (keep the welcome bubble).
            conversationHistory = [];
            localStorage.removeItem(`agentic_history_${currentAgentId}`);
            localStorage.removeItem(`agentic_stats_${currentAgentId}`);
            const messagesEl = qid('agentic-messages');
            if (messagesEl) {
                const bubbles = messagesEl.querySelectorAll('.agentic-message');
                for (let i = 1; i < bubbles.length; i++) { bubbles[i].remove(); }
            }
            const statsEl = qid('agentic-stats');
            if (statsEl) statsEl.textContent = '';
        } catch (e) {
            // Network/parse error - keep the conversation untouched.
        }
    }

    // Load conversation from localStorage (per-agent)
    function loadConversation() {
        const saved = localStorage.getItem(`agentic_history_${currentAgentId}`);
        if (saved) {
            try {
                conversationHistory = JSON.parse(saved);
                // Replay messages to UI (skip initial greeting)
                conversationHistory.forEach(msg => {
                    addMessage(msg.content, msg.role === 'user' ? 'user' : 'agent');
                });
                reconcileRestoredConversation();
            } catch (e) {
                conversationHistory = [];
            }
        }
        
        // Load saved stats
        const savedStats = localStorage.getItem(`agentic_stats_${currentAgentId}`);
        if (savedStats) {
            try {
                const stats = JSON.parse(savedStats);
                totalTokens = stats.tokens || 0;
                totalCost = stats.cost || 0;
                updateStats();
            } catch (e) {
                totalTokens = 0;
                totalCost = 0;
            }
        }
    }

    // Clear conversation (for current agent only)
    function clearConversation() {
        conversationHistory = [];
        localStorage.removeItem(`agentic_history_${currentAgentId}`);
        localStorage.removeItem(`agentic_stats_${currentAgentId}`);
        sessionId = generateUUID();
        localStorage.setItem(`agentic_session_${currentAgentId}`, sessionId);
        totalTokens = 0;
        totalCost = 0;
        updateStats();

        // Clear messages except the first greeting
        while (messages.children.length > 1) {
            messages.removeChild(messages.lastChild);
        }

        // Close history panel if open
        const panel = qid('agentic-history-panel');
        if (panel) panel.style.display = 'none';
    }

    // ── Chat History Panel ──

    // Status LEDs (AI connectivity + credits).
    function setLed(which, level, title) {
        var el = document.querySelector('.agentic-led[data-led="' + which + '"]');
        if (!el) return;
        el.classList.remove('agentic-led-ok', 'agentic-led-warn', 'agentic-led-error', 'agentic-led-unknown');
        el.classList.add('agentic-led-' + level);
        if (title) el.title = title;
    }
    function applyStatusLeds(d) {
        if (!d) return;
        if (d.configured === false) { setLed('ai', 'error', 'No AI provider configured'); }
        else if (d.ai === 'ok') { setLed('ai', 'ok', 'AI service: connected'); }
        else if (d.ai === 'unreachable') { setLed('ai', 'error', 'AI service: unreachable'); }
        else { setLed('ai', 'unknown', 'AI service: unknown'); }
        if (d.credits === 'ok') { setLed('credits', 'ok', 'Credits: OK'); }
        else if (d.credits === 'exhausted') { setLed('credits', 'error', 'Credits: exhausted \u2014 add credits or use your own API key'); }
        else if (d.credits === 'low') { setLed('credits', 'warn', 'Credits: running low'); }
        else { setLed('credits', 'unknown', 'Credits: unknown'); }
    }
    function fetchStatus() {
        if (typeof agenticChat === 'undefined' || !agenticChat.restUrl) return;
        fetch(agenticChat.restUrl + 'status', { headers: { 'X-WP-Nonce': agenticChat.nonce } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { applyStatusLeds(d); })
            .catch(function () {});
    }

    function initHistory() {
        const historyBtn = qid('agentic-history-btn');
        const closeBtn = qid('agentic-history-close');
        const panel = qid('agentic-history-panel');
        if (!historyBtn || !panel) return;

        historyBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            const visible = panel.style.display !== 'none';
            if (visible) {
                panel.style.display = 'none';
            } else {
                panel.style.display = 'flex';
                fetchSessions();
            }
        });

        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                panel.style.display = 'none';
            });
        }
    }

    async function fetchSessions() {
        const listEl = qid('agentic-history-list');
        if (!listEl) return;

        listEl.innerHTML = '<div class="agentic-history-loading">Loading sessions…</div>';

        try {
            const url = agenticChat.restUrl + 'sessions?agent_id=' + encodeURIComponent(currentAgentId) + '&limit=30';
            const response = await fetch(url, {
                headers: { 'X-WP-Nonce': agenticChat.nonce }
            });
            const data = await response.json();
            const sessions = data.sessions || [];

            if (sessions.length === 0) {
                listEl.innerHTML = '<div class="agentic-history-empty">No previous conversations found.</div>';
                return;
            }

            listEl.innerHTML = '';
            sessions.forEach(function(session) {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'agentic-history-item';
                if (session.session_id === sessionId) {
                    item.classList.add('agentic-history-item-active');
                }

                const date = new Date(session.created_at + 'Z');
                const dateStr = date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
                const timeStr = date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });

                const preview = session.preview || 'Empty conversation';

                item.innerHTML =
                    '<div class="agentic-history-preview">' + escapeHtml(preview) + '</div>' +
                    '<div class="agentic-history-date">' + escapeHtml(dateStr + ' ' + timeStr) + '</div>';

                item.addEventListener('click', function() {
                    loadSession(session.session_id);
                });

                listEl.appendChild(item);
            });

            if (data.history_days_limit) {
                const limitNote = document.createElement('div');
                limitNote.className = 'agentic-history-limit-note';
                limitNote.textContent = 'Showing last ' + data.history_days_limit + ' days.';
                listEl.appendChild(limitNote);
            }
        } catch (err) {
            console.error('Failed to fetch sessions:', err);
            listEl.innerHTML = '<div class="agentic-history-empty">Failed to load history.</div>';
        }
    }

    async function loadSession(targetSessionId) {
        const panel = qid('agentic-history-panel');

        try {
            const url = agenticChat.restUrl + 'history/' + encodeURIComponent(targetSessionId);
            const response = await fetch(url, {
                headers: { 'X-WP-Nonce': agenticChat.nonce }
            });
            const data = await response.json();
            const history = data.history || [];

            if (history.length === 0) return;

            // Clear current conversation
            conversationHistory = [];
            while (messages.children.length > 1) {
                messages.removeChild(messages.lastChild);
            }

            // Set session
            sessionId = targetSessionId;
            localStorage.setItem(`agentic_session_${currentAgentId}`, sessionId);

            // Replay messages
            history.forEach(function(msg) {
                const role = msg.role === 'user' ? 'user' : 'agent';
                const meta = {};
                if (msg.tools_used && msg.tools_used.length) {
                    meta.tools = msg.tools_used;
                }
                addMessage(msg.content, role, meta);
                conversationHistory.push({ role: msg.role, content: msg.content });
            });

            saveConversation();

            // Close panel
            if (panel) panel.style.display = 'none';
        } catch (err) {
            console.error('Failed to load session:', err);
        }
    }

    // Dispatch to the right card renderer: a user-space proposal (medium risk,
    // once/session/always/deny) or a high-risk admin approval-queue item
    // (approve/reject only, backed by the same endpoint the Approvals page uses).
    function renderProposalCard(proposal) {
        return proposal.kind === 'approval' ? renderApprovalCard(proposal) : renderProposal(proposal);
    }

    // Render a proposal card with diff and approve/reject buttons
    function renderProposal(proposal) {
        const card = document.createElement('div');
        card.className = 'agentic-proposal-card';
        card.dataset.proposalId = proposal.id;

        // Header
        const header = document.createElement('div');
        header.className = 'agentic-proposal-header';
        header.innerHTML = '<span class="dashicons dashicons-editor-code"></span> <strong>Proposed Change</strong>';
        card.appendChild(header);

        // Description
        const desc = document.createElement('div');
        desc.className = 'agentic-proposal-desc';
        desc.textContent = proposal.description || agenticChat.i18n.proposalDefault;
        card.appendChild(desc);

        // Diff view
        if (proposal.diff) {
            const diffToggle = document.createElement('button');
            diffToggle.type = 'button';
            diffToggle.className = 'agentic-proposal-toggle';
            diffToggle.textContent = agenticChat.i18n.showDiff;
            card.appendChild(diffToggle);

            const diffPre = document.createElement('pre');
            diffPre.className = 'agentic-proposal-diff';
            diffPre.style.display = 'none';
            diffPre.innerHTML = formatDiff(proposal.diff);
            card.appendChild(diffPre);

            diffToggle.addEventListener('click', function() {
                const visible = diffPre.style.display !== 'none';
                diffPre.style.display = visible ? 'none' : 'block';
                diffToggle.textContent = visible ? agenticChat.i18n.showDiff : agenticChat.i18n.hideDiff;
            });
        }

        // Action buttons (only shown to admins; non-admin frontend visitors are always blocked)
        const actions = document.createElement('div');
        actions.className = 'agentic-proposal-actions';

        if (agenticChat.isAdmin === '1') {
            const onceBtn = document.createElement('button');
            onceBtn.type = 'button';
            onceBtn.className = 'agentic-proposal-btn agentic-proposal-approve';
            onceBtn.innerHTML = '<span class="dashicons dashicons-yes"></span> Allow Once';
            onceBtn.addEventListener('click', () => handleProposalAction(proposal.id, 'once', card));

            const sessionBtn = document.createElement('button');
            sessionBtn.type = 'button';
            sessionBtn.className = 'agentic-proposal-btn agentic-proposal-session';
            sessionBtn.innerHTML = '<span class="dashicons dashicons-clock"></span> Allow this Session';
            sessionBtn.addEventListener('click', () => handleProposalAction(proposal.id, 'session', card));

            const alwaysBtn = document.createElement('button');
            alwaysBtn.type = 'button';
            alwaysBtn.className = 'agentic-proposal-btn agentic-proposal-always';
            alwaysBtn.innerHTML = '<span class="dashicons dashicons-star-filled"></span> Always Allow';
            alwaysBtn.addEventListener('click', () => handleProposalAction(proposal.id, 'always', card));

            const rejectBtn = document.createElement('button');
            rejectBtn.type = 'button';
            rejectBtn.className = 'agentic-proposal-btn agentic-proposal-reject';
            rejectBtn.innerHTML = '<span class="dashicons dashicons-no"></span> Deny';
            rejectBtn.addEventListener('click', () => handleProposalAction(proposal.id, 'reject', card));

            actions.appendChild(onceBtn);
            actions.appendChild(sessionBtn);
            actions.appendChild(alwaysBtn);
            actions.appendChild(rejectBtn);
        } else {
            // Non-admin frontend users cannot grant tool permissions.
            const blockedNote = document.createElement('div');
            blockedNote.className = 'agentic-proposal-status';
            blockedNote.textContent = 'This action requires admin approval.';
            actions.appendChild(blockedNote);
        }
        card.appendChild(actions);

        return card;
    }

    // Render a high-risk admin-approval card (Approval Queue item), approve/reject
    // only — no once/session/always distinction, since that grant model doesn't
    // apply to admin-queued actions.
    function renderApprovalCard(proposal) {
        const card = document.createElement('div');
        card.className = 'agentic-proposal-card';
        card.dataset.proposalId = proposal.id;

        const header = document.createElement('div');
        header.className = 'agentic-proposal-header';
        header.innerHTML = '<span class="dashicons dashicons-lock"></span> <strong>Needs Your Approval</strong>';
        card.appendChild(header);

        const desc = document.createElement('div');
        desc.className = 'agentic-proposal-desc';
        desc.textContent = proposal.description || agenticChat.i18n.approvalDefault;
        card.appendChild(desc);

        const actions = document.createElement('div');
        actions.className = 'agentic-proposal-actions';

        if (agenticChat.isAdmin === '1') {
            const approveBtn = document.createElement('button');
            approveBtn.type = 'button';
            approveBtn.className = 'agentic-proposal-btn agentic-proposal-approve';
            approveBtn.innerHTML = '<span class="dashicons dashicons-yes"></span> Approve';
            approveBtn.addEventListener('click', () => handleApprovalAction(proposal.id, 'approve', card));

            const rejectBtn = document.createElement('button');
            rejectBtn.type = 'button';
            rejectBtn.className = 'agentic-proposal-btn agentic-proposal-reject';
            rejectBtn.innerHTML = '<span class="dashicons dashicons-no"></span> Reject';
            rejectBtn.addEventListener('click', () => handleApprovalAction(proposal.id, 'reject', card));

            actions.appendChild(approveBtn);
            actions.appendChild(rejectBtn);
        } else {
            // Non-admins cannot resolve admin approval-queue items.
            const blockedNote = document.createElement('div');
            blockedNote.className = 'agentic-proposal-status';
            blockedNote.textContent = 'This action requires admin approval.';
            actions.appendChild(blockedNote);
        }
        card.appendChild(actions);

        return card;
    }

    // Format diff with color highlighting
    function formatDiff(diff) {
        return diff.split('\n').map(line => {
            const escaped = escapeHtml(line);
            if (line.startsWith('+')) {
                return '<span class="diff-add">' + escaped + '</span>';
            } else if (line.startsWith('-')) {
                return '<span class="diff-del">' + escaped + '</span>';
            } else if (line.startsWith('@@')) {
                return '<span class="diff-hunk">' + escaped + '</span>';
            }
            return escaped;
        }).join('\n');
    }

    // Send grant action to REST API
    async function handleProposalAction(proposalId, action, cardElement) {
        const buttons = cardElement.querySelectorAll('.agentic-proposal-btn');
        buttons.forEach(btn => btn.disabled = true);

        try {
            const response = await fetch(agenticChat.restUrl + 'proposals/' + proposalId, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': agenticChat.nonce
                },
                body: JSON.stringify({ action: action, session_id: sessionId })
            });

            const data = await response.json();

            // Update card styling
            const isApproveVariant = action === 'once' || action === 'session' || action === 'always';
            cardElement.classList.add('agentic-proposal-' + (isApproveVariant ? 'approved' : 'rejected'));

            // Replace buttons with status
            const actionsDiv = cardElement.querySelector('.agentic-proposal-actions');
            let statusText;
            if (action === 'once') statusText = '✅ Allowed once — change applied.';
            else if (action === 'session') statusText = '✅ Allowed for this session — change applied.';
            else if (action === 'always') statusText = '✅ Always allowed — change applied.';
            else statusText = '❌ Denied — no change made.';
            actionsDiv.innerHTML = '<div class="agentic-proposal-status">' + statusText + '</div>';

            if (data.error) {
                actionsDiv.innerHTML = '<div class="agentic-proposal-status agentic-proposal-error">⚠️ ' + escapeHtml(data.error) + '</div>';
            }

            // If the approved tool returned a shortcode, show implementation instructions.
            if (isApproveVariant && data.shortcode) {
                let msg = '';
                if (data.title) msg += '**' + data.title + '** has been created.\n\n';
                msg += 'Paste this shortcode into any post or page to display the form:\n\n';
                msg += '`' + data.shortcode + '`\n\n';
                if (data.entries_shortcode) {
                    msg += 'To view submitted entries, paste this shortcode on any private or admin page:\n\n';
                    msg += '`' + data.entries_shortcode + '`\n\n';
                }
                if (data.note) msg += data.note;
                addMessage(msg, 'agent');

                // Inject the result into conversation history so the LLM knows the form_id on the next turn.
                conversationHistory.push({ role: 'assistant', content: msg });
            }

            // If the approved tool created/published content, show a friendly confirmation with links.
            if (isApproveVariant && !data.error && data.url) {
                var title = data.title ? data.title : 'Your content';
                var verb = data.status === 'publish' ? 'is now live'
                    : (data.status === 'future' ? 'is scheduled to publish' : 'has been saved as a draft');
                var confirmMsg = '✅ **' + title + '** ' + verb + '.\n\n[View →](' + data.url + ')';
                if (data.edit_url) { confirmMsg += '  ·  [Edit](' + data.edit_url + ')'; }
                addMessage(confirmMsg, 'agent');
                conversationHistory.push({ role: 'assistant', content: confirmMsg });
                saveConversation();
            }
        } catch (error) {
            console.error('Proposal action error:', error);
            buttons.forEach(btn => btn.disabled = false);
            addMessage(agenticChat.i18n.errorProposal.replace('%s', error.message), 'agent');
        }
    }

    // Send an approve/reject decision to the real admin approval-queue REST
    // endpoint (the same one the Approvals page uses) — never a call the LLM
    // can trigger itself; only a genuine click from a signed-in admin browser
    // session reaches this function.
    async function handleApprovalAction(approvalId, action, cardElement) {
        const buttons = cardElement.querySelectorAll('.agentic-proposal-btn');
        buttons.forEach(btn => btn.disabled = true);

        try {
            const response = await fetch(agenticChat.restUrl + 'approvals/' + approvalId, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': agenticChat.nonce
                },
                body: JSON.stringify({ action: action })
            });

            const data = await response.json();
            const actionsDiv = cardElement.querySelector('.agentic-proposal-actions');

            if (!response.ok || data.success === false) {
                const errMsg = (data.error && data.error.message) || data.message || 'Something went wrong.';
                actionsDiv.innerHTML = '<div class="agentic-proposal-status agentic-proposal-error">⚠️ ' + escapeHtml(errMsg) + '</div>';
                buttons.forEach(btn => btn.disabled = false);
                return;
            }

            cardElement.classList.add('agentic-proposal-' + (action === 'approve' ? 'approved' : 'rejected'));
            const okMsg = (data.data && data.data.message) || (action === 'approve' ? 'Approved.' : 'Rejected.');
            actionsDiv.innerHTML = '<div class="agentic-proposal-status">' + (action === 'approve' ? '✅ ' : '❌ ') + escapeHtml(okMsg) + '</div>';
        } catch (error) {
            console.error('Approval action error:', error);
            buttons.forEach(btn => btn.disabled = false);
            addMessage(agenticChat.i18n.errorApproval.replace('%s', error.message), 'agent');
        }
    }

    // Simple markdown renderer
    function renderMarkdown(text) {
        if (!text) return '';
        
        // Escape HTML first
        let html = escapeHtml(text);

        // Code blocks
        html = html.replace(/```(\w*)\n([\s\S]*?)```/g, '<div class="agentic-code-wrap"><button class="agentic-copy-btn" title="Copy">Copy</button><pre><code class="language-$1">$2</code></pre></div>');
        
        // Inline code
        html = html.replace(/`([^`]+)`/g, '<code>$1</code>');

        // Tables — process before headers/lists to avoid conflicts
        html = html.replace(/(^\|.+\|$\n?)+/gm, function(tableBlock) {
            const rows = tableBlock.trim().split('\n');
            if (rows.length < 2) return tableBlock;
            
            // Check for separator row (|---|---|)
            const sepIndex = rows.findIndex(r => /^\|[\s:]*-{2,}[\s:]*\|/.test(r));
            if (sepIndex === -1) return tableBlock;
            
            let tableHtml = '<table>';
            
            // Header rows (everything before separator)
            tableHtml += '<thead>';
            for (let i = 0; i < sepIndex; i++) {
                const cells = rows[i].split('|').slice(1, -1);
                tableHtml += '<tr>' + cells.map(c => '<th>' + c.trim() + '</th>').join('') + '</tr>';
            }
            tableHtml += '</thead>';
            
            // Body rows (everything after separator)
            if (sepIndex + 1 < rows.length) {
                tableHtml += '<tbody>';
                for (let i = sepIndex + 1; i < rows.length; i++) {
                    if (!rows[i].trim()) continue;
                    const cells = rows[i].split('|').slice(1, -1);
                    tableHtml += '<tr>' + cells.map(c => '<td>' + c.trim() + '</td>').join('') + '</tr>';
                }
                tableHtml += '</tbody>';
            }
            
            tableHtml += '</table>';
            return tableHtml;
        });

        // Horizontal rules
        html = html.replace(/^-{3,}$/gm, '<hr>');
        
        // Headers (most specific first)
        html = html.replace(/^#### (.*$)/gm, '<h4>$1</h4>');
        html = html.replace(/^### (.*$)/gm, '<h3>$1</h3>');
        html = html.replace(/^## (.*$)/gm, '<h2>$1</h2>');
        html = html.replace(/^# (.*$)/gm, '<h1>$1</h1>');
        
        // Bold and italic
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');
        
        // Agent delegation links — rendered as buttons, not anchors.
        // Convention: [→ Agent Name](agentic-delegate:agent-id)
        html = html.replace(/\[([^\]]+)\]\(agentic-delegate:([a-z0-9-]+)\)/g,
            '<button class="agentic-delegate-btn" data-agent="$2">$1</button>');

        // Standard links
        html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
        
        // Lists
        html = html.replace(/^\s*[-*]\s+(.*)$/gm, '<li>$1</li>');
        html = html.replace(/(<li>.*<\/li>\n?)+/g, '<ul>$&</ul>');
        
        // Numbered lists
        html = html.replace(/^\s*\d+\.\s+(.*)$/gm, '<li>$1</li>');
        
        // Blockquotes
        html = html.replace(/^>\s+(.*)$/gm, '<blockquote>$1</blockquote>');
        
        // Paragraphs
        html = html.replace(/\n\n/g, '</p><p>');
        html = '<p>' + html + '</p>';
        html = html.replace(/<p><\/p>/g, '');
        html = html.replace(/<p>(<h[1-6]>)/g, '$1');
        html = html.replace(/(<\/h[1-6]>)<\/p>/g, '$1');
        html = html.replace(/<p>(<ul>)/g, '$1');
        html = html.replace(/(<\/ul>)<\/p>/g, '$1');
        html = html.replace(/<p>(<pre>)/g, '$1');
        html = html.replace(/(<\/pre>)<\/p>/g, '$1');
        html = html.replace(/<p>(<div class="agentic-code-wrap">)/g, '$1');
        html = html.replace(/(<\/div>)<\/p>/g, '$1');
        html = html.replace(/<p>(<blockquote>)/g, '$1');
        html = html.replace(/(<\/blockquote>)<\/p>/g, '$1');
        html = html.replace(/<p>(<table>)/g, '$1');
        html = html.replace(/(<\/table>)<\/p>/g, '$1');
        html = html.replace(/<p>(<hr>)/g, '$1');
        html = html.replace(/(<hr>)<\/p>/g, '$1');

        return html;
    }

    // Escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Generate UUID
    function generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // ── Slash Commands ────────────────────────────────────────────────────────

    var slashPalette = null;

    function getAvailableCommands() {
        var cmds = (typeof agenticChat !== 'undefined' && agenticChat.slashCommands) ? agenticChat.slashCommands : [];
        var isAdmin = typeof agenticChat !== 'undefined' && agenticChat.isAdmin === '1';
        var ctx = isAdmin ? 'backend' : 'frontend';
        return cmds.filter(function(c) { return c.contexts && c.contexts.indexOf(ctx) !== -1; });
    }

    function getSlashCommand(name) {
        return getAvailableCommands().find(function(c) { return c.name === name; }) || null;
    }

    function initSlashPalette() {
        if (!form) return;
        slashPalette = document.createElement('div');
        slashPalette.id = 'agentic-slash-palette';
        slashPalette.className = 'agentic-slash-palette';
        slashPalette.style.display = 'none';
        // Insert before the form (inside the same parent so it floats above).
        var inputArea = input ? input.closest('.agentic-chat-input-area') : null;
        var parent = inputArea || (form ? form.parentNode : null);
        if (parent) {
            parent.style.position = 'relative';
            parent.insertBefore(slashPalette, inputArea || form);
        }
    }

    function openSlashPalette(query) {
        if (!slashPalette) return;
        var cmds = getAvailableCommands().filter(function(c) {
            return !query || c.name.indexOf(query) === 0;
        });
        if (!cmds.length) { closeSlashPalette(); return; }

        var html = cmds.map(function(c, i) {
            var label = '/' + c.name;
            if (c.has_args) label += ' <span class="agentic-slash-arg">&lt;' + escapeHtml(c.arg_hint) + '&gt;</span>';
            return '<div class="agentic-slash-item' + (i === 0 ? ' agentic-slash-item--active' : '') + '" data-cmd="' + escapeHtml(c.name) + '" data-client="' + (c.client_side ? '1' : '0') + '">' +
                '<span class="agentic-slash-name">' + label + '</span>' +
                '<span class="agentic-slash-desc">' + escapeHtml(c.description) + '</span>' +
                '</div>';
        }).join('');

        slashPalette.innerHTML = html;
        slashPalette.style.display = 'block';
        slashPaletteActive = true;
        slashPaletteIndex = 0;

        slashPalette.querySelectorAll('.agentic-slash-item').forEach(function(el) {
            el.addEventListener('mousedown', function(e) {
                e.preventDefault();
                var cmd = getSlashCommand(el.dataset.cmd);
                if (cmd) {
                    input.value = '';
                    closeSlashPalette();
                    executeSlashCommand(cmd, []);
                }
            });
        });
    }

    function closeSlashPalette() {
        if (slashPalette) slashPalette.style.display = 'none';
        slashPaletteActive = false;
        slashPaletteIndex = 0;
    }

    function movePaletteSelection(delta) {
        if (!slashPalette) return;
        var items = slashPalette.querySelectorAll('.agentic-slash-item');
        if (!items.length) return;
        items[slashPaletteIndex].classList.remove('agentic-slash-item--active');
        slashPaletteIndex = (slashPaletteIndex + delta + items.length) % items.length;
        items[slashPaletteIndex].classList.add('agentic-slash-item--active');
        items[slashPaletteIndex].scrollIntoView({ block: 'nearest' });
    }

    function selectPaletteItem() {
        if (!slashPalette) return;
        var items = slashPalette.querySelectorAll('.agentic-slash-item');
        if (!items.length) return;
        var el = items[slashPaletteIndex];
        var cmd = getSlashCommand(el.dataset.cmd);
        if (cmd) {
            if (cmd.has_args) {
                input.value = '/' + cmd.name + ' ';
                closeSlashPalette();
                input.focus();
            } else {
                input.value = '';
                closeSlashPalette();
                executeSlashCommand(cmd, []);
            }
        }
    }

    async function executeSlashCommand(cmd, args) {
        // Show as user message.
        var display = '/' + cmd.name + (args.length ? ' ' + args.join(' ') : '');
        addMessage(display, 'user');

        if (cmd.client_side) {
            await executeClientCommand(cmd.name, args);
        } else {
            await executeServerCommand(cmd.name, args);
        }
    }

    async function executeClientCommand(name, args) {
        switch (name) {
            case 'clear':
                clearConversation();
                addMessage('_Conversation cleared._', 'agent');
                break;
            case 'help': {
                var cmds = getAvailableCommands();
                var lines = ['## Available Slash Commands\n', '| Command | Description |', '|---|---|'];
                cmds.forEach(function(c) {
                    var label = '`/' + c.name + (c.has_args ? ' <' + c.arg_hint + '>' : '') + '`';
                    lines.push('| ' + label + ' | ' + c.description + ' |');
                });
                addMessage(lines.join('\n'), 'agent');
                break;
            }
            case 'history': {
                var count = parseInt(args[0]) || 10;
                var turns = conversationHistory.slice(-count);
                if (!turns.length) { addMessage('_No conversation history._', 'agent'); break; }
                var lines2 = turns.map(function(t) {
                    return '**' + (t.role === 'user' ? 'You' : 'Agent') + ':** ' + (t.content || '').slice(0, 200);
                });
                addMessage(lines2.join('\n\n'), 'agent');
                break;
            }
            case 'export': {
                var md = conversationHistory.map(function(t) {
                    return '**' + (t.role === 'user' ? 'You' : 'Agent') + ':**\n\n' + (t.content || '');
                }).join('\n\n---\n\n');
                var blob = new Blob([md], { type: 'text/markdown' });
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'conversation-' + new Date().toISOString().slice(0, 10) + '.md';
                a.click();
                addMessage('_Conversation exported._', 'agent');
                break;
            }
            case 'debug':
                debugMode = !debugMode;
                addMessage('_Debug mode ' + (debugMode ? 'enabled' : 'disabled') + '._', 'agent');
                break;
            case 'prompts': {
                var container = chatContainer || document.querySelector('.agentic-chat-container');
                var btns = container ? container.querySelectorAll('.agentic-prompt-btn') : [];
                if (!btns.length) { addMessage('_No suggested prompts configured for this agent._', 'agent'); break; }
                var list = Array.from(btns).map(function(b) { return '- ' + (b.dataset.prompt || b.textContent); });
                addMessage('## Suggested Prompts\n\n' + list.join('\n'), 'agent');
                break;
            }
            default:
                addMessage('_Client command `/' + name + '` not implemented._', 'agent');
        }
    }

    async function executeServerCommand(name, args) {
        isProcessing = true;
        sendBtn.disabled = true;
        typingIndicator.style.display = 'flex';

        try {
            var payload = {
                command: name,
                args: args,
                agent_id: currentAgentId,
                history: name === 'compact' ? conversationHistory.slice(-30) : []
            };
            var res = await fetch(agenticChat.restUrl + 'slash-command', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': agenticChat.nonce },
                body: JSON.stringify(payload),
                credentials: 'same-origin'
            });
            var data = await res.json();

            if (data.error) {
                addMessage('_Error: ' + escapeHtml(data.error) + '_', 'agent');
            } else {
                addMessage(data.output || '', 'agent');

                // Side effects from server response.
                if (data.switch_agent && agentSelect) {
                    agentSelect.value = data.switch_agent;
                    agentSelect.dispatchEvent(new Event('change'));
                }
                if (data.compact) {
                    var summary = conversationHistory.slice(-1)[0] || {};
                    conversationHistory = [
                        { role: 'assistant', content: data.output || 'Conversation summarised.' }
                    ];
                    saveConversation();
                }
                if (data.run_task) {
                    // Trigger the task as a regular message to the agent.
                    await sendMessage('Run scheduled task: ' + data.run_task.task_id);
                }
            }
        } catch (err) {
            addMessage('_Slash command failed. Please try again._', 'agent');
        } finally {
            isProcessing = false;
            sendBtn.disabled = false;
            typingIndicator.style.display = 'none';
        }
    }

    // ── TTS Output ──

    function initTTS() {
        const ttsBtn = qid('agentic-tts-btn');
        if (!ttsBtn) return;

        ttsBtn.style.display = '';
        localStorage.setItem('agentic_tts_enabled', '0');
        ttsBtn.title = agenticChat.i18n.ttsOff;
        ttsBtn.classList.remove('agentic-tts-active');

        ttsBtn.addEventListener('click', function() {
            const nowEnabled = localStorage.getItem('agentic_tts_enabled') === '1';
            if (nowEnabled) {
                localStorage.setItem('agentic_tts_enabled', '0');
                ttsBtn.title = agenticChat.i18n.ttsOff;
                ttsBtn.classList.remove('agentic-tts-active');
                // Stop current playback.
                if (ttsCurrentSource) {
                    try { ttsCurrentSource.stop(); } catch(e) {}
                    ttsCurrentSource = null;
                }
            } else {
                localStorage.setItem('agentic_tts_enabled', '1');
                ttsBtn.title = agenticChat.i18n.ttsOn;
                ttsBtn.classList.add('agentic-tts-active');
            }
        });
    }

    async function playTTSResponse(text, meta = {}) {
        if (!text) { ttsPending = false; typingIndicator.style.display = 'none'; return; }

        // Strip HTML tags, collapse whitespace for synthesis.
        const plain = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
        if (!plain) { ttsPending = false; typingIndicator.style.display = 'none'; addMessage(text, 'agent', meta); return; }

        try {
            const response = await fetch(agenticChat.restUrl + 'tts', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': agenticChat.nonce,
                },
                body: JSON.stringify({
                    text:  plain.substring(0, 4096),
                    voice: agenticChat.ttsVoice || 'neural2-c',
                }),
            });

            if (!response.ok) {
                const ttsData = await response.json().catch(() => ({}));
                // Show the text response regardless of TTS error.
                addMessage(text, 'agent', meta);
                if (response.status === 429 || response.status === 402) {
                    const ttsMsg = addMessage(ttsData.data?.error || agenticChat.i18n.errorTtsLimit, 'agent');
                    if (ttsMsg) ttsMsg.classList.add('agentic-quota-error');
                } else {
                    console.error('[Agentic TTS]', response.status, ttsData.data?.error || 'service error');
                }
                return;
            }

            // Receive chunked binary MP3.
            const arrayBuffer = await response.arrayBuffer();
            if (!arrayBuffer.byteLength) {
                addMessage(text, 'agent', meta);
                return;
            }

            // Stop any currently playing TTS.
            if (ttsCurrentSource) {
                try { ttsCurrentSource.stop(); } catch(e) {}
                ttsCurrentSource = null;
            }

            // Lazily create AudioContext (requires prior user gesture — sending a
            // message satisfies that requirement in all major browsers).
            if (!ttsAudioCtx || ttsAudioCtx.state === 'closed') {
                ttsAudioCtx = new AudioContext();
            }
            if (ttsAudioCtx.state === 'suspended') {
                await ttsAudioCtx.resume();
            }

            const audioBuffer = await ttsAudioCtx.decodeAudioData(arrayBuffer);

            // Audio is decoded and ready — hide typing indicator, show message bubble.
            ttsPending = false;
            typingIndicator.style.display = 'none';

            // Create message bubble with empty content; typewriter fills it in.
            const msgEl = addMessage('', 'agent', meta);
            const contentDiv = msgEl ? msgEl.querySelector('.agentic-message-content') : null;

            const source = ttsAudioCtx.createBufferSource();
            source.buffer = audioBuffer;
            source.connect(ttsAudioCtx.destination);
            ttsCurrentSource = source;
            source.onended = () => { ttsCurrentSource = null; };
            source.start(0);

            // Reveal words progressively, timed to match audio duration.
            if (contentDiv) {
                const words = plain.split(/\s+/).filter(Boolean);
                const msPerWord = (audioBuffer.duration * 1000) / Math.max(words.length, 1);
                let shown = 0;
                const tick = setInterval(() => {
                    shown++;
                    contentDiv.textContent = words.slice(0, shown).join(' ');
                    messages.scrollTop = messages.scrollHeight;
                    if (shown >= words.length) {
                        clearInterval(tick);
                        // Swap plain text for full markdown rendering once all words are shown.
                        contentDiv.innerHTML = renderMarkdown(text);
                        if (msgEl) messages.scrollTop = msgEl.offsetTop - messages.offsetTop;
                    }
                }, msPerWord);
            }

        } catch(e) {
            console.error('[Agentic TTS] playback error:', e);
            addMessage(text, 'agent', meta);
        } finally {
            ttsPending = false;
            typingIndicator.style.display = 'none';
        }
    }


    }

    function bootAllChats() {
        var roots = document.querySelectorAll(
            '.agentic-chat-container[data-agent-id], #agentic-chat.agentic-chat-container, #agentic-chat'
        );
        // Prefer unique roots (modal nests .agentic-chat-container inside widget).
        var seen = [];
        roots.forEach(function (root) {
            // Skip nested containers if parent already is a chat root
            for (var i = 0; i < seen.length; i++) {
                if (seen[i].contains(root) && seen[i] !== root) {
                    return;
                }
            }
            seen.push(root);
            bootChatRoot(root);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootAllChats);
    } else {
        bootAllChats();
    }
})();
