(() => {
    if (!window.omongStat?.collectUrl) {
        return;
    }

    const payload = JSON.stringify({
        path: window.location.pathname,
        postId: window.omongStat.postId ?? 0,
        referrer: document.referrer,
    });

    if (navigator.sendBeacon) {
        navigator.sendBeacon(
            window.omongStat.collectUrl,
            blob
        );

        return;
    }
    
    fetch (window.omongStat.collectUrl, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
        },
        body: payload,
        keepalive: true,
    }).catch(() => { });

})();