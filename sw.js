// ACVideoReview - Service Worker for PWA Offline Support
const CACHE_NAME = 'acvideoreview-v1';
const STATIC_ASSETS = [
    '/dashboard.php',
    '/css/style-guide.css',
    '/js/app.js',
    '/js/video-player.js',
    '/js/telestration.js',
    '/js/device-sync.js',
    '/manifest.json'
];

// Install: cache static assets
self.addEventListener('install', function(event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function(cache) {
            return cache.addAll(STATIC_ASSETS);
        }).catch(function() {
            // Caching may fail if assets aren't available yet
        })
    );
    self.skipWaiting();
});

// Activate: clean old caches
self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(cacheNames) {
            return Promise.all(
                cacheNames.filter(function(name) {
                    return name !== CACHE_NAME;
                }).map(function(name) {
                    return caches.delete(name);
                })
            );
        })
    );
    self.clients.claim();
});

// Fetch: network-first for API/PHP, cache-first for static assets
self.addEventListener('fetch', function(event) {
    var request = event.request;

    // Skip non-GET requests
    if (request.method !== 'GET') {
        return;
    }

    // Skip API calls and PHP pages (always fetch from network)
    var url = new URL(request.url);
    if (url.pathname.endsWith('.php') || url.pathname.startsWith('/api/')) {
        event.respondWith(
            fetch(request).catch(function() {
                return caches.match(request);
            })
        );
        return;
    }

    // Cache-first for static assets (CSS, JS, fonts, images)
    event.respondWith(
        caches.match(request).then(function(cachedResponse) {
            if (cachedResponse) {
                // Refresh cache in background
                fetch(request).then(function(networkResponse) {
                    if (networkResponse && networkResponse.status === 200) {
                        caches.open(CACHE_NAME).then(function(cache) {
                            cache.put(request, networkResponse);
                        });
                    }
                }).catch(function() {
                    // Network unavailable, cached version still served
                });
                return cachedResponse;
            }
            return fetch(request).then(function(networkResponse) {
                if (networkResponse && networkResponse.status === 200) {
                    var responseClone = networkResponse.clone();
                    caches.open(CACHE_NAME).then(function(cache) {
                        cache.put(request, responseClone);
                    });
                }
                return networkResponse;
            });
        })
    );
});
