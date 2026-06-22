<?php
/** @var string $title */
/** @var string $body */

use App\Config;
use App\Http;
use App\PostRepository;
use App\Post;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$isHome = $path === '/';

$siteTitle = (string) Config::get('SITE_TITLE');
$siteUrl = rtrim((string) Config::get('SITE_URL'), '/');
$siteDesc = (string) Config::get('SITE_DESCRIPTION', '');
$siteAuthor = (string) Config::get('DEFAULT_AUTHOR', '');
$browserTitle = $isHome || $title === $siteTitle
    ? $siteTitle
    : ($title . ' — ' . $siteTitle);

// Include the (sanitized) query string in the canonical URL so paginated pages
// are their own canonicals; otherwise SEs treat ?page=2 as duplicate of /.
$qs = $_SERVER['QUERY_STRING'] ?? '';
$canonicalUrl = $siteUrl . $path . ($qs !== '' ? '?' . $qs : '');

// Pull $post from the render data when the post view set it.
$isPost = isset($post) && $post instanceof Post;
$isTag = isset($tag) && is_string($tag) && $tag !== '';

// Page-specific description: post summary > site description.
$pageDesc = $isPost && $post->summary !== null && $post->summary !== ''
    ? $post->summary
    : ($isTag ? "Posts tagged #{$tag} on {$siteTitle}." : $siteDesc);

// og:type — `article` for posts, `website` otherwise.
$ogType = $isPost ? 'article' : 'website';

// Footer metadata. Index cache makes this cheap.
$footerRepo = new PostRepository();
$footerTags = $footerRepo->allTags();

// JSON-LD structured data — BlogPosting for posts, Blog for home.
$jsonLd = null;
if ($isPost) {
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        'headline' => $post->title,
        'description' => $pageDesc,
        'datePublished' => $post->date,
        'dateModified' => $post->date,
        'url' => $canonicalUrl,
        'mainEntityOfPage' => $canonicalUrl,
        'inLanguage' => 'vi',
        'keywords' => implode(', ', $post->tags),
        'author' => [
            '@type' => 'Person',
            'name' => $post->author ?? $siteAuthor ?: $siteTitle,
        ],
        'publisher' => [
            '@type' => 'Organization',
            'name' => $siteTitle,
            'url' => $siteUrl . '/',
        ],
    ];
} elseif ($isHome) {
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'Blog',
        'name' => $siteTitle,
        'description' => $pageDesc,
        'url' => $siteUrl . '/',
        'inLanguage' => 'vi',
        'author' => [
            '@type' => 'Person',
            'name' => $siteAuthor ?: $siteTitle,
        ],
    ];
}

// Minimal CRT-themed inline SVG favicon — avoids /favicon.ico 404 noise.
$favicon = 'data:image/svg+xml,'
    . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
        . '<rect width="32" height="32" fill="#0a0e0a"/>'
        . '<text x="4" y="24" font-family="monospace" font-size="22" fill="#39ff14">&gt;_</text>'
        . '</svg>');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0a0e0a">
    <meta name="color-scheme" content="dark">

    <title><?= Http::e($browserTitle) ?></title>
    <meta name="description" content="<?= Http::e($pageDesc) ?>">
    <?php if ($siteAuthor !== ''): ?>
        <meta name="author" content="<?= Http::e($isPost && $post->author ? $post->author : $siteAuthor) ?>">
    <?php endif; ?>
    <meta name="generator" content="LazyBlog (PHP + Markdown)">
    <meta name="robots" content="<?= str_starts_with($path, '/admin') ? 'noindex, nofollow' : 'index, follow, max-image-preview:large' ?>">

    <link rel="canonical" href="<?= Http::e($canonicalUrl) ?>">

    <!-- Open Graph -->
    <meta property="og:title" content="<?= Http::e($title) ?>">
    <meta property="og:description" content="<?= Http::e($pageDesc) ?>">
    <meta property="og:url" content="<?= Http::e($canonicalUrl) ?>">
    <meta property="og:type" content="<?= $ogType ?>">
    <meta property="og:site_name" content="<?= Http::e($siteTitle) ?>">
    <meta property="og:locale" content="vi_VN">
    <?php if ($isPost): ?>
        <meta property="article:published_time" content="<?= Http::e($post->date) ?>">
        <?php if ($post->author): ?>
            <meta property="article:author" content="<?= Http::e($post->author) ?>">
        <?php endif; ?>
        <?php foreach ($post->tags as $t): ?>
            <meta property="article:tag" content="<?= Http::e($t) ?>">
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= Http::e($title) ?>">
    <meta name="twitter:description" content="<?= Http::e($pageDesc) ?>">

    <!-- Alternates: machine-readable / AI / RSS -->
    <?php if ($isPost): ?>
        <link rel="alternate" type="text/markdown" href="<?= Http::e($siteUrl . '/posts/' . $post->slug . '.md') ?>">
    <?php endif; ?>
    <link rel="alternate" type="text/plain" title="llms.txt index" href="/llms.txt">
    <link rel="alternate" type="application/rss+xml" title="<?= Http::e($siteTitle) ?> RSS" href="/feed.xml">

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="<?= $favicon ?>">

    <?php if ($jsonLd !== null): ?>
        <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <?php endif; ?>

    <script>
    (function () {
        try {
            var t = localStorage.getItem('theme');
            if (t !== 'green' && t !== 'amber') t = 'green';
            document.documentElement.setAttribute('data-theme', t);
        } catch (e) {
            document.documentElement.setAttribute('data-theme', 'green');
        }
    })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Play:wght@400;700&family=Share+Tech+Mono&family=VT323&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="/assets/site.css">
    <?php if (str_starts_with($path, '/admin')): ?>
        <link rel="stylesheet" href="/assets/admin.css">
    <?php endif; ?>
</head>
<body class="<?php
    $bodyClasses = [];
    if ($isHome) $bodyClasses[] = 'is-home';
    if (str_starts_with($path, '/admin')) $bodyClasses[] = 'is-admin';
    echo implode(' ', $bodyClasses);
?>">

<header>
    <?php $callsign = (string) Config::get('CALLSIGN', ''); if ($callsign !== ''): ?>
        <div class="callsign"><?= Http::e($callsign) ?></div>
    <?php endif; ?>
    <h1><a href="/" class="brand-link"><?= Http::e($siteTitle) ?></a></h1>
    <?php if ($siteDesc !== ''): ?>
        <div class="subtitle">// <?= Http::e($siteDesc) ?></div>
    <?php else: ?>
        <div class="subtitle">// PHOSPHOR TERMINAL // ASIA/SAIGON</div>
    <?php endif; ?>
    <div class="header-actions">
        <a class="header-btn" href="/" aria-label="Back to home"<?= $isHome ? ' aria-current="page"' : '' ?>>[ HOME ]</a>
        <a class="header-btn" href="/archive" aria-label="Archive"<?= $path === '/archive' ? ' aria-current="page"' : '' ?>>[ ARCHIVE ]</a>
        <a class="header-btn" href="/search" aria-label="Search"<?= $path === '/search' ? ' aria-current="page"' : '' ?>>[ SEARCH ]</a>
        <?php if (App\Auth::check()): ?>
            <a class="header-btn" href="/admin" aria-label="Admin"<?= str_starts_with($path, '/admin') ? ' aria-current="page"' : '' ?>>[ ADMIN ]</a>
        <?php endif; ?>
        <button class="header-btn theme-toggle" id="theme-toggle" type="button" aria-label="Switch theme">
            <span data-when="green">[ AMBER MODE ]</span>
            <span data-when="amber">[ GREEN MODE ]</span>
        </button>
    </div>
</header>

<main>
    <?= $body ?>
</main>

<footer>
    <?php if ($footerTags !== []): ?>
        <div class="footer-block">
            <div class="footer-label">§ TAGS — FREQUENCIES</div>
            <div class="footer-tags">
                <?php foreach ($footerTags as $t): ?>
                    <a class="tag-chip" href="/tags/<?= Http::e($t) ?>">#<?= Http::e($t) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php
        // Footer sign-off — configurable via FOOTER_SIGNOFF env. Supports {year}
        // token for auto-update. Empty value hides the line entirely.
        $footerSignoff = (string) Config::get('FOOTER_SIGNOFF', '');
        if ($footerSignoff !== '') {
            $footerSignoff = str_replace('{year}', date('Y'), $footerSignoff);
        }
    ?>
    <?php if ($footerSignoff !== ''): ?>
        <div class="footer-copyright"><?= Http::e($footerSignoff) ?></div>
    <?php endif; ?>
</footer>

<button id="back-to-top" class="back-to-top" aria-label="Back to top" title="Back to top">↑</button>

<script>
document.getElementById('theme-toggle').addEventListener('click', function () {
    var cur = document.documentElement.getAttribute('data-theme');
    var next = cur === 'green' ? 'amber' : 'green';
    document.documentElement.setAttribute('data-theme', next);
    try { localStorage.setItem('theme', next); } catch (e) {}
});

(function () {
    var btt = document.getElementById('back-to-top');
    var tocWrap = document.querySelector('.post-toc-wrap');
    var threshold = 400;
    var ticking = false;

    function update() {
        var scrolled = window.scrollY > threshold;
        if (btt) btt.classList.toggle('visible', scrolled);
        if (tocWrap) tocWrap.classList.toggle('floating-visible', scrolled);
        ticking = false;
    }
    window.addEventListener('scroll', function () {
        if (!ticking) { window.requestAnimationFrame(update); ticking = true; }
    }, { passive: true });
    if (btt) {
        btt.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }
    update();
})();

/* Scrollspy: highlight the TOC link matching the h2 currently near the top of
   the viewport. Uses IntersectionObserver — no scroll listener, no rAF needed. */
(function () {
    if (!('IntersectionObserver' in window)) return;
    var headings = document.querySelectorAll('.post-body h2[id]');
    if (!headings.length) return;

    var tocLinks = document.querySelectorAll('.toc-list a');
    if (!tocLinks.length) return;

    var intersecting = new Set();

    function setActive(activeId) {
        var activeHref = activeId ? '#' + activeId : null;
        tocLinks.forEach(function (a) {
            a.classList.toggle('is-active', a.getAttribute('href') === activeHref);
        });
    }

    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
            if (e.isIntersecting) intersecting.add(e.target);
            else intersecting.delete(e.target);
        });
        var topmost = null;
        var topmostY = Infinity;
        intersecting.forEach(function (el) {
            var y = el.getBoundingClientRect().top;
            if (y < topmostY) { topmostY = y; topmost = el; }
        });
        setActive(topmost ? topmost.id : null);
    }, {
        // Trigger band: top 10–35% of viewport. Heading is "active" while it
        // sits inside that band as the reader scrolls past.
        rootMargin: '-10% 0px -65% 0px',
        threshold: 0,
    });

    headings.forEach(function (h) { observer.observe(h); });
})();

/* Code block enhancements: language label + copy button */
(function () {
    var blocks = document.querySelectorAll('.post-body pre');
    if (!blocks.length) return;

    blocks.forEach(function (pre) {
        var code = pre.querySelector('code');
        var lang = '';
        if (code && code.className) {
            var match = code.className.match(/language-([\w-]+)/);
            if (match) lang = match[1];
        }

        if (lang) {
            var label = document.createElement('span');
            label.className = 'code-lang-tag';
            label.textContent = lang;
            pre.appendChild(label);
        }

        var btn = document.createElement('button');
        btn.className = 'copy-btn';
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Copy code');
        btn.textContent = 'COPY';
        btn.addEventListener('click', function () {
            var text = (code || pre).textContent || '';
            var done = function () {
                btn.textContent = 'COPIED';
                btn.classList.add('copied');
                setTimeout(function () {
                    btn.textContent = 'COPY';
                    btn.classList.remove('copied');
                }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done).catch(function () {
                    fallbackCopy(text, done);
                });
            } else {
                fallbackCopy(text, done);
            }
        });
        pre.appendChild(btn);
    });

    function fallbackCopy(text, cb) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus(); ta.select();
        try { document.execCommand('copy'); cb(); } catch (e) {}
        document.body.removeChild(ta);
    }
})();
</script>

</body>
</html>
