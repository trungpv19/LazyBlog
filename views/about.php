<?php
/** @var \App\AboutPage $about */
/** @var string $bodyHtml */
/** @var array{
 *   posts:int,tags:int,series:int,
 *   firstDate:?string,lastDate:?string,
 *   daysOnline:?int,serverUptime:?string,
 *   streak:array{current:int,longest:int,atRisk:bool,nextDeadline:string,hasAny:bool},
 *   badges:list<array{code:string,label:string,description:string,tier:string,current:int,target:int,unlocked:bool,unlockedAt:?string,isRecentUnlock:bool}>
 * } $stats */

use App\Auth;
use App\Http;

$hasStack = $about->stack !== [];
$hasContacts = $about->contacts !== [];
$hasCurrently = $about->currently !== null && $about->currently !== '';
$hasBody = trim((string) $about->bodyMarkdown) !== '';

// Pretty-print "X days online" — falls back to "TODAY" on day 0.
$daysLabel = match (true) {
    $stats['daysOnline'] === null => '—',
    $stats['daysOnline'] === 0    => 'TODAY',
    $stats['daysOnline'] === 1    => '1 DAY',
    default                       => $stats['daysOnline'] . ' DAYS',
};
?>

<article class="about-page">
    <div class="about-hud">

        <!-- Top meta strip: device id · uplink status -->
        <div class="about-meta-strip">
            <span class="about-device-id"><?= Http::e($about->deviceId()) ?></span>
            <?php if ($about->status !== null): ?>
                <span class="about-status">UPLINK: <strong><?= Http::e($about->status) ?></strong></span>
            <?php endif; ?>
        </div>

        <!-- Identity: avatar + name block + currently snippet -->
        <section class="about-identity">
            <?php if ($about->avatar !== null): ?>
                <div class="about-avatar hud-frame">
                    <span class="about-avatar-photo">
                        <img src="<?= Http::e($about->avatar) ?>"
                             alt="<?= Http::e($about->name) ?>"
                             loading="eager"
                             width="180" height="180">
                    </span>
                </div>
            <?php endif; ?>

            <div class="about-name-block">
                <div class="section-tag">§ OPERATOR PROFILE</div>
                <h1 class="post-page-title about-name"><?= Http::e($about->name) ?></h1>
                <?php if ($about->callsign !== null): ?>
                    <div class="about-callsign">// <?= Http::e($about->callsign) ?></div>
                <?php endif; ?>
                <?php if ($about->location !== null): ?>
                    <div class="about-location">↳ <?= Http::e($about->location) ?></div>
                <?php endif; ?>

                <?php if ($hasCurrently): ?>
                    <div class="about-currently-inline">
                        <span class="about-currently-label">NOW &nbsp;»</span>
                        <span class="about-currently-text"><?= Http::e($about->currently) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Stats panel — auto-computed from PostRepository -->
        <section class="about-panel hud-frame about-stats">
            <div class="about-panel-label">&gt; TRANSMISSION STATS</div>
            <ul class="about-stat-grid">
                <li class="about-stat">
                    <span class="about-stat-num"><?= (int) $stats['posts'] ?></span>
                    <span class="about-stat-label">TRANSMISSIONS</span>
                </li>
                <li class="about-stat">
                    <span class="about-stat-num"><?= (int) $stats['tags'] ?></span>
                    <span class="about-stat-label">TAGS</span>
                </li>
                <li class="about-stat">
                    <span class="about-stat-num"><?= (int) $stats['series'] ?></span>
                    <span class="about-stat-label">SERIES</span>
                </li>
                <li class="about-stat">
                    <span class="about-stat-num"><?= Http::e($daysLabel) ?></span>
                    <span class="about-stat-label">SINCE FIRST DATE</span>
                </li>
            </ul>
            <?php if ($stats['lastDate'] !== null && $stats['lastDate'] !== $stats['firstDate']): ?>
                <div class="about-stat-footer">
                    LAST TX <?= Http::e($stats['lastDate']) ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($stats['badges'] !== []): ?>
            <section class="about-panel hud-frame about-badges">
                <div class="about-panel-label">&gt; BADGES</div>
                <ul class="about-badges-grid">
                    <?php foreach ($stats['badges'] as $badge): ?>
                        <?php
                        $isUnlocked = (bool) $badge['unlocked'];
                        $ariaLabel = $isUnlocked
                            ? ($badge['label'] . ': unlocked' . ($badge['unlockedAt'] !== null ? ' on ' . $badge['unlockedAt'] : ''))
                            : ('Locked: ' . $badge['current'] . ' of ' . $badge['target'] . ' toward ' . $badge['label']);
                        ?>
                        <?php
                        $isHidden = ($badge['tier'] ?? 'volume') === 'hidden';
                        $isRecent = !empty($badge['isRecentUnlock']);
                        ?>
                        <li class="about-badge <?= $isUnlocked ? 'is-unlocked' : 'is-locked' ?><?= $isHidden ? ' is-hidden-unlocked' : '' ?><?= $isRecent ? ' is-recent-unlock' : '' ?>"
                            aria-label="<?= Http::e($ariaLabel) ?>">
                            <div class="about-badge-head">
                                <span class="about-badge-icon" aria-hidden="true"><?= $isHidden ? '[★]' : ($isUnlocked ? '[■]' : '[ ]') ?></span>
                                <span class="about-badge-code"><?= Http::e($badge['code']) ?></span>
                            </div>
                            <div class="about-badge-meta">
                                <?php if (!$isUnlocked): ?>
                                    <span class="about-badge-progress">
                                        <?= (int) $badge['current'] ?>/<?= (int) $badge['target'] ?>
                                    </span>
                                <?php elseif ($badge['unlockedAt'] !== null): ?>
                                    <span class="about-badge-date"><?= Http::e($badge['unlockedAt']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="about-badge-desc"><?= Http::e($badge['description']) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <!-- BIO body — full markdown. Sits above CONTACT/STACK so the
             narrative reads before the supporting metadata; the
             two-column grid below acts as a sign-off footer. -->
        <?php if ($hasBody): ?>
            <section class="about-panel hud-frame">
                <div class="about-panel-label">&gt; BIO</div>
                <div class="post-body about-body">
                    <?= $bodyHtml ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- 2-col panel grid: CONTACT + STACK (only when both have content;
             collapses to single column on mobile, or to a single block
             when only one is populated). -->
        <?php if ($hasContacts || $hasStack): ?>
            <div class="about-grid">
                <?php if ($hasContacts): ?>
                    <section class="about-panel hud-frame">
                        <div class="about-panel-label">&gt; CONTACT</div>
                        <ul class="about-contacts">
                            <?php foreach ($about->contacts as $c): ?>
                                <li class="about-contact-row">
                                    <span class="about-contact-label">[ <?= Http::e($c['label']) ?> ]</span>
                                    <?php if ($c['url'] !== null): ?>
                                        <a class="about-contact-value"
                                           href="<?= Http::e($c['url']) ?>"
                                           target="_blank"
                                           rel="noopener noreferrer"><?= Http::e($c['value']) ?> ↗</a>
                                    <?php else: ?>
                                        <span class="about-contact-value"><?= Http::e($c['value']) ?></span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>

                <?php if ($hasStack): ?>
                    <section class="about-panel hud-frame">
                        <div class="about-panel-label">&gt; STACK</div>
                        <ul class="about-stack">
                            <?php foreach ($about->stack as $chip): ?>
                                <li class="about-stack-chip"><?= Http::e($chip) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Decorative terminal log — auto-generated transcript. Pure flavour. -->
        <section class="about-panel hud-frame about-log">
            <div class="about-panel-label">&gt; TRANSMISSION LOG</div>
            <pre class="about-log-body">
<span class="about-log-prompt">$</span> whoami
<span class="about-log-out">&gt; <?= Http::e(mb_strtoupper(str_replace(' ', '_', $about->name), 'UTF-8')) ?><?php
    if ($about->callsign !== null) echo ' / ' . Http::e($about->callsign);
    if ($about->location !== null) echo ' / ' . Http::e(mb_strtoupper(str_replace([' ', ','], ['_', ''], $about->location), 'UTF-8'));
?></span>

<span class="about-log-prompt">$</span> uptime
<span class="about-log-out">&gt; <?php
    // Real /proc/uptime → "up 5d 04:12"; fall back to blog-career days
    // when the file isn't readable (Windows / locked-down host).
    if ($stats['serverUptime'] !== null) {
        echo 'UP ', Http::e($stats['serverUptime']);
    } else {
        echo Http::e($daysLabel), ' ONLINE';
    }
?></span>

<span class="about-log-prompt">$</span> ping ground_station
<span class="about-log-out">&gt; RESPONSE OK &middot; 73<?= $about->callsign !== null ? ' DE ' . Http::e($about->callsign) : '' ?></span></pre>
        </section>

        <?php if (Auth::check()): ?>
            <div class="post-footer">
                <a class="view-source-link view-source-link-edit" href="/admin/about">[ EDIT ABOUT ]</a>
            </div>
        <?php endif; ?>

    </div>
</article>
