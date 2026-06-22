<?php
/** @var string $title */
/** @var string $next */
/** @var string|null $error */

use App\Csrf;
use App\Http;
?>

<section class="admin-card">
    <div class="section-tag">§ ADMIN — AUTHENTICATION</div>
    <h2>Login</h2>

    <?php if ($error !== null): ?>
        <p class="admin-error">// <?= Http::e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/login" class="admin-form">
        <input type="hidden" name="_csrf" value="<?= Http::e(Csrf::token()) ?>">
        <input type="hidden" name="next" value="<?= Http::e($next) ?>">

        <label class="admin-label" for="password">PASSWORD</label>
        <input type="password" name="password" id="password" required autofocus
               autocomplete="current-password" class="admin-input">

        <div class="admin-actions">
            <button type="submit" class="admin-btn admin-btn-primary">[ LOG IN ]</button>
            <a class="admin-btn" href="/">[ BACK ]</a>
        </div>
    </form>
</section>
