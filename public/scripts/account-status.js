(function () {
  'use strict';

  const script = document.currentScript;
  if (!script) return;

  const statusUrl = script.dataset.statusUrl;
  const loginUrl = script.dataset.loginUrl;
  const pausedUrl = script.dataset.pausedUrl;
  const logoutUrl = script.dataset.logoutUrl;
  const resumeUrl = script.dataset.resumeUrl;
  const pausedPage = /(?:^|\/)account_paused\.php(?:$|[?#])/.test(window.location.href);
  // Keep inactive notices and reactivation changes nearly immediate for open accounts.
  const intervalMs = 2000;
  let checking = false;
  let alertDialog;
  let alertMessage;
  let checkButton;
  const defaultMessage = 'Access is paused until an administrator reactivates your account. This screen will update automatically.';

  if (!statusUrl) return;

  function ensureAlert() {
    if (alertDialog) return alertDialog;

    alertDialog = document.createElement('dialog');
    alertDialog.className = 'account-status-alert';
    alertDialog.setAttribute('aria-labelledby', 'inactive-account-alert-title');
    alertDialog.setAttribute('aria-describedby', 'inactive-account-alert-message');
    alertDialog.innerHTML = '<div class="maintenance-alert-content">' +
      '<span class="maintenance-alert-icon" aria-hidden="true"><i class="fa fa-user-slash"></i></span>' +
      '<p class="maintenance-alert-eyebrow">Account notice</p>' +
      '<h2 id="inactive-account-alert-title">Account temporarily inactive</h2>' +
      '<p id="inactive-account-alert-message"></p>' +
      '<button type="button" class="account-status-check">Check status</button>' +
      '<a class="account-status-signout"></a>' +
      '</div>';
    document.body.appendChild(alertDialog);
    alertMessage = alertDialog.querySelector('#inactive-account-alert-message');
    checkButton = alertDialog.querySelector('.account-status-check');
    alertMessage.textContent = defaultMessage;
    const signOut = alertDialog.querySelector('.account-status-signout');
    signOut.href = logoutUrl || new URL('logout.php', statusUrl).href;
    signOut.textContent = 'Sign out';
    checkButton.addEventListener('click', function () { checkAccountStatus(true); });
    return alertDialog;
  }

  function showInactiveAlert() {
    const dialog = ensureAlert();
    alertMessage.textContent = defaultMessage;
    if (!dialog.open && typeof dialog.showModal === 'function') dialog.showModal();
  }

  function closeInactiveAlert() {
    if (alertDialog && alertDialog.open) alertDialog.close();
  }

  async function checkAccountStatus(manual) {
    if (checking || (!manual && document.visibilityState === 'hidden')) return;
    checking = true;
    if (manual && checkButton) {
      checkButton.disabled = true;
      checkButton.textContent = 'Checking status…';
    }

    try {
      const response = await fetch(statusUrl, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' }
      });

      if (response.status === 401) {
        if (loginUrl) window.location.replace(loginUrl);
        return;
      }
      if (!response.ok) throw new Error('Account status check failed');

      const result = await response.json();
      if (result.authenticated === false) {
        if (loginUrl) window.location.replace(loginUrl);
      } else if (result.active === false) {
        if (manual && !pausedPage && pausedUrl) {
          window.location.replace(pausedUrl);
        } else if (!pausedPage) {
          showInactiveAlert();
        }
      } else if (result.active === true) {
        if (pausedPage && resumeUrl) {
          window.location.replace(resumeUrl);
        } else {
          closeInactiveAlert();
        }
      }
    } catch (_) {
      if (manual && alertMessage) {
        alertMessage.textContent = 'Unable to check your account status right now. Please try again.';
      }
    } finally {
      checking = false;
      if (manual && checkButton) {
        checkButton.disabled = false;
        checkButton.textContent = 'Check status';
      }
      if (alertMessage && (!alertDialog || !alertDialog.open)) alertMessage.textContent = defaultMessage;
    }
  }

  checkAccountStatus(false);
  window.setInterval(function () { checkAccountStatus(false); }, intervalMs);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') checkAccountStatus(false);
  });
})();
