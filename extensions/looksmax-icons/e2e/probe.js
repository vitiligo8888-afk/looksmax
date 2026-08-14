/*
 * The in-page half of the icon audit. Injected verbatim by audit.ts.
 *
 * It is a separate file rather than a template literal inside the driver so it
 * can be linted, read, and pasted straight into devtools when a single surface
 * needs poking at by hand.
 *
 * ── What counts as an icon ─────────────────────────────────────────────────
 *
 * Not just <iconify-icon>. The operator's two reports were a CSS mask
 * (.Search-input::before) and a spinner (a bordered div), neither of which is an
 * icon element at all. Enumerating only the elements this extension creates is
 * how "fixed two and declared victory" happens, so this walks:
 *
 *   iconify   <iconify-icon>                     — ours
 *   fa        <i class="fa-*">                   — anything the swap missed
 *   svg       a light-DOM <svg>                  — other extensions, core
 *   img       an <img> whose box is icon-sized   — avatars excluded
 *   mask      any element or ::before/::after with a mask-image or an svg
 *             background-image                    — the search glyph lives here
 *   spinner   .LoadingIndicator                  — the notifications complaint
 *
 * ── What is a failure ──────────────────────────────────────────────────────
 *
 *   unresolved  an <iconify-icon> with no <svg> in its shadow root, or an
 *               icon-shaped box with no geometry behind it at all. This is the
 *               root cause of "big weird rotating icon": a replaced element
 *               with no intrinsic size takes whatever its flex parent offers.
 *   ratio       rendered aspect ratio more than 10% off the SOURCE artwork's
 *               ratio (from the shadow svg's viewBox, the light svg's viewBox,
 *               or the viewBox inside the mask's data: URI). This is the check
 *               that catches "stretched" as a number instead of a squint.
 *   oversize    box longer than 1.6x the computed font-size on an element whose
 *               size is supposed to come from font-size.
 *   zero        visible, but measures 0 on an axis.
 *   overflow    sticks out past its parent's border box by more than 1px.
 *
 * Every record is returned whether it passed or failed, so the report can state
 * a denominator. A pass rate with no denominator is not evidence.
 */
(() => {
  const R = (n) => Math.round(n * 100) / 100;

  /* Elements deliberately allowed to be bigger than their font-size, because
   * their size comes from somewhere else entirely (a logo mark, an avatar
   * frame, a rank badge). Matched against the element's own selector chain, and
   * kept SHORT: every entry here is a check that no longer runs. */
  const FONT_SIZED_EXEMPT = [
    '.Brand-splash', '.Hero', '-logo', 'Logo', 'Avatar', 'avatar',
    'Frame', 'frame', 'Splash', 'splash', 'Banner', 'banner',
  ];

  const sel = (el) => {
    if (!el || !el.tagName) return null;
    const cls = String((el.getAttribute && el.getAttribute('class')) || '')
      .trim().split(/\s+/).filter(Boolean).slice(0, 3).join('.');
    return el.tagName.toLowerCase() + (cls ? '.' + cls : '');
  };

  const chain = (el, depth) => {
    const parts = [];
    let n = el;
    for (let i = 0; i < (depth || 4) && n; i++) { parts.unshift(sel(n)); n = n.parentElement; }
    return parts.filter(Boolean).join(' > ');
  };

  const visible = (el) => {
    try {
      if (el.checkVisibility) return el.checkVisibility({ checkVisibilityCSS: true });
    } catch (e) { /* older engine */ }
    return !!(el.getClientRects && el.getClientRects().length);
  };

  /** Aspect ratio of the artwork itself, from an <svg>'s viewBox. */
  const svgRatio = (s) => {
    if (!s) return null;
    const vb = s.getAttribute && s.getAttribute('viewBox');
    if (vb) {
      const a = vb.trim().split(/[\s,]+/).map(Number);
      if (a.length === 4 && a[2] > 0 && a[3] > 0) return a[2] / a[3];
    }
    const w = parseFloat(s.getAttribute('width'));
    const h = parseFloat(s.getAttribute('height'));
    if (w > 0 && h > 0) return w / h;
    return null;
  };

  /** Aspect ratio of the artwork inside a url(data:image/svg+xml,...) value. */
  const cssUrlRatio = (value) => {
    try {
      const m = /url\((['"]?)([\s\S]*?)\1\)/.exec(value || '');
      if (!m) return null;
      let s = m[2];
      if (/^data:image\/svg\+xml;base64,/i.test(s)) s = atob(s.split(',').slice(1).join(','));
      else if (/^data:image\/svg\+xml/i.test(s)) s = decodeURIComponent(s.split(',').slice(1).join(','));
      else return null;
      const v = /viewBox\s*=\s*["']?([-\d.\s,]+)["']?/i.exec(s);
      if (v) {
        const a = v[1].trim().split(/[\s,]+/).map(Number);
        if (a.length === 4 && a[2] > 0 && a[3] > 0) return a[2] / a[3];
      }
      const w = /\bwidth\s*=\s*["']?([\d.]+)/i.exec(s);
      const h = /\bheight\s*=\s*["']?([\d.]+)/i.exec(s);
      if (w && h && +w[1] > 0 && +h[1] > 0) return +w[1] / +h[1];
    } catch (e) { /* not decodable — reported as unknown intrinsic */ }
    return null;
  };

  const records = [];

  /**
   * One measurement. `box` may be the element's own rect or a pseudo-element's
   * computed size, so it is passed in rather than read here.
   */
  function record(o) {
    const fails = [];
    const { w, h, fontSize, intrinsic, resolved, fontSized, hidden } = o;
    const ratio = h > 0 ? w / h : 0;

    if (!hidden) {
      if (resolved === false) fails.push('unresolved');
      if (w <= 0 || h <= 0) fails.push('zero');
      else if (intrinsic != null && intrinsic > 0) {
        /*
         * Two ratios, because they fail for different reasons and a single
         * number hides one of them.
         *
         *   painted   the <svg> the component actually painted. If this is off,
         *             the ARTWORK is distorted — the operator's "stretched".
         *   ratio     the host element's box. If the svg is square but the host
         *             is 1.25:1 (Flarum core reserves a fixed-width column for
         *             FontAwesome's variable-width glyphs), the glyph is not
         *             distorted but it is off-centre in a box that was sized for
         *             a different rendering technology, and the column it was
         *             supposed to align no longer aligns.
         *
         * Both fail. The reason string says which, so the fix is obvious.
         */
        const dev = Math.abs(ratio / intrinsic - 1);
        if (o.paintedRatio != null && o.paintedRatio > 0) {
          const adev = Math.abs(o.paintedRatio / intrinsic - 1);
          if (adev > 0.10) fails.push('artwork-ratio:' + R(adev * 100) + '%');
          if (dev > 0.10) fails.push('hostbox-ratio:' + R(dev * 100) + '%');
        } else if (dev > 0.10) {
          fails.push('ratio:' + R(dev * 100) + '%');
        }
      }
      if (fontSized && fontSize > 0 && Math.max(w, h) > fontSize * 1.6) {
        fails.push('oversize:' + R(Math.max(w, h) / fontSize) + 'x');
      }
      if (o.overflow > 1) fails.push('overflow:' + R(o.overflow) + 'px');
    }

    records.push(Object.assign({}, o, { ratio: R(ratio), fails, pass: fails.length === 0 }));
  }

  /** How far `r` sticks out past its parent's border box, worst axis. */
  function overflowOf(el, r) {
    const p = el.parentElement;
    if (!p || p === document.body || p === document.documentElement) return 0;
    const pcs = getComputedStyle(p);
    // A scroll container is allowed to hold something bigger than itself.
    if (/auto|scroll/.test(pcs.overflow + pcs.overflowX + pcs.overflowY)) return 0;
    /*
     * An `display: inline` parent does not have a box that contains its
     * children — its rect is the union of its line boxes, whose height comes
     * from the font, not from the content. An inline-block icon sitting on the
     * text baseline with `vertical-align: -0.125em` therefore pokes below it by
     * design, exactly as a descender does. Measured: .UserCard-lastSeen
     * reported 1.25px of "overflow" on a 14px icon, which is 0.125 x 14 minus
     * rounding — the rule working, reported as a fault. Every text glyph on the
     * page does the same thing and none of them is a bug.
     */
    if (pcs.display === 'inline' || pcs.display === 'contents') return 0;
    const pr = p.getBoundingClientRect();
    if (pr.width === 0 || pr.height === 0) return 0;
    return Math.max(0, pr.left - r.left, r.right - pr.right, pr.top - r.top, r.bottom - pr.bottom);
  }

  const exempt = (path) => FONT_SIZED_EXEMPT.some((frag) => path.indexOf(frag) !== -1);

  // ------------------------------------------------------------ iconify-icon
  document.querySelectorAll('iconify-icon').forEach((el) => {
    const r = el.getBoundingClientRect();
    const cs = getComputedStyle(el);
    const shadowSvg = el.shadowRoot ? el.shadowRoot.querySelector('svg') : null;
    // The painted box, not the host box. Document CSS cannot reach into the
    // shadow root, so the svg can be a perfectly square 14x14 inside a host
    // that a stylesheet has made 17.5 wide — two different defects that look
    // like one number if only the host is measured.
    const sr = shadowSvg ? shadowSvg.getBoundingClientRect() : null;
    const path = chain(el);
    record({
      kind: 'iconify',
      id: el.getAttribute('icon') || '(no icon attr)',
      path,
      w: R(r.width), h: R(r.height),
      svgW: sr ? R(sr.width) : null,
      svgH: sr ? R(sr.height) : null,
      paintedRatio: sr && sr.height > 0 ? sr.width / sr.height : null,
      fontSize: R(parseFloat(cs.fontSize) || 0),
      display: cs.display,
      parentDisplay: el.parentElement ? getComputedStyle(el.parentElement).display : null,
      intrinsic: svgRatio(shadowSvg),
      resolved: !!shadowSvg,
      fontSized: !exempt(path),
      overflow: R(overflowOf(el, r)),
      hidden: !visible(el),
    });
  });

  // --------------------------------------------------- leftover font awesome
  document.querySelectorAll('i[class*="fa-"], i.fa, i.fas, i.far, i.fab, i.fal, .fa, .fas, .far, .fab')
    .forEach((el) => {
      if (el.tagName === 'ICONIFY-ICON') return;
      const r = el.getBoundingClientRect();
      const cs = getComputedStyle(el);
      const before = getComputedStyle(el, '::before');
      const path = chain(el);
      const cls = String(el.getAttribute('class') || '');
      const faName = (cls.match(/\bfa-([a-z0-9-]+)/) || [])[0] || null;
      // A FontAwesome glyph that never painted: no content, or the webfont did
      // not load so the glyph box collapses. Both look identical to a reader —
      // nothing is there.
      const glyph = before.content && before.content !== 'none' && before.content !== '""';
      record({
        kind: 'fa',
        id: faName || cls.slice(0, 40) || el.tagName.toLowerCase(),
        path,
        w: R(r.width), h: R(r.height),
        fontSize: R(parseFloat(cs.fontSize) || 0),
        display: cs.display,
        parentDisplay: el.parentElement ? getComputedStyle(el.parentElement).display : null,
        fontFamily: before.fontFamily || cs.fontFamily,
        intrinsic: null, // a glyph has no declared ratio; the size checks apply
        resolved: !!glyph,
        fontSized: !exempt(path),
        overflow: R(overflowOf(el, r)),
        hidden: !visible(el),
      });
    });

  // -------------------------------------------------------------- light svgs
  document.querySelectorAll('svg').forEach((el) => {
    // Skip anything inside a shadow root we already measured through its host,
    // and skip large illustrative graphics (charts) — they are not icons.
    const r = el.getBoundingClientRect();
    if (r.width > 96 || r.height > 96) return;
    const cs = getComputedStyle(el);
    const path = chain(el);
    record({
      kind: 'svg',
      id: (el.getAttribute('class') || el.parentElement && el.parentElement.className || 'svg')
        .toString().slice(0, 48),
      path,
      w: R(r.width), h: R(r.height),
      fontSize: R(parseFloat(cs.fontSize) || 0),
      display: cs.display,
      parentDisplay: el.parentElement ? getComputedStyle(el.parentElement).display : null,
      intrinsic: svgRatio(el),
      resolved: !!el.querySelector('path,circle,rect,polygon,polyline,line,ellipse,use,g,image,text'),
      fontSized: !exempt(path),
      overflow: R(overflowOf(el, r)),
      hidden: !visible(el),
    });
  });

  // ------------------------------------------------- mask / background icons
  //
  // The header search glyph is one of these. So is every `.Button--icon::before`
  // a theme might draw. Walking every element is ~3k getComputedStyle calls per
  // pseudo, which is affordable once per surface and is the only way to find
  // icons nobody remembers writing.
  const all = document.querySelectorAll('body *');
  for (let i = 0; i < all.length; i++) {
    const el = all[i];
    if (el.tagName === 'ICONIFY-ICON' || el.tagName === 'SVG') continue;
    ['', '::before', '::after'].forEach((pseudo) => {
      let cs;
      try { cs = getComputedStyle(el, pseudo || null); } catch (e) { return; }
      if (!cs) return;
      if (pseudo && (cs.content === 'none' || !cs.content)) return;

      const mask = cs.maskImage && cs.maskImage !== 'none' ? cs.maskImage
        : (cs.webkitMaskImage && cs.webkitMaskImage !== 'none' ? cs.webkitMaskImage : null);
      const bg = cs.backgroundImage && /svg|\.png|\.gif|\.webp/i.test(cs.backgroundImage)
        ? cs.backgroundImage : null;
      const src = mask || bg;
      if (!src) return;

      // Size of the painted box. For a pseudo-element the computed width and
      // height are resolved values, so they are usable directly; for the
      // element itself use its rect.
      let w, h;
      if (pseudo) { w = parseFloat(cs.width) || 0; h = parseFloat(cs.height) || 0; }
      else { const r = el.getBoundingClientRect(); w = r.width; h = r.height; }

      // A full-bleed decorative background (a page texture, a gradient overlay)
      // is not an icon. Anything above 64px on both axes is scenery.
      if (w > 64 && h > 64) return;

      const path = chain(el) + (pseudo || '');
      // `contain` and `cover` letterbox rather than stretch, so the artwork
      // keeps its ratio inside a differently-shaped box. `100% 100%` and the
      // default `auto` on a mask DO stretch.
      const sizing = (mask ? (cs.maskSize || cs.webkitMaskSize) : cs.backgroundSize) || '';
      const preserves = /contain|cover/.test(sizing);
      record({
        kind: mask ? 'mask' : 'bgimage',
        id: (src.slice(0, 60) + (src.length > 60 ? '…' : '')).replace(/\s+/g, ' '),
        path,
        w: R(w), h: R(h),
        fontSize: R(parseFloat(cs.fontSize) || 0),
        display: cs.display,
        parentDisplay: el.parentElement ? getComputedStyle(el.parentElement).display : null,
        sizing: sizing,
        // If the box letterboxes the artwork, the box's own ratio is not a
        // distortion — but a square box holding a 3:1 mark still looks wrong,
        // so it is reported as `boxRatio` rather than silently dropped.
        intrinsic: preserves ? null : cssUrlRatio(src),
        artRatio: cssUrlRatio(src),
        resolved: null,
        fontSized: false, // a mask box is sized in px on purpose
        overflow: 0,
        hidden: !visible(el),
      });
    });
  }

  // -------------------------------------------------------------- spinners
  //
  // Flarum's LoadingIndicator is NOT an icon: it is a bordered div with
  // border-radius 50% spinning on a keyframe, sized by --size/--thickness set
  // on .LoadingIndicator-container. It renders as "a weird stretched rotating
  // big icon" exactly when it is not square, or when --size did not resolve and
  // width/height fell back to auto inside a flex parent.
  document.querySelectorAll('.LoadingIndicator').forEach((el) => {
    const r = el.getBoundingClientRect();
    const cs = getComputedStyle(el);
    const size = cs.getPropertyValue('--size').trim();
    const thickness = cs.getPropertyValue('--thickness').trim();
    /*
     * offsetWidth/offsetHeight, NOT getBoundingClientRect.
     *
     * The spinner is mid-`animation: spin` at all times, and a client rect is
     * the AXIS-ALIGNED bounding box of the TRANSFORMED element, so it depends
     * on which frame the measurement landed on. The same spinner measured
     * 41x87, 86x38, 60x85 and 75x77 on four consecutive runs — the numbers move
     * even though nothing changed, which is unusable as a gate. offsetWidth is
     * the layout border box and ignores transforms, so it is the same number
     * every time.
     */
    record({
      kind: 'spinner',
      id: '.LoadingIndicator ' + (el.className || ''),
      path: chain(el, 5),
      w: R(el.offsetWidth), h: R(el.offsetHeight),
      rectW: R(r.width), rectH: R(r.height),
      padding: cs.padding,
      fontSize: R(parseFloat(cs.fontSize) || 0),
      display: cs.display,
      parentDisplay: el.parentElement ? getComputedStyle(el.parentElement).display : null,
      sizeVar: size || '(unset)',
      thicknessVar: thickness || '(unset)',
      borderRadius: cs.borderTopLeftRadius,
      borderWidth: cs.borderTopWidth,
      animation: cs.animationName,
      // A spinner must be a circle: a 1:1 box. That IS its intrinsic ratio.
      intrinsic: 1,
      // Unresolved means the sizing custom properties never landed, which is
      // the failure mode that produces a full-width bar that spins.
      resolved: !!size,
      fontSized: false,
      overflow: R(overflowOf(el, r)),
      hidden: !visible(el),
    });
  });

  return {
    href: location.pathname + location.search,
    defined: !!(window.customElements && customElements.get('iconify-icon')),
    /* Every fa-* name the swap script met and had no icon for, collected by
     * js/dist/forum.js. An unmapped name is invisible on the page — the element
     * is empty and less/forum.less hides it — so without this the only way to
     * find one is for a reader to notice a missing button. */
    unmapped: window.__lmxUnmappedIcons || {},
    fontsLoaded: (() => {
      try {
        return document.fonts && document.fonts.check
          ? ['"Font Awesome 5 Free"', '"Font Awesome 5 Brands"']
            .map((f) => f + '=' + document.fonts.check('16px ' + f)).join(' ')
          : 'unknown';
      } catch (e) { return 'error'; }
    })(),
    records,
  };
})()
