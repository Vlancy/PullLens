<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Opening GitHub</title>
        <style>
            :root {
                color-scheme: light dark;
                font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            }

            body {
                align-items: center;
                background: #0f172a;
                color: #e5e7eb;
                display: flex;
                justify-content: center;
                margin: 0;
                min-height: 100vh;
            }

            main {
                background: rgba(15, 23, 42, 0.86);
                border: 1px solid rgba(148, 163, 184, 0.24);
                border-radius: 24px;
                box-shadow: 0 24px 80px rgba(0, 0, 0, 0.35);
                max-width: 420px;
                padding: 32px;
                text-align: center;
            }

            h1 {
                font-size: 24px;
                line-height: 1.2;
                margin: 0 0 12px;
            }

            p {
                color: #94a3b8;
                line-height: 1.6;
                margin: 0 0 24px;
            }

            button {
                background: #ffffff;
                border: 0;
                border-radius: 999px;
                color: #020617;
                cursor: pointer;
                font: inherit;
                font-weight: 700;
                padding: 12px 18px;
            }
        </style>
    </head>
    <body>
        <main>
            <h1>Opening GitHub</h1>
            <p>PullLens is sending the GitHub App setup request. If the new tab does not continue automatically, use the button below.</p>

            <form id="github-app-manifest-form" method="post" action="{{ $action }}">
                <textarea name="manifest" hidden>{{ $manifest }}</textarea>
                <button type="submit">Continue to GitHub</button>
            </form>
        </main>

        <script>
            document.getElementById('github-app-manifest-form').submit();
        </script>
    </body>
</html>
