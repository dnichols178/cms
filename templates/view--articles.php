<div class="view-articles">
    <p style="margin-bottom: 25px; color: #555;">Official updates, media statements, and project milestones from the Paducah Site.</p>
    
    <?php if (empty($articles)): ?>
        <p>No press releases currently published.</p>
    <?php else: ?>
        <div class="articles-list">
            <?php foreach ($articles as $art): ?>
                <article class="article-summary-card" style="margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid var(--frnp-border);">
                    <div style="font-size: 0.85rem; color: #777; margin-bottom: 4px;">
                        <?= htmlspecialchars($art['fields_data']['publication_date'] ?? date('F j, Y', strtotime($art['created_at']))) ?>
                    </div>
                    <h2 style="font-size: 1.35rem; margin-bottom: 8px;">
                        <a href="/cms/<?= htmlspecialchars($art['slug']) ?>" style="color: var(--frnp-navy); text-decoration: none;">
                            <?= htmlspecialchars($art['title']) ?>
                        </a>
                    </h2>
                    <?php if (!empty($art['fields_data']['subtitle'])): ?>
                        <div style="font-style: italic; color: #444; margin-bottom: 10px; font-size: 0.95rem;">
                            <?= htmlspecialchars($art['fields_data']['subtitle']) ?>
                        </div>
                    <?php endif; ?>
                    <p style="color: #333; font-size: 0.95rem; line-height: 1.5;">
                        <?= htmlspecialchars($art['teaser']) ?>...
                    </p>
                    <div style="margin-top: 10px;">
                        <a href="/cms/<?= htmlspecialchars($art['slug']) ?>" style="color: var(--frnp-navy); font-weight: 600; font-size: 0.85rem; text-decoration: underline;">
                            Read Full Release &raquo;
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>