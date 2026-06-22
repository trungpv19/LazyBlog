<?php
/**
 * Reusable pagination footer.
 *
 * Required vars:
 *   int    $page         Current page (1-indexed)
 *   int    $totalPages
 *   int    $total
 *   string $pageBaseUrl  e.g. "/" or "/tags/foo"
 */
/** @var int $page */
/** @var int $totalPages */
/** @var int $total */
/** @var string $pageBaseUrl */

use App\Http;

if ($totalPages <= 1) {
    return;
}

$linkFor = static function (int $p) use ($pageBaseUrl): string {
    if ($p <= 1) {
        return $pageBaseUrl;
    }
    $sep = str_contains($pageBaseUrl, '?') ? '&' : '?';
    return $pageBaseUrl . $sep . 'page=' . $p;
};
?>

<nav class="pagination" aria-label="Pagination">
    <?php if ($page > 1): ?>
        <a class="page-btn" rel="prev" href="<?= Http::e($linkFor($page - 1)) ?>">← PREV</a>
    <?php else: ?>
        <span class="page-btn page-btn-disabled" aria-disabled="true">← PREV</span>
    <?php endif; ?>

    <span class="page-indicator">
        PAGE <?= (int) $page ?> / <?= (int) $totalPages ?>
        &nbsp;·&nbsp;
        <?= (int) $total ?> POST<?= $total === 1 ? '' : 'S' ?>
    </span>

    <?php if ($page < $totalPages): ?>
        <a class="page-btn" rel="next" href="<?= Http::e($linkFor($page + 1)) ?>">NEXT →</a>
    <?php else: ?>
        <span class="page-btn page-btn-disabled" aria-disabled="true">NEXT →</span>
    <?php endif; ?>
</nav>
