<article class="node-article">
    <header class="article-meta">
        <div class="badge">Press Release / News</div>
        <?php if (!empty($node['fields_data']['publication_date'])): ?>
            <time class="pub-date"><?= htmlspecialchars($node['fields_data']['publication_date']) ?></time>
        <?php else: ?>
            <time class="pub-date"><?= date('F j, Y', strtotime($node['created_at'])) ?></time>
        <?php endif; ?>
        <?php if (!empty($node['fields_data']['subtitle'])): ?>
            <h2 class="article-subtitle"><?= htmlspecialchars($node['fields_data']['subtitle']) ?></h2>
        <?php endif; ?>

        <?php if (!empty($node['terms'])): ?>
            <div style="margin-top: 10px;">
                <?php foreach ($node['terms'] as $term): ?>
                    <a href="/cms/term/<?= htmlspecialchars($term['slug']) ?>" class="tag-pill">
                        <?= htmlspecialchars($term['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Slot: Header / Top Meta -->
        <?= render_custom_fields($node, 'header_meta') ?>
    </header>

    <!-- Slot: Above Body / Main Content -->
    <?= render_custom_fields($node, 'above_body') ?>

    <div class="node-body">
        <?= $node['body'] ?>
    </div>

    <!-- Slot: Below Body / Main Content -->
    <?= render_custom_fields($node, 'below_body') ?>
</article>