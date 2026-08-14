#!/usr/bin/env python3
"""
Mark exploration for Looksmax.lat.

Eight directions, drawn parametrically on one 32x32 grid so they can be
compared as pixels rather than as descriptions. Everything is a multiple of
2 grid units, which is 1 device pixel when the mark is rasterised at 16px —
a stroke that lands on a half pixel is the reason most logos turn to mush in
a browser tab, and it is invisible in the vector.

Output: design/explore/<name>.svg. render.ts turns these into true-size PNGs
and a magnified contact sheet.

Colour is left to the caller: every shape uses currentColor, so the same file
is the dark-surface mark, the white-surface mark and the monochrome mark.
"""
import os
from pathlib import Path

OUT = Path(__file__).parent / "explore"
OUT.mkdir(parents=True, exist_ok=True)

HEAD = ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32" '
        'fill="none" stroke="none" role="img">')


def write(name: str, body: str, note: str) -> None:
    svg = f"{HEAD}<title>{name}</title><desc>{note}</desc>{body}</svg>"
    (OUT / f"{name}.svg").write_text(svg)


# --------------------------------------------------------------- 1. caliper
# The literal tool of the subject: two jaws closing on a measured object.
# Jaw strokes are 4u (2px at 16), the object is 4u, the two gaps are 5u.
write(
    "caliper",
    '<path d="M12 5H6v22h6" stroke="currentColor" stroke-width="4" fill="none"/>'
    '<path d="M20 5h6v22h-6" stroke="currentColor" stroke-width="4" fill="none"/>'
    '<rect x="14" y="10" width="4" height="12" fill="currentColor"/>',
    "opposed caliper jaws closing on a measured object",
)

# ------------------------------------------------------------------ 2. jaw
# The mandible in profile: ramus down the back, gonial angle, body forward to
# the chin. One stroke, no container.
write(
    "jaw",
    '<path d="M23 5v11c0 6-4 10-10 10H8" stroke="currentColor" stroke-width="5" '
    'fill="none" stroke-linecap="square"/>',
    "mandible in profile as a single stroke",
)

# --------------------------------------------------------------- 3. profile
# Angular face profile, filled silhouette, cropped at the back of the skull.
write(
    "profile",
    '<path d="M9 4h9l3 6-3 3 7 5-6 2 3 2-3 2 4 4H9z" fill="currentColor"/>',
    "angular face profile as a filled silhouette",
)

# ----------------------------------------------------------------- 4. split
# Facial symmetry: one shape, two halves, one solved and one not.
write(
    "split",
    '<path d="M15 3C9 5 4 10 4 16s5 11 11 13z" fill="currentColor"/>'
    '<path d="M17 3c6 2 11 7 11 13s-5 11-11 13" stroke="currentColor" '
    'stroke-width="4" fill="none"/>',
    "vertical symmetry axis, one half solved and one half outlined",
)

# ---------------------------------------------------------------- 5. ascent
# Three ascending bars. The most legible option and the least specific one.
write(
    "ascent",
    '<rect x="4" y="18" width="6" height="10" fill="currentColor"/>'
    '<rect x="13" y="11" width="6" height="17" fill="currentColor"/>'
    '<rect x="22" y="4" width="6" height="24" fill="currentColor"/>',
    "three ascending bars",
)

# ----------------------------------------------------------------- 6. thirds
# The facial-thirds diagram: one solid block, two cut lines. Reads as a filled
# silhouette at 16px, which is the only thing that survives there.
write(
    "thirds",
    '<path fill-rule="evenodd" fill="currentColor" d="M8 3h16a5 5 0 0 1 5 5v16a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5V8a5 5 0 0 1 5-5z'
    'M3 12h26v3H3zM3 20h26v3H3z"/>',
    "the facial-thirds diagram as a block cut by two rules",
)

# ------------------------------------------------------------------ 7. gauge
# Measuring rule with three divisions: the thirds again, but as an instrument
# rather than a face.
write(
    "gauge",
    '<rect x="6" y="4" width="4" height="24" fill="currentColor"/>'
    '<rect x="10" y="5" width="16" height="4" fill="currentColor"/>'
    '<rect x="10" y="14" width="10" height="4" fill="currentColor"/>'
    '<rect x="10" y="23" width="16" height="4" fill="currentColor"/>',
    "measuring rule divided into thirds",
)

# ------------------------------------------------------------------- 8. apex
# Chevron peak inside a hexagon.
write(
    "apex",
    '<path d="M16 2.5 28.5 9.5v13L16 29.5 3.5 22.5v-13z" stroke="currentColor" '
    'stroke-width="3" fill="none" stroke-linejoin="round"/>'
    '<path d="M9 18l7-6 7 6" stroke="currentColor" stroke-width="4" fill="none" '
    'stroke-linejoin="miter" stroke-linecap="square"/>'
    '<path d="M9 24l7-6 7 6" stroke="currentColor" stroke-width="4" fill="none" '
    'stroke-linejoin="miter" stroke-linecap="square"/>',
    "stacked chevrons in a hexagon",
)

print("\n".join(sorted(p.name for p in OUT.glob("*.svg"))))
