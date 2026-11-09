# Account Guide

Open the account dropdown in the Admin, Manager, or Cashier header and select Guide. It opens instructions for the current page. Search finds page names, tabs, and actions.

- New accounts receive a three-step welcome on their first page with a shared header.
- Finish, Skip, close, or Escape dismisses the initial welcome. The preference is stored per account.
- Existing accounts keep manual Guide access without an automatic welcome.
- Replay welcome is always available inside Guide.
- Find a control on this page focuses and highlights a visible control; it does not activate it.
- Explain controls closes the dialog and enters a labeled help mode. Select a tab, icon, button, or field to read its purpose. Exit guide mode or press Escape to resume normal use.
- Help mode blocks business clicks, typing, form submissions, and checkout shortcuts. The guide dialog also isolates page keyboard shortcuts.
- Content and assets are served locally. A failed preference request is retained in the browser session and retried when connectivity returns or the page reloads.

Your Role & Tasks explains the role assigned by an admin, its usual workflow, and when admin help is needed. Every page guide states what that role can do. An admin role change is reflected after the user refreshes or opens a page. This feature does not change permissions or add individual task assignments.

Topics follow account access. Managers receive review and planning topics; Cashiers receive menu, order, payment, and account topics. Guide links open viewing pages, never generation, stock, payment, or deletion endpoints.

The Admin and Manager Dashboard guide covers the Sales & Orders, Inventory Forecasting, and AI Sales Analytics tabs. It explains stock coverage and purchase planning, including temporary unit-cost estimates, and distinguishes local forecasts from optional AI recommendations. AI requests are manual, subject to a 30-second cooldown, and successful recommendations are cached for six hours. The Admin System Settings topic covers BIR receipt identifiers, business and receipt details, the POS promo rate and inclusive date window, system status, SMTP, AI settings, and backup access. Cashier topics explain the read-only active promo, statutory discount checks, payment and cash-change review, and receipt printing. Keep `includes/user_guide_catalog.php` aligned with `public/settings/settings.php`, `public/orders/orders.php`, `public/payments/order_payment.php`, `public/orders/receipt_template.php`, and the dashboard and inventory workspace when controls change.

## Installation

Fresh schema: the progress table is included in db.sql. Existing installations:

    C:\xampp\php\php.exe database\migrations\20261010_add_user_guide.php

The migration adds user_guide_progress and an installation marker in system_settings. Existing users are initialized once. Re-running does not dismiss a newer account's welcome. Normal page rendering performs no guide schema changes. Missing preference storage leaves manual help available.

The JSON preference endpoint authenticates the account, checks active status and CSRF, and takes the account ID only from the session. It accepts completed or skipped preferences only.

## Verification

    C:\xampp\php\php.exe tests\user_guide_test.php
    node tests/user_guide_workspace_test.js
    C:\xampp\php\php.exe tests\user_guide_shell_test.php admin
    C:\xampp\php\php.exe tests\user_guide_shell_test.php manager
    C:\xampp\php\php.exe tests\user_guide_shell_test.php cashier

PHP tests use connection-local temporary tables and disposable sessions. Interaction tests exercise the actual browser controller with isolated DOM and network fixtures. They cover role filtering, account isolation, installation reruns, CSRF validation, search, welcome replay, keyboard access, action prevention, and connection-loss retries.

Local rollout on 10 October 2026 initialized six existing accounts. Backup: backups/kape_agosto_20261010_075845.sql. Before/after hashes matched for every existing table except the additional installation marker in system_settings; all pre-existing settings also matched. No sample data was added.

Automated checks validate markup and controller behavior. Desktop/mobile visual inspection remains a manual check.
