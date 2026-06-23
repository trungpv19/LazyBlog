/* =========================================================================
   LazyBlog — admin-about-editor.js
   Avatar [ UPLOAD ] button on /admin/about. Picks a file, POSTs to
   /admin/upload (the same endpoint EasyMDE uses for body images),
   fills the avatar text field with the returned URL.
   Loaded only on /admin/about — see views/admin/edit-about.php.
   ========================================================================= */
(function () {
    var btn = document.getElementById('avatar-upload-btn');
    var fileInput = document.getElementById('avatar-file');
    var urlInput = document.getElementById('avatar');
    var status = document.getElementById('avatar-upload-status');
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (!btn || !fileInput || !urlInput || !csrfMeta) return;

    btn.addEventListener('click', function () { fileInput.click(); });

    fileInput.addEventListener('change', function () {
        var file = fileInput.files && fileInput.files[0];
        if (!file) return;
        status.textContent = 'Uploading ' + file.name + '…';
        var fd = new FormData();
        fd.append('file', file);  // /admin/upload reads $_FILES['file']
        fetch('/admin/upload', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfMeta.getAttribute('content') || '' },
            body: fd,
        })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (res) {
            if (res.ok && res.body && res.body.url) {
                urlInput.value = res.body.url;
                status.textContent = 'Uploaded → ' + res.body.url;
            } else {
                status.textContent = 'Upload failed: ' + ((res.body && res.body.error) || 'unknown');
            }
        })
        .catch(function (e) {
            status.textContent = 'Upload error: ' + e.message;
        })
        .finally(function () { fileInput.value = ''; });
    });
})();
