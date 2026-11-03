(function () {
    'use strict';

    function getVisitorId() {
        let vid = localStorage.getItem('rnp_vid');
        if (!vid) {
            vid = 'v_' + Math.random().toString(36).substring(2, 12) + '_' + Date.now().toString(36);
            localStorage.setItem('rnp_vid', vid);
        }
        document.cookie = "rnp_vid=" + vid + "; path=/; max-age=" + (60 * 60 * 24 * 365) + "; SameSite=Lax";
        return vid;
    }

    function getSessionId() {
        let sid = sessionStorage.getItem('rnp_sid');
        if (!sid) {
            sid = 's_' + Math.random().toString(36).substring(2, 10) + '_' + Date.now().toString(36);
            sessionStorage.setItem('rnp_sid', sid);
        }
        return sid;
    }

    const visitorId = getVisitorId();
    const sessionId = getSessionId();
    const startTime = Date.now();

    const commentTokenField = document.getElementById('visitor_id');
    if (commentTokenField) {
        commentTokenField.value = visitorId;
    }

    // Resolve base path dynamically
    const basePath = window.location.pathname.includes('/cms/') ? '/cms/' : '/';
    const endpoint = basePath + 'api/tracker.php';

    function sendEvent(data) {
        data.visitor_id = visitorId;
        data.session_id = sessionId;
        data.page_url = window.location.href;
        data.page_title = document.title;
        data.referrer = document.referrer || '';

        const payload = JSON.stringify(data);

        if (navigator.sendBeacon) {
            const blob = new Blob([payload], { type: 'application/json' });
            navigator.sendBeacon(endpoint, blob);
        } else {
            fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: payload,
                keepalive: true
            }).catch(() => {});
        }
    }

    sendEvent({ event_type: 'pageview' });

    window.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            const dwellSec = Math.round((Date.now() - startTime) / 1000);
            if (dwellSec >= 2) {
                sendEvent({
                    event_type: 'dwell_time',
                    dwell_seconds: dwellSec
                });
            }
        }
    });

    document.addEventListener('click', function (e) {
        const link = e.target.closest('a');
        if (!link || !link.href) return;

        const href = link.href;
        const isExternal = href.indexOf(window.location.origin) !== 0 && href.indexOf('http') === 0;
        const fileMatch = href.match(/\.(pdf|docx?|xlsx?|zip|csv|pptx?|tar\.gz)$/i);

        if (fileMatch) {
            sendEvent({
                event_type: 'file_download',
                target_url: href,
                meta_data: JSON.stringify({
                    file_ext: fileMatch[1].toLowerCase(),
                    link_text: (link.textContent || '').trim().substring(0, 100)
                })
            });
        } else if (isExternal || link.getAttribute('data-track')) {
            sendEvent({
                event_type: 'link_click',
                target_url: href,
                meta_data: JSON.stringify({
                    is_external: isExternal,
                    link_text: (link.textContent || '').trim().substring(0, 100)
                })
            });
        }
    }, true);
})();
