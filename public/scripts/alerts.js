(function () {
  'use strict';

  function configureAlertify() {
    if (!window.alertify) return false;
    window.alertify.defaults.glossary.title = 'Kape Agosto';
    window.alertify.defaults.glossary.ok = 'Confirm';
    window.alertify.defaults.glossary.cancel = 'Cancel';
    window.alertify.defaults.transition = 'zoom';
    window.alertify.defaults.notifier.position = 'top-right';
    window.alertify.defaults.notifier.delay = 5;
    window.alertify.defaults.notifier.closeButton = true;
    return true;
  }

  function applyDialogType(dialog, type) {
    var normalizedType = String(type || 'message').toLowerCase();
    if (normalizedType === 'error') normalizedType = 'danger';
    if (!['message', 'success', 'warning', 'danger'].includes(normalizedType)) {
      normalizedType = 'message';
    }

    var typeClasses = [
      'kape-alertify-message',
      'kape-alertify-success',
      'kape-alertify-warning',
      'kape-alertify-danger'
    ];
    var elements = dialog && dialog.elements ? dialog.elements : {};

    [elements.root, elements.dialog].forEach(function (element) {
      if (!element || !element.classList) return;
      typeClasses.forEach(function (className) {
        element.classList.remove(className);
      });
      element.classList.add('kape-alertify-' + normalizedType);
    });

    return dialog;
  }

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (character) {
      return {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      }[character];
    });
  }

  function fallbackNotice(message, type) {
    var notice = document.createElement('div');
    notice.className = 'kape-alert-fallback is-' + (type || 'message');
    notice.setAttribute('role', type === 'error' ? 'alert' : 'status');
    notice.textContent = String(message || '');
    document.body.appendChild(notice);
    requestAnimationFrame(function () {
      notice.classList.add('is-visible');
    });
    setTimeout(function () {
      notice.classList.remove('is-visible');
      setTimeout(function () { notice.remove(); }, 250);
    }, 5000);
  }

  window.kapeNotify = function (message, type) {
    type = ['success', 'error', 'warning', 'message'].includes(type) ? type : 'message';
    if (!configureAlertify()) {
      fallbackNotice(message, type);
      return null;
    }
    return window.alertify[type](escapeHtml(message));
  };

  window.kapeAlert = function (options) {
    options = typeof options === 'string' ? { message: options } : (options || {});
    var title = String(options.title || 'Kape Agosto');
    var message = String(options.message || '');

    if (!configureAlertify()) {
      fallbackNotice(message, options.type || 'message');
      return Promise.resolve(true);
    }

    return new Promise(function (resolve) {
      var dialog = window.alertify.alert(
        escapeHtml(title),
        escapeHtml(message),
        function () { resolve(true); }
      );
      applyDialogType(dialog, options.type || 'message');
    });
  };

  window.kapeAlertifyConfirm = function (options) {
    options = options || {};
    if (!configureAlertify()) return null;

    return new Promise(function (resolve) {
      var dialog = window.alertify.confirm(
        escapeHtml(options.title || 'Confirm Action'),
        escapeHtml(options.message || 'Are you sure you want to proceed?'),
        function () { resolve(true); },
        function () { resolve(false); }
      );
      dialog.set('labels', {
        ok: escapeHtml(options.okLabel || 'Confirm'),
        cancel: escapeHtml(options.cancelLabel || 'Cancel')
      });
      applyDialogType(dialog, options.type || 'warning');
      if (dialog.elements && dialog.elements.root) {
        dialog.elements.root.classList.add('kape-alertify-confirmation');
      }
    });
  };

  // Safety net for any future legacy alert() call. Confirmations remain asynchronous
  // and are handled through window.kapeConfirm in confirm.js.
  window.alert = function (message) {
    window.kapeAlert({ message: String(message || '') });
  };
})();
