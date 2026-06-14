(function () {
  function applyCabinetOffsets() {
    var items = Array.prototype.slice.call(document.querySelectorAll('[data-cabinet-tabs] > .cabinet-tab-wrap, [data-cabinet-tabs] > .cabinet-tab--add'));
    if (!items.length) {
      return;
    }

    var rowTop = null;
    var rowIndex = -1;
    var useOffsets = window.innerWidth > 900;

    items.forEach(function (item) {
      var top = item.offsetTop;
      if (rowTop === null || Math.abs(top - rowTop) > 8) {
        rowTop = top;
        rowIndex += 1;
      }

      item.style.setProperty('--row-offset', useOffsets && (rowIndex % 2 === 1) ? '32px' : '0px');
    });
  }

  function bindScrollTargets() {
    Array.prototype.slice.call(document.querySelectorAll('[data-scroll-target]')).forEach(function (button) {
      button.addEventListener('click', function () {
        var target = document.getElementById(button.getAttribute('data-scroll-target'));
        if (!target) {
          return;
        }

        if (target.tagName.toLowerCase() === 'details') {
          target.open = true;
        }

        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });
  }

  function extractDocReference(rawValue) {
    var value = (rawValue || '').trim();
    if (!value) {
      return '';
    }

    var match = value.match(/https?:\/\/[^\s<>"']+/);
    return match ? match[0] : value;
  }

  function bindDocDropzones() {
    Array.prototype.slice.call(document.querySelectorAll('[data-doc-dropzone]')).forEach(function (form) {
      var input = form.querySelector('[data-doc-input]');
      var surface = form.querySelector('[data-drop-surface]');

      if (!input || !surface) {
        return;
      }

      function populate(rawValue) {
        var nextValue = extractDocReference(rawValue);
        if (!nextValue) {
          return;
        }

        input.value = nextValue;
        input.focus();
      }

      surface.addEventListener('click', function () {
        input.focus();
      });

      surface.addEventListener('dragenter', function (event) {
        event.preventDefault();
        surface.classList.add('doc-dropzone--dragover');
      });

      surface.addEventListener('dragover', function (event) {
        event.preventDefault();
        if (event.dataTransfer) {
          event.dataTransfer.dropEffect = 'copy';
        }
        surface.classList.add('doc-dropzone--dragover');
      });

      surface.addEventListener('dragleave', function (event) {
        if (!surface.contains(event.relatedTarget)) {
          surface.classList.remove('doc-dropzone--dragover');
        }
      });

      surface.addEventListener('drop', function (event) {
        event.preventDefault();
        surface.classList.remove('doc-dropzone--dragover');

        var transfer = event.dataTransfer;
        var rawValue = '';
        if (transfer) {
          rawValue = transfer.getData('text/uri-list') || transfer.getData('text/plain') || '';
        }

        populate(rawValue);
      });

      input.addEventListener('paste', function (event) {
        var clipboard = event.clipboardData || window.clipboardData;
        if (!clipboard) {
          return;
        }

        var rawValue = clipboard.getData('text');
        var nextValue = extractDocReference(rawValue);
        if (!nextValue) {
          return;
        }

        event.preventDefault();
        populate(nextValue);
      });
    });
  }

  function bindInlineEditors() {
    var editors = Array.prototype.slice.call(document.querySelectorAll('[data-inline-editor]'));
    if (!editors.length) {
      return;
    }

    function setLockState(toggle, state) {
      if (!toggle) {
        return;
      }

      toggle.setAttribute('data-lock-state', state);
      toggle.setAttribute('aria-pressed', state === 'unlocked' ? 'true' : 'false');
    }

    function clearAutoEditQuery() {
      if (!window.history || !window.history.replaceState || typeof URL === 'undefined') {
        return;
      }

      var url = new URL(window.location.href);
      if (!url.searchParams.has('edit')) {
        return;
      }

      url.searchParams.delete('edit');
      window.history.replaceState({}, document.title, url.toString());
    }

    function closeEditor(editor, resetValues) {
      var form = editor.querySelector('[data-inline-form]');
      var toggle = editor.querySelector('[data-inline-toggle]');
      editor.classList.remove('is-editing');

      if (resetValues && form) {
        form.reset();
      }

      if (toggle) {
        var lockedLabel = toggle.getAttribute('data-locked-label') || 'Click the lock to make changes.';
        toggle.setAttribute('aria-label', lockedLabel);
        toggle.setAttribute('title', lockedLabel);
        setLockState(toggle, 'locked');
      }
    }

    function openEditor(editor) {
      editors.forEach(function (other) {
        if (other !== editor) {
          closeEditor(other, true);
        }
      });

      editor.classList.add('is-editing');

      var toggle = editor.querySelector('[data-inline-toggle]');
      var primary = editor.querySelector('[data-inline-primary]');
      if (toggle) {
        var unlockedLabel = toggle.getAttribute('data-unlocked-label') || 'Click the lock to prevent further changes.';
        toggle.setAttribute('aria-label', unlockedLabel);
        toggle.setAttribute('title', unlockedLabel);
        setLockState(toggle, 'unlocked');
      }

      if (primary) {
        primary.focus();
        primary.select();
      }
    }

    editors.forEach(function (editor) {
      var toggle = editor.querySelector('[data-inline-toggle]');
      var form = editor.querySelector('[data-inline-form]');
      if (!toggle || !form) {
        return;
      }

      setLockState(toggle, editor.classList.contains('is-editing') ? 'unlocked' : 'locked');

      toggle.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();

        if (editor.classList.contains('is-editing')) {
          if (form.requestSubmit) {
            form.requestSubmit();
          } else {
            form.submit();
          }
          return;
        }

        openEditor(editor);
      });

      form.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          event.preventDefault();
          closeEditor(editor, true);
          return;
        }

        if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
          event.preventDefault();
          if (form.requestSubmit) {
            form.requestSubmit();
          } else {
            form.submit();
          }
        }
      });
    });

    var autoEditor = document.querySelector('[data-inline-editor][data-auto-edit="true"]');
    if (autoEditor) {
      openEditor(autoEditor);
      clearAutoEditQuery();
    }
  }

  function bindHistoryControls() {
    var undoButton = document.querySelector('[data-history-trigger="undo"]');
    var redoButton = document.querySelector('[data-history-trigger="redo"]');
    if (!undoButton && !redoButton) {
      return;
    }

    function isEditableTarget(target) {
      if (!target || target === document.body) {
        return false;
      }

      var tagName = target.tagName ? target.tagName.toLowerCase() : '';
      if (target.isContentEditable || tagName === 'textarea' || tagName === 'select') {
        return true;
      }

      if (tagName === 'input') {
        return true;
      }

      return isEditableTarget(target.parentElement);
    }

    function submitButton(button) {
      if (!button || button.disabled) {
        return false;
      }

      if (typeof button.click === 'function') {
        button.click();
        return true;
      }

      var formId = button.getAttribute('form');
      var form = formId ? document.getElementById(formId) : null;
      if (!form) {
        return false;
      }

      if (form.requestSubmit) {
        form.requestSubmit();
      } else {
        form.submit();
      }

      return true;
    }

    document.addEventListener('keydown', function (event) {
      if (!(event.metaKey || event.ctrlKey) || event.altKey) {
        return;
      }

      if (isEditableTarget(event.target) || isEditableTarget(document.activeElement)) {
        return;
      }

      var key = String(event.key || '').toLowerCase();
      if (key === 'z') {
        var handled = event.shiftKey ? submitButton(redoButton) : submitButton(undoButton);
        if (handled) {
          event.preventDefault();
        }
        return;
      }

      if (key === 'y') {
        if (submitButton(redoButton)) {
          event.preventDefault();
        }
      }
    });
  }

  function bindUserEditor() {
    var form = document.querySelector('[data-user-form]');
    if (!form) {
      return;
    }

    var username = form.querySelector('[data-user-field="username"]');
    var displayName = form.querySelector('[data-user-field="display_name"]');
    var role = form.querySelector('[data-user-field="role"]');
    var password = form.querySelector('[data-user-field="password"]');
    var submitButton = form.querySelector('[data-user-submit]');
    var cancelButton = form.querySelector('[data-user-cancel]');
    var label = form.querySelector('[data-user-form-label]');
    var editButtons = Array.prototype.slice.call(document.querySelectorAll('[data-user-edit]'));

    if (!username || !displayName || !role || !password || !submitButton || !cancelButton || !label) {
      return;
    }

    function resetForm() {
      form.reset();
      username.readOnly = false;
      username.removeAttribute('aria-readonly');
      form.removeAttribute('data-user-mode');
      submitButton.textContent = 'Save User';
      label.textContent = 'Creating a new user.';
      cancelButton.hidden = true;
    }

    editButtons.forEach(function (button) {
      button.addEventListener('click', function () {
        username.value = button.getAttribute('data-username') || '';
        displayName.value = button.getAttribute('data-display-name') || '';
        role.value = button.getAttribute('data-role') || 'employee';
        password.value = '';
        username.readOnly = true;
        username.setAttribute('aria-readonly', 'true');
        form.setAttribute('data-user-mode', 'editing');
        submitButton.textContent = 'Update User';
        label.textContent = 'Editing ' + (button.getAttribute('data-display-name') || button.getAttribute('data-username') || 'user') + '.';
        cancelButton.hidden = false;
        displayName.focus();
        displayName.select();
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });

    cancelButton.addEventListener('click', function () {
      resetForm();
      username.focus();
    });
  }

  function bindDocZoomControls() {
    var frame = document.querySelector('[data-doc-zoom-frame]');
    var iframe = document.querySelector('[data-doc-iframe]');
    var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-doc-zoom-trigger]'));
    if (!frame || !buttons.length) {
      return;
    }

    var zoomLevels = [1, 1.1, 1.25, 1.4, 1.6, 1.8, 2, 2.25];
    var zoomInButton = document.querySelector('[data-doc-zoom-trigger="in"]');
    var zoomOutButton = document.querySelector('[data-doc-zoom-trigger="out"]');

    function isEditableTarget(target) {
      if (!target || target === document.body) {
        return false;
      }

      var tagName = target.tagName ? target.tagName.toLowerCase() : '';
      if (target.isContentEditable || tagName === 'textarea' || tagName === 'select') {
        return true;
      }

      if (tagName === 'input') {
        return true;
      }

      return isEditableTarget(target.parentElement);
    }

    function applyZoom(level) {
      var nextLevel = Math.max(0, Math.min(zoomLevels.length - 1, level));
      frame.setAttribute('data-doc-zoom-level', String(nextLevel));
      frame.style.setProperty('--doc-zoom-scale', String(zoomLevels[nextLevel]));

      if (zoomOutButton) {
        zoomOutButton.disabled = nextLevel === 0;
      }

      if (zoomInButton) {
        zoomInButton.disabled = nextLevel === zoomLevels.length - 1;
      }
    }

    function focusEmbeddedDocument() {
      if (!iframe) {
        return;
      }

      if (typeof iframe.focus === 'function') {
        iframe.focus();
      }

      try {
        if (iframe.contentWindow && typeof iframe.contentWindow.focus === 'function') {
          iframe.contentWindow.focus();
        }
      } catch (error) {
        // Cross-origin iframes may reject direct focus access.
      }
    }

    function stepZoom(delta) {
      var currentLevel = parseInt(frame.getAttribute('data-doc-zoom-level') || '0', 10);
      focusEmbeddedDocument();
      applyZoom(currentLevel + delta);
    }

    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        var delta = button.getAttribute('data-doc-zoom-trigger') === 'in' ? 1 : -1;
        stepZoom(delta);
      });
    });

    window.addEventListener('keydown', function (event) {
      if (!(event.metaKey || event.ctrlKey) || event.altKey || isEditableTarget(event.target)) {
        return;
      }

      var currentLevel = parseInt(frame.getAttribute('data-doc-zoom-level') || '0', 10);
      if (event.key === '+' || event.key === '=' || event.key === 'Add') {
        event.preventDefault();
        stepZoom(1);
        return;
      }

      if (event.key === '-' || event.key === '_' || event.key === 'Subtract') {
        event.preventDefault();
        stepZoom(-1);
        return;
      }

      if (event.key === '0') {
        event.preventDefault();
        focusEmbeddedDocument();
        applyZoom(0);
      }
    }, true);

    applyZoom(parseInt(frame.getAttribute('data-doc-zoom-level') || '0', 10));
  }

  function bindDocBackToTop() {
    var button = document.querySelector('[data-doc-top-trigger]');
    var frame = document.querySelector('[data-doc-zoom-frame]');
    var iframe = document.querySelector('[data-doc-iframe]');
    if (!button || !frame || !iframe) {
      return;
    }

    var src = iframe.getAttribute('src') || '';
    if (!src) {
      return;
    }

    button.addEventListener('click', function () {
      frame.scrollIntoView({ behavior: 'smooth', block: 'start' });
      iframe.setAttribute('src', src);
    });
  }

  window.addEventListener('load', applyCabinetOffsets);
  window.addEventListener('resize', applyCabinetOffsets);
  document.addEventListener('DOMContentLoaded', function () {
    bindScrollTargets();
    bindDocDropzones();
    bindInlineEditors();
    bindHistoryControls();
    bindUserEditor();
    bindDocZoomControls();
    bindDocBackToTop();
    applyCabinetOffsets();
  });
})();
