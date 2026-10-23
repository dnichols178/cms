<div class="view-contact">
    <p style="margin-bottom: 25px; color: #444;">
        For general questions regarding Paducah Site deactivation, environmental reports, procurement, or community outreach, contact the Four Rivers Nuclear Partnership communications office below.
    </p>

    <?php if (!empty($contact_success)): ?>
        <div class="notice" style="background:#e8f5e9; border-left:4px solid #2e7d32; padding:15px; margin-bottom:20px; color:#1b5e20;">
            ✓ Your inquiry has been transmitted to the site communications team. We will review your message promptly.
        </div>
    <?php endif; ?>

    <?php if (!empty($contact_error)): ?>
        <div class="notice" style="background:#fee; border-left:4px solid #c00; padding:15px; margin-bottom:20px; color:#c00;">
            <?= htmlspecialchars($contact_error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="/cms/contact" style="background: #fdfdfd; padding: 25px; border: 1px solid #dcdcdc; border-radius: 4px;">
        <div class="form-row" style="margin-bottom: 15px;">
            <label style="display:block; font-weight:600; margin-bottom:5px;">Full Name *</label>
            <input type="text" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" style="width:100%; padding:9px; border:1px solid #ccd0d5; border-radius:3px;">
        </div>
        <div class="form-row" style="margin-bottom: 15px;">
            <label style="display:block; font-weight:600; margin-bottom:5px;">Email Address *</label>
            <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" style="width:100%; padding:9px; border:1px solid #ccd0d5; border-radius:3px;">
        </div>
        <div class="form-row" style="margin-bottom: 15px;">
            <label style="display:block; font-weight:600; margin-bottom:5px;">Subject *</label>
            <input type="text" name="subject" required value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>" style="width:100%; padding:9px; border:1px solid #ccd0d5; border-radius:3px;">
        </div>
        <div class="form-row" style="margin-bottom: 15px;">
            <label style="display:block; font-weight:600; margin-bottom:5px;">Message *</label>
            <textarea name="message" rows="6" required style="width:100%; padding:9px; border:1px solid #ccd0d5; border-radius:3px;"><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
        </div>
        <div style="display:none !important;" aria-hidden="true">
            <label for="frnp_hp_company">Do not fill this out if human</label>
            <input type="text" id="frnp_hp_company" name="frnp_hp_company" tabindex="-1" autocomplete="off">
        </div>
        <input type="hidden" name="form_token_time" value="<?= time() ?>">
        <button type="submit" class="btn-primary" style="padding:10px 24px;">Submit Message</button>
    </form>
</div>