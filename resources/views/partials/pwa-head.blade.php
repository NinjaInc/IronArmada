    <link rel="manifest" href="/manifest.webmanifest"/>
    <meta name="theme-color" content="#0b1117"/>
    <meta name="mobile-web-app-capable" content="yes"/>
    <meta name="apple-mobile-web-app-capable" content="yes"/>
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"/>
    <meta name="apple-mobile-web-app-title" content="Iron Armada"/>
    <link rel="apple-touch-startup-image" href="/img/iron-armada/splash/ios-1170x2532.jpg" media="(device-width: 390px) and (device-height: 844px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)"/>
    <link rel="apple-touch-startup-image" href="/img/iron-armada/splash/ios-1179x2556.jpg" media="(device-width: 393px) and (device-height: 852px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)"/>
    <link rel="apple-touch-startup-image" href="/img/iron-armada/splash/ios-1290x2796.jpg" media="(device-width: 430px) and (device-height: 932px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)"/>
    <script>
        // Installed-app launch splash: shown only in standalone mode, removed on load (4s safety cap).
        (function () {
            try {
                var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
                if (!standalone) { return; }
                var el = document.createElement('div');
                el.id = 'ia-splash';
                el.setAttribute('aria-hidden', 'true');
                el.style.cssText = 'position:fixed;inset:0;z-index:2147483647;background:#070a0e url(/img/iron-armada/splash/splash-900x1600.jpg) center/cover no-repeat;transition:opacity .4s ease;';
                document.documentElement.appendChild(el);
                var done = function () {
                    el.style.opacity = '0';
                    setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, 450);
                };
                window.addEventListener('load', done);
                setTimeout(done, 4000);
            } catch (e) {}
        })();
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {});
            });
        }
    </script>
