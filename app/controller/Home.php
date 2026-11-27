<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\Settings;

final class Home extends BaseController
{
    public function index()
    {
        if (!_f19()) {
            return redirect('/install/');
        }
        try {
            $siteName = Settings::siteName();
        } catch (\Throwable $ex) {
            $siteName = SITE_NAME_FALLBACK;
        }

        $v = rawurlencode(APP_VERSION);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');

        $html = <<<'HTML'
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>控制台 · {SITE}</title>
<link rel="icon" href="/assets/brand/logo-mark.webp" type="image/webp">
<link rel="stylesheet" href="/assets/el/element-plus.css?v={V}">
<link rel="stylesheet" href="/assets/admin/admin.css?v={V}">
</head>
<body>
<svg width="0" height="0" style="position:absolute;overflow:hidden" aria-hidden="true">
<defs>
<symbol id="i-overview" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></symbol>
<symbol id="i-calls" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h10"/><circle cx="19" cy="18" r="2"/></symbol>
<symbol id="i-api" viewBox="0 0 24 24"><path d="m8 7-5 5 5 5M16 7l5 5-5 5M13.5 4l-3 16"/></symbol>
<symbol id="i-upstream" viewBox="0 0 24 24"><path d="M9 7V3M15 7V3"/><path d="M7 7h10v4a5 5 0 0 1-10 0V7Z"/><path d="M12 16v5"/></symbol>
<symbol id="i-site" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.4 4 5.6 4 9s-1.5 6.6-4 9c-2.5-2.4-4-5.6-4-9s1.5-6.6 4-9Z"/></symbol>
<symbol id="i-plugin" viewBox="0 0 24 24"><path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="m3 8v8l9 5 9-5V8M12 13v8"/></symbol>
<symbol id="i-group" viewBox="0 0 24 24"><path d="M16 21v-1.8a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4V21"/><circle cx="9" cy="7" r="3.2"/><path d="M22 21v-1.8a4 4 0 0 0-3-3.8"/><path d="M16.5 4.2a3.2 3.2 0 0 1 0 6.1"/></symbol>
<symbol id="i-live" viewBox="0 0 14 14"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5v2a1 1 0 0 1-1 1h-2m0-13h2a1 1 0 0 1 1 1v2m-13 0v-2a1 1 0 0 1 1-1h2m0 13h-2a1 1 0 0 1-1-1v-2m6.5-6V8H5.5m-1 2a3.63 3.63 0 0 0 5 0M4 4.75a.25.25 0 0 1 0-.5m0 .5a.25.25 0 0 0 0-.5m6 .5a.25.25 0 0 1 0-.5m0 .5a.25.25 0 1 0 0-.5"/></symbol>
<symbol id="i-verify" viewBox="0 0 48 48"><circle cx="36.651" cy="33.011" r="1.212" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"/><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" d="M37.401 33.964 38.59 35.3"/><circle cx="29.355" cy="19.872" r="2.47" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"/><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" d="M24.828 28.515a4.526 4.526 0 0 1 9.052-.006v.006z"/><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" d="m33.56 35.691-23.457.025c-1.845-.039-3.382-.96-3.382-2.85V13.024c0-1.891 1.537-2.812 3.382-2.85l25.797.028s2.919 0 2.919 2.822v16.895"/><ellipse cx="37.164" cy="33.699" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" rx="4.115" ry="4.127"/><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" d="M11.443 20.412h10.504m0 4.937h-8.221"/></symbol>
<symbol id="i-system" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></symbol>
<symbol id="i-menu" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></symbol>
<symbol id="i-close" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></symbol>
<symbol id="i-refresh" viewBox="0 0 24 24"><path d="M20 11a8 8 0 1 0-2.3 6.3"/><path d="M20 4v7h-7"/></symbol>
<symbol id="i-eye" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></symbol>
<symbol id="i-eyeoff" viewBox="0 0 24 24"><path d="m3 3 18 18M10.6 6.2A10.9 10.9 0 0 1 12 6c6.5 0 10 6 10 6a17.4 17.4 0 0 1-3.2 3.9M6.6 6.7C3.7 8.6 2 12 2 12s3.5 7 10 7c1.6 0 3-.4 4.3-1"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></symbol>
<symbol id="i-copy" viewBox="0 0 24 24"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></symbol>
<symbol id="i-upload" viewBox="0 0 24 24"><path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></symbol>
<symbol id="i-download" viewBox="0 0 24 24"><path d="M12 4v12M7 11l5 5 5-5"/><path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></symbol>
<symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></symbol>
<symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
<symbol id="i-trash" viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 10v6M14 10v6"/></symbol>
<symbol id="i-play" viewBox="0 0 24 24"><path d="M6 4l14 8-14 8V4Z"/></symbol>
<symbol id="i-link" viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7-7l-1.2 1.2"/><path d="M14 11a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7 7l1.2-1.2"/></symbol>
<symbol id="i-check" viewBox="0 0 24 24"><path d="m5 12 5 5L20 7"/></symbol>
<symbol id="i-arrow" viewBox="0 0 24 24"><path d="m9 5 7 7-7 7"/></symbol>
<symbol id="i-alert" viewBox="0 0 24 24"><path d="M12 3 2 21h20L12 3Z"/><path d="M12 9v5M12 17.5v.5"/></symbol>
<symbol id="i-logout" viewBox="0 0 24 24"><path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5"/><path d="m15 8 4 4-4 4M9 12h10"/></symbol>
<symbol id="i-key" viewBox="0 0 24 24"><circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l3 3M14 9l2 2"/></symbol>
<symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 4.5 3 8 7 10 4-2 7-5.5 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></symbol>
<symbol id="i-test" viewBox="0 0 24 24"><path d="M9 3h6M10 3v5.4L4.5 18.2A2 2 0 0 0 6.3 21h11.4a2 2 0 0 0 1.8-2.8L14 8.4V3"/><path d="M7.5 14.5h9"/></symbol>
</defs>
</svg>
<div id="app">
    <div class="boot-screen">
        <img class="boot-mark" src="/assets/brand/logo-mark.webp" alt="">
        <div class="dots boot-dots"><i></i><i></i><i></i></div>
    </div>
</div>
<script src="/assets/el/vue.global.prod.js?v={V}"></script>
<script src="/assets/el/element-plus.min.js?v={V}"></script>
<script src="/assets/el/zh-cn.min.js?v={V}"></script>
<script src="/assets/admin/qr.js?v={V}"></script>
<script src="/assets/admin/app.js?v={V}"></script>
</body>
</html>
HTML;
        $html = str_replace(['{SITE}', '{V}'], [_f14($siteName), $v], $html);
        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
