<article class="node-default">
    <!-- Slot: Header / Top Meta -->
    <?= render_custom_fields($node, 'header_meta') ?>

    <!-- Slot: Above Body / Main Content -->
    <?= render_custom_fields($node, 'above_body') ?>

    <?php if (!empty($node['body'])): ?>
        <div class="node-body">
            <?= $node['body'] ?>
        </div>
    <?php endif; ?>

    <!-- Slot: Below Body / Main Content -->
    <?= render_custom_fields($node, 'below_body') ?>
</article>