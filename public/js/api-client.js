(function () {
    function sleep(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }

    function getToken() {
        if (typeof window.getCsrfToken === 'function') {
            return window.getCsrfToken();
        }

        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    function normalizeHeaders(inputHeaders) {
        if (inputHeaders instanceof Headers) {
            const result = {};
            inputHeaders.forEach((value, key) => {
                result[key] = value;
            });
            return result;
        }

        return inputHeaders ? { ...inputHeaders } : {};
    }

    async function apiFetch(url, options = {}) {
        const {
            retries = 2,
            retryDelayMs = 1000,
            retryOn = [429, 503],
            ...rest
        } = options;

        const request = {
            credentials: 'include',
            ...rest,
            headers: normalizeHeaders(rest.headers)
        };

        const method = (request.method || 'GET').toUpperCase();
        const isWriteMethod = ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method);

        if (isWriteMethod && !request.headers['X-CSRF-TOKEN'] && !request.headers['X-CSRF-Token'] && !request.headers['x-csrf-token']) {
            request.headers['X-CSRF-TOKEN'] = getToken();
        }

        let attempt = 0;
        while (true) {
            const response = await window.fetch(url, request);

            if (response.status === 401) {
                const error = new Error('Sessão expirada. Faça login novamente.');
                error.code = 'AUTH_EXPIRED';
                error.response = response;
                throw error;
            }

            if (!retryOn.includes(response.status) || attempt >= retries) {
                return response;
            }

            const delay = retryDelayMs * Math.pow(2, attempt);
            await sleep(delay);
            attempt += 1;
        }
    }

    async function apiJson(url, options = {}) {
        const response = await apiFetch(url, options);
        const data = await response.json().catch(() => null);
        return { response, data };
    }

    async function apiRequest(url, options = {}) {
        const response = await apiFetch(url, options);
        if (!response.ok) {
            const error = new Error(`HTTP ${response.status}`);
            error.response = response;
            throw error;
        }
        return response.json().catch(() => null);
    }

    window.ApiClient = {
        fetch: apiFetch,
        json: apiJson,
        request: apiRequest
    };
})();

// Chrome shell fixes (legacy layouts/app.php). No debug sinks.
(function () {
    const boot = () => {
        const sidebar = document.getElementById('sidebar');
        const brand = sidebar ? sidebar.querySelector('.sidebar-brand') : null;
        const isLegacyChrome = !!(
            sidebar
            && document.querySelector('header.top-navbar')
            && !sidebar.querySelector('.sidebar-user')
            && !sidebar.querySelector('a.brand-link')
        );

        if (
            isLegacyChrome
            && (location.pathname === '/dashboard/pricing' || location.pathname === '/dashboard/precificador')
        ) {
            location.replace('/dashboard/pricing-v2' + location.search + location.hash);
            return;
        }

        if (location.pathname.indexOf('/dashboard/pricing-v2') === 0) {
            const crumb = document.querySelector('.breadcrumb-nav .current');
            if (crumb && /pricing\s*v2/i.test(crumb.textContent || '')) {
                crumb.textContent = 'Precificador';
            }
            if (/pricing\s*v2/i.test(document.title || '')) {
                document.title = (document.title || '').replace(/pricing\s*v2/ig, 'Precificador');
            }
        }

        if (isLegacyChrome && brand && !brand.querySelector('a')) {
            const anchor = document.createElement('a');
            anchor.href = '/dashboard';
            anchor.setAttribute('aria-label', 'ML Manager — Dashboard');
            anchor.style.cssText = 'display:flex;align-items:center;gap:inherit;color:inherit;text-decoration:none;cursor:pointer;width:100%;height:100%';
            while (brand.firstChild) {
                anchor.appendChild(brand.firstChild);
            }
            brand.appendChild(anchor);
            brand.style.setProperty('cursor', 'pointer', 'important');
            brand.removeAttribute('role');
            brand.removeAttribute('tabindex');
        }

        const navEl = sidebar
            ? (sidebar.querySelector('.sidebar-nav') || sidebar.querySelector('nav'))
            : null;
        if (isLegacyChrome && sidebar && navEl) {
            sidebar.style.setProperty('overflow', 'hidden', 'important');
            sidebar.style.setProperty('display', 'flex', 'important');
            sidebar.style.setProperty('flex-direction', 'column', 'important');
            if (brand) {
                brand.style.setProperty('flex', '0 0 auto', 'important');
                brand.style.setProperty('position', 'relative', 'important');
                brand.style.setProperty('z-index', '2', 'important');
            }
            navEl.style.setProperty('flex', '1 1 auto', 'important');
            navEl.style.setProperty('overflow-y', 'auto', 'important');
            navEl.style.setProperty('min-height', '0', 'important');
        }

        const activeNav = sidebar
            ? sidebar.querySelector('a.nav-link.active, a.nav-item.active')
            : null;
        if (activeNav && typeof activeNav.scrollIntoView === 'function') {
            if (isLegacyChrome || (navEl && getComputedStyle(navEl).overflowY === 'auto')) {
                activeNav.scrollIntoView({ block: 'center', inline: 'nearest' });
            }
        }

        document.addEventListener('click', (e) => {
            const el = e.target && e.target.closest ? e.target.closest('[onclick]') : null;
            if (!el) return;
            const attr = el.getAttribute('onclick') || '';
            const m = attr.match(/markAsRead\s*\(\s*(\d+)\s*\)/);
            if (!m) return;
            e.preventDefault();
            e.stopPropagation();
            const id = Number(m[1]);
            const fnOk = typeof window.markAsRead === 'function';
            const title = (el.querySelector('.fw-semibold')?.textContent || el.textContent || '').trim();
            const orderMatch = title.match(/#(\d{10,})/);
            const after = async () => {
                await refreshNotificationBadge();
                if (orderMatch) {
                    window.location.href = '/dashboard/orders?order=' + encodeURIComponent(orderMatch[1]);
                }
            };
            if (fnOk) {
                Promise.resolve(window.markAsRead(id)).then(after).catch(after);
            } else {
                after();
            }
        }, true);

        function updateNotificationBadge(count) {
            const bellBtn = document.querySelector('header.top-navbar button[data-bs-toggle="dropdown"]');
            if (!bellBtn) return;
            let badge = bellBtn.querySelector('.notification-badge');
            const n = Math.max(0, Number(count) || 0);
            if (n <= 0) {
                if (badge) badge.remove();
                return;
            }
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'notification-badge';
                bellBtn.appendChild(badge);
            }
            badge.textContent = n > 9 ? '9+' : String(n);
        }

        async function refreshNotificationBadge() {
            try {
                if (typeof window.requestJson !== 'function') return null;
                const data = await window.requestJson('/api/notifications?limit=5');
                const unread = data && typeof data.unread_count === 'number' ? data.unread_count : null;
                if (unread !== null) {
                    updateNotificationBadge(unread);
                }
                return unread;
            } catch (err) {
                return null;
            }
        }

        if (typeof window.markAsRead === 'function' && !window.markAsRead.__badgePatched) {
            const origMark = window.markAsRead.bind(window);
            window.markAsRead = async function patchedMarkAsRead(id) {
                const out = await origMark(id);
                await refreshNotificationBadge();
                if (typeof window.loadNotifications === 'function') {
                    try { await window.loadNotifications(); } catch (_) { /* ignore */ }
                }
                return out;
            };
            window.markAsRead.__badgePatched = true;
        }

        refreshNotificationBadge();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();


