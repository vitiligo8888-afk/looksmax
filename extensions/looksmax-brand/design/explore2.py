#!/usr/bin/env python3
"""
Round 2. What round 1 measured, at 16px, on real pixels:

  gauge    rendered as a capital E
  jaw      rendered as a capital J
  thirds   rendered as a hamburger menu button
  ascent   rendered as a signal-strength icon
  apex     three separate outlines collapsed into a blob
  caliper  legible, but reads "code editor", not "face"
  split    legible, but reads "dark mode toggle"
  profile  the only one still recognisable AND on-subject, but as a positive
           fill its edge dissolves into the background at 16px

The rule that falls out: at 16px only a filled silhouette with a hard outer
edge survives, and any mark assembled from three or more separated strokes
turns into either mush or an unintended letter. So round 2 keeps exactly one
idea — the profile — and gives it a plate to hold its edge, then varies the
plate and the amount of detail in the knockout.
"""
from pathlib import Path

OUT = Path(__file__).parent / "explore2"
OUT.mkdir(parents=True, exist_ok=True)
HEAD = ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32" '
        'fill="none" stroke="none" role="img">')


def write(name: str, body: str, note: str) -> None:
    (OUT / f"{name}.svg").write_text(f"{HEAD}<title>{name}</title><desc>{note}</desc>{body}</svg>")


# The profile itself. Faceted rather than smooth: a board that argues about
# gonial angles and canthal tilt should have a mark that looks measured, and a
# soft portrait silhouette reads "photography studio".
#
# DETAIL is the full drawing; PLAIN drops the lips so that at 16px the nose and
# the chin each get a whole pixel step instead of sharing one.
DETAIL = ("M6 32V13l1.4-4.4L11 5.6 15 4.6l4 1.2 2.2 3.2.3 3.6-2.1 1.2"
          "L26 18.4l-6.2 1.7 2 1.7-2.4 1.2 3.4 1.9-1.7 3.6-1 7.5z")
PLAIN = ("M6 32V13l1.4-4.4L11 5.6 15 4.6l4 1.2 2.2 3.2.3 3.6-2.1 1.2"
         "L26 18.4l-6.6 2.1 3.2 3-1.6 3.4-1 7.5z")

PLATE_SQ = "M8 0h16a8 8 0 0 1 8 8v16a8 8 0 0 1-8 8H8a8 8 0 0 1-8-8V8a8 8 0 0 1 8-8z"
PLATE_HEX = "M16 0l13.9 8v16L16 32 2.1 24V8z"
PLATE_CIRC = "M16 0a16 16 0 1 1 0 32 16 16 0 0 1 0-32z"
# A keystone: square shoulders, tapered foot. Reads as a plate at 16px but is
# not the same rounded square as every other app icon on the tab strip.
PLATE_SHIELD = "M4 0h24a4 4 0 0 1 4 4v13c0 8-6.8 12.6-16 15C6.8 29.6 0 25 0 17V4a4 4 0 0 1 4-4z"

write("plate-profile", f'<path fill-rule="evenodd" fill="currentColor" d="{PLATE_SQ}{DETAIL}"/>',
      "profile knocked out of a rounded-square plate")
write("plate-plain", f'<path fill-rule="evenodd" fill="currentColor" d="{PLATE_SQ}{PLAIN}"/>',
      "same plate, lips dropped so the nose and chin each own a pixel at 16px")
write("hex-profile", f'<path fill-rule="evenodd" fill="currentColor" d="{PLATE_HEX}{PLAIN}"/>',
      "profile knocked out of a hexagon")
write("circle-profile", f'<path fill-rule="evenodd" fill="currentColor" d="{PLATE_CIRC}{PLAIN}"/>',
      "profile knocked out of a disc")
write("shield-profile", f'<path fill-rule="evenodd" fill="currentColor" d="{PLATE_SHIELD}{PLAIN}"/>',
      "profile knocked out of a keystone")

# Thirds rules cut across the plate at the brow and the base of the nose: the
# facial-thirds diagram, drawn on the thing it measures rather than beside it.
THIRDS = "M0 11.4h32v1.6H0zM0 19.6h32v1.6H0z"
write("plate-thirds", f'<path fill-rule="evenodd" fill="currentColor" d="{PLATE_SQ}{PLAIN}{THIRDS}"/>',
      "plate + profile + the two thirds rules")

# Inverted: profile as the positive shape standing on the plate's baseline,
# plate reduced to a frame. Included to check that the knockout is actually the
# stronger of the two, rather than assumed to be.
write("frame-profile",
      f'<path fill="currentColor" d="{PLAIN}" transform="translate(1 0) scale(0.94)"/>'
      '<path d="M2 2h28v28H2z" stroke="currentColor" stroke-width="3" fill="none"/>',
      "profile as positive fill inside a frame")

# The measured-object idea from round 1's caliper, but merged into the plate so
# it is one silhouette: plate with the profile knocked out and a caliper notch
# taken out of the right edge at nose height.
write("plate-notch",
      f'<path fill-rule="evenodd" fill="currentColor" d="{PLATE_SQ}{PLAIN}'
      'M32 14.2v5.6l-4-2.8z"/>',
      "plate + profile + a caliper notch in the right edge at nose height")

print("\n".join(sorted(p.name for p in OUT.glob("*.svg"))))
