<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#047857">
    <title>You're offline · {{ config('site.name') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f8fafc; color: #0f172a;
               font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; padding: 24px; box-sizing: border-box; }
        .card { max-width: 380px; width: 100%; background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 32px 28px; text-align: center; box-shadow: 0 1px 2px rgba(15,23,42,.05); }
        img { width: 56px; height: 56px; border-radius: 14px; }
        h1 { font-size: 20px; margin: 20px 0 8px; }
        p { margin: 0; font-size: 14px; line-height: 1.55; color: #475569; }
        button { margin-top: 22px; border: 0; border-radius: 10px; background: #047857; color: #fff; font: inherit; font-size: 14px; font-weight: 600; padding: 10px 20px; cursor: pointer; }
        button:hover { background: #065f46; }
    </style>
</head>
<body>
    <div class="card">
        <img src="/icons/icon-192.png" alt="{{ config('site.name') }}">
        <h1>You're offline</h1>
        <p>{{ config('site.name') }} needs an internet connection. Bids and auction times are always checked live, so nothing is lost. Reconnect and try again.</p>
        <button type="button" onclick="location.reload()">Try again</button>
    </div>
    <script>window.addEventListener('online', () => location.reload());</script>
</body>
</html>
