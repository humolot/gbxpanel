<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ ($reason ?? 'entry') === 'ip' ? '403 Forbidden' : '404 Not Found' }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #0d0f12; color: #c3c8cf; font-family: "Segoe UI", system-ui, -apple-system, Roboto, Arial, sans-serif; }
        .box { text-align: center; padding: 2rem; max-width: 520px; }
        h1 { font-size: 3.5rem; margin: 0; color: #e8eaed; font-weight: 700; letter-spacing: -.02em; }
        p { color: #858d97; font-size: .95rem; line-height: 1.6; }
        code { background: #1a1e23; border: 1px solid #252a31; padding: .15rem .45rem; border-radius: 5px; color: #e8eaed; }
    </style>
</head>
<body>
<div class="box">
    @if (($reason ?? 'entry') === 'ip')
        <h1>403</h1>
        <p>Your IP address is not allowed to access this panel.</p>
    @else
        <h1>404</h1>
        <p>The requested page could not be found.</p>
        <p style="font-size:.8rem">Administrator: run <code>gbx</code> on the server to see the panel address.</p>
    @endif
</div>
</body>
</html>
