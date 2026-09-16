<?php
// Payment configuration
// Configure these in the local .env/environment. Never commit payment secrets.

// Stripe API Keys
if (!defined('STRIPE_SECRET')) {
    define('STRIPE_SECRET', getenv('STRIPE_SECRET') ?: '');
}
if (!defined('STRIPE_PUBLISHABLE')) {
    define('STRIPE_PUBLISHABLE', getenv('STRIPE_PUBLISHABLE') ?: '');
}
if (!defined('STRIPE_WEBHOOK_SECRET')) {
    define('STRIPE_WEBHOOK_SECRET', getenv('STRIPE_WEBHOOK_SECRET') ?: '');
}

// Default currency (ISO 4217, lowercase for Stripe)
if (!defined('PAYMENT_CURRENCY')) {
    define('PAYMENT_CURRENCY', 'php'); // Philippine Peso
}

// Allowed manual payment methods (POS). Keep cash-only for now.
if (!defined('ALLOWED_MANUAL_METHODS')) {
    define('ALLOWED_MANUAL_METHODS', ['cash']);
}

// Manual payment methods allowed in the POS keypad.
// Only 'cash' should be enabled for now.
if (!defined('ALLOWED_MANUAL_METHODS')) {
    define('ALLOWED_MANUAL_METHODS', ['cash']);
}

// Toggle online payments (Stripe Checkout) visibility/usage in the UI.
// Keep disabled for now as requested.
if (!defined('ONLINE_PAYMENTS_ENABLED')) {
    define('ONLINE_PAYMENTS_ENABLED', false);
}

// Helper to build absolute URLs reliably based on current request
function app_base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // This file will be included from public/*; so script_name points inside /public
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    return $scheme . '://' . $host . $basePath;
}
