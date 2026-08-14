#!/usr/bin/env python3
"""
Round 5: pixel-snap the chosen mark, and settle how it is plated.

sq-a won round 4. Its one measured defect was a fuzzy foot at 16px, and the
cause is arithmetic, not taste: its edges sat on odd grid units, so at 16px
(where one grid unit is half a pixel) every horizontal edge landed on a half
pixel and got anti-aliased into a grey smear. Every edge here is on an even
unit. Nothing else about the drawing changed.

Plating is the other decision this round settles. A bare gold mark is right in
the header, where it sits on our own dark surface. It is wrong as a favicon:
gold on the white tab strip of a light-themed browser measures 1.75:1, which
is invisible. So the favicon and the app icons get a dark plate, and the plate
is part of the mark at those sizes rather than an afterthought.
"""
from pathlib import Path

OUT = Path(__file__).parent / "explore5"
OUT.mkdir(parents=True, exist_ok=True)
HEAD = ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32" '
        'fill="none" stroke="none" role="img">')
F = 'fill-rule="evenodd" fill="currentColor"'


def write(name, body, note):
    (OUT / f"{name}.svg").write_text(f"{HEAD}<title>{name}</title><desc>{note}</desc>{body}</svg>")


# --- the mark, every edge on an even grid unit ------------------------------
# stock  x 4..12, y 4..28  (chamfered 4u at the top outer corner)
# blade  x 4..28, y 22..28 (chamfered 4u at the right outer corner)
SQUARE = "M4 28V8l4-4h4v18h12l4 4v2z"
TICKS3 = "M14 22h2v4h-2zM18 22h2v2h-2zM22 22h2v4h-2z"
TICKS2 = "M14 22h2v4h-2zM20 22h2v4h-2z"
TICKS_DEEP = "M14 22h2v6h-2zM18 22h2v2h-2zM22 22h2v6h-2z"

write("m1", f'<path {F} d="{SQUARE}{TICKS3}"/>', "snapped, three graduations")
write("m2", f'<path {F} d="{SQUARE}{TICKS2}"/>', "snapped, two graduations")
write("m3", f'<path {F} d="{SQUARE}{TICKS_DEEP}"/>', "snapped, graduations cut clean through")
write("m4", f'<path {F} d="{SQUARE}"/>', "snapped, no graduations")
# variant with the stock also carrying two graduations, restrained
write("m5", f'<path {F} d="{SQUARE}{TICKS3}M12 8h-4v2h4zM12 14h-4v2h4z"/>',
      "snapped, graduations on both arms")

# --- plated forms -----------------------------------------------------------
PLATE = "M7 0h18a7 7 0 0 1 7 7v18a7 7 0 0 1-7 7H7a7 7 0 0 1-7-7V7a7 7 0 0 1 7-7z"


def plated(name, inset_scale, note):
    """Plate in one colour with the mark in the other, as two paths."""
    s = inset_scale
    t = (32 - 32 * s) / 2
    body = (f'<path fill="var(--plate,#12161c)" d="{PLATE}"/>'
            f'<g transform="translate({t:.2f} {t:.2f}) scale({s})">'
            f'<path {F} d="{SQUARE}{TICKS3}"/></g>')
    write(name, body, note)


plated("p-tight", 0.80, "plated, mark at 80%")
plated("p-mid", 0.70, "plated, mark at 70%")
plated("p-loose", 0.62, "plated, mark at 62% (maskable safe zone)")

print("\n".join(sorted(p.name for p in OUT.glob("*.svg"))))
