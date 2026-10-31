<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>WAFFRLI API reference</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <style>body { margin: 0; font-family: system-ui, sans-serif; } .missing { max-width: 40rem; margin: 4rem auto; padding: 0 1rem; line-height: 1.6; }</style>
</head>
<body>
@if ($scalarAvailable)
    <script id="api-reference" data-url="{{ route('api-docs.spec') }}"
            data-configuration='{"theme":"default","withDefaultFonts":false,"telemetry":false,"showDeveloperTools":"never","agent":{"disabled":true},"mcp":{"disabled":true},"hideDownloadButton":false,"persistAuth":false,"defaultHttpClient":{"targetKey":"shell","clientKey":"curl"},"metaData":{"title":"WAFFRLI API reference"}}'></script>
    {{-- Self-hosted Scalar (npm @scalar/api-reference, pinned): no CDN, no fonts, telemetry/AI/MCP off. --}}
    <script src="{{ asset('vendor/scalar/standalone.js') }}"></script>
@else
    <div class="missing">
        <h1>WAFFRLI API reference</h1>
        <p>The Scalar viewer is not built yet. Run <code>npm run build:api-docs</code> (Docker: <code>docker compose run --rm node npm run build:api-docs</code>) and reload.</p>
        <p>The OpenAPI document itself is available at <a href="{{ route('api-docs.spec') }}">{{ route('api-docs.spec') }}</a>.</p>
    </div>
@endif
</body>
</html>
