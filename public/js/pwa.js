/**
 * PWA JavaScript - Mercado Livre Manager
 * Gerencia funcionalidades PWA: instalação, notificações, offline
 */

async function requestJson(url, options = {}) {
    if (window.ApiClient) return window.ApiClient.request(url, options);
    const resp = await fetch(url, { credentials: 'include', ...options });
    if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
    return resp.json();
}

class PWAManager {
    constructor() {
        this.deferredPrompt = null;
        this.swRegistration = null;
        this.isOnline = navigator.onLine;
        this.pushSubscription = null;

        this.init();
    }

    async init() {
        // Registrar Service Worker
        await this.registerServiceWorker();

        // Configurar eventos de rede
        this.setupNetworkEvents();

        // Configurar prompt de instalação
        this.setupInstallPrompt();

        // Inicializar push notifications
        await this.initPushNotifications();

        // Verificar se está instalado como PWA
        this.checkIfInstalled();

    }

    /**
     * Registrar Service Worker
     */
    async registerServiceWorker() {
        if (!('serviceWorker' in navigator)) {
            console.warn('[PWA] Service Worker not supported');
            return;
        }

        try {
            this.swRegistration = await navigator.serviceWorker.register('/service-worker.js', {
                scope: '/'
            });

            // Verificar atualizações
            this.swRegistration.addEventListener('updatefound', () => {
                const newWorker = this.swRegistration.installing;

                newWorker.addEventListener('statechange', () => {
                    if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                        this.showUpdateNotification();
                    }
                });
            });

            // Comunicação com SW
            navigator.serviceWorker.addEventListener('message', (event) => {
                this.handleSWMessage(event.data);
            });

        } catch (error) {
            console.error('[PWA] Service Worker registration failed:', error);
        }
    }

    /**
     * Configurar eventos de rede
     */
    setupNetworkEvents() {
        window.addEventListener('online', () => {
            this.isOnline = true;
            this.onNetworkChange(true);
        });

        window.addEventListener('offline', () => {
            this.isOnline = false;
            this.onNetworkChange(false);
        });
    }

    /**
     * Callback de mudança de rede
     */
    onNetworkChange(isOnline) {
        // Disparar evento customizado
        window.dispatchEvent(new CustomEvent('networkchange', {
            detail: { online: isOnline }
        }));

        // Sincronizar dados quando voltar online
        if (isOnline) {
            this.syncOfflineData();
        }

        // Mostrar/ocultar indicador offline
        const indicator = document.getElementById('offlineIndicator');
        if (indicator) {
            indicator.classList.toggle('show', !isOnline);
        }
    }

    /**
     * Configurar prompt de instalação
     */
    setupInstallPrompt() {
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            this.deferredPrompt = e;


            // Disparar evento customizado
            window.dispatchEvent(new CustomEvent('pwainstallready'));

            // Mostrar banner de instalação após delay
            const dismissed = localStorage.getItem('pwa-install-dismissed');
            if (!dismissed || (Date.now() - parseInt(dismissed)) > 7 * 24 * 60 * 60 * 1000) {
                setTimeout(() => this.showInstallBanner(), 5000);
            }
        });

        window.addEventListener('appinstalled', () => {
            this.deferredPrompt = null;
            this.hideInstallBanner();
            this.trackInstallation();
        });
    }

    /**
     * Mostrar banner de instalação
     */
    showInstallBanner() {
        const banner = document.getElementById('installBanner');
        if (banner && this.deferredPrompt) {
            banner.classList.add('show');
        }
    }

    /**
     * Ocultar banner de instalação
     */
    hideInstallBanner() {
        const banner = document.getElementById('installBanner');
        if (banner) {
            banner.classList.remove('show');
        }
    }

    /**
     * Dispensar instalação
     */
    dismissInstall() {
        this.hideInstallBanner();
        localStorage.setItem('pwa-install-dismissed', Date.now().toString());
    }

    /**
     * Instalar PWA
     */
    async install() {
        if (!this.deferredPrompt) {
            console.warn('[PWA] No install prompt available');
            return false;
        }

        this.deferredPrompt.prompt();
        const { outcome } = await this.deferredPrompt.userChoice;

        this.deferredPrompt = null;

        return outcome === 'accepted';
    }

    /**
     * Verificar se está instalado
     */
    checkIfInstalled() {
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches ||
            window.navigator.standalone ||
            document.referrer.includes('android-app://');

        if (isStandalone) {
            document.body.classList.add('pwa-installed');
        }

        return isStandalone;
    }

    /**
     * Inicializar Push Notifications
     */
    async initPushNotifications() {
        if (!('PushManager' in window)) {
            console.warn('[PWA] Push notifications not supported');
            return;
        }

        // Verificar permissão atual
        const permission = Notification.permission;

        if (permission === 'granted') {
            await this.subscribeToPush();
        }
    }

    /**
     * Solicitar permissão de notificação
     */
    async requestNotificationPermission() {
        if (!('Notification' in window)) {
            console.warn('[PWA] Notifications not supported');
            return false;
        }

        const permission = await Notification.requestPermission();

        if (permission === 'granted') {
            await this.subscribeToPush();
            return true;
        }

        return false;
    }

    /**
     * Inscrever para push notifications
     */
    async subscribeToPush() {
        if (!this.swRegistration) {
            console.warn('[PWA] No service worker registration');
            return;
        }

        try {
            // Obter VAPID public key
            const { publicKey } = await requestJson('/api/push/vapid-key');

            if (!publicKey) {
                console.warn('[PWA] No VAPID public key');
                return;
            }

            // Converter base64 para Uint8Array
            const applicationServerKey = this.urlBase64ToUint8Array(publicKey);

            // Criar subscription
            this.pushSubscription = await this.swRegistration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: applicationServerKey
            });


            // Enviar subscription para servidor
            await this.sendSubscriptionToServer(this.pushSubscription);

        } catch (error) {
            console.error('[PWA] Push subscription failed:', error);
        }
    }

    /**
     * Enviar subscription para servidor
     */
    async sendSubscriptionToServer(subscription) {
        try {
            const result = await requestJson('/api/push/subscribe', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ subscription: subscription.toJSON() })
            });

        } catch (error) {
            console.error('[PWA] Failed to save subscription:', error);
        }
    }

    /**
     * Cancelar inscrição de push
     */
    async unsubscribeFromPush() {
        if (!this.pushSubscription) {
            return;
        }

        try {
            await this.pushSubscription.unsubscribe();

            // Notificar servidor
            await requestJson('/api/push/unsubscribe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ endpoint: this.pushSubscription.endpoint })
            });

            this.pushSubscription = null;

        } catch (error) {
            console.error('[PWA] Unsubscribe failed:', error);
        }
    }

    /**
     * Converter base64 para Uint8Array
     */
    urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
            .replace(/-/g, '+')
            .replace(/_/g, '/');

        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);

        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }

        return outputArray;
    }

    /**
     * Mostrar notificação local
     */
    async showNotification(title, options = {}) {
        if (Notification.permission !== 'granted') {
            return;
        }

        const defaultOptions = {
            icon: '/icons/icon-192x192.png',
            badge: '/icons/badge-72x72.png',
            vibrate: [100, 50, 100],
            data: { url: '/dashboard' }
        };

        const notification = new Notification(title, { ...defaultOptions, ...options });

        notification.onclick = () => {
            window.focus();
            if (options.data?.url) {
                window.location.href = options.data.url;
            }
            notification.close();
        };
    }

    /**
     * Mostrar notificação de atualização
     */
    showUpdateNotification() {
        const banner = document.createElement('div');
        banner.className = 'update-banner';
        banner.innerHTML = `
            <span>Nova versão disponível!</span>
            <button onclick="window.location.reload()">Atualizar</button>
        `;
        document.body.appendChild(banner);
    }

    /**
     * Sincronizar dados offline
     */
    async syncOfflineData() {

        // Disparar evento para que componentes sincronizem
        window.dispatchEvent(new CustomEvent('sync-data'));

        // Processar fila de requisições offline
        if (this.swRegistration && 'sync' in this.swRegistration) {
            try {
                await this.swRegistration.sync.register('sync-requests');
            } catch (error) {
                console.error('[PWA] Background sync failed:', error);
            }
        }
    }

    /**
     * Manipular mensagem do Service Worker
     */
    handleSWMessage(data) {

        switch (data.type) {
            case 'SYNC_COMPLETE':
                window.dispatchEvent(new CustomEvent('sync-complete', { detail: data }));
                break;
            case 'CACHE_UPDATED':
                window.dispatchEvent(new CustomEvent('cache-updated', { detail: data }));
                break;
            case 'NEW_NOTIFICATION':
                this.showNotification(data.title, data.options);
                break;
        }
    }

    /**
     * Enviar mensagem para Service Worker
     */
    postMessage(message) {
        if (navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage(message);
        }
    }

    /**
     * Forçar atualização do Service Worker
     */
    async updateServiceWorker() {
        if (this.swRegistration) {
            await this.swRegistration.update();
            this.postMessage({ type: 'SKIP_WAITING' });
        }
    }

    /**
     * Limpar cache
     */
    clearCache() {
        this.postMessage({ type: 'CLEAR_CACHE' });
    }

    /**
     * Obter status do cache
     */
    async getCacheStatus() {
        return new Promise((resolve) => {
            const channel = new MessageChannel();
            channel.port1.onmessage = (event) => resolve(event.data);
            this.postMessage({ type: 'GET_CACHE_STATUS' }, [channel.port2]);
        });
    }

    /**
     * Rastrear instalação
     */
    async trackInstallation() {
        try {
            await requestJson('/api/push/track-install', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    installed: true,
                    platform: navigator.platform,
                    userAgent: navigator.userAgent,
                    timestamp: new Date().toISOString()
                })
            });
        } catch (error) {
            console.error('[PWA] Track installation failed:', error);
        }
    }

    /**
     * Verificar status de notificações
     */
    getNotificationStatus() {
        return {
            supported: 'Notification' in window,
            permission: Notification.permission,
            subscribed: !!this.pushSubscription
        };
    }

    /**
     * Verificar se pode ser instalado
     */
    canBeInstalled() {
        return !!this.deferredPrompt;
    }

    /**
     * Verificar status online
     */
    isNetworkOnline() {
        return this.isOnline;
    }
}

// Utilitários de armazenamento local
class OfflineStorage {
    constructor(prefix = 'ml_') {
        this.prefix = prefix;
    }

    set(key, value, ttl = null) {
        const item = {
            value: value,
            timestamp: Date.now(),
            ttl: ttl
        };
        localStorage.setItem(this.prefix + key, JSON.stringify(item));
    }

    get(key) {
        const item = localStorage.getItem(this.prefix + key);
        if (!item) return null;

        try {
            const parsed = JSON.parse(item);

            // Verificar TTL
            if (parsed.ttl && (Date.now() - parsed.timestamp) > parsed.ttl) {
                this.remove(key);
                return null;
            }

            return parsed.value;
        } catch {
            return null;
        }
    }

    remove(key) {
        localStorage.removeItem(this.prefix + key);
    }

    clear() {
        const keys = Object.keys(localStorage).filter(k => k.startsWith(this.prefix));
        keys.forEach(k => localStorage.removeItem(k));
    }
}

// Inicializar PWA Manager
let pwaManager = null;
let offlineStorage = null;

document.addEventListener('DOMContentLoaded', () => {
    pwaManager = new PWAManager();
    offlineStorage = new OfflineStorage('ml_');

    // Expor globalmente
    window.pwaManager = pwaManager;
    window.offlineStorage = offlineStorage;
});

// Funções globais para uso em HTML
function installPWA() {
    if (pwaManager) {
        pwaManager.install();
    }
}

function dismissInstall() {
    if (pwaManager) {
        pwaManager.dismissInstall();
    }
}

function enableNotifications() {
    if (pwaManager) {
        pwaManager.requestNotificationPermission();
    }
}

function syncData() {
    if (pwaManager) {
        pwaManager.syncOfflineData();
    }
}
