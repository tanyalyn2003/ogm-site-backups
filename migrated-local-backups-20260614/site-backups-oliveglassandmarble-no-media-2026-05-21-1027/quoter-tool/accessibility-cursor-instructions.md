# OGM Accessibility Module — Cursor Implementation Instructions

Add these immediately after each `ogm-theme-toggle` include:

```html
<link rel="stylesheet" href="ogm-accessibility.css">
<script src="ogm-accessibility.js" defer></script>
```

For `website-leads/*` pages, use:

```html
<link rel="stylesheet" href="../ogm-accessibility.css">
<script src="../ogm-accessibility.js" defer></script>
```

`ogm-theme-toggle.js` creates `#ogm-theme-toggle` with class `.ogm-theme-toggle-btn`; the accessibility module inserts the mic button after that element.

For destructive actions, wrap the removal with:

```js
if (window.OGMAccessibility && window.OGMAccessibility.isOn()) {
  window.OGMAccessibility.showUndoToast('Removed', function () {
    // restore previous state here
  }, 2000);
}
```
