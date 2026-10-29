<?php declare(strict_types=1); ?>
<div class="contact-page-container">
    <p style="margin-bottom: 24px; color: #475569; font-size: 1.05rem; line-height: 1.6;">
        For questions regarding site cleanup operations, media inquiries, or general communications, please submit the form below.
    </p>

    <?php if (!empty($contact_success)): ?>
        <div class="notice" style="background:#ecfdf5; border-left:4px solid #059669; padding:16px 20px; color:#065f46; border-radius:4px; margin-bottom:25px;">
            <h4 style="margin:0 0 4px 0; font-size:1.05rem;">Message Dispatched Successfully</h4>
            <p style="margin:0; font-size:0.92rem;">Thank you for contacting FRNP Communications. Our team will review your inquiry promptly.</p>
        </div>
    <?php endif; ?>

    <?php if (!empty($contact_error)): ?>
        <div class="notice" style="background:#fef2f2; border-left:4px solid #dc2626; padding:16px 20px; color:#991b1b; border-radius:4px; margin-bottom:25px;">
            <?= htmlspecialchars($contact_error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= base_path('contact') ?>" style="background: #fdfdfd; padding: 25px; border: 1px solid #dcdcdc; border-radius: 4px;">
    
    <!-- Anti-Spam Honeypot (Invisible to human users, filled by bots) -->
    <div style="display: none !important; position: absolute; left: -9999px;" aria-hidden="true">
        <label for="frnp_hp_company">Company</label>
        <input type="text" name="frnp_hp_company" id="frnp_hp_company" tabindex="-1" autocomplete="off" value="">
        <input type="hidden" name="form_token_time" value="<?= time() ?>">
    </div>

    <div class="form-row" style="margin-bottom: 15px;">
        <label style="display:block; font-weight:600; margin-bottom:5px;">Full Name *</label>
        <input type="text" name="name" required style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:4px;" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
    </div>

    <div class="form-row" style="margin-bottom: 15px;">
        <label style="display:block; font-weight:600; margin-bottom:5px;">Email Address *</label>
        <input type="email" name="email" required style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:4px;" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
    </div>

    <div class="form-row" style="margin-bottom: 15px;">
        <label style="display:block; font-weight:600; margin-bottom:5px;">Subject *</label>
        <input type="text" name="subject" required style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:4px;" value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>">
    </div>

    <div class="form-row" style="margin-bottom: 20px;">
        <label style="display:block; font-weight:600; margin-bottom:5px;">Message *</label>
        <textarea name="message" rows="5" required style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:4px;"><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
    </div>

    <button type="submit" class="btn-primary" style="padding: 10px 24px; font-size: 0.95rem; cursor: pointer;">Send Message</button>
</form>
</div>
