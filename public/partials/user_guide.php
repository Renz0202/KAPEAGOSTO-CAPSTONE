<?php
if (!empty($kapeGuideRendered) || !current_user()) return;
$kapeGuideRendered = true;
require_once dirname(__DIR__, 2) . '/includes/user_guide.php';
require_once __DIR__ . '/assets.php';
$guideRole = current_user_role();
$guideTopics = user_guide_catalog($guideRole);
if (!$guideTopics) return;
$_SESSION['user_guide_csrf'] ??= bin2hex(random_bytes(32));
try {
    $guideProgress = user_guide_progress(db(), (int) current_user()['id']);
} catch (Throwable $guideError) {
    error_log('Guide availability: ' . $guideError->getMessage());
    $guideProgress = ['needsWelcome' => false, 'status' => null, 'version' => USER_GUIDE_VERSION];
}
foreach ($guideTopics as &$guideTopic) {
    $guideTopic['url'] = $guideTopic['page'] !== '' ? kape_asset_url($guideTopic['page']) : '';
}
unset($guideTopic);
$guideRoleProfile = user_guide_role_profile($guideRole);
$guideWelcome = ['Your role: ' . role_display_name($guideRole), $guideRoleProfile['summary']];
$guideData = [
    'userId' => (int) current_user()['id'],
    'roleLabel' => role_display_name($guideRole),
    'topics' => $guideTopics,
    'currentTopic' => user_guide_current_topic($guideTopics, $_SERVER['REQUEST_URI'] ?? ''),
    'needsWelcome' => $guideProgress['needsWelcome'],
    'csrf' => $_SESSION['user_guide_csrf'],
    'stateUrl' => kape_asset_url('user_guide_state.php'),
    'welcome' => [
        ['Welcome to Kape Agosto', 'A quick guide to help you use your account with confidence. You can skip this welcome and reopen it from Guide at any time.'],
        $guideWelcome,
        ['Help at every step', 'Browse page guides for clear instructions. Choose Explain controls, then select a tab, icon, or button to read what it does. Exit guide mode to use the controls normally.'],
    ],
];
?>
<dialog id="kapeGuide" class="kape-guide" aria-labelledby="kapeGuideTitle">
  <div class="kg-header">
    <div><span class="kg-eyebrow">Kape Agosto &middot; User Guide</span><h2 id="kapeGuideTitle"><?= htmlspecialchars(role_display_name($guideRole)) ?> Guide</h2></div>
    <button type="button" class="kg-icon-button" data-guide-close aria-label="Close guide">&times;</button>
  </div>
  <div id="kgWelcome" class="kg-welcome" hidden>
    <span class="kg-badge" id="kgWelcomeCount"></span>
    <h3 id="kgWelcomeTitle"></h3><p id="kgWelcomeText"></p>
    <div class="kg-welcome-dots" id="kgWelcomeDots" aria-hidden="true"></div>
    <div class="kg-welcome-actions">
      <button type="button" class="kg-button kg-muted" id="kgWelcomeSkip">Skip welcome</button>
      <div><button type="button" class="kg-button kg-secondary" id="kgWelcomeBack">Back</button>
      <button type="button" class="kg-button kg-primary" id="kgWelcomeNext">Next</button></div>
    </div>
  </div>
  <div id="kgWorkspace" class="kg-workspace">
    <aside class="kg-sidebar">
      <label class="kg-search-label" for="kgSearch">Find a page or action</label>
      <input id="kgSearch" type="search" placeholder="Search guide..." autocomplete="off">
      <nav id="kgTopics" aria-label="Guide topics"></nav>
      <p id="kgNoResults" class="kg-empty" role="status" hidden>No matching topics. Try a page name or action.</p>
      <button type="button" class="kg-replay" id="kgReplay">Replay welcome</button>
    </aside>
    <article id="kgArticle" class="kg-article" aria-labelledby="kgTopicTitle" tabindex="-1"></article>
  </div>
  <div class="kg-footer">
    <span id="kgSaveStatus" role="status"></span>
    <button type="button" class="kg-button kg-secondary" id="kgExplain">Explain controls</button>
  </div>
</dialog>
<div id="kgInspectBar" class="kg-inspect-bar" hidden role="region" aria-label="Guide mode">
  <div><strong>Guide mode</strong><span>Select a tab, icon, button, or field to read what it does.</span></div>
  <button type="button" class="kg-button kg-primary" id="kgInspectExit">Exit guide mode</button>
</div>
<div id="kgControlHelp" class="kg-control-help" hidden role="status" aria-live="polite">
  <strong id="kgControlTitle"></strong><p id="kgControlText"></p>
</div>
<script type="application/json" id="kapeGuideData"><?= json_encode($guideData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/user-guide.js') . '?v=' . filemtime(dirname(__DIR__) . '/scripts/user-guide.js')) ?>" defer></script>
