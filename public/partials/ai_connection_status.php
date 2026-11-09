<?php
$connectionResult = $_SESSION['ai_connection_result'] ?? [];
$connectionFingerprint = hash('sha256', trim($settings['ai_api_key'] ?? '') . '|' . ai_model($settings) . '|' . ($settings['ai_provider'] ?? ''));
if (($connectionResult['fingerprint'] ?? '') !== $connectionFingerprint) $connectionResult = [];
?>
<div class="settings-span-2 ai-key-check">
  <label class="ai-auto-model"><input type="checkbox" name="ai_automatic_model" value="1" id="aiAutomaticModel" <?= in_array(trim($settings['ai_model'] ?? ''), ['', 'gpt-4o-mini'], true) ? 'checked' : '' ?>> Use automatic model selection</label>
  <strong>Saved API connection status</strong>
  <strong id="aiKeyStatusBadge" data-state="<?= htmlspecialchars($connectionResult['state'] ?? 'unknown') ?>"><?= ($connectionResult['state'] ?? '') === 'active' ? 'API CONNECTED' : (($connectionResult['state'] ?? '') === 'invalid' ? 'API KEY REJECTED' : 'CONNECTION NOT VERIFIED') ?></strong>
  <div id="aiKeyStatus" role="status" aria-live="polite" data-state="<?= htmlspecialchars($connectionResult['state'] ?? 'unknown') ?>">
    <?= htmlspecialchars($connectionResult['message'] ?? ($aiApiKeyStored ? 'Key saved, not checked. Check the connection to verify it.' : 'No API key saved. Save a key before checking.')) ?>
  </div>
  <small id="aiKeyCheckedAt"><?= !empty($connectionResult['checkedAt']) ? 'Last checked: ' . htmlspecialchars($connectionResult['checkedAt']) : '' ?></small>
  <button type="button" class="settings-cancel" id="checkAiKey">Check connection</button>
  <details class="ai-settings-help"><summary>Connection and model details</summary><p>Save changes before checking. This test uses a small API request and may incur a charge. Status reflects the last check. Automatic selection uses the latest Gemini Flash-Lite alias or the OpenAI system default.</p></details>
</div>
<style>
.ai-key-check { display: grid; justify-items: start; gap: 10px; padding: 16px; border: 1px solid #e6d8cc; border-radius: 8px; background: #fffaf4; }
.ai-key-check #aiKeyStatus { line-height: 1.6; }
.ai-key-check [data-state="active"] { color: #21683a; }
.ai-key-check [data-state="invalid"] { color: #a52d25; }
.ai-key-check small { line-height: 1.6; }
.ai-key-check #aiKeyCheckedAt:empty { display: none; }
.ai-key-check button:disabled { opacity: .6; cursor: wait; }
body.kape-settings-page .settings-ai-panel #aiKeyStatusBadge { grid-column: 1; display: inline-flex; justify-self: start; align-items: center; padding: 7px 10px; border: 1px solid #decaa6; border-radius: 6px; background: #fff1d5; color: #785014; font-size: .74rem; letter-spacing: .04em; line-height: 1.4; }
body.kape-settings-page .settings-ai-panel #aiKeyStatusBadge[data-state="active"] { background: #e9f6ed; border-color: #a4cdb1; color: #21683a; }
body.kape-settings-page .settings-ai-panel #aiKeyStatusBadge[data-state="invalid"] { background: #fceceb; border-color: #e4b2ad; color: #a52d25; }
body.kape-settings-page .settings-ai-panel { grid-template-columns: minmax(180px, .28fr) minmax(0, 1fr); padding: 24px; gap: 28px; }
body.kape-settings-page .settings-ai-panel .settings-panel-intro { padding-right: 24px; }
body.kape-settings-page .settings-ai-panel .settings-panel-icon { width: 38px; height: 38px; font-size: 1rem; margin-bottom: 12px; }
body.kape-settings-page .settings-ai-panel h2 { font: 750 1.4rem/1.25 Inter, "Segoe UI", sans-serif; }
body.kape-settings-page .settings-ai-panel .settings-panel-intro p { font-size: .82rem; line-height: 1.6; margin-top: 10px; }
body.kape-settings-page .settings-ai-panel .settings-grid { gap: 14px 18px; align-content: start; }
body.kape-settings-page .settings-ai-panel .settings-grid label { gap: 6px; }
body.kape-settings-page .settings-ai-panel label[hidden] { display: none !important; }
body.kape-settings-page .settings-ai-panel .settings-grid :is(input:not([type="checkbox"]), select) { min-height: 40px; padding: 9px 12px; font-size: .85rem; }
body.kape-settings-page .settings-ai-panel .settings-grid label span { font-size: .68rem; letter-spacing: .06em; }
body.kape-settings-page .settings-ai-panel .settings-grid label small { font-size: .74rem; line-height: 1.5; }
body.kape-settings-page .settings-ai-panel .settings-toggle { min-height: 40px; padding: 9px 12px; }
body.kape-settings-page .settings-ai-panel input[type="checkbox"] { width: 16px; height: 16px; min-height: 16px; padding: 0; accent-color: #6b3018; flex: 0 0 auto; }
body.kape-settings-page .settings-ai-panel .ai-key-check { grid-template-columns: minmax(0, 1fr) auto; align-items: start; gap: 8px 18px; padding: 14px 16px; background: #fcfaf7; font-size: .82rem; }
body.kape-settings-page .settings-ai-panel .ai-key-check .ai-auto-model { grid-column: 1 / -1; display: flex; align-items: center; gap: 8px; padding-bottom: 12px; margin-bottom: 4px; border-bottom: 1px solid #e9dfd7; font-size: .82rem; }
body.kape-settings-page .settings-ai-panel .ai-key-check > strong { grid-column: 1; font-size: .78rem; }
body.kape-settings-page .settings-ai-panel #aiKeyStatus { grid-column: 1; line-height: 1.5; }
body.kape-settings-page .settings-ai-panel #aiKeyCheckedAt { grid-column: 1; font-size: .7rem; color: #79665b; }
body.kape-settings-page .settings-ai-panel #checkAiKey { grid-column: 2; grid-row: 2 / span 3; min-height: 36px; padding: 8px 12px; font-size: .72rem; align-self: center; }
body.kape-settings-page .settings-ai-panel .ai-settings-help { grid-column: 1 / -1; font-size: .75rem; line-height: 1.5; color: #756156; }
body.kape-settings-page .settings-ai-panel .ai-settings-help summary { cursor: pointer; padding: 4px 0; }
body.kape-settings-page .settings-ai-panel .ai-settings-help p { margin: 8px 0 0; font-size: .75rem; line-height: 1.6; }
@media (max-width: 900px) {
  body.kape-settings-page .settings-ai-panel { grid-template-columns: minmax(0, 1fr); gap: 18px; padding: 18px; }
  body.kape-settings-page .settings-ai-panel .settings-panel-intro { border-right: 0; border-bottom: 1px solid #e9dfd7; padding: 0 0 16px; }
}
@media (max-width: 540px) {
  body.kape-settings-page .settings-ai-panel .ai-key-check { grid-template-columns: minmax(0, 1fr); }
  body.kape-settings-page .settings-ai-panel #checkAiKey { grid-column: 1; grid-row: auto; justify-self: start; }
}
</style>
<script>
const automaticAiModel = document.getElementById('aiAutomaticModel');
const aiModelInput = automaticAiModel.closest('form').querySelector('[name="ai_model"]');
const syncAiModel = () => { aiModelInput.closest('label').hidden = automaticAiModel.checked; if (automaticAiModel.checked) aiModelInput.value = ''; };
automaticAiModel.addEventListener('change', syncAiModel);
automaticAiModel.closest('form').querySelector('[name="ai_provider"]').addEventListener('change', () => { automaticAiModel.checked = true; syncAiModel(); });
syncAiModel();
document.getElementById('checkAiKey').addEventListener('click', async function () {
  const button = this;
  const status = document.getElementById('aiKeyStatus');
  const form = button.closest('form');
  const badge = document.getElementById('aiKeyStatusBadge');
  function showResult(state, title, message, type) {
    badge.dataset.state = state;
    badge.textContent = title;
    status.dataset.state = state;
    status.textContent = message;
    if (window.kapeAlert) window.kapeAlert({title, message, type});
    else window.alert(title + '\n\n' + message);
  }
  if (form.querySelector('[name="ai_api_key"]').value.trim() || form.querySelector('[name="clear_ai_api_key"]:checked')) {
    showResult('unknown', 'SAVE SETTINGS FIRST', 'Save your key changes before checking the saved connection.', 'warning');
    return;
  }
  button.disabled = true;
  button.textContent = 'Checking...';
  badge.dataset.state = 'unknown';
  badge.textContent = 'CHECKING CONNECTION';
  status.dataset.state = 'unknown';
  status.textContent = 'Checking the saved API key and model. This may take up to 60 seconds...';
  document.getElementById('aiKeyCheckedAt').textContent = '';
  try {
    const url = new URL(window.location.href);
    url.search = '?check_ai_connection=1';
    const response = await fetch(url, { method: 'POST', cache: 'no-store', body: new URLSearchParams({ token: <?= json_encode($_SESSION['ai_connection_token']) ?> }) });
    const data = await response.json();
    const state = response.ok ? data.state || 'unknown' : 'unknown';
    showResult(state,
      state === 'active' ? 'API CONNECTED' : state === 'invalid' ? 'API KEY REJECTED' : 'CONNECTION NOT VERIFIED',
      data.message || 'Unable to verify the connection.',
      state === 'active' ? 'success' : state === 'invalid' ? 'error' : 'warning');
    document.getElementById('aiKeyCheckedAt').textContent = data.checkedAt ? 'Last checked: ' + data.checkedAt : '';
  } catch (error) {
    showResult('unknown', 'CONNECTION NOT VERIFIED', 'Unable to reach the server. Connection status could not be verified.', 'warning');
  } finally {
    button.disabled = false;
    button.textContent = 'Check connection';
  }
});
</script>
