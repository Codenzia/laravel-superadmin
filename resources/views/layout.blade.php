<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ config('app.name') }} — {{ __('Super Admin Recovery') }}</title>
    {{-- Self-contained on purpose: break-glass must render with zero build assets, so no Tailwind classes here. --}}
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f4f4f5; --card: #ffffff; --text: #18181b; --muted: #71717a;
            --border: #e4e4e7; --accent: #18181b; --accent-text: #ffffff;
            --error-bg: #fef2f2; --error-text: #991b1b;
            --ok-bg: #f0fdf4; --ok-text: #166534;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #18181b; --card: #27272a; --text: #fafafa; --muted: #a1a1aa;
                --border: #3f3f46; --accent: #fafafa; --accent-text: #18181b;
                --error-bg: #450a0a; --error-text: #fca5a5;
                --ok-bg: #052e16; --ok-text: #86efac;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: var(--bg); color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        .card {
            background: var(--card); border: 1px solid var(--border); border-radius: 12px;
            padding: 2rem; width: 100%; max-width: 26rem; margin: 1rem;
        }
        h1 { font-size: 1.125rem; margin: 0 0 .5rem; }
        p { color: var(--muted); font-size: .875rem; line-height: 1.5; margin: 0 0 1.25rem; }
        label { display: block; font-size: .8125rem; font-weight: 600; margin-bottom: .375rem; }
        input[type="password"] {
            width: 100%; padding: .5rem .75rem; margin-bottom: 1rem; font-size: .875rem;
            background: var(--bg); color: var(--text);
            border: 1px solid var(--border); border-radius: 8px;
        }
        button {
            width: 100%; padding: .625rem 1rem; font-size: .875rem; font-weight: 600;
            background: var(--accent); color: var(--accent-text);
            border: 0; border-radius: 8px; cursor: pointer;
        }
        button:hover { opacity: .9; }
        .status { background: var(--ok-bg); color: var(--ok-text); }
        .errors { background: var(--error-bg); color: var(--error-text); }
        .status, .errors {
            border-radius: 8px; padding: .75rem 1rem; font-size: .875rem; margin-bottom: 1.25rem;
        }
        .errors ul { margin: 0; padding-left: 1.1rem; }
    </style>
</head>
<body>
    <main class="card">
        @if (session('superadmin-status'))
            <div class="status">{{ session('superadmin-status') }}</div>
        @endif

        @if ($errors->any())
            <div class="errors">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
