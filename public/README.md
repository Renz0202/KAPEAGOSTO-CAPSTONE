# Public Folder Structure

The original public URLs are preserved by `.htaccess` rewrite rules so existing links, forms, redirects, and AJAX calls continue to work.

The public root is kept clean: route PHP files live inside module folders instead of directly in `public/`.

## Route Modules

- `pages/` - welcome, dashboard, profile, and general pages
- `auth/` - login, logout, password reset, and authentication actions
- `users/` - user management pages and actions
- `catalog/` - categories and item management
- `inventory_module/` - inventory, stock, deduction, and inventory data endpoints
- `orders/` - order creation, order list, voiding, receipts, and order data endpoints
- `payments/` - payment creation, webhook, manual payment, and payment data endpoints
- `reports_module/` - report pages and daily report generation
- `maintenance/` - backup, restore, mail diagnostics, version, and admin utilities
- `legacy_routes/` - archived compatibility wrapper files from the previous root layout; direct browser access is blocked by `.htaccess`

## Public Assets And Data

- `assets/`, `css/`, `scripts/`, `img/` - browser assets
- `uploads/` - uploaded user/category/item files
- `reports/` - generated report data
- `partials/` - shared PHP partials, including the stylesheet and asset URL helpers

Keep route PHP files inside their module folders. If a new old-style URL is needed, add a matching rewrite rule in `.htaccess`.

For shared styles, include `partials/stylesheets.php` in PHP pages. Page CSS should live in `css/style.css`, not inside PHP `<style>` blocks.

Page-specific styles extracted from PHP are scoped with body classes such as `kape-auth-login` and `kape-pages-welcome` so they do not leak into other modules.

Generated email/export HTML may still use inline CSS because it must be self-contained outside the normal app page layout.

## Connectivity Requirement

Direct sign-in at localhost, a loopback address, or the shop server's private IP address works without internet. Both the device and server must be on the local network, with Apache and MySQL running. Local sign-in checks the existing account password, active status, and role in the server database. When internet is available, reCAPTCHA appears and must be completed. During an internet outage, local sign-in becomes available after Google fails to load or the browser reports a lost connection. The server independently checks Google before accepting a local fallback; if Google can be reached, a valid reCAPTCHA token is still required. Use the server's private IP address when signing in from another shop device.

Public-domain, public-IP, and proxied sign-in still require Google reCAPTCHA. Local mode is decided from the direct client/server addresses and request hostname; browser offline flags and forwarded IP headers cannot bypass online verification. Failed, expired, or malformed CAPTCHA responses are rejected whenever Google responds. The widget retries after connection failures and returns when internet is restored. All sign-ins require a session form token. Five failed attempts for a username from one client address trigger a wait of up to 15 minutes; a client address also has a budget of 30 total attempts per 15 minutes. The limits persist across browser sessions in locked storage outside the web root.

This supports internet outages while the local server is reachable. It does not provide access when the local server or shop network is unavailable, or add offline data synchronization. Email recovery, online payments, and other external services still require internet. The local login page loads its assets from the server, without waiting for external fonts or scripts. Other pages may use browser font and icon fallbacks during an outage. Service-worker asset caching remains disabled.

## Core Module Alignment Migration

Existing installations must run the following once after updating the code:

```powershell
C:\xampp\php\php.exe database\migrations\20260812_align_core_modules.php
```

The migration is idempotent and adds customer/job-order history, product and inventory SKUs, audited and idempotent inventory deductions, persistent notifications, and forecast snapshots. Historical orders and payments are retained permanently; the old daily order-reset endpoint is disabled.

Run the current inventory loss-cost and customer-discount migrations once after updating the code:

```powershell
C:\xampp\php\php.exe database\migrations\20261028_add_inventory_loss_costs.php
C:\xampp\php\php.exe database\migrations\20261028_add_order_discounts.php
```

These migrations preserve existing stock and order totals. Unit-cost estimates are marked separately from verified purchase costs. Admins set the POS promotional rate and optional inclusive date range in System Settings; managers set them in Forecast & Team Settings. All roles may apply the saved rate only while its date range is active, but it cannot be changed in the POS. Senior/PWD checkout records the beneficiary ID and applies the more favorable eligible discount without stacking.

Local predictive analytics work without an API key. If OpenAI analytics is enabled in System Settings, the dashboard sends aggregate operational metrics (not customer details) to the Responses API at most once every six hours for a concise recommendation.
