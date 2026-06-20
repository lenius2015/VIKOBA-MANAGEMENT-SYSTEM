// ============================================================
// VIKOBA - Service Worker for PWA Offline Support
// ============================================================

const CACHE_NAME = 'vikoba-cache-v1';
const STATIC_ASSETS = [
  '/vikoba/',
  '/vikoba/index.php',
  '/vikoba/public/css/style.css',
  '/vikoba/public/js/app.js',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css',
  'https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css',
];

// Install event - cache static assets
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(STATIC_ASSETS);
    })
  );
  self.skipWaiting();
});

// Activate event - clean old caches
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames
          .filter((name) => name !== CACHE_NAME)
          .map((name) => caches.delete(name))
      );
    })
  );
  self.clients.claim();
});

// Fetch event - serve from cache, fallback to network
self.addEventListener('fetch', (event) => {
  // Only handle GET requests
  if (event.request.method !== 'GET') return;

  // Skip non-http(s) requests
  if (!event.request.url.startsWith('http')) return;

  event.respondWith(
    caches.match(event.request).then((cachedResponse) => {
      if (cachedResponse) {
        // Return cached response and update in background
        event.waitUntil(updateCache(event.request));
        return cachedResponse;
      }

      // Not in cache - fetch from network
      return fetch(event.request)
        .then((response) => {
          // Cache successful responses
          if (response.ok) {
            const clone = response.clone();
            event.waitUntil(
              caches.open(CACHE_NAME).then((cache) => {
                // Only cache same-origin requests
                if (event.request.url.startsWith(self.location.origin)) {
                  cache.put(event.request, clone);
                }
              })
            );
          }
          return response;
        })
        .catch(() => {
          // Network failed - return offline page for navigation requests
          if (event.request.mode === 'navigate') {
            return caches.match('/vikoba/pages/offline.php');
          }
          return new Response('Offline', { status: 503 });
        });
    })
  );
});

// Background sync for offline actions
self.addEventListener('sync', (event) => {
  if (event.tag === 'sync-payments') {
    event.waitUntil(syncPendingPayments());
  }
});

// Push notification handler
self.addEventListener('push', (event) => {
  const data = event.data.json();
  const options = {
    body: data.body || 'New notification from VIKOBA',
    icon: '/vikoba/public/img/icon-192x192.png',
    badge: '/vikoba/public/img/badge-72x72.png',
    vibrate: [200, 100, 200],
    data: {
      url: data.url || '/vikoba/',
    },
  };

  event.waitUntil(
    self.registration.showNotification(data.title || 'VIKOBA', options)
  );
});

// Notification click handler
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  event.waitUntil(
    clients.openWindow(event.notification.data.url || '/vikoba/')
  );
});

// Update cache in background
function updateCache(request) {
  return caches.open(CACHE_NAME).then((cache) => {
    return fetch(request).then((response) => {
      if (response.ok && request.url.startsWith(self.location.origin)) {
        return cache.put(request, response);
      }
    }).catch(() => {});
  });
}

// Sync pending payments when online
function syncPendingPayments() {
  return self.indexedDB.open('vikoba-offline', 1).then((db) => {
    const transaction = db.transaction(['pending-payments'], 'readonly');
    const store = transaction.objectStore('pending-payments');
    return store.getAll();
  }).then((payments) => {
    return Promise.all(payments.map((payment) => {
      return fetch('/vikoba/pages/ajax_sync_payment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payment),
      });
    }));
  });
}