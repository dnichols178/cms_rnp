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

    <form method="POST" action="<?= base_path('contact') ?>" style="background: #ffffff; padding: 30px; border: 1px solid #cbd5e1; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <!-- Bot Traps -->
        <div style="display:none !important;" aria-hidden="true">
            <label for="frnp_hp_company">Company</label>
            <input type="text" name="frnp_hp_company" id="frnp_hp_company" tabindex="-1" autocomplete="off">
            <input type="hidden" name="form_token_time" value="<?= time() ?>">
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 18px;">
            <div class="form-row">
                <label style="display:block; font-weight:600; margin-bottom:6px; color:#0b2545;">Full Name *</label>
                <input type="text" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                       style="width:100%; box-sizing:border-box; padding:10px 12px; border:1px solid #cbd5e1; border-radius:4px;">
            </div>
            <div class="form-row">
                <label style="display:block; font-weight:600; margin-bottom:6px; color:#0b2545;">Email Address *</label>
                <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       style="width:100%; box-sizing:border-box; padding:10px 12px; border:1px solid #cbd5e1; border-radius:4px;">
            </div>
        </div>

        <div class="form-row" style="margin-bottom: 18px;">
            <label style="display:block; font-weight:600; margin-bottom:6px; color:#0b2545;">Subject *</label>
            <input type="text" name="subject" required value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>"
                   placeholder="e.g. Environmental Monitoring Inquiry"
                   style="width:100%; box-sizing:border-box; padding:10px 12px; border:1px solid #cbd5e1; border-radius:4px;">
        </div>

        <div class="form-row" style="margin-bottom: 22px;">
            <label style="display:block; font-weight:600; margin-bottom:6px; color:#0b2545;">Message Copy *</label>
            <textarea name="message" rows="5" required
                      style="width:100%; box-sizing:border-box; padding:10px 12px; border:1px solid #cbd5e1; border-radius:4px; font-family:inherit;"><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
        </div>

        <!-- Security Verification CAPTCHA Card -->
        <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-left: 4px solid #0b2545; padding: 16px 20px; border-radius: 4px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
            <div>
                <label for="captcha_input" style="font-weight: 700; color: #0b2545; font-size: 0.95rem; display: block; margin-bottom: 4px;">
                    Security Verification <span style="color:#dc2626;">*</span>
                </label>
                <span style="font-size: 0.85rem; color: #64748b;">
                    Solve the math equation to prove you are human: 
                    <strong style="color: #0b2545; font-size: 1.05rem; background: #e2e8f0; padding: 2px 8px; border-radius: 3px; margin-left: 4px;">
                        <?= $captcha_prompt ?? '4 + 3' ?> = ?
                    </strong>
                </span>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <input type="number" name="captcha_answer" id="captcha_input" required placeholder="Answer"
                       style="width: 100px; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 1rem; font-weight: 700; text-align: center;">
            </div>
        </div>

        <button type="submit" class="btn-primary" style="padding: 12px 28px; font-size: 1rem; font-weight: 600; cursor: pointer;">
            Submit Message
        </button>
    </form>
</div>