/* =========================================================================
   LazyBlog — site.js
   Universal-page interactions: theme toggle, back-to-top.
   Loaded with `defer` on every page. Post-page-only behaviours live in
   post.js to avoid parsing them on /, /archive, /search, etc.
   ========================================================================= */

/* ---------- Theme toggle ----------
   Swap data-theme between green/amber and persist in localStorage.
   Initial value is set by the inline theme-bootstrap in layout.php's <head>
   so there's no FOUC; this only handles user-driven switches. */
(function () {
    var btn = document.getElementById('theme-toggle');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var cur = document.documentElement.getAttribute('data-theme');
        var next = cur === 'green' ? 'amber' : 'green';
        document.documentElement.setAttribute('data-theme', next);
        try { localStorage.setItem('theme', next); } catch (e) {}
    });
})();

/* ---------- Back-to-top floating button ----------
   Fades in after scrolling past a threshold; smooth-scrolls to top on click.
   Uses a passive scroll listener + rAF batching so it never blocks scroll
   on slower devices. */
(function () {
    var btt = document.getElementById('back-to-top');
    if (!btt) return;
    var threshold = 400;
    var ticking = false;

    function update() {
        btt.classList.toggle('visible', window.scrollY > threshold);
        ticking = false;
    }
    window.addEventListener('scroll', function () {
        if (!ticking) { window.requestAnimationFrame(update); ticking = true; }
    }, { passive: true });
    btt.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
    update();
})();
