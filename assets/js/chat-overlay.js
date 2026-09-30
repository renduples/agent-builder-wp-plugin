/**
 * Agentic Admin-Bar Chat Overlay
 *
 * Self-contained chat panel that opens when a user clicks an agent
 * in the "AI Agents" admin-bar menu. Each agent gets its own
 * isolated conversation history and session.
 */
(function () {
    'use strict';

    /* ------------------------------------------------------------------ */
    /*  State                                                              */
    /* ------------------------------------------------------------------ */
    let overlay         = null;   // The overlay wrapper element.
    let activeAgent     = null;   // Current agent slug.
    let sessionId       = null;
    let history         = [];
    let isProcessing    = false;
    let pendingImage    = null;   // { dataUrl, mimeType, name }

    /* ------------------------------------------------------------------ */
    /*  Boot                                                               */
    /* ------------------------------------------------------------------ */
    document.addEventListener('DOMContentLoaded', function () {
        // Intercept clicks on admin-bar agent links.
        document.addEventListener('click', function (e) {
            const link = e.target.closest('#wp-admin-bar-agentic-chat-bar .agentic-chat-trigger-bar a');
            if (!link) return;
            e.preventDefault();

            const href = link.getAttribute('href') || '';
            const match = href.match(/#agentic-chat-(.+)/);
            if (!match) return;

            const slug = match[1];
            openOverlay(slug, link.textContent.trim());
        });

        // Intercept clicks on contextual launchers rendered by Agent Builder on
        // core admin screens. Scoped to our own markup (.agentic-context-launcher)
        // so arbitrary third-party DOM cannot trigger a seeded agent launch.
        document.addEventListener('click', function (e) {
            const trigger = e.target.closest('.agentic-context-launcher[data-agentic-launch]');
            if (!trigger) return;
            e.preventDefault();

            const slug = trigger.getAttribute('data-agentic-launch');
            if (!slug) return;

            // Only small, non-sensitive identifiers are passed via data attributes.
            // The prompt is a human-readable starter the user can edit before sending.
            const prompt  = trigger.getAttribute('data-agentic-prompt') || '';
            const label   = trigger.getAttribute('data-agentic-label') || trigger.textContent.trim();

            openOverlay(slug, label, { prompt: prompt });
        });

        // Close on Escape.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay) closeOverlay();
        });
    });

    /* ------------------------------------------------------------------ */
    /*  Overlay lifecycle                                                   */
    /* ------------------------------------------------------------------ */
    function openOverlay(slug, displayName, opts) {
        opts = opts || {};
        var seedPrompt = (typeof opts.prompt === 'string') ? opts.prompt : '';

        // If same agent already open, just show it (and seed the composer if the
        // user hasn't already typed something we'd clobber).
        if (overlay && activeAgent === slug) {
            overlay.classList.add('agentic-overlay-visible');
            if (seedPrompt) seedComposer(seedPrompt);
            return;
        }

        // Tear down previous overlay.
        if (overlay) overlay.remove();

        activeAgent = slug;
        sessionId   = localStorage.getItem('agentic_overlay_session_' + slug) || uuid();
        localStorage.setItem('agentic_overlay_session_' + slug, sessionId);
        history     = [];
        pendingImage = null;

        // Use the agent's real name for the window title (from welcomeMessages keys or fallback).
        var agentNames = (typeof agenticChat !== 'undefined' && agenticChat.agentNames) || {};
        var title = agentNames[slug] || displayName;
        buildOverlay(title);
        loadHistory();

        // Seed the composer with an editable starter prompt. We never auto-send —
        // the user reviews and presses Send, so no admin-screen context leaves the
        // site without an explicit action.
        if (seedPrompt) seedComposer(seedPrompt);

        // Show with slight delay for CSS transition.
        requestAnimationFrame(function () {
            overlay.classList.add('agentic-overlay-visible');
        });
    }

    /**
     * Place an editable starter prompt into the composer without clobbering text
     * the user has already typed. Focuses and moves the caret to the end.
     *
     * @param {string} text Starter prompt.
     */
    function seedComposer(text) {
        var input = document.getElementById('agentic-overlay-input');
        if (!input) return;
        if (input.value && input.value.trim()) {
            // Don't overwrite the user's in-progress message; just focus.
            input.focus();
            return;
        }
        input.value = text;
        input.focus();
        try {
            input.setSelectionRange(text.length, text.length);
        } catch (err) { /* non-text inputs */ }
        // Trigger autoResize so the textarea grows to fit the seeded text.
        input.dispatchEvent(new Event('input'));
    }

    function closeOverlay() {
        if (!overlay) return;
        overlay.classList.remove('agentic-overlay-visible');
        // Remove from DOM after transition.
        setTimeout(function () {
            if (overlay) overlay.remove();
            overlay = null;
            activeAgent = null;
        }, 250);
    }

    /* ------------------------------------------------------------------ */
    /*  Build DOM                                                          */
    /* ------------------------------------------------------------------ */
    function buildOverlay(displayName) {
        overlay = el('div', { className: 'agentic-overlay' });

        // Backdrop.
        const backdrop = el('div', { className: 'agentic-overlay-backdrop' });
        backdrop.addEventListener('click', closeOverlay);
        overlay.appendChild(backdrop);

        // Panel.
        const panel = el('div', { className: 'agentic-overlay-panel' });

        // --- Header ---
        const header = el('div', { className: 'agentic-overlay-header' });
        const title  = el('span', { className: 'agentic-overlay-title', textContent: displayName || activeAgent });

        const actions = el('div', { className: 'agentic-overlay-actions' });

        const newBtn = el('button', {
            className: 'agentic-overlay-btn',
            title: 'New conversation',
            innerHTML: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>'
        });
        newBtn.addEventListener('click', clearConversation);

        const closeBtn = el('button', {
            className: 'agentic-overlay-btn agentic-overlay-close',
            title: 'Close',
            innerHTML: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>'
        });
        closeBtn.addEventListener('click', closeOverlay);

        actions.appendChild(newBtn);
        actions.appendChild(closeBtn);
        header.appendChild(title);
        header.appendChild(actions);
        panel.appendChild(header);

        // --- Messages ---
        const msgs = el('div', { className: 'agentic-overlay-messages', id: 'agentic-overlay-msgs' });
        panel.appendChild(msgs);

        // --- Typing indicator ---
        const typing = el('div', { className: 'agentic-overlay-typing', id: 'agentic-overlay-typing', style: 'display:none' });
        typing.innerHTML = '<span></span><span></span><span></span>';
        panel.appendChild(typing);

        // --- Image preview ---
        const previewWrap = el('div', { className: 'agentic-overlay-image-preview', id: 'agentic-overlay-preview', style: 'display:none' });
        const previewImg  = el('img', { id: 'agentic-overlay-preview-img' });
        const removeImg   = el('button', { className: 'agentic-overlay-remove-img', textContent: '✕', title: 'Remove image' });
        removeImg.addEventListener('click', clearImage);
        previewWrap.appendChild(previewImg);
        previewWrap.appendChild(removeImg);
        panel.appendChild(previewWrap);

        // Copy button on code blocks.
        document.addEventListener('click', function(ev) {
            if (ev.target.classList.contains('agentic-copy-btn')) {
                var code = ev.target.nextElementSibling.textContent;
                navigator.clipboard.writeText(code).then(function() {
                    ev.target.textContent = 'Copied!';
                    setTimeout(function() { ev.target.textContent = 'Copy'; }, 2000);
                });
            }
        });

        // --- Input form ---
        const form = el('form', { className: 'agentic-overlay-form', id: 'agentic-overlay-form' });

        const attachBtn = el('button', {
            type: 'button',
            className: 'agentic-overlay-attach',
            title: 'Attach image',
            innerHTML: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66L9.41 17.41a2 2 0 01-2.83-2.83l8.49-8.49"/></svg>'
        });
        const fileInput = el('input', { type: 'file', accept: 'image/*', style: 'display:none', id: 'agentic-overlay-file' });
        attachBtn.addEventListener('click', function () { fileInput.click(); });
        fileInput.addEventListener('change', handleFileSelect);

        const textarea = el('textarea', {
            className: 'agentic-overlay-input',
            id: 'agentic-overlay-input',
            placeholder: 'Type your message…',
            rows: 1
        });
        textarea.addEventListener('input', autoResize);
        textarea.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                form.dispatchEvent(new Event('submit'));
            }
        });

        const sendBtn = el('button', {
            type: 'submit',
            className: 'agentic-overlay-send',
            id: 'agentic-overlay-send',
            innerHTML: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>'
        });

        form.appendChild(fileInput);
        form.appendChild(attachBtn);
        form.appendChild(textarea);
        form.appendChild(sendBtn);
        form.addEventListener('submit', handleSubmit);

        panel.appendChild(form);

        // Footer branding.
        if (typeof agenticChat !== 'undefined' && agenticChat.showBranding === '1') {
            const footer = el('div', { className: 'agentic-overlay-footer' });
            var parts = [ ( window.wp && wp.i18n && typeof wp.i18n.__ === 'function' ) ? wp.i18n.__( 'Powered by Agent Builder', 'agent-builder' ) : 'Powered by Agent Builder' ];
            if (agenticChat.provider) parts.push(agenticChat.provider);
            if (agenticChat.model) parts.push(agenticChat.model);
            footer.textContent = parts.join(' - ');
            panel.appendChild(footer);
        }

        overlay.appendChild(panel);
        document.body.appendChild(overlay);
    }

    /* ------------------------------------------------------------------ */
    /*  Messages                                                           */
    /* ------------------------------------------------------------------ */
    function addMessage(content, role, meta, imageData) {
        const msgs = document.getElementById('agentic-overlay-msgs');
        if (!msgs) return;

        const div = el('div', { className: 'agentic-overlay-msg agentic-overlay-msg-' + role });

        // Attached image.
        if (imageData && imageData.dataUrl) {
            const img = el('img', { src: imageData.dataUrl, className: 'agentic-overlay-chat-img', alt: imageData.name || 'image' });
            div.appendChild(img);
        }

        const body = el('div', { className: 'agentic-overlay-msg-body' });
        if (content) {
            body.innerHTML = role === 'agent' ? renderMarkdown(content) : esc(content);
        }
        div.appendChild(body);

        // Meta bar.
        if (role === 'agent' && meta) {
            const metaDiv = el('div', { className: 'agentic-overlay-msg-meta' });
            if (meta.cached) metaDiv.innerHTML += '<span title="Cached">⚡</span> ';
            div.appendChild(metaDiv);

            // Show a proposal (medium risk) or approval-queue (high risk) card
            // for a pending action, so it can be resolved right here instead of
            // only on a separate admin page.
            if (meta.proposal) {
                div.appendChild(renderProposalCard(meta.proposal));
            }
        }

        msgs.appendChild(div);
        msgs.scrollTop = msgs.scrollHeight;
        return div;
    }

    /* ------------------------------------------------------------------ */
    /*  Proposals & approvals                                              */
    /*  Mirrors assets/js/chat.js's two card kinds:                        */
    /*    - "proposal" (medium risk, user-space): once/session/always/deny */
    /*    - "approval"  (high risk, admin queue): approve/reject only,     */
    /*      backed by the same secure endpoint the Approvals admin page    */
    /*      uses. Every user who can even see this overlay already passed  */
    /*      the manage_options gate in enqueue_adminbar_chat_overlay(), so */
    /*      agenticChat.isAdmin is checked here only for defense-in-depth  */
    /*      consistency with the other chat surfaces, not as the real gate.*/
    /* ------------------------------------------------------------------ */
    function renderProposalCard(proposal) {
        return proposal.kind === 'approval' ? renderApprovalCard(proposal) : renderProposal(proposal);
    }

    function renderProposal(proposal) {
        const card = el('div', { className: 'agentic-overlay-proposal-card' });
        card.dataset.proposalId = proposal.id;

        const header = el('div', {
            className: 'agentic-overlay-proposal-header',
            innerHTML: '<span class="dashicons dashicons-editor-code"></span> <strong>Proposed Change</strong>'
        });
        card.appendChild(header);

        const desc = el('div', {
            className: 'agentic-overlay-proposal-desc',
            textContent: proposal.description || 'Agent wants to make a change.'
        });
        card.appendChild(desc);

        // Key arguments (title/status/post type) so the user sees what they're
        // approving, not just the generic tool description. Rendered as text
        // nodes, never innerHTML, so a title with &, quotes or dashes shows as-is.
        if (proposal.summary && typeof proposal.summary === 'object') {
            const summaryKeys = Object.keys(proposal.summary);
            if (summaryKeys.length) {
                const summaryDiv = document.createElement('div');
                summaryDiv.className = 'agentic-overlay-proposal-summary';
                const i18n = (typeof agenticChat !== 'undefined' && agenticChat.i18n) || {};
                const labels = {
                    title: i18n.summaryTitle || 'Title',
                    status: i18n.summaryStatus || 'Status',
                    post_type: i18n.summaryPostType || 'Post type',
                    post_id: i18n.summaryPost || 'Post'
                };
                summaryKeys.forEach(function (key) {
                    const value = proposal.summary[key];
                    if (value === null || value === undefined || value === '') return;
                    const row = document.createElement('div');
                    row.className = 'agentic-overlay-proposal-summary-row';
                    const label = document.createElement('strong');
                    label.textContent = (labels[key] || key.replace(/_/g, ' ')) + ': ';
                    row.appendChild(label);
                    row.appendChild(document.createTextNode(String(value)));
                    summaryDiv.appendChild(row);
                });
                card.appendChild(summaryDiv);
            }
        }

        if (proposal.diff) {
            const diffToggle = el('button', {
                type: 'button',
                className: 'agentic-overlay-proposal-toggle',
                textContent: '▶ Show Diff'
            });
            card.appendChild(diffToggle);

            const diffPre = el('pre', {
                className: 'agentic-overlay-proposal-diff',
                style: 'display:none',
                innerHTML: formatDiff(proposal.diff)
            });
            card.appendChild(diffPre);

            diffToggle.addEventListener('click', function () {
                const visible = diffPre.style.display !== 'none';
                diffPre.style.display = visible ? 'none' : 'block';
                diffToggle.textContent = visible ? '▶ Show Diff' : '▼ Hide Diff';
            });
        }

        const actions = el('div', { className: 'agentic-overlay-proposal-actions' });

        if (agenticChat.isAdmin === '1') {
            const onceBtn = el('button', {
                type: 'button',
                className: 'agentic-overlay-proposal-btn agentic-overlay-proposal-approve',
                innerHTML: '<span class="dashicons dashicons-yes"></span> Allow Once'
            });
            onceBtn.addEventListener('click', () => handleProposalAction(proposal.id, 'once', card));

            const sessionBtn = el('button', {
                type: 'button',
                className: 'agentic-overlay-proposal-btn agentic-overlay-proposal-session',
                innerHTML: '<span class="dashicons dashicons-clock"></span> Allow this Session'
            });
            sessionBtn.addEventListener('click', () => handleProposalAction(proposal.id, 'session', card));

            const alwaysBtn = el('button', {
                type: 'button',
                className: 'agentic-overlay-proposal-btn agentic-overlay-proposal-always',
                innerHTML: '<span class="dashicons dashicons-star-filled"></span> Always Allow'
            });
            alwaysBtn.addEventListener('click', () => handleProposalAction(proposal.id, 'always', card));

            const rejectBtn = el('button', {
                type: 'button',
                className: 'agentic-overlay-proposal-btn agentic-overlay-proposal-reject',
                innerHTML: '<span class="dashicons dashicons-no"></span> Deny'
            });
            rejectBtn.addEventListener('click', () => handleProposalAction(proposal.id, 'reject', card));

            actions.appendChild(onceBtn);
            actions.appendChild(sessionBtn);
            actions.appendChild(alwaysBtn);
            actions.appendChild(rejectBtn);
        } else {
            actions.appendChild(el('div', {
                className: 'agentic-overlay-proposal-status',
                textContent: 'This action requires admin approval.'
            }));
        }
        card.appendChild(actions);

        return card;
    }

    function renderApprovalCard(proposal) {
        const card = el('div', { className: 'agentic-overlay-proposal-card' });
        card.dataset.proposalId = proposal.id;

        const header = el('div', {
            className: 'agentic-overlay-proposal-header',
            innerHTML: '<span class="dashicons dashicons-lock"></span> <strong>Needs Your Approval</strong>'
        });
        card.appendChild(header);

        const desc = el('div', {
            className: 'agentic-overlay-proposal-desc',
            textContent: proposal.description || 'An agent wants to perform a high-risk action.'
        });
        card.appendChild(desc);

        const actions = el('div', { className: 'agentic-overlay-proposal-actions' });

        if (agenticChat.isAdmin === '1') {
            const approveBtn = el('button', {
                type: 'button',
                className: 'agentic-overlay-proposal-btn agentic-overlay-proposal-approve',
                innerHTML: '<span class="dashicons dashicons-yes"></span> Approve'
            });
            approveBtn.addEventListener('click', () => handleApprovalAction(proposal.id, 'approve', card));

            const rejectBtn = el('button', {
                type: 'button',
                className: 'agentic-overlay-proposal-btn agentic-overlay-proposal-reject',
                innerHTML: '<span class="dashicons dashicons-no"></span> Reject'
            });
            rejectBtn.addEventListener('click', () => handleApprovalAction(proposal.id, 'reject', card));

            actions.appendChild(approveBtn);
            actions.appendChild(rejectBtn);
        } else {
            actions.appendChild(el('div', {
                className: 'agentic-overlay-proposal-status',
                textContent: 'This action requires admin approval.'
            }));
        }
        card.appendChild(actions);

        return card;
    }

    function formatDiff(diff) {
        return diff.split('\n').map(function (line) {
            const escaped = esc(line);
            if (line.startsWith('+')) return '<span class="diff-add">' + escaped + '</span>';
            if (line.startsWith('-')) return '<span class="diff-del">' + escaped + '</span>';
            if (line.startsWith('@@')) return '<span class="diff-hunk">' + escaped + '</span>';
            return escaped;
        }).join('\n');
    }

    // Send a once/session/always/reject decision to the user-space proposal endpoint.
    async function handleProposalAction(proposalId, action, cardElement) {
        const buttons = cardElement.querySelectorAll('.agentic-overlay-proposal-btn');
        buttons.forEach(function (btn) { btn.disabled = true; });

        try {
            const response = await fetch(agenticChat.restUrl + 'proposals/' + proposalId, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': agenticChat.nonce },
                body: JSON.stringify({ action: action, session_id: sessionId })
            });
            const data = await response.json();

            const isApproveVariant = action === 'once' || action === 'session' || action === 'always';
            cardElement.classList.add('agentic-overlay-proposal-' + (isApproveVariant ? 'approved' : 'rejected'));

            const actionsDiv = cardElement.querySelector('.agentic-overlay-proposal-actions');
            let statusText;
            if (action === 'once') statusText = '✅ Allowed once — change applied.';
            else if (action === 'session') statusText = '✅ Allowed for this session — change applied.';
            else if (action === 'always') statusText = '✅ Always allowed — change applied.';
            else statusText = '❌ Denied — no change made.';
            if (data.error) statusText = '⚠️ ' + data.error;
            actionsDiv.innerHTML = '<div class="agentic-overlay-proposal-status' + (data.error ? ' agentic-overlay-proposal-error' : '') + '">' + esc(statusText) + '</div>';
        } catch (error) {
            buttons.forEach(function (btn) { btn.disabled = false; });
            addMessage('Error processing proposal: ' + error.message, 'agent');
        }
    }

    // Send an approve/reject decision to the real admin approval-queue REST
    // endpoint (the same one the Approvals page uses) — never a call the LLM
    // can trigger itself; only a genuine click from a signed-in admin browser
    // session reaches this function.
    async function handleApprovalAction(approvalId, action, cardElement) {
        const buttons = cardElement.querySelectorAll('.agentic-overlay-proposal-btn');
        buttons.forEach(function (btn) { btn.disabled = true; });

        try {
            const response = await fetch(agenticChat.restUrl + 'approvals/' + approvalId, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': agenticChat.nonce },
                body: JSON.stringify({ action: action })
            });
            const data = await response.json();
            const actionsDiv = cardElement.querySelector('.agentic-overlay-proposal-actions');

            if (!response.ok || data.success === false) {
                const errMsg = (data.error && data.error.message) || data.message || 'Something went wrong.';
                actionsDiv.innerHTML = '<div class="agentic-overlay-proposal-status agentic-overlay-proposal-error">⚠️ ' + esc(errMsg) + '</div>';
                buttons.forEach(function (btn) { btn.disabled = false; });
                return;
            }

            cardElement.classList.add('agentic-overlay-proposal-' + (action === 'approve' ? 'approved' : 'rejected'));
            const okMsg = (data.data && data.data.message) || (action === 'approve' ? 'Approved.' : 'Rejected.');
            actionsDiv.innerHTML = '<div class="agentic-overlay-proposal-status">' + (action === 'approve' ? '✅ ' : '❌ ') + esc(okMsg) + '</div>';
        } catch (error) {
            buttons.forEach(function (btn) { btn.disabled = false; });
            addMessage('Error processing approval: ' + error.message, 'agent');
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Send / receive                                                     */
    /* ------------------------------------------------------------------ */
    async function handleSubmit(e) {
        e.preventDefault();
        const input = document.getElementById('agentic-overlay-input');
        const message = (input ? input.value.trim() : '');
        if ((!message && !pendingImage) || isProcessing) return;

        const imageData = pendingImage ? Object.assign({}, pendingImage) : null;
        addMessage(message, 'user', null, imageData);

        if (input) { input.value = ''; input.style.height = 'auto'; }
        clearImage();

        const text = message || 'Describe this image.';
        history.push({ role: 'user', content: text });
        saveHistory();

        await sendMessage(text, imageData);
    }

    async function sendMessage(text, imageData) {
        isProcessing = true;
        toggleSend(true);
        showTyping(true);

        try {
            var payload = {
                message:    text,
                session_id: sessionId,
                agent_id:   activeAgent,
                history:    history.slice(-20)
            };
            if (imageData && imageData.dataUrl) {
                payload.image = imageData.dataUrl;
            }

            var res = await fetch(agenticChat.restUrl + 'chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': agenticChat.nonce
                },
                body: JSON.stringify(payload)
            });

            var data = await res.json();

            if (data.error) {
                const isQuota = res.status === 429 || res.status === 402;
                const msg = addMessage(data.response || 'Something went wrong. The issue has been reported.', 'agent');
                if (isQuota && msg) msg.classList.add('agentic-quota-error');
            } else {
                history.push({ role: 'assistant', content: data.response });
                saveHistory();
                addMessage(data.response, 'agent', {
                    tokens:   data.tokens_used,
                    cost:     data.cost,
                    tools:    data.tools_used,
                    cached:   data.cached || false,
                    proposal: data.pending_proposal ? data.proposal : null
                });
            }
        } catch (err) {
            addMessage('Connection error — please try again.', 'agent');
        } finally {
            isProcessing = false;
            toggleSend(false);
            showTyping(false);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Image handling                                                     */
    /* ------------------------------------------------------------------ */
    function handleFileSelect() {
        var file = this.files[0]; // eslint-disable-line no-invalid-this
        if (!file) return;
        if (!file.type.startsWith('image/')) { agenticUI.toast('Only images supported.', 'warning'); this.value = ''; return; }
        if (file.size > 5 * 1024 * 1024) { agenticUI.toast('Max 5 MB.', 'warning'); this.value = ''; return; }

        var reader = new FileReader();
        reader.onload = function (ev) {
            pendingImage = { dataUrl: ev.target.result, mimeType: file.type, name: file.name };
            var wrap = document.getElementById('agentic-overlay-preview');
            var img  = document.getElementById('agentic-overlay-preview-img');
            if (wrap && img) { img.src = ev.target.result; wrap.style.display = 'flex'; }
        };
        reader.readAsDataURL(file);
    }

    function clearImage() {
        pendingImage = null;
        var wrap  = document.getElementById('agentic-overlay-preview');
        var input = document.getElementById('agentic-overlay-file');
        if (wrap) wrap.style.display = 'none';
        if (input) input.value = '';
    }

    /* ------------------------------------------------------------------ */
    /*  Conversation persistence                                           */
    /* ------------------------------------------------------------------ */
    function saveHistory() {
        if (!activeAgent) return;
        localStorage.setItem('agentic_overlay_history_' + activeAgent, JSON.stringify(history));
    }

    function loadHistory() {
        if (!activeAgent) return;
        var raw = localStorage.getItem('agentic_overlay_history_' + activeAgent);
        if (raw) {
            try {
                history = JSON.parse(raw);
                history.forEach(function (m) {
                    addMessage(m.content, m.role === 'user' ? 'user' : 'agent');
                });
            } catch (e) {
                history = [];
            }
        }

        // Show welcome message for new conversations.
        if (!history.length) {
            showWelcomeMessage();
        }
    }

    function showWelcomeMessage() {
        if (!activeAgent) return;
        var messages = (typeof agenticChat !== 'undefined' && agenticChat.welcomeMessages) || {};
        var msg = messages[activeAgent];
        if (!msg) return;
        addMessage(msg, 'agent');

        // Add quick-action buttons for WordPress Assistant.
        if (activeAgent === 'onboarding-agent') {
            var msgs = document.getElementById('agentic-overlay-msgs');
            if (!msgs) return;
            var btnsWrap = el('div', { className: 'agentic-overlay-quick-actions' });
            var actions = [
                { label: 'Agent Builder', slug: 'wordpress-assistant' },
                { label: 'Content Agent', slug: 'content-writer' },
                { label: 'Plugin Agent', slug: 'plugin-assistant' },
                { label: 'Theme Agent', slug: 'theme-assistant' }
            ];
            actions.forEach(function (action) {
                var btn = el('button', { className: 'agentic-overlay-quick-btn', textContent: action.label });
                btn.addEventListener('click', function () {
                    // Close onboarding overlay and open the selected agent.
                    closeOverlay();
                    var names = (typeof agenticChat !== 'undefined' && agenticChat.agentNames) || {};
                    var displayName = names[action.slug] || action.label;
                    setTimeout(function () {
                        openOverlay(action.slug, displayName);
                    }, 300);
                });
                btnsWrap.appendChild(btn);
            });
            msgs.appendChild(btnsWrap);
            msgs.scrollTop = msgs.scrollHeight;
        }
    }

    function clearConversation() {
        if (!activeAgent) return;
        history = [];
        localStorage.removeItem('agentic_overlay_history_' + activeAgent);
        sessionId = uuid();
        localStorage.setItem('agentic_overlay_session_' + activeAgent, sessionId);
        var msgs = document.getElementById('agentic-overlay-msgs');
        if (msgs) msgs.innerHTML = '';
        showWelcomeMessage();
    }

    /* ------------------------------------------------------------------ */
    /*  UI helpers                                                         */
    /* ------------------------------------------------------------------ */
    function showTyping(on) {
        var t = document.getElementById('agentic-overlay-typing');
        if (t) t.style.display = on ? 'flex' : 'none';
    }

    function toggleSend(disabled) {
        var btn = document.getElementById('agentic-overlay-send');
        if (btn) btn.disabled = disabled;
    }

    function autoResize() {
        this.style.height = 'auto'; // eslint-disable-line no-invalid-this
        this.style.height = Math.min(this.scrollHeight, 120) + 'px'; // eslint-disable-line no-invalid-this
    }

    /* ------------------------------------------------------------------ */
    /*  Markdown (lightweight)                                             */
    /* ------------------------------------------------------------------ */
    function renderMarkdown(text) {
        // Delegates to the shared AgenticMarkdown utility (assets/js/agentic-markdown.js),
        // loaded as a script dependency. The overlay has no agent handoff, so
        // delegate links stay off.
        return window.AgenticMarkdown.render(text);
    }

    /* ------------------------------------------------------------------ */
    /*  Utilities                                                          */
    /* ------------------------------------------------------------------ */
    function el(tag, props) {
        var node = document.createElement(tag);
        if (props) Object.keys(props).forEach(function (k) {
            if (k === 'className')       node.className   = props[k];
            else if (k === 'innerHTML')  node.innerHTML   = props[k];
            else if (k === 'textContent') node.textContent = props[k];
            else if (k === 'style' && typeof props[k] === 'string') node.style.cssText = props[k];
            else node.setAttribute(k, props[k]);
        });
        return node;
    }

    function esc(text) {
        var d = document.createElement('div');
        d.textContent = text;
        return d.innerHTML;
    }

    function uuid() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }
})();
