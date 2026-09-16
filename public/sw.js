/* Cleanup-only worker retained for browsers that installed an older offline cache. */
self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys
        .filter((key) => key.indexOf('kapeagosto-cache-') === 0)
        .map((key) => caches.delete(key))))
      .then(() => self.registration.unregister())
  );
});
