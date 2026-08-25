<?php

namespace Local\Brand;

use Flarum\Frontend\Document;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Everything the brand needs in <head>.
 *
 * Flarum core emits exactly one icon tag — `<link rel="shortcut icon">` from
 * the favicon_path setting (Frontend/Content/Meta.php:58) — and no share tags
 * at all. The rest of a real icon set, the manifest and the Open Graph and
 * Twitter cards are added here, through Document::$head and Document::$meta,
 * which is the extender-supported way to reach the document. Nothing writes
 * raw HTML into a template.
 *
 * Two things this deliberately overrides rather than adds:
 *
 *  - theme-color. Core sets it to themePrimaryColor, which on this forum is
 *    the brass #e8c07d, so Android Chrome paints its address bar gold above a
 *    near-black page. It is set here to the header surface, which is what the
 *    user actually sees at the top of the document.
 *  - description. Core takes it from the forum description, which is right;
 *    it is repeated into og:description and twitter:description so the three
 *    cannot drift.
 *
 * URLs are absolute. A relative og:image is ignored by every crawler that
 * matters, and the failure is invisible until somebody pastes a link.
 */
class Head
{
    /** Where copyAssetsTo() puts this extension's assets/ directory. */
    private const ASSET_PREFIX = 'assets/extensions/local-looksmax-brand';

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected UrlGenerator $url
    ) {
    }

    public function __invoke(Document $document, Request $request): void
    {
        // ?v= busts browser/CDN caches when the icon art changes. Bump the
        // constant when shipping a new mark (v4 = the skinny triple chevron).
        $asset = fn (string $file): string => $this->url->to('forum')->path(self::ASSET_PREFIX.'/'.$file).'?v=chev4';

        $title = $this->settings->get('forum_title') ?: 'Looksmax.lat';
        $description = $this->settings->get('forum_description') ?: '';
        $pageTitle = $document->title ?: $title;
        $canonical = $document->canonicalUrl ?: $this->url->to('forum')->base();
        $ogImage = $this->settings->get('brand.og_image') ?: $asset('og.png');

        // The address bar and the Windows tile follow the surface at the top of
        // the page, not the accent colour.
        $document->meta['theme-color'] = '#0b0e14';
        $document->meta['color-scheme'] = 'dark';
        if ($description !== '') {
            $document->meta['description'] = $description;
        }

        $head = [];

        // ------------------------------------------------------------- icons
        // Order is load-bearing. A browser takes the last icon link it
        // understands, so the SVG comes after the PNGs: any engine that can
        // render it should, and the ones that cannot fall back to the raster
        // drawn for their size.
        $head['brand-favicon-ico'] = '<link rel="icon" href="'.e($asset('favicon.ico')).'" sizes="16x16 32x32 48x48 64x64">';
        $head['brand-favicon-16'] = '<link rel="icon" type="image/png" sizes="16x16" href="'.e($asset('favicon-16.png')).'">';
        $head['brand-favicon-32'] = '<link rel="icon" type="image/png" sizes="32x32" href="'.e($asset('favicon-32.png')).'">';
        $head['brand-favicon-svg'] = '<link rel="icon" type="image/svg+xml" href="'.e($asset('icon.svg')).'">';
        $head['brand-mask-icon'] = '<link rel="mask-icon" href="'.e($asset('mark-brass.svg')).'" color="#e8c07d">';

        // --------------------------------------------------------------- ios
        $head['brand-apple-icon'] = '<link rel="apple-touch-icon" sizes="180x180" href="'.e($asset('apple-touch-icon.png')).'">';
        $head['brand-apple-icon-167'] = '<link rel="apple-touch-icon" sizes="167x167" href="'.e($asset('apple-touch-icon-167.png')).'">';
        $head['brand-apple-icon-152'] = '<link rel="apple-touch-icon" sizes="152x152" href="'.e($asset('apple-touch-icon-152.png')).'">';
        $head['brand-apple-title'] = '<meta name="apple-mobile-web-app-title" content="Looksmax">';
        $head['brand-apple-capable'] = '<meta name="mobile-web-app-capable" content="yes">';
        $head['brand-apple-bar'] = '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">';

        // ----------------------------------------------------------- manifest
        $head['brand-manifest'] = '<link rel="manifest" href="'.e($asset('site.webmanifest')).'">';
        $head['brand-app-name'] = '<meta name="application-name" content="'.e($title).'">';
        $head['brand-tile-color'] = '<meta name="msapplication-TileColor" content="#0b0e14">';
        $head['brand-tile-image'] = '<meta name="msapplication-TileImage" content="'.e($asset('icon-192.png')).'">';

        // ---------------------------------------------------------- open graph
        $head['brand-og-site'] = '<meta property="og:site_name" content="'.e($title).'">';
        $head['brand-og-type'] = '<meta property="og:type" content="website">';
        $head['brand-og-title'] = '<meta property="og:title" content="'.e($pageTitle).'">';
        $head['brand-og-url'] = '<meta property="og:url" content="'.e($canonical).'">';
        // og:locale is not prose but it is not a constant either: a Spanish
        // page announcing en_US makes every share card render in the wrong
        // language on Facebook. The locale file names its own territory.
        $head['brand-og-locale'] = '<meta property="og:locale" content="'.e(resolve('translator')->trans('local-looksmax-brand.lib.og_locale')).'">';
        $head['brand-og-image'] = '<meta property="og:image" content="'.e($ogImage).'">';
        $head['brand-og-image-w'] = '<meta property="og:image:width" content="1200">';
        $head['brand-og-image-h'] = '<meta property="og:image:height" content="630">';
        $head['brand-og-image-type'] = '<meta property="og:image:type" content="image/png">';
        $head['brand-og-image-alt'] = '<meta property="og:image:alt" content="'.e(resolve('translator')->trans('local-looksmax-brand.lib.og_image_alt', ['title' => $title])).'">';

        // -------------------------------------------------------------- twitter
        $head['brand-tw-card'] = '<meta name="twitter:card" content="summary_large_image">';
        $head['brand-tw-title'] = '<meta name="twitter:title" content="'.e($pageTitle).'">';
        $head['brand-tw-image'] = '<meta name="twitter:image" content="'.e($ogImage).'">';

        if ($description !== '') {
            $head['brand-og-desc'] = '<meta property="og:description" content="'.e($description).'">';
            $head['brand-tw-desc'] = '<meta name="twitter:description" content="'.e($description).'">';
        }

        $document->head = array_merge($document->head, $head);
    }
}
