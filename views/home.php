<?php
/** @var string $title */
/** @var list<array{slug:string,title:string,date:string,tags:list<string>,draft:bool,icon:?string,summary:?string,file:string,mtime:int}> $posts */

use App\Http;
?>

<section>
    <h2>> LATEST BROADCASTS</h2>

    <?php if ($posts === []): ?>
        <p style="color: var(--text-dim);">// NO POSTS YET. AIRWAVES QUIET.</p>
    <?php else: ?>
        <ul class="post-list">
            <?php foreach ($posts as $entry): ?>
                <li class="post-item">
                    <?php if ($entry['tags'] !== [] || !empty($entry['series'])): ?>
                        <div class="post-meta">
                            <?php foreach ($entry['tags'] as $t): ?>
                                <a class="tag-chip" href="/tags/<?= Http::e($t) ?>">#<?= Http::e($t) ?></a>
                            <?php endforeach; ?>
                            <?php if (!empty($entry['series'])): ?>
                                <a class="post-series-tag" href="/series/<?= Http::e((string) $entry['series']) ?>">
                                    📡 <?= Http::e((string) $entry['series']) ?><?php
                                        if (isset($entry['part']) && $entry['part'] !== null) echo ' · PART ' . (int) $entry['part'];
                                    ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <a class="post-title-link" href="/posts/<?= Http::e($entry['slug']) ?>">
                        <span class="post-title">
                            <?php if (!empty($entry['icon'])): ?><span class="post-icon"><?= Http::e($entry['icon']) ?></span> <?php endif; ?>
                            <?= Http::e($entry['title']) ?>
                        </span>
                    </a>
                    <?php if (!empty($entry['summary'])): ?>
                        <p class="post-summary"><?= Http::e($entry['summary']) ?></p>
                    <?php endif; ?>
                    <div class="post-date-row">
                        <span class="post-date">
                            <?= Http::e($entry['date']) ?><?php
                                if (!empty($entry['author'])) {
                                    echo ' // ' . Http::e((string) $entry['author']);
                                }
                            ?>
                        </span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php include __DIR__ . '/_pagination.php'; ?>
    <?php endif; ?>
</section>
