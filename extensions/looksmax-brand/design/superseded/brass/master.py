#!/usr/bin/env python3
"""
Generate the SVG masters. Everything else in the brand — every PNG, the .ico,
the OG image, the e-mail header — is rasterised from these files, so this is
the only place the artwork exists.

The wordmark is emitted as outlines, not as <text>. An SVG logo containing
<text> renders in whatever the viewer happens to have installed: in an e-mail
client, in a Slack unfurl, or on a machine without the webfont it silently
becomes Arial. Outlines are the whole reason a logo is a vector file.

Shaping goes through HarfBuzz so the advance widths and kerning are the ones
the type designer specified, then fontTools pulls each glyph's outline.

Usage: python3 design/master.py            (writes ../assets/*.svg)
"""
from pathlib import Path
import sys

HERE = Path(__file__).parent
ASSETS = HERE.parent / "assets"
ASSETS.mkdir(parents=True, exist_ok=True)
FONT = HERE / "fonts" / "archivo-800.ttf"

# ---------------------------------------------------------------- the palette
BRASS = "#e8c07d"          # primary
INK = "#eef2f7"            # text on dark
PLATE = "#12161c"          # icon plate
INK_DARK = "#12161c"       # text on light

# ------------------------------------------------------------------ the mark
# The chrigger head, engraved. design/chrigger.py cuts the illustration's
# background, separates horns / hair / features, fills the silhouette with
# brass and knocks the boundaries back out as lines, then potraces the result
# onto this same 32-unit grid. Two optical sizes come out of it:
#
#   full    the engraving — horns, hair fringe, brow, eyes, smirk
#   simple  the bare silhouette, for <= 32px, where the lines go sub-pixel
#
# Both are traced from ONE crop so they stay in register; swapping between them
# at a breakpoint must not move the head.
#
# The mark this replaced — a graduated machinist's square, and the eight
# directions it was chosen from — is kept at design/superseded/. See BRAND.md.
import json

_PATHS = json.loads((HERE / "mark-paths.json").read_text())
MARK_D = _PATHS["full"]
MARK_SIMPLE_D = _PATHS["simple"]
MARK_FACE = _PATHS["face"]

# the superseded square, still used by the abstract supporting artwork below,
# which is a measurement motif rather than the mark itself
SQUARE = "M4 28V8l4-4h4v18h12l4 4v2z"
GRADS = "M14 22h2v4h-2zM18 22h2v2h-2zM22 22h2v4h-2z"

SVG_OPEN = ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="{vb}" width="{w}" height="{h}" '
            'fill="none" role="img" aria-label="{label}">')


def svg(vb, w, h, body, label="Looksmax.lat"):
    return SVG_OPEN.format(vb=vb, w=w, h=h, label=label) + body + "</svg>"


def write(name, content):
    (ASSETS / name).write_text(content + "\n")
    print(f"  {name}  {len(content)}b")


# ------------------------------------------------------------------- wordmark
def wordmark_paths(text: str, font_path: Path, size: float, tracking_em: float):
    """Return (paths, advance, cap_height, ascender) in SVG user units.

    paths is a list of (d, char) with y already flipped into SVG space and the
    origin at the text baseline.
    """
    import uharfbuzz as hb
    from fontTools.ttLib import TTFont
    from fontTools.pens.svgPathPen import SVGPathPen
    from fontTools.pens.transformPen import TransformPen
    from fontTools.misc.transform import Transform

    blob = hb.Blob.from_file_path(str(font_path))
    face = hb.Face(blob)
    hbfont = hb.Font(face)
    upem = face.upem
    buf = hb.Buffer()
    buf.add_str(text)
    buf.guess_segment_properties()
    hb.shape(hbfont, buf, {"kern": True, "liga": True})

    tt = TTFont(str(font_path))
    glyphset = tt.getGlyphSet()
    order = tt.getGlyphOrder()
    scale = size / upem
    track = tracking_em * size

    out, x = [], 0.0
    for info, pos in zip(buf.glyph_infos, buf.glyph_positions):
        gname = order[info.codepoint]
        pen = SVGPathPen(glyphset, ntos=lambda v: f"{v:.2f}")
        # flip y (font space is y-up, SVG is y-down) and place at the pen position
        t = Transform(scale, 0, 0, -scale, x + pos.x_offset * scale, -pos.y_offset * scale)
        glyphset[gname].draw(TransformPen(pen, t))
        d = pen.getCommands()
        if d:
            out.append((d, text[info.cluster]))
        x += pos.x_advance * scale + track

    os2 = tt["OS/2"]
    cap = getattr(os2, "sCapHeight", None) or tt["head"].unitsPerEm * 0.72
    return out, x - track, cap * scale, tt["hhea"].ascender * scale


def build_lockup(name, mark_fill, word_fill, dot_fill, tracking=-0.032, orientation="h"):
    """Compose mark + outlined wordmark into one master."""
    SIZE = 100.0                      # wordmark font size in user units
    paths, adv, cap, _ = wordmark_paths("Looksmax.lat", FONT, SIZE, tracking)

    # The mark is a square. The machinist's square filled its 32-unit box edge
    # to edge and 1.42x cap was right for it; the chrigger head does not — it
    # is a head inside a square canvas with clear air at the corners, so at the
    # same nominal height it reads about a fifth smaller. 1.85x restores the
    # weight, which is also the operator's call to make the nav mark bigger.
    # Its foot sits on the text baseline, which is what stops it floating.
    mh = cap * 1.85
    gap = cap * 0.34
    s = mh / 32.0

    if orientation == "h":
        pad = cap * 0.18
        mark_x, mark_y = pad, pad
        word_x = pad + mh + gap
        base_y = pad + mh                          # baseline: mark's foot
        w = word_x + adv + pad
        h = mh + pad * 2
        descender_room = cap * 0.30
        h += descender_room
    else:
        pad = cap * 0.18
        w = max(mh, adv) + pad * 2
        mark_x = (w - mh) / 2
        mark_y = pad
        word_x = (w - adv) / 2
        base_y = pad + mh + cap * 0.62 + cap
        h = base_y + cap * 0.30 + pad

    body = (f'<g transform="translate({mark_x:.2f} {mark_y:.2f}) scale({s:.5f})">'
            f'<path fill-rule="evenodd" fill="{mark_fill}" d="{MARK_D}"/></g>'
            f'<g transform="translate({word_x:.2f} {base_y:.2f})">')
    for d, ch in paths:
        fill = dot_fill if ch == "." else word_fill
        body += f'<path fill="{fill}" d="{d}"/>'
    body += "</g>"
    write(name, svg(f"0 0 {w:.2f} {h:.2f}", f"{w:.2f}", f"{h:.2f}", body))
    return w, h


print("masters:")

# --- the mark, on its own, colour inherited from context ---------------------
write("mark.svg", svg("0 0 32 32", "32", "32",
                      f'<path fill-rule="evenodd" fill="currentColor" d="{MARK_D}"/>',
                      "Looksmax.lat mark"))
write("mark-simple.svg", svg("0 0 32 32", "32", "32",
                             f'<path fill-rule="evenodd" fill="currentColor" d="{MARK_SIMPLE_D}"/>',
                             "Looksmax.lat mark"))
write("mark-brass.svg", svg("0 0 32 32", "32", "32",
                            f'<path fill-rule="evenodd" fill="{BRASS}" d="{MARK_D}"/>',
                            "Looksmax.lat mark"))

# --- plated icon ------------------------------------------------------------
# Gold on the white tab strip of a light-themed browser measures 1.75:1, so the
# favicon carries its own dark plate. On a dark tab strip the plate disappears
# into the chrome and what is left is exactly the bare mark, which is the
# correct fallback rather than an accident.
PLATE_D = "M7 0h18a7 7 0 0 1 7 7v18a7 7 0 0 1-7 7H7a7 7 0 0 1-7-7V7a7 7 0 0 1 7-7z"
inset = 0.78
t = (32 - 32 * inset) / 2
write("icon.svg", svg("0 0 32 32", "32", "32",
                      f'<path fill="{PLATE}" d="{PLATE_D}"/>'
                      f'<g transform="translate({t:.3f} {t:.3f}) scale({inset})">'
                      f'<path fill-rule="evenodd" fill="{BRASS}" d="{MARK_D}"/></g>',
                      "Looksmax.lat"))
# The favicon raster at 16 and 32px drops the graduations: at 16px each one is
# a single pixel and they read as noise on the foot rather than as ticks.
write("icon-small.svg", svg("0 0 32 32", "32", "32",
                            f'<path fill="{PLATE}" d="{PLATE_D}"/>'
                            f'<g transform="translate({t:.3f} {t:.3f}) scale({inset})">'
                            f'<path fill-rule="evenodd" fill="{BRASS}" d="{MARK_SIMPLE_D}"/></g>',
                            "Looksmax.lat"))
# Maskable: the platform may crop to a circle inscribed in the middle 80%, so
# the plate goes full bleed and the mark shrinks into the safe zone.
mi = 0.56
mt = (32 - 32 * mi) / 2
write("icon-maskable.svg", svg("0 0 32 32", "32", "32",
                               f'<rect width="32" height="32" fill="{PLATE}"/>'
                               f'<g transform="translate({mt:.3f} {mt:.3f}) scale({mi})">'
                               f'<path fill-rule="evenodd" fill="{BRASS}" d="{MARK_D}"/></g>',
                               "Looksmax.lat"))
# Apple adds its own corner radius and never renders alpha, so this one is a
# full-bleed square with no rounding of its own.
ai = 0.66
at = (32 - 32 * ai) / 2
write("icon-apple.svg", svg("0 0 32 32", "32", "32",
                            f'<rect width="32" height="32" fill="{PLATE}"/>'
                            f'<g transform="translate({at:.3f} {at:.3f}) scale({ai})">'
                            f'<path fill-rule="evenodd" fill="{BRASS}" d="{MARK_D}"/></g>',
                            "Looksmax.lat"))

# --- lockups ----------------------------------------------------------------
if not FONT.exists():
    sys.exit(f"missing {FONT}")
build_lockup("lockup.svg", BRASS, INK, BRASS)              # on our dark surfaces
# On white the brass in the dot has to change: brass-500 measures 1.71:1 there.
# brass-700 measures 3.68:1 and still reads as gold rather than as brown.
build_lockup("lockup-light.svg", INK_DARK, INK_DARK, "#a18049")  # white: e-mail, print
build_lockup("lockup-mono-light.svg", INK, INK, INK)       # one colour, light artwork
build_lockup("lockup-mono-dark.svg", INK_DARK, INK_DARK, INK_DARK)
build_lockup("lockup-stacked.svg", BRASS, INK, BRASS, orientation="v")

# --- supporting artwork -----------------------------------------------------
# A loading mark, and art for the two empty states a forum always has. Drawn
# from the same geometry as the logo rather than from a stock illustration set,
# which is the usual way a brand comes apart below the header.

# Splash / loading: the silhouette in outline with the engraving held at half
# strength inside it. The outline is what the loading animation in brand.less
# sweeps, and it is the *simple* drawing so the sweep follows one closed
# contour instead of forty.
write("splash.svg", svg("0 0 32 32", "128", "128",
                        f'<path fill-rule="evenodd" fill="none" stroke="{BRASS}" stroke-width="1.1" '
                        f'stroke-linejoin="round" d="{MARK_SIMPLE_D}"/>'
                        f'<path fill-rule="evenodd" fill="{BRASS}" opacity=".45" d="{MARK_D}"/>',
                        "Loading"))

# 404: the mark, with its blade running out into dashes — the measurement does
# not reach. The first attempt drew three disconnected outline fragments and,
# rendered, the right-hand one read as a stray diagonal rather than as a broken
# rule (design/shots/404-1440.png, first version).
write("art-404.svg", svg("0 0 160 80", "160", "80",
                         '<g opacity=".7"><path fill-rule="evenodd" fill="currentColor" '
                         f'transform="translate(6 2) scale(2.4)" d="{MARK_D}"/></g>'
                         '<path d="M84 57h68" stroke="currentColor" stroke-width="5" '
                         'stroke-dasharray="5 13" stroke-linecap="butt" opacity=".45"/>',
                         "Nothing here"))

# Empty state: graduations with nothing between them.
write("art-empty.svg", svg("0 0 120 48", "120", "48",
                           '<g fill="currentColor" opacity=".7">'
                           '<path d="M8 30h4v12H8zM24 30h4v8h-4zM40 30h4v12h-4z'
                           'M56 30h4v8h-4zM72 30h4v12h-4zM88 30h4v8h-4zM104 30h4v12h-4z"/></g>'
                           '<path fill="currentColor" opacity=".35" d="M4 42h112v3H4z"/>',
                           "Nothing yet"))

print("done")
