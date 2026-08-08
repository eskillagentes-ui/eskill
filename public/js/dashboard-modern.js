/**
 * Modern Dashboard Logic
 */

// ── Mercado Livre URL Utilities ─────────────────────────────────────
// Centraliza construção de URLs de itens/anúncios do ML Brasil.
// Regras:
//   - Item IDs (ex: MLB1234567890) usam domínio produto.mercadolivre.com.br
//   - Dash obrigatório entre prefixo de site (3 letras) e parte numérica
//   - /p/ em www é para fichas de catálogo, NÃO para anúncios
window.ML = {
    ITEM_BASE_URL: 'https://produto.mercadolivre.com.br',

    /**
     * Formata ML item ID inserindo dash: MLB1234567890 → MLB-1234567890
     * Se já formatado (MLB-123), retorna inalterado.
     */
    formatItemId: function (id) {
        var s = String(id || '').trim();
        if (/^[A-Z]{3}-\d+$/.test(s)) return s;
        var m = s.match(/^([A-Z]{3})(\d+)$/);
        return m ? m[1] + '-' + m[2] : s;
    },

    /**
     * Constrói URL completa de anúncio ML.
     * @param {string} id  Item ID (com ou sem dash)
     * @returns {string}   https://produto.mercadolivre.com.br/MLB-1234567890
     */
    itemUrl: function (id) {
        return this.ITEM_BASE_URL + '/' + this.formatItemId(id);
    }
};

// requestJson is defined globally in <head> via the layout

console.log('=== dashboard-modern.js carregado ===');

document.addEventListener('DOMContentLoaded', () => {
    console.log('=== DOMContentLoaded em dashboard-modern.js ===');
    // Sidebar Toggle
    const sidebar = document.querySelector('.sidebar');
    const mainContent = document.querySelector('.main-content');
    const toggleBtn = document.querySelector('#sidebarToggle');
    const sidebarToggleBtn = document.querySelector('.sidebar-toggle'); // Mobile toggle button inside sidebar

    if (toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            // Check if we're on mobile or desktop
            if (window.innerWidth < 768) {
                // On mobile, use the open/close behavior
                sidebar.classList.toggle('open');

                // Update button icon based on state
                const icon = toggleBtn.querySelector('i');
                if (sidebar.classList.contains('open')) {
                    icon.classList.remove('bi-list');
                    icon.classList.add('bi-x-lg');
                } else {
                    icon.classList.remove('bi-x-lg');
                    icon.classList.add('bi-list');
                }
            } else {
                // On desktop, use the collapse/expand behavior
                sidebar.classList.toggle('collapsed');

                // Update main content margin based on sidebar state
                if (sidebar.classList.contains('collapsed')) {
                    mainContent.style.marginLeft = '60px';
                    // Update button icon to indicate collapsed state
                    const icon = toggleBtn.querySelector('i');
                    icon.classList.remove('bi-list');
                    icon.classList.add('bi-chevron-right');
                } else {
                    // Get the CSS variable value
                    const navWidth = getComputedStyle(document.documentElement).getPropertyValue('--nav-width').trim();
                    mainContent.style.marginLeft = navWidth || '260px';
                    // Update button icon to indicate expanded state
                    const icon = toggleBtn.querySelector('i');
                    icon.classList.remove('bi-chevron-right');
                    icon.classList.add('bi-list');
                }
            }
        });
    }

    // Mobile sidebar toggle button (inside sidebar)
    if (sidebarToggleBtn) {
        sidebarToggleBtn.addEventListener('click', function () {
            sidebar.classList.toggle('open');

            // Change icon based on state
            const icon = this.querySelector('i');
            if (sidebar.classList.contains('open')) {
                icon.classList.remove('bi-list');
                icon.classList.add('bi-x-lg');
            } else {
                icon.classList.remove('bi-x-lg');
                icon.classList.add('bi-list');
            }
        });
    }

    // Dropdowns (if any)
    const dropdowns = document.querySelectorAll('.dropdown-toggle');
    dropdowns.forEach(dropdown => {
        dropdown.addEventListener('click', (e) => {
            e.preventDefault();
            const menu = dropdown.nextElementSibling;
            menu.classList.toggle('show');
        });
    });

    // Close sidebar on outside click (mobile)
    document.addEventListener('click', (e) => {
        if (window.innerWidth < 768) {
            if (!sidebar.contains(e.target) && !toggleBtn.contains(e.target) && !sidebarToggleBtn.contains(e.target) && sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
            }
        }
    });

    // Handle window resize to adjust behavior between mobile and desktop
    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            if (window.innerWidth >= 769) {
                // On desktop, remove open class and ensure proper state
                sidebar.classList.remove('open');
            } else {
                // On mobile, remove collapsed class
                sidebar.classList.remove('collapsed');
                // Reset main content margin to default
                const navWidth = getComputedStyle(document.documentElement).getPropertyValue('--nav-width').trim();
                mainContent.style.marginLeft = navWidth || '260px';
            }
        }, 250); // Debounce resize event
    });

    // Initialize Tooltips (using Bootstrap if available, or custom)
    // Assuming Bootstrap 5 is still loaded for utility classes/JS
    if (typeof bootstrap !== 'undefined') {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }

    // Sidebar search functionality
    const searchInput = document.querySelector('.search-input');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const searchTerm = this.value.toLowerCase();
            const navItems = document.querySelectorAll('.nav-item');

            navItems.forEach(function (item) {
                const link = item.querySelector('.nav-link');
                if (link) {
                    const linkText = link.textContent.toLowerCase();
                    if (linkText.includes(searchTerm) || searchTerm === '') {
                        item.style.display = 'block';
                    } else {
                        item.style.display = 'none';
                    }
                }
            });
        });

        // Add keyboard navigation for search results
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                this.value = '';
                const navItems = document.querySelectorAll('.nav-item');
                navItems.forEach(item => item.style.display = 'block');

                // Announce that search was cleared
                const announcer = document.getElementById('sidebar-announcer');
                if (announcer) {
                    announcer.textContent = 'Busca limpa, todos os itens do menu exibidos';
                }
            }
        });
    }

    // Enhanced search functionality with backend suggestions
    let searchTimeout;
    let currentSuggestionIndex = -1;
    let suggestionData = [];

    const searchInputEnhanced = document.querySelector('.search-input');
    const searchSuggestions = document.getElementById('search-suggestions');

    if (searchInputEnhanced) {
        // Handle input for search suggestions
        searchInputEnhanced.addEventListener('input', function (e) {
            const query = this.value.trim();

            // Clear previous timeout
            clearTimeout(searchTimeout);

            if (query.length >= 2) {
                // Debounce search requests
                searchTimeout = setTimeout(() => {
                    fetchSearchSuggestions(query);
                }, 300);
            } else {
                // Hide suggestions if query is too short
                if (searchSuggestions) {
                    searchSuggestions.style.display = 'none';
                }
                currentSuggestionIndex = -1;

                // Show all items if search is cleared
                if (query === '') {
                    const navItems = document.querySelectorAll('.nav-item');
                    navItems.forEach(item => item.style.display = 'block');
                }
            }
        });

        // Handle keyboard navigation for suggestions
        searchInputEnhanced.addEventListener('keydown', function (e) {
            if (!searchSuggestions || !searchSuggestions.style.display || searchSuggestions.style.display === 'none') {
                return;
            }

            const suggestionItems = searchSuggestions.querySelectorAll('.search-suggestion-item');

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    currentSuggestionIndex = Math.min(currentSuggestionIndex + 1, suggestionItems.length - 1);
                    updateSuggestionHighlight(suggestionItems);
                    break;

                case 'ArrowUp':
                    e.preventDefault();
                    currentSuggestionIndex = Math.max(currentSuggestionIndex - 1, -1);
                    updateSuggestionHighlight(suggestionItems);
                    break;

                case 'Enter':
                    e.preventDefault();
                    if (currentSuggestionIndex >= 0 && suggestionItems[currentSuggestionIndex]) {
                        suggestionItems[currentSuggestionIndex].click();
                    } else {
                        // If no suggestion selected, perform default search
                        performLocalSearch(this.value);
                    }
                    break;

                case 'Escape':
                    if (searchSuggestions) {
                        searchSuggestions.style.display = 'none';
                    }
                    currentSuggestionIndex = -1;
                    break;
            }
        });

        // Hide suggestions when clicking elsewhere
        document.addEventListener('click', function (e) {
            if (!searchInputEnhanced.contains(e.target) && (!searchSuggestions || !searchSuggestions.contains(e.target))) {
                if (searchSuggestions) {
                    searchSuggestions.style.display = 'none';
                }
                currentSuggestionIndex = -1;
            }
        });

        // Add keyboard navigation for search results
        searchInputEnhanced.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                this.value = '';
                const navItems = document.querySelectorAll('.nav-item');
                navItems.forEach(item => item.style.display = 'block');

                // Hide suggestions
                if (searchSuggestions) {
                    searchSuggestions.style.display = 'none';
                }

                // Announce that search was cleared
                const announcer = document.getElementById('sidebar-announcer');
                if (announcer) {
                    announcer.textContent = 'Busca limpa, todos os itens do menu exibidos';
                }
            }
        });
    }

    function fetchSearchSuggestions(query) {
        // Real API call for search suggestions
        requestJson(`/api/dashboard/search-suggestions?q=${encodeURIComponent(query)}`, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(data => {
                if (data.results && data.results.length > 0) {
                    displaySearchSuggestions(data.results);
                } else {
                    // Fallback to local search if no API results
                    performLocalSearch(query);
                }
            })
            .catch(error => {
                console.error('Error fetching search suggestions:', error);
                // Fallback to local search on error
                performLocalSearch(query);
            });
    }

    function displaySearchSuggestions(results) {
        if (!searchSuggestions) return;

        if (results.length === 0) {
            searchSuggestions.innerHTML = '<div class="search-suggestion-item">Nenhum resultado encontrado</div>';
            searchSuggestions.style.display = 'block';
            return;
        }

        // Store the suggestion data
        suggestionData = results;

        // Generate HTML for suggestions
        searchSuggestions.innerHTML = results.map((item, index) => `
            <div class="search-suggestion-item" data-index="${index}" data-url="${item.url}">
                <i class="bi bi-${item.icon}"></i>
                <div class="search-suggestion-text">
                    <div class="fw-bold">${highlightMatch(item.title, searchInput.value)}</div>
                    <small class="text-muted">${item.category}</small>
                </div>
                <span class="search-suggestion-hotkey">Enter</span>
            </div>
        `).join('');

        // Add event listeners to suggestion items
        searchSuggestions.querySelectorAll('.search-suggestion-item').forEach((item, index) => {
            item.addEventListener('click', function () {
                const url = this.getAttribute('data-url');
                if (url) {
                    window.location.href = url;
                }
            });
        });

        searchSuggestions.style.display = 'block';
        currentSuggestionIndex = -1;
    }

    function highlightMatch(text, query) {
        if (!query) return text;

        const regex = new RegExp(`(${escapeRegExp(query)})`, 'gi');
        return text.replace(regex, '<mark class="text-bg-primary">$1</mark>');
    }

    function escapeRegExp(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function updateSuggestionHighlight(suggestionItems) {
        // Remove highlight from all items
        suggestionItems.forEach(item => item.classList.remove('highlighted'));

        // Add highlight to current item
        if (currentSuggestionIndex >= 0 && suggestionItems[currentSuggestionIndex]) {
            suggestionItems[currentSuggestionIndex].classList.add('highlighted');
            // Scroll to highlighted item
            suggestionItems[currentSuggestionIndex].scrollIntoView({ block: 'nearest' });
        }
    }

    function performLocalSearch(query) {
        // Perform the original local search functionality
        const navItems = document.querySelectorAll('.nav-item');
        let visibleCount = 0;

        navItems.forEach(function (item) {
            const link = item.querySelector('.nav-link');
            if (link) {
                const linkText = link.textContent.toLowerCase();
                if (linkText.includes(query.toLowerCase()) || query === '') {
                    item.style.display = 'block';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            }
        });

        // Announce search results to screen readers
        const announcer = document.getElementById('sidebar-announcer');
        if (announcer) {
            if (query) {
                announcer.textContent = `Mostrando ${visibleCount} de ${navItems.length} itens`;
            } else {
                announcer.textContent = 'Todos os itens do menu exibidos';
            }
        }
    }

    // Keyboard navigation for sidebar links
    const navLinks = document.querySelectorAll('.nav-link');
    navLinks.forEach((link, index) => {
        link.setAttribute('tabindex', '0');

        link.addEventListener('keydown', function (e) {
            let nextElement;

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    nextElement = navLinks[index + 1];
                    if (nextElement) nextElement.focus();
                    break;

                case 'ArrowUp':
                    e.preventDefault();
                    nextElement = navLinks[index - 1];
                    if (nextElement) nextElement.focus();
                    break;

                case 'Enter':
                case ' ':
                    e.preventDefault();
                    this.click();
                    break;
            }
        });
    });

    // Focus management for collapsible sections
    const collapsibleTriggers = document.querySelectorAll('[data-bs-toggle="collapse"]');
    collapsibleTriggers.forEach(trigger => {
        trigger.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.click();
            }
        });
    });

    // Dropdown focus management
    const dropdownsFocus = document.querySelectorAll('.dropdown-toggle');
    dropdownsFocus.forEach((dropdown, index) => {
        dropdown.addEventListener('keydown', function (e) {
            let nextElement;

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    const menu = this.nextElementSibling;
                    if (menu && menu.classList.contains('show')) {
                        const firstItem = menu.querySelector('.dropdown-item');
                        if (firstItem) firstItem.focus();
                    }
                    break;
            }
        });
    });

    // Online/Offline detection
    function updateConnectionStatus() {
        const indicator = document.getElementById('connection-indicator');
        const connectionText = document.getElementById('connection-text');

        if (indicator && connectionText) {
            if (navigator.onLine) {
                indicator.innerHTML = '<i class="bi bi-wifi text-success" aria-hidden="true"></i> <span id="connection-text">Online</span>';
            } else {
                indicator.innerHTML = '<i class="bi bi-wifi-off text-danger" aria-hidden="true"></i> <span id="connection-text">Offline</span>';
            }
        }
    }

    // Initial check
    updateConnectionStatus();

    // Listen for online/offline events
    window.addEventListener('online', updateConnectionStatus);
    window.addEventListener('offline', updateConnectionStatus);

    // Translation functionality
    window.changeLanguage = function (lang) {
        // Store selected language in localStorage
        localStorage.setItem('selectedLanguage', lang);

        // Update the displayed language
        const currentLangSpan = document.getElementById('current-lang');
        if (currentLangSpan) {
            switch (lang) {
                case 'pt':
                    currentLangSpan.textContent = 'Português';
                    break;
                case 'en':
                    currentLangSpan.textContent = 'English';
                    break;
                case 'es':
                    currentLangSpan.textContent = 'Español';
                    break;
                default:
                    currentLangSpan.textContent = 'Português';
            }
        }

        // Trigger a custom event for other parts of the app to handle
        const langChangeEvent = new CustomEvent('languageChanged', { detail: { language: lang } });
        document.dispatchEvent(langChangeEvent);

        // Show a temporary message
        const announcer = document.getElementById('sidebar-announcer');
        if (announcer) {
            announcer.textContent = 'Idioma alterado para ' + (lang === 'en' ? 'Inglês' : lang === 'es' ? 'Espanhol' : 'Português');
            setTimeout(() => {
                announcer.textContent = '';
            }, 3000);
        }
    };

    // Load saved language preference
    document.addEventListener('DOMContentLoaded', function () {
        const savedLang = localStorage.getItem('selectedLanguage');
        if (savedLang) {
            changeLanguage(savedLang);
        }
    });

    // Function to show keyboard shortcuts modal
    window.showKeyboardShortcuts = function () {
        // Create modal instance and show it
        const modalElement = document.getElementById('keyboardShortcutsModal');
        if (modalElement && typeof bootstrap !== 'undefined') {
            const modal = new bootstrap.Modal(modalElement);
            modal.show();
        } else {
            // Fallback: alert with shortcuts
            alert('Atalhos de Teclado:\n\nCtrl+K: Buscar funcionalidades\nCtrl+Alt+H: Ajuda\nCtrl+Alt+T: Alternar tema\nCtrl+Alt+L: Alternar idioma\n?: Mostrar este painel');
        }
    };

    // Add keyboard shortcut listener for '?'
    document.addEventListener('keydown', function (e) {
        if ((e.key === '?' || e.key === '/') && e.ctrlKey === false && e.altKey === false) {
            e.preventDefault();
            showKeyboardShortcuts();
        }
    });

    // Session timeout warning functionality
    function setupSessionTimeoutWarning() {
        // This would normally come from server configuration
        const sessionTimeout = 30 * 60 * 1000; // 30 minutes in milliseconds
        const warningTime = 5 * 60 * 1000; // 5 minutes before timeout

        let warningTimer;
        let countdownInterval;

        function showTimeoutWarning() {
            const warningElement = document.getElementById('session-timeout-warning');
            const countdownElement = document.getElementById('timeout-countdown');

            if (warningElement && countdownElement) {
                warningElement.style.display = 'block';

                let secondsLeft = 5 * 60; // 5 minutes

                countdownInterval = setInterval(() => {
                    const minutes = Math.floor(secondsLeft / 60);
                    const seconds = secondsLeft % 60;

                    countdownElement.textContent = `${minutes}:${seconds < 10 ? '0' : ''}${seconds}`;

                    secondsLeft--;

                    if (secondsLeft < 0) {
                        clearInterval(countdownInterval);
                        // Redirect to logout or show modal
                        alert('Sua sessão expirou. Faça login novamente.');
                        window.location.href = '/auth/login';
                    }
                }, 1000);
            }
        }

        // Set timer to show warning before session expires
        warningTimer = setTimeout(showTimeoutWarning, sessionTimeout - warningTime);

        // Reset timers on user activity
        function resetTimers() {
            clearTimeout(warningTimer);
            clearInterval(countdownInterval);

            // Hide warning if it was shown
            const warningElement = document.getElementById('session-timeout-warning');
            if (warningElement) {
                warningElement.style.display = 'none';
            }

            // Restart the timeout sequence
            warningTimer = setTimeout(showTimeoutWarning, sessionTimeout - warningTime);
        }

        // Listen for user activity
        ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'].forEach(event => {
            document.addEventListener(event, resetTimers, true);
        });
    }

    // Initialize session timeout warning
    setupSessionTimeoutWarning();

    // Event tracking for navigation clicks
    function trackNavigationEvent(category, action, label) {
        // Send event to analytics (Google Analytics, etc.)
        if (typeof gtag !== 'undefined') {
            gtag('event', action, {
                'event_category': category,
                'event_label': label
            });
        }

        // Log to console for debugging
        console.log(`Navigation Event: ${category} - ${action} - ${label}`);

        // Send to custom analytics endpoint if needed
        // fetch('/api/analytics/track', {
        //     method: 'POST',
        //     headers: {
        //         'Content-Type': 'application/json',
        //     },
        //     body: JSON.stringify({
        //         category: category,
        //         action: action,
        //         label: label,
        //         timestamp: new Date().toISOString()
        //     })
        // }).catch(err => console.error('Analytics tracking failed:', err));
    }

    // Add event listeners to all navigation links
    const navLinksAnalytics = document.querySelectorAll('.nav-link');
    navLinksAnalytics.forEach(link => {
        if (!link.classList.contains('dropdown-toggle')) { // Don't track dropdown toggles
            link.addEventListener('click', function () {
                const href = this.getAttribute('href');
                const text = (this.querySelector('span')?.textContent || this.textContent || '').trim();

                trackNavigationEvent('Sidebar Navigation', 'Click', `${text} (${href})`);
            });
        }
    });

    // Track dropdown toggles separately
    const dropdownTogglesAnalytics = document.querySelectorAll('.dropdown-toggle');
    dropdownTogglesAnalytics.forEach(toggle => {
        toggle.addEventListener('click', function () {
            const text = (this.querySelector('span')?.textContent || this.textContent || '').trim();
            trackNavigationEvent('Sidebar Dropdown', 'Toggle', text);
        });
    });

    // Track search input
    const searchInputAnalytics = document.querySelector('.search-input');
    if (searchInputAnalytics) {
        searchInputAnalytics.addEventListener('input', function () {
            trackNavigationEvent('Sidebar Search', 'Input', 'Search used');
        });

        searchInputAnalytics.addEventListener('keyup', function (e) {
            if (e.key === 'Enter') {
                trackNavigationEvent('Sidebar Search', 'Submit', `Searched: ${this.value}`);
            }
        });
    }

    // Track logout
    const logoutForm = document.getElementById('logout-form');
    if (logoutForm) {
        logoutForm.addEventListener('submit', function () {
            trackNavigationEvent('User Action', 'Logout', 'User logged out');
        });
    }

    // Dynamic menu loading functionality
    async function loadDynamicMenuItems() {
        try {
            // Show loading state with Skeletons
            const dynamicMenuContainer = document.getElementById('dynamic-menu-items');
            if (dynamicMenuContainer) {
                dynamicMenuContainer.innerHTML = Array(3).fill(0).map(() => `
                    <div class="nav-item">
                        <div class="nav-link">
                            <i class="bi bi-circle skeleton skeleton-avatar" style="width: 16px; height: 16px; border-radius: 50%;"></i>
                            <div class="skeleton skeleton-text" style="width: 70%; height: 14px;"></div>
                        </div>
                    </div>
                `).join('');
            }

            // Fetch dynamic menu items from API
            const response = await fetch('/api/menu-items', {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();

            // Render dynamic menu items
            if (dynamicMenuContainer && Array.isArray(data.items)) {
                dynamicMenuContainer.innerHTML = '';

                data.items.forEach(item => {
                    const menuItem = document.createElement('div');
                    menuItem.className = 'nav-item';

                    menuItem.innerHTML = `
                        <a href="${item.url}" class="nav-link" data-permission="${item.permission}">
                            <i class="bi ${item.icon}"></i>
                            <span>${item.title}</span>
                        </a>
                    `;

                    dynamicMenuContainer.appendChild(menuItem);

                    // Add event tracking to the new item
                    menuItem.querySelector('a').addEventListener('click', function () {
                        const href = this.getAttribute('href');
                        const text = this.querySelector('span')?.textContent || this.textContent;

                        trackNavigationEvent('Dynamic Sidebar Navigation', 'Click', `${text} (${href})`);
                    });
                });
            }
        } catch (error) {
            console.error('Error loading dynamic menu items:', error);

            // Hide loading state and show error message
            const dynamicMenuContainer = document.getElementById('dynamic-menu-items');
            if (dynamicMenuContainer) {
                dynamicMenuContainer.innerHTML = `
                    <div class="nav-item">
                        <a href="#" class="nav-link text-muted" onclick="loadDynamicMenuItems(); return false;" title="Clique para tentar novamente">
                            <i class="bi bi-arrow-repeat"></i>
                            <span>Erro ao carregar itens</span>
                        </a>
                    </div>
                `;
            }
        }
    }

    // Load dynamic menu items when DOM is ready
    document.addEventListener('DOMContentLoaded', function () {
        // Only load dynamic menu items if the container exists
        if (document.getElementById('dynamic-menu-items')) {
            loadDynamicMenuItems();
        }
    });

    // Allow manual refresh of dynamic menu
    window.refreshDynamicMenu = function () {
        loadDynamicMenuItems();
    };

    // User preferences functionality
    window.showPreferencesPanel = function () {
        // Create modal instance and show it
        const modalElement = document.getElementById('preferencesModal');
        if (modalElement && typeof bootstrap !== 'undefined') {
            const modal = new bootstrap.Modal(modalElement);

            // Load saved preferences
            loadUserPreferences();

            modal.show();
        } else {
            // Fallback: alert
            alert('Preferências do usuário estão sendo carregadas...');
        }
    };

    function loadUserPreferences() {
        // Load preferences from localStorage
        const autoCollapse = localStorage.getItem('autoCollapseSidebar') === 'true';
        const showNotifications = localStorage.getItem('showNotifications') === 'true';
        const enableAnimations = localStorage.getItem('enableAnimations') === 'true';
        const fontSize = localStorage.getItem('fontSize') || 'normal';
        const menuOrder = localStorage.getItem('menuOrder') || 'alphabetical';
        const highContrastMode = localStorage.getItem('highContrastMode') === 'true';
        const reduceMotion = localStorage.getItem('reduceMotion') === 'true';
        const screenReaderFriendly = localStorage.getItem('screenReaderFriendly') === 'true';
        const focusIndicatorSize = localStorage.getItem('focusIndicatorSize') || 'normal';

        // Set form values
        document.getElementById('autoCollapseSidebar').checked = autoCollapse;
        document.getElementById('showNotifications').checked = showNotifications;
        document.getElementById('enableAnimations').checked = enableAnimations;
        document.getElementById('fontSizeSelect').value = fontSize;
        document.getElementById('menuOrderSelect').value = menuOrder;
        document.getElementById('highContrastMode').checked = highContrastMode;
        document.getElementById('reduceMotion').checked = reduceMotion;
        document.getElementById('screenReaderFriendly').checked = screenReaderFriendly;
        document.getElementById('focusIndicatorSize').value = focusIndicatorSize;
    }

    window.saveUserPreferences = function () {
        // Save preferences to localStorage
        const autoCollapse = document.getElementById('autoCollapseSidebar').checked;
        const showNotifications = document.getElementById('showNotifications').checked;
        const enableAnimations = document.getElementById('enableAnimations').checked;
        const fontSize = document.getElementById('fontSizeSelect').value;
        const menuOrder = document.getElementById('menuOrderSelect').value;
        const highContrastMode = document.getElementById('highContrastMode').checked;
        const reduceMotion = document.getElementById('reduceMotion').checked;
        const screenReaderFriendly = document.getElementById('screenReaderFriendly').checked;
        const focusIndicatorSize = document.getElementById('focusIndicatorSize').value;

        localStorage.setItem('autoCollapseSidebar', autoCollapse);
        localStorage.setItem('showNotifications', showNotifications);
        localStorage.setItem('enableAnimations', enableAnimations);
        localStorage.setItem('fontSize', fontSize);
        localStorage.setItem('menuOrder', menuOrder);
        localStorage.setItem('highContrastMode', highContrastMode);
        localStorage.setItem('reduceMotion', reduceMotion);
        localStorage.setItem('screenReaderFriendly', screenReaderFriendly);
        localStorage.setItem('focusIndicatorSize', focusIndicatorSize);

        // Apply preferences immediately
        applyUserPreferences();

        // Close modal
        const modalElement = document.getElementById('preferencesModal');
        if (modalElement && typeof bootstrap !== 'undefined') {
            const modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) {
                modal.hide();
            }
        }

        // Show confirmation
        const announcer = document.getElementById('sidebar-announcer');
        if (announcer) {
            announcer.textContent = 'Preferências salvas com sucesso!';
            setTimeout(() => {
                announcer.textContent = '';
            }, 3000);
        }
    };

    function applyUserPreferences() {
        // Apply font size preference
        const fontSize = localStorage.getItem('fontSize') || 'normal';
        const sidebar = document.querySelector('.sidebar');

        if (sidebar) {
            sidebar.classList.remove('font-size-normal', 'font-size-large', 'font-size-larger');
            sidebar.classList.add(`font-size-${fontSize}`);
        }

        // Apply animation preference
        const enableAnimations = localStorage.getItem('enableAnimations') === 'true';
        document.body.classList.toggle('disable-animations', !enableAnimations);

        // Apply high contrast mode
        const highContrastMode = localStorage.getItem('highContrastMode') === 'true';
        document.body.classList.toggle('high-contrast-mode', highContrastMode);

        // Apply reduced motion
        const reduceMotion = localStorage.getItem('reduceMotion') === 'true';
        if (reduceMotion) {
            document.body.classList.add('reduce-motion');
            document.documentElement.style.setProperty('--transition-speed', '0.01ms');
        } else {
            document.body.classList.remove('reduce-motion');
            document.documentElement.style.setProperty('--transition-speed', '0.3s'); // Default value
        }

        // Apply screen reader friendly mode
        const screenReaderFriendly = localStorage.getItem('screenReaderFriendly') === 'true';
        document.body.classList.toggle('screen-reader-friendly', screenReaderFriendly);

        // Apply focus indicator size
        const focusIndicatorSize = localStorage.getItem('focusIndicatorSize') || 'normal';
        document.body.classList.remove('focus-size-normal', 'focus-size-large', 'focus-size-larger');
        document.body.classList.add(`focus-size-${focusIndicatorSize}`);

        // Apply other preferences as needed
        console.log('User preferences applied');
    }

    // Initialize preferences when DOM is loaded
    document.addEventListener('DOMContentLoaded', function () {
        loadUserPreferences();
        applyUserPreferences();
    });

    // Real-time notification system
    let notificationSocket = null;
    let notificationRetryCount = 0;
    const MAX_RETRY_COUNT = 5;

    function connectNotificationSocket() {
        // Check if WebSocket is supported
        if (!window.WebSocket) {
            console.warn('WebSocket not supported by browser');
            return;
        }

        try {
            // Use appropriate URL based on protocol
            const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
            const wsUrl = `${protocol}//${window.location.host}/ws/notifications`;

            notificationSocket = new WebSocket(wsUrl);

            notificationSocket.onopen = function (event) {
                console.log('Connected to notification server');
                notificationRetryCount = 0; // Reset retry count on successful connection

                // Send authentication token if available
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
                    document.querySelector('input[name="csrf_token"]')?.value;

                if (csrfToken) {
                    notificationSocket.send(JSON.stringify({
                        type: 'auth',
                        token: csrfToken
                    }));
                }
            };

            notificationSocket.onmessage = function (event) {
                try {
                    const data = JSON.parse(event.data);

                    switch (data.type) {
                        case 'notification':
                            handleNewNotification(data.payload);
                            break;
                        case 'update':
                            handleNotificationUpdate(data.payload);
                            break;
                        case 'count':
                            updateNotificationCount(data.count);
                            break;
                        default:
                            console.log('Unknown message type:', data.type);
                    }
                } catch (e) {
                    console.error('Error parsing notification:', e);
                }
            };

            notificationSocket.onclose = function (event) {
                console.log('Disconnected from notification server:', event.code, event.reason);

                // Attempt to reconnect with exponential backoff
                if (notificationRetryCount < MAX_RETRY_COUNT) {
                    notificationRetryCount++;
                    const delay = Math.pow(2, notificationRetryCount) * 1000; // Exponential backoff

                    console.log(`Attempting to reconnect in ${delay}ms (attempt ${notificationRetryCount}/${MAX_RETRY_COUNT})`);

                    setTimeout(connectNotificationSocket, delay);
                } else {
                    console.error('Max retry attempts reached. Could not reconnect to notification server.');

                    // Show user notification about connection issue
                    const announcer = document.getElementById('sidebar-announcer');
                    if (announcer) {
                        announcer.textContent = 'Conexão com servidor de notificações perdida. Tentando reconectar...';
                        setTimeout(() => {
                            if (announcer.textContent.includes('Conexão com servidor')) {
                                announcer.textContent = '';
                            }
                        }, 5000);
                    }
                }
            };

            notificationSocket.onerror = function (error) {
                console.error('WebSocket error:', error);
            };
        } catch (e) {
            console.error('Error connecting to notification server:', e);
        }
    }

    function handleNewNotification(notification) {
        // Update badge counts
        updateNotificationBadges(notification);

        // Show desktop notification if permissions granted
        showDesktopNotification(notification);

        // Update live region for screen readers
        const announcer = document.getElementById('sidebar-announcer');
        if (announcer) {
            announcer.textContent = `Nova notificação: ${notification.title || notification.message}`;
            setTimeout(() => {
                announcer.textContent = '';
            }, 5000);
        }
    }

    function handleNotificationUpdate(updateData) {
        // Handle notification updates
        console.log('Notification update received:', updateData);
    }

    function updateNotificationCount(count) {
        // Update the global notification count display
        const notificationBell = document.querySelector('.notification-bell');
        if (notificationBell) {
            let badge = notificationBell.querySelector('.notification-badge');
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'notification-badge';
                notificationBell.appendChild(badge);
            }

            if (count > 0) {
                badge.textContent = count;
                badge.style.display = 'inline';
            } else {
                badge.style.display = 'none';
            }
        }
    }

    function updateNotificationBadges(notification) {
        // Update specific notification badges based on notification type
        if (notification.type === 'message') {
            // Update messages badge
            const messageLink = document.querySelector('a[href="/dashboard/messages"]');
            if (messageLink) {
                let badge = messageLink.querySelector('.badge');
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'badge badge-danger';
                    badge.setAttribute('aria-label', `${notification.count} mensagens não lidas`);
                    messageLink.appendChild(badge);
                }
                badge.textContent = notification.count || 1;
            }
        }

        // Add similar logic for other notification types
    }

    function showDesktopNotification(notification) {
        // Request notification permission if not already granted
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification(notification.title || 'Nova Notificação', {
                body: notification.message,
                icon: notification.icon || '/favicon.ico',
                tag: notification.id
            });
        } else if ('Notification' in window && Notification.permission !== 'denied') {
            Notification.requestPermission().then(permission => {
                if (permission === 'granted') {
                    new Notification(notification.title || 'Nova Notificação', {
                        body: notification.message,
                        icon: notification.icon || '/favicon.ico',
                        tag: notification.id
                    });
                }
            });
        }
    }

    // Initialize notification system when DOM is loaded
    document.addEventListener('DOMContentLoaded', function () {
        // Only initialize if user has notifications enabled in preferences
        const showNotifications = localStorage.getItem('showNotifications') !== 'false';
        if (showNotifications) {
            connectNotificationSocket();
        }
    });

    // Close WebSocket connection when page is unloaded
    window.addEventListener('beforeunload', function () {
        if (notificationSocket) {
            notificationSocket.close();
        }
    });

    // User activity feed functionality
    function updateRecentActivity() {
        // In a real implementation, this would fetch from an API
        // For demo purposes, we'll simulate recent activity

        // This would be the real API call:
        /*
        fetch('/api/user/activity', {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            displayRecentActivity(data.activities);
        })
        .catch(error => {
            console.error('Error fetching recent activity:', error);
        });
        */

        // Fetch real activity data from API
        requestJson('/api/dashboard/recent-activity')
            .then(data => {
                if (data.success && data.activities) {
                    displayRecentActivity(data.activities);
                } else {
                    console.warn('No activity data available');
                    displayRecentActivity([]);
                }
            })
            .catch(error => {
                console.error('Error fetching recent activity:', error);
                displayRecentActivity([]);
            });
    }

    function displayRecentActivity(activities) {
        const activityList = document.querySelector('.activity-list');
        if (!activityList) return;

        // Limit to 5 most recent activities
        const recentActivities = activities.slice(0, 5);

        activityList.innerHTML = recentActivities.map(activity => {
            const typeClass = `text-${activity.type}`;
            return `
                <div class="activity-item">
                    <i class="bi bi-${activity.icon} ${typeClass}"></i>
                    <div class="activity-text">
                        <div class="activity-title">${activity.title}</div>
                        <small class="text-muted">${activity.time}</small>
                    </div>
                </div>
            `;
        }).join('');
    }

    // Update activity feed every 5 minutes
    setInterval(updateRecentActivity, 5 * 60 * 1000);

    // Initialize activity feed on page load
    document.addEventListener('DOMContentLoaded', function () {
        updateRecentActivity();
    });

    // Function to add a new activity item programmatically
    window.addActivityItem = function (icon, title, time, type = 'info') {
        const activityList = document.querySelector('.activity-list');
        if (!activityList) return;

        // Create new activity item
        const activityItem = document.createElement('div');
        activityItem.className = 'activity-item';
        activityItem.innerHTML = `
            <i class="bi bi-${icon} text-${type}"></i>
            <div class="activity-text">
                <div class="activity-title">${title}</div>
                <small class="text-muted">${time || 'Agora'}</small>
            </div>
        `;

        // Add to the top of the list
        activityList.insertBefore(activityItem, activityList.firstChild);

        // Limit to 5 items
        if (activityList.children.length > 5) {
            activityList.removeChild(activityList.lastChild);
        }
    };

    // Theme persistence functionality
    function initializeThemePersistence() {
        // Check for saved theme in localStorage
        const savedTheme = localStorage.getItem('selectedTheme');
        if (savedTheme) {
            applyTheme(savedTheme);
        } else {
            // Check for system preference
            const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (systemPrefersDark) {
                applyTheme('dark');
            }
        }
    }

    // Override the existing themeManager.applyTheme function if it exists
    if (typeof window.themeManager === 'undefined') {
        window.themeManager = {};
    }

    window.themeManager.applyTheme = function (theme) {
        // Save theme to localStorage
        localStorage.setItem('selectedTheme', theme);

        // Apply the theme to the document
        document.documentElement.setAttribute('data-theme', theme);

        // Update theme-related UI elements
        updateThemeUI(theme);
    };

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        updateThemeUI(theme);
    }

    function updateThemeUI(theme) {
        // Update theme selector UI to reflect current theme
        const themeButtons = document.querySelectorAll('#themeDropdown ~ ul .dropdown-item');
        themeButtons.forEach(button => {
            // Extract theme name from onclick attribute
            const themeMatch = button.getAttribute('onclick').match(/'([^']+)'/);
            if (themeMatch) {
                const buttonTheme = themeMatch[1];
                if (buttonTheme === theme) {
                    button.classList.add('active');
                } else {
                    button.classList.remove('active');
                }
            }
        });
    }

    // Initialize theme persistence when DOM is loaded
    document.addEventListener('DOMContentLoaded', function () {
        initializeThemePersistence();

        const collapsibleMenus = document.querySelectorAll('[data-bs-toggle="collapse"]');
        collapsibleMenus.forEach(trigger => {
            // Add keyboard support for collapsible menus
            trigger.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.click();
                }
            });

            // Add event to handle nested collapses
            trigger.addEventListener('click', function () {
                // Find the target collapse element
                const targetId = this.getAttribute('href') || this.getAttribute('data-bs-target');
                if (targetId) {
                    const targetElement = document.querySelector(targetId);
                    if (targetElement) {
                        // When opening a parent, close siblings
                        const parent = this.closest('.collapse');
                        if (parent) {
                            // Close other open submenus in the same level
                            const siblings = Array.from(parent.children).filter(child =>
                                child.classList.contains('nav-sub-item') &&
                                child !== targetElement.parentElement
                            );

                            siblings.forEach(sibling => {
                                const siblingCollapse = sibling.querySelector('.collapse');
                                if (siblingCollapse && siblingCollapse.classList.contains('show')) {
                                    const bsCollapse = bootstrap.Collapse.getInstance(siblingCollapse);
                                    if (bsCollapse) {
                                        bsCollapse.hide();
                                    }
                                }
                            });
                        }
                    }
                }
            });
        });

        // Handle nested collapse events
        document.querySelectorAll('.collapse').forEach(collapseEl => {
            collapseEl.addEventListener('hide.bs.collapse', function () {
                // When a collapse is hidden, also hide its children
                const childCollapses = this.querySelectorAll('.collapse');
                childCollapses.forEach(child => {
                    const bsCollapse = bootstrap.Collapse.getInstance(child);
                    if (bsCollapse) {
                        bsCollapse.hide();
                    }
                });
            });
        });
    });

    // Listen for system theme changes
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
        // Only apply system theme if user hasn't explicitly chosen a theme
        const savedTheme = localStorage.getItem('selectedTheme');
        if (!savedTheme) {
            if (e.matches) {
                applyTheme('dark');
            } else {
                applyTheme('light');
            }
        }
    });

    // Performance Monitoring
    function initializePerformanceMonitoring() {
        // Record page load start time
        const startTime = performance.now();

        // Calculate page load time when page is fully loaded
        window.addEventListener('load', function () {
            const loadTime = performance.now() - startTime;
            updateLoadTime(loadTime);
        });

        // Monitor performance regularly
        setInterval(updatePerformanceMetrics, 5000); // Update every 5 seconds

        // Initial update
        updatePerformanceMetrics();
    }

    function updateLoadTime(time) {
        const loadTimeElement = document.getElementById('load-time');
        if (loadTimeElement) {
            loadTimeElement.textContent = `${Math.round(time)}ms`;

            // Add performance indicator class based on load time
            loadTimeElement.classList.remove('perf-good', 'perf-average', 'perf-poor');
            if (time < 1000) {
                loadTimeElement.classList.add('perf-good');
            } else if (time < 3000) {
                loadTimeElement.classList.add('perf-average');
            } else {
                loadTimeElement.classList.add('perf-poor');
            }
        }
    }

    function updatePerformanceMetrics() {
        // Update load time if available
        if (performance.timing.loadEventEnd > 0) {
            const loadTime = performance.timing.loadEventEnd - performance.timing.navigationStart;
            updateLoadTime(loadTime);
        }

        // Update memory usage if available (not supported in all browsers)
        if (performance.memory) {
            const memoryUsed = Math.round(performance.memory.usedJSHeapSize / 1048576 * 100) / 100; // Convert to MB
            const memoryElement = document.getElementById('memory-usage');
            if (memoryElement) {
                memoryElement.textContent = `${memoryUsed} MB`;

                // Add performance indicator class based on memory usage
                memoryElement.classList.remove('perf-good', 'perf-average', 'perf-poor');
                if (memoryUsed < 50) {
                    memoryElement.classList.add('perf-good');
                } else if (memoryUsed < 100) {
                    memoryElement.classList.add('perf-average');
                } else {
                    memoryElement.classList.add('perf-poor');
                }
            }
        }

        // Update uptime
        updateUptime();
    }

    function updateUptime() {
        const uptimeElement = document.getElementById('uptime');
        if (uptimeElement) {
            // Calculate uptime since page load
            const uptime = Date.now() - performance.timing.navigationStart;
            const days = Math.floor(uptime / (1000 * 60 * 60 * 24));
            const hours = Math.floor((uptime % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((uptime % (1000 * 60 * 60)) / (1000 * 60));

            let uptimeText = '';
            if (days > 0) {
                uptimeText = `${days}d ${hours}h ${minutes}m`;
            } else if (hours > 0) {
                uptimeText = `${hours}h ${minutes}m`;
            } else {
                uptimeText = `${minutes}m`;
            }

            uptimeElement.textContent = uptimeText;
        }
    }

    // Initialize performance monitoring when DOM is loaded
    document.addEventListener('DOMContentLoaded', function () {
        initializePerformanceMonitoring();
    });

    // Custom Branding Support
    function normalizeExternalUrl(url) {
        if (!url || typeof url !== 'string') return url;
        if (url.startsWith('data:') || url.startsWith('blob:')) return url;
        if (url.startsWith('http://')) return url.replace(/^http:\/\//i, 'https://');
        if (url.startsWith('//')) return `${window.location.protocol}${url}`;
        return url;
    }

    function applyCustomBranding() {
        // Check for custom branding in localStorage or from server
        const customBrand = {
            logo: normalizeExternalUrl(localStorage.getItem('custom_brand_logo')),
            name: localStorage.getItem('custom_brand_name'),
            icon: localStorage.getItem('custom_brand_icon'),
            color: localStorage.getItem('custom_brand_color'),
            favicon: normalizeExternalUrl(localStorage.getItem('custom_favicon'))
        };

        // Apply custom branding if available
        if (customBrand.logo) {
            const brandLogoElements = document.querySelectorAll('.brand-logo');
            brandLogoElements.forEach(element => {
                // Replace the content with custom logo
                element.innerHTML = `
                    <img src="${customBrand.logo}" alt="${customBrand.name || 'Brand Logo'}" class="brand-logo-img">
                    <span>${customBrand.name || 'ML Manager'}</span>
                `;
            });
        } else if (customBrand.icon) {
            const brandLogoElements = document.querySelectorAll('.brand-logo');
            brandLogoElements.forEach(element => {
                element.innerHTML = `
                    <i class="bi ${customBrand.icon}" aria-hidden="true"></i>
                    <span>${customBrand.name || 'ML Manager'}</span>
                `;
            });
        }

        // Apply custom brand color if available
        if (customBrand.color) {
            document.documentElement.style.setProperty('--primary-color', customBrand.color);
            document.documentElement.style.setProperty('--primary-gradient', `linear-gradient(135deg, ${customBrand.color} 0%, #2575fc 100%)`);
        }

        // Update favicon if available
        if (customBrand.favicon) {
            const favicon = document.querySelector('link[rel="icon"]');
            if (favicon) {
                favicon.href = customBrand.favicon;
            }
        }

        // Update page title if needed
        if (customBrand.name) {
            document.title = customBrand.name + ' Manager';
        }
    }

    // Function to set custom branding
    window.setCustomBranding = function (brandingData) {
        if (brandingData.logo) localStorage.setItem('custom_brand_logo', normalizeExternalUrl(brandingData.logo));
        if (brandingData.name) localStorage.setItem('custom_brand_name', brandingData.name);
        if (brandingData.icon) localStorage.setItem('custom_brand_icon', brandingData.icon);
        if (brandingData.color) localStorage.setItem('custom_brand_color', brandingData.color);
        if (brandingData.favicon) localStorage.setItem('custom_favicon', normalizeExternalUrl(brandingData.favicon));

        // Apply the new branding
        applyCustomBranding();
    };

    // Initialize custom branding when DOM is loaded
    document.addEventListener('DOMContentLoaded', function () {
        applyCustomBranding();
    });

    // User Onboarding Tour
    function initializeOnboardingTour() {
        // Check if user has completed the tour before
        const hasCompletedTour = localStorage.getItem('hasCompletedOnboardingTour') === 'true';

        // Only show tour for new users or when explicitly requested
        if (!hasCompletedTour) {
            // Show a welcome modal for first-time users
            setTimeout(() => {
                showWelcomeModal();
            }, 2000); // Show after 2 seconds
        }
    }

    function showWelcomeModal() {
        // Create a simple welcome modal to introduce the tour
        const welcomeHtml = `
            <div id="welcome-modal" class="modal fade" tabindex="-1" style="display: block; padding-right: 17px; z-index: 1051;">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Bem-vindo ao ML Manager!</h5>
                        </div>
                        <div class="modal-body">
                            <p>Olá! Parece que é sua primeira vez aqui. Gostaria de fazer um tour rápido pelo sistema?</p>
                            <div class="d-flex justify-content-between">
                                <button id="skip-tour-btn" class="btn btn-secondary">Pular Tour</button>
                                <button id="start-tour-btn" class="btn btn-primary">Começar Tour</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-backdrop show" style="z-index: 1050;"></div>
            </div>
        `;

        // Add the modal to the page
        document.body.insertAdjacentHTML('beforeend', welcomeHtml);

        // Add event listeners
        document.getElementById('start-tour-btn').addEventListener('click', function () {
            document.body.removeChild(document.getElementById('welcome-modal'));
            document.body.removeChild(document.querySelector('.modal-backdrop'));
            startDashboardTour();
        });

        document.getElementById('skip-tour-btn').addEventListener('click', function () {
            document.body.removeChild(document.getElementById('welcome-modal'));
            document.body.removeChild(document.querySelector('.modal-backdrop'));
            localStorage.setItem('hasCompletedOnboardingTour', 'true');
        });
    }

    // Enhanced tour functionality
    window.tourManager = {
        tour: null,

        init: function () {
            // Initialize Shepherd.js tour
            if (typeof Shepherd !== 'undefined') {
                this.tour = new Shepherd.Tour({
                    defaultStepOptions: {
                        cancelIcon: {
                            enabled: true
                        },
                        classes: 'shadow-lg',
                        scrollTo: { behavior: 'smooth', block: 'center' }
                    },
                    useModalOverlay: true,
                });

                this.addSteps();
            } else {
                console.warn('Shepherd.js not loaded. Tour functionality disabled.');
            }
        },

        addSteps: function () {
            if (!this.tour) return;

            // Step 1: Dashboard Overview
            this.tour.addStep({
                id: 'dashboard-overview',
                title: 'Visão Geral do Dashboard',
                text: 'Este é o seu painel principal. Aqui você encontrará resumos importantes e atalhos para as funções mais utilizadas.',
                attachTo: {
                    element: '.brand-logo',
                    on: 'bottom'
                },
                buttons: [
                    {
                        text: 'Pular',
                        action: this.tour.cancel
                    },
                    {
                        text: 'Próximo',
                        action: this.tour.next
                    }
                ]
            });

            // Step 2: User Profile
            this.tour.addStep({
                id: 'user-profile',
                title: 'Seu Perfil',
                text: 'Esta seção mostra suas informações pessoais, incluindo nome, função e status de segurança da conta.',
                attachTo: {
                    element: '.user-profile',
                    on: 'right'
                },
                buttons: [
                    {
                        text: 'Voltar',
                        action: this.tour.back
                    },
                    {
                        text: 'Próximo',
                        action: this.tour.next
                    }
                ]
            });

            // Step 3: Navigation Menu
            this.tour.addStep({
                id: 'navigation-menu',
                title: 'Menu de Navegação',
                text: 'Aqui estão todas as funcionalidades do sistema organizadas por categorias. Você pode pesquisar usando a barra acima.',
                attachTo: {
                    element: '.sidebar-nav',
                    on: 'right'
                },
                buttons: [
                    {
                        text: 'Voltar',
                        action: this.tour.back
                    },
                    {
                        text: 'Próximo',
                        action: this.tour.next
                    }
                ]
            });

            // Step 4: Search Functionality
            this.tour.addStep({
                id: 'search-functionality',
                title: 'Busca Rápida',
                text: 'Use esta barra de pesquisa para encontrar rapidamente qualquer funcionalidade no sistema.',
                attachTo: {
                    element: '.search-input',
                    on: 'bottom'
                },
                buttons: [
                    {
                        text: 'Voltar',
                        action: this.tour.back
                    },
                    {
                        text: 'Próximo',
                        action: this.tour.next
                    }
                ]
            });

            // Step 5: Quick Actions
            this.tour.addStep({
                id: 'quick-actions',
                title: 'Ações Rápidas',
                text: 'Estes botões fornecem acesso direto às tarefas mais comuns, economizando tempo.',
                attachTo: {
                    element: '.quick-actions',
                    on: 'top'
                },
                buttons: [
                    {
                        text: 'Voltar',
                        action: this.tour.back
                    },
                    {
                        text: 'Próximo',
                        action: this.tour.next
                    }
                ]
            });

            // Step 6: Preferences and Settings
            this.tour.addStep({
                id: 'preferences-settings',
                title: 'Personalização',
                text: 'Personalize sua experiência com opções de idioma, tema, preferências de usuário e muito mais.',
                attachTo: {
                    element: '#langDropdown',
                    on: 'top'
                },
                buttons: [
                    {
                        text: 'Voltar',
                        action: this.tour.back
                    },
                    {
                        text: 'Próximo',
                        action: this.tour.next
                    }
                ]
            });

            // Step 7: Final Step
            this.tour.addStep({
                id: 'final-step',
                title: 'Pronto para Começar!',
                text: 'Agora você conhece os principais recursos do sistema. Se precisar rever este tour, clique em "Ajuda" > "Tour do Dashboard".',
                attachTo: {
                    element: '.sidebar-footer',
                    on: 'top'
                },
                buttons: [
                    {
                        text: 'Voltar',
                        action: this.tour.back
                    },
                    {
                        text: 'Concluir Tour',
                        action: () => {
                            this.tour.complete();
                            localStorage.setItem('hasCompletedOnboardingTour', 'true');
                        }
                    }
                ]
            });
        },

        start: function () {
            if (this.tour) {
                this.tour.start();
            } else {
                this.init();
                if (this.tour) {
                    this.tour.start();
                }
            }
        },

        restart: function () {
            localStorage.removeItem('hasCompletedOnboardingTour');
            this.start();
        }
    };

    // Function to start the dashboard tour (for backward compatibility)
    window.startDashboardTour = function () {
        tourManager.start();
    };

    // Initialize onboarding tour when DOM is loaded
    document.addEventListener('DOMContentLoaded', function () {
        initializeOnboardingTour();

        // Initialize tour manager
        if (typeof Shepherd !== 'undefined') {
            tourManager.init();
        }

        // Initialize menu customization
        initializeMenuCustomization();
    });

    // Menu customization functionality
    function initializeMenuCustomization() {
        const enableMenuCustomization = document.getElementById('enableMenuCustomization');
        const menuCustomizationSection = document.getElementById('menu-customization-section');
        const menuOrderList = document.getElementById('menu-order-list');

        if (enableMenuCustomization) {
            // Toggle menu customization section
            enableMenuCustomization.addEventListener('change', function () {
                if (this.checked) {
                    menuCustomizationSection.style.display = 'block';
                    populateMenuOrderList();
                } else {
                    menuCustomizationSection.style.display = 'none';
                }
            });
        }

        // Populate the menu order list with current menu items
        function populateMenuOrderList() {
            if (!menuOrderList) return;

            // Get all navigation items from the sidebar
            const navItems = document.querySelectorAll('.sidebar-nav .nav-item a.nav-link');
            menuOrderList.innerHTML = ''; // Clear existing items

            navItems.forEach((item, index) => {
                const text = item.querySelector('span')?.textContent || item.textContent;
                const icon = item.querySelector('i')?.className || '';

                const menuItem = document.createElement('div');
                menuItem.className = 'draggable-menu-item';
                menuItem.setAttribute('data-index', index);
                menuItem.setAttribute('draggable', 'true');

                menuItem.innerHTML = `
                    <i class="drag-handle bi bi-grip-vertical"></i>
                    <i class="menu-item-icon ${icon}"></i>
                    <span class="menu-item-text">${text}</span>
                `;

                // Add drag events
                addDragEvents(menuItem);

                menuOrderList.appendChild(menuItem);
            });
        }

        // Add drag and drop events to menu items
        function addDragEvents(element) {
            element.addEventListener('dragstart', function (e) {
                e.dataTransfer.setData('text/plain', this.getAttribute('data-index'));
                this.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
            });

            element.addEventListener('dragend', function () {
                this.classList.remove('dragging');
            });

            element.addEventListener('dragover', function (e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                this.classList.add('over');
            });

            element.addEventListener('dragleave', function () {
                this.classList.remove('over');
            });

            element.addEventListener('drop', function (e) {
                e.preventDefault();
                this.classList.remove('over');

                const draggedIndex = e.dataTransfer.getData('text/plain');
                const draggedElement = document.querySelector(`[data-index="${draggedIndex}"]`);

                if (draggedElement && draggedElement !== this) {
                    // Reorder the elements
                    const allItems = Array.from(menuOrderList.children);
                    const draggedIndexNum = parseInt(draggedIndex);
                    const targetIndex = allItems.indexOf(this);

                    if (draggedIndexNum !== targetIndex) {
                        // Remove the dragged element
                        menuOrderList.removeChild(draggedElement);

                        // Insert at new position
                        if (targetIndex >= allItems.indexOf(this)) {
                            menuOrderList.insertBefore(draggedElement, this.nextSibling);
                        } else {
                            menuOrderList.insertBefore(draggedElement, this);
                        }

                        // Update indices
                        updateIndices();
                    }
                }
            });
        }

        // Update the data-index attributes after reordering
        function updateIndices() {
            const items = menuOrderList.querySelectorAll('.draggable-menu-item');
            items.forEach((item, index) => {
                item.setAttribute('data-index', index);
            });
        }

        // Function to save menu order to localStorage
        window.saveMenuOrder = function () {
            const items = menuOrderList.querySelectorAll('.draggable-menu-item');
            const order = [];

            items.forEach(item => {
                const text = item.querySelector('.menu-item-text').textContent;
                order.push(text);
            });

            localStorage.setItem('customMenuOrder', JSON.stringify(order));
            console.log('Menu order saved:', order);
        };

        // Function to restore menu order from localStorage
        window.restoreMenuOrder = function () {
            const savedOrder = localStorage.getItem('customMenuOrder');
            if (savedOrder) {
                const order = JSON.parse(savedOrder);
                console.log('Restoring menu order:', order);
                // Implementation would reorder the actual menu items
            }
        };
    }

    // Recent Documents functionality
    function initializeRecentDocuments() {
        // Fetch real documents from API
        requestJson('/api/dashboard/recent-documents', {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(data => {
                if (data.success && data.documents) {
                    displayRecentDocuments(data.documents);
                } else {
                    console.warn('No documents data available');
                    displayRecentDocuments([]);
                }
            })
            .catch(error => {
                console.error('Error fetching recent documents:', error);
                displayRecentDocuments([]);
            });
    }

    function displayRecentDocuments(documents) {
        const documentsList = document.querySelector('.documents-list');
        if (!documentsList) return;

        // Limit to 5 most recent documents
        const recentDocuments = documents.slice(0, 5);

        documentsList.innerHTML = recentDocuments.map(doc => {
            // Map document types to appropriate Bootstrap Icons
            const iconMap = {
                'pdf': 'bi-file-earmark-pdf text-danger',
                'excel': 'bi-file-earmark-excel text-success',
                'word': 'bi-file-earmark-word text-primary',
                'powerpoint': 'bi-file-earmark-ppt text-warning',
                'archive': 'bi-file-zip text-info',
                'image': 'bi-file-image text-secondary',
                'default': 'bi-file-earmark text-muted'
            };

            const iconClass = iconMap[doc.type] || iconMap['default'];

            return `
                <div class="document-item" data-doc-id="${doc.id}" title="${doc.name} (${doc.size})">
                    <i class="bi ${iconClass}"></i>
                    <div class="document-text">
                        <div class="document-title">${doc.name}</div>
                        <small class="text-muted">${doc.time}</small>
                    </div>
                </div>
            `;
        }).join('');

        // Add click event listeners to document items
        document.querySelectorAll('.document-item').forEach(item => {
            item.addEventListener('click', function () {
                const docId = this.getAttribute('data-doc-id');
                // In a real implementation, this would open the document
                console.log('Opening document with ID:', docId);

                // For demo purposes, we'll just show an alert
                const docTitle = this.querySelector('.document-title').textContent;
                alert(`Abrindo documento: ${docTitle}\nEm uma implementação real, isso abriria o documento.`);
            });
        });
    }

    // Update recent documents periodically
    function updateRecentDocuments() {
        // In a real implementation, this would fetch updated documents
        // For demo, we'll just refresh the display
        initializeRecentDocuments();
    }

    // Initialize recent documents when DOM is loaded
    document.addEventListener('DOMContentLoaded', function () {
        initializeRecentDocuments();

        // Update documents list every 10 minutes
        setInterval(updateRecentDocuments, 10 * 60 * 1000);

        // Initialize theme scheduling
        initializeThemeScheduling();
    });

    // Theme scheduling functionality
    function initializeThemeScheduling() {
        const enableThemeScheduling = document.getElementById('enableThemeScheduling');
        const themeSchedulingSection = document.getElementById('theme-scheduling-section');

        if (enableThemeScheduling) {
            // Toggle theme scheduling section
            enableThemeScheduling.addEventListener('change', function () {
                if (this.checked) {
                    themeSchedulingSection.style.display = 'block';
                    loadThemeScheduleSettings();
                } else {
                    themeSchedulingSection.style.display = 'none';
                    // Clear any scheduled theme changes
                    clearInterval(window.themeScheduleInterval);
                }
            });
        }

        // Load saved theme schedule settings
        function loadThemeScheduleSettings() {
            const lightTime = localStorage.getItem('lightThemeTime') || '06:00';
            const darkTime = localStorage.getItem('darkThemeTime') || '18:00';
            const scheduledDays = JSON.parse(localStorage.getItem('scheduledThemeDays') || '[]');

            // Set the time inputs
            const lightTimeInput = document.getElementById('lightThemeTime');
            const darkTimeInput = document.getElementById('darkThemeTime');

            if (lightTimeInput) lightTimeInput.value = lightTime;
            if (darkTimeInput) darkTimeInput.value = darkTime;

            // Set the day checkboxes
            const dayIds = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
            dayIds.forEach((dayId, index) => {
                const checkbox = document.getElementById(dayId);
                if (checkbox) {
                    checkbox.checked = scheduledDays.length === 0 || scheduledDays.includes(index);
                }
            });

            // Add event listeners to save changes
            if (lightTimeInput) {
                lightTimeInput.addEventListener('change', saveThemeScheduleSettings);
            }
            if (darkTimeInput) {
                darkTimeInput.addEventListener('change', saveThemeScheduleSettings);
            }

            // Add event listeners to day checkboxes
            dayIds.forEach(dayId => {
                const checkbox = document.getElementById(dayId);
                if (checkbox) {
                    checkbox.addEventListener('change', saveThemeScheduleSettings);
                }
            });

            // Start the theme scheduler
            startThemeScheduler();
        }

        // Save theme schedule settings
        function saveThemeScheduleSettings() {
            const lightTimeInput = document.getElementById('lightThemeTime');
            const darkTimeInput = document.getElementById('darkThemeTime');

            if (lightTimeInput && darkTimeInput) {
                localStorage.setItem('lightThemeTime', lightTimeInput.value);
                localStorage.setItem('darkThemeTime', darkTimeInput.value);

                // Get selected days
                const dayIds = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
                const selectedDays = [];

                dayIds.forEach((dayId, index) => {
                    const checkbox = document.getElementById(dayId);
                    if (checkbox && checkbox.checked) {
                        selectedDays.push(index);
                    }
                });

                // If all days are selected, store empty array (meaning all days)
                if (selectedDays.length === 7) {
                    localStorage.setItem('scheduledThemeDays', JSON.stringify([]));
                } else {
                    localStorage.setItem('scheduledThemeDays', JSON.stringify(selectedDays));
                }

                // Restart the scheduler with new settings
                startThemeScheduler();
            }
        }

        // Start the theme scheduler
        function startThemeScheduler() {
            // Clear any existing interval
            if (window.themeScheduleInterval) {
                clearInterval(window.themeScheduleInterval);
            }

            // Check theme every minute
            window.themeScheduleInterval = setInterval(checkScheduledTheme, 60000);

            // Run immediately to set initial theme
            checkScheduledTheme();
        }

        // Check if it's time to change theme
        function checkScheduledTheme() {
            const now = new Date();
            const currentHour = now.getHours();
            const currentMinute = now.getMinutes();
            const currentTime = currentHour * 60 + currentMinute;

            // Get scheduled times
            const lightTimeString = localStorage.getItem('lightThemeTime') || '06:00';
            const darkTimeString = localStorage.getItem('darkThemeTime') || '18:00';

            const [lightHours, lightMinutes] = lightTimeString.split(':').map(Number);
            const [darkHours, darkMinutes] = darkTimeString.split(':').map(Number);

            const lightTime = lightHours * 60 + lightMinutes;
            const darkTime = darkHours * 60 + darkMinutes;

            // Check if today is in the scheduled days
            const scheduledDays = JSON.parse(localStorage.getItem('scheduledThemeDays') || '[]');
            const currentDay = now.getDay(); // Sunday = 0, Monday = 1, etc.

            const isScheduledDay = scheduledDays.length === 0 || scheduledDays.includes(currentDay);

            if (!isScheduledDay) {
                return; // Don't change theme on unscheduled days
            }

            // Determine which theme should be active
            let shouldUseDarkTheme = false;

            if (lightTime <= darkTime) {
                // Normal case: light theme in morning, dark theme in evening
                shouldUseDarkTheme = (currentTime >= darkTime || currentTime < lightTime);
            } else {
                // Overnight case: dark theme overnight, light theme during day
                shouldUseDarkTheme = (currentTime >= darkTime || currentTime < lightTime) &&
                    !(currentTime >= lightTime && currentTime < darkTime);
            }

            // Apply the appropriate theme
            if (shouldUseDarkTheme) {
                if (localStorage.getItem('selectedTheme') !== 'dark') {
                    themeManager.applyTheme('dark');
                }
            } else {
                if (localStorage.getItem('selectedTheme') !== 'light') {
                    themeManager.applyTheme('light');
                }
            }
        }
    }

    // User Statistics Dashboard functionality
    function initializeUserStatistics() {
        // In a real implementation, this would fetch from an API
        // For demo purposes, we'll use mock data

        // This would be the real API call:
        // Fetch real user statistics from API
        requestJson('/api/dashboard/user-statistics', {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(data => {
                if (data.success && data.statistics) {
                    displayUserStatistics(data.statistics);
                } else {
                    console.warn('No statistics data available');
                }
            })
            .catch(error => {
                console.error('Error fetching user statistics:', error);
            });

        // Update statistics every 5 minutes
        setInterval(updateUserStatistics, 5 * 60 * 1000);
    }

    function displayUserStatistics(stats) {
        // Update productivity score
        const productivityScore = document.getElementById('productivity-score');
        if (productivityScore) {
            productivityScore.textContent = `${stats.productivity}%`;

            // Add color class based on productivity level
            productivityScore.classList.remove('stat-level-low', 'stat-level-medium', 'stat-level-high');
            if (stats.productivity < 50) {
                productivityScore.classList.add('stat-level-low');
            } else if (stats.productivity < 80) {
                productivityScore.classList.add('stat-level-medium');
            } else {
                productivityScore.classList.add('stat-level-high');
            }
        }

        // Update activity level
        const activityLevel = document.getElementById('activity-level');
        if (activityLevel) {
            activityLevel.textContent = stats.activityLevel;

            // Add color class based on activity level
            activityLevel.classList.remove('stat-level-low', 'stat-level-medium', 'stat-level-high');
            if (stats.activityLevel === 'Baixa') {
                activityLevel.classList.add('stat-level-low');
            } else if (stats.activityLevel === 'Média') {
                activityLevel.classList.add('stat-level-medium');
            } else {
                activityLevel.classList.add('stat-level-high');
            }
        }

        // Update goals completed
        const goalsCompleted = document.getElementById('goals-completed');
        if (goalsCompleted) {
            goalsCompleted.textContent = `${stats.goalsCompleted}/10`;

            // Add color class based on completion rate
            goalsCompleted.classList.remove('stat-level-low', 'stat-level-medium', 'stat-level-high');
            const completionRate = (stats.goalsCompleted / 10) * 100;
            if (completionRate < 50) {
                goalsCompleted.classList.add('stat-level-low');
            } else if (completionRate < 80) {
                goalsCompleted.classList.add('stat-level-medium');
            } else {
                goalsCompleted.classList.add('stat-level-high');
            }
        }
    }

    function updateUserStatistics() {
        // Fetch real updated statistics from API
        requestJson('/api/dashboard/user-statistics', {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(data => {
                if (data.success && data.statistics) {
                    displayUserStatistics(data.statistics);
                }
            })
            .catch(error => {
                console.error('Error updating user statistics:', error);
            });
    }

    // Initialize user statistics when DOM is loaded
    document.addEventListener('DOMContentLoaded', function () {
        initializeUserStatistics();

        // Initialize comprehensive keyboard navigation
        initializeComprehensiveKeyboardNavigation();
    });

    // Comprehensive keyboard navigation for all elements
    function initializeComprehensiveKeyboardNavigation() {
        // Add keyboard shortcuts for main navigation
        document.addEventListener('keydown', function (e) {
            // Global shortcuts (work anywhere on the page)
            if (e.ctrlKey && e.key === 'k') {
                e.preventDefault();
                const searchInput = document.querySelector('.search-input');
                if (searchInput) {
                    searchInput.focus();
                }
                return;
            }

            // '?' for help/shortcuts modal
            if (e.key === '?' && !e.ctrlKey && !e.altKey && !e.metaKey) {
                if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') {
                    e.preventDefault();
                    showKeyboardShortcuts();
                }
                return;
            }

            // 'H' for help menu
            if ((e.ctrlKey && e.altKey && e.key === 'h') ||
                (e.ctrlKey && e.key === 'h')) {
                e.preventDefault();
                const helpDropdown = document.getElementById('helpDropdown');
                if (helpDropdown) {
                    helpDropdown.click();
                }
                return;
            }

            // 'T' for theme toggle
            if (e.ctrlKey && e.altKey && e.key === 't') {
                e.preventDefault();
                const themeDropdown = document.getElementById('themeDropdown');
                if (themeDropdown) {
                    themeDropdown.click();
                }
                return;
            }

            // 'L' for language toggle
            if (e.ctrlKey && e.altKey && e.key === 'l') {
                e.preventDefault();
                const langDropdown = document.getElementById('langDropdown');
                if (langDropdown) {
                    langDropdown.click();
                }
                return;
            }

            // 'S' for settings/preferences
            if (e.ctrlKey && e.altKey && e.key === 's') {
                e.preventDefault();
                showPreferencesPanel();
                return;
            }

            // Arrow navigation for sidebar items
            if (e.key.startsWith('Arrow') && document.activeElement.classList.contains('nav-link')) {
                e.preventDefault();
                handleArrowNavigation(e);
                return;
            }
        });

        // Add keyboard support to all interactive elements
        addKeyboardSupportToElements();
    }

    function handleArrowNavigation(e) {
        const allNavLinks = Array.from(document.querySelectorAll('.nav-link'));
        const currentIndex = allNavLinks.indexOf(document.activeElement);

        if (currentIndex === -1) return;

        let newIndex = currentIndex;

        switch (e.key) {
            case 'ArrowDown':
                newIndex = Math.min(currentIndex + 1, allNavLinks.length - 1);
                break;
            case 'ArrowUp':
                newIndex = Math.max(currentIndex - 1, 0);
                break;
            case 'ArrowRight':
                // Expand collapsed items
                if (document.activeElement.getAttribute('aria-controls')) {
                    document.activeElement.click();
                }
                break;
            case 'ArrowLeft':
                // Collapse expanded items
                if (document.activeElement.getAttribute('aria-expanded') === 'true') {
                    document.activeElement.click();
                }
                break;
        }

        if (newIndex !== currentIndex) {
            allNavLinks[newIndex].focus();
            // Scroll to element if needed
            allNavLinks[newIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }

    function addKeyboardSupportToElements() {
        // Add keyboard support to all buttons
        const buttons = document.querySelectorAll('button, [role="button"]');
        buttons.forEach(button => {
            if (!button.hasAttribute('tabindex')) {
                button.setAttribute('tabindex', '0');
            }

            button.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.click();
                }
            });
        });

        // Add keyboard support to all links
        const links = document.querySelectorAll('a');
        links.forEach(link => {
            link.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.click();
                }
            });
        });

        // Add keyboard support to form elements
        const inputs = document.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            input.addEventListener('keydown', function (e) {
                // Prevent form submission with Enter in search fields
                if (e.key === 'Enter' && this.classList.contains('search-input')) {
                    e.preventDefault();
                    // Perform search
                    const searchTerm = this.value;
                    if (searchTerm) {
                        performSearch(searchTerm);
                    }
                }
            });
        });

        // Add keyboard support to dropdowns
        const dropdowns = document.querySelectorAll('.dropdown-toggle');
        dropdowns.forEach(dropdown => {
            dropdown.addEventListener('keydown', function (e) {
                switch (e.key) {
                    case 'Enter':
                    case ' ':
                        e.preventDefault();
                        this.click();
                        break;
                    case 'Escape':
                        // Close dropdown
                        const dropdownMenu = this.nextElementSibling;
                        if (dropdownMenu && dropdownMenu.classList.contains('dropdown-menu')) {
                            dropdownMenu.classList.remove('show');
                        }
                        break;
                    case 'ArrowDown':
                        e.preventDefault();
                        // Focus on first item in dropdown
                        const firstItem = this.nextElementSibling?.querySelector('.dropdown-item');
                        if (firstItem) {
                            firstItem.focus();
                        }
                        break;
                }
            });
        });

        // Add keyboard support to dropdown items
        const dropdownItems = document.querySelectorAll('.dropdown-item');
        dropdownItems.forEach(item => {
            item.addEventListener('keydown', function (e) {
                switch (e.key) {
                    case 'Enter':
                    case ' ':
                        e.preventDefault();
                        this.click();
                        break;
                    case 'ArrowDown':
                        e.preventDefault();
                        // Focus on next item
                        const nextItem = this.nextElementSibling;
                        if (nextItem && nextItem.classList.contains('dropdown-item')) {
                            nextItem.focus();
                        }
                        break;
                    case 'ArrowUp':
                        e.preventDefault();
                        // Focus on previous item
                        const prevItem = this.previousElementSibling;
                        if (prevItem && prevItem.classList.contains('dropdown-item')) {
                            prevItem.focus();
                        } else {
                            // Focus on dropdown toggle
                            const dropdownToggle = this.closest('.dropdown')?.querySelector('.dropdown-toggle');
                            if (dropdownToggle) {
                                dropdownToggle.focus();
                            }
                        }
                        break;
                    case 'Escape':
                        e.preventDefault();
                        // Close dropdown and focus on toggle
                        const dropdownMenu = this.closest('.dropdown-menu');
                        if (dropdownMenu) {
                            dropdownMenu.classList.remove('show');
                            const dropdownToggle = dropdownMenu.previousElementSibling;
                            if (dropdownToggle) {
                                dropdownToggle.focus();
                            }
                        }
                        break;
                }
            });
        });

        // Add keyboard support to collapsible elements
        const collapsibles = document.querySelectorAll('[data-bs-toggle="collapse"]');
        collapsibles.forEach(collapsible => {
            collapsible.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.click();
                }
            });
        });

        // Add keyboard support to document items
        const documentItems = document.querySelectorAll('.document-item');
        documentItems.forEach(item => {
            item.setAttribute('tabindex', '0');
            item.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.click();
                }
            });
        });

        // Add keyboard support to quick action buttons
        const quickActionButtons = document.querySelectorAll('.quick-action-btn');
        quickActionButtons.forEach(button => {
            button.setAttribute('tabindex', '0');
            button.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.click();
                }
            });
        });
    }

    function performSearch(term) {
        // Perform search action
        console.log('Searching for:', term);
        // In a real implementation, this would trigger the search functionality
    }

    // Export/Import Settings functionality
    window.exportSettings = function () {
        // Collect all user settings from localStorage
        const settings = {
            // Theme settings
            selectedTheme: localStorage.getItem('selectedTheme'),

            // Preference settings
            autoCollapseSidebar: localStorage.getItem('autoCollapseSidebar'),
            showNotifications: localStorage.getItem('showNotifications'),
            enableAnimations: localStorage.getItem('enableAnimations'),
            fontSize: localStorage.getItem('fontSize'),
            menuOrder: localStorage.getItem('menuOrder'),
            highContrastMode: localStorage.getItem('highContrastMode'),
            reduceMotion: localStorage.getItem('reduceMotion'),
            screenReaderFriendly: localStorage.getItem('screenReaderFriendly'),
            focusIndicatorSize: localStorage.getItem('focusIndicatorSize'),

            // Menu customization
            customMenuOrder: localStorage.getItem('customMenuOrder'),

            // Theme scheduling
            lightThemeTime: localStorage.getItem('lightThemeTime'),
            darkThemeTime: localStorage.getItem('darkThemeTime'),
            scheduledThemeDays: localStorage.getItem('scheduledThemeDays'),

            // Custom branding
            custom_brand_logo: localStorage.getItem('custom_brand_logo'),
            custom_brand_name: localStorage.getItem('custom_brand_name'),
            custom_brand_icon: localStorage.getItem('custom_brand_icon'),
            custom_brand_color: localStorage.getItem('custom_brand_color'),
            custom_favicon: localStorage.getItem('custom_favicon'),

            // Timestamp
            exportedAt: new Date().toISOString()
        };

        // Create a downloadable file
        const dataStr = JSON.stringify(settings, null, 2);
        const dataBlob = new Blob([dataStr], { type: 'application/json' });
        const url = URL.createObjectURL(dataBlob);

        // Create a temporary link and trigger download
        const link = document.createElement('a');
        link.href = url;
        link.download = `ml-manager-settings-${new Date().toISOString().split('T')[0]}.json`;
        document.body.appendChild(link);
        link.click();

        // Clean up
        setTimeout(() => {
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }, 100);

        // Show confirmation
        const announcer = document.getElementById('sidebar-announcer');
        if (announcer) {
            announcer.textContent = 'Configurações exportadas com sucesso!';
            setTimeout(() => {
                announcer.textContent = '';
            }, 3000);
        }
    };

    window.importSettings = function () {
        // Create a file input element
        const fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = '.json,application/json';
        fileInput.style.display = 'none';

        fileInput.onchange = function (e) {
            const file = e.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function (e) {
                try {
                    const settings = JSON.parse(e.target.result);

                    // Validate the imported settings
                    if (!settings.exportedAt) {
                        alert('Arquivo de configurações inválido!');
                        return;
                    }

                    // Confirm import
                    if (!confirm(`Tem certeza que deseja importar as configurações salvas em ${settings.exportedAt}? Esta ação substituirá todas as configurações atuais.`)) {
                        return;
                    }

                    // Apply the imported settings
                    Object.keys(settings).forEach(key => {
                        if (key !== 'exportedAt') { // Don't import the timestamp
                            localStorage.setItem(key, settings[key]);
                        }
                    });

                    // Show confirmation
                    const announcer = document.getElementById('sidebar-announcer');
                    if (announcer) {
                        announcer.textContent = 'Configurações importadas com sucesso! Recarregando página...';
                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
                    }

                } catch (error) {
                    console.error('Error importing settings:', error);
                    alert('Erro ao importar configurações. Arquivo pode estar corrompido.');
                }
            };
            reader.readAsText(file);
        };

        document.body.appendChild(fileInput);
        fileInput.click();

        // Clean up
        setTimeout(() => {
            document.body.removeChild(fileInput);
        }, 100);
    };

    // Advanced Search with Filters functionality
    function initializeAdvancedSearch() {
        const searchInput = document.querySelector('.search-input');
        const advancedFilters = document.getElementById('advanced-search-filters');

        if (searchInput) {
            // Add search icon to toggle filters
            const searchContainer = searchInput.parentElement;
            const filterToggle = document.createElement('button');
            filterToggle.className = 'search-toggle-filters';
            filterToggle.innerHTML = '<i class="bi bi-funnel" aria-hidden="true"></i>';
            filterToggle.setAttribute('aria-label', 'Alternar filtros avançados');
            filterToggle.type = 'button';

            searchContainer.appendChild(filterToggle);

            // Toggle advanced filters
            filterToggle.addEventListener('click', function (e) {
                e.stopPropagation();
                const isVisible = advancedFilters.style.display !== 'none';
                advancedFilters.style.display = isVisible ? 'none' : 'block';

                // Update icon based on state
                const icon = this.querySelector('i');
                icon.className = isVisible ? 'bi bi-funnel' : 'bi bi-funnel-fill';
            });

            // Add search functionality with filters
            searchInput.addEventListener('input', function () {
                const searchTerm = this.value.toLowerCase();
                const categoryFilter = document.getElementById('search-category-filter')?.value || '';
                const typeFilter = document.getElementById('search-type-filter')?.value || '';
                const favoritesOnly = document.getElementById('search-favorites-filter')?.checked || false;

                performAdvancedSearch(searchTerm, categoryFilter, typeFilter, favoritesOnly);
            });

            // Add keyboard shortcuts for search
            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    // Clear search and filters
                    this.value = '';
                    clearSearchResults();
                    if (advancedFilters) {
                        advancedFilters.style.display = 'none';
                    }

                    // Update filter icon
                    const filterIcon = filterToggle.querySelector('i');
                    if (filterIcon) {
                        filterIcon.className = 'bi bi-funnel';
                    }
                }
            });
        }

        // Add event listeners to filter controls
        const categoryFilter = document.getElementById('search-category-filter');
        const typeFilter = document.getElementById('search-type-filter');
        const favoritesFilter = document.getElementById('search-favorites-filter');

        if (categoryFilter) {
            categoryFilter.addEventListener('change', applyCurrentFilters);
        }

        if (typeFilter) {
            typeFilter.addEventListener('change', applyCurrentFilters);
        }

        if (favoritesFilter) {
            favoritesFilter.addEventListener('change', applyCurrentFilters);
        }
    }

    function performAdvancedSearch(searchTerm, categoryFilter, typeFilter, favoritesOnly) {
        // Get all navigation items
        const navItems = document.querySelectorAll('.nav-item');
        let visibleCount = 0;

        navItems.forEach(item => {
            const link = item.querySelector('.nav-link');
            if (link) {
                const linkText = link.textContent.toLowerCase();
                const linkHref = link.getAttribute('href') || '';

                // Basic search match
                const matchesSearch = searchTerm === '' ||
                    linkText.includes(searchTerm) ||
                    linkHref.toLowerCase().includes(searchTerm);

                // Category filter (would need data-category attributes on links)
                const categoryMatch = !categoryFilter ||
                    categoryFilter === '' ||
                    link.dataset.category === categoryFilter ||
                    link.classList.contains(categoryFilter);

                // Type filter (would need data-type attributes on links)
                const typeMatch = !typeFilter ||
                    typeFilter === '' ||
                    link.dataset.type === typeFilter ||
                    link.classList.contains(typeFilter);

                // Favorites filter (would need to check user's favorites)
                const linkHrefClean = linkHref.replace(/^\//, ''); // Remove leading slash
                const isFavorite = localStorage.getItem(`favorite_${linkHrefClean}`) === 'true';
                const favoritesMatch = !favoritesOnly || isFavorite;

                // Show/hide item based on all conditions
                if (matchesSearch && categoryMatch && typeMatch && favoritesMatch) {
                    item.style.display = 'block';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            }
        });

        // Update search suggestions with count
        updateSearchSuggestions(visibleCount, searchTerm);
    }

    function updateSearchSuggestions(count, searchTerm) {
        const searchSuggestions = document.getElementById('search-suggestions');
        if (!searchSuggestions) return;

        if (searchTerm) {
            searchSuggestions.innerHTML = `
                <div class="search-suggestion-item">
                    <i class="bi bi-search"></i>
                    <span>Mostrando ${count} resultados para "${searchTerm}"</span>
                </div>
            `;
            searchSuggestions.style.display = 'block';
        } else {
            searchSuggestions.style.display = 'none';
        }
    }

    function clearSearchResults() {
        const navItems = document.querySelectorAll('.nav-item');
        navItems.forEach(item => {
            item.style.display = 'block';
        });

        const searchSuggestions = document.getElementById('search-suggestions');
        if (searchSuggestions) {
            searchSuggestions.style.display = 'none';
        }
    }

    function applyCurrentFilters() {
        const searchInput = document.querySelector('.search-input');
        if (searchInput) {
            const searchTerm = searchInput.value.toLowerCase();
            const categoryFilter = document.getElementById('search-category-filter')?.value || '';
            const typeFilter = document.getElementById('search-type-filter')?.value || '';
            const favoritesOnly = document.getElementById('search-favorites-filter')?.checked || false;

            performAdvancedSearch(searchTerm, categoryFilter, typeFilter, favoritesOnly);
        }
    }

    window.clearSearchFilters = function () {
        const categoryFilter = document.getElementById('search-category-filter');
        const typeFilter = document.getElementById('search-type-filter');
        const favoritesFilter = document.getElementById('search-favorites-filter');

        if (categoryFilter) categoryFilter.value = '';
        if (typeFilter) typeFilter.value = '';
        if (favoritesFilter) favoritesFilter.checked = false;

        // Re-run search with cleared filters
        applyCurrentFilters();
    };

    window.applySearchFilters = function () {
        applyCurrentFilters();

        // Hide the filters panel after applying
        const filtersPanel = document.getElementById('advanced-search-filters');
        if (filtersPanel) {
            filtersPanel.style.display = 'none';
        }

        // Update filter icon
        const filterToggle = document.querySelector('.search-toggle-filters i');
        if (filterToggle) {
            filterToggle.className = 'bi bi-funnel';
        }
    };

    // Initialize advanced search when DOM is loaded
    document.addEventListener('DOMContentLoaded', function () {
        initializeAdvancedSearch();

        // Initialize team collaboration features
        initializeTeamCollaboration();
    });

    // Team Collaboration functionality
    function initializeTeamCollaboration() {
        // Update team member statuses periodically from real API
        setInterval(updateTeamMemberStatuses, 30000); // Update every 30 seconds

        // Initial status update
        updateTeamMemberStatuses();
    }

    function updateTeamMemberStatuses() {
        // Fetch real team status from API
        requestJson('/api/dashboard/team-status', {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(data => {
                if (data.success && data.team) {
                    displayTeamStatuses(data.team);
                }
            })
            .catch(error => {
                console.error('Error fetching team status:', error);
            });
    }

    function displayTeamStatuses(team) {
        const teamContainer = document.querySelector('.team-members-list');
        if (!teamContainer) return;

        // Update or create team member elements
        team.forEach(member => {
            let memberEl = teamContainer.querySelector(`[data-member-id="${member.id}"]`);

            if (!memberEl) {
                // Create new member element
                memberEl = document.createElement('div');
                memberEl.className = 'team-member';
                memberEl.setAttribute('data-member-id', member.id);
                teamContainer.appendChild(memberEl);
            }

            // Update status indicator
            const statusClass = member.status || 'offline';
            const statusTitles = {
                'online': 'Online',
                'away': 'Ausente',
                'busy': 'Ocupado',
                'offline': 'Offline'
            };

            memberEl.innerHTML = `
                <div class="team-member-avatar">
                    <span class="team-status-indicator ${statusClass}" title="${statusTitles[statusClass] || member.statusText}"></span>
                    <span class="avatar-initials">${member.name.substring(0, 2).toUpperCase()}</span>
                </div>
                <div class="team-member-info">
                    <div class="team-member-name">${member.name}</div>
                    <small class="text-muted">${member.statusText}</small>
                    ${member.pendingOrders > 0 ? `<span class="badge bg-warning ms-1">${member.pendingOrders} pedidos</span>` : ''}
                    ${member.pendingQuestions > 0 ? `<span class="badge bg-info ms-1">${member.pendingQuestions} perguntas</span>` : ''}
                </div>
            `;
        });
    }

    // Show team chat functionality
    window.showTeamChat = function () {
        // Create a modal for team chat
        const chatModal = document.createElement('div');
        chatModal.className = 'modal fade';
        chatModal.id = 'teamChatModal';
        chatModal.tabIndex = '-1';
        chatModal.innerHTML = `
            <div class="modal-dialog modal-dialog-scrollable" style="max-width: 500px;">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Chat da Equipe</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body" style="height: 400px; overflow-y: auto;">
                        <div class="chat-messages">
                            <div class="message sent">
                                <div class="message-content">
                                    <div class="message-text">Olá equipe! Como estão indo com os relatórios deste mês?</div>
                                    <small class="text-muted">Você • Agora</small>
                                </div>
                            </div>
                            <div class="message received">
                                <div class="message-content">
                                    <div class="message-text">Estou quase terminando o meu, João. Devo entregar até amanhã cedo.</div>
                                    <small class="text-muted">Maria • Há 2 min</small>
                                </div>
                            </div>
                            <div class="message received">
                                <div class="message-content">
                                    <div class="message-text">Já finalizei os meus dados, posso ajudar caso alguém precise.</div>
                                    <small class="text-muted">Carlos • Há 5 min</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <div class="input-group">
                            <input type="text" class="form-control" placeholder="Digite sua mensagem..." id="chatMessageInput">
                            <button class="btn btn-primary" type="button" id="sendMessageBtn">Enviar</button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(chatModal);

        // Initialize Bootstrap modal
        const modal = new bootstrap.Modal(chatModal);
        modal.show();

        // Add event listener to send message
        document.getElementById('sendMessageBtn').addEventListener('click', function () {
            const messageInput = document.getElementById('chatMessageInput');
            const message = messageInput.value.trim();

            if (message) {
                // Add message to chat (in a real implementation, this would send to server)
                const chatBody = chatModal.querySelector('.chat-messages');
                const messageDiv = document.createElement('div');
                messageDiv.className = 'message sent';
                messageDiv.innerHTML = `
                    <div class="message-content">
                        <div class="message-text">${message}</div>
                        <small class="text-muted">Você • Agora</small>
                    </div>
                `;

                chatBody.appendChild(messageDiv);
                messageInput.value = '';

                // Scroll to bottom
                chatBody.scrollTop = chatBody.scrollHeight;
            }
        });

        // Allow sending with Enter key
        document.getElementById('chatMessageInput').addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                document.getElementById('sendMessageBtn').click();
            }
        });

        // Remove modal from DOM when hidden
        chatModal.addEventListener('hidden.bs.modal', function () {
            document.body.removeChild(chatModal);
        });
    }

    // Function to add team member activity
    window.addTeamActivity = function (memberName, activity) {
        // In a real implementation, this would update team activity feeds
        console.log(`${memberName} realizou: ${activity}`);

        // Show a notification if the user is on the same page
        const notification = document.createElement('div');
        notification.className = 'team-activity-notification';
        notification.innerHTML = `
            <i class="bi bi-bell-fill text-primary"></i>
            <span><strong>${memberName}</strong> ${activity}</span>
        `;

        // Add to notification area if it exists
        const notificationArea = document.getElementById('sidebar-announcer');
        if (notificationArea) {
            notificationArea.textContent = `${memberName} ${activity}`;
            setTimeout(() => {
                notificationArea.textContent = '';
            }, 5000);
        }
    };

    // Audit Trail functionality
    function initializeAuditTrail() {
        // Load initial audit entries from API
        loadAuditTrail();

        // Add audit trail for navigation events (client-side tracking)
        document.addEventListener('click', function (e) {
            // Track clicks on navigation links
            if (e.target.closest('.nav-link')) {
                const linkText = e.target.closest('.nav-link').textContent.trim();
                addAuditEntry('Navegação', `Acessou: ${linkText}`, 'info');
            }
        });

        // Track search actions
        const searchInput = document.querySelector('.search-input');
        if (searchInput) {
            let searchTimeout;
            searchInput.addEventListener('input', function () {
                clearTimeout(searchTimeout);
                if (this.value.length > 2) {
                    searchTimeout = setTimeout(() => {
                        addAuditEntry('Busca', `Pesquisou por: ${this.value}`, 'info');
                    }, 1000);
                }
            });
        }

        // Track theme changes
        document.addEventListener('click', function (e) {
            if (e.target.closest('.theme-selector') || e.target.closest('[data-theme]')) {
                addAuditEntry('Preferências', 'Alterou tema', 'warning');
            }
        });
    }

    function loadAuditTrail(limit = 20) {
        requestJson(`/api/dashboard/audit-trail?limit=${limit}`, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(data => {
                if (data.success && data.entries) {
                    displayAuditEntries(data.entries);
                }
            })
            .catch(error => {
                console.error('Error loading audit trail:', error);
            });
    }

    function displayAuditEntries(entries) {
        const auditContainer = document.querySelector('.audit-trail-list');
        if (!auditContainer) return;

        auditContainer.innerHTML = entries.map(entry => {
            const severityClass = entry.severity === 'critical' ? 'danger' :
                entry.severity === 'warning' ? 'warning' : 'info';
            return `
                <div class="audit-entry audit-${entry.severity}">
                    <i class="bi bi-${entry.icon} text-${severityClass}"></i>
                    <div class="audit-content">
                        <div class="audit-action">${entry.actionHuman}</div>
                        <small class="text-muted">${entry.user} • ${entry.timeAgo}</small>
                        ${entry.entityId ? `<small class="text-muted d-block">${entry.entity}: ${entry.entityId}</small>` : ''}
                    </div>
                </div>
            `;
        }).join('');

        // Track settings changes
        document.addEventListener('change', function (e) {
            if (e.target.closest('.preferences-modal') || e.target.type === 'checkbox' || e.target.type === 'select-one') {
                addAuditEntry('Preferências', 'Alterou configurações', 'warning');
            }
        });
    }

    // Add an entry to the audit trail
    window.addAuditEntry = function (category, action, type = 'info') {
        const timestamp = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        // Create audit entry object
        const auditEntry = {
            id: Date.now(),
            timestamp: timestamp,
            category: category,
            action: action,
            type: type
        };

        // Store in localStorage (in a real app, this would go to a database)
        const auditLog = JSON.parse(localStorage.getItem('auditLog') || '[]');
        auditLog.unshift(auditEntry); // Add to beginning

        // Keep only the last 50 entries
        if (auditLog.length > 50) {
            auditLog.splice(50);
        }

        localStorage.setItem('auditLog', JSON.stringify(auditLog));

        // Update the UI if the audit log is visible
        updateAuditLogDisplay();

        // Also add to the sidebar audit trail if it exists
        updateSidebarAuditTrail();
    };

    // Update the audit log display in the sidebar
    function updateSidebarAuditTrail() {
        const auditLogElement = document.querySelector('.audit-log');
        if (!auditLogElement) return;

        const auditLog = JSON.parse(localStorage.getItem('auditLog') || '[]');
        const recentEntries = auditLog.slice(0, 3); // Show only 3 most recent

        auditLogElement.innerHTML = recentEntries.map(entry => {
            const typeColors = {
                'success': 'text-success',
                'info': 'text-info',
                'warning': 'text-warning',
                'danger': 'text-danger'
            };

            const colorClass = typeColors[entry.type] || 'text-muted';

            return `
                <div class="audit-entry">
                    <i class="bi bi-circle-fill ${colorClass}" style="font-size: 0.5rem;"></i>
                    <span class="text-truncate">${entry.action}</span>
                    <small class="text-muted">${entry.timestamp}</small>
                </div>
            `;
        }).join('');
    }

    // Update the full audit log display
    function updateAuditLogDisplay() {
        // This would update the full audit log view when the modal is open
        const fullAuditLog = document.getElementById('full-audit-log');
        if (fullAuditLog) {
            const auditLog = JSON.parse(localStorage.getItem('auditLog') || '[]');

            fullAuditLog.innerHTML = auditLog.map(entry => {
                const typeColors = {
                    'success': 'text-success',
                    'info': 'text-info',
                    'warning': 'text-warning',
                    'danger': 'text-danger'
                };

                const colorClass = typeColors[entry.type] || 'text-muted';

                return `
                    <div class="audit-log-entry d-flex align-items-center p-2 border-bottom">
                        <i class="bi bi-circle-fill ${colorClass} me-2" style="font-size: 0.6rem;"></i>
                        <div class="flex-grow-1">
                            <div class="fw-medium">${entry.category}: ${entry.action}</div>
                            <small class="text-muted">${entry.timestamp}</small>
                        </div>
                    </div>
                `;
            }).join('');
        }
    }

    // Show full audit trail modal
    window.showAuditTrail = function () {
        // Create modal for full audit trail
        const auditModal = document.createElement('div');
        auditModal.className = 'modal fade';
        auditModal.id = 'auditTrailModal';
        auditModal.tabIndex = '-1';
        auditModal.innerHTML = `
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Histórico de Auditoria</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <div class="audit-filters mb-3">
                            <div class="row">
                                <div class="col-md-4">
                                    <label class="form-label">Categoria</label>
                                    <select class="form-select" id="auditCategoryFilter">
                                        <option value="">Todas as categorias</option>
                                        <option value="Navegação">Navegação</option>
                                        <option value="Busca">Busca</option>
                                        <option value="Preferências">Preferências</option>
                                        <option value="Sistema">Sistema</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Tipo</label>
                                    <select class="form-select" id="auditTypeFilter">
                                        <option value="">Todos os tipos</option>
                                        <option value="success">Sucesso</option>
                                        <option value="info">Informação</option>
                                        <option value="warning">Aviso</option>
                                        <option value="danger">Erro</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Período</label>
                                    <select class="form-select" id="auditPeriodFilter">
                                        <option value="today">Hoje</option>
                                        <option value="week">Última semana</option>
                                        <option value="month">Último mês</option>
                                        <option value="all">Todo período</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div id="full-audit-log" class="audit-full-log">
                            <!-- Audit entries will be populated here -->
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                        <button type="button" class="btn btn-outline-danger" onclick="clearAuditTrail()">Limpar Histórico</button>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(auditModal);

        // Initialize modal
        const modal = new bootstrap.Modal(auditModal);
        modal.show();

        // Populate the audit log
        updateAuditLogDisplay();

        // Add filter event listeners
        document.getElementById('auditCategoryFilter').addEventListener('change', applyAuditFilters);
        document.getElementById('auditTypeFilter').addEventListener('change', applyAuditFilters);
        document.getElementById('auditPeriodFilter').addEventListener('change', applyAuditFilters);

        // Remove modal from DOM when hidden
        auditModal.addEventListener('hidden.bs.modal', function () {
            document.body.removeChild(auditModal);
        });
    }

    // Apply filters to audit trail
    function applyAuditFilters() {
        // In a real implementation, this would filter the audit log based on selected criteria
        // For demo, we'll just update the display
        updateAuditLogDisplay();
    }

    // Clear audit trail
    window.clearAuditTrail = function () {
        if (confirm('Tem certeza que deseja limpar o histórico de auditoria? Esta ação não pode ser desfeita.')) {
            localStorage.removeItem('auditLog');
            updateAuditLogDisplay();
            updateSidebarAuditTrail();

            // Show confirmation
            const announcer = document.getElementById('sidebar-announcer');
            if (announcer) {
                announcer.textContent = 'Histórico de auditoria limpo com sucesso';
                setTimeout(() => {
                    announcer.textContent = '';
                }, 3000);
            }
        }
    }

    // Initialize audit trail when DOM is loaded
    document.addEventListener('DOMContentLoaded', function () {
        initializeAuditTrail();
    });

    // Keyboard Shortcut Manager
    function initializeKeyboardShortcutManager() {
        // Define all available shortcuts
        window.keyboardShortcuts = {
            // Navigation shortcuts
            'ctrl+k': { action: openSearch, description: 'Abrir barra de busca' },
            'ctrl+,': { action: openSettings, description: 'Abrir configurações' },
            'ctrl+shift+h': { action: startDashboardTour, description: 'Iniciar tour do dashboard' },

            // Interface shortcuts
            'ctrl+shift+t': { action: toggleTheme, description: 'Alternar tema' },
            'ctrl+shift+l': { action: toggleLanguage, description: 'Alternar idioma' },
            'shift+/': { action: showKeyboardShortcuts, description: 'Mostrar atalhos de teclado' },

            // Quick actions
            'n': { action: createNewOrder, description: 'Criar novo pedido' },
            'm': { action: goToMessages, description: 'Ir para mensagens' },
            's': { action: saveCurrentPage, description: 'Salvar página atual' },

            // Additional shortcuts
            'esc': { action: closeOverlay, description: 'Fechar sobreposição' },
            '?': { action: showKeyboardShortcuts, description: 'Mostrar ajuda de atalhos' }
        };

        // Add global keyboard event listener
        document.addEventListener('keydown', handleGlobalKeyboardShortcuts);

        // Add specific shortcut for Shift+? to show shortcuts
        document.addEventListener('keydown', function (e) {
            if (e.shiftKey && e.key === '?' && !isInputElement(e.target)) {
                e.preventDefault();
                showKeyboardShortcuts();
            }
        });
    }

    // Handle global keyboard shortcuts
    function handleGlobalKeyboardShortcuts(e) {
        // Don't intercept shortcuts when in input elements
        if (isInputElement(e.target)) {
            // Special case: allow Ctrl+K to override search input
            if (e.ctrlKey && e.key === 'k' && e.target.classList.contains('search-input')) {
                // Don't prevent default for search input
                return;
            }

            // Only allow help shortcut (?) to work in inputs
            if (!(e.shiftKey && e.key === '?')) {
                return;
            }
        }

        // Create a normalized key combination string
        let keyCombo = '';
        if (e.ctrlKey) keyCombo += 'ctrl+';
        if (e.shiftKey) keyCombo += 'shift+';
        if (e.altKey) keyCombo += 'alt+';
        if (e.metaKey) keyCombo += 'meta+'; // Cmd key on Mac

        keyCombo += e.key.toLowerCase();

        // Look up the shortcut
        const shortcut = window.keyboardShortcuts[keyCombo];
        if (shortcut) {
            e.preventDefault();
            shortcut.action();
        }

        // Special case for ESC to close overlays
        if (e.key === 'Escape') {
            closeOverlay();
        }
    }

    // Check if target is an input element
    function isInputElement(element) {
        return element.tagName === 'INPUT' ||
            element.tagName === 'TEXTAREA' ||
            element.tagName === 'SELECT' ||
            element.contentEditable === 'true';
    }

    // Shortcut actions
    function openSearch() {
        const searchInput = document.querySelector('.search-input');
        if (searchInput) {
            searchInput.focus();
        }
    }

    function openSettings() {
        // Open settings modal
        showPreferencesPanel();
    }

    function startDashboardTour() {
        if (typeof tourManager !== 'undefined' && typeof tourManager.start !== 'undefined') {
            tourManager.start();
        } else if (typeof Shepherd !== 'undefined') {
            // Fallback to Shepherd tour
            const tour = new Shepherd.Tour({
                defaultStepOptions: {
                    classes: 'shadow-lg',
                    scrollTo: true
                }
            });

            tour.addStep({
                id: 'dashboard-overview',
                title: 'Tour do Dashboard',
                text: 'Este é o seu painel principal. Use as teclas de atalho para navegar mais rapidamente.',
                attachTo: {
                    element: '.brand-logo',
                    on: 'bottom'
                },
                buttons: [
                    {
                        text: 'Próximo',
                        action: tour.next
                    }
                ]
            });

            tour.start();
        }
    }

    function toggleTheme() {
        const currentTheme = localStorage.getItem('selectedTheme') || 'light';
        const newTheme = currentTheme === 'light' ? 'dark' : 'light';

        if (typeof themeManager !== 'undefined' && typeof themeManager.applyTheme === 'function') {
            themeManager.applyTheme(newTheme);
        } else {
            // Fallback: try to toggle theme class on document
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('selectedTheme', newTheme);
        }
    }

    function toggleLanguage() {
        // Cycle through available languages
        const languages = ['pt', 'en', 'es'];
        const currentLang = localStorage.getItem('selectedLanguage') || 'pt';
        const currentIndex = languages.indexOf(currentLang);
        const nextIndex = (currentIndex + 1) % languages.length;
        const nextLang = languages[nextIndex];

        if (typeof changeLanguage === 'function') {
            changeLanguage(nextLang);
        } else {
            localStorage.setItem('selectedLanguage', nextLang);
            // Reload page to apply new language
            window.location.reload();
        }
    }

    function createNewOrder() {
        window.location.href = '/dashboard/orders/new';
    }

    function goToMessages() {
        window.location.href = '/dashboard/messages';
    }

    function saveCurrentPage() {
        // Trigger save action - this would depend on the current page
        // For now, we'll just show a notification
        if (typeof Toast !== 'undefined') {
            Toast.success('Página salva com sucesso!');
        } else {
            alert('Página salva com sucesso!');
        }
    }

    // Show keyboard shortcuts overlay
    function showKeyboardShortcuts() {
        const overlay = document.getElementById('keyboard-shortcuts-overlay');
        if (overlay) {
            overlay.style.display = 'flex';
            overlay.setAttribute('aria-hidden', 'false');

            // Focus the close button for accessibility
            const closeButton = overlay.querySelector('.btn-close');
            if (closeButton) {
                setTimeout(() => closeButton.focus(), 100);
            }
        }
    }

    // Close overlay
    function closeOverlay() {
        const overlay = document.getElementById('keyboard-shortcuts-overlay');
        if (overlay) {
            overlay.style.display = 'none';
            overlay.setAttribute('aria-hidden', 'true');
        }
    }

    // Function called by the close button
    window.closeShortcutOverlay = function () {
        closeOverlay();
    };

    // Add click outside to close functionality
    document.addEventListener('click', function (e) {
        const overlay = document.getElementById('keyboard-shortcuts-overlay');
        if (overlay && overlay.style.display !== 'none' && e.target === overlay) {
            closeOverlay();
        }
    });

    // Initialize keyboard shortcut manager when DOM is loaded
    document.addEventListener('DOMContentLoaded', function () {
        initializeKeyboardShortcutManager();

        // Initialize smart notifications
        initializeSmartNotifications();
    });

    // Smart Notifications with AI Prioritization
    function initializeSmartNotifications() {
        // Load smart notifications from localStorage or API
        loadSmartNotifications();

        // Update notifications every 30 seconds
        setInterval(loadSmartNotifications, 30000);

        // Initialize notification priority algorithm
        initializeNotificationPriorityEngine();
    }

    function initializeNotificationPriorityEngine() {
        // In a real implementation, this would connect to an AI service
        // For demo, we'll simulate an intelligent prioritization algorithm

        // Store user interaction patterns to improve prioritization
        window.notificationInteractions = JSON.parse(localStorage.getItem('notificationInteractions') || '{}');

        // Function to calculate notification priority based on multiple factors
        window.calculateNotificationPriority = function (notification) {
            let priorityScore = 0;

            // Base priority factors
            if (notification.urgent) priorityScore += 50;
            if (notification.type === 'critical') priorityScore += 40;
            if (notification.type === 'alert') priorityScore += 30;
            if (notification.type === 'warning') priorityScore += 20;

            // Time sensitivity
            const now = new Date();
            const notificationTime = new Date(notification.timestamp);
            const timeDiff = (now - notificationTime) / (1000 * 60); // Minutes

            if (timeDiff < 5) priorityScore += 15; // Recent notifications get priority
            else if (timeDiff < 15) priorityScore += 10;
            else if (timeDiff < 60) priorityScore += 5;

            // User behavior patterns
            if (notificationInteractions[notification.id]) {
                const interaction = notificationInteractions[notification.id];
                if (interaction.clicked) priorityScore += 20;
                if (interaction.ignored > 3) priorityScore -= 15; // Lower priority if often ignored
            }

            // Topic relevance based on user preferences
            const userPreferences = JSON.parse(localStorage.getItem('userPreferences') || '{}');
            if (userPreferences.interests && Array.isArray(userPreferences.interests)) {
                if (userPreferences.interests.some(interest =>
                    notification.title.toLowerCase().includes(interest.toLowerCase()) ||
                    notification.message.toLowerCase().includes(interest.toLowerCase()))) {
                    priorityScore += 25;
                }
            }

            // Business impact factors
            if (notification.category === 'sales') priorityScore += 15;
            if (notification.category === 'inventory') priorityScore += 10;
            if (notification.category === 'customer') priorityScore += 12;

            return priorityScore;
        };
    }

    function loadSmartNotifications() {
        // In a real implementation, this would fetch from an API
        // For demo, we'll use mock data with priorities calculated

        // Fetch real notifications from API
        requestJson('/api/dashboard/notifications', {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(data => {
                if (data.success && data.notifications) {
                    // Calculate priorities for each notification
                    const notifications = data.notifications.map(notification => {
                        // Convert timestamp string to Date object if needed
                        if (typeof notification.timestamp === 'string') {
                            notification.timestamp = new Date(notification.timestamp);
                        }
                        notification.priorityScore = calculateNotificationPriority(notification);
                        return notification;
                    });

                    // Sort by priority score (highest first)
                    notifications.sort((a, b) => b.priorityScore - a.priorityScore);

                    displaySmartNotifications(notifications);
                } else {
                    console.warn('No notifications data available');
                    displaySmartNotifications([]);
                }
            })
            .catch(error => {
                console.error('Error fetching smart notifications:', error);
                displaySmartNotifications([]);
            });
    }

    function displaySmartNotifications(notifications) {
        const notificationsList = document.getElementById('smart-notifications-list');
        const notificationsCount = document.getElementById('smart-notifications-count');

        if (!notificationsList || !notificationsCount) return;

        // Filter unread notifications for count
        const unreadCount = notifications.filter(n => !n.read).length;
        notificationsCount.textContent = unreadCount;

        // Display top 3 notifications
        const topNotifications = notifications.slice(0, 3);

        notificationsList.innerHTML = topNotifications.map(notification => {
            // Determine priority class based on calculated score
            let priorityClass = 'priority-low';
            if (notification.priorityScore >= 50) {
                priorityClass = 'priority-high';
            } else if (notification.priorityScore >= 25) {
                priorityClass = 'priority-medium';
            }

            // Format timestamp
            const timeDiff = Math.floor((new Date() - new Date(notification.timestamp)) / (1000 * 60));
            let timeString;
            if (timeDiff < 1) {
                timeString = 'Agora';
            } else if (timeDiff < 60) {
                timeString = `Há ${timeDiff} min`;
            } else if (timeDiff < 1440) { // Less than a day
                const hours = Math.floor(timeDiff / 60);
                timeString = `Há ${hours}h`;
            } else {
                const days = Math.floor(timeDiff / 1440);
                timeString = `Há ${days}d`;
            }

            // Get appropriate icon based on notification type
            let iconClass = 'bi-info-circle text-info';
            if (notification.type === 'critical') iconClass = 'bi-exclamation-circle text-danger';
            else if (notification.type === 'warning') iconClass = 'bi-exclamation-triangle text-warning';
            else if (notification.type === 'success') iconClass = 'bi-check-circle text-success';

            return `
                <div class="smart-notification-item ${priorityClass}" data-notification-id="${notification.id}" onclick="handleNotificationClick(${notification.id})">
                    <i class="smart-notification-icon bi ${iconClass}"></i>
                    <div class="smart-notification-content">
                        <div class="smart-notification-title">
                            <span>${notification.title}</span>
                            ${!notification.read ? '<span class="badge bg-danger">NOVO</span>' : ''}
                        </div>
                        <div class="smart-notification-message">${notification.message}</div>
                        <div class="smart-notification-time">${timeString}</div>
                    </div>
                </div>
            `;
        }).join('');
    }

    function handleNotificationClick(notificationId) {
        // Mark notification as clicked/read
        const notificationElement = document.querySelector(`[data-notification-id="${notificationId}"]`);
        if (notificationElement) {
            // Remove the 'NEW' badge
            const badge = notificationElement.querySelector('.badge');
            if (badge) {
                badge.remove();
            }

            // Update notification interactions in localStorage
            if (!window.notificationInteractions[notificationId]) {
                window.notificationInteractions[notificationId] = {};
            }
            window.notificationInteractions[notificationId].clicked = true;
            window.notificationInteractions[notificationId].clickTime = new Date().toISOString();

            localStorage.setItem('notificationInteractions', JSON.stringify(window.notificationInteractions));
        }

        // In a real implementation, this would navigate to the relevant page
        // For demo, we'll just show an alert
        alert(`Notificação ${notificationId} aberta. Em uma implementação real, isso abriria os detalhes da notificação.`);
    }

    window.showSmartNotifications = function () {
        // Create modal to show all notifications
        const notificationsModal = document.createElement('div');
        notificationsModal.className = 'modal fade';
        notificationsModal.id = 'smartNotificationsModal';
        notificationsModal.tabIndex = '-1';
        notificationsModal.innerHTML = `
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Notificações Inteligentes</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <div class="notifications-filter mb-3">
                            <div class="row">
                                <div class="col-md-4">
                                    <select class="form-select" id="notificationTypeFilter">
                                        <option value="all">Todos os tipos</option>
                                        <option value="critical">Críticas</option>
                                        <option value="warning">Avisos</option>
                                        <option value="info">Informações</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <select class="form-select" id="notificationCategoryFilter">
                                        <option value="all">Todas as categorias</option>
                                        <option value="sales">Vendas</option>
                                        <option value="inventory">Estoque</option>
                                        <option value="customer">Clientes</option>
                                        <option value="system">Sistema</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <select class="form-select" id="notificationPriorityFilter">
                                        <option value="all">Todas as prioridades</option>
                                        <option value="high">Alta</option>
                                        <option value="medium">Média</option>
                                        <option value="low">Baixa</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div id="all-smart-notifications" class="notifications-list">
                            <!-- All notifications will be populated here -->
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                        <button type="button" class="btn btn-outline-primary" onclick="markAllAsRead()">Marcar todas como lidas</button>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(notificationsModal);

        // Initialize modal
        const modal = new bootstrap.Modal(notificationsModal);
        modal.show();

        // Populate all notifications
        loadAndDisplayAllNotifications();

        // Add filter event listeners
        document.getElementById('notificationTypeFilter').addEventListener('change', loadAndDisplayAllNotifications);
        document.getElementById('notificationCategoryFilter').addEventListener('change', loadAndDisplayAllNotifications);
        document.getElementById('notificationPriorityFilter').addEventListener('change', loadAndDisplayAllNotifications);

        // Remove modal from DOM when hidden
        notificationsModal.addEventListener('hidden.bs.modal', function () {
            document.body.removeChild(notificationsModal);
        });
    };

    function loadAndDisplayAllNotifications() {
        // In a real implementation, this would fetch from API with filters
        // For demo, we'll use the same mock data
        loadSmartNotifications();

        // Get filters
        const typeFilter = document.getElementById('notificationTypeFilter')?.value || 'all';
        const categoryFilter = document.getElementById('notificationCategoryFilter')?.value || 'all';
        const priorityFilter = document.getElementById('notificationPriorityFilter')?.value || 'all';

        // This would be implemented in a real application to filter the notifications
        // based on the selected filters
    }

    window.markAllAsRead = function () {
        // In a real implementation, this would make an API call
        // For demo, we'll just update the UI
        const notificationItems = document.querySelectorAll('.smart-notification-item');
        notificationItems.forEach(item => {
            const badge = item.querySelector('.badge');
            if (badge && badge.textContent === 'NOVO') {
                badge.remove();
            }
        });

        // Update the count
        const notificationsCount = document.getElementById('smart-notifications-count');
        if (notificationsCount) {
            notificationsCount.textContent = '0';
        }

        // Show confirmation
        const announcer = document.getElementById('sidebar-announcer');
        if (announcer) {
            announcer.textContent = 'Todas as notificações foram marcadas como lidas';
            setTimeout(() => {
                announcer.textContent = '';
            }, 3000);
        }
    };

    // Contextual Help Tooltips with Interactive Demos
    function initializeContextualHelp() {
        // Define contextual help content for different elements
        window.contextualHelpContent = {
            '.brand-logo': {
                title: 'Dashboard Principal',
                content: 'Este é o seu painel principal. Aqui você encontra resumos importantes e atalhos para as funções mais utilizadas.',
                placement: 'right',
                interactiveDemo: [
                    { step: 1, instruction: 'Este é o logotipo do sistema' },
                    { step: 2, instruction: 'Clicar aqui sempre leva ao dashboard principal' }
                ]
            },
            '.search-input': {
                title: 'Busca Rápida',
                content: 'Use esta barra de pesquisa para encontrar rapidamente qualquer funcionalidade no sistema.',
                placement: 'bottom',
                interactiveDemo: [
                    { step: 1, instruction: 'Digite palavras-chave para pesquisar funcionalidades' },
                    { step: 2, instruction: 'Use os filtros avançados para refinar sua busca' }
                ]
            },
            '.nav-link[href="/dashboard/orders"]': {
                title: 'Gestão de Pedidos',
                content: 'Acompanhe todos os seus pedidos, desde a criação até a entrega.',
                placement: 'right',
                interactiveDemo: [
                    { step: 1, instruction: 'Veja o número de pedidos pendentes' },
                    { step: 2, instruction: 'Clique para acessar a gestão completa de pedidos' }
                ]
            },
            '.nav-link[href="/dashboard/messages"]': {
                title: 'Central de Mensagens',
                content: 'Gerencie todas as comunicações com clientes e fornecedores.',
                placement: 'right',
                interactiveDemo: [
                    { step: 1, instruction: 'Verifique mensagens não lidas' },
                    { step: 2, instruction: 'Responda diretamente da sidebar' }
                ]
            },
            '#themeDropdown': {
                title: 'Personalização de Tema',
                content: 'Escolha entre diferentes temas para personalizar sua experiência.',
                placement: 'bottom',
                interactiveDemo: [
                    { step: 1, instruction: 'Clique para abrir o seletor de temas' },
                    { step: 2, instruction: 'Selecione o tema que melhor se adapta à sua preferência' }
                ]
            },
            '#helpDropdown': {
                title: 'Centro de Ajuda',
                content: 'Acesse tutoriais, atalhos de teclado e documentação.',
                placement: 'bottom',
                interactiveDemo: [
                    { step: 1, instruction: 'Clique para abrir o menu de ajuda' },
                    { step: 2, instruction: 'Acesse o tour do dashboard ou atalhos de teclado' }
                ]
            }
        };

        // Add event listeners to elements that have contextual help
        Object.keys(contextualHelpContent).forEach(selector => {
            const elements = document.querySelectorAll(selector);
            elements.forEach(element => {
                // Mouse enter event to show tooltip
                element.addEventListener('mouseenter', function () {
                    showContextualTooltip(this, selector);
                });

                // Mouse leave event to hide tooltip
                element.addEventListener('mouseleave', function () {
                    // Add a small delay to prevent flickering
                    setTimeout(() => {
                        if (!this.matches(':hover')) {
                            hideContextualTooltip();
                        }
                    }, 300);
                });

                // Focus event for accessibility
                element.addEventListener('focus', function () {
                    showContextualTooltip(this, selector);
                });

                // Blur event for accessibility
                element.addEventListener('blur', function () {
                    // Only hide if the element losing focus is not part of the tooltip
                    setTimeout(() => {
                        const activeElement = document.activeElement;
                        const tooltip = document.getElementById('contextual-tooltip');
                        if (tooltip && !tooltip.contains(activeElement)) {
                            hideContextualTooltip();
                        }
                    }, 100);
                });
            });
        });

        // Add keyboard shortcut to show contextual help overview
        document.addEventListener('keydown', function (e) {
            if (e.ctrlKey && e.shiftKey && e.key === 'H') {
                e.preventDefault();
                showContextualHelpOverview();
            }
        });
    }

    // Show contextual tooltip
    function showContextualTooltip(element, selector) {
        // Remove any existing tooltips
        hideContextualTooltip();

        const helpData = contextualHelpContent[selector];
        if (!helpData) return;

        // Get element position
        const rect = element.getBoundingClientRect();
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;

        // Create tooltip element
        const tooltip = document.createElement('div');
        tooltip.className = 'contextual-tooltip';
        tooltip.id = 'contextual-tooltip';
        tooltip.setAttribute('role', 'tooltip');

        // Position the tooltip based on the specified placement
        let top, left;
        const tooltipHeight = 200; // Approximate height
        const tooltipWidth = 250;  // Approximate width

        switch (helpData.placement) {
            case 'top':
                top = rect.top + scrollTop - tooltipHeight - 10;
                left = rect.left + scrollLeft + (rect.width / 2) - (tooltipWidth / 2);
                break;
            case 'bottom':
                top = rect.bottom + scrollTop + 10;
                left = rect.left + scrollLeft + (rect.width / 2) - (tooltipWidth / 2);
                break;
            case 'left':
                top = rect.top + scrollTop + (rect.height / 2) - (tooltipHeight / 2);
                left = rect.left + scrollLeft - tooltipWidth - 10;
                break;
            case 'right':
            default:
                top = rect.top + scrollTop + (rect.height / 2) - (tooltipHeight / 2);
                left = rect.right + scrollLeft + 10;
                break;
        }

        // Adjust if tooltip goes off-screen
        if (left < 20) left = 20;
        if (left + tooltipWidth > window.innerWidth - 20) left = window.innerWidth - tooltipWidth - 20;
        if (top < 20) top = 20;

        tooltip.style.top = `${top}px`;
        tooltip.style.left = `${left}px`;

        // Build tooltip content
        tooltip.innerHTML = `
            <div class="tooltip-header">
                <h6 class="tooltip-title">${helpData.title}</h6>
                <button class="tooltip-close" aria-label="Fechar ajuda">&times;</button>
            </div>
            <div class="tooltip-content">
                <p>${helpData.content}</p>

                ${helpData.interactiveDemo ? `
                <div class="interactive-demo">
                    <h6 class="text-primary">Demonstração Interativa:</h6>
                    ${helpData.interactiveDemo.map(step => `
                        <div class="demo-step">
                            <span class="demo-step-number">${step.step}</span>
                            <span>${step.instruction}</span>
                        </div>
                    `).join('')}
                </div>
                ` : ''}

                <div class="tooltip-actions">
                    <button class="btn btn-sm btn-outline-primary" onclick="startInteractiveDemo('${selector}')">Tentar</button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="hideContextualTooltip()">Fechar</button>
                </div>
            </div>
            <div class="tooltip-pointer" data-placement="${helpData.placement}"></div>
        `;

        // Add to container
        const container = document.getElementById('contextual-help-container');
        if (container) {
            container.appendChild(tooltip);

            // Show the tooltip with animation
            setTimeout(() => {
                tooltip.classList.add('visible');
            }, 10);

            // Add event listener to close button
            const closeButton = tooltip.querySelector('.tooltip-close');
            if (closeButton) {
                closeButton.addEventListener('click', hideContextualTooltip);
            }
        }
    }

    // Hide contextual tooltip
    function hideContextualTooltip() {
        const existingTooltip = document.getElementById('contextual-tooltip');
        if (existingTooltip) {
            existingTooltip.classList.remove('visible');
            setTimeout(() => {
                if (existingTooltip.parentNode) {
                    existingTooltip.parentNode.removeChild(existingTooltip);
                }
            }, 200);
        }
    }

    // Show contextual help overview
    function showContextualHelpOverview() {
        // Create modal with all available help topics
        const helpModal = document.createElement('div');
        helpModal.className = 'modal fade';
        helpModal.id = 'contextual-help-modal';
        helpModal.tabIndex = '-1';
        helpModal.innerHTML = `
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Ajuda Contextual</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <p>Esta funcionalidade fornece ajuda contextual diretamente nos elementos da interface.</p>
                        <p>Passar o mouse sobre elementos importantes mostrará dicas de ajuda com demonstrações interativas.</p>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            Use <kbd>Ctrl</kbd> + <kbd>Shift</kbd> + <kbd>H</kbd> para ativar a ajuda contextual em qualquer momento.
                        </div>
                        <h6>Elementos com ajuda disponível:</h6>
                        <ul class="list-group">
                            <li class="list-group-item d-flex justify-content-between align-items-start">
                                <div class="ms-2 me-auto">
                                    <div class="fw-bold">Logotipo do Dashboard</div>
                                    <small>Informações sobre o painel principal</small>
                                </div>
                                <span class="badge bg-primary rounded-pill">1</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-start">
                                <div class="ms-2 me-auto">
                                    <div class="fw-bold">Barra de Pesquisa</div>
                                    <small>Como usar a busca rápida</small>
                                </div>
                                <span class="badge bg-primary rounded-pill">2</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-start">
                                <div class="ms-2 me-auto">
                                    <div class="fw-bold">Links de Navegação</div>
                                    <small>Ajuda para funcionalidades específicas</small>
                                </div>
                                <span class="badge bg-primary rounded-pill">3</span>
                            </li>
                        </ul>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                        <button type="button" class="btn btn-primary" onclick="startGuidedTour()">Iniciar Tour Guiado</button>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(helpModal);

        // Show modal
        const modal = new bootstrap.Modal(helpModal);
        modal.show();

        // Remove modal from DOM when hidden
        helpModal.addEventListener('hidden.bs.modal', function () {
            document.body.removeChild(helpModal);
        });
    }

    // Start interactive demo for a specific element
    window.startInteractiveDemo = function (selector) {
        const element = document.querySelector(selector);
        if (element) {
            // Highlight the element
            element.classList.add('contextual-help-highlight');

            // Scroll to element if needed
            element.scrollIntoView({ behavior: 'smooth', block: 'center' });

            // In a real implementation, this would guide the user through an interactive demo
            // For now, we'll just show a message
            alert(`Iniciando demonstração interativa para: ${contextualHelpContent[selector]?.title || selector}`);

            // Remove highlight after delay
            setTimeout(() => {
                element.classList.remove('contextual-help-highlight');
            }, 3000);
        }
    };

    // Start a guided tour using contextual help
    window.startGuidedTour = function () {
        // This would start a step-by-step tour using the contextual help definitions
        alert('Tour guiado iniciado. Em uma implementação real, isso guiaría o usuário através das principais funcionalidades.');
    };

    // Initialize contextual help when DOM is loaded
    document.addEventListener('DOMContentLoaded', function () {
        initializeContextualHelp();
    });

    // AI-Powered Insights Panel
    function initializeAiInsightsPanel() {
        const aiInsightsToggle = document.getElementById('ai-insights-toggle');
        const aiInsightsPanel = document.getElementById('ai-insights-panel');
        const closeAiInsights = document.getElementById('close-ai-insights');

        if (aiInsightsToggle) {
            // Toggle AI insights panel
            aiInsightsToggle.addEventListener('click', function () {
                if (aiInsightsPanel.style.display === 'none' || aiInsightsPanel.style.display === '') {
                    aiInsightsPanel.style.display = 'block';
                    // Load AI insights when panel is opened
                    loadAiInsights();
                } else {
                    aiInsightsPanel.style.display = 'none';
                }
            });
        }

        if (closeAiInsights) {
            // Close AI insights panel
            closeAiInsights.addEventListener('click', function () {
                aiInsightsPanel.style.display = 'none';
            });
        }

        // Initialize with panel hidden
        if (aiInsightsPanel) {
            aiInsightsPanel.style.display = 'none';
        }
    }

    // Load AI insights data
    function loadAiInsights() {
        // Fetch real AI insights from API
        requestJson('/api/dashboard/ai-insights', {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(data => {
                if (data.success && data.insights) {
                    displayAiInsights(data.insights);
                } else {
                    console.warn('No AI insights data available');
                    displayAiInsights([]);
                }
            })
            .catch(error => {
                console.error('Error fetching AI insights:', error);
                displayAiInsights([]);
            });
    }

    // Display AI insights
    function displayAiInsights(insights) {
        const insightsContent = document.querySelector('.ai-insights-content');
        if (!insightsContent) return;

        insightsContent.innerHTML = insights.map(insight => {
            // Determine color based on priority
            let iconColor = 'text-muted';
            if (insight.priority === 'high') {
                iconColor = 'text-danger';
            } else if (insight.priority === 'medium') {
                iconColor = 'text-warning';
            } else if (insight.priority === 'low') {
                iconColor = 'text-success';
            }

            return `
                <div class="ai-insight-item" data-insight-id="${insight.id}">
                    <i class="ai-insight-icon bi ${insight.icon} ${iconColor}"></i>
                    <div class="ai-insight-text">
                        <div class="ai-insight-title">${insight.title}</div>
                        <div class="ai-insight-description">${insight.description}</div>
                        <div class="ai-insight-recommendation text-muted small mt-1">
                            <i class="bi bi-arrow-return-right"></i> ${insight.recommendation}
                        </div>
                        <div class="ai-insight-confidence text-xs mt-1">
                            <small class="text-muted">Confiança: ${insight.confidence}%</small>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    // View detailed AI report
    window.viewDetailedAiReport = function () {
        // In a real implementation, this would navigate to a detailed AI insights report
        // For demo, we'll show an alert
        alert('Relatório detalhado de IA. Em uma implementação real, isso abriria um painel com insights analíticos avançados e recomendações baseadas em IA.');
    };

    // Refresh AI insights
    window.refreshAiInsights = function () {
        loadAiInsights();
    };

    // Initialize AI insights panel when DOM is loaded
    document.addEventListener('DOMContentLoaded', function () {
        initializeAiInsightsPanel();

        // Initialize touch gestures for mobile devices
        initializeTouchGestures();
    });

    // Touch Gesture Controls for Mobile Devices
    function initializeTouchGestures() {
        // Variables to track touch events
        let touchStartX = 0;
        let touchStartY = 0;
        let touchEndX = 0;
        let touchEndY = 0;

        // Add touch event listeners to the entire document for swipe gestures
        document.addEventListener('touchstart', function (event) {
            touchStartX = event.changedTouches[0].screenX;
            touchStartY = event.changedTouches[0].screenY;
        }, false);

        document.addEventListener('touchend', function (event) {
            touchEndX = event.changedTouches[0].screenX;
            touchEndY = event.changedTouches[0].screenY;
            handleSwipeGesture();
        }, false);

        // Handle swipe gestures
        function handleSwipeGesture() {
            const MIN_SWIPE_DISTANCE = 50; // Minimum distance in pixels to register as swipe
            const deltaX = touchEndX - touchStartX;
            const deltaY = touchEndY - touchStartY;

            // Determine dominant direction (horizontal vs vertical)
            if (Math.abs(deltaX) > Math.abs(deltaY)) {
                // Horizontal swipe
                if (Math.abs(deltaX) > MIN_SWIPE_DISTANCE) {
                    if (deltaX > 0) {
                        // Swipe right - could open sidebar on mobile
                        handleSwipeRight();
                    } else {
                        // Swipe left - could close sidebar
                        handleSwipeLeft();
                    }
                }
            } else {
                // Vertical swipe
                if (Math.abs(deltaY) > MIN_SWIPE_DISTANCE) {
                    if (deltaY > 0) {
                        // Swipe down - could show notifications
                        handleSwipeDown();
                    } else {
                        // Swipe up - could hide notifications or show search
                        handleSwipeUp();
                    }
                }
            }
        }

        // Swipe right handler - opens sidebar on mobile
        function handleSwipeRight() {
            // Only on mobile screens
            if (window.innerWidth < 768) {
                const sidebar = document.querySelector('.sidebar');
                if (sidebar && !sidebar.classList.contains('open')) {
                    sidebar.classList.add('open');

                    // Announce to screen readers
                    const announcer = document.getElementById('sidebar-announcer');
                    if (announcer) {
                        announcer.textContent = 'Menu aberto com gesto de toque';
                        setTimeout(() => {
                            announcer.textContent = '';
                        }, 3000);
                    }
                }
            }
        }

        // Swipe left handler - closes sidebar
        function handleSwipeLeft() {
            // Only on mobile screens
            if (window.innerWidth < 768) {
                const sidebar = document.querySelector('.sidebar');
                if (sidebar && sidebar.classList.contains('open')) {
                    sidebar.classList.remove('open');

                    // Announce to screen readers
                    const announcer = document.getElementById('sidebar-announcer');
                    if (announcer) {
                        announcer.textContent = 'Menu fechado com gesto de toque';
                        setTimeout(() => {
                            announcer.textContent = '';
                        }, 3000);
                    }
                }
            }
        }

        // Swipe down handler - shows notifications
        function handleSwipeDown() {
            // Could show notifications panel or quick actions
            const notificationsPanel = document.getElementById('smart-notifications-panel');
            if (notificationsPanel) {
                notificationsPanel.style.display = 'block';

                // Announce to screen readers
                const announcer = document.getElementById('sidebar-announcer');
                if (announcer) {
                    announcer.textContent = 'Painel de notificações aberto com gesto de toque';
                    setTimeout(() => {
                        announcer.textContent = '';
                    }, 3000);
                }
            }
        }

        // Swipe up handler - could hide panels or show search
        function handleSwipeUp() {
            // Could hide notifications panel or show search bar
            const notificationsPanel = document.getElementById('smart-notifications-panel');
            if (notificationsPanel) {
                notificationsPanel.style.display = 'none';

                // Announce to screen readers
                const announcer = document.getElementById('sidebar-announcer');
                if (announcer) {
                    announcer.textContent = 'Painel de notificações fechado com gesto de toque';
                    setTimeout(() => {
                        announcer.textContent = '';
                    }, 3000);
                }
            }
        }

        // Add pinch gesture detection for zoom controls
        let initialDistance = null;

        document.addEventListener('touchstart', function (event) {
            if (event.touches.length === 2) {
                initialDistance = getDistance(event.touches[0], event.touches[1]);
            }
        }, { passive: true });

        document.addEventListener('touchmove', function (event) {
            if (event.touches.length === 2 && initialDistance !== null) {
                event.preventDefault(); // Prevent default browser zoom
                const currentDistance = getDistance(event.touches[0], event.touches[1]);

                if (currentDistance > initialDistance * 1.5) {
                    // Pinch out - zoom in gesture
                    handlePinchOut();
                    initialDistance = currentDistance;
                } else if (currentDistance < initialDistance * 0.5) {
                    // Pinch in - zoom out gesture
                    handlePinchIn();
                    initialDistance = currentDistance;
                }
            }
        }, { passive: false });

        document.addEventListener('touchend', function (event) {
            if (event.touches.length < 2) {
                initialDistance = null;
            }
        }, { passive: true });

        // Helper function to calculate distance between two touch points
        function getDistance(touch1, touch2) {
            return Math.sqrt(
                Math.pow(touch2.screenX - touch1.screenX, 2) +
                Math.pow(touch2.screenY - touch1.screenY, 2)
            );
        }

        // Pinch out handler - zoom in
        function handlePinchOut() {
            // Could increase UI scale or font size
            const currentScale = parseFloat(document.documentElement.style.getPropertyValue('--ui-scale') || '1');
            const newScale = Math.min(currentScale + 0.1, 1.5); // Max 150% scaling

            document.documentElement.style.setProperty('--ui-scale', newScale);

            // Announce to screen readers
            const announcer = document.getElementById('sidebar-announcer');
            if (announcer) {
                announcer.textContent = `Zoom aumentado para ${(newScale * 100).toFixed(0)}%`;
                setTimeout(() => {
                    announcer.textContent = '';
                }, 3000);
            }
        }

        // Pinch in handler - zoom out
        function handlePinchIn() {
            // Could decrease UI scale or font size
            const currentScale = parseFloat(document.documentElement.style.getPropertyValue('--ui-scale') || '1');
            const newScale = Math.max(currentScale - 0.1, 0.8); // Min 80% scaling

            document.documentElement.style.setProperty('--ui-scale', newScale);

            // Announce to screen readers
            const announcer = document.getElementById('sidebar-announcer');
            if (announcer) {
                announcer.textContent = `Zoom reduzido para ${(newScale * 100).toFixed(0)}%`;
                setTimeout(() => {
                    announcer.textContent = '';
                }, 3000);
            }
        }

        // Add double-tap gesture for quick actions
        let lastTap = 0;

        document.addEventListener('touchend', function (event) {
            const currentTime = new Date().getTime();
            const tapLength = currentTime - lastTap;

            if (tapLength < 500 && tapLength > 0) {
                // Double tap detected
                handleDoubleTap(event);
                event.preventDefault();
            }

            lastTap = currentTime;
        }, false);

        // Handle double tap gesture
        function handleDoubleTap(event) {
            // Determine what element was double-tapped
            const tappedElement = event.target;

            // If it's a nav link, maybe trigger a quick action
            if (tappedElement.closest('.nav-link')) {
                // Could trigger a quick preview or favorite action
                tappedElement.classList.add('double-tapped');
                setTimeout(() => {
                    tappedElement.classList.remove('double-tapped');
                }, 300);
            }
            // Could add other double-tap actions for different elements
        }
    }

    // Predictive Search with Machine Learning functionality
    function initializePredictiveSearch() {
        const searchInput = document.querySelector('.search-input');
        const searchResults = document.getElementById('search-results');
        const searchPopular = document.getElementById('search-popular');
        const searchRecent = document.getElementById('search-recent');
        const searchClearBtn = document.querySelector('.search-clear-btn');

        if (!searchInput) return;

        // Load popular and recent searches
        loadPopularSearches();
        loadRecentSearches();

        // Add event listeners
        searchInput.addEventListener('input', handleSearchInput);
        searchInput.addEventListener('focus', function () {
            if (this.value.trim() === '') {
                showPopularAndRecentSearches();
            }
        });
        searchInput.addEventListener('blur', function () {
            // Delay hiding to allow click on results
            setTimeout(() => {
                const activeElement = document.activeElement;
                if (!searchResults.contains(activeElement)) {
                    hideSearchSuggestions();
                }
            }, 200);
        });

        // Clear button functionality
        if (searchClearBtn) {
            searchClearBtn.addEventListener('click', function () {
                searchInput.value = '';
                searchInput.focus();
                hideSearchSuggestions();
                this.style.display = 'none';
            });
        }

        // Keyboard navigation for search results
        searchInput.addEventListener('keydown', function (e) {
            if (!searchResults || !searchResults.style.display || searchResults.style.display !== 'block') return;

            const results = searchResults.querySelectorAll('.search-result-item');
            if (results.length === 0) return;

            let currentIndex = Array.from(results).findIndex(item => item.classList.contains('highlighted'));

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    currentIndex = Math.min(currentIndex + 1, results.length - 1);
                    updateHighlightedResult(results, currentIndex);
                    break;

                case 'ArrowUp':
                    e.preventDefault();
                    currentIndex = Math.max(currentIndex - 1, -1); // -1 means no selection
                    updateHighlightedResult(results, currentIndex);
                    break;

                case 'Enter':
                    e.preventDefault();
                    if (currentIndex >= 0 && results[currentIndex]) {
                        results[currentIndex].click();
                    } else if (this.value.trim() !== '') {
                        // If no specific result is selected but there's a search term, perform search
                        performSearch(this.value.trim());
                    }
                    break;

                case 'Escape':
                    hideSearchSuggestions();
                    this.blur();
                    break;
            }
        });

        // Update clear button visibility based on input
        searchInput.addEventListener('input', function () {
            if (searchClearBtn) {
                searchClearBtn.style.display = this.value ? 'block' : 'none';
            }

            if (this.value.trim() === '') {
                showPopularAndRecentSearches();
            }
        });
    }

    // Handle search input with predictive functionality
    function handleSearchInput(e) {
        const query = e.target.value.trim();

        if (query.length === 0) {
            showPopularAndRecentSearches();
            return;
        }

        if (query.length < 2) {
            // Show popular/recent searches for short queries
            showPopularAndRecentSearches();
            return;
        }

        // Debounce search to avoid too many API calls
        clearTimeout(window.searchDebounceTimer);
        window.searchDebounceTimer = setTimeout(() => {
            performPredictiveSearch(query);
        }, 300);
    }

    // Perform predictive search with real API
    function performPredictiveSearch(query) {
        // Call real predictive search API
        requestJson(`/api/dashboard/predictive-search?q=${encodeURIComponent(query)}`, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(data => {
                if (data.success && data.predictions) {
                    displaySearchPredictions(data.predictions);
                } else {
                    // Fallback to local search
                    const localPredictions = generateLocalPredictions(query);
                    displaySearchPredictions(localPredictions);
                }
            })
            .catch(error => {
                console.error('Error fetching predictions:', error);
                // Fallback to local search on error
                const localPredictions = generateLocalPredictions(query);
                displaySearchPredictions(localPredictions);
            });
    }

    // Generate local predictions as fallback
    function generateLocalPredictions(query) {
        const allMenuItems = [
            { id: 1, title: 'Dashboard', url: '/dashboard', category: 'Principal', icon: 'bi-speedometer2', hotkey: 'D' },
            { id: 2, title: 'Mensagens', url: '/dashboard/messages', category: 'Principal', icon: 'bi-chat-dots', hotkey: 'M' },
            { id: 3, title: 'Configurações', url: '/dashboard/settings', category: 'Principal', icon: 'bi-gear', hotkey: 'S' },
            { id: 4, title: 'Analytics BI', url: '/dashboard/analytics', category: 'Principal', icon: 'bi-bar-chart-line', hotkey: 'A' },
            { id: 5, title: 'Anúncios', url: '/dashboard/items', category: 'Catálogo', icon: 'bi-box-seam', hotkey: 'I' },
            { id: 6, title: 'Pedidos', url: '/dashboard/orders', category: 'Vendas', icon: 'bi-cart', hotkey: 'O' },
            { id: 7, title: 'Perguntas', url: '/dashboard/questions', category: 'Vendas', icon: 'bi-chat-left-text', hotkey: 'Q' },
            { id: 8, title: 'Relatórios DRE', url: '/dashboard/financials', category: 'Principal', icon: 'bi-file-earmark-bar-graph', hotkey: 'R' },
            { id: 9, title: 'Mercado Ads', url: '/dashboard/ads', category: 'Marketing', icon: 'bi-megaphone', hotkey: 'E' },
            { id: 10, title: 'Gestão de Clientes', url: '/dashboard/customers', category: 'Marketing', icon: 'bi-people', hotkey: 'C' },
            { id: 11, title: 'Contas ML', url: '/dashboard/accounts', category: 'Sistema', icon: 'bi-person-badge', hotkey: 'L' }
        ];

        // Filter items based on query
        const filteredItems = allMenuItems.filter(item =>
            item.title.toLowerCase().includes(query.toLowerCase())
        );

        // Add some predicted items based on user behavior patterns
        const predictedItems = [...filteredItems];

        // Add related items based on common usage patterns
        if (query.toLowerCase().includes('pedido') || query.toLowerCase().includes('order')) {
            predictedItems.push(
                { id: 11, title: 'Pedidos Pendentes', url: '/dashboard/orders?status=pending', category: 'Vendas', icon: 'bi-cart-check', hotkey: 'P' },
                { id: 12, title: 'Histórico de Pedidos', url: '/dashboard/orders/history', category: 'Vendas', icon: 'bi-clock-history', hotkey: 'H' }
            );
        } else if (query.toLowerCase().includes('relatório') || query.toLowerCase().includes('report')) {
            predictedItems.push(
                { id: 13, title: 'Relatórios Financeiros', url: '/dashboard/financials/reports', category: 'Principal', icon: 'bi-file-bar-graph', hotkey: 'F' },
                { id: 14, title: 'Relatórios de Vendas', url: '/dashboard/reports/sales', category: 'Vendas', icon: 'bi-graph-up', hotkey: 'V' }
            );
        }

        // Limit to 10 results
        return predictedItems.slice(0, 10);
    }

    // Display search predictions
    function displaySearchPredictions(predictions) {
        const searchResults = document.getElementById('search-results');
        if (!searchResults) return;

        if (predictions.length === 0) {
            searchResults.innerHTML = '<div class="no-results">Nenhum resultado encontrado</div>';
            searchResults.style.display = 'block';
            return;
        }

        searchResults.innerHTML = predictions.map(item => `
            <div class="search-result-item" data-url="${item.url}" tabindex="0" role="option" aria-label="${item.title}, categoria ${item.category}">
                <i class="bi ${item.icon}"></i>
                <div class="search-result-text">
                    <div class="search-result-title">${highlightMatch(item.title, searchInput.value)}</div>
                    <small class="search-result-category">${item.category}</small>
                </div>
                <span class="search-result-hotkey">${item.hotkey}</span>
            </div>
        `).join('');

        // Add event listeners to result items
        searchResults.querySelectorAll('.search-result-item').forEach(item => {
            item.addEventListener('click', function () {
                const url = this.getAttribute('data-url');
                if (url) {
                    window.location.href = url;

                    // Save to recent searches
                    saveRecentSearch(searchInput.value);
                }
            });

            // Allow keyboard selection
            item.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.click();
                }
            });
        });

        // Hide popular and recent sections when showing results
        const searchPopular = document.getElementById('search-popular');
        const searchRecent = document.getElementById('search-recent');

        if (searchPopular) searchPopular.style.display = 'none';
        if (searchRecent) searchRecent.style.display = 'none';

        searchResults.style.display = 'block';
    }

    // Highlight matching text in search results
    function highlightMatch(text, query) {
        if (!query) return text;

        const regex = new RegExp(`(${escapeRegExp(query)})`, 'gi');
        return text.replace(regex, '<mark class="text-bg-primary">$1</mark>');
    }

    // Escape special regex characters
    function escapeRegExp(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    // Show popular and recent searches
    function showPopularAndRecentSearches() {
        const searchResults = document.getElementById('search-results');
        const searchPopular = document.getElementById('search-popular');
        const searchRecent = document.getElementById('search-recent');

        if (searchResults) {
            searchResults.style.display = 'none';
        }

        if (searchPopular) {
            searchPopular.style.display = 'block';
        }

        if (searchRecent) {
            searchRecent.style.display = 'block';
        }
    }

    // Hide search suggestions
    function hideSearchSuggestions() {
        const searchResults = document.getElementById('search-results');
        const searchPopular = document.getElementById('search-popular');
        const searchRecent = document.getElementById('search-recent');

        if (searchResults) {
            searchResults.style.display = 'none';
        }

        if (searchPopular) {
            searchPopular.style.display = 'none';
        }

        if (searchRecent) {
            searchRecent.style.display = 'none';
        }
    }

    // Load popular searches
    function loadPopularSearches() {
        // In a real implementation, this would fetch from an API
        // For demo, we'll use mock data
        const popularSearches = [
            { term: 'Pedidos', count: 142 },
            { term: 'Relatórios', count: 98 },
            { term: 'Anúncios', count: 87 },
            { term: 'Configurações', count: 76 },
            { term: 'Mensagens', count: 65 }
        ];

        const popularItemsContainer = document.querySelector('.search-popular-items');
        if (popularItemsContainer) {
            popularItemsContainer.innerHTML = popularSearches.map(search => `
                <div class="search-popular-item" data-search-term="${search.term}">
                    <i class="bi bi-star-fill text-warning"></i>
                    <span>${search.term}</span>
                    <small class="text-muted ms-auto">${search.count}</small>
                </div>
            `).join('');

            // Add click handlers to popular items
            popularItemsContainer.querySelectorAll('.search-popular-item').forEach(item => {
                item.addEventListener('click', function () {
                    const searchTerm = this.getAttribute('data-search-term');
                    const searchInput = document.querySelector('.search-input');
                    if (searchInput) {
                        searchInput.value = searchTerm;
                        searchInput.focus();
                        performPredictiveSearch(searchTerm);

                        // Save to recent searches
                        saveRecentSearch(searchTerm);
                    }
                });
            });
        }
    }

    // Load recent searches
    function loadRecentSearches() {
        // Get recent searches from localStorage
        const recentSearches = JSON.parse(localStorage.getItem('recentSearches') || '[]');

        const recentItemsContainer = document.querySelector('.search-recent-items');
        if (recentItemsContainer) {
            if (recentSearches.length > 0) {
                recentItemsContainer.innerHTML = recentSearches.map(search => `
                    <div class="search-recent-item" data-search-term="${search.term}">
                        <i class="bi bi-clock-history text-muted"></i>
                        <span>${search.term}</span>
                        <small class="text-muted ms-auto">${formatDate(search.timestamp)}</small>
                    </div>
                `).join('');

                // Add click handlers to recent items
                recentItemsContainer.querySelectorAll('.search-recent-item').forEach(item => {
                    item.addEventListener('click', function () {
                        const searchTerm = this.getAttribute('data-search-term');
                        const searchInput = document.querySelector('.search-input');
                        if (searchInput) {
                            searchInput.value = searchTerm;
                            searchInput.focus();
                            performPredictiveSearch(searchTerm);
                        }
                    });
                });
            } else {
                recentItemsContainer.innerHTML = '<div class="no-recent-searches text-muted">Nenhuma pesquisa recente</div>';
            }
        }
    }

    // Save recent search
    function saveRecentSearch(term) {
        if (!term) return;

        let recentSearches = JSON.parse(localStorage.getItem('recentSearches') || '[]');

        // Remove duplicates
        recentSearches = recentSearches.filter(search => search.term !== term);

        // Add new search at the beginning
        recentSearches.unshift({
            term: term,
            timestamp: new Date().toISOString()
        });

        // Keep only the 5 most recent
        recentSearches = recentSearches.slice(0, 5);

        localStorage.setItem('recentSearches', JSON.stringify(recentSearches));

        // Update the UI
        loadRecentSearches();
    }

    // Format date for display
    function formatDate(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);

        if (diffMins < 1) return 'Agora';
        if (diffMins < 60) return `Há ${diffMins} min`;
        if (diffMins < 1440) return `Há ${Math.floor(diffMins / 60)}h`; // Less than a day
        return `Há ${Math.floor(diffMins / 1440)}d`; // Days
    }

    // Update highlighted search result
    function updateHighlightedResult(results, index) {
        // Remove highlight from all items
        results.forEach(item => item.classList.remove('highlighted'));

        // Add highlight to selected item
        if (index >= 0 && results[index]) {
            results[index].classList.add('highlighted');
            results[index].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }

    // Perform search (when pressing Enter without selecting a specific result)
    function performSearch(query) {
        // In a real implementation, this would redirect to a search results page
        // For demo, we'll just log the search
        console.log('Performing search for:', query);

        // Save to recent searches
        saveRecentSearch(query);

        // Hide suggestions
        hideSearchSuggestions();
    }

    // Initialize predictive search when DOM is loaded
    document.addEventListener('DOMContentLoaded', function () {
        initializePredictiveSearch();

        // Initialize voice commands if browser supports it
        initializeVoiceCommands();
    });

    // Voice Commands functionality
    function initializeVoiceCommands() {
        // Check if browser supports speech recognition
        if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
            console.log('Reconhecimento de voz não suportado neste navegador');
            return;
        }

        // Create speech recognition instance
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        const recognition = new SpeechRecognition();

        recognition.continuous = false;
        recognition.interimResults = false;
        recognition.lang = 'pt-BR'; // Set to Portuguese Brazil

        // Voice command button
        const voiceCommandBtn = document.createElement('button');
        voiceCommandBtn.className = 'voice-command-btn';
        voiceCommandBtn.id = 'voice-command-btn';
        voiceCommandBtn.innerHTML = '<i class="bi bi-mic"></i>';
        voiceCommandBtn.setAttribute('aria-label', 'Comandos de voz');
        voiceCommandBtn.title = 'Ativar comandos de voz (Pressione F1 para ativar)';

        // Add voice command button to the search container
        const searchContainer = document.querySelector('.search-container');
        if (searchContainer) {
            searchContainer.appendChild(voiceCommandBtn);

            // Add event listener to voice command button
            voiceCommandBtn.addEventListener('click', function () {
                startVoiceRecognition();
            });
        }

        // Add keyboard shortcut for voice commands
        document.addEventListener('keydown', function (e) {
            if (e.key === 'F1') {
                e.preventDefault();
                startVoiceRecognition();
            }
        });

        // Start voice recognition
        function startVoiceRecognition() {
            try {
                recognition.start();
                voiceCommandBtn.innerHTML = '<i class="bi bi-mic-fill text-danger"></i>';
                voiceCommandBtn.title = 'Ouvindo... (Clique para parar)';

                // Announce to screen readers
                const announcer = document.getElementById('sidebar-announcer');
                if (announcer) {
                    announcer.textContent = 'Reconhecimento de voz ativado. Fale um comando.';
                    setTimeout(() => {
                        announcer.textContent = '';
                    }, 3000);
                }
            } catch (error) {
                console.error('Erro ao iniciar reconhecimento de voz:', error);

                // Announce error to screen readers
                const announcer = document.getElementById('sidebar-announcer');
                if (announcer) {
                    announcer.textContent = 'Erro ao ativar reconhecimento de voz.';
                    setTimeout(() => {
                        announcer.textContent = '';
                    }, 3000);
                }
            }
        }

        // Handle recognized speech
        recognition.onresult = function (event) {
            const transcript = event.results[0][0].transcript.toLowerCase().trim();

            // Reset button to default state
            voiceCommandBtn.innerHTML = '<i class="bi bi-mic"></i>';
            voiceCommandBtn.title = 'Ativar comandos de voz (Pressione F1 para ativar)';

            // Process the voice command
            processVoiceCommand(transcript);
        };

        // Handle recognition errors
        recognition.onerror = function (event) {
            console.error('Erro no reconhecimento de voz:', event.error);

            // Reset button to default state
            voiceCommandBtn.innerHTML = '<i class="bi bi-mic"></i>';
            voiceCommandBtn.title = 'Ativar comandos de voz (Pressione F1 para ativar)';

            // Announce error to screen readers
            const announcer = document.getElementById('sidebar-announcer');
            if (announcer) {
                announcer.textContent = `Erro no reconhecimento de voz: ${event.error}`;
                setTimeout(() => {
                    announcer.textContent = '';
                }, 3000);
            }
        };

        // Handle recognition ending
        recognition.onend = function () {
            // Reset button to default state
            voiceCommandBtn.innerHTML = '<i class="bi bi-mic"></i>';
            voiceCommandBtn.title = 'Ativar comandos de voz (Pressione F1 para ativar)';
        };

        // Process voice commands
        function processVoiceCommand(command) {
            console.log('Comando de voz reconhecido:', command);

            // Announce the recognized command
            const announcer = document.getElementById('sidebar-announcer');
            if (announcer) {
                announcer.textContent = `Comando reconhecido: ${command}`;
                setTimeout(() => {
                    announcer.textContent = '';
                }, 3000);
            }

            // Define voice command mappings
            const voiceCommands = {
                // Navigation commands
                'ir para o dashboard': '/dashboard',
                'dashboard': '/dashboard',
                'painel': '/dashboard',
                'ir para mensagens': '/dashboard/messages',
                'mensagens': '/dashboard/messages',
                'ver mensagens': '/dashboard/messages',
                'ir para configurações': '/dashboard/settings',
                'configurações': '/dashboard/settings',
                'ajustes': '/dashboard/settings',
                'ir para pedidos': '/dashboard/orders',
                'pedidos': '/dashboard/orders',
                'ver pedidos': '/dashboard/orders',
                'ir para anúncios': '/dashboard/items',
                'anúncios': '/dashboard/items',
                'produtos': '/dashboard/items',
                'ir para relatórios': '/dashboard/reports',
                'relatórios': '/dashboard/reports',
                'ver relatórios': '/dashboard/reports',
                'ir para clientes': '/dashboard/customers',
                'clientes': '/dashboard/customers',
                'gestão de clientes': '/dashboard/customers',
                'ir para marketing': '/dashboard/marketing',
                'marketing': '/dashboard/marketing',
                'ir para analytics': '/dashboard/analytics',
                'analytics': '/dashboard/analytics',
                'análises': '/dashboard/analytics',

                // Action commands
                'nova mensagem': '/dashboard/messages/new',
                'novo pedido': '/dashboard/orders/new',
                'novo anúncio': '/dashboard/items/new',
                'novo cliente': '/dashboard/customers/new',

                // Theme commands
                'modo escuro': () => themeManager.applyTheme('dark'),
                'modo claro': () => themeManager.applyTheme('light'),
                'alternar tema': () => themeManager.toggleTheme(),

                // Help commands
                'ajuda': () => showHelpModal(),
                'tour do sistema': () => startDashboardTour(),
                'como funciona': () => showHelpModal(),

                // Search commands
                'pesquisar': () => {
                    const searchInput = document.querySelector('.search-input');
                    if (searchInput) {
                        searchInput.focus();
                        return true; // Indicates this command was handled specially
                    }
                },

                // Logout command
                'sair': () => {
                    if (confirm('Tem certeza que deseja sair?')) {
                        window.location.href = '/auth/logout';
                    }
                }
            };

            // Check for exact matches first
            if (voiceCommands[command]) {
                const action = voiceCommands[command];

                if (typeof action === 'string') {
                    // It's a URL, navigate to it
                    window.location.href = action;
                } else if (typeof action === 'function') {
                    // It's a function, execute it
                    const result = action();

                    // If function returns true, it was handled specially
                    if (result !== true) {
                        // Announce action completion
                        if (announcer) {
                            announcer.textContent = `Ação executada: ${command}`;
                            setTimeout(() => {
                                announcer.textContent = '';
                            }, 3000);
                        }
                    }
                }
            } else {
                // Try fuzzy matching
                let matched = false;

                for (const [cmd, action] of Object.entries(voiceCommands)) {
                    // Simple fuzzy matching - check if command contains the voice command
                    if (command.includes(cmd) || cmd.includes(command)) {
                        if (typeof action === 'string') {
                            window.location.href = action;
                        } else if (typeof action === 'function') {
                            action();
                        }

                        matched = true;
                        break;
                    }
                }

                if (!matched) {
                    // If no command matched, try to use it as a search term
                    const searchInput = document.querySelector('.search-input');
                    if (searchInput) {
                        searchInput.value = command;
                        searchInput.focus();

                        // Trigger search
                        const event = new Event('input', { bubbles: true });
                        searchInput.dispatchEvent(event);

                        // Announce search
                        if (announcer) {
                            announcer.textContent = `Pesquisando por: ${command}`;
                            setTimeout(() => {
                                announcer.textContent = '';
                            }, 3000);
                        }
                    } else {
                        // No matching command found
                        if (announcer) {
                            announcer.textContent = `Comando não reconhecido: ${command}. Digite 'ajuda' para obter uma lista de comandos.`;
                            setTimeout(() => {
                                announcer.textContent = '';
                            }, 5000);
                        }
                    }
                }
            }
        }
    }

    // Smart Menu Grouping based on usage patterns
    function initializeSmartMenuGrouping() {
        // Load menu usage data from localStorage or initialize
        let menuUsageData = JSON.parse(localStorage.getItem('menuUsageData') || '{}');

        // Track menu clicks to gather usage data
        const menuLinks = document.querySelectorAll('.nav-link:not(.dropdown-toggle)');
        menuLinks.forEach(link => {
            link.addEventListener('click', function (e) {
                // Only track actual navigation links, not dropdown toggles
                const href = this.getAttribute('href');
                if (href && href.startsWith('/')) { // Internal links only
                    recordMenuUsage(href, this.textContent.trim());
                }
            });
        });

        // Update smart menu groupings periodically
        updateSmartMenuGroupings();

        // Update every 5 minutes
        setInterval(updateSmartMenuGroupings, 5 * 60 * 1000);
    }

    // Record menu usage
    function recordMenuUsage(menuHref, menuTitle) {
        let menuUsageData = JSON.parse(localStorage.getItem('menuUsageData') || '{}');

        if (!menuUsageData[menuHref]) {
            menuUsageData[menuHref] = {
                count: 0,
                lastAccessed: null,
                title: menuTitle || ''
            };
        }

        menuUsageData[menuHref].count += 1;
        menuUsageData[menuHref].lastAccessed = new Date().toISOString();
        if (!menuUsageData[menuHref].title) {
            menuUsageData[menuHref].title = menuTitle || menuHref;
        }

        localStorage.setItem('menuUsageData', JSON.stringify(menuUsageData));
    }

    // Update smart menu groupings
    function updateSmartMenuGroupings() {
        const menuUsageData = JSON.parse(localStorage.getItem('menuUsageData') || '{}');

        // Convert to array and sort by usage count
        const menuItemsArray = Object.entries(menuUsageData)
            .map(([href, data]) => ({ href, ...data }))
            .sort((a, b) => b.count - a.count)
            .slice(0, 5); // Top 5 most used items

        const frequentItemsContainer = document.getElementById('frequent-menu-items');
        if (!frequentItemsContainer) return;

        if (menuItemsArray.length === 0) {
            frequentItemsContainer.innerHTML = '<div class="text-muted small p-2">Nenhum dado de uso disponível</div>';
            return;
        }

        // Generate HTML for frequent items
        frequentItemsContainer.innerHTML = menuItemsArray.map(item => {
            // Get icon from the original menu item if possible
            const originalMenuItem = document.querySelector(`a[href="${item.href}"] i`);
            let iconClass = 'bi bi-star';

            if (originalMenuItem) {
                // Extract icon class from original menu item
                const classes = originalMenuItem.className.split(' ');
                const biClass = classes.find(cls => cls.startsWith('bi-'));
                if (biClass) {
                    iconClass = biClass;
                }
            }

            return `
                <a href="${item.href}" class="smart-group-item" title="Acessado ${item.count} vezes">
                    <i class="menu-item-icon bi ${iconClass}"></i>
                    <span class="menu-item-text">${item.title}</span>
                    <span class="badge bg-primary badge-sm">${item.count}</span>
                </a>
            `;
        }).join('');

        // Add click tracking to the smart menu items
        const smartGroupItems = frequentItemsContainer.querySelectorAll('.smart-group-item');
        smartGroupItems.forEach(item => {
            item.addEventListener('click', function (e) {
                e.preventDefault();
                const href = this.getAttribute('href');

                // Record the click in usage data
                const text = this.querySelector('.menu-item-text')?.textContent || this.textContent;
                recordMenuUsage(href, text);

                // Navigate to the page
                window.location.href = href;
            });
        });
    }

    // Initialize smart menu grouping when DOM is loaded
    document.addEventListener('DOMContentLoaded', function () {
        initializeSmartMenuGrouping();

        // Initialize system analytics
        initializeSystemAnalytics();
    });

    // System Analytics and Forecasting functionality
    function initializeSystemAnalytics() {
        // Load initial analytics data
        updateSystemAnalytics();

        // Update analytics every 30 seconds
        setInterval(updateSystemAnalytics, 30000);

        // Update forecast every minute
        setInterval(updateForecast, 60000);
    }

    // Update system analytics display
    function updateSystemAnalytics() {
        // Fetch real system analytics from API
        requestJson('/api/dashboard/system-analytics', {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(data => {
                if (data.success && data.analytics) {
                    displaySystemAnalytics(data.analytics);
                } else {
                    console.warn('No system analytics data available');
                }
            })
            .catch(error => {
                console.error('Error fetching system analytics:', error);
            });
    }

    // Display system analytics
    function displaySystemAnalytics(analytics) {
        // Update productivity meter
        const productivityMeter = document.getElementById('productivity-meter');
        if (productivityMeter) {
            productivityMeter.textContent = `${analytics.productivity}%`;

            // Add color class based on value
            productivityMeter.className = 'analytics-value';
            if (analytics.productivity >= 90) {
                productivityMeter.classList.add('positive');
            } else if (analytics.productivity >= 75) {
                productivityMeter.classList.add('neutral');
            } else {
                productivityMeter.classList.add('negative');
            }
        }

        // Update performance meter
        const performanceMeter = document.getElementById('performance-meter');
        if (performanceMeter) {
            performanceMeter.textContent = `${analytics.performance}%`;

            // Add color class based on value
            performanceMeter.className = 'analytics-value';
            if (analytics.performance >= 90) {
                performanceMeter.classList.add('positive');
            } else if (analytics.performance >= 75) {
                performanceMeter.classList.add('neutral');
            } else {
                performanceMeter.classList.add('negative');
            }
        }

        // Update forecast indicator
        updateForecast();
    }

    // Update forecast based on trends
    function updateForecast() {
        // Calculate forecast based on historical data
        const forecastElement = document.getElementById('forecast-indicator');
        if (!forecastElement) return;

        // In a real implementation, this would use ML algorithms to predict trends
        // For demo, we'll use a simple trend calculation

        // Get historical data from localStorage
        let historicalData = JSON.parse(localStorage.getItem('analyticsHistory') || '[]');

        // Add current data point
        const currentData = {
            timestamp: new Date().toISOString(),
            productivity: parseInt(document.getElementById('productivity-meter')?.textContent || '85'),
            performance: parseInt(document.getElementById('performance-meter')?.textContent || '85')
        };

        historicalData.push(currentData);

        // Keep only last 10 data points
        if (historicalData.length > 10) {
            historicalData = historicalData.slice(-10);
        }

        localStorage.setItem('analyticsHistory', JSON.stringify(historicalData));

        // Calculate trend
        if (historicalData.length >= 2) {
            const firstPoint = historicalData[0];
            const lastPoint = historicalData[historicalData.length - 1];

            // Calculate average trend
            const prodChange = lastPoint.productivity - firstPoint.productivity;
            const perfChange = lastPoint.performance - firstPoint.performance;

            // Determine forecast direction
            let forecastDirection = 'neutro';
            let forecastClass = 'forecast-neutral';

            if (prodChange > 5 || perfChange > 5) {
                forecastDirection = 'positivo';
                forecastClass = 'forecast-up';
            } else if (prodChange < -5 || perfChange < -5) {
                forecastDirection = 'negativo';
                forecastClass = 'forecast-down';
            }

            forecastElement.textContent = forecastDirection.charAt(0).toUpperCase() + forecastDirection.slice(1);
            forecastElement.className = forecastClass;
        } else {
            forecastElement.textContent = 'Inicial';
            forecastElement.className = 'forecast-neutral';
        }
    }

    // Show detailed system analytics modal
    window.showSystemAnalytics = function () {
        // Create analytics modal
        const analyticsModal = document.createElement('div');
        analyticsModal.className = 'modal fade';
        analyticsModal.id = 'system-analytics-modal';
        analyticsModal.tabIndex = '-1';
        analyticsModal.innerHTML = `
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Análises do Sistema</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <div class="analytics-dashboard">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6 class="card-title">Produtividade</h6>
                                            <div class="metric-value display-4">87%</div>
                                            <div class="metric-trend positive">
                                                <i class="bi bi-arrow-up"></i> 3.2% desde ontem
                                            </div>
                                            <canvas id="productivity-chart" height="100"></canvas>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6 class="card-title">Desempenho</h6>
                                            <div class="metric-value display-4">92%</div>
                                            <div class="metric-trend positive">
                                                <i class="bi bi-arrow-up"></i> 1.8% desde ontem
                                            </div>
                                            <canvas id="performance-chart" height="100"></canvas>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6 class="card-title">Previsão</h6>
                                            <div class="metric-value display-4">Estável</div>
                                            <div class="metric-trend neutral">
                                                <i class="bi bi-dash"></i> Sem variação esperada
                                            </div>
                                            <canvas id="forecast-chart" height="100"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row mt-4">
                                <div class="col-12">
                                    <h6>Previsão para Próxima Semana</h6>
                                    <div class="forecast-container">
                                        <div class="forecast-item">
                                            <span>Segunda</span>
                                            <div class="forecast-bar" style="width: 85%;">
                                                <div class="forecast-value">85%</div>
                                            </div>
                                        </div>
                                        <div class="forecast-item">
                                            <span>Terça</span>
                                            <div class="forecast-bar" style="width: 88%;">
                                                <div class="forecast-value">88%</div>
                                            </div>
                                        </div>
                                        <div class="forecast-item">
                                            <span>Quarta</span>
                                            <div class="forecast-bar" style="width: 92%;">
                                                <div class="forecast-value">92%</div>
                                            </div>
                                        </div>
                                        <div class="forecast-item">
                                            <span>Quinta</span>
                                            <div class="forecast-bar" style="width: 89%;">
                                                <div class="forecast-value">89%</div>
                                            </div>
                                        </div>
                                        <div class="forecast-item">
                                            <span>Sexta</span>
                                            <div class="forecast-bar" style="width: 91%;">
                                                <div class="forecast-value">91%</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                        <button type="button" class="btn btn-primary" onclick="exportAnalyticsReport()">Exportar Relatório</button>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(analyticsModal);

        // Initialize modal
        const modal = new bootstrap.Modal(analyticsModal);
        modal.show();

        // Remove modal from DOM when hidden
        analyticsModal.addEventListener('hidden.bs.modal', function () {
            document.body.removeChild(analyticsModal);
        });
    };

    // Export analytics report
    window.exportAnalyticsReport = function () {
        // In a real implementation, this would generate a detailed report
        // For demo, we'll just show a success message
        alert('Relatório de análises exportado com sucesso!');
    };
});
