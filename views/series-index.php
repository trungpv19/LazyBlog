<?php
/** @var string $title */
/** @var list<array{slug:string,title:string,count:int,firstDate:string,lastDate:string}> $series */

use App\Http;
?>

<section class="series-page">
    <h2 class="series-page-title">> ALL SERIES (<?= count($series) ?>)</h2>

    <?php if ($series === []): ?>
        <p style="color: var(--text-dim);">// NO SERIES YET. Add `series: my-slug` frontmatter to any post to start one.</p>
    <?php else: ?>
        <ul class="series-list">
            <?php foreach ($series as $s): ?>
                <li class="series-item series-item-card">
                    <div class="series-item-body">
                        <a class="series-item-title" href="/series/<?= Http::e($s['slug']) ?>">
                            <?= Http::e($s['title']) ?>
                        </a>
                        <?php
                        // Display dates collapse to YYYY-MM-DD — frontmatter
                        // can carry full ISO datetime for the wall-clock
                        // gamification kinds, but the listing only needs
                        // the calendar day.
                        $firstDate = substr((string) $s['firstDate'], 0, 10);
                        $lastDate = substr((string) $s['lastDate'], 0, 10);
                        ?>
                        <div class="series-item-meta">
                            <?= $s['count'] ?> part<?= $s['count'] === 1 ? '' : 's' ?>
                            · <?= Http::e($firstDate) ?>
                            <?php if ($firstDate !== $lastDate): ?>
                                → <?= Http::e($lastDate) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p style="margin-top: 32px;">
        <a class="view-source-link" href="/">← BACK TO INDEX</a>
    </p>
</section>
