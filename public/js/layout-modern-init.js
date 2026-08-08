'use strict';

if (typeof window.Chart === 'undefined') {
    (function () {
        const script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.7/chart.umd.min.js';
        document.head.appendChild(script);
    })();
}

if (typeof window.requestJson !== 'function') {
    window.requestJson = async function requestJson(url, options = {}) {
        if (window.ApiClient) {
            return window.ApiClient.request(url, options);
        }

        const response = await fetch(url, {
            credentials: 'include',
            ...options,
        });

        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }

        return response.json();
    };
}

window.Toast = {
    notify: function (message, type = 'info') {
        const container = document.querySelector('.toast-container');
        if (!container || !window.bootstrap || !bootstrap.Toast) {
            return;
        }

        const id = 'toast_' + Date.now();
        const config = {
            success: { icon: 'bi-check-circle-fill', bg: 'bg-success', title: 'Sucesso' },
            error: { icon: 'bi-x-circle-fill', bg: 'bg-danger', title: 'Erro' },
            warning: { icon: 'bi-exclamation-triangle-fill', bg: 'bg-warning', title: 'Atenção' },
            info: { icon: 'bi-info-circle-fill', bg: 'bg-primary', title: 'Info' },
        }[type] || { icon: 'bi-info-circle-fill', bg: 'bg-primary', title: 'Info' };

        const html = `
        <div id="${id}" class="toast align-items-center text-white ${config.bg} border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body d-flex align-items-center gap-2">
                    <i class="bi ${config.icon}"></i>
                    <span>${message}</span>
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    `;

        container.insertAdjacentHTML('beforeend', html);
        const toastEl = document.getElementById(id);
        const toast = new bootstrap.Toast(toastEl, { delay: 4000 });
        toast.show();
        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    },
    success: function (msg) { window.Toast.notify(msg, 'success'); },
    error: function (msg) { window.Toast.notify(msg, 'error'); },
    warning: function (msg) { window.Toast.notify(msg, 'warning'); },
    info: function (msg) { window.Toast.notify(msg, 'info'); },
};

window.Loading = {
    show: function (text = 'Carregando...') {
        const loader = document.getElementById('pageLoader');
        const loaderText = loader ? loader.querySelector('.loader-text') : null;
        if (loaderText) {
            loaderText.textContent = text;
        }
        if (loader) {
            loader.classList.add('active');
        }
    },

    hide: function () {
        const loader = document.getElementById('pageLoader');
        if (loader) {
            loader.classList.remove('active');
        }
    },

    bar: function (show = true) {
        const bar = document.getElementById('loadingBar');
        if (!bar) {
            return;
        }

        if (show) {
            bar.classList.add('active');
        } else {
            bar.classList.remove('active');
            bar.style.width = '0';
        }
    },

    progress: function (percent) {
        const bar = document.getElementById('loadingBar');
        if (!bar) {
            return;
        }

        bar.classList.remove('active');
        bar.style.width = percent + '%';
    },

    button: function (btn, loading = true) {
        const element = typeof btn === 'string' ? document.querySelector(btn) : btn;
        if (!element) {
            return;
        }

        if (loading) {
            element.classList.add('loading');
            element.disabled = true;
        } else {
            element.classList.remove('loading');
            element.disabled = false;
        }
    },

    card: function (card, loading = true) {
        const element = typeof card === 'string' ? document.querySelector(card) : card;
        if (!element) {
            return;
        }

        if (loading) {
            element.classList.add('card-loading');
        } else {
            element.classList.remove('card-loading');
        }
    },

    inline: function () {
        return '<span class="inline-loader"><span class="dot"></span><span class="dot"></span><span class="dot"></span></span>';
    },
};

function showFlashMessagesFromData() {
    const flashData = document.getElementById('flash-messages-data');
    if (!flashData) {
        return;
    }

    const raw = flashData.getAttribute('data-flash');
    if (!raw) {
        return;
    }

    let messages;
    try {
        messages = JSON.parse(raw);
    } catch (error) {
        return;
    }

    if (!Array.isArray(messages)) {
        return;
    }

    messages.forEach((message) => {
        const text = (message && typeof message.message === 'string') ? message.message : '';
        if (!text) {
            return;
        }

        const type = (message.type === 'danger') ? 'error' : (message.type || 'info');
        const notifier = window.Toast[type] || window.Toast.info;
        notifier(text);
    });
}

function setTheme(theme) {
    document.body.setAttribute('data-theme', theme);
    document.cookie = 'theme=' + theme + ';path=/;max-age=31536000';

    const light = document.getElementById('themeLight');
    const dark = document.getElementById('themeDark');
    if (light) {
        light.classList.toggle('active', theme === 'light');
    }
    if (dark) {
        dark.classList.toggle('active', theme === 'dark');
    }
}

async function switchAccount(accountId) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    try {
        window.Loading.bar(true);
        const data = await window.requestJson('/api/dashboard/switch-account', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
            },
            body: JSON.stringify({
                account_id: accountId,
            }),
        });

        if (data.success) {
            window.location.reload();
            return;
        }

        window.Loading.bar(false);
        if (window.Toast) {
            window.Toast.error(data.message || data.error || 'Erro ao trocar de conta');
        }
    } catch (error) {
        window.Loading.bar(false);
        if (window.Toast) {
            window.Toast.error('Erro de conexão ao trocar conta');
        }
    }
}

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('themeLight')?.addEventListener('click', () => setTheme('light'));
    document.getElementById('themeDark')?.addEventListener('click', () => setTheme('dark'));

    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const sidebarClose = document.getElementById('sidebarClose');

    sidebarToggle?.addEventListener('click', () => {
        sidebar?.classList.toggle('active');
        overlay?.classList.toggle('active');
    });

    sidebarClose?.addEventListener('click', () => {
        sidebar?.classList.remove('active');
        overlay?.classList.remove('active');
        sidebar?.classList.remove('open');
    });

    overlay?.addEventListener('click', () => {
        sidebar?.classList.remove('active');
        overlay?.classList.remove('active');
        sidebar?.classList.remove('open');
    });

    document.addEventListener('keydown', (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key === 'k') {
            event.preventDefault();
            document.getElementById('sidebarSearch')?.focus();
        }
    });

    document.getElementById('globalSearch')?.addEventListener('click', () => {
        document.getElementById('sidebarSearch')?.focus();
    });

    const searchInput = document.getElementById('sidebarSearch');
    searchInput?.addEventListener('input', (event) => {
        const query = String(event.target?.value || '').toLowerCase();
        document.querySelectorAll('.nav-item').forEach((item) => {
            const text = item.textContent.toLowerCase();
            const section = item.closest('.nav-section');

            if (query === '' || text.includes(query)) {
                item.style.display = '';
                if (section) {
                    section.style.display = '';
                }
                return;
            }

            item.style.display = 'none';
        });
    });

    document.querySelectorAll('.nav-section.collapsible .nav-section-title').forEach((title) => {
        title.addEventListener('click', () => {
            title.closest('.nav-section')?.classList.toggle('collapsed');
        });
    });

    document.getElementById('mobileMenuBtn')?.addEventListener('click', () => {
        sidebar?.classList.toggle('open');
    });

    document.querySelectorAll('.sidebar a').forEach((link) => {
        link.addEventListener('click', () => {
            if (window.innerWidth < 768) {
                sidebar?.classList.remove('open');
                sidebar?.classList.remove('active');
                overlay?.classList.remove('active');
            }
        });
    });

    showFlashMessagesFromData();

    // PATCH 6/8: enhancements do DRE/caixa do ledger (financials.php é root-owned)
    if (/^\/dashboard\/financials\/?$/.test(window.location.pathname)) {
        const s = document.createElement('script');
        s.src = '/js/financial-ledger-enhancements.js?v=' + Date.now();
        s.defer = true;
        document.head.appendChild(s);
    }
});

document.addEventListener('submit', function (event) {
    const form = event.target;
    const submitBtn = form.querySelector('[type="submit"]');
    if (submitBtn && !submitBtn.classList.contains('no-loading')) {
        window.Loading.button(submitBtn, true);
    }
});

document.addEventListener('click', function (event) {
    const link = event.target.closest('a[href]:not([target="_blank"]):not([href^="#"]):not([href^="javascript"])');
    if (link && !link.classList.contains('no-loading')) {
        window.Loading.bar(true);
    }
});

document.addEventListener('click', function (event) {
    const button = event.target.closest('[data-switch-account-id]');
    if (!button || button.disabled) {
        return;
    }

    event.preventDefault();
    const accountId = Number(button.getAttribute('data-switch-account-id'));
    if (Number.isInteger(accountId) && accountId > 0) {
        switchAccount(accountId);
    }
});
