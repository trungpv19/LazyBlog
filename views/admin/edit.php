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
    <h2><?= $isEdit ? 'EDIT: ' . Http::e($formValues['title']) : 'NEW POST' ?></h2>

    <?php if ($formError !== null): ?>
        <p class="admin-error">// <?= Http::e($formError) ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/save" class="admin-form">
        <input type="hidden" name="_csrf" value="<?= Http::e(Csrf::token()) ?>">
        <input type="hidden" name="mode" value="<?= Http::e($mode) ?>">
        <input type="hidden" name="original_filename" value="<?= Http::e($originalFilename) ?>">

        <!-- Row 1: Title (wide) + Author + Icon — primary identifying fields -->
        <div class="admin-form-row">
            <div class="admin-field admin-field-grow">
                <label class="admin-label" for="title">Title</label>
                <input type="text" name="title" id="title" required
                       value="<?= Http::e($formValues['title']) ?>"
                       class="admin-input"
                       placeholder="Your post title">
            </div>
            <div class="admin-field" style="flex: 0 1 180px">
                <label class="admin-label" for="author">Author</label>
                <input type="text" name="author" id="author"
                       value="<?= Http::e($formValues['author']) ?>"
                       class="admin-input admin-mono">
            </div>
            <div class="admin-field" style="flex: 0 0 96px">
                <label class="admin-label" for="icon">Icon</label>
                <input type="text" name="icon" id="icon"
                       value="<?= Http::e($formValues['icon']) ?>"
                       maxlength="8"
                       class="admin-input"
                       placeholder="📺">
            </div>
        </div>

        <!-- Row 2: Date + Slug + Tags + Draft — secondary structural fields -->
        <div class="admin-form-row">
            <div class="admin-field" style="flex: 0 0 140px">
                <label class="admin-label" for="date">Date</label>
                <input type="text" name="date" id="date" required
                       value="<?= Http::e($formValues['date']) ?>"
                       pattern="\d{4}-\d{2}-\d{2}"
                       class="admin-input admin-mono">
            </div>
            <div class="admin-field" style="flex: 1 1 200px">
                <label class="admin-label" for="slug">Slug</label>
                <input type="text" name="slug" id="slug"
                       value="<?= Http::e($formValues['slug']) ?>"
                       maxlength="80"
                       placeholder="auto-from-title"
                       class="admin-input admin-mono">
            </div>
            <div class="admin-field admin-field-grow">
                <label class="admin-label" for="tags">Tags</label>
                <input type="text" name="tags" id="tags"
                       value="<?= Http::e($formValues['tags']) ?>"
                       class="admin-input admin-mono"
                       placeholder="ham-radio, sstv, history">
            </div>
            <div class="admin-field" style="flex: 0 0 auto">
                <label class="admin-label">&nbsp;</label>
                <label class="admin-checkbox-pill">
                    <input type="checkbox" name="draft" value="1" <?= $formValues['draft'] ? 'checked' : '' ?>>
                    <span>Draft</span>
                </label>
            </div>
        </div>

        <!-- Summary — kept compact, single line -->
        <div class="admin-field">
            <label class="admin-label" for="summary">Summary <span class="admin-label-hint">(optional · listings · og · llms.txt)</span></label>
            <input type="text" name="summary" id="summary"
                   value="<?= Http::e($formValues['summary']) ?>"
                   class="admin-input"
                   placeholder="One-line description shown in post lists and meta tags.">
        </div>

        <!-- Social-card image (per-post og:image override) -->
        <div class="admin-field">
            <label class="admin-label" for="image">Social image <span class="admin-label-hint">(optional · og:image · falls back to first body image)</span></label>
            <input type="text" name="image" id="image"
                   value="<?= Http::e($formValues['image']) ?>"
                   class="admin-input"
                   placeholder="/uploads/2026/06/cover.webp  or  https://…">
        </div>

        <!-- Series — slug + optional part number side by side -->
        <div class="admin-row">
            <div class="admin-field" style="flex: 2 1 0">
                <label class="admin-label" for="series">Series <span class="admin-label-hint">(optional · slug shared across all parts)</span></label>
                <input type="text" name="series" id="series"
                       value="<?= Http::e($formValues['series']) ?>"
                       class="admin-input"
                       placeholder="rtl-sdr-cho-nguoi-moi">
            </div>
            <div class="admin-field" style="flex: 0 0 110px">
                <label class="admin-label" for="part">Part # <span class="admin-label-hint">(opt)</span></label>
                <input type="number" name="part" id="part" min="1" step="1"
                       value="<?= Http::e($formValues['part']) ?>"
                       class="admin-input"
                       placeholder="1">
            </div>
        </div>

        <!-- Body — the focus -->
        <div class="admin-field">
            <label class="admin-label" for="body">Body <span class="admin-label-hint">(markdown)</span></label>
            <textarea name="body" id="body" rows="28" required
                      class="admin-input admin-textarea-body"
                      placeholder="# Heading

Write in **markdown**. Use `code`, [links](https://example.com), and admonitions:

::: highlight
Key fact or callout.
:::

::: story icon=&quot;🌕&quot; title=&quot;A story&quot;
Body of the story card.
:::"><?= Http::e($formValues['body']) ?></textarea>
        </div>

        <div class="admin-actions">
            <button type="submit" class="admin-btn admin-btn-primary">[ <?= $isEdit ? 'SAVE' : 'CREATE' ?> ]</button>
            <a class="admin-btn" href="/admin">[ CANCEL ]</a>
            <?php if ($isEdit && $post !== null): ?>
                <a class="admin-btn" href="/posts/<?= Http::e($post->slug) ?>" target="_blank">[ VIEW ]</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<!-- EasyMDE: markdown editor with live preview, autosave, fullscreen, etc. -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.css">
<!-- Font Awesome 4 — explicit load from jsdelivr (instead of EasyMDE's
     autoDownload, which points at maxcdn.bootstrapcdn.com that isn't in CSP) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/font-awesome@4.7.0/css/font-awesome.min.css">
<script defer src="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.js"></script>
<script defer src="/assets/admin-editor.js"></script>
