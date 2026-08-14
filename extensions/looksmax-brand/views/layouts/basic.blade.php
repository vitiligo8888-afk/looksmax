{{--
  The server-rendered page shell: 404, 500, CSRF mismatch, password reset,
  e-mail confirmation, log-out.

  Core's version (vendor/flarum/core/views/layouts/basic.blade.php) is a white
  page with #333 text and a system font. On a near-black forum that is a jarring
  flash of a different website, and it is the page a visitor most often sees
  from outside — a stale link from search, an expired confirmation e-mail. It is
  also the one page in Flarum that renders with no stylesheet from any
  extension, so it cannot be fixed with CSS: the only way in is to replace the
  view, which Extend\View->extendNamespace does by prepending our hint path
  ahead of core's.

  Self-contained on purpose. No forum.css, no webfont, one image. If the app is
  broken enough to be showing this page, it must not depend on the app's assets
  to render, so the mark is inlined as SVG and the type is the system stack.
--}}
@php
  $brass = '#e8c07d';
  $forumTitle = $settings->get('forum_title', 'Looksmax.lat');
  $base = $url->to('forum')->base();
@endphp
<!DOCTYPE html>
<html lang="{{ app('flarum.locales')->getLocale() }}">
  <head>
    <meta charset="utf-8">
    <title>@if ($__env->hasSection('title'))@yield('title') - @endif{{ $forumTitle }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b0e14">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex">
    <link rel="icon" type="image/svg+xml" href="{{ $url->to('forum')->path('assets/extensions/local-looksmax-brand/icon.svg') }}">
    <link rel="icon" href="{{ $url->to('forum')->path('assets/extensions/local-looksmax-brand/favicon.ico') }}">
    <style>
      *, *::before, *::after { box-sizing: border-box; }
      html { color-scheme: dark; }
      body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 32px 20px;
        background:
          radial-gradient(720px 420px at 18% -20%, rgba(232,192,125,.10), transparent 62%),
          radial-gradient(620px 380px at 100% 120%, rgba(122,162,247,.08), transparent 60%),
          #0e1116;
        color: #eef2f7;
        font: 400 17px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
              "Helvetica Neue", Arial, sans-serif;
        -webkit-font-smoothing: antialiased;
      }
      .card { width: 100%; max-width: 520px; text-align: left; }
      .lockup { display: flex; align-items: center; gap: 12px; margin-bottom: 40px; }
      .lockup svg { display: block; }
      .lockup span { font-size: 21px; font-weight: 800; letter-spacing: -.03em; }
      .lockup b { color: {{ $brass }}; font-weight: 800; }
      .art { color: #5f6d82; margin-bottom: 26px; }
      .art svg { display: block; width: 168px; height: 84px; }
      h1 { margin: 0 0 12px; font-size: 27px; line-height: 1.2; letter-spacing: -.02em; font-weight: 800; }
      p { margin: 0 0 20px; color: #aab5c6; }
      .errors { color: #ff8fa3; }
      .errors ul { margin: 0; padding-left: 18px; }
      a { color: {{ $brass }}; font-weight: 700; text-decoration: none; }
      a:hover { text-decoration: underline; }
      .button, button, input[type=submit] {
        display: inline-block;
        padding: 12px 22px;
        border: 0;
        border-radius: 9px;
        background: {{ $brass }};
        color: #0e1116;
        font: inherit;
        font-weight: 800;
        cursor: pointer;
        text-decoration: none;
      }
      .button:hover { background: #edc683; text-decoration: none; }
      .form { margin-top: 8px; }
      .form-control {
        display: block;
        width: 100%;
        margin-bottom: 12px;
        padding: 12px 14px;
        border-radius: 9px;
        border: 1px solid #5f6d82;
        background: #12161c;
        color: #eef2f7;
        font: inherit;
      }
      .form-control:focus { outline: 2px solid #b3ccff; outline-offset: 1px; border-color: #b3ccff; }
      .rule {
        margin-top: 38px;
        padding-top: 18px;
        border-top: 1px solid #232c39;
        font-size: 14px;
        color: #8b96a8;
      }
    </style>
  </head>
  <body>
    <main class="card">
      <a class="lockup" href="{{ $base }}" aria-label="{{ $forumTitle }}">
        <svg width="26" height="26" viewBox="0 0 32 32" fill="none" aria-hidden="true">
          <path fill-rule="evenodd" fill="{{ $brass }}"
                d="M4 28V8l4-4h4v18h12l4 4v2zM14 22h2v4h-2zM18 22h2v2h-2zM22 22h2v4h-2z"/>
        </svg>
        <span>Looksmax<b>.</b>lat</span>
      </a>

      @yield('content')

      <div class="rule">{{ $forumTitle }}</div>
    </main>
  </body>
</html>
