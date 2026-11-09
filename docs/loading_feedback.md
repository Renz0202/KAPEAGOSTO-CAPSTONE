# Branded loading feedback

`public/partials/stylesheets.php` loads `app-loading.css` and `app-loading.js` through canonical, versioned asset URLs. The small loading controller runs in the head so it can respond during initial parsing and before a real page departure. It uses the existing `NEWLOGO_WHITE.png` for page loading and `CUP_WHITE.png` for compact update feedback. There are no new image assets or dependencies.

Page feedback appears after a short delay: 450 ms for initial loading and 350 ms for navigation/reloading. Background polling uses a non-blocking indicator after 900 ms; explicit inventory/history searches and payment saves use 350 ms. Fast requests finish silently. No request is artificially slowed and no fabricated percentage is shown. The initial HTML must reach the browser before a first-visit indicator can run; navigation from an already open module can show feedback while the next page is being requested.

The controller does not intercept links, forms, clicks or fetch. Actual page departures use `beforeunload`, including browser refresh; automatic live reload explicitly supplies a refresh label. Each asynchronous integration calls `KapeLoading.start()` and completes its own callback in `finally`, after response parsing and rendering. Dashboard polling, Current Stock, Movement History, Voided/Cancelled, Customer History, order list refresh, payment saves/refresh and notification acknowledgement are connected. Existing business handlers, errors, form validation and submit guards are retained.

```javascript
const finishLoading = window.KapeLoading?.start({ label: 'Updating records...' });
try {
  // Await the request, read its body, and update the existing view.
} finally {
  finishLoading?.();
}
```

Concurrent requests have independent completion callbacks; stale or aborted requests cannot hide another pending update. A page transition takes priority over the compact update indicator. A 20-second display timeout prevents a permanent visual mask without cancelling the underlying application request. Initial loading also releases six seconds after DOM readiness if an external asset stalls. Page hide, browser cache restoration and printing clear the indicator. Cancelled departures release navigation feedback. Hidden tabs keep feedback hidden until visible again.

The status uses a polite live region without moving focus or capturing pointer input. Reduced-motion preferences disable the animation. The logo has a text fallback. Receipt pages and printing exclude the indicator; a page can also use `data-kape-loading="off"` on its body. Loader styles contain no sidebar sizing or document-flow changes.

Validation: `node tests/app_loading_test.js` covers 31 loading lifecycle and presentation constraints. Existing inventory, customer-history and viewport interaction checks also pass. Changed PHP and standalone/inline JavaScript parse successfully; the shared include produces correct paths from nested modules, and both loader assets and logo files return HTTP 200 from localhost. Live browser visual review remains unavailable because the Windows browser helper cannot initialize.
