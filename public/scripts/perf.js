/* Lightweight performance helpers: faster boot and reloads */
(function(){
  'use strict';

  var d = document;

  // Preconnect to common CDNs early to reduce DNS/TLS cost on future navigations.
  var preconnectHosts = [
    'https://cdn.jsdelivr.net',
    'https://cdnjs.cloudflare.com',
    'https://code.jquery.com',
    'https://fonts.googleapis.com',
    'https://fonts.gstatic.com',
    'https://www.google.com',
    'https://www.gstatic.com'
  ];
  try {
    preconnectHosts.forEach(function(href){
      var l = d.createElement('link');
      l.rel = 'preconnect';
      l.href = href;
      l.crossOrigin = 'anonymous';
      d.head && d.head.appendChild(l);
    });
  } catch(_){}

  // Remove legacy offline caching. Kape Agosto is an online-only system.
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function(){
      var legacyWorkerUrl = new URL('sw.js', document.baseURI).href;
      navigator.serviceWorker.getRegistrations().then(function(registrations){
        registrations.forEach(function(registration){
          var worker = registration.active || registration.waiting || registration.installing;
          if (worker && worker.scriptURL === legacyWorkerUrl) {
            registration.unregister().catch(function(){ /* ignore */ });
          }
        });
      }).catch(function(){ /* ignore */ });
    });
  }

  if ('caches' in window) {
    window.addEventListener('load', function(){
      caches.keys().then(function(keys){
        keys.forEach(function(key){
          if (key.indexOf('kapeagosto-cache-') === 0) {
            caches.delete(key).catch(function(){ /* ignore */ });
          }
        });
      }).catch(function(){ /* ignore */ });
    });
  }

  function onReady(cb){
    if (d.readyState === 'complete' || d.readyState === 'interactive') cb();
    else d.addEventListener('DOMContentLoaded', cb, {once:true});
  }

  onReady(function(){
    // Ensure all non-critical images lazy-load by default.
    try {
      var imgs = d.getElementsByTagName('img');
      for (var i=0;i<imgs.length;i++){
        var img = imgs[i];
        // Respect explicit eager images or background slideshows.
        if (img.hasAttribute('loading')) continue;
        if (img.getAttribute('fetchpriority') === 'high') continue;
        img.setAttribute('loading','lazy');
        img.setAttribute('decoding','async');
      }
    } catch(_){}

    // Idle-time tasks: cleanup, font swap classes, etc.
    var ric = window.requestIdleCallback || function(fn){ return setTimeout(fn, 500); };
    ric(function(){
      try {
        d.documentElement.classList.add('perf-ready');
      } catch(_){}
    });
  });
})();
