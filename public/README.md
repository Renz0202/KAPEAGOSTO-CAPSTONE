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

Kape Agosto requires an active internet connection. Login depends on Google reCAPTCHA, and the system does not provide offline access or offline data synchronization. Service-worker asset caching is intentionally disabled so the interface does not suggest that offline operation is supported.

## Core Module Alignment Migration

Existing installations must run the following once after updating the code:

```powershell
C:\xampp\php\php.exe database\migrations\20260812_align_core_modules.php
```

The migration is idempotent and adds customer/job-order history, product and inventory SKUs, audited and idempotent inventory deductions, persistent notifications, and forecast snapshots. Historical orders and payments are retained permanently; the old daily order-reset endpoint is disabled.

Local predictive analytics work without an API key. If OpenAI analytics is enabled in System Settings, the dashboard sends aggregate operational metrics (not customer details) to the Responses API at most once every six hours for a concise recommendation.
