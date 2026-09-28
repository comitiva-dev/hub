<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comitiva invitation</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 36rem; margin: 4rem auto; padding: 0 1rem; line-height: 1.5; color: #1f2937; }
        code { background: #f3f4f6; padding: 0.1rem 0.3rem; border-radius: 0.25rem; word-break: break-all; }
        @media (prefers-color-scheme: dark) { body { background: #111827; color: #e5e7eb; } code { background: #1f2937; } }
    </style>
</head>
<body>
    <h1>You were invited to a Comitiva workspace</h1>
    <ol>
        <li>Open Comitiva. In <strong>Settings → Hub</strong>, connect to <code>{{ url('/') }}</code> and sign in or create an account with the invited email address.</li>
        <li>In the workspace menu at the top of the sidebar, choose <strong>Join with a link</strong> and paste this page's address:</li>
    </ol>
    <p><code>{{ url()->current() }}</code></p>
</body>
</html>
