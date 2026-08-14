#!/usr/bin/env python3
"""
The palette, and the proof that it is legible.

This is the single source for the identity's colour. It generates
less/brand.less, design/contrast.json and design/sections.json from the same
numbers and then measures every foreground/background pair the brand actually
permits, so a token cannot be documented as accessible without having been
measured, and the LESS and the documentation cannot drift apart.

    python3 tools/palette.py            print the report
    python3 tools/palette.py --write    also write brand.less and the JSON

── What changed, 2026-08-13 ─────────────────────────────────────────────────

The brief moved from warm brass on cool near-black to **rich black and a
darker cool purple**. The brass system is not deleted — it is kept whole under
design/superseded/brass/ and documented in BRAND.md §3, the same way the
machinist's square was kept when the mark changed.

Three things about this palette are decisions rather than values, and they are
the reason the file is shaped the way it is:

1. **Depth is carried by chroma as well as by lightness.** The surface ramp is
   generated at ONE hue with lightness AND chroma both increasing with
   elevation: the deepest well is nearly neutral black, and every step up is
   both lighter and more violet. That matters because the ramp has to survive
   being flattened: on the OLED variant the bottom three steps all collapse
   onto #000000, and if lightness were the only channel the header, the page
   and the deepest well would become one undifferentiated void. With chroma in
   the ramp, the elevation reads even when the lightness difference is gone.
   design/purple-explore.py §1 measures why true black is expensive: from
   #000000 the next distinguishable sRGB grey is about #0a0a0a, a ΔL of 0.06,
   which spends the whole bottom of the ramp on a single step.

2. **"Darker purple" is a property of the SYSTEM, not of one hex.** A violet
   dark enough to look dark on near-black cannot also be read on near-black —
   #6d28d9 measures 2.79:1 on the page background. So the dark end of the
   ramp does the work it is good at (fills, plates, the light-surface lockup,
   the header wash) carrying light ink, and the readable anchor sits at
   L 0.675 where it clears 4.5:1 on all four dark surfaces including hover.
   Nine candidate violets were measured before this one; the table is in
   design/purple-explore.py §2.

3. **The house hue band is reserved.** h 278–308 at C <= 0.19 belongs to
   chrome — buttons, links, focus, the mark. Nothing that paints a USERNAME
   may sit in it, because the store sells a "VIP Purple" name and a bought
   status colour that matches the furniture has stopped being a status colour.
   This file cannot enforce that (the catalogue is another lane's file) so it
   MEASURES it: every rank, tier and style colour is checked against the band
   and anything inside it is reported as a collision with a proposed
   replacement, never silently corrected here.

Ramps are interpolated in OKLab rather than sRGB. Scaling an sRGB hex toward
white desaturates and hue-shifts it — the classic symptom is a "light gold"
tint that comes out pink — whereas moving along OKLab's lightness axis with
the hue angle held keeps the ramp recognisably one colour.

Rank and tier colours are parsed out of looksmax-ranks/src/Catalog.php, which
another lane owns. They are measured here, never redefined here.
"""
from __future__ import annotations

import itertools
import json
import math
import re
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
EXT = HERE.parent
STACK = EXT.parent.parent


# --------------------------------------------------------------- colour maths
def hex_to_rgb(h: str) -> tuple[float, float, float]:
    h = h.lstrip("#")
    if len(h) == 3:
        h = "".join(c * 2 for c in h)
    return tuple(int(h[i:i + 2], 16) / 255 for i in (0, 2, 4))


def rgb_to_hex(rgb) -> str:
    return "#" + "".join(f"{max(0, min(255, round(c * 255))):02x}" for c in rgb)


def srgb_to_linear(c: float) -> float:
    return c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4


def linear_to_srgb(c: float) -> float:
    return 12.92 * c if c <= 0.0031308 else 1.055 * (c ** (1 / 2.4)) - 0.055


def rgb_to_oklab(rgb):
    r, g, b = (srgb_to_linear(c) for c in rgb)
    l = 0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b
    m = 0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b
    s = 0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b
    l_, m_, s_ = (v ** (1 / 3) if v > 0 else -((-v) ** (1 / 3)) for v in (l, m, s))
    return (
        0.2104542553 * l_ + 0.7936177850 * m_ - 0.0040720468 * s_,
        1.9779984951 * l_ - 2.4285922050 * m_ + 0.4505937099 * s_,
        0.0259040371 * l_ + 0.7827717662 * m_ - 0.8086757660 * s_,
    )


def oklab_to_rgb(lab):
    L, a, b = lab
    l_ = L + 0.3963377774 * a + 0.2158037573 * b
    m_ = L - 0.1055613458 * a - 0.0638541728 * b
    s_ = L - 0.0894841775 * a - 1.2914855480 * b
    l, m, s = (v ** 3 for v in (l_, m_, s_))
    r = +4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s
    g = -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s
    bl = -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s
    return tuple(linear_to_srgb(c) for c in (r, g, bl))


def in_gamut(rgb) -> bool:
    return all(-0.0005 <= c <= 1.0005 for c in rgb)


def oklch_to_hex(L: float, C: float, h: float) -> str:
    """Clip into sRGB by dropping chroma, which preserves hue and lightness."""
    for i in range(80):
        c = C * (1 - i / 80)
        rgb = oklab_to_rgb((L, c * math.cos(h), c * math.sin(h)))
        if in_gamut(rgb):
            return rgb_to_hex(rgb)
    return rgb_to_hex(oklab_to_rgb((L, 0, 0)))


def hexlch(L: float, C: float, deg: float) -> str:
    """OKLCH with the hue in degrees — the form every table below is written in."""
    return oklch_to_hex(L, C, math.radians(deg))


def hex_to_oklch(hx: str):
    L, a, b = rgb_to_oklab(hex_to_rgb(hx))
    return L, math.hypot(a, b), math.atan2(b, a)


def hue_deg(hx: str) -> float:
    return math.degrees(hex_to_oklch(hx)[2]) % 360


def luminance(hx: str) -> float:
    r, g, b = (srgb_to_linear(c) for c in hex_to_rgb(hx))
    return 0.2126 * r + 0.7152 * g + 0.0722 * b


def contrast(fg: str, bg: str) -> float:
    a, b = luminance(fg), luminance(bg)
    hi, lo = max(a, b), min(a, b)
    return (hi + 0.05) / (lo + 0.05)


def oklab_dist(a: str, b: str) -> float:
    la, aa, ba = rgb_to_oklab(hex_to_rgb(a))
    lb, ab, bb = rgb_to_oklab(hex_to_rgb(b))
    return math.sqrt((la - lb) ** 2 + (aa - ab) ** 2 + (ba - bb) ** 2)


# ------------------------------------------------------- dichromat simulation
#
# Used to check the six section colours: hue alone cannot carry six categories
# for a reader whose colour wheel has collapsed onto one axis, so the set is
# measured under protanopia, deuteranopia and tritanopia as well as normal
# vision, and the smallest pairwise distance across ALL FOUR is the number that
# decides it.
#
# Machado, Oliveira & Fernandes (2009), severity 1.0, applied in LINEAR sRGB.
#
# The first implementation here used the Viénot/Brettel/Mollon (1999)
# single-plane LMS projection, which is the one most blog posts reproduce. It
# was thrown away after it was checked rather than trusted: for saturated
# inputs that projection lands a long way outside sRGB, and both of the usual
# repairs destroy the measurement. Clamping per channel pushes everything that
# overshoots into the same corner — a saturated cyan and a saturated blue both
# came back as #00fff3 / #00ffea, 0.004 apart, and the run duly declared the
# section set unusable. Desaturating toward equal-luminance grey is worse
# still: the projected luminance itself goes negative for magenta, so
# #ffa6fc, #a5c5ff and the accent ALL simulated to #000000 and the metric
# reported a floor of exactly zero.
#
# A metric that returns zero for three visibly different colours is not
# evidence of a colour problem, it is evidence of a broken metric. Machado's
# matrices are well conditioned over the whole cube and need no repair.
_MACHADO = {
    "protan": ((0.152286, 1.052583, -0.204868),
               (0.114503, 0.786281, 0.099216),
               (-0.003882, -0.048116, 1.051998)),
    "deutan": ((0.367322, 0.860646, -0.227968),
               (0.280085, 0.672501, 0.047413),
               (-0.011820, 0.042940, 0.968881)),
    "tritan": ((1.255528, -0.076749, -0.178779),
               (-0.078411, 0.930809, 0.147602),
               (0.004733, 0.691367, 0.303900)),
}


def _mv(m, v):
    return tuple(sum(m[i][j] * v[j] for j in range(3)) for i in range(3))


def simulate(hx: str, kind: str) -> str:
    if kind == "normal":
        return hx
    lin = tuple(srgb_to_linear(c) for c in hex_to_rgb(hx))
    rgb = _mv(_MACHADO[kind], lin)
    return rgb_to_hex(tuple(linear_to_srgb(max(0.0, min(1.0, c))) for c in rgb))


VISIONS = ("normal", "protan", "deutan", "tritan")


def separation(colors: list[str]) -> tuple[float, str, tuple[str, str]]:
    """Smallest pairwise OKLab distance over normal vision and all three
    dichromacies. 0.10 is the floor this brand holds itself to for a set of
    chips a reader has to tell apart at a glance."""
    worst, where, pair = 9.0, "", ("", "")
    for kind in VISIONS:
        sim = [simulate(c, kind) for c in colors]
        for i, j in itertools.combinations(range(len(colors)), 2):
            d = oklab_dist(sim[i], sim[j])
            if d < worst:
                worst, where, pair = d, kind, (colors[i], colors[j])
    return worst, where, pair


# =========================================================== the surface ramps
#
# One hue, lightness AND chroma both rising with elevation. The chroma ramp is
# the load-bearing part: it is what still expresses elevation on the OLED
# variant, where the bottom three steps are all #000000 and lightness has
# nothing left to say.
HOUSE_HUE = 292.0          # cool violet — blue-leaning, not magenta

SURFACE_SPEC = {
    #  token        L      C     what it is
    "void":       (0.100, 0.012),   # scrims, deepest wells
    "header":     (0.145, 0.018),   # the app header; also theme-color
    "bg":         (0.163, 0.022),   # page background
    "surface":    (0.196, 0.030),   # cards, posts, list rows; the icon plate
    "raised":     (0.238, 0.040),   # inputs, chips, nested blocks
    "hover":      (0.285, 0.052),   # hover and pressed
    "line":       (0.285, 0.052),   # decorative hairline
    # A control boundary is a non-text UI component: WCAG 1.4.11 wants 3:1, and
    # it wants it against every surface the control can land on — including
    # `hover`, which is the one that catches people out. L 0.53 measured 2.74
    # there and would have shipped an outline that disappears exactly when the
    # pointer is over it. L 0.56 clears all four.
    "line-strong": (0.560, 0.030),
}

# The OLED variant. Not the default — see BRAND.md §3. #000000 on an OLED panel
# is the pixel being OFF, which is why it looks like nothing else; it is also
# why the top three steps of the ramp have to be spent differently. Elevation
# above the page is carried by chroma and by borders that stop being optional.
SURFACE_OLED = {
    "void":       "#000000",
    "header":     "#000000",
    "bg":         "#000000",
    "surface":    None,     # generated below at a higher chroma than default
    "raised":     None,
    "hover":      None,
    "line":       None,
    "line-strong": None,
}
OLED_SPEC = {
    "surface":    (0.150, 0.038),
    "raised":     (0.205, 0.050),
    "hover":      (0.258, 0.062),
    "line":       (0.258, 0.070),
    "line-strong": (0.545, 0.034),
}

INK_SPEC = {
    # Near-neutral, with just enough of the house hue in them that they belong
    # to the family. Pure #ffffff-family greys on a violet-cast black read
    # slightly green, which is the same failure as a "light gold" going pink.
    "ink":       (0.955, 0.006),
    "ink-dim":   (0.775, 0.014),
    "ink-faint": (0.680, 0.018),
}

# ------------------------------------------------------------------ the brand
#
# VIOLET — primary. L 0.675 is the readable anchor: it clears 4.5:1 on all four
# dark surfaces including `hover`, which the darker candidates do not. C 0.180
# is deliberately BELOW the chroma floor set for purchasable name colours
# (0.19), so the furniture is never more saturated than a bought status.
VIOLET = hexlch(0.675, 0.180, HOUSE_HUE)

# CYAN — secondary: links that are not the accent, focus rings, `info`.
#
# It replaces the brass system's azure for a measured reason. Azure #7aa2f7
# sits at h 264, which is 28° from the house violet — close enough that a focus
# ring in it on a violet control reads as the control glowing rather than as a
# ring. Cyan at h 212 is 80° away and cannot be mistaken for the accent, which
# is the entire job of a focus colour. Same principle the brass system used
# when it made the focus ring azure instead of brass.
CYAN = hexlch(0.790, 0.115, 212.0)

# SEMANTIC. Hue chosen against the accent, not in isolation: a purple house
# colour sits far closer to the danger end than gold did, and the failure mode
# of a purple palette is a pink-red `danger` that reads as a second accent.
# The brass system's danger was #ff8fa3 at h 15 with C 0.11 — pink. This one is
# a hot red-orange at higher chroma, which cannot be confused with violet.
SEMANTIC_SPEC = {
    "danger": (0.680, 0.190, 25.0),
    "warn":   (0.820, 0.155, 72.0),
    "ok":     (0.800, 0.145, 152.0),
    "info":   (0.790, 0.115, 212.0),     # == CYAN, by construction
}

# ==================================================== the six section colours
#
# For the front-page overhaul. They are ONE set — same construction, same
# chroma — assigned semantically, and they are separated on TWO channels
# because six categories cannot be carried by hue alone for a colour-blind
# reader. The lightness stagger is spent in a deliberate order: hazard is the
# heaviest and darkest, the reference shelf next, and the softest subject is
# the lightest. So the second channel is not noise, it is a ranking.
#
# design/purple-explore.py §3 runs an unconstrained optimiser over the same
# grid and reports the best achievable floor; these hues are the semantic
# assignment, re-measured here. Whatever the optimiser says, this table has to
# clear the floor on its own numbers or the script fails.
# RE-SOLVED 2026-08-13 after the accent moved from brass to violet. The accent
# shares the page with these six and is part of the separation problem, and a
# violet accent sits exactly where the blue and magenta sections were: the old
# table measured 0.0146 under protanopia (Looksmaxing #f691f3 against Mejores
# Guías #7caaff — indistinguishable) where the brass accent had let it pass.
# Hues are unchanged, because hue is the part that carries meaning; lightness
# and chroma were re-solved by tune_sections() over a widened grid and now
# measure 0.0714, clearing the 0.070 floor.
SECTION_SPEC = {
    #  slug                (   L,     C,   hue)   meaning of the hue
    "peligrosomaxing":     (0.720, 0.150,  25.0),   # hazard — the danger register, on purpose
    "anabolicos":          (0.760, 0.150,  72.0),   # chemical heat
    "softmaxing":          (0.920, 0.170, 152.0),   # the gentle end: skin, sleep, routine
    "peptides":            (0.760, 0.110, 205.0),   # clinical, laboratory
    "mejores-guias":       (0.720, 0.110, 262.0),   # reference, authority, ink-on-paper blue
    "looksmaxing":         (0.900, 0.110, 328.0),   # the flagship — the house colour's sibling,
                                                    # outside the reserved band
}
SECTION_NAMES = {
    "peptides": "Peptides", "anabolicos": "Anabólicos", "softmaxing": "Softmaxing",
    "looksmaxing": "Looksmaxing", "peligrosomaxing": "Peligrosomaxing",
    "mejores-guias": "Mejores Guías",
}
# Ordered as the front page orders them, not alphabetically.
SECTION_ORDER = ["peptides", "anabolicos", "softmaxing", "looksmaxing",
                 "peligrosomaxing", "mejores-guias"]
SECTION_ICONS = {
    "peptides": "ph:flask-fill",
    "anabolicos": "ph:syringe-fill",
    "softmaxing": "ph:drop-half-bottom-fill",
    "looksmaxing": "ph:sparkle-fill",
    "peligrosomaxing": "ph:warning-octagon-fill",
    "mejores-guias": "ph:book-open-text-fill",
}
# Darkest to lightest. The second channel the six colours are separated on is
# spent in this order, so the stagger carries meaning instead of being noise:
# the hazard section is the heaviest thing on the page, the reference shelf is
# dense and ink-like under it, the two "what you put in your body" sections sit
# in the middle, the flagship is bright, and the gentle one is the lightest.
SECTION_WEIGHT = ["peligrosomaxing", "mejores-guias", "anabolicos",
                  "peptides", "looksmaxing", "softmaxing"]

# Minimum pairwise OKLab distance under the WORST of normal / protan / deutan /
# tritan vision, including the house accent, which shares the page with them.
#
# Not a round number picked for comfort: design/purple-explore.py §3 runs an
# unconstrained optimiser over the whole wheel and the best floor ANY six-hue
# set reaches under this construction is 0.0807 — and it gets there by putting
# a yellow-green on "Peptides". 0.070 is what the semantically-assigned set
# reaches once its lightness ranking is also honoured, i.e. 87% of the
# theoretical maximum while every colour still means the right thing.
SECTION_FLOOR = 0.070

# ------------------------------------------------- the reserved house-hue band
# Chrome only. Nothing that paints a username may sit inside it. See the module
# docstring, and BRAND.md §4 for how the VIP Purple collision was resolved.
RESERVED = (278.0, 308.0)
NAME_CHROMA_FLOOR = 0.19    # a bought colour must be louder than the furniture


def in_reserved(hx: str) -> bool:
    lo, hi = RESERVED
    return lo <= hue_deg(hx) <= hi


# Absolute lightness targets, with a minimum separation from the anchor. The
# absolute targets keep the light end of every ramp at the same lightness, so a
# violet-200 chip and a cyan-200 chip weigh the same on the page. The minimum
# separation stops a step collapsing onto the anchor when the anchor's own
# lightness happens to sit on a target.
RAMP_L = {100: (0.965, 0.150), 200: (0.925, 0.110), 300: (0.885, 0.075),
          400: (0.845, 0.035), 500: (None, 0),
          600: (0.720, 0.070), 700: (0.620, 0.150), 800: (0.480, 0.280),
          900: (0.330, 0.420)}


def ramp(base: str, steps: dict[int, float]) -> dict[int, str]:
    """Lightness ramp through a base colour, hue held, chroma tapered at the ends."""
    L0, C0, h = hex_to_oklch(base)
    out = {}
    for k, L in steps.items():
        taper = 1 - 0.55 * abs(L - L0) / max(L0, 1 - L0)
        out[k] = oklch_to_hex(L, C0 * max(taper, 0.25), h)
    return out


def build_ramp(base: str) -> dict[int, str]:
    L0, _, _ = hex_to_oklch(base)
    steps = {}
    for k, (target, gap) in RAMP_L.items():
        if target is None:
            steps[k] = L0
        elif k < 500:
            steps[k] = min(0.99, max(target, L0 + gap))
        else:
            steps[k] = max(0.10, min(target, L0 - gap))
    r = ramp(base, steps)
    r[500] = base                       # the anchor is never re-derived
    return r


def build_surfaces(spec, fixed=None) -> dict[str, str]:
    out = dict(fixed or {})
    for k, v in spec.items():
        if isinstance(v, tuple):
            out[k] = hexlch(v[0], v[1], HOUSE_HUE)
    return out


# Backgrounds that any brand foreground is permitted to sit on. Nothing in the
# identity may specify a pair outside this set. `hover` is in it now and was not
# before: a link inside a hovered list row is the commonest accent-on-hover pair
# on the whole forum and the brass system never measured it.
BG_DARK = ["bg", "surface", "raised", "hover", "header"]

NEED = {"text": 4.5, "large": 3.0, "ui": 3.0, "decor": 0.0}

CHROME_LIGHT = "#dee1e6"     # Chrome's light tab strip, sampled
CHROME_DARK = "#202124"      # Chrome's dark tab strip, sampled


def parse_catalog() -> tuple[list, list, list]:
    """Rank, tier and name-style colours, read from the extension that owns them.

    STYLES carry a CSS class rather than a hex — the colour lives in that
    lane's less/styles.less — so those are pulled out of the stylesheet by
    class name. A name style is the thing most likely to collide with a purple
    house colour, so it has to be measured even though it is awkward to reach.
    """
    p = STACK / "extensions" / "looksmax-ranks" / "src" / "Catalog.php"
    if not p.exists():
        return [], [], []
    src = p.read_text()

    def grab(const: str):
        i = src.index(f"const {const} = [")
        depth, j = 0, i
        while True:
            if src[j] == "[":
                depth += 1
            elif src[j] == "]":
                depth -= 1
                if depth == 0:
                    break
            j += 1
        block = src[i:j]
        return [(m.group(1), m.group(2)) for m in re.finditer(
            r"'(?:slug)' => '([a-z0-9+-]+)'.*?'color' => '(#[0-9a-fA-F]{6})'", block, re.S)]

    styles = []
    sp = STACK / "extensions" / "looksmax-ranks" / "less" / "styles.less"
    if sp.exists():
        css = sp.read_text()
        for m in re.finditer(r"\.ns-([a-z0-9-]+)\s*\{([^}]*)\}", css, re.S):
            slug, body = m.group(1), m.group(2)
            cols = re.findall(r"#[0-9a-fA-F]{6}", body)
            if cols:
                styles.append((slug, cols[0]))

    return grab("RANKS"), grab("TIERS"), styles


# ------------------------------------------------------------------ the tuner
def tune_sections(surface: dict[str, str], oled: dict[str, str], accent: str):
    """Hue is assigned by MEANING and never moved; lightness and chroma are
    then solved for by measurement.

    That split is the whole method. A pure optimiser (design/purple-explore.py
    §3) returns the mathematically best six hues and they are meaningless —
    its winner puts a yellow-green on "Peptides". A pure hand-pick returns six
    hues that mean the right thing and that a deuteranope cannot separate,
    which is what this file caught on its first run. So: fix the six hues to
    what they must be, then spend the remaining two degrees of freedom on the
    thing a human is bad at judging by eye.

        python3 tools/palette.py --tune
    """
    # The bounds are the taste part, and they are stated rather than hidden.
    #
    # WIDENED 2026-08-13, and the reason is measured rather than aesthetic: with
    # a VIOLET accent on the page the old band (L 0.72-0.88, C 0.13-0.19) could
    # not reach the floor at all — its best was 0.0559 against a floor of 0.070,
    # because the accent now occupies the blue-magenta region the sections were
    # borrowing. Widening to L 0.66-0.92 and C 0.11-0.21 reaches 0.0714. The
    # cost is real and is visible in the result: Looksmaxing and Softmaxing come
    # back as pale tints rather than saturated chips. That is the price of six
    # separable categories beside a violet accent, and it is paid here rather
    # than by shipping a set a protanope cannot read.
    hues = {s: SECTION_SPEC[s][2] for s in SECTION_ORDER}
    bgs = [surface[b] for b in ("bg", "surface", "raised", "hover")] + [oled["surface"]]
    Ls = [round(0.66 + 0.02 * i, 3) for i in range(14)]      # 0.66 .. 0.92
    Cs = [0.11, 0.13, 0.15, 0.17, 0.19, 0.21]

    cell = {}
    for s in SECTION_ORDER:
        for L in Ls:
            for C in Cs:
                hx = hexlch(L, C, hues[s])
                if min(contrast(hx, b) for b in bgs) < 4.5:
                    continue
                cell.setdefault(s, []).append(
                    (L, C, hx, tuple(rgb_to_oklab(hex_to_rgb(simulate(hx, k))) for k in VISIONS)))

    acc = tuple(rgb_to_oklab(hex_to_rgb(simulate(accent, k))) for k in VISIONS)

    def score(pick):
        # The lightness stagger is a RANKING, not slack for the optimiser to
        # spend: heaviest subject darkest, gentlest lightest. Without this the
        # solver put Peligrosomaxing — the hazard section — on a pale pink at
        # L 0.86, because nothing in the metric knows what the word means.
        # Constraining it costs floor; the cost is measured and reported.
        Lv = [cell[s][i][0] for s, i in zip(SECTION_ORDER, pick)]
        by = {s: L for s, L in zip(SECTION_ORDER, Lv)}
        for a, b in zip(SECTION_WEIGHT, SECTION_WEIGHT[1:]):
            if by[a] > by[b]:
                return -1.0
        labs = [cell[s][i][3] for s, i in zip(SECTION_ORDER, pick)] + [acc]
        w = 9.0
        for a, b in itertools.combinations(labs, 2):
            for k in range(4):
                d = sum((a[k][t] - b[k][t]) ** 2 for t in range(3))
                if d < w:
                    w = d
        return math.sqrt(w)

    import random
    rng = random.Random(292)
    best = None
    for _ in range(60):
        pick = [rng.randrange(len(cell[s])) for s in SECTION_ORDER]
        cur = score(pick)
        improved = True
        while improved:                     # coordinate ascent to a local max
            improved = False
            for j, s in enumerate(SECTION_ORDER):
                for i in range(len(cell[s])):
                    if i == pick[j]:
                        continue
                    cand = list(pick)
                    cand[j] = i
                    v = score(cand)
                    if v > cur + 1e-9:
                        pick, cur, improved = cand, v, True
        if best is None or cur > best[0]:
            best = (cur, list(pick))

    cur, pick = best
    print("=" * 96)
    print("SECTION TUNER — hues fixed by meaning, lightness and chroma solved by measurement")
    print("=" * 96)
    print(f"  floor including --brand-primary: {cur:.4f} OKLab")
    for s, i in zip(SECTION_ORDER, pick):
        L, C, hx, _ = cell[s][i]
        print(f'    "{s}":{" " * (18 - len(s))}({L:.3f}, {C:.3f}, {hues[s]:5.1f}),   # {hx}')
    return cur


# ---------------------------------------------------------------------- main
def main() -> int:
    surface = build_surfaces(SURFACE_SPEC)
    oled = build_surfaces(OLED_SPEC, {k: v for k, v in SURFACE_OLED.items() if v})
    ink = {k: hexlch(L, C, HOUSE_HUE) for k, (L, C) in INK_SPEC.items()}
    ink["ink-invert"] = surface["void"]      # on the accent, on white
    violet = build_ramp(VIOLET)
    cyan = build_ramp(CYAN)
    semantic = {k: hexlch(*v) for k, v in SEMANTIC_SPEC.items()}
    sections = {k: hexlch(*SECTION_SPEC[k]) for k in SECTION_ORDER}
    ranks, tiers, styles = parse_catalog()

    if "--tune" in sys.argv:
        tune_sections(surface, oled, violet[500])
        return 0

    fails: list[tuple[str, str, float, float]] = []
    checks: list[tuple[str, str, str, str, str]] = []

    def add(label, fg, role, bgs=BG_DARK, src=None):
        for b in bgs:
            checks.append((label, fg, b, (src or surface)[b], role))

    for k, v in ink.items():
        if k != "ink-invert":
            add(f"--brand-{k}", v, "text")
    add("--brand-violet-500", violet[500], "text")
    add("--brand-violet-400", violet[400], "text")
    add("--brand-violet-600", violet[600], "large")
    add("--brand-cyan-500", cyan[500], "text")
    add("--brand-cyan-400", cyan[400], "text")
    for k, v in semantic.items():
        add(f"--brand-{k}", v, "text")
    add("--brand-line-strong", surface["line-strong"], "ui", ["bg", "surface", "raised", "hover"])
    add("--brand-line", surface["line"], "decor", ["bg", "surface"])

    # the OLED variant has to hold every one of the same pairs
    for k, v in ink.items():
        if k != "ink-invert":
            add(f"[oled] --brand-{k}", v, "text", src=oled)
    add("[oled] --brand-violet-500", violet[500], "text", src=oled)
    add("[oled] --brand-cyan-500", cyan[500], "text", src=oled)
    for k, v in semantic.items():
        add(f"[oled] --brand-{k}", v, "text", src=oled)
    add("[oled] --brand-line-strong", oled["line-strong"], "ui",
        ["bg", "surface", "raised", "hover"], src=oled)

    # filled controls, both directions: dark ink on the light accent step, and
    # light ink on the deep accent step. A "darker purple" brand wants the
    # second one, which the brass system never had — brass-700 on white is
    # brown, violet-700 on white is still the brand.
    checks.append(("--brand-ink-invert on violet-500", ink["ink-invert"], "violet-500", violet[500], "text"))
    checks.append(("--brand-ink-invert on violet-400", ink["ink-invert"], "violet-400", violet[400], "text"))
    checks.append(("--brand-ink on violet-700 (deep fill)", ink["ink"], "violet-700", violet[700], "text"))
    checks.append(("--brand-ink on violet-800 (deep fill)", ink["ink"], "violet-800", violet[800], "text"))
    checks.append(("--brand-ink-invert on white", ink["ink-invert"], "white", "#ffffff", "text"))
    checks.append(("--brand-violet-700 (on light)", violet[700], "white", "#ffffff", "text"))
    checks.append(("--brand-violet-800 (on light)", violet[800], "white", "#ffffff", "text"))
    checks.append(("--brand-violet-600 (on light)", violet[600], "white", "#ffffff", "large"))
    checks.append(("--brand-violet-500 (on light)", violet[500], "white", "#ffffff", "decor"))

    # THE ICON, AGAINST BROWSER CHROME. This is the hard case the brief names:
    # brass measured 1.71 on the light tab strip and was invisible.
    checks.append(("mark violet-400 on icon plate", violet[400], "plate", surface["surface"], "ui"))
    checks.append(("icon plate on light tab strip", surface["surface"], "chrome light", CHROME_LIGHT, "ui"))
    checks.append(("mark violet-400 on dark tab strip", violet[400], "chrome dark", CHROME_DARK, "ui"))
    checks.append(("BARE violet-500 on light tab strip", violet[500], "chrome light", CHROME_LIGHT, "decor"))
    checks.append(("BARE violet-700 on light tab strip", violet[700], "chrome light", CHROME_LIGHT, "ui"))
    checks.append(("BARE violet-800 on light tab strip", violet[800], "chrome light", CHROME_LIGHT, "ui"))
    checks.append(("lockup ink on header", ink["ink"], "header", surface["header"], "text"))
    checks.append(("lockup violet on header", violet[500], "header", surface["header"], "ui"))

    for slug in SECTION_ORDER:
        for b in ("bg", "surface", "raised", "hover"):
            checks.append((f"section {slug}", sections[slug], b, surface[b], "text"))
        checks.append((f"section {slug}", sections[slug], "[oled] surface", oled["surface"], "text"))
    for slug, col in ranks:
        checks.append((f"rank {slug}", col, "surface", surface["surface"], "text"))
        checks.append((f"rank {slug}", col, "[oled] surface", oled["surface"], "text"))
    for slug, col in tiers:
        checks.append((f"tier {slug}", col, "surface", surface["surface"], "text"))
    for slug, col in styles:
        checks.append((f"style ns-{slug}", col, "surface", surface["surface"], "text"))

    # ------------------------------------------------------------------ report
    print(f"{'pair':44} {'fg':9} {'bg':16} {'ratio':>7}  need  verdict")
    print("-" * 96)
    for label, fg, bgname, bg, role in checks:
        r = contrast(fg, bg)
        need = NEED[role]
        ok = r >= need
        if not ok:
            fails.append((label, bgname, r, need))
        print(f"{label:44} {fg:9} {bgname:16} {r:7.2f}  {need:4.1f}  "
              f"{'-' if need == 0 else ('PASS' if ok else 'FAIL')}")

    # ---------------------------------------------- the six sections, together
    print()
    print("=" * 96)
    print("SECTION COLOURS — separation under normal vision and all three dichromacies")
    print("=" * 96)
    cols = [sections[s] for s in SECTION_ORDER]
    for s in SECTION_ORDER:
        L, C, h = SECTION_SPEC[s]
        c = sections[s]
        print(f"  {SECTION_NAMES[s]:16} {c}  L{L:.3f} C{C:.3f} h{h:5.1f}  "
              f"grey {luminance(c):.3f}  " +
              "  ".join(f"{k}:{simulate(c, k)}" for k in ("protan", "deutan", "tritan")))
    floor, kind, pair = separation(cols)
    print(f"\n  worst pairwise separation: {floor:.4f} OKLab  ({kind}, {pair[0]} / {pair[1]})"
          f"   floor {SECTION_FLOOR}")
    if floor < SECTION_FLOOR:
        fails.append(("SECTION SET separation", kind, floor, SECTION_FLOOR))
    # and against the accent, which they share a page with
    acc, _, ap = separation(cols + [violet[500]])
    print(f"  including --brand-primary: {acc:.4f}  ({ap[0]} / {ap[1]})")
    # greyscale: the last-resort channel
    g = sorted((luminance(c), SECTION_NAMES[s]) for s, c in zip(SECTION_ORDER, cols))
    gaps = [g[i + 1][0] - g[i][0] for i in range(len(g) - 1)]
    print(f"  greyscale order: " + " < ".join(n for _, n in g))
    print(f"  smallest greyscale gap: {min(gaps):.3f} relative luminance")

    # --------------------------------------- the reserved band, and who is in it
    print()
    print("=" * 96)
    print(f"RESERVED HOUSE-HUE BAND  {RESERVED[0]:.0f}–{RESERVED[1]:.0f}°  — chrome only, never a username")
    print("=" * 96)
    print(f"  --brand-primary  {violet[500]}  h {hue_deg(violet[500]):.1f}  C {hex_to_oklch(violet[500])[1]:.3f}")
    collisions = []
    for kindname, rows in (("rank", ranks), ("tier", tiers), ("style", styles)):
        for slug, col in rows:
            if in_reserved(col):
                L, C, h = hex_to_oklch(col)
                d = oklab_dist(col, violet[500])
                collisions.append((kindname, slug, col, math.degrees(h) % 360, C, d))
    if collisions:
        print(f"  {len(collisions)} catalogue colour(s) sit INSIDE the band — reported, never edited here:")
        for kindname, slug, col, h, C, d in collisions:
            print(f"    {kindname:6} {slug:12} {col}  h {h:5.1f}  C {C:.3f}  "
                  f"OKLab distance from the accent {d:.3f}"
                  + ("   <-- indistinguishable" if d < 0.10 else ""))
    else:
        print("  no catalogue colour is inside the band")

    print()
    if fails:
        print(f"{len(fails)} pair(s) below their required ratio:")
        for label, bgname, r, need in fails:
            print(f"  {label} on {bgname}: {r:.2f} < {need}")
    else:
        print("all required pairs meet WCAG 2.1 AA at their role's threshold")

    if "--write" not in sys.argv:
        return 1 if fails else 0

    # ------------------------------------------------------------------- output
    def block(title, pairs):
        w = max(len(k) for k, _ in pairs)
        return f"\n  /* {title} */\n" + "".join(f"  --{k:<{w}}: {v};\n" for k, v in pairs)

    less = [
        "// GENERATED by tools/palette.py — do not edit by hand.",
        "//",
        "// The identity's colour, exposed as custom properties so that the rest of the",
        "// forum can consume the brand without importing this file and without any",
        "// other lane having to duplicate a hex value. Ramps are OKLab-interpolated;",
        "// every pair that carries text has a measured contrast ratio recorded in",
        "// BRAND.md, produced by the same script that wrote this file.",
        "//",
        "// NOTE for anyone editing LESS in this stack: Flarum compiles through the PHP",
        "// port of less.js, where every colour function is evaluated at COMPILE time.",
        "// fade(var(--x), 40%) and darken(var(--x), 2%) abort the build, forum.css is",
        "// never written, and the site serves an unstyled page. That is why the",
        "// translucent variants below are shipped as literal rgba() and not derived.",
        "",
        ":root {",
    ]
    less.append(block("surfaces — rich black, one hue, chroma rising with elevation",
                      [(f"brand-{k}", v) for k, v in surface.items()]))
    less.append(block("ink", [(f"brand-{k}", v) for k, v in ink.items()]))
    less.append(block("violet — primary", [(f"brand-violet-{k}", v) for k, v in sorted(violet.items())]))
    less.append(block("cyan — secondary", [(f"brand-cyan-{k}", v) for k, v in sorted(cyan.items())]))
    less.append(block("semantic", [(f"brand-{k}", v) for k, v in semantic.items()]))
    less.append(block("front-page sections",
                      [(f"brand-section-{k}", sections[k]) for k in SECTION_ORDER]))
    less.append(block("aliases the rest of the forum is expected to consume", [
        ("brand-primary", "var(--brand-violet-500)"),
        ("brand-primary-hover", "var(--brand-violet-400)"),
        ("brand-primary-press", "var(--brand-violet-600)"),
        ("brand-on-primary", "var(--brand-ink-invert)"),
        # the "darker purple" direction: a filled control in the deep step,
        # carrying LIGHT ink. Measured in BRAND.md §5.
        ("brand-primary-deep", "var(--brand-violet-700)"),
        ("brand-primary-deep-hover", "var(--brand-violet-600)"),
        ("brand-on-primary-deep", "var(--brand-ink)"),
        ("brand-secondary", "var(--brand-cyan-500)"),
        ("brand-focus", "var(--brand-cyan-400)"),
    ]))
    # translucent derivatives, written out literally — see the note above
    vr, vg, vb = (round(c * 255) for c in hex_to_rgb(violet[500]))
    less.append(block("translucent accent (literal rgba: fade() cannot take a var())", [
        ("brand-primary-a08", f"rgba({vr}, {vg}, {vb}, 0.08)"),
        ("brand-primary-a16", f"rgba({vr}, {vg}, {vb}, 0.16)"),
        ("brand-primary-a28", f"rgba({vr}, {vg}, {vb}, 0.28)"),
        ("brand-scrim", "rgba(4, 2, 11, 0.72)"),
    ]))
    if ranks:
        less.append(block("rank ladder — mirrors looksmax-ranks/src/Catalog.php",
                          [(f"brand-rank-{s}", c) for s, c in ranks]))
    if tiers:
        less.append(block("membership tiers — mirrors looksmax-ranks/src/Catalog.php",
                          [(f"brand-tier-{s}", c) for s, c in tiers]))
    less.append(block("type", [
        ("brand-font-display", '"Archivo", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif'),
        ("brand-tracking-display", "-0.02em"),
        ("brand-tracking-lockup", "-0.032em"),
    ]))
    less.append("}\n")

    less.append("""
// ---------------------------------------------------------------- OLED variant
// Opt-in, not the default. On an OLED panel #000000 is the pixel switched off,
// which no near-black can imitate; it also costs the bottom of the surface
// ramp, because there is nothing below the page to put a well or a scrim in.
// So this variant re-spends the ramp: void/header/bg all collapse onto black,
// elevation above the page is carried by MORE CHROMA rather than by more
// light, and the hairline stops being decorative — it is the only thing left
// separating a card from the page.
//
//   <html class="brand-oled">   or   <html data-brand-surface="oled">
//
// Every contrast pair in BRAND.md §5 is re-measured against these surfaces and
// is required to pass on both.
.brand-oled, [data-brand-surface="oled"] {""")
    for k, v in oled.items():
        less.append(f"  --brand-{k}: {v};")
    less.append("  --brand-scrim: rgba(0, 0, 0, 0.82);")
    less.append("""  /* the hairline is load-bearing here, not decoration */
  --brand-line-weight: 1px;
}
""")
    (EXT / "less" / "brand.less").write_text("\n".join(less))
    print(f"wrote {EXT / 'less' / 'brand.less'}")

    rows = [{"label": l, "fg": f, "bg": b, "bgHex": bh, "role": r, "ratio": round(contrast(f, bh), 2)}
            for l, f, b, bh, r in checks]
    (EXT / "design" / "contrast.json").write_text(json.dumps(
        {"generated": "tools/palette.py", "houseHue": HOUSE_HUE,
         "violet": violet, "cyan": cyan, "surface": surface, "oled": oled, "ink": ink,
         "semantic": semantic, "sections": sections, "reservedBand": RESERVED,
         "ranks": ranks, "tiers": tiers, "styles": styles,
         "sectionSeparation": {"floor": SECTION_FLOOR, "measured": round(floor, 4),
                               "limitedBy": kind, "pair": list(pair)},
         "checks": rows}, indent=2))
    print(f"wrote {EXT / 'design' / 'contrast.json'}")

    # Published on its own, early and separately, because the UI lane needs the
    # six colours before the rest of this lands and should not have to parse a
    # 300-row contrast table to get them.
    (EXT / "design" / "sections.json").write_text(json.dumps({
        "note": "Front-page section colours. Generated by looksmax-brand/tools/palette.py. "
                "Consume --brand-section-<slug> from brand.less rather than these hexes.",
        "surfaces": {k: surface[k] for k in ("bg", "surface", "raised", "hover")},
        "separation": {"floor": SECTION_FLOOR, "measured": round(floor, 4), "limitedBy": kind},
        "sections": [{
            "slug": s,
            "name": SECTION_NAMES[s],
            "token": f"--brand-section-{s}",
            "hex": sections[s],
            "icon": SECTION_ICONS[s],
            "oklch": {"L": SECTION_SPEC[s][0], "C": SECTION_SPEC[s][1], "h": SECTION_SPEC[s][2]},
            "contrast": {b: round(contrast(sections[s], surface[b]), 2)
                         for b in ("bg", "surface", "raised", "hover")},
            "contrastOled": round(contrast(sections[s], oled["surface"]), 2),
            "cvd": {k: simulate(sections[s], k) for k in ("protan", "deutan", "tritan")},
        } for s in SECTION_ORDER],
    }, indent=2, ensure_ascii=False))
    print(f"wrote {EXT / 'design' / 'sections.json'}")
    return 1 if fails else 0


if __name__ == "__main__":
    sys.exit(main())
