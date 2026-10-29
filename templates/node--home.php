<?php
declare(strict_types=1);

$hero_video_url  = get_site_setting('home_hero_video', base_path('assets/video/deactivation-loop.mp4'));
$hero_poster_url = get_site_setting('home_hero_poster', base_path('assets/img/hero-poster.webp'));

// Fall back to .jpg if the client browser does not advertise WebP support
$accepts_webp = isset($_SERVER['HTTP_ACCEPT']) && str_contains(strtolower($_SERVER['HTTP_ACCEPT']), 'image/webp');

if (!$accepts_webp && str_ends_with(strtolower($hero_poster_url), '.webp')) {
    $hero_poster_url = preg_replace('/\.webp$/i', '.jpg', $hero_poster_url);
}
$hero_headline   = get_site_setting('home_hero_headline', 'Future in Focus');
$hero_subhead    = get_site_setting('home_hero_subhead', 'Safe. Compliant. Mission-Driven Environmental Remediation.');
?>
<!-- Full-Viewport Skydio-Style Video Hero -->
<section class="skydio-hero-wrapper" id="homeHero">
    <div class="video-crop-container">
        <video 
            class="hero-video-element"
            autoplay 
            muted 
            loop 
            playsinline 
            preload="metadata"
            poster="<?= htmlspecialchars($hero_poster_url) ?>"
            aria-hidden="true"
        >
            <source src="<?= htmlspecialchars($hero_video_url) ?>" type="video/mp4">
            <img src="<?= htmlspecialchars($hero_poster_url) ?>" alt="<?= htmlspecialchars($hero_headline) ?>" style="width:100%;height:100%;object-fit:cover;">
        </video>

        <!-- Dark Gradient Scrim for Readability -->
        <div class="hero-scrim-overlay"></div>
    </div>

    <!-- Foreground Content Layer -->
    <div class="hero-foreground-content">
        <div class="hero-content-inner">
            <h1 class="hero-main-title"><?= htmlspecialchars($hero_headline) ?></h1>
            <p class="hero-tagline"><?= htmlspecialchars($hero_subhead) ?></p>
            
            <div class="hero-button-group">
                <a href="<?= base_path('program-areas') ?>" class="btn-hero-solid">Learn More</a>
                <a href="#overviewSection" class="btn-hero-translucent" onclick="smoothScrollToOverview(event)">
                    Additional Information <span>&darr;</span>
                </a>
            </div>
        </div>

        <!-- Scroll Indicator Cue -->
        <div class="hero-scroll-cue" onclick="smoothScrollToOverview(event)" title="Scroll down">
            <span class="scroll-label">Scroll to explore</span>
            <div class="scroll-arrow-indicator"></div>
        </div>
    </div>
</section>
