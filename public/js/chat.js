(() => {
    'use strict';

    const els = {
        messages: document.getElementById('messages'),
        welcome: document.getElementById('welcome'),
        suggestions: document.getElementById('suggestions'),
        form: document.getElementById('composer'),
        input: document.getElementById('input'),
        send: document.getElementById('send'),
        newChat: document.getElementById('new-chat'),
        orders: document.getElementById('orders'),
        refreshOrders: document.getElementById('refresh-orders'),
    };

    const TOOL_LABELS = {
        find_order: 'Looked up order',
        list_customer_orders: 'Listed customer orders',
        search_products: 'Searched products',
    };

    const STORAGE_KEY = 'kiez-living-thread';
    let threadId = loadThreadId();
    let busy = false;

    // ---- Thread ID (one conversation per browser, kept across reloads) ----

    function newId() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'thread-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
    }

    function loadThreadId() {
        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved && /^[A-Za-z0-9-]{8,64}$/.test(saved)) return saved;
        } catch (e) { /* storage blocked: fall back to a per-page thread */ }
        return saveThreadId(newId());
    }

    function saveThreadId(id) {
        try { localStorage.setItem(STORAGE_KEY, id); } catch (e) { /* ignore */ }
        return id;
    }

    // ---- Rendering ----

    function escapeHtml(text) {
        return text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    // Small, safe Markdown subset: **bold**, `code`, bullet lists, paragraphs.
    function renderMarkdown(text) {
        const inline = (s) => escapeHtml(s)
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/`([^`]+)`/g, '<code>$1</code>');

        const isItem = (l) => /^\s*([-*•]|\d+\.)\s+/.test(l);
        const stripItem = (l) => l.replace(/^\s*([-*•]|\d+\.)\s+/, '');

        // Group consecutive list lines into <ul>, other lines into <p> (so "Items:\n- a\n- b" works)
        return text.trim().split(/\n{2,}/).map((block) => {
            let html = '';
            let para = [];
            let items = [];
            const flushPara = () => { if (para.length) { html += '<p>' + para.map(inline).join('<br>') + '</p>'; para = []; } };
            const flushList = () => { if (items.length) { html += '<ul>' + items.map((l) => '<li>' + inline(stripItem(l)) + '</li>').join('') + '</ul>'; items = []; } };

            block.split('\n').forEach((line) => {
                if (isItem(line)) { flushPara(); items.push(line); } else { flushList(); para.push(line); }
            });
            flushPara();
            flushList();
            return html;
        }).join('');
    }

    function hideWelcome() {
        if (els.welcome) els.welcome.hidden = true;
    }

    function scrollToBottom() {
        els.messages.scrollTop = els.messages.scrollHeight;
    }

    function addMessage(role, text, toolsUsed = []) {
        hideWelcome();
        const wrap = document.createElement('div');
        wrap.className = 'msg ' + role;

        const bubble = document.createElement('div');
        bubble.className = 'bubble';
        if (role === 'assistant') {
            bubble.innerHTML = renderMarkdown(text);
        } else {
            bubble.textContent = text;
        }
        wrap.appendChild(bubble);

        if (toolsUsed.length) {
            const tools = document.createElement('div');
            tools.className = 'tools';
            toolsUsed.forEach((name) => {
                const tag = document.createElement('span');
                tag.className = 'tool-tag';
                tag.title = 'Tool: ' + name;
                tag.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M22 19l-6.6-6.6a6 6 0 0 0-7.8-7.8l3.9 3.9-2.8 2.8-3.9-3.9a6 6 0 0 0 7.8 7.8L19.2 22z"/></svg>';
                tag.appendChild(document.createTextNode(TOOL_LABELS[name] || name));
                tools.appendChild(tag);
            });
            wrap.appendChild(tools);
        }

        els.messages.appendChild(wrap);
        scrollToBottom();
    }

    function addError(message, detail) {
        hideWelcome();
        const wrap = document.createElement('div');
        wrap.className = 'msg assistant error';
        const bubble = document.createElement('div');
        bubble.className = 'bubble';
        bubble.textContent = message;
        if (detail) {
            const d = document.createElement('span');
            d.className = 'detail';
            d.textContent = detail;
            bubble.appendChild(d);
        }
        wrap.appendChild(bubble);
        els.messages.appendChild(wrap);
        scrollToBottom();
    }

    function showTyping() {
        const wrap = document.createElement('div');
        wrap.className = 'msg assistant';
        wrap.id = 'typing';
        wrap.innerHTML = '<div class="bubble typing" aria-label="Assistant is typing"><span></span><span></span><span></span></div>';
        els.messages.appendChild(wrap);
        scrollToBottom();
    }

    function hideTyping() {
        const t = document.getElementById('typing');
        if (t) t.remove();
    }

    function clearMessages() {
        els.messages.querySelectorAll('.msg').forEach((m) => m.remove());
        if (els.welcome) els.welcome.hidden = false;
    }

    // ---- API ----

    async function api(path, options = {}) {
        const res = await fetch(path, {
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            ...options,
        });
        let data = null;
        try { data = await res.json(); } catch (e) { /* non-JSON error page */ }
        if (!res.ok) {
            const err = new Error((data && data.error) || 'Request failed (' + res.status + ')');
            err.detail = data && data.detail;
            throw err;
        }
        return data;
    }

    async function sendMessage(text) {
        text = text.trim();
        if (!text || busy) return;

        busy = true;
        els.send.disabled = true;
        els.newChat.disabled = true;
        addMessage('user', text);
        els.input.value = '';
        autoGrow();
        showTyping();

        try {
            const data = await api('/api/chat', {
                method: 'POST',
                body: JSON.stringify({ threadId, message: text }),
            });
            hideTyping();
            addMessage('assistant', data.reply || '…', data.tools_used || []);
            loadOrders();
        } catch (e) {
            hideTyping();
            addError(e.message, e.detail);
        } finally {
            busy = false;
            els.send.disabled = false;
            els.newChat.disabled = false;
            els.input.focus();
        }
    }

    async function loadHistory() {
        try {
            const data = await api('/api/history?threadId=' + encodeURIComponent(threadId));
            (data.messages || []).forEach((m) => addMessage(m.role, m.text));
        } catch (e) {
            addError('Could not load the conversation.', e.message);
        }
    }

    async function loadOrders() {
        try {
            const data = await api('/api/orders');
            els.orders.innerHTML = '';
            if (!data.orders.length) {
                els.orders.innerHTML = '<li class="orders-empty">No orders. Run <code>bin/console app:db:setup</code>.</li>';
                return;
            }
            data.orders.forEach((o) => {
                const li = document.createElement('li');
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'order';
                btn.innerHTML =
                    '<span class="order-no"></span><span class="status status-' + escapeHtml(o.status) + '"></span>' +
                    '<span class="order-meta"></span><span class="order-total"></span>';
                btn.querySelector('.order-no').textContent = o.order_number;
                btn.querySelector('.status').textContent = o.status;
                btn.querySelector('.order-meta').textContent = o.customer_name + ' · ' + o.ordered_at;
                btn.querySelector('.order-total').textContent = Number(o.total_eur).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });
                btn.addEventListener('click', () => {
                    els.input.value = 'What is the status of order ' + o.order_number + '?';
                    autoGrow();
                    els.input.focus();
                });
                li.appendChild(btn);
                els.orders.appendChild(li);
            });
        } catch (e) {
            els.orders.innerHTML = '';
            const li = document.createElement('li');
            li.className = 'orders-empty';
            li.textContent = 'Could not load orders: ' + e.message;
            els.orders.appendChild(li);
        }
    }

    // ---- Events ----

    function autoGrow() {
        els.input.style.height = 'auto';
        els.input.style.height = Math.min(els.input.scrollHeight, 160) + 'px';
    }

    els.form.addEventListener('submit', (e) => {
        e.preventDefault();
        sendMessage(els.input.value);
    });

    els.input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage(els.input.value);
        }
    });

    els.input.addEventListener('input', autoGrow);

    els.suggestions.addEventListener('click', (e) => {
        const chip = e.target.closest('.chip');
        if (chip) sendMessage(chip.textContent);
    });

    els.newChat.addEventListener('click', async () => {
        if (busy) return;
        try {
            await api('/api/history?threadId=' + encodeURIComponent(threadId), { method: 'DELETE' });
        } catch (e) { /* start fresh anyway */ }
        threadId = saveThreadId(newId());
        clearMessages();
        els.input.focus();
    });

    els.refreshOrders.addEventListener('click', loadOrders);

    loadHistory();
    loadOrders();
})();
