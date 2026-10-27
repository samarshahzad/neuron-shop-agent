(() => {
    'use strict';

    const $ = (id) => document.getElementById(id);

    const els = {
        messages: $('messages'),
        welcome: $('welcome'),
        suggestions: $('suggestions'),
        form: $('composer'),
        input: $('input'),
        send: $('send'),
        newChat: $('new-chat'),
        sidePanel: $('side-panel'),
        account: $('account'),
        signInDialog: $('sign-in-dialog'),
        customerList: $('customer-list'),
        orderLogin: $('order-login'),
        loginError: $('login-error'),
        tabs: document.querySelectorAll('.tab'),
        viewAssistant: $('view-assistant'),
        viewProducts: $('view-products'),
        filters: $('filters'),
        productGrid: $('product-grid'),
        similarityHint: $('similarity-hint'),
        similarDialog: $('similar-dialog'),
        similarTitle: $('similar-title'),
        similarSub: $('similar-sub'),
        similarList: $('similar-list'),
    };

    const TOOL_LABELS = {
        find_order: 'Looked up order',
        list_customer_orders: 'Listed customer orders',
        search_products: 'Searched products',
        recommend_products: 'Recommended products',
    };

    const METHOD_LABELS = {
        embeddings: 'AI similarity',
        rules: 'Rule-based',
        bestsellers: 'Bestsellers',
    };

    // Colors for the product swatches (no product photos in this demo)
    const SWATCHES = {
        'sage green': '#b7c4a6', grey: '#c9c6c1', sand: '#e3d3b8', cream: '#f1e8d6', natural: '#d9c29d',
        white: '#f4f1ec', clear: '#dfe9ec', terracotta: '#d39a7a', 'olive green': '#a9ad7c', beige: '#e8dcc4',
        gold: '#dcc58b', 'warm white': '#f6e7bf',
    };

    const STORAGE_KEY = 'kiez-living-thread';
    let threadId = loadThreadId();
    let busy = false;
    let customer = null;
    let productsLoaded = false;
    let activeCategory = '';

    // ---- Helpers ----

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

    function escapeHtml(text) {
        return String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function euro(value) {
        return Number(value).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });
    }

    function initials(name) {
        return name.split(/\s+/).map((p) => p[0]).join('').slice(0, 2).toUpperCase();
    }

    // Build an element: el('div', {className: 'x'}, [children or text])
    function el(tag, props = {}, children = []) {
        const node = Object.assign(document.createElement(tag), props);
        (Array.isArray(children) ? children : [children]).forEach((c) => {
            if (c === null || c === undefined) return;
            node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        });
        return node;
    }

    function swatch(product) {
        const s = el('span', { className: 'swatch' }, product.category[0]);
        s.style.background = SWATCHES[product.color] || '#e4d9cb';
        s.setAttribute('aria-hidden', 'true');
        return s;
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

    // ---- API ----

    async function api(path, options = {}) {
        const res = await fetch(path, {
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            credentials: 'same-origin',
            ...options,
        });
        let data = null;
        try { data = await res.json(); } catch (e) { /* non-JSON error page */ }
        if (!res.ok) {
            const err = new Error((data && data.error) || 'Request failed (' + res.status + ')');
            err.detail = data && data.detail;
            err.status = res.status;
            throw err;
        }
        return data;
    }

    // ---- Chat ----

    function hideWelcome() {
        if (els.welcome) els.welcome.hidden = true;
    }

    function scrollToBottom() {
        els.messages.scrollTop = els.messages.scrollHeight;
    }

    function addMessage(role, text, toolsUsed = []) {
        hideWelcome();
        const wrap = el('div', { className: 'msg ' + role });
        const bubble = el('div', { className: 'bubble' });
        if (role === 'assistant') {
            bubble.innerHTML = renderMarkdown(text);
        } else {
            bubble.textContent = text;
        }
        wrap.appendChild(bubble);

        if (toolsUsed.length) {
            const tools = el('div', { className: 'tools' });
            toolsUsed.forEach((name) => {
                const tag = el('span', { className: 'tool-tag', title: 'Tool: ' + name });
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
        const bubble = el('div', { className: 'bubble' }, message);
        if (detail) bubble.appendChild(el('span', { className: 'detail' }, detail));
        els.messages.appendChild(el('div', { className: 'msg assistant error' }, bubble));
        scrollToBottom();
    }

    function showTyping() {
        const wrap = el('div', { className: 'msg assistant', id: 'typing' });
        wrap.innerHTML = '<div class="bubble typing" aria-label="Assistant is typing"><span></span><span></span><span></span></div>';
        els.messages.appendChild(wrap);
        scrollToBottom();
    }

    function hideTyping() {
        const t = $('typing');
        if (t) t.remove();
    }

    function clearMessages() {
        els.messages.querySelectorAll('.msg').forEach((m) => m.remove());
        if (els.welcome) els.welcome.hidden = false;
    }

    async function startNewChat() {
        try {
            await api('/api/history?threadId=' + encodeURIComponent(threadId), { method: 'DELETE' });
        } catch (e) { /* start fresh anyway */ }
        threadId = saveThreadId(newId());
        clearMessages();
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

    function askAbout(text) {
        els.input.value = text;
        autoGrow();
        els.input.focus();
    }

    // ---- Account ----

    function renderAccount() {
        els.account.innerHTML = '';
        if (!customer) {
            const btn = el('button', { type: 'button', className: 'btn-ghost', id: 'sign-in' }, 'Sign in');
            btn.addEventListener('click', openSignIn);
            els.account.appendChild(btn);
            return;
        }
        const chip = el('span', { className: 'account-chip' }, [
            el('span', { className: 'avatar' }, initials(customer.name)),
            el('span', { className: 'account-name' }, customer.name),
        ]);
        const out = el('button', { type: 'button', className: 'btn-link' }, 'Sign out');
        out.addEventListener('click', signOut);
        els.account.append(chip, out);
    }

    async function openSignIn() {
        els.loginError.hidden = true;
        els.orderLogin.reset();
        els.customerList.innerHTML = '<li class="orders-empty">Loading…</li>';
        els.signInDialog.showModal();

        try {
            const data = await api('/api/customers');
            els.customerList.innerHTML = '';
            data.customers.forEach((c) => {
                const btn = el('button', { type: 'button', className: 'customer-btn' }, [
                    el('span', { className: 'avatar' }, initials(c.name)),
                    el('span', {}, [c.name, el('br'), el('small', {}, c.email)]),
                    el('small', {}, c.orders + (c.orders === 1 ? ' order' : ' orders')),
                ]);
                btn.addEventListener('click', () => signIn({ customerId: c.id }));
                els.customerList.appendChild(el('li', {}, btn));
            });
        } catch (e) {
            els.customerList.innerHTML = '';
            els.customerList.appendChild(el('li', { className: 'form-error' }, 'Could not load customers: ' + e.message));
        }
    }

    async function signIn(payload) {
        els.loginError.hidden = true;
        try {
            const data = await api('/api/session', { method: 'POST', body: JSON.stringify(payload) });
            customer = data.customer;
            els.signInDialog.close();
            await startNewChat(); // a new customer gets a fresh conversation
            renderAccount();
            renderSidePanel();
        } catch (e) {
            els.loginError.textContent = e.message;
            els.loginError.hidden = false;
        }
    }

    async function signOut() {
        try { await api('/api/session', { method: 'DELETE' }); } catch (e) { /* ignore */ }
        customer = null;
        await startNewChat();
        renderAccount();
        renderSidePanel();
    }

    // ---- Side panel ----

    function section(title, badge, body) {
        const head = el('div', { className: 'panel-head' }, [el('h2', {}, title)]);
        if (badge) head.appendChild(badge);
        return el('div', { className: 'panel-section' }, [head, body]);
    }

    function orderButton(o, meta) {
        const btn = el('button', { type: 'button', className: 'order' }, [
            el('span', { className: 'order-no' }, o.order_number),
            el('span', { className: 'status status-' + o.status }, o.status),
            el('span', { className: 'order-meta' }, meta),
            el('span', { className: 'order-total' }, euro(o.total_eur)),
        ]);
        if (o.items && o.items.length) {
            btn.appendChild(el('span', { className: 'order-items' }, o.items.map((i) => i.quantity + ' × ' + i.name).join(', ')));
        }
        btn.addEventListener('click', () => askAbout('What is the status of order ' + o.order_number + '?'));
        return el('li', {}, btn);
    }

    function recCard(item) {
        const p = item.product;
        const card = el('div', { className: 'rec' }, [
            swatch(p),
            el('span', { className: 'rec-name' }, p.name),
            el('span', { className: 'rec-price' }, euro(p.price_eur)),
            el('span', { className: 'rec-reason' }, item.reason),
            el('span', { className: 'rec-match' }, item.match_percent === null ? '' : item.match_percent + '% match'),
        ]);
        return card;
    }

    async function renderSidePanel() {
        const panel = els.sidePanel;
        panel.innerHTML = '';

        if (!customer) {
            const signInBtn = el('button', { type: 'button', className: 'btn-primary' }, 'Sign in');
            signInBtn.addEventListener('click', openSignIn);
            panel.appendChild(el('div', { className: 'signin-card' }, [
                el('p', {}, 'Sign in as a customer to see your orders and personal product recommendations.'),
                signInBtn,
            ]));

            const list = el('ul', { className: 'orders' }, el('li', { className: 'orders-empty' }, 'Loading…'));
            panel.appendChild(section('Orders in the database', null, list));
            try {
                const data = await api('/api/orders');
                list.innerHTML = '';
                data.orders.forEach((o) => list.appendChild(orderButton(o, o.customer_name + ' · ' + o.ordered_at)));
            } catch (e) {
                list.innerHTML = '';
                list.appendChild(el('li', { className: 'orders-empty' }, 'Could not load orders: ' + e.message));
            }
            return;
        }

        panel.appendChild(el('div', { className: 'panel-section' }, [
            el('p', { className: 'greeting' }, 'Hi ' + customer.name.split(' ')[0] + '!'),
            el('p', { className: 'panel-hint' }, customer.email + ' · ' + customer.city),
        ]));

        const recBody = el('div', { className: 'rec-list' }, el('p', { className: 'orders-empty' }, 'Loading…'));
        const recSection = section('Recommended for you', null, recBody);
        const orderList = el('ul', { className: 'orders' }, el('li', { className: 'orders-empty' }, 'Loading…'));
        panel.append(recSection, section('Your orders', null, orderList));

        const [recs, orders] = await Promise.allSettled([api('/api/me/recommendations'), api('/api/me/orders')]);

        recBody.innerHTML = '';
        if (recs.status === 'fulfilled') {
            recSection.querySelector('.panel-head').appendChild(
                el('span', { className: 'method-badge', title: 'How the recommendations were calculated' }, METHOD_LABELS[recs.value.method] || recs.value.method),
            );
            if (!recs.value.items.length) recBody.appendChild(el('p', { className: 'orders-empty' }, 'No recommendations yet.'));
            recs.value.items.forEach((item) => recBody.appendChild(recCard(item)));
            const ask = el('button', { type: 'button', className: 'btn-link' }, 'Ask the assistant why →');
            ask.addEventListener('click', () => sendMessage('What else would go with my orders?'));
            recBody.appendChild(ask);
        } else {
            recBody.appendChild(el('p', { className: 'form-error' }, 'Could not load recommendations: ' + recs.reason.message));
        }

        orderList.innerHTML = '';
        if (orders.status === 'fulfilled') {
            if (!orders.value.orders.length) orderList.appendChild(el('li', { className: 'orders-empty' }, 'No orders yet.'));
            orders.value.orders.forEach((o) => orderList.appendChild(orderButton(o, o.ordered_at)));
        } else {
            orderList.appendChild(el('li', { className: 'form-error' }, 'Could not load orders: ' + orders.reason.message));
        }
    }

    // ---- Products ----

    function showView(view) {
        els.tabs.forEach((t) => {
            const active = t.dataset.view === view;
            t.classList.toggle('is-active', active);
            t.setAttribute('aria-pressed', String(active));
        });
        els.viewAssistant.hidden = view !== 'assistant';
        els.viewProducts.hidden = view !== 'products';
        if (view === 'products' && !productsLoaded) loadProducts();
    }

    async function loadProducts() {
        els.productGrid.innerHTML = '<p class="orders-empty">Loading…</p>';
        try {
            const data = await api('/api/products' + (activeCategory ? '?category=' + encodeURIComponent(activeCategory) : ''));
            productsLoaded = true;
            els.similarityHint.textContent = data.products.length + ' products · similar products by ' +
                (data.similarity === 'embeddings' ? 'AI similarity (embeddings)' : 'rules (category, color, material)');
            renderFilters(data.categories);
            els.productGrid.innerHTML = '';
            data.products.forEach((p) => els.productGrid.appendChild(productCard(p)));
        } catch (e) {
            els.productGrid.innerHTML = '';
            els.productGrid.appendChild(el('p', { className: 'form-error' }, 'Could not load products: ' + e.message));
        }
    }

    function renderFilters(categories) {
        els.filters.innerHTML = '';
        ['', ...categories].forEach((c) => {
            const chip = el('button', { type: 'button', className: 'chip' + (c === activeCategory ? ' is-active' : '') }, c || 'All');
            chip.setAttribute('aria-pressed', String(c === activeCategory));
            chip.addEventListener('click', () => { activeCategory = c; loadProducts(); });
            els.filters.appendChild(chip);
        });
    }

    function productCard(p) {
        const visual = el('div', { className: 'product-visual' }, [
            el('span', { className: 'cat' }, p.category),
            el('span', { className: 'stock' + (p.in_stock ? '' : ' out') }, p.in_stock ? p.stock + ' in stock' : 'Sold out'),
        ]);
        visual.style.background = SWATCHES[p.color] || '#e4d9cb';

        const similarBtn = el('button', { type: 'button', className: 'btn-link' }, 'Similar products');
        similarBtn.addEventListener('click', () => openSimilar(p));

        return el('article', { className: 'product-card' }, [
            visual,
            el('div', { className: 'product-body' }, [
                el('span', { className: 'product-name' }, p.name),
                el('span', { className: 'product-meta' }, p.color + ' · ' + p.material),
                el('p', { className: 'product-desc' }, p.description),
                el('div', { className: 'product-foot' }, [
                    el('span', { className: 'product-price' }, euro(p.price_eur)),
                    similarBtn,
                ]),
            ]),
        ]);
    }

    async function openSimilar(product) {
        els.similarTitle.textContent = 'Similar to ' + product.name;
        els.similarSub.textContent = 'Loading…';
        els.similarList.innerHTML = '';
        els.similarDialog.showModal();
        try {
            const data = await api('/api/products/' + encodeURIComponent(product.sku) + '/similar');
            els.similarSub.textContent = 'Method: ' + (METHOD_LABELS[data.method] || data.method) + '. Only products in stock.';
            data.items.forEach((item) => els.similarList.appendChild(recCard(item)));
        } catch (e) {
            els.similarSub.textContent = 'Could not load similar products: ' + e.message;
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
        await startNewChat();
        els.input.focus();
    });

    els.orderLogin.addEventListener('submit', (e) => {
        e.preventDefault();
        const form = new FormData(els.orderLogin);
        signIn({ email: String(form.get('email') || ''), orderNumber: String(form.get('orderNumber') || '') });
    });

    els.tabs.forEach((t) => t.addEventListener('click', () => showView(t.dataset.view)));

    // Close dialogs when clicking the dark backdrop
    [els.signInDialog, els.similarDialog].forEach((d) => d.addEventListener('click', (e) => {
        if (e.target === d) d.close();
    }));

    // ---- Start ----

    (async () => {
        try {
            customer = (await api('/api/session')).customer;
        } catch (e) { customer = null; }
        renderAccount();
        renderSidePanel();
        loadHistory();
    })();
})();
