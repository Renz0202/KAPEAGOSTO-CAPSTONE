<?php
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/settings.php';

require_admin();

$pdo = db();
require_once dirname(__DIR__, 2) . '/includes/ai_connection_check.php';
$flash = '';
$flashType = 'success';

if (empty($_SESSION['ai_settings_csrf'])) {
    $_SESSION['ai_settings_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['ai_settings_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your session token expired. Please reload the page and try again.');
        }

        settings_save_ai($pdo, $_POST);
        $flash = 'AI settings updated successfully.';
    } catch (InvalidArgumentException | RuntimeException $e) {
        $flash = $e->getMessage();
        $flashType = 'error';
    } catch (Throwable $e) {
        error_log('AI settings save failed: ' . $e->getMessage());
        $flash = 'AI settings could not be saved. Please try again.';
        $flashType = 'error';
    }
}

$settings = settings_all($pdo);
$aiApiKeyStored = trim(settings_value($settings, 'ai_api_key')) !== '';

function ai_setting_field_value(array $settings, string $key): string
{
    return htmlspecialchars(settings_value($settings, $key), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AI Settings - Kape Agosto</title>
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
    <strong>AI Settings</strong>
  </div>

  <section class="settings-hero">
    <div>
      <span class="settings-kicker">Business Intelligence</span>
      <h1>AI Settings</h1>
      <p>Configure AI-assisted sales recommendations without changing any other system settings.</p>
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

  <form method="post" class="settings-form" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['ai_settings_csrf'], ENT_QUOTES, 'UTF-8') ?>">

    <section class="settings-panel settings-ai-panel">
      <div class="settings-panel-intro">
        <span class="settings-panel-icon"><i class="fa fa-wand-magic-sparkles"></i></span>
        <h2>AI Sales Analytics</h2>
        <p>Optionally connect OpenAI or Gemini for generated operational recommendations. Forecasting and inventory analytics continue to work locally when AI is disabled.</p>
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
          <input type="text" name="ai_model" value="<?= ai_setting_field_value($settings, 'ai_model') ?>" placeholder="Automatic provider default">
        </label>
        
        <label class="settings-span-2">
          <span>AI API Key</span>
          <input type="password" name="ai_api_key" value="" placeholder="<?= $aiApiKeyStored ? 'Stored API key detected. Leave blank to keep it.' : 'Paste your AI API key' ?>" autocomplete="new-password">
          <small><?= $aiApiKeyStored ? 'An API key is currently saved. Enter a new key only to replace it.' : 'The key is securely saved and is not displayed again after saving.' ?></small>
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
        Save AI Settings
      </button>
      <a href="dashboard.php" class="settings-cancel">Cancel</a>
    </div>
  </form>
</main>

<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>
</body>
</html>
