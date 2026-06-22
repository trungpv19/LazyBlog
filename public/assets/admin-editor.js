/**
 * LazyBlog admin editor enhancements:
 *   1. Mount EasyMDE on the #body textarea (live-preview markdown editor).
 *   2. Convert the comma-separated #tags input into a chip UI.
 *   3. Guard against navigating away with unsaved changes.
 *
 * Pure vanilla — no build step. EasyMDE is loaded from CDN by edit.php.
 */

(function () {
    'use strict';

    var form = document.querySelector('.admin-form');
    if (!form) return;

    /* ---------- 1. EasyMDE ---------- */
    var bodyEl = document.getElementById('body');
    var easyMDE = null;
    var slugEl = document.getElementById('slug');

    if (bodyEl && window.EasyMDE) {
        var autosaveId = 'lazyblog-' + (slugEl && slugEl.value ? slugEl.value : 'new');

        // Mirror fullscreen state to the body so we can hide the CRT scanline
        // overlay while the editor takes over the viewport.
        var observer = new MutationObserver(function () {
            var fs = document.querySelector('.CodeMirror-fullscreen, .editor-toolbar.fullscreen');
            document.body.classList.toggle('editor-fullscreen-active', !!fs);
        });
        observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });

        // Server-side preview — POSTs raw markdown to /admin/preview and
        // uses the real PHP MarkdownRenderer so admonitions (::: highlight,
        // ::: story) and freq-tag chips render the same as the public page.
        // Debounced so we don't hammer the server on every keystroke.
        var previewCache = '';
        var previewTimer = null;
        function previewRender(plainText, previewEl) {
            clearTimeout(previewTimer);
            previewTimer = setTimeout(function () {
                fetch('/admin/preview', {
                    method: 'POST',
                    headers: { 'Content-Type': 'text/plain; charset=utf-8' },
                    body: plainText,
                    credentials: 'same-origin',
                }).then(function (r) { return r.text(); })
                  .then(function (html) {
                      previewCache = html;
                      previewEl.innerHTML = html;
                  })
                  .catch(function () {
                      previewEl.innerHTML = '<p style="color:#ff8a8a">// Preview render failed.</p>';
                  });
            }, 300);
            // Return last cached HTML so the panel doesn't flash blank between updates.
            return previewCache || '<p style="opacity:0.5">Rendering…</p>';
        }

        easyMDE = new EasyMDE({
            element: bodyEl,
            spellChecker: false,
            forceSync: true,            // mirror back to the underlying textarea so form submit works
            // Font Awesome is loaded explicitly from jsdelivr in edit.php — disabling
            // EasyMDE's auto-download keeps the request inside our CSP allow-list.
            autoDownloadFontAwesome: false,
            previewRender: previewRender,
            autosave: {
                enabled: true,
                uniqueId: autosaveId,
                delay: 1500,
            },
            placeholder: bodyEl.placeholder || '',
            status: ['lines', 'words', 'cursor'],
            tabSize: 2,
            indentWithTabs: false,
            toolbar: [
                'bold', 'italic', 'heading', '|',
                'quote', 'unordered-list', 'ordered-list', '|',
                'code', 'link', 'image', '|',
                {
                    name: 'highlight',
                    action: function (editor) {
                        var cm = editor.codemirror;
                        var output = '::: highlight\nKey fact or callout.\n:::';
                        cm.replaceSelection('\n' + output + '\n');
                    },
                    className: 'fa fa-exclamation-triangle',
                    title: 'Insert highlight admonition',
                },
                {
                    name: 'story',
                    action: function (editor) {
                        var cm = editor.codemirror;
                        var output = '::: story icon="🌕" title="A story"\nBody.\n:::';
                        cm.replaceSelection('\n' + output + '\n');
                    },
                    className: 'fa fa-comment',
                    title: 'Insert story card',
                },
                '|',
                'preview', 'side-by-side', 'fullscreen', '|',
                'guide',
            ],
            shortcuts: {
                togglePreview: 'Cmd-P',
                toggleSideBySide: 'F9',
                toggleFullScreen: 'F11',
            },
        });
    }

    /* ---------- 2. Tag chip input ---------- */
    var tagsEl = document.getElementById('tags');
    if (tagsEl) {
        var wrap = document.createElement('div');
        wrap.className = 'tag-chip-wrap';

        var chipsBox = document.createElement('div');
        chipsBox.className = 'tag-chip-list';

        var entry = document.createElement('input');
        entry.type = 'text';
        entry.className = 'tag-chip-entry admin-input admin-mono';
        entry.placeholder = 'add tag + comma or Enter';
        entry.autocomplete = 'off';

        wrap.appendChild(chipsBox);
        wrap.appendChild(entry);

        // Hide the original input but keep it in the form for submission.
        tagsEl.classList.add('tag-chip-hidden');
        tagsEl.parentNode.insertBefore(wrap, tagsEl.nextSibling);

        var current = [];

        function syncHidden() {
            tagsEl.value = current.join(', ');
        }

        function normalize(raw) {
            return (raw || '')
                .toLowerCase()
                .trim()
                .replace(/[^a-z0-9-]/g, '');
        }

        function renderChips() {
            chipsBox.innerHTML = '';
            current.forEach(function (t, i) {
                var chip = document.createElement('span');
                chip.className = 'tag-chip-pill';
                chip.textContent = '#' + t;

                var x = document.createElement('button');
                x.type = 'button';
                x.className = 'tag-chip-x';
                x.setAttribute('aria-label', 'Remove ' + t);
                x.textContent = '×';
                x.addEventListener('click', function () {
                    current.splice(i, 1);
                    syncHidden();
                    renderChips();
                });
                chip.appendChild(x);
                chipsBox.appendChild(chip);
            });
        }

        function addFromEntry() {
            var raw = entry.value;
            var parts = raw.split(',');
            parts.forEach(function (p) {
                var t = normalize(p);
                if (t && current.indexOf(t) === -1) current.push(t);
            });
            entry.value = '';
            syncHidden();
            renderChips();
        }

        // Seed from existing value.
        (tagsEl.value || '').split(',').forEach(function (p) {
            var t = normalize(p);
            if (t && current.indexOf(t) === -1) current.push(t);
        });
        syncHidden();
        renderChips();

        entry.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                addFromEntry();
            } else if (e.key === 'Backspace' && entry.value === '' && current.length > 0) {
                current.pop();
                syncHidden();
                renderChips();
            }
        });
        entry.addEventListener('blur', addFromEntry);
    }

    /* ---------- 3. Unsaved-changes guard ---------- */
    var dirty = false;

    function markDirty() {
        dirty = true;
    }

    Array.prototype.slice.call(form.querySelectorAll('input, select, textarea')).forEach(function (el) {
        el.addEventListener('input', markDirty);
        el.addEventListener('change', markDirty);
    });

    if (easyMDE) {
        easyMDE.codemirror.on('change', markDirty);
    }

    window.addEventListener('beforeunload', function (e) {
        if (!dirty) return;
        e.preventDefault();
        e.returnValue = '';
        return '';
    });

    // Clear dirty when the form is actually submitted.
    form.addEventListener('submit', function () {
        dirty = false;
    });
})();
