<?php

function settings_defaults(): array
{
    return [
        'business_name' => 'Kape Agosto',
        'owner_name' => '',
        'bir_registered_name' => '',
        'bir_tax_type' => '',
        'bir_tin' => '',
        'bir_branch_code' => '',
        'business_tagline' => 'Coffee Shop',
        'business_address' => '1157 R. Papa St. Corner F. Aguilar St. Barrio Obrero, Tondo, Manila, PH',
        'business_phone' => '0915-292-6848',
        'business_email' => 'kape.agosto@gmail.com',
        'google_maps_url' => 'https://maps.app.goo.gl/6JhoL7kvme5pWtWz5',
        'receipt_thank_you' => 'Thank you for choosing Kape Agosto',
        'currency_label' => 'PHP',
        'timezone' => 'Asia/Manila',
        'system_mode' => 'active',
        'sidebar_status_title' => 'System Status',
        'sidebar_status_copy' => 'Operations are ready for today.',
        'maintenance_message' => 'Transactions are temporarily unavailable while system maintenance is in progress.',
        'smtp_enabled' => '0',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_encryption' => 'tls',
        'smtp_username' => '',
        'smtp_password' => '',
        'smtp_from_email' => '',
        'smtp_from_name' => 'Kape Agosto',
        'smtp_reply_to' => '',
        'ai_enabled' => '0',
        'ai_provider' => 'openai',
        'ai_model' => '',
        'ai_api_key' => '',
        'sales_target_daily' => '0',
        'sales_target_weekly' => '0',
        'sales_target_monthly' => '0',
        'performance_period_days' => '30',
        'promo_discount_percent' => '0',
        'promo_discount_start_date' => '',
        'promo_discount_end_date' => '',
    ];
}

function settings_save_manager_targets(PDO $pdo, array $values): void
{
    settings_ensure_schema($pdo);

    $allowed = [
        'sales_target_daily' => [0, 10000000],
        'sales_target_weekly' => [0, 50000000],
        'sales_target_monthly' => [0, 200000000],
        'performance_period_days' => [1, 365],
    ];

    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");

    foreach ($allowed as $key => [$minimum, $maximum]) {
        if (!array_key_exists($key, $values)) {
            continue;
        }
        if (!is_numeric($values[$key])) {
            throw new InvalidArgumentException('Performance target values must be numeric.');
        }
        $number = (float) $values[$key];
        if ($number < $minimum || $number > $maximum) {
            throw new InvalidArgumentException('A performance target value is outside the allowed range.');
        }
        $stored = $key === 'performance_period_days' ? (string) (int) $number : number_format($number, 2, '.', '');
        $stmt->execute([$key, $stored]);
    }
}

function settings_normalize_promo_values(array $values): array
{
    $percent = $values['promo_discount_percent'] ?? '0';
    if (!is_numeric($percent) || !is_finite((float) $percent) || (float) $percent < 0 || (float) $percent > 100) {
        throw new InvalidArgumentException('Promotional discount must be a percentage from 0 to 100.');
    }

    $startDate = trim((string) ($values['promo_discount_start_date'] ?? ''));
    $endDate = trim((string) ($values['promo_discount_end_date'] ?? ''));
    foreach (['start' => $startDate, 'end' => $endDate] as $label => $date) {
        if ($date === '') continue;
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed || $parsed->format('Y-m-d') !== $date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('Enter valid promo start and end dates.');
        }
    }
    if (($startDate === '') !== ($endDate === '')) {
        throw new InvalidArgumentException('Enter both promo start and end dates, or leave both blank.');
    }
    if ($startDate !== '' && $startDate > $endDate) {
        throw new InvalidArgumentException('Promo start date must be on or before its end date.');
    }

    return [
        'promo_discount_percent' => number_format((float) $percent, 2, '.', ''),
        'promo_discount_start_date' => $startDate,
        'promo_discount_end_date' => $endDate,
    ];
}

function settings_save_promo(PDO $pdo, array $values): void
{
    settings_ensure_schema($pdo);
    $normalized = settings_normalize_promo_values(array_replace(settings_all($pdo), $values));
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
    foreach ($normalized as $key => $value) {
        $stmt->execute([$key, $value]);
    }
}

function settings_promo_is_active(array $settings): bool
{
    $percent = (float) ($settings['promo_discount_percent'] ?? 0);
    if (!is_finite($percent) || $percent <= 0) return false;

    $startDate = trim((string) ($settings['promo_discount_start_date'] ?? ''));
    $endDate = trim((string) ($settings['promo_discount_end_date'] ?? ''));
    if ($startDate === '' && $endDate === '') return true;
    if ($startDate === '' || $endDate === '' || $startDate > $endDate) return false;

    try {
        $timezone = new DateTimeZone((string) ($settings['timezone'] ?? 'Asia/Manila'));
    } catch (Throwable $e) {
        $timezone = new DateTimeZone('Asia/Manila');
    }
    $today = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
    return $today >= $startDate && $today <= $endDate;
}

function settings_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_key = setting_key
    ");

    foreach (settings_defaults() as $key => $value) {
        $stmt->execute([$key, $value]);
    }

    $legacyTagline = $pdo->prepare("
        UPDATE system_settings
        SET setting_value = ?
        WHERE setting_key = 'business_tagline'
          AND setting_value IN ('Mobile Coffee Bar', 'Mobile Coffee Bar and Events.')
    ");
    $legacyTagline->execute(['Coffee Shop']);

    $ready = true;
}

function settings_all(PDO $pdo): array
{
    settings_ensure_schema($pdo);

    $settings = settings_defaults();
    $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

    foreach ($rows as $key => $value) {
        if (array_key_exists($key, $settings)) {
            $settings[$key] = (string) $value;
        }
    }

    return $settings;
}

function settings_save(PDO $pdo, array $values): void
{
    settings_ensure_schema($pdo);

    $values = settings_normalize_smtp_values($values);
    $currentSettings = settings_all($pdo);
    if (array_key_exists('ai_provider', $values) && ($values['ai_provider'] ?? '') !== $currentSettings['ai_provider'] && trim((string) ($values['ai_api_key'] ?? '')) === '' && ($values['clear_ai_api_key'] ?? '0') !== '1') {
        throw new InvalidArgumentException('Enter the matching API key when switching AI providers.');
    }
    if (($values['ai_automatic_model'] ?? '0') === '1') $values['ai_model'] = '';
    if (array_intersect(['promo_discount_percent', 'promo_discount_start_date', 'promo_discount_end_date'], array_keys($values))) {
        $promoValues = settings_normalize_promo_values(array_replace($currentSettings, $values));
        $values = array_replace($values, $promoValues);
    }
    if (
        (string) ($values['clear_smtp_password'] ?? '0') !== '1'
        && trim((string) ($values['smtp_password'] ?? '')) === ''
        && trim(settings_value($currentSettings, 'smtp_password')) !== ''
    ) {
        $values['smtp_password'] = settings_value($currentSettings, 'smtp_password');
    }
    if (
        (string) ($values['clear_ai_api_key'] ?? '0') !== '1'
        && trim((string) ($values['ai_api_key'] ?? '')) === ''
        && trim(settings_value($currentSettings, 'ai_api_key')) !== ''
    ) {
        $values['ai_api_key'] = settings_value($currentSettings, 'ai_api_key');
    }
    settings_validate_smtp_values($values);
    settings_validate_ai_values($values);
    $birValues = settings_normalize_bir_values(array_replace($currentSettings, $values));
    foreach (['bir_registered_name', 'bir_tax_type', 'bir_tin', 'bir_branch_code'] as $birKey) {
        if (array_key_exists($birKey, $values)) {
            $values[$birKey] = $birValues[$birKey];
        }
    }
    $defaults = settings_defaults();
    $clearSmtpPassword = (string) ($values['clear_smtp_password'] ?? '0') === '1';
    $clearAiApiKey = (string) ($values['clear_ai_api_key'] ?? '0') === '1';
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");

    foreach ($defaults as $key => $default) {
        if (!array_key_exists($key, $values)) {
            continue;
        }

        $value = trim((string) $values[$key]);
        if ($key === 'owner_name') {
            $ownerNameLength = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
            if ($ownerNameLength > 150) {
                throw new InvalidArgumentException('Owner name must be 150 characters or fewer.');
            }
        }
        if ($key === 'smtp_password' && $clearSmtpPassword) {
            $stmt->execute([$key, '']);
            continue;
        }

        if ($key === 'ai_api_key' && $clearAiApiKey) {
            $stmt->execute([$key, '']);
            continue;
        }

        if ($key === 'smtp_password' && $value === '') {
            continue;
        }

        if ($key === 'ai_api_key' && $value === '') {
            continue;
        }

        if ($key === 'currency_label') {
            $value = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $value), 0, 6));
            $value = $value !== '' ? $value : $default;
        }

        if ($key === 'timezone') {
            $value = in_array($value, settings_timezone_options(), true) ? $value : $default;
        }

        if ($key === 'system_mode') {
            $value = in_array($value, ['active', 'maintenance'], true) ? $value : $default;
        }

        if ($key === 'smtp_enabled') {
            $value = $value === '1' ? '1' : '0';
        }

        if ($key === 'ai_enabled') {
            $value = $value === '1' ? '1' : '0';
        }

        if ($key === 'ai_provider') {
            $value = in_array($value, settings_ai_provider_options(), true) ? $value : $default;
        }

        if ($key === 'smtp_port') {
            $port = (int) $value;
            $value = $port > 0 && $port <= 65535 ? (string) $port : $default;
        }

        if ($key === 'smtp_encryption') {
            $value = in_array($value, settings_smtp_encryption_options(), true) ? $value : $default;
        }

        if ($key === 'smtp_password') {
            $value = trim($value);
            $smtpHost = strtolower((string) ($values['smtp_host'] ?? ''));
            if ($value !== '' && strpos($smtpHost, 'gmail.com') !== false) {
                $value = preg_replace('/\s+/', '', $value) ?? '';
            }
        }

        $stmt->execute([$key, $value]);
    }
}

function settings_normalize_bir_values(array $values): array
{
    $name = trim((string) ($values['bir_registered_name'] ?? ''));
    $type = trim((string) ($values['bir_tax_type'] ?? ''));
    $tin = trim((string) ($values['bir_tin'] ?? ''));
    $branch = trim((string) ($values['bir_branch_code'] ?? ''));
    if (strlen($name) > 250) {
        throw new InvalidArgumentException('BIR registered name must be 250 bytes or fewer.');
    }
    if (!in_array($type, ['', 'vat', 'non_vat'], true)) {
        throw new InvalidArgumentException('Select VAT or NON-VAT as shown on your BIR certificate.');
    }
    if ($tin !== '' && !preg_match('/^(?:[0-9]{9}|[0-9]{3}-[0-9]{3}-[0-9]{3})$/D', $tin)) {
        throw new InvalidArgumentException('Enter a 9-digit TIN, such as 123-456-789. Enter the branch code separately.');
    }
    if ($branch !== '' && !preg_match('/^[0-9]{3,5}$/D', $branch)) {
        throw new InvalidArgumentException('Branch code must contain 3 to 5 digits, including leading zeros.');
    }
    if (($type !== '' && $tin === '') || ($tin !== '' && $type === '') || ($branch !== '' && $tin === '')) {
        throw new InvalidArgumentException('Enter both the BIR tax type and TIN, or leave both blank if not yet configured.');
    }
    $digits = str_replace('-', '', $tin);
    return [
        'bir_registered_name' => $name,
        'bir_tax_type' => $type,
        'bir_tin' => $digits === '' ? '' : substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 3),
        'bir_branch_code' => $branch,
    ];
}

function settings_save_ai(PDO $pdo, array $values): void
{
    $allowedKeys = [
        'ai_enabled',
        'ai_provider',
        'ai_model',
        'ai_api_key',
        'clear_ai_api_key',
        'ai_automatic_model',
    ];

    $aiValues = array_intersect_key($values, array_flip($allowedKeys));
    settings_save($pdo, $aiValues);
}

function settings_value(array $settings, string $key): string
{
    $defaults = settings_defaults();
    return (string) ($settings[$key] ?? $defaults[$key] ?? '');
}

function settings_timezone_options(): array
{
    return [
        'Asia/Manila',
        'Asia/Singapore',
        'UTC',
    ];
}

function settings_smtp_encryption_options(): array
{
    return [
        'tls',
        'ssl',
        'none',
    ];
}

function settings_ai_provider_options(): array
{
    return [
        'openai',
        'gemini',
    ];
}

function settings_normalize_smtp_values(array $values): array
{
    $host = trim((string) ($values['smtp_host'] ?? ''));
    $username = trim((string) ($values['smtp_username'] ?? ''));
    $fromEmail = trim((string) ($values['smtp_from_email'] ?? ''));
    $replyTo = trim((string) ($values['smtp_reply_to'] ?? ''));

    if (filter_var($host, FILTER_VALIDATE_EMAIL)) {
        $email = $host;
        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        $values['smtp_host'] = settings_guess_smtp_host($domain);

        if ($username === '') {
            $values['smtp_username'] = $email;
        }
        if ($fromEmail === '') {
            $values['smtp_from_email'] = $email;
        }
        if ($replyTo === '') {
            $values['smtp_reply_to'] = $email;
        }
    }

    $host = trim((string) ($values['smtp_host'] ?? ''));
    $username = trim((string) ($values['smtp_username'] ?? ''));
    if ($host !== '' && stripos($host, 'gmail.com') !== false && filter_var($username, FILTER_VALIDATE_EMAIL)) {
        $values['smtp_from_email'] = strtolower($username);
        if ($replyTo === '') {
            $values['smtp_reply_to'] = strtolower($username);
        }
    }

    return $values;
}

function settings_guess_smtp_host(string $domain): string
{
    if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
        return 'smtp.gmail.com';
    }
    if ($domain === 'outlook.com' || $domain === 'hotmail.com' || $domain === 'live.com') {
        return 'smtp.office365.com';
    }
    if ($domain === 'yahoo.com') {
        return 'smtp.mail.yahoo.com';
    }
    return $domain !== '' ? 'smtp.' . $domain : '';
}

function settings_validate_smtp_values(array $values): void
{
    $enabled = (string) ($values['smtp_enabled'] ?? '0') === '1';
    $host = trim((string) ($values['smtp_host'] ?? ''));
    $portRaw = trim((string) ($values['smtp_port'] ?? '587'));
    $encryption = strtolower(trim((string) ($values['smtp_encryption'] ?? 'tls')));
    $username = trim((string) ($values['smtp_username'] ?? ''));
    $password = trim((string) ($values['smtp_password'] ?? ''));
    $fromEmail = strtolower(trim((string) ($values['smtp_from_email'] ?? '')));

    if ($portRaw !== '' && (!ctype_digit($portRaw) || (int) $portRaw < 1 || (int) $portRaw > 65535)) {
        throw new InvalidArgumentException('SMTP port must be between 1 and 65535.');
    }

    if (!in_array($encryption, settings_smtp_encryption_options(), true)) {
        throw new InvalidArgumentException('SMTP encryption must be TLS, SSL, or None.');
    }

    if (strlen($host) > 190) {
        throw new InvalidArgumentException('SMTP host must be 190 characters or less.');
    }

    if (strlen($username) > 190) {
        throw new InvalidArgumentException('SMTP username must be 190 characters or less.');
    }

    if (strlen($password) > 500) {
        throw new InvalidArgumentException('SMTP password must be 500 characters or less.');
    }

    if (strlen($fromEmail) > 190) {
        throw new InvalidArgumentException('From email must be 190 characters or less.');
    }

    if (!$enabled) {
        if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('From email format is invalid.');
        }
        return;
    }

    if ($host === '') {
        throw new InvalidArgumentException('SMTP host is required when SMTP sending is enabled.');
    }

    if (filter_var($host, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('SMTP host must be a server address like smtp.gmail.com, not an email address.');
    }

    if (stripos($host, 'gmail.com') !== false && $username === '') {
        throw new InvalidArgumentException('Gmail SMTP requires the full Gmail address as the SMTP username.');
    }

    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('A valid From Email is required when SMTP sending is enabled.');
    }

    if ($username !== '' && $password === '') {
        throw new InvalidArgumentException('SMTP password is required when SMTP username is set.');
    }

    if (stripos($host, 'gmail.com') !== false && $username !== '' && filter_var($username, FILTER_VALIDATE_EMAIL)) {
        if (strtolower($fromEmail) !== strtolower($username)) {
            throw new InvalidArgumentException('For Gmail, From Email must match the SMTP username.');
        }
    }
}

function settings_validate_ai_values(array $values): void
{
    $enabled = (string) ($values['ai_enabled'] ?? '0') === '1';
    $provider = strtolower(trim((string) ($values['ai_provider'] ?? 'openai')));
    $model = trim((string) ($values['ai_model'] ?? ''));
    $apiKey = trim((string) ($values['ai_api_key'] ?? ''));

    if (!in_array($provider, settings_ai_provider_options(), true)) {
        throw new InvalidArgumentException('AI provider must be OpenAI or Gemini.');
    }

    if (strlen($model) > 120) {
        throw new InvalidArgumentException('AI model must be 120 characters or less.');
    }

    if (strlen($apiKey) > 500) {
        throw new InvalidArgumentException('AI API key must be 500 characters or less.');
    }

    if ($enabled && $apiKey === '') {
        throw new InvalidArgumentException('AI API key is required when AI analytics is enabled.');
    }
}
