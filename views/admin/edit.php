<?php
/** @var string $title */
/** @var string $mode  'new' | 'edit' */
/** @var \App\Post|null $post */
/** @var string $originalFilename */
/** @var string|null $formError */
/** @var array{date:string,slug:string,title:string,author:string,tags:string,draft:bool,icon:string,summary:string,body:string} $formValues */

use App\Csrf;
use App\Http;

$isEdit = $mode === 'edit';
?>

<section>
    <div class="section-tag">§ ADMIN — <?= $isEdit ? 'EDIT POST' : 'NEW POST' ?></div>
    <h2><?= $isEdit ? 'Edit: ' . Http::e($formValues['title']) : 'New post' ?></h2>

    <?php if ($formError !== null): ?>
        <p class="admin-error">// <?= Http::e($formError) ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/save" class="admin-form">
        <input type="hidden" name="_csrf" value="<?= Http::e(Csrf::token()) ?>">
        <input type="hidden" name="mode" value="<?= Http::e($mode) ?>">
        <input type="hidden" name="original_filename" value="<?= Http::e($originalFilename) ?>">

        <div class="admin-grid">
            <div>
                <label class="admin-label" for="date">DATE (YYYY-MM-DD)</label>
                <input type="text" name="date" id="date" required
                       value="<?= Http::e($formValues['date']) ?>"
                       pattern="\d{4}-\d{2}-\d{2}"
                       class="admin-input admin-mono">
            </div>
            <div>
                <label class="admin-label" for="slug">SLUG ([a-z0-9-])</label>
                <input type="text" name="slug" id="slug"
                       value="<?= Http::e($formValues['slug']) ?>"
                       maxlength="80"
                       placeholder="auto-from-title"
                       class="admin-input admin-mono">
            </div>
        </div>

        <label class="admin-label" for="title">TITLE</label>
        <input type="text" name="title" id="title" required
               value="<?= Http::e($formValues['title']) ?>"
               class="admin-input">

        <div class="admin-grid">
            <div>
                <label class="admin-label" for="author">AUTHOR</label>
                <input type="text" name="author" id="author"
                       value="<?= Http::e($formValues['author']) ?>"
                       class="admin-input admin-mono">
            </div>
            <div>
                <label class="admin-label" for="icon">ICON (emoji, optional)</label>
                <input type="text" name="icon" id="icon"
                       value="<?= Http::e($formValues['icon']) ?>"
                       maxlength="8"
                       class="admin-input admin-mono">
            </div>
        </div>

        <label class="admin-label" for="tags">TAGS (comma-separated)</label>
        <input type="text" name="tags" id="tags"
               value="<?= Http::e($formValues['tags']) ?>"
               class="admin-input admin-mono"
               placeholder="ham-radio, sstv, history">

        <label class="admin-label" for="summary">SUMMARY (optional — used in listings, OG, llms.txt)</label>
        <textarea name="summary" id="summary" rows="2"
                  class="admin-input"><?= Http::e($formValues['summary']) ?></textarea>

        <label class="admin-label admin-checkbox-row">
            <input type="checkbox" name="draft" value="1" <?= $formValues['draft'] ? 'checked' : '' ?>>
            <span>DRAFT (hidden from public site + llms.txt)</span>
        </label>

        <label class="admin-label" for="body">BODY (markdown)</label>
        <textarea name="body" id="body" rows="24" required
                  class="admin-input admin-textarea-body"><?= Http::e($formValues['body']) ?></textarea>

        <div class="admin-actions">
            <button type="submit" class="admin-btn admin-btn-primary">[ <?= $isEdit ? 'SAVE' : 'CREATE' ?> ]</button>
            <a class="admin-btn" href="/admin">[ CANCEL ]</a>
            <?php if ($isEdit && $post !== null): ?>
                <a class="admin-btn" href="/posts/<?= Http::e($post->slug) ?>" target="_blank">[ VIEW ]</a>
            <?php endif; ?>
        </div>
    </form>
</section>
