<?php
/** @var string $title */
/** @var list<array{slug:string,title:string,count:int,firstDate:string,lastDate:string}> $series */

use App\Http;
?>

<section class="series-page">
    <div class="section-tag">§ SERIES — TRANSMISSION SEQUENCES</div>
    <h2 class="series-page-title">📡 ALL SERIES</h2>
    <p class="series-meta"><?= count($series) ?> SERIES TOTAL</p>

    <?php if ($series === []): ?>
        <p style="color: var(--text-dim);">// NO SERIES YET. Add `series: my-slug` frontmatter to any post to start one.</p>
    <?php else: ?>
        <ul class="series-list">
            <?php foreach ($series as $s): ?>
                <li class="series-item series-item-card">
                    <span class="series-part-no"><?= sprintf('%02d', $s['count']) ?></span>
                    <div class="series-item-body">
                        <a class="series-item-title" href="/series/<?= Http::e($s['slug']) ?>">
                            📡 <?= Http::e($s['title']) ?>
                        </a>
                        <div class="series-item-meta">
                            <?= $s['count'] ?> part<?= $s['count'] === 1 ? '' : 's' ?>
                            · <?= Http::e($s['firstDate']) ?>
                            <?php if ($s['firstDate'] !== $s['lastDate']): ?>
                                → <?= Http::e($s['lastDate']) ?>
                            <?php endif; ?>
                            · slug: <code><?= Http::e($s['slug']) ?></code>
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
