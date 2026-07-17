/* eslint-env serviceworker */
/* eslint-disable no-undef */
/**
 * Service Worker - Mercado Livre Manager PWA
 * Implementa cache, modo offline e push notifications
 * 
 * @version 1.1.0
 */

const CACHE_NAME = 'ml-manager-v3';
const DYNAMIC_CACHE = 'ml-manager-dynamic-v3';
const API_CACHE = 'ml-manager-api-v3';
const EMERGENCY_NETWORK_ONLY = true;

// Declarações explícitas para lint em ambiente Service Worker
const self = globalThis.self;
const caches = globalThis.caches;
const clients = globalThis.clients;
const Notification = globalThis.Notification;
const console = globalThis.console;
const URL = globalThis.URL;
const fetch = globalThis.fetch;
const Response = globalThis.Response;
const Headers = globalThis.Headers;

// Assets estáticos para cache imediato
const STATIC_ASSETS = [
    '/offline.html',
    '/css/pwa.css',
    '/js/pwa.js',
    '/js/app.js',
    '/manifest.json',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css',
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js'
];

// Rotas da API que podem ser cacheadas
const CACHEABLE_API_ROUTES = [
    '/api/categories',
    '/api/categories/tree',
    '/api/dashboard/metrics',
    '/api/statistics'
];

// Tempo máximo para considerar cache válido (em ms)
const CACHE_TTL = {
    api: 5 * 60 * 1000, // 5 minutos para APIs
    static: 24 * 60 * 60 * 1000 // 24 horas para estáticos
};

/**
 * Instalação do Service Worker
 */
self.addEventListener('install', (event) => {
    console.log('[SW] Installing Service Worker...');

    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                console.log('[SW] Caching static assets');
                return cache.addAll(STATIC_ASSETS);
            })
            .then(() => {
                console.log('[SW] Static assets cached successfully');
                return self.skipWaiting();
            })
            .catch((error) => {
                console.error('[SW] Error caching static assets:', error);
            })
    );
});

/**
 * Ativação do Service Worker
 */
self.addEventListener('activate', (event) => {
    console.log('[SW] Activating Service Worker...');

    event.waitUntil(
        Promise.all([
            // Modo de emergência: limpar todos os caches para remover estado corrompido
            ...(EMERGENCY_NETWORK_ONLY ? [
                caches.keys().then((keys) => Promise.all(keys.map((key) => caches.delete(key))))
            ] : []),
            // Limpar caches antigos
            caches.keys().then((keys) => {
                return Promise.all(
                    keys.filter((key) => {
                        return key !== CACHE_NAME &&
                            key !== DYNAMIC_CACHE &&
                            key !== API_CACHE;
                    }).map((key) => {
                        console.log('[SW] Removing old cache:', key);
                        return caches.delete(key);
                    })
                );
            }),
            // Tomar controle de todas as abas
            self.clients.claim()
        ])
    );
});

/**
 * Interceptação de requisições (Fetch)
 */
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Modo de emergência: sempre rede para evitar loops por cache/offline antigo
    if (EMERGENCY_NETWORK_ONLY) {
        event.respondWith(
            fetch(request).catch((error) => {
                throw error;
            })
        );
        return;
    }

    // Ignorar requisições não HTTP/HTTPS
    if (!url.protocol.startsWith('http')) {
        return;
    }

    // Estratégias diferentes para diferentes tipos de requisição
    if (request.method !== 'GET') {
        // POST, PUT, DELETE: Tentar rede, se offline, enfileirar
        event.respondWith(handleMutationRequest(request));
        return;
    }

    // Nunca cachear HTML dinâmico com nonce CSP (evita mismatch de nonce em scripts inline).
    if (isHtmlRequest(request)) {
        event.respondWith(networkOnlyHtmlStrategy(request));
        return;
    }

    if (isApiRequest(url)) {
        // API: Network First com fallback para cache
        event.respondWith(networkFirstStrategy(request, API_CACHE));
        return;
    }

    if (isStaticAsset(url)) {
        // Assets estáticos: Cache First
        event.respondWith(cacheFirstStrategy(request, CACHE_NAME));
        return;
    }

    // Demais GET: preferir rede
    event.respondWith(fetch(request));
});

/**
 * Verifica se é uma requisição de API
 */
function isApiRequest(url) {
    return url.pathname.startsWith('/api/');
}

/**
 * Verifica se é um asset estático
 */
function isStaticAsset(url) {
    const staticExtensions = ['.js', '.css', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.woff', '.woff2', '.ttf'];
    return staticExtensions.some(ext => url.pathname.endsWith(ext)) ||
        url.hostname !== self.location.hostname;
}

/**
 * Verifica se é uma requisição de documento HTML/navegação
 */
function isHtmlRequest(request) {
    const accept = request.headers.get('accept') || '';
    return request.mode === 'navigate' || accept.includes('text/html');
}

/**
 * Estratégia: Cache First
 * Bom para assets estáticos
 */
async function cacheFirstStrategy(request, cacheName) {
    const cached = await caches.match(request);

    if (cached) {
        return cached;
    }

    try {
        const response = await fetch(request);
        const contentType = (response.headers.get('content-type') || '').toLowerCase();

        if (response.ok && !contentType.includes('text/html')) {
            const cache = await caches.open(cacheName);
            cache.put(request, response.clone());
        }

        return response;
    } catch (error) {
        console.log('[SW] Network failed for:', request.url);
        return caches.match('/offline.html');
    }
}

/**
 * Estratégia: Network Only para HTML
 * Evita servir páginas com nonce CSP antigo de qualquer cache local.
 */
async function networkOnlyHtmlStrategy(request) {
    try {
        return await fetch(request, { cache: 'no-store' });
    } catch (error) {
        const offline = await caches.match('/offline.html');
        if (offline) {
            return offline;
        }

        return new Response('Offline', {
            status: 503,
            headers: { 'Content-Type': 'text/plain; charset=utf-8' }
        });
    }
}

/**
 * Estratégia: Network First
 * Bom para APIs
 */
async function networkFirstStrategy(request, cacheName) {
    try {
        const response = await fetch(request);

        if (response.ok && isCacheableApiRoute(request.url)) {
            const cache = await caches.open(cacheName);
            const responseToCache = response.clone();

            // Adicionar timestamp ao cache
            const headers = new Headers(responseToCache.headers);
            headers.append('sw-cached-at', Date.now().toString());

            cache.put(request, new Response(responseToCache.body, {
                status: responseToCache.status,
                statusText: responseToCache.statusText,
                headers: headers
            }));
        }

        return response;
    } catch (error) {
        console.log('[SW] Network failed, trying cache for:', request.url);

        const cached = await caches.match(request);

        if (cached) {
            // Verificar se o cache ainda é válido
            const cachedAt = cached.headers.get('sw-cached-at');
            if (cachedAt && (Date.now() - parseInt(cachedAt, 10)) > CACHE_TTL.api) {
                console.log('[SW] Cache expired for:', request.url);
            }
            return cached;
        }

        // Retornar resposta de erro offline
        return new Response(JSON.stringify({
            success: false,
            offline: true,
            message: 'Você está offline. Tente novamente quando estiver conectado.'
        }), {
            status: 503,
            headers: { 'Content-Type': 'application/json' }
        });
    }
}

/**
 * Estratégia: Stale While Revalidate
 * Bom para páginas HTML
 */
async function staleWhileRevalidateStrategy(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);

    // Buscar nova versão em background
    const networkPromise = fetch(request).then((response) => {
        if (response.ok) {
            cache.put(request, response.clone());
        }
        return response;
    }).catch(() => null);

    // Retornar cache imediatamente se disponível
    if (cached) {
        return cached;
    }

    // Se não tem cache, aguardar rede
    const networkResponse = await networkPromise;

    if (networkResponse) {
        return networkResponse;
    }

    // Se offline e sem cache, mostrar página offline
    return caches.match('/offline.html');
}

/**
 * Verifica se a rota da API pode ser cacheada
 */
function isCacheableApiRoute(url) {
    return CACHEABLE_API_ROUTES.some(route => url.includes(route));
}

/**
 * Manipula requisições de mutação (POST, PUT, DELETE)
 */
async function handleMutationRequest(request) {
    try {
        return await fetch(request);
    } catch (error) {
        // Se offline, enfileirar para sync
        if (request.method !== 'GET') {
            await queueRequest(request);

            return new Response(JSON.stringify({
                success: false,
                queued: true,
                message: 'Você está offline. A ação será processada quando você se reconectar.'
            }), {
                status: 202,
                headers: { 'Content-Type': 'application/json' }
            });
        }

        throw error;
    }
}

/**
 * Enfileira requisição para sincronização posterior
 */
async function queueRequest(request) {
    const queue = await getRequestQueue();

    const requestData = {
        url: request.url,
        method: request.method,
        headers: Object.fromEntries(request.headers.entries()),
        body: await request.text(),
        timestamp: Date.now()
    };

    queue.push(requestData);
    await saveRequestQueue(queue);

    // Registrar para Background Sync
    if ('sync' in self.registration) {
        try {
            await self.registration.sync.register('sync-requests');
        } catch (error) {
            console.error('[SW] Background sync registration failed:', error);
        }
    }
}

/**
 * Obtém fila de requisições pendentes
 */
async function getRequestQueue() {
    const cache = await caches.open('request-queue');
    const response = await cache.match('queue');

    if (response) {
        return await response.json();
    }

    return [];
}

/**
 * Salva fila de requisições pendentes
 */
async function saveRequestQueue(queue) {
    const cache = await caches.open('request-queue');
    await cache.put('queue', new Response(JSON.stringify(queue)));
}

/**
 * Background Sync - processar requisições enfileiradas
 */
self.addEventListener('sync', (event) => {
    console.log('[SW] Background sync triggered');

    if (event.tag === 'sync-requests') {
        event.waitUntil(processRequestQueue());
    }
});

/**
 * Processa fila de requisições pendentes
 */
async function processRequestQueue() {
    const queue = await getRequestQueue();

    if (queue.length === 0) {
        return;
    }

    console.log(`[SW] Processing ${queue.length} queued requests`);

    const remainingRequests = [];

    for (const requestData of queue) {
        try {
            const response = await fetch(requestData.url, {
                method: requestData.method,
                headers: requestData.headers,
                body: requestData.body
            });

            if (response.ok) {
                console.log('[SW] Queued request processed:', requestData.url);

                // Notificar usuário
                await notifyUser('Sincronização Completa', {
                    body: 'Suas alterações offline foram sincronizadas.',
                    icon: '/icons/icon-192x192.png',
                    badge: '/icons/badge-72x72.png'
                });
            } else {
                // Manter na fila para tentar novamente
                remainingRequests.push(requestData);
            }
        } catch (error) {
            console.error('[SW] Failed to process queued request:', error);
            remainingRequests.push(requestData);
        }
    }

    await saveRequestQueue(remainingRequests);
}

/**
 * Push Notifications
 */
self.addEventListener('push', (event) => {
    console.log('[SW] Push notification received');

    let data = {
        title: 'Mercado Livre Manager',
        body: 'Nova notificação',
        icon: '/icons/icon-192x192.png',
        badge: '/icons/badge-72x72.png',
        tag: 'ml-manager',
        data: {}
    };

    if (event.data) {
        try {
            const payload = event.data.json();
            data = { ...data, ...payload };
        } catch (error) {
            data.body = event.data.text();
        }
    }

    const options = {
        body: data.body,
        icon: data.icon,
        badge: data.badge,
        tag: data.tag,
        renotify: true,
        requireInteraction: data.requireInteraction || false,
        data: data.data,
        actions: data.actions || [
            { action: 'view', title: 'Ver' },
            { action: 'dismiss', title: 'Dispensar' }
        ],
        vibrate: [100, 50, 100]
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

/**
 * Clique em notificação
 */
self.addEventListener('notificationclick', (event) => {
    console.log('[SW] Notification clicked:', event.notification.tag);

    event.notification.close();

    const action = event.action;
    const data = event.notification.data || {};

    if (action === 'dismiss') {
        return;
    }

    // Determinar URL para abrir
    let urlToOpen = data.url || '/dashboard';

    // Ações específicas por tipo
    if (data.type === 'order') {
        urlToOpen = `/dashboard/orders?highlight=${data.orderId}`;
    } else if (data.type === 'alert') {
        urlToOpen = `/dashboard?alert=${data.alertId}`;
    }

    event.waitUntil(
          clients.matchAll({ type: 'window', includeUncontrolled: true })
              .then(async (clientList) => {
                  // Procurar por janela já aberta
                  for (const client of clientList) {
                      if (client.url.includes(self.location.origin) && 'focus' in client) {
                          client.navigate(urlToOpen);
                          await client.focus();
                          return;
                      }
                  }

                  // Abrir nova janela
                  if (clients.openWindow) {
                      await clients.openWindow(urlToOpen);
                  }
              })
      );
});

/**
 * Notificar usuário (função utilitária)
 */
async function notifyUser(title, options) {
    if (Notification.permission === 'granted') {
        await self.registration.showNotification(title, options);
    }
}

/**
 * Listener para mensagens do cliente
 */
self.addEventListener('message', (event) => {
    console.log('[SW] Message received:', event.data);

    if (event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }

    if (event.data.type === 'CACHE_URLS') {
        event.waitUntil(
            caches.open(DYNAMIC_CACHE).then((cache) => {
                return cache.addAll(event.data.urls);
            })
        );
    }

    if (event.data.type === 'CLEAR_CACHE') {
        event.waitUntil(
            caches.keys().then((keys) => {
                return Promise.all(keys.map((key) => caches.delete(key)));
            })
        );
    }

    if (event.data.type === 'GET_CACHE_STATUS') {
        event.waitUntil(
            getCacheStatus().then((status) => {
                event.ports[0].postMessage(status);
            })
        );
    }
});

/**
 * Obtém status do cache
 */
async function getCacheStatus() {
    const cacheNames = await caches.keys();
    const status = {};

    for (const name of cacheNames) {
        const cache = await caches.open(name);
        const keys = await cache.keys();
        status[name] = keys.length;
    }

    return status;
}

/**
 * Periodic Background Sync (se suportado)
 */
self.addEventListener('periodicsync', (event) => {
    if (event.tag === 'sync-data') {
        event.waitUntil(syncData());
    }
});

/**
 * Sincroniza dados em background
 */
async function syncData() {
    console.log('[SW] Periodic sync triggered');

    try {
        // Atualizar métricas do dashboard
        const metricsResponse = await fetch('/api/dashboard/metrics');
        if (metricsResponse.ok) {
            const cache = await caches.open(API_CACHE);
            cache.put('/api/dashboard/metrics', metricsResponse);
        }

        // Verificar novos pedidos
        const ordersResponse = await fetch('/api/orders?limit=5');
        if (ordersResponse.ok) {
            const orders = await ordersResponse.json();

            // Notificar sobre novos pedidos
            if (orders.new_count && orders.new_count > 0) {
                await notifyUser('Novos Pedidos', {
                    body: `Você tem ${orders.new_count} novo(s) pedido(s)!`,
                    icon: '/icons/icon-192x192.png',
                    badge: '/icons/badge-72x72.png',
                    tag: 'new-orders',
                    data: { type: 'order' }
                });
            }
        }
    } catch (error) {
        console.error('[SW] Periodic sync failed:', error);
    }
}

console.log('[SW] Service Worker loaded');

// Referência explícita para evitar no-unused-vars em lint
void staleWhileRevalidateStrategy;
