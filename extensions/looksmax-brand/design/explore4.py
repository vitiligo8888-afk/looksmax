#!/usr/bin/env python3
"""
Round 4: refine the one direction that survived three rounds of rendering.

What round 3 showed at true pixel size:
  caliper-d  a hammer
  caliper-v  a plumbing fitting
  seam       a pause button
  slab       a marker pen
  jaws       still brackets
  delta      clean and bold, and a generic arrowhead
  wedge      stairs
  try-l      a plain L: legible, characterless
  try-l-ticks  a graduated square. The only one that is both legible at 16px
               and specific to a board whose own tagline is "evidence over
               cope" — an instrument that measures, which is the argument this
               forum has about faces.
  plate-l    crisp at every size, but a letter in a box, which is the thing
             the brief rules out.

This round raises the craft on the graduated square and keeps three rivals in
the frame so the choice stays a comparison:
  * arms of unequal thickness (stock and blade), which is what stops it being
    the letter L — a letter has one stroke weight
  * chamfered arm ends, so the outline is drawn rather than typed
  * graduations cut out of the solid, so 16px loses the detail and keeps the
    silhouette exactly
"""
from pathlib import Path

OUT = Path(__file__).parent / "explore4"
OUT.mkdir(parents=True, exist_ok=True)
HEAD = ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32" '
        'fill="none" stroke="none" role="img">')
F = 'fill-rule="evenodd" fill="currentColor"'


def write(name, body, note):
    (OUT / f"{name}.svg").write_text(f"{HEAD}<title>{name}</title><desc>{note}</desc>{body}</svg>")


# ---- the square ------------------------------------------------------------
# stock (upright arm) 9 wide, blade (lower arm) 6 tall: unequal on purpose.
# Outer corner at (4,29); both free ends chamfered 3u on the outer side.
SQ_CHAMFER = "M4 29V6l3-3h6v20h13l3 3v3z"
SQ_PLAIN = "M4 29V3h9v20h16v6z"
# blade graduations, cut down from the blade's top edge (y=23)
TICKS_BLADE = "M15 23h2v4h-2zM19 23h2v2.5h-2zM23 23h2v4h-2z"
# stock graduations, cut in from the stock's inner edge (x=13)
TICKS_STOCK = "M13 7v2h-4V7zM13 12v2h-2.5v-2zM13 17v2h-4v-2z"

write("sq-a", f'<path {F} d="{SQ_CHAMFER}{TICKS_BLADE}"/>',
      "graduated square, chamfered, blade graduations")
write("sq-b", f'<path {F} d="{SQ_CHAMFER}{TICKS_BLADE}{TICKS_STOCK}"/>',
      "graduated square, both arms graduated")
write("sq-c", f'<path {F} d="{SQ_PLAIN}{TICKS_BLADE}"/>',
      "square corners, blade graduations")
write("sq-d", f'<path {F} d="{SQ_CHAMFER}{TICKS_BLADE}M13 23h-4v-4h4z"/>',
      "chamfered, with the right-angle marker cut at the inner corner")

# Upright: long stock, short blade — a square as it is actually held.
write("sq-e",
      f'<path {F} d="M11 29V6l3-3h6v20h6l3 3v3z'
      'M20 7v2h-4V7zM20 12v2h-2.5v-2zM20 17v2h-4v-2z"/>',
      "upright square, long graduated stock")

# App-icon form: the square knocked out of a plate.
write("sq-plate",
      f'<path {F} d="M8 0h16a8 8 0 0 1 8 8v16a8 8 0 0 1-8 8H8a8 8 0 0 1-8-8V8a8 8 0 0 1 8-8z'
      'M8 26V10l2-2h4v13h9l2 2v3z'
      'M15.5 21h1.5v3h-1.5zM19 21h1.5v2h-1.5z"/>',
      "graduated square knocked out of a plate")

# ---- rivals kept in frame --------------------------------------------------
write("rise",
      f'<path {F} d="M3 29v-4h4v4zM9 29V19h4v10zM15 29V13h4v16zM21 29V7h4v22zM27 29V3h4v26z" '
      'transform="translate(-1 0)"/>',
      "graduations rising to the right")
write("delta2", f'<path {F} d="M16 2l13 27-13-6.5-13 6.5zM16 12l-5 10 5-2.5 5 2.5z"/>',
      "delta with a second delta cut out")
write("plate-l",
      f'<path {F} d="M8 0h16a8 8 0 0 1 8 8v16a8 8 0 0 1-8 8H8a8 8 0 0 1-8-8V8a8 8 0 0 1 8-8z'
      'M9 7v18h15v-6H15V7z"/>',
      "round 3 control: plain L in a plate")

print("\n".join(sorted(p.name for p in OUT.glob("*.svg"))))
