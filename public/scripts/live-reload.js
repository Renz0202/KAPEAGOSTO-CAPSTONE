(function(){
  // Simple live reload with version polling.
  var DISABLED = window.LIVE_RELOAD_DISABLED || /[?&]no-reload=1\b/.test(location.search);
  if (DISABLED) { return; }

  var POLL_MS_VISIBLE = 5000;
  var POLL_MS_HIDDEN  = 15000;
  var lastVersion = null;
  var reloading = false;

  function debug(){
    if (window.LIVE_RELOAD_DEBUG) {
      try { console.debug.apply(console, arguments); } catch(_) {}
    }
  }

  function fetchVersion(){
    var url = (window.LIVE_RELOAD_ENDPOINT || 'version.php') + '?t=' + Date.now();
    return fetch(url, {cache:'no-store', credentials:'same-origin'})
      .then(function(res){ return res.json(); })
      .then(function(data){ return (data && data.version) ? String(data.version) : null; })
      .catch(function(){ return null; });
  }

  function scheduleNext(){
    var delay = (document.visibilityState === 'visible') ? POLL_MS_VISIBLE : POLL_MS_HIDDEN;
    setTimeout(tick, delay);
  }

  function softReload(){
    if (reloading) return;
    reloading = true;
    window.KapeLoading?.navigate('Refreshing workspace...');

    try {
      var href = new URL(location.href);
      href.searchParams.set('_lr', Date.now());
      location.replace(href.toString());
    } catch(_) {
      location.reload();
    }
  }

  function tick(){
    if (DISABLED) return;
    fetchVersion().then(function(v){
      if (!v) { scheduleNext(); return; }
      if (lastVersion === null) {
        lastVersion = v;
        debug('[live-reload] initial version', v);
        scheduleNext();
        return;
      }
      if (v !== lastVersion) {
        debug('[live-reload] change detected', lastVersion, '->', v);
        softReload();
        return;
      }
      scheduleNext();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', tick);
  } else {
    tick();
  }
})();
