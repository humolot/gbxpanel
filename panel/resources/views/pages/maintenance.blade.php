<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Maintenance · {{ $domain }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f5f6f8; color: #1d2127; font-family: "Segoe UI", system-ui, -apple-system, Roboto, Arial, sans-serif; }
        .box { max-width: 520px; text-align: center; padding: 2.5rem 2rem; }
        .icon { width: 64px; height: 64px; margin: 0 auto 1.5rem; border-radius: 16px; background: #1d2127; display: grid; place-items: center; }
        .icon svg { width: 30px; height: 30px; stroke: #fff; }
        h1 { font-size: 1.6rem; font-weight: 650; margin: 0 0 .75rem; }
        p { color: #5f6770; line-height: 1.6; margin: 0; }
        small { display: block; margin-top: 2rem; color: #9aa0a8; font-size: .78rem; }
    </style>
</head>
<body>
<div class="box">
    <div class="icon">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
    </div>
    <h1>We will be back soon</h1>
    <p>{{ $message !== '' ? $message : $domain.' is undergoing scheduled maintenance. Please try again in a few minutes.' }}</p>
    <small>{{ $domain }}</small>
</div>
</body>
</html>
