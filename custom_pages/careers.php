
<?php

/**
 * FRNP Careers Integration
 * PHP 8.x with cURL
 *
 * Lists current positions and displays job details
 * locally. Applications remain on Reference Services.
 */

const API_BASE = 'https://fourriversnuclearpartnership.referenceservicesinc.com/core/jobs/722';
const PORTAL_BASE = 'https://fourriversnuclearpartnership.referenceservicesinc.com';
const CACHE_SECONDS = 1800;

function esc(mixed $value): string {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


/**
 * Clean externally supplied job-description HTML.
 * Preserves readable formatting while removing unsafe markup.
 *
 * Requires the PHP DOM extension.
 */
function formatJobDescription(array $job): string
{
    $html = (string) (
        $job['advertisingDescriptionHtml']
        ?? $job['description']
        ?? ''
    );

    // Fallback if HTML fields are unavailable.
    if (trim($html) === '') {
        $html = (string)($job['advertisingDescription'] ?? '');
    }

    if (trim($html) === '') {
        return '<p>No job description available.</p>';
    }

    // Repair common Microsoft Word bullet characters.
    $html = str_replace(
        ["\u{F0B7}", "\u{F0A7}", "\u{25A0}", "\u{FFFD}"],
        '•',
        $html
    );

    // If the source is plain text, preserve line breaks.
    if ($html === strip_tags($html)) {
        $lines = preg_split('/\R/u', $html);
        $parts = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $parts[] = '<p>' . esc($line) . '</p>';
        }

        return implode("\n", $parts);
    }

    $dom = new DOMDocument('1.0', 'UTF-8');

    $previous = libxml_use_internal_errors(true);

    $dom->loadHTML(
        '<?xml encoding="UTF-8"><div id="job-content">' .
        $html . '</div>',
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
    );

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $allowed = [
        'p', 'br', 'div',
        'strong', 'b', 'em', 'i', 'u',
        'ul', 'ol', 'li',
        'h2', 'h3', 'h4', 'h5',
        'blockquote', 'span'
    ];

    $clean = function (DOMNode $node) use (&$clean, $allowed): string {

        if ($node instanceof DOMText) {
            return esc($node->nodeValue);
        }

        if (!$node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);

        // Drop executable and unrelated content.
        if (in_array($tag, [
            'script', 'style', 'iframe', 'object',
            'embed', 'form', 'input', 'button',
            'svg', 'img'
        ], true)) {
            return '';
        }

        $content = '';

        foreach ($node->childNodes as $child) {
            $content .= $clean($child);
        }

        // Strip unapproved tags but keep their text.
        if (!in_array($tag, $allowed, true)) {
            return $content;
        }

        if ($tag === 'br') {
            return '<br>';
        }

        // Convert inline Word formatting to simple HTML.
        if ($tag === 'span') {
            return $content;
        }

        return '<' . $tag . '>' .
            $content .
            '</' . $tag . '>';
    };

    $root = $dom->getElementById('job-content');

    if (!$root) {
        return '<p>Description temporarily unavailable.</p>';
    }

    $result = '';

    foreach ($root->childNodes as $child) {
        $result .= $clean($child);
    }

    return trim($result);
}


function apiGet(string $url, string $cacheKey): array {
    $cache = sys_get_temp_dir() . '/frnp_' .
        hash('sha256', $cacheKey) . '.json';

    $readCache = static function () use ($cache): ?array {
        if (!is_file($cache)) return null;
        $json = @file_get_contents($cache);
        if ($json === false) return null;
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    };

    if (
        is_file($cache) &&
        time() - filemtime($cache) < CACHE_SECONDS
    ) {
        $cached = $readCache();
        if ($cached !== null) return $cached;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Cannot initialize cURL.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ]
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response !== false && $status === 200) {
        $json = json_decode($response, true);

        if (
            is_array($json) &&
            ($json['success'] ?? false) === true &&
            isset($json['data'])
        ) {
            @file_put_contents($cache, $response, LOCK_EX);
            return $json;
        }
    }

    // Keep serving previously retrieved listings
    // during short API outages.
    $cached = $readCache();
    if ($cached !== null) return $cached;

    throw new RuntimeException('Career data unavailable.');
}

function listingUrl(): string {
    $params = [
        'cityUrl' => '',
        'countryAbbreviation' => '',
        'stateAbbreviation' => '',
        'isInternal' => 0,
        'showPayFrequency' => 1,
        'showLocation' => 1,
        'locationTitle' => 'Location',
        'locationWeight' => 2,
        'showCustomCategory' => 0,
        'customCategoryTitle' => '',
        'customCategoryWeight' => 0,
        'showEmploymentType' => 0,
        'employmentTypeTitle' => 'Employment Type',
        'employmentTypeWeight' => 5,
        'showJobBoardClassifications' => 0,
        'jobBoardClassificationsTitle' => 'Classification',
        'jobBoardClassificationsWeight' => 4,
        'showJobCategory' => 0,
        'jobCategoryTitle' => '',
        'jobCategoryWeight' => 0,
        'showOrgUnit' => 0,
        'orgUnitTitle' => 'Department',
        'orgUnitWeight' => 2,
        'showDate' => 0,
        'dateTitle' => 'Closing Date',
        'dateWeight' => 3,
        'showWorkplaceType' => 0,
        'workplaceTypeTitle' => 'Workplace Type',
        'workplaceTypeWeight' => 11,
        'chatToApplyButton' => '0',
        'chatToApplySort' => ''
    ];

    return API_BASE . '?' . http_build_query([
        'getParams' => json_encode($params)
    ]);
}

$jobId = filter_input(
    INPUT_GET,
    'job',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$error = null;
$jobs = [];
$detail = null;

try {
    // Always get the current listing first.
    $result = apiGet(listingUrl(), 'list');
    $jobs = $result['data']['jobs'] ?? [];

    if (!is_array($jobs)) {
        throw new RuntimeException('Invalid job list.');
    }

    if ($jobId) {
        // Only display jobs present in the listing.
        $validIds = array_map(
            static fn($j) => (int)($j['id'] ?? 0),
            $jobs
        );

        if (in_array($jobId, $validIds, true)) {
            $result = apiGet(
                API_BASE . '/' . $jobId . '/job-details',
                'detail_' . $jobId
            );
            $detail = $result['data'];
        } else {
            $error = 'This position is no longer listed.';
        }
    }
} catch (Throwable $e) {
    error_log('FRNP Careers: ' . $e->getMessage());
    $error = 'Job information is temporarily unavailable.';
}

?>
<style>
.frnp-careers {
    max-width: 1000px;
    margin: 30px auto;
    padding: 0 20px;
    font-family: Arial, sans-serif;
    color: #243247;
}
.frnp-careers h1 {
    font-size: 30px;
}
.frnp-job {
    border: 1px solid #dbe2ea;
    border-radius: 7px;
    padding: 20px;
    margin: 14px 0;
    background: #fff;
}
.frnp-job h2 {
    margin: 0 0 10px;
    font-size: 20px;
}
.frnp-meta {
    color: #5d697a;
    font-size: 14px;
    margin: 10px 0 20px;
}
.frnp-btn {
    display: inline-block;
    background: #174e7d;
    color: white !important;
    text-decoration: none;
    padding: 11px 19px;
    border-radius: 5px;
}
.frnp-btn:hover {
    background: #10395d;
}
.frnp-description {
    line-height: 1.65;
    white-space: pre-line;
    overflow-wrap: anywhere;
}
.frnp-back {
    display: inline-block;
    margin-bottom: 20px;
}

.frnp-description {
    font-family: Arial, sans-serif;
    font-size: 16px;
    line-height: 1.7;
    color: #333;
    white-space: normal;
    overflow-wrap: anywhere;
}

.frnp-description p {
    margin: 0 0 15px;
}

.frnp-description h2,
.frnp-description h3,
.frnp-description h4 {
    color: #174e7d;
    font-weight: 700;
    line-height: 1.3;
    margin: 25px 0 12px;
}

.frnp-description h2 {
    font-size: 22px;
}

.frnp-description h3 {
    font-size: 19px;
}

.frnp-description ul {
    list-style-type: disc;
    padding-left: 30px;
    margin: 10px 0 20px;
}

.frnp-description ol {
    list-style-type: decimal;
    padding-left: 30px;
    margin: 10px 0 20px;
}

.frnp-description li {
    display: list-item;
    margin-bottom: 8px;
    padding-left: 3px;
}

.frnp-description strong,
.frnp-description b {
    font-weight: 700;
}

.frnp-description blockquote {
    margin: 15px 0;
    padding-left: 20px;
    border-left: 3px solid #174e7d;
}

</style>

<main class="frnp-careers">

<?php if ($error !== null): ?>

    <p><?= esc($error) ?></p>
    <a href="careers.php">Back to Careers</a>

<?php elseif ($detail !== null): ?>

    <!-- INDIVIDUAL JOB PAGE -->

    <a class="frnp-back" href="careers.php">
        &larr; All Job Opportunities
    </a>

    <h1><?= esc($detail['title'] ?? '') ?></h1>

    <p class="frnp-meta">
        Location: <?= esc($detail['city'] ?? '') ?>
        <?php if (!empty($detail['startDateRef'])): ?>
            | Posted: <?= esc($detail['startDateRef']) ?>
        <?php endif; ?>
    </p>

 <div class="frnp-description">
    <?= formatJobDescription($detail) ?>
</div>

    <p>
        <a class="frnp-btn"
           href="<?= esc(PORTAL_BASE . '/jobs/' .
                        (int)$detail['id'] . '.html') ?>"
           target="_blank"
           rel="noopener noreferrer">
            Apply for This Position
        </a>
    </p>

<?php else: ?>

    <!-- ALL JOB LISTINGS -->

    <h1>Current Job Opportunities</h1>

    <p><?= count($jobs) ?> positions available</p>

    <?php foreach ($jobs as $job): ?>

        <?php
        $id = (int)($job['id'] ?? 0);
        if ($id <= 0) continue;
        ?>

        <article class="frnp-job">

            <h2><?= esc($job['title'] ?? '') ?></h2>

            <div class="frnp-meta">

                <?php
                $location = trim(implode(', ', array_filter([
                    $job['city'] ?? '',
                    $job['abbreviation'] ?? ''
                ])));
                ?>

                <?php if ($location !== ''): ?>
                    <p>Location: <?= esc($location) ?></p>
                <?php endif; ?>

                <?php if (!empty($job['startDateRef'])): ?>
                    <p>Posted: <?= esc($job['startDateRef']) ?></p>
                <?php endif; ?>

            </div>

            <a class="frnp-btn"
               href="?job=<?= $id ?>">
                View Job Details
            </a>

        </article>

    <?php endforeach; ?>

<?php endif; ?>

</main>
