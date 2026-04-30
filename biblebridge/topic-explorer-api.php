<?php
/**
 * Topic Explorer — API-only mode (standalone build)
 * Scripture-first topic detail and browse pages.
 * Uses the BibleBridge API instead of direct DB access.
 * Included by topic-explorer.php.
 *
 * Phase 1a reframe (2026-04-09): description prose + directional flow UI stripped.
 * The API may still return `description`, `flow`, `related` payloads; this template
 * ignores them. Shared-verse adjacency will be added in Task 3.
 */

// $slug and config vars already set by topic-explorer.php

if (!empty($slug)) {
    // =========================================================
    // DETAIL MODE — fetch single topic from API
    // =========================================================
    $apiData = bb_api_topics($slug);

    if (!$apiData || ($apiData['status'] ?? '') !== 'success' || empty($apiData['topic'])) {
        http_response_code(404);
        $pageTitle = 'Topic Not Found — ' . htmlspecialchars($siteName);
        include __DIR__ . '/404.php';
        exit;
    }

    $topic = $apiData['topic'];
    $topicName = $topic['name'];

    $pageTitle    = htmlspecialchars($topicName) . ' — ' . htmlspecialchars($siteName) . ' Topics';
    $canonicalUrl = $siteUrl . $bbBaseUrl . '/topics/' . htmlspecialchars($slug);

    // Shared-verse adjacency (Task 3) — supplied by API
    $adjacency = $apiData['adjacency'] ?? [];

    // Meta description (Task 6) — supplied by API; fall back to a generic line
    $metaDescription = $topic['meta_description']
        ?? ('Anchor scriptures for ' . $topicName . ' on ' . $siteName . '.');

    // Anchors from API
    $anchors = $apiData['anchors'] ?? [];
    $otAnchors = [];
    $ntAnchors = [];
    foreach ($anchors as $a) {
        // verse_id format: book*1000000 + chapter*1000 + verse
        $vid = $a['verse_id'] ?? 0;
        $bookId = intdiv($vid, 1000000);
        $ref = $a['reference'] ?? '';
        $text = $a['text'] ?? '';
        $bookName = '';
        $ch = 0;
        $vn = 0;
        if (preg_match('/^(.+?)\s+(\d+):(\d+)$/', $ref, $m)) {
            $bookName = $m[1];
            $ch = (int)$m[2];
            $vn = (int)$m[3];
        }
        $bookSlug = strtolower(str_replace(' ', '-', $bookName));

        $entry = [
            'ref'     => $ref,
            'slug'    => $bookSlug,
            'chapter' => $ch,
            'verse'   => $vn,
            'text'    => $text,
        ];
        if ($bookId <= 39) {
            $otAnchors[] = $entry;
        } else {
            $ntAnchors[] = $entry;
        }
    }
    $totalAnchors = count($anchors);

} else {
    // =========================================================
    // BROWSE MODE — fetch all topics from API
    // =========================================================
    $apiData = bb_api_topics();

    $allTopics  = [];
    $tierALabel = 'Start with doctrine';
    $tierAIntro = '';
    $tierBLabel = 'For pastoral care & life';
    $tierBIntro = '';
    if ($apiData && ($apiData['status'] ?? '') === 'success') {
        $allTopics  = $apiData['topics'] ?? [];
        $tierALabel = $apiData['tier_a_label'] ?? $tierALabel;
        $tierAIntro = $apiData['tier_a_intro'] ?? $tierAIntro;
        $tierBLabel = $apiData['tier_b_label'] ?? $tierBLabel;
        $tierBIntro = $apiData['tier_b_intro'] ?? $tierBIntro;
    }

    // Filter into tiers. The API ships topics already sorted (tier A in
    // teaching order, tier B alphabetical), so we just preserve order.
    $tierATopics = array_values(array_filter($allTopics, fn($t) => ($t['tier'] ?? null) === 'a'));
    $tierBTopics = array_values(array_filter($allTopics, fn($t) => ($t['tier'] ?? null) === 'b'));

    $topic = null;
    $pageTitle    = 'Scripture Topics — ' . htmlspecialchars($siteName);
    $canonicalUrl = $siteUrl . $bbBaseUrl . '/topics';
    $metaDescription = 'Browse scripture by topic on ' . $siteName . ' — anchor verses curated for each theme.';
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <meta name="description" content="<?= htmlspecialchars($metaDescription) ?>">
    <link rel="canonical" href="<?= $canonicalUrl ?>">
    <link rel="stylesheet" href="<?= $bbBaseUrl ?>/assets/fonts/fonts.css">
    <link rel="stylesheet" href="<?= $bbBaseUrl ?>/assets/reader.min.css?v=20260409h">
    <link rel="icon" type="image/svg+xml" href="<?= $bbBaseUrl ?>/favicon.svg">
    <script>
        (function () {
            var t = localStorage.getItem('bb_theme');
            if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>
</head>
<body class="reader-index-page">

<header class="reader-header">
    <div class="reader-header-left">
        <a href="<?= $bbBaseUrl ?>/read" class="reader-logo"><?= htmlspecialchars($siteName) ?></a>
        <nav class="reader-header-nav">
            <a href="<?= $bbBaseUrl ?>/read" class="reader-header-nav-link">Read</a>
            <a href="<?= $bbBaseUrl ?>/plans" class="reader-header-nav-link">Plans</a>
            <a href="<?= $bbBaseUrl ?>/topics" class="reader-header-nav-link active">Topics</a>
        </nav>
    </div>
    <div class="reader-header-center">
        <button class="mobile-search-toggle" id="mobileSearchToggle" aria-label="Open search">
            <svg width="18" height="18" viewBox="0 0 14 14" fill="none"><circle cx="6" cy="6" r="4.5" stroke="currentColor" stroke-width="1.5"/><path d="M10 10l2.5 2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
        </button>
        <button class="mobile-search-close" id="mobileSearchClose" aria-label="Close search">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
        </button>
        <form class="reader-search-form" action="<?= $bbBaseUrl ?>/read/search" method="get">
            <input class="reader-search-input" type="search" name="q" placeholder="Search scripture..." autocomplete="off" aria-label="Search">
            <button class="reader-search-btn" type="submit" aria-label="Search">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><circle cx="6" cy="6" r="4.5" stroke="currentColor" stroke-width="1.5"/><path d="M10 10l2.5 2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
            </button>
        </form>
    </div>
    <div class="reader-header-right">
        <button class="theme-toggle" id="themeToggle" aria-label="Toggle dark mode">
            <svg class="theme-icon-moon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            <svg class="theme-icon-sun" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
        </button>
    </div>
</header>

<?php if ($topic): ?>
<main class="te-main">

    <!-- 1. HEADER -->
    <div class="te-header">
        <div class="te-breadcrumb"><a href="<?= $bbBaseUrl ?>/topics">Topics</a> / <?= htmlspecialchars($topicName) ?></div>
        <h1 class="te-title"><?= htmlspecialchars($topicName) ?></h1>
    </div>

    <!-- 2. SHARED-VERSE ADJACENCY -->
    <?php if (!empty($adjacency)): ?>
    <section class="te-section te-adjacency">
        <h2 class="te-section-title">Topics that share scripture</h2>
        <p class="te-adjacency-intro">Other topics whose anchor verses also appear under <?= htmlspecialchars($topicName) ?>.</p>
        <ul class="te-adjacency-list">
            <?php foreach ($adjacency as $adj): ?>
            <li class="te-adjacency-item">
                <a href="<?= $bbBaseUrl ?>/topics/<?= htmlspecialchars($adj['slug']) ?>" class="te-adjacency-link"><?= htmlspecialchars($adj['name']) ?></a>
                <span class="te-adjacency-count"><?= (int)$adj['shared'] ?> shared <?= (int)$adj['shared'] === 1 ? 'verse' : 'verses' ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <!-- 3. ANCHOR SCRIPTURES -->
    <?php if ($totalAnchors > 0): ?>
    <section class="te-section">
        <h2 class="te-section-title">Anchor Scriptures <span class="te-anchor-count"><?= $totalAnchors ?> verses</span>
            <button type="button" class="te-copy-all"
                    data-bb-copy-anchors
                    data-topic-name="<?= htmlspecialchars($topicName) ?>"
                    data-source-url="<?= htmlspecialchars($canonicalUrl) ?>">Copy all verses</button>
        </h2>
        <?php if (!empty($otAnchors)): ?>
        <div class="te-testament-label">Old Testament</div>
        <div class="te-anchors">
            <?php foreach ($otAnchors as $idx => $av): ?>
            <div class="te-anchor-item<?= $idx >= 8 ? ' te-anchor-hidden' : '' ?>">
                <a href="<?= $bbBaseUrl ?>/read/<?= $av['slug'] ?>/<?= $av['chapter'] ?>/<?= $av['verse'] ?>"
                   class="te-anchor-ref"><?= htmlspecialchars($av['ref']) ?></a>
                <?php if ($av['text']): ?>
                <p class="te-anchor-text">&ldquo;<?= htmlspecialchars($av['text']) ?>&rdquo;</p>
                <?php endif; ?>
                <button type="button" class="te-anchor-expand" data-bb-context-ref="<?= htmlspecialchars($av['ref']) ?>">Expand context</button>
                <div class="te-anchor-utils">
                    <a href="<?= $bbBaseUrl ?>/read/<?= $av['slug'] ?>/<?= $av['chapter'] ?>/<?= $av['verse'] ?>" class="te-anchor-context">Read in context</a>
                    <button type="button" class="te-anchor-xref" data-bb-xref-ref="<?= htmlspecialchars($av['ref']) ?>">Cross-references</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($ntAnchors)): ?>
        <div class="te-testament-label">New Testament</div>
        <div class="te-anchors">
            <?php foreach ($ntAnchors as $idx => $av):
                $globalIdx = count($otAnchors) + $idx;
            ?>
            <div class="te-anchor-item<?= $globalIdx >= 8 ? ' te-anchor-hidden' : '' ?>">
                <a href="<?= $bbBaseUrl ?>/read/<?= $av['slug'] ?>/<?= $av['chapter'] ?>/<?= $av['verse'] ?>"
                   class="te-anchor-ref"><?= htmlspecialchars($av['ref']) ?></a>
                <?php if ($av['text']): ?>
                <p class="te-anchor-text">&ldquo;<?= htmlspecialchars($av['text']) ?>&rdquo;</p>
                <?php endif; ?>
                <button type="button" class="te-anchor-expand" data-bb-context-ref="<?= htmlspecialchars($av['ref']) ?>">Expand context</button>
                <div class="te-anchor-utils">
                    <a href="<?= $bbBaseUrl ?>/read/<?= $av['slug'] ?>/<?= $av['chapter'] ?>/<?= $av['verse'] ?>" class="te-anchor-context">Read in context</a>
                    <button type="button" class="te-anchor-xref" data-bb-xref-ref="<?= htmlspecialchars($av['ref']) ?>">Cross-references</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($totalAnchors > 8): ?>
        <button class="te-show-all-btn" id="showAllAnchors" onclick="document.querySelectorAll('.te-anchor-hidden').forEach(function(e){e.classList.remove('te-anchor-hidden')});this.style.display='none'">
            Show all <?= $totalAnchors ?> verses
        </button>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- 3. BROWSE ENTRY POINTS -->
    <section class="te-section te-section--entry">
        <a href="<?= $bbBaseUrl ?>/topics" class="te-read-link te-read-link--muted">Browse all topics &rarr;</a>
    </section>

</main>

<?php else: ?>
<main class="te-browse-main">
    <div class="te-browse-hero">
        <h1 class="te-browse-title">Scripture by Topic</h1>
        <p class="te-browse-sub">Use this shelf for sermon prep, doctrine classes, or finding verses for life themes. Each topic gathers anchor scriptures pastors and study tools have connected across church history.</p>
    </div>

    <!-- Topics — Tier A / Tier B intentional shelves (Phase 1a Task 7.5c) -->
    <?php if (!empty($tierATopics)): ?>
    <section class="te-tier">
        <h2 class="te-tier-label"><?= htmlspecialchars($tierALabel) ?></h2>
        <?php if ($tierAIntro): ?><p class="te-tier-intro"><?= htmlspecialchars($tierAIntro) ?></p><?php endif; ?>
        <div class="te-cluster-grid">
            <?php foreach ($tierATopics as $t): ?>
            <a href="<?= $bbBaseUrl ?>/topics/<?= htmlspecialchars($t['slug']) ?>" class="te-cluster-card">
                <div class="te-cluster-card-body">
                    <div class="te-cluster-title"><?= htmlspecialchars($t['name']) ?></div>
                    <?php if (!empty($t['top_anchors'])): ?>
                    <div class="te-cluster-anchors"><?= htmlspecialchars(implode(' · ', $t['top_anchors'])) ?></div>
                    <?php endif; ?>
                </div>
                <div class="te-cluster-footer">
                    <span class="te-cluster-count"><?= (int)($t['anchor_count'] ?? 0) ?> verses</span>
                    <span class="te-cluster-arrow">&rarr;</span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($tierBTopics)): ?>
    <section class="te-tier">
        <h2 class="te-tier-label"><?= htmlspecialchars($tierBLabel) ?></h2>
        <?php if ($tierBIntro): ?><p class="te-tier-intro"><?= htmlspecialchars($tierBIntro) ?></p><?php endif; ?>
        <div class="te-cluster-grid">
            <?php foreach ($tierBTopics as $t): ?>
            <a href="<?= $bbBaseUrl ?>/topics/<?= htmlspecialchars($t['slug']) ?>" class="te-cluster-card">
                <div class="te-cluster-card-body">
                    <div class="te-cluster-title"><?= htmlspecialchars($t['name']) ?></div>
                    <?php if (!empty($t['top_anchors'])): ?>
                    <div class="te-cluster-anchors"><?= htmlspecialchars(implode(' · ', $t['top_anchors'])) ?></div>
                    <?php endif; ?>
                </div>
                <div class="te-cluster-footer">
                    <span class="te-cluster-count"><?= (int)($t['anchor_count'] ?? 0) ?> verses</span>
                    <span class="te-cluster-arrow">&rarr;</span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if (empty($tierATopics) && empty($tierBTopics)): ?>
    <p style="text-align:center; color:var(--text-muted); padding:2rem;">Could not load topics. Please try again later.</p>
    <?php else: ?>
    <p class="te-browse-footer-search">
        Don't see what you need?
        <a href="<?= $bbBaseUrl ?>/read/search">Search scripture directly &rarr;</a>
    </p>
    <?php endif; ?>
</main>
<?php endif; ?>

<?php $bottomNavActive = 'topics'; include __DIR__ . '/bottom-nav.php'; ?>
<script src="<?= $bbBaseUrl ?>/assets/reader.min.js?v=20260409h"></script>
<?php if (!empty($topic)): ?>
<script>
    window.BB_XREF_WALKER_CONFIG = {
        endpoint: '<?= $bbBaseUrl ?>/xref.php?',
        version: 'kjv',
        baseUrl: '<?= $bbBaseUrl ?>'
    };
</script>
<script src="<?= $bbBaseUrl ?>/assets/xref-walker.js?v=20260409h"></script>
<script src="<?= $bbBaseUrl ?>/assets/topic-tools.js?v=20260409h"></script>
<script>
    window.BB_CONTEXT_CONFIG = {
        endpoint: '<?= $bbBaseUrl ?>/context-proxy.php?',
        version: 'kjv',
        window: 2
    };
</script>
<script src="<?= $bbBaseUrl ?>/assets/topic-context.js?v=20260411"></script>
<?php endif; ?>
</body>
</html>
