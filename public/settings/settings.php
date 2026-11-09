<?php
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/settings.php';

require_admin();

$pdo = db();
require_once dirname(__DIR__, 2) . '/includes/ai_connection_check.php';
$flash = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        settings_save($pdo, $_POST);
        $flash = 'System settings updated successfully.';
    } catch (InvalidArgumentException $e) {
        $flash = $e->getMessage();
        $flashType = 'error';
    } catch (Throwable $e) {
        error_log('Settings save failed: ' . $e->getMessage());
        $flash = 'Settings could not be saved. Please try again.';
        $flashType = 'error';
    }
}

$settings = settings_all($pdo);
$smtpPasswordStored = trim(settings_value($settings, 'smtp_password')) !== '';
$aiApiKeyStored = trim(settings_value($settings, 'ai_api_key')) !== '';

function setting_field_value(array $settings, string $key): string
{
    return htmlspecialchars(settings_value($settings, $key), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>System Settings - Kape Agosto</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
  <link rel="stylesheet" href="<?= htmlspecialchars(kape_asset_url('css/settings-workspace.css') . '?v=' . filemtime(dirname(__DIR__) . '/css/settings-workspace.css')) ?>">
</head>
<body class="kape-settings-page">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<main class="content settings-main">
  <div class="settings-breadcrumb" aria-label="Breadcrumb">
    <span><?= htmlspecialchars(role_display_name(current_user_role())) ?></span>
    <i class="fa fa-chevron-right"></i>
    <strong>System Settings</strong>
  </div>

  <section class="settings-hero">
    <div>
      <span class="settings-kicker">Control Center</span>
      <h1>System Settings</h1>
      <p>Manage business details, receipts, shared settings, and database backup and recovery.</p>
    </div>
    <a class="settings-back" href="dashboard.php">
      <i class="fa fa-arrow-left"></i>
      Back to Dashboard
    </a>
  </section>

  <?php if ($flash !== ''): ?>
    <div class="settings-alert <?= $flashType === 'error' ? 'danger' : 'success' ?>" role="alert">
      <i class="fa <?= $flashType === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
      <span><?= htmlspecialchars($flash) ?></span>
    </div>
  <?php endif; ?>

  <section class="settings-panel" aria-labelledby="settings-backup-title">
    <div class="settings-panel-intro">
      <span class="settings-panel-icon"><i class="fa fa-database" aria-hidden="true"></i></span>
      <h2 id="settings-backup-title">Backup &amp; Restore</h2>
      <p>Protect system data and manage database recovery. Administrator access only.</p>
    </div>
    <div class="settings-grid">
      <div class="settings-span-2">
        <p>Create database backups, review available files and backup schedules, or restore a saved SQL backup.</p>
        <div class="settings-test-links">
          <a href="backup_restore.php" class="settings-test-link"><i class="fa fa-database" aria-hidden="true"></i> Open Backup &amp; Restore</a>
        </div>
      </div>
    </div>
  </section>

  <form method="post" class="settings-form" autocomplete="off">
    <section class="settings-panel">
      <div class="settings-panel-intro">
        <span class="settings-panel-icon"><i class="fa fa-file-invoice"></i></span>
        <h2>BIR Registration</h2>
        <p>Copy the details from your BIR Certificate of Registration (Form 2303).</p>
      </div>
      <div class="settings-grid">
        <label class="settings-span-2">
          <span>BIR Registered Name</span>
          <input type="text" name="bir_registered_name" value="<?= setting_field_value($settings, 'bir_registered_name') ?>" maxlength="250" placeholder="Registered taxpayer name on your certificate">
        </label>
        <label>
          <span>Tax Registration Type</span>
          <select name="bir_tax_type">
            <option value="">Not configured</option>
            <option value="vat" <?= settings_value($settings, 'bir_tax_type') === 'vat' ? 'selected' : '' ?>>VAT</option>
            <option value="non_vat" <?= settings_value($settings, 'bir_tax_type') === 'non_vat' ? 'selected' : '' ?>>NON-VAT</option>
          </select>
        </label>
        <label>
          <span>Taxpayer Identification Number (TIN)</span>
          <input type="text" name="bir_tin" value="<?= setting_field_value($settings, 'bir_tin') ?>" inputmode="numeric" maxlength="11" pattern="[0-9]{3}-[0-9]{3}-[0-9]{3}" placeholder="000-000-000" title="Use the format 000-000-000" aria-describedby="bir-tin-help">
          <small id="bir-tin-help">Format: 000-000-000. Enter the branch code separately.</small>
        </label>
        <label>
          <span>Branch Code</span>
          <input type="text" name="bir_branch_code" value="<?= setting_field_value($settings, 'bir_branch_code') ?>" inputmode="numeric" pattern="[0-9]{3,5}" maxlength="5" placeholder="As shown on your BIR certificate">
        </label>
        <div class="settings-help settings-span-2">These details appear on receipt printouts. Saving them does not register or verify your business with the BIR. VAT receipts show the included 12% VAT for regular menu sales; menu prices must already include VAT. NON-VAT receipts do not calculate VAT. Special VAT-exempt and zero-rated transactions are not supported by this checkout.</div>
      </div>
    </section>
    <section class="settings-panel">
      <div class="settings-panel-intro">
        <span class="settings-panel-icon"><i class="fa fa-percent"></i></span>
        <h2>POS Promotional Discount</h2>
        <p>Set the approved promo rate and optional date range. POS can apply the promo only during those dates.</p>
      </div>
      <div class="settings-grid">
        <label>
          <span>Promo Rate (%)</span>
          <input type="number" name="promo_discount_percent" min="0" max="100" step="0.01" value="<?= setting_field_value($settings, 'promo_discount_percent') ?>" required>
          <small>Enter 0 to turn off the promo.</small>
        </label>
        <label>
          <span>Promo Start Date (inclusive)</span>
          <input type="date" name="promo_discount_start_date" value="<?= setting_field_value($settings, 'promo_discount_start_date') ?>">
        </label>
        <label>
          <span>Promo End Date (inclusive)</span>
          <input type="date" name="promo_discount_end_date" value="<?= setting_field_value($settings, 'promo_discount_end_date') ?>">
        </label>
        <div class="settings-help settings-span-2">Leave both dates blank for a promo with no date limit. If you set dates, both are required; the promo remains active through the end date.</div>
      </div>
    </section>
    <section class="settings-panel">
      <div class="settings-panel-intro">
        <span class="settings-panel-icon"><i class="fa fa-store"></i></span>
        <h2>Business Profile</h2>
        <p>These details appear across the admin footer and receipt header.</p>
      </div>

      <div class="settings-grid">
        <label>
          <span>Business Name</span>
          <input type="text" name="business_name" value="<?= setting_field_value($settings, 'business_name') ?>" required>
        </label>
        <label>
          <span>Owner's Complete Name</span>
          <input type="text" name="owner_name" value="<?= setting_field_value($settings, 'owner_name') ?>" maxlength="150" autocomplete="name" placeholder="Enter the owner's full name">
          <small>This name appears in the owner approval signature area on printed reports.</small>
        </label>
        <label>
          <span>Tagline</span>
          <input type="text" name="business_tagline" value="<?= setting_field_value($settings, 'business_tagline') ?>" required>
        </label>
        <label>
          <span>Contact Number</span>
          <input type="text" name="business_phone" value="<?= setting_field_value($settings, 'business_phone') ?>" required>
        </label>
        <label>
          <span>Email Address</span>
          <input type="email" name="business_email" value="<?= setting_field_value($settings, 'business_email') ?>" required>
        </label>
        <label class="settings-span-2">
          <span>Google Maps Link</span>
          <input type="url" name="google_maps_url" value="<?= setting_field_value($settings, 'google_maps_url') ?>">
        </label>
      </div>
    </section>

    <section class="settings-panel">
      <div class="settings-panel-intro">
        <span class="settings-panel-icon"><i class="fa fa-receipt"></i></span>
        <h2>Receipt & Localization</h2>
        <p>Use these settings for receipt printouts and money labels.</p>
      </div>

      <div class="settings-grid">
        <label class="settings-span-2">
          <span>Business Address</span>
          <textarea name="business_address" rows="3" required><?= setting_field_value($settings, 'business_address') ?></textarea>
        </label>
        <label>
          <span>Receipt Thank You Message</span>
          <input type="text" name="receipt_thank_you" value="<?= setting_field_value($settings, 'receipt_thank_you') ?>" required>
        </label>
        <label>
          <span>Currency Label</span>
          <input type="text" name="currency_label" value="<?= setting_field_value($settings, 'currency_label') ?>" maxlength="6" required>
        </label>
        <label>
          <span>System Timezone</span>
          <select name="timezone">
            <?php foreach (settings_timezone_options() as $timezone): ?>
              <option value="<?= htmlspecialchars($timezone) ?>" <?= settings_value($settings, 'timezone') === $timezone ? 'selected' : '' ?>>
                <?= htmlspecialchars($timezone) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
    </section>

    <section class="settings-panel">
      <div class="settings-panel-intro">
        <span class="settings-panel-icon"><i class="fa fa-gauge-high"></i></span>
        <h2>System Status</h2>
        <p>Control transaction availability and the operational message shown throughout the system.</p>
      </div>

      <div class="settings-grid">
        <label>
          <span>System Mode</span>
          <select name="system_mode" id="system-mode">
            <option value="active" <?= settings_value($settings, 'system_mode') === 'active' ? 'selected' : '' ?>>Active — Transactions enabled</option>
            <option value="maintenance" <?= settings_value($settings, 'system_mode') === 'maintenance' ? 'selected' : '' ?>>Maintenance — Transactions paused</option>
          </select>
          <small>Maintenance mode blocks order creation, payment, completion, item changes, job updates, and voiding for every role.</small>
        </label>
        <label>
          <span>Active Message</span>
          <input type="text" name="sidebar_status_copy" value="<?= setting_field_value($settings, 'sidebar_status_copy') ?>" required>
        </label>
        <label class="settings-span-2">
          <span>Maintenance Message</span>
          <input type="text" name="maintenance_message" value="<?= setting_field_value($settings, 'maintenance_message') ?>" required>
          <small>This message is displayed when someone tries to open a transaction screen during maintenance.</small>
        </label>
        <div class="settings-help settings-span-2">
          <i class="fa fa-shield-halved"></i>
          <span>Order history, reports, inventory review, user management, and System Settings remain available. Existing external payment callbacks are still recorded safely.</span>
        </div>
      </div>
    </section>

    <section class="settings-panel">
      <div class="settings-panel-intro">
        <span class="settings-panel-icon"><i class="fa fa-envelope-open-text"></i></span>
        <h2>Email & SMTP</h2>
        <p>Configure outgoing email for Forgot Password reset links and system email tools.</p>
        <div class="settings-test-links">
          <a class="settings-test-link" href="test_email.php">
            <i class="fa fa-paper-plane"></i>
            Send Test Email
          </a>
          <a class="settings-test-link" href="mail_diag.php">
            <i class="fa fa-stethoscope"></i>
            Mail Diagnostics
          </a>
        </div>
      </div>

      <div class="settings-grid">
        <label class="settings-toggle settings-span-2">
          <input type="hidden" name="smtp_enabled" value="0">
          <input type="checkbox" name="smtp_enabled" value="1" <?= settings_value($settings, 'smtp_enabled') === '1' ? 'checked' : '' ?>>
          <span>Enable SMTP Sending</span>
        </label>
        <label>
          <span>SMTP Host</span>
          <input type="text" name="smtp_host" value="<?= setting_field_value($settings, 'smtp_host') ?>" placeholder="smtp.gmail.com">
        </label>
        <label>
          <span>SMTP Port</span>
          <input type="number" name="smtp_port" value="<?= setting_field_value($settings, 'smtp_port') ?>" min="1" max="65535">
        </label>
        <label>
          <span>Encryption</span>
          <select name="smtp_encryption">
            <?php foreach (settings_smtp_encryption_options() as $encryption): ?>
              <option value="<?= htmlspecialchars($encryption) ?>" <?= settings_value($settings, 'smtp_encryption') === $encryption ? 'selected' : '' ?>>
                <?= htmlspecialchars(strtoupper($encryption)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          <span>SMTP Username / Email</span>
          <input type="email" name="smtp_username" value="<?= setting_field_value($settings, 'smtp_username') ?>" placeholder="your-email@gmail.com" autocomplete="off">
        </label>
        <label>
          <span>SMTP Password</span>
          <input type="password" name="smtp_password" value="" placeholder="<?= $smtpPasswordStored ? 'Stored password detected. Leave blank to keep it.' : 'Enter SMTP App Password' ?>" autocomplete="new-password">
          <small><?= $smtpPasswordStored ? 'A password is currently saved. Enter a new one only to replace it.' : 'For Gmail, this must be a Google App Password, not your normal Gmail password.' ?></small>
        </label>
        <label class="settings-toggle">
          <input type="hidden" name="clear_smtp_password" value="0">
          <input type="checkbox" name="clear_smtp_password" value="1">
          <span>Clear stored SMTP password on save</span>
        </label>
        <label>
          <span>From Email</span>
          <input type="email" name="smtp_from_email" value="<?= setting_field_value($settings, 'smtp_from_email') ?>" placeholder="<?= setting_field_value($settings, 'business_email') ?>">
        </label>
        <label>
          <span>From Name</span>
          <input type="text" name="smtp_from_name" value="<?= setting_field_value($settings, 'smtp_from_name') ?>">
        </label>
        <label>
          <span>Reply-To Email</span>
          <input type="email" name="smtp_reply_to" value="<?= setting_field_value($settings, 'smtp_reply_to') ?>" placeholder="Optional">
        </label>
        <div class="settings-help settings-span-2">
          <i class="fa fa-circle-info"></i>
          <span>For Gmail: SMTP Host must be smtp.gmail.com, Port 587, Encryption TLS, Username is your Gmail address, and Password must be a Gmail App Password. Do not put your email address in SMTP Host.</span>
        </div>
        <div class="settings-help settings-span-2">
          <i class="fa fa-key"></i>
          <span>Gmail App Password setup: turn on 2-Step Verification in your Google Account, create an App Password, then paste that 16-character password here.</span>
        </div>
      </div>
    </section>

    <section class="settings-panel settings-ai-panel">
      <div class="settings-panel-intro">
        <span class="settings-panel-icon"><i class="fa fa-wand-magic-sparkles"></i></span>
        <h2>AI Sales Analytics</h2>
        <p>Optionally connect OpenAI or Gemini for generated operational recommendations. Forecasts, turnover, movement classes, and accuracy continue to run locally.</p>
      </div>

      <div class="settings-grid">
        <label class="settings-toggle settings-span-2">
          <input type="hidden" name="ai_enabled" value="0">
          <input type="checkbox" name="ai_enabled" value="1" <?= settings_value($settings, 'ai_enabled') === '1' ? 'checked' : '' ?>>
          <span>Enable AI-assisted analytics</span>
        </label>
        <label>
          <span>AI Provider</span>
          <select name="ai_provider">
            <?php foreach (settings_ai_provider_options() as $provider): ?>
              <option value="<?= htmlspecialchars($provider) ?>" <?= settings_value($settings, 'ai_provider') === $provider ? 'selected' : '' ?>>
                <?= htmlspecialchars(ucfirst($provider)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          <span>AI Model (optional)</span>
          <input type="text" name="ai_model" value="<?= setting_field_value($settings, 'ai_model') ?>" placeholder="Automatic provider default">
        </label>
        
        <label class="settings-span-2">
          <span>AI API Key</span>
          <input type="password" name="ai_api_key" value="" placeholder="<?= $aiApiKeyStored ? 'Stored API key detected. Leave blank to keep it.' : 'Paste your AI API key' ?>" autocomplete="new-password">
          <small><?= $aiApiKeyStored ? 'An API key is currently saved. Enter a new key only to replace it.' : 'The key is saved in system settings and is not displayed again after saving.' ?></small>
        </label>
        <?php include dirname(__DIR__) . '/partials/ai_connection_status.php'; ?>
        <label class="settings-toggle settings-span-2">
          <input type="hidden" name="clear_ai_api_key" value="0">
          <input type="checkbox" name="clear_ai_api_key" value="1">
          <span>Clear stored AI API key on save</span>
        </label>
        <details class="settings-span-2 ai-settings-help"><summary>How AI recommendations work</summary><p>Forecasts run locally. The selected provider generates recommendations from sales summaries, only when you click Generate, with results cached for six hours. API usage and limits depend on your provider.</p></details>
      </div>
    </section>

    <div class="settings-actions">
      <button type="submit" class="settings-save">
        <i class="fa fa-floppy-disk"></i>
        Save Settings
      </button>
      <a href="dashboard.php" class="settings-cancel">Cancel</a>
    </div>
  </form>
</main>

<script>
(() => {
  const tin = document.querySelector('input[name="bir_tin"]');
  if (!tin) return;
  const formatTin = () => {
    const cursor = tin.selectionStart;
    const digitsBeforeCursor = tin.value.slice(0, cursor).replace(/[^0-9]/g, '').length;
    const digits = tin.value.replace(/[^0-9]/g, '').slice(0, 9);
    tin.value = digits.match(/.{1,3}/g)?.join('-') || '';
    const position = Math.min(tin.value.length, digitsBeforeCursor + Math.floor(Math.max(0, digitsBeforeCursor - 1) / 3));
    tin.setSelectionRange(position, position);
  };
  tin.addEventListener('input', formatTin);
  formatTin();
})();
</script>
<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>
</body>
</html>
