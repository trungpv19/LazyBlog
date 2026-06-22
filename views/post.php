<?php
/** @var string $title */
/** @var \App\Post $post */
/** @var string $body_html */
/** @var list<array{level:int,id:string,text:string}> $toc */

use App\Http;

$showToc = isset($toc) && count($toc) >= 3;
?>

<?php
// Reusable TOC list HTML so we can render both inline + floating without duplicating logic.
$renderTocList = function () use ($toc): void {
    foreach ($toc as $item) {
        echo '<li class="level-' . (int) $item['level'] . '">';
        echo '<a href="#' . htmlspecialchars($item['id'], ENT_QUOTES) . '">' . htmlspecialchars($item['text'], ENT_QUOTES) . '</a>';
        echo '</li>';
    }
};
?>

<article class="post-article">
    <div class="section-tag">
        § TRANSMISSION — <?= Http::e($post->date) ?><?php
            if ($post->author !== null && $post->author !== '') {
                echo ' — ' . Http::e($post->author);
            }
        ?>
    </div>
    <h2 class="post-page-title">
        <?php if ($post->icon !== null && $post->icon !== ''): ?>
            <span class="post-icon"><?= Http::e($post->icon) ?></span>
        <?php endif; ?>
        <?= Http::e($post->title) ?>
    </h2>

    <?php if ($post->tags !== []): ?>
        <div class="post-meta-tags">
            <?php foreach ($post->tags as $t): ?>
                <a class="tag-chip" href="/tags/<?= Http::e($t) ?>">#<?= Http::e($t) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($showToc): ?>
        <nav class="toc toc-inline" aria-label="Table of contents">
            <div class="toc-label">§ TOC — NAVIGATION</div>
            <ul class="toc-list"><?php $renderTocList(); ?></ul>
        </nav>
    <?php endif; ?>

    <div class="post-body">
        <?= $body_html /* trusted: rendered by MarkdownRenderer, source HTML escaped */ ?>
    </div>

    <div class="post-footer">
        <a class="view-source-link" href="<?= Http::e($post->rawUrl()) ?>">[ VIEW SOURCE .md ]</a>
        <a class="view-source-link" href="/">← BACK TO INDEX</a>
    </div>
</article>

<?php if ($showToc): ?>
    <aside class="post-toc-wrap" aria-hidden="true">
        <nav class="toc" aria-label="Floating table of contents">
            <div class="toc-label">§ TOC — NAVIGATION</div>
            <ul class="toc-list"><?php $renderTocList(); ?></ul>
        </nav>
    </aside>
<?php endif; ?>
