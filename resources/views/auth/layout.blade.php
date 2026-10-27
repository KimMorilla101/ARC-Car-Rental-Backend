<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>@yield('title') · ARC Ride</title>
        <style>
            * { box-sizing: border-box; }
            body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; background: #f4f6fa; color: #1b2433; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
            main { width: 100%; max-width: 400px; background: #fff; border-radius: 16px; padding: 28px 24px; box-shadow: 0 4px 24px rgba(20, 30, 60, .08); }
            h1 { margin: 0 0 6px; font-size: 22px; }
            p { margin: 0 0 20px; color: #5b6576; line-height: 1.5; }
            label { display: block; font-size: 14px; font-weight: 600; margin: 14px 0 6px; }
            input { width: 100%; padding: 12px; font-size: 16px; border: 1px solid #d5dae3; border-radius: 10px; }
            input:focus { outline: 2px solid #1e5eff; border-color: transparent; }
            input[readonly] { background: #f4f6fa; color: #5b6576; }
            button { width: 100%; margin-top: 22px; padding: 13px; font-size: 16px; font-weight: 600; color: #fff; background: #1e5eff; border: 0; border-radius: 10px; cursor: pointer; }
            .error { color: #c4271b; font-size: 14px; margin-top: 6px; }
            .brand { font-weight: 700; color: #1e5eff; margin-bottom: 18px; letter-spacing: .02em; }
        </style>
    </head>
    <body>
        <main>
            <div class="brand">ARC Ride</div>
            @yield('content')
        </main>
    </body>
</html>
