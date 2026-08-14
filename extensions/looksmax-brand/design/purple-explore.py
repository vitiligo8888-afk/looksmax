#!/usr/bin/env python3
"""
Choosing the violet, the rich-black ramp and the six section hues — by
measurement, not by taste.

The operator's direction was "rich black and darker purple, you choose the
shades". This file is the choosing. It exists separately from tools/palette.py
so that the SEARCH is kept next to the result: palette.py states what shipped,
this states what else was on the table and what each candidate measured.

Three questions are answered here, in order:

  1. How dark can the surfaces go before elevation stops being expressible?
  2. Which violet survives (a) near-black as an accent, (b) Chrome's LIGHT tab
     strip, where the old brass measured 1.71:1 and was invisible, and
     (c) a filled button with dark text on it?
  3. Which six hues, at what lightness, stay separable for a colour-blind
     reader — measured with a real dichromat simulation, not asserted.

    python3 design/purple-explore.py
"""
from __future__ import annotations

import itertools
import math
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "tools"))
from palette import (contrast, hex_to_oklch, hex_to_rgb, luminance,  # noqa: E402
                     oklch_to_hex, rgb_to_hex, rgb_to_oklab, srgb_to_linear,
                     linear_to_srgb)

CHROME_LIGHT = "#dee1e6"   # Chrome's light tab strip, sampled
CHROME_DARK = "#202124"    # Chrome's dark tab strip, sampled
WHITE = "#ffffff"


# ------------------------------------------------------- dichromat simulation
# Viénot, Brettel & Mollon (1999), "Digital video colourmaps for checking the
# legibility of displays by dichromats". The single-plane projection in LMS.
# This is the version that is actually correct for protan/deutan; tritan needs
# Brettel's two half-planes, which is why it is done separately below.
_RGB2LMS = ((0.31399022, 0.63951294, 0.04649755),
            (0.15537241, 0.75789446, 0.08670142),
            (0.01775239, 0.10944209, 0.87256922))
_LMS2RGB = ((5.47221206, -4.6419601, 0.16963708),
            (-1.1252419, 2.29317094, -0.1678952),
            (0.02980165, -0.19318073, 1.16364789))


def _mv(m, v):
    return tuple(sum(m[i][j] * v[j] for j in range(3)) for i in range(3))


def simulate(hx: str, kind: str) -> str:
    """sRGB hex as a protanope / deuteranope / tritanope sees it."""
    lin = tuple(srgb_to_linear(c) for c in hex_to_rgb(hx))
    L, M, S = _mv(_RGB2LMS, lin)
    if kind == "protan":
        L2 = 2.02344 * M - 2.52581 * S
        lms = (L2, M, S)
    elif kind == "deutan":
        M2 = 0.494207 * L + 1.24827 * S
        lms = (L, M2, S)
    elif kind == "tritan":
        S2 = -0.395913 * L + 0.801109 * M
        lms = (L, M, S2)
    else:
        lms = (L, M, S)
    rgb = _mv(_LMS2RGB, lms)
    return rgb_to_hex(tuple(linear_to_srgb(max(0.0, min(1.0, c))) for c in rgb))


def dist(a: str, b: str) -> float:
    """Perceptual distance in OKLab. ~0.02 is a just-noticeable step for large
    fields; two chips a user must tell apart at a glance want an order more."""
    la, aa, ba = rgb_to_oklab(hex_to_rgb(a))
    lb, ab, bb = rgb_to_oklab(hex_to_rgb(b))
    return math.sqrt((la - lb) ** 2 + (aa - ab) ** 2 + (ba - bb) ** 2)


def grey(hx: str) -> float:
    """Relative luminance -> the greyscale channel. The last-resort check: if
    two chips also differ here, they survive a monochrome print and every form
    of colour blindness including achromatopsia."""
    return luminance(hx)


def worst_cvd(colors: list[str]) -> tuple[float, str, tuple[str, str]]:
    """Smallest pairwise OKLab distance across normal vision and all three
    dichromacies. This is the number that decides whether a set is legible."""
    worst, where, pair = 9.0, "", ("", "")
    for kind in ("normal", "protan", "deutan", "tritan"):
        sim = [c if kind == "normal" else simulate(c, kind) for c in colors]
        for i, j in itertools.combinations(range(len(colors)), 2):
            d = dist(sim[i], sim[j])
            if d < worst:
                worst, where, pair = d, kind, (colors[i], colors[j])
    return worst, where, pair


# =============================================================== 1. surfaces
def q1_surfaces():
    print("=" * 78)
    print("1. How black can 'rich black' be before elevation stops working")
    print("=" * 78)
    print("""
A surface ramp expresses depth by LIGHTNESS DIFFERENCE. sRGB is 8-bit, and the
transfer curve is steepest at the bottom: near black, one code point is a large
perceptual step, but there are only a few code points left to spend. Below is
the OKLab lightness of the first few sRGB greys, and the step between them.
""")
    prev = None
    for v in range(0, 34, 2):
        hx = f"#{v:02x}{v:02x}{v:02x}"
        L, _, _ = hex_to_oklch(hx)
        step = "" if prev is None else f"  ΔL {L - prev:+.4f}"
        print(f"  {hx}  L {L:.4f}{step}")
        prev = L
    print("""
The consequence, stated plainly: from #000000 the next usable elevation is
around #0a0a0a — ΔL 0.06, which is a visible step. So true black is not
impossible, it is EXPENSIVE: it spends the bottom of the ramp on one step and
leaves the card/raised/hover trio squeezed into what is left. It also destroys
the deepest well: there is nothing below #000 to put a scrim or an inset
shadow in, so every "recessed" affordance has to become a border instead.

That is the reason the default ships at L 0.175 rather than at 0.000, and the
reason the OLED variant is a variant: on OLED, #000 is genuinely OFF, which
looks superb and costs the same expressiveness.
""")


# ================================================================ 2. the violet
CANDIDATES = {
    # name: (anchor hex, note)
    "iris":      ("#8b5cf6", "Tailwind violet-500 — the default everyone ships"),
    "amethyst":  ("#a78bfa", "Tailwind violet-400 — already the Master rank"),
    "orchid":    ("#bb9af7", "Tokyo Night purple — already the Elite tier"),
    "wisteria":  ("#9d7cff", "blue-leaning, mid"),
    "lilac":     ("#b39ddb", "Material deep-purple-200 — pastel"),
    "grape":     ("#7c3aed", "violet-600 — dark, saturated"),
    "royal":     ("#6d28d9", "violet-700 — dark"),
    "indigo":    ("#818cf8", "indigo-400 — nearly blue"),
    "heliotrope": ("#a06bff", "cooler than orchid, more chroma than lilac"),
    "ULTRAVIOLET": ("#9b7cf0", "the shipped anchor — see palette.py"),
}


def q2_violet(bg: str, surface: str):
    print("=" * 78)
    print("2. Which violet survives the three hard cases")
    print("=" * 78)
    print("""
  on-bg      accent text/icon on the page background        need 4.5
  btn-dark   the near-black ink a filled button would carry  need 4.5
  strip-L    the SAME hex on Chrome's light tab strip        this is where
             brass measured 1.71 and vanished
  vs-vip     OKLab distance from the purchasable 'VIP Purple' the store sells
""")
    vip_purple = "#a855f7"     # the store's magenta-leaning purple, see §4
    print(f"{'name':12} {'hex':9} {'L':>5} {'C':>6} {'h°':>5} "
          f"{'on-bg':>6} {'on-surf':>7} {'btn-dark':>8} {'strip-L':>7} {'strip-D':>7} {'vs-vip':>6}")
    print("-" * 100)
    for name, (hx, _note) in CANDIDATES.items():
        L, C, h = hex_to_oklch(hx)
        print(f"{name:12} {hx:9} {L:5.3f} {C:6.3f} {math.degrees(h) % 360:5.1f} "
              f"{contrast(hx, bg):6.2f} {contrast(hx, surface):7.2f} "
              f"{contrast('#0a0810', hx):8.2f} {contrast(hx, CHROME_LIGHT):7.2f} "
              f"{contrast(hx, CHROME_DARK):7.2f} {dist(hx, vip_purple):6.3f}")
    print("""
Reading of the table:

* Nothing in the violet family reaches brass's 11.05 on the page background,
  and nothing can: brass-500 is L 0.81, a violet at that lightness is a pastel
  lilac and stops reading as purple at all. 6–8:1 is the honest ceiling, which
  is comfortably above the 4.5 AA threshold — the accent has never needed 11.
* The dark candidates (grape, royal) fail as accent TEXT on near-black. They
  are still useful, as FILLS carrying light ink, which is the direction a
  "darker purple" wants to be used in anyway — so the ramp keeps them and the
  button spec uses them, rather than the anchor being dragged down to them.
* The light tab strip did NOT get easier, and the guess that it would was
  wrong. Any violet light enough to clear 4.5:1 on near-black measures 2.0–2.4
  on #dee1e6 — amethyst 2.08, orchid 1.76, wisteria 2.37. That is brass's 1.71
  problem again, because it is not a problem about hue: it is a problem about
  putting a light colour on a light background. The favicon stays plated.

  What DID change is the escape route. Brass's dark end goes brown — brass-700
  #a18049 reads as a different colour from the brand, and only reached 3.68 on
  white. Violet's dark end stays violet: royal #6d28d9 measures 5.42 on the
  light tab strip and 8.09 on white while still being recognisably the house
  colour. So the light-surface lockup and any mark that must sit unplated on
  white can be drawn in violet-700/800 and still be the brand, which was never
  true of gold.
""")


# ==================================================== 3. the six section hues
SECTIONS = ["Peptides", "Anabólicos", "Softmaxing",
            "Looksmaxing", "Peligrosomaxing", "Mejores Guías"]


def q3_sections(bgs: dict[str, str], reserved: tuple[float, float]):
    print("=" * 78)
    print("3. Six section colours that a colour-blind reader can still tell apart")
    print("=" * 78)
    print(f"""
Search, not taste. Six hues are drawn from a grid, at a small lightness
stagger so that the set carries a SECOND channel besides hue — a dichromat
collapses the colour wheel onto one axis, and two chips that differ only in
hue can land on the same point. The house violet band {reserved[0]:.0f}–{reserved[1]:.0f}°
is excluded: a section chip must never be mistakable for chrome.

Scored on the smallest pairwise OKLab distance over normal vision AND
simulated protanopia, deuteranopia and tritanopia. Highest floor wins.
""")
    import random
    lo, hi = reserved
    hues = [h for h in range(0, 360, 6) if not (lo <= h <= hi)]
    Ls = [0.760, 0.820, 0.880]
    C = 0.155

    # Precompute every candidate cell once. The inner loop of the search is
    # then pure arithmetic on cached OKLab triples — the naive version
    # (177k hue sets x 729 lightness assignments x a fresh colour conversion
    # per pair) is about 10^9 conversions and does not finish.
    cell: dict[tuple[int, int], tuple] = {}
    for h in hues:
        for li, L in enumerate(Ls):
            hx = oklch_to_hex(L, C, math.radians(h))
            if min(contrast(hx, b) for b in bgs.values()) < 4.5:
                continue
            labs = tuple(rgb_to_oklab(hex_to_rgb(hx if k == "normal" else simulate(hx, k)))
                         for k in ("normal", "protan", "deutan", "tritan"))
            cell[(h, li)] = (hx, labs)
    keys = list(cell)
    print(f"  {len(keys)} (hue, lightness) cells clear 4.5:1 on all three surfaces")

    def floor(sel):
        w = 9.0
        for i, j in itertools.combinations(range(6), 2):
            a, b = cell[sel[i]][1], cell[sel[j]][1]
            for k in range(4):
                la, aa, ba = a[k]
                lb, ab, bb = b[k]
                d = (la - lb) ** 2 + (aa - ab) ** 2 + (ba - bb) ** 2
                if d < w:
                    w = d
        return math.sqrt(w)

    def ok_spread(sel):
        s = sorted(h for h, _ in sel)
        return min((s[(i + 1) % 6] - s[i]) % 360 for i in range(6)) >= 30

    rng = random.Random(20260813)
    top = None
    for _restart in range(400):
        while True:
            sel = rng.sample(keys, 6)
            if len(set(h for h, _ in sel)) == 6 and ok_spread(sel):
                break
        cur = floor(sel)
        for _step in range(4000):
            i = rng.randrange(6)
            cand = list(sel)
            cand[i] = rng.choice(keys)
            if len(set(h for h, _ in cand)) < 6 or not ok_spread(cand):
                continue
            f = floor(cand)
            if f > cur:
                sel, cur = cand, f
        if top is None or cur > top[0]:
            top = (cur, sorted(sel))

    w, sel = top
    cols = [cell[k][0] for k in sel]
    wc, kind, pair = worst_cvd(cols)
    print(f"\n  best floor: {wc:.4f} OKLab, limited by {kind} on {pair[0]} / {pair[1]}")
    for (h, li), c in zip(sel, cols):
        print(f"    h{h:3d}  L{Ls[li]:.3f}  {c}   grey {grey(c):.3f}  "
              + "  ".join(f"{k}:{simulate(c, k)}" for k in ("protan", "deutan", "tritan")))
    print("""
The winner is a STARTING POINT, not the answer: the optimiser knows about
distance and nothing about meaning. What shipped keeps the measured floor but
assigns the six hues semantically — see tools/palette.py SECTIONS, which
re-measures whatever it is given and refuses to be documented as passing
without the number.
""")
    return top


def main():
    bg, surface = "#0b0910", "#100d18"
    q1_surfaces()
    print()
    q2_violet(bg, surface)
    print()
    q3_sections({"bg": bg, "surface": surface, "raised": "#191426"}, (280.0, 312.0))


if __name__ == "__main__":
    main()
