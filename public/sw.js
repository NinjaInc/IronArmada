const CACHE_NAME = 'iron-armada-v1';
const STATIC_PREFIXES = ['/build/', '/img/', '/fonts/'];

const OFFLINE_HTML = `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0b1117">
  <title>Iron Armada - Offline</title>
  <style>
    body {
      margin: 0;
      min-height: 100vh;
      display: grid;
      place-items: center;
      background: #0b1117;
      color: #d7e2ec;
      font-family: system-ui, -apple-system, sans-serif;
      text-align: center;
      padding: 24px;
    }
    main { max-width: 360px; }
    h1 { font-size: 1.25rem; margin: 0 0 8px; color: #f4a24a; }
    p { margin: 0 0 16px; color: #9ab0c4; line-height: 1.5; }
    button {
      background: #1e3348;
      color: #eef5fb;
      border: 1px solid #3c6082;
      border-radius: 4px;
      padding: 10px 18px;
      font-size: 1rem;
      cursor: pointer;
    }
  </style>
</head>
<body>
  <main>
    <h1>Connection Lost</h1>
    <p>Iron Armada requires an active connection to sync your empire and fleets.</p>
    <button type="button" onclick="window.location.reload()">Retry</button>
  </main>
</body>
</html>`;

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    // Never intercept or cache live API/auth/broadcasting endpoints.
    if (
        url.pathname.startsWith('/api/') ||
        url.pathname.startsWith('/broadcasting/') ||
        url.pathname.startsWith('/sanctum/')
    ) {
        return;
    }

    // Navigation requests: network-first so game state & CSRF tokens stay fresh.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(
                () =>
                    new Response(OFFLINE_HTML, {
                        status: 503,
                        headers: { 'Content-Type': 'text/html; charset=utf-8' },
                    })
            )
        );
        return;
    }

    // Static versioned assets: stale-while-revalidate.
    if (STATIC_PREFIXES.some((prefix) => url.pathname.startsWith(prefix))) {
        event.respondWith(
            caches.open(CACHE_NAME).then((cache) =>
                cache.match(request).then((cached) => {
                    const networkFetch = fetch(request)
                        .then((response) => {
                            if (response && response.ok) {
                                cache.put(request, response.clone());
                            }
                            return response;
                        })
                        .catch(() => cached);
                    return cached || networkFetch;
                })
            )
        );
    }
});
