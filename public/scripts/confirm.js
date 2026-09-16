// Shared in-app confirmation dialog.
// Usage:
// - Add data-confirm="Your message" to links, buttons, or forms.
// - Optional: data-confirm-title="Delete item", data-confirm-type="danger|warning|success".
// - Optional: data-confirm-ok-label="Delete", data-confirm-cancel-label="Keep item".
// - Optional: data-confirm-require="DELETE" requires exact typed confirmation.
// - window.kapeConfirm({ message, title, type, requireText }) is available for custom JS.

(function () {
  'use strict';

  var dialogState = {
    resolve: null,
    lastFocus: null,
    requireText: ''
  };

  function getHeuristicMessage(urlOrAction) {
    if (!urlOrAction) return null;
    var s = String(urlOrAction).toLowerCase();
    if (/delete/.test(s)) return 'This will delete data. Continue?';
    if (/remove/.test(s)) return 'This will remove data. Continue?';
    if (/void/.test(s)) return 'This will void the transaction. Continue?';
    if (/cancel/.test(s)) return 'This will cancel the operation. Continue?';
    if (/restore/.test(s)) return 'This will restore data and may overwrite existing records. Continue?';
    if (/deduct/.test(s)) return 'This will deduct inventory or payment data. Continue?';
    return null;
  }

  function ensureDialog() {
    var existing = document.getElementById('kapeConfirmDialog');
    if (existing) return existing;

    var root = document.createElement('div');
    root.id = 'kapeConfirmDialog';
    root.className = 'kape-confirm-backdrop';
    root.setAttribute('aria-hidden', 'true');
    root.innerHTML = [
      '<section class="kape-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="kapeConfirmTitle" aria-describedby="kapeConfirmMessage">',
      '  <button type="button" class="kape-confirm-close" data-kape-confirm-cancel aria-label="Close"><i class="fa fa-xmark"></i></button>',
      '  <div class="kape-confirm-icon"><i class="fa fa-circle-exclamation"></i></div>',
      '  <h2 id="kapeConfirmTitle">Confirm Action</h2>',
      '  <p id="kapeConfirmMessage">Are you sure you want to proceed?</p>',
      '  <label class="kape-confirm-require" for="kapeConfirmInput">',
      '    <span></span>',
      '    <input id="kapeConfirmInput" type="text" autocomplete="off">',
      '  </label>',
      '  <div class="kape-confirm-actions">',
      '    <button type="button" class="kape-confirm-btn secondary" data-kape-confirm-cancel>Cancel</button>',
      '    <button type="button" class="kape-confirm-btn primary" data-kape-confirm-ok>Confirm</button>',
      '  </div>',
      '</section>'
    ].join('');
    document.body.appendChild(root);

    root.addEventListener('click', function (event) {
      if (event.target === root || event.target.closest('[data-kape-confirm-cancel]')) {
        settle(false);
      }
    });

    root.querySelector('[data-kape-confirm-ok]').addEventListener('click', function () {
      var input = root.querySelector('#kapeConfirmInput');
      if (dialogState.requireText && input.value !== dialogState.requireText) {
        input.focus();
        input.classList.add('is-invalid');
        return;
      }
      settle(true);
    });

    root.querySelector('#kapeConfirmInput').addEventListener('input', function (event) {
      event.target.classList.remove('is-invalid');
    });

    document.addEventListener('keydown', function (event) {
      if (!root.classList.contains('is-open')) return;
      if (event.key === 'Escape') settle(false);
      if (event.key !== 'Tab') return;

      var focusable = root.querySelectorAll('button:not([disabled]), input:not([disabled])');
      if (!focusable.length) return;
      var first = focusable[0];
      var last = focusable[focusable.length - 1];

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });

    return root;
  }

  function settle(result) {
    var root = document.getElementById('kapeConfirmDialog');
    if (!root || !dialogState.resolve) return;

    root.classList.remove('is-open', 'is-danger', 'is-warning', 'is-success');
    root.setAttribute('aria-hidden', 'true');
    document.documentElement.classList.remove('kape-confirm-lock');

    var resolve = dialogState.resolve;
    var lastFocus = dialogState.lastFocus;
    dialogState.resolve = null;
    dialogState.lastFocus = null;
    dialogState.requireText = '';

    resolve(result);
    if (lastFocus && typeof lastFocus.focus === 'function') {
      setTimeout(function () {
        lastFocus.focus({ preventScroll: true });
      }, 0);
    }
  }

  function openConfirm(options) {
    options = options || {};
    if (typeof window.kapeAlertifyConfirm === 'function') {
      var alertifyConfirmation = window.kapeAlertifyConfirm(options);
      if (alertifyConfirmation) return alertifyConfirmation;
    }

    var root = ensureDialog();
    var title = options.title || 'Confirm Action';
    var message = options.message || 'Are you sure you want to proceed?';
    var type = options.type || 'warning';
    var requireText = options.requireText || '';

    if (dialogState.resolve) {
      settle(false);
    }

    dialogState.lastFocus = document.activeElement;
    dialogState.requireText = requireText;

    root.querySelector('#kapeConfirmTitle').textContent = title;
    root.querySelector('#kapeConfirmMessage').textContent = message;
    root.classList.remove('is-danger', 'is-warning', 'is-success');
    root.classList.add('is-' + type);

    var requireWrap = root.querySelector('.kape-confirm-require');
    var requireLabel = requireWrap.querySelector('span');
    var requireInput = root.querySelector('#kapeConfirmInput');
    requireInput.value = '';
    requireInput.classList.remove('is-invalid');

    if (requireText) {
      requireLabel.textContent = 'Type ' + requireText + ' to confirm';
      requireWrap.hidden = false;
    } else {
      requireWrap.hidden = true;
    }

    root.classList.add('is-open');
    root.setAttribute('aria-hidden', 'false');
    document.documentElement.classList.add('kape-confirm-lock');

    setTimeout(function () {
      (requireText ? requireInput : root.querySelector('[data-kape-confirm-ok]')).focus();
    }, 0);

    return new Promise(function (resolve) {
      dialogState.resolve = resolve;
    });
  }

  function getConfirmOptions(el, fallbackMessage) {
    var message = el.hasAttribute('data-confirm') ? el.getAttribute('data-confirm') : fallbackMessage;
    if (!message) return null;

    return {
      message: message,
      title: el.getAttribute('data-confirm-title') || 'Confirm Action',
      type: el.getAttribute('data-confirm-type') || 'warning',
      requireText: el.getAttribute('data-confirm-require') || '',
      okLabel: el.getAttribute('data-confirm-ok-label') || 'Confirm',
      cancelLabel: el.getAttribute('data-confirm-cancel-label') || 'Cancel'
    };
  }

  function appendSubmitterValue(form, submitter) {
    if (!submitter || !submitter.name) return null;
    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = submitter.name;
    hidden.value = submitter.value;
    form.appendChild(hidden);
    return hidden;
  }

  window.kapeConfirm = openConfirm;

  document.addEventListener('click', function (event) {
    var el = event.target.closest('a, button, input[type="submit"]');
    if (!el || el.closest('[data-no-confirm]')) return;

    if ((el.tagName === 'BUTTON' || el.matches('input[type="submit"]')) && el.type === 'submit' && el.form) {
      return;
    }

    var fallback = el.tagName === 'A' ? getHeuristicMessage(el.getAttribute('href')) : null;
    var options = getConfirmOptions(el, fallback);
    if (!options) return;

    event.preventDefault();
    event.stopPropagation();

    openConfirm(options).then(function (ok) {
      if (!ok) return;
      if (el.tagName === 'A' && el.href) {
        window.location.href = el.href;
      }
    });
  }, true);

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || form.nodeName !== 'FORM' || form.hasAttribute('data-no-confirm')) return;
    if (form.dataset.kapeConfirmed === '1') {
      delete form.dataset.kapeConfirmed;
      return;
    }

    var fallback = getHeuristicMessage(form.getAttribute('action'));
    var options = getConfirmOptions(form, fallback);
    if (!options) return;

    event.preventDefault();
    event.stopPropagation();

    openConfirm(options).then(function (ok) {
      if (!ok) return;
      form.dataset.kapeConfirmed = '1';
      appendSubmitterValue(form, event.submitter);
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.submit();
      }
    });
  }, true);
})();
