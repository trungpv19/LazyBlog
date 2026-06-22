<?php
/** @var string $title */
/** @var list<array<string,mixed>> $posts */
/** @var string|null $flash */

use App\Csrf;
use App\Http;
?>

<section>
    <div class="admin-header-row">
        <div>
            <div class="section-tag">§ ADMIN — POSTS</div>
            <h2>All posts (<?= count($posts) ?>)</h2>
        </div>
        <div class="admin-actions">
            <a class="admin-btn admin-btn-primary" href="/admin/new">[ NEW POST ]</a>
            <form method="post" action="/admin/logout" style="display:inline">
                <input type="hidden" name="_csrf" value="<?= Http::e(Csrf::token()) ?>">
                <button type="submit" class="admin-btn">[ LOG OUT ]</button>
            </form>
        </div>
    </div>

    <?php if ($flash !== null): ?>
        <p class="admin-flash">// <?= Http::e($flash) ?></p>
    <?php endif; ?>

    <?php if ($posts === []): ?>
        <p style="color: var(--text-dim);">// No posts yet. <a href="/admin/new">Create the first one.</a></p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>DATE</th>
                    <th>TITLE</th>
                    <th>TAGS</th>
                    <th>STATUS</th>
                    <th>ACTIONS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($posts as $entry): ?>
                    <tr>
                        <td class="admin-mono"><?= Http::e((string) $entry['date']) ?></td>
                        <td>
                            <a href="/posts/<?= Http::e((string) $entry['slug']) ?>" target="_blank">
                                <?php if (!empty($entry['icon'])): ?><?= Http::e((string) $entry['icon']) ?> <?php endif; ?>
                                <?= Http::e((string) $entry['title']) ?>
                            </a>
                            <div class="admin-slug">/posts/<?= Http::e((string) $entry['slug']) ?></div>
                        </td>
                        <td class="admin-mono"><?= Http::e(implode(', ', (array) $entry['tags'])) ?></td>
                        <td class="admin-mono">
                            <?php if (!empty($entry['draft'])): ?>
                                <span class="admin-status admin-status-draft" title="Draft" aria-label="Draft">Draft</span>
                            <?php elseif ($entry['date'] > date('Y-m-d')): ?>
                                <span class="admin-status admin-status-scheduled" title="Scheduled" aria-label="Scheduled">Scheduled</span>
                            <?php else: ?>
                                <span class="admin-status admin-status-live" title="Live" aria-label="Live">Live</span>
                            <?php endif; ?>
                        </td>
                        <td class="admin-row-actions">
                            <a class="admin-btn admin-btn-sm" href="/admin/edit/<?= Http::e((string) $entry['slug']) ?>">EDIT</a>
                            <form method="post" action="/admin/delete/<?= Http::e((string) $entry['slug']) ?>"
                                  style="display:inline"
                                  onsubmit="return confirm('Delete <?= Http::e((string) $entry['slug']) ?>? This is permanent.');">
                                <input type="hidden" name="_csrf" value="<?= Http::e(Csrf::token()) ?>">
                                <button type="submit" class="admin-btn admin-btn-sm admin-btn-danger">DEL</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
