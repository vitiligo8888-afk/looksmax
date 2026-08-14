#!/usr/bin/env python3
"""
Round 3.

Round 2 killed the face. Knocking a profile out of a plate leaves the plate as
a thin ring at 16px, and at 512px the nose and lips read as a beak — it looks
like a cartoon, not like a board that argues about millimetres.

Two constraints survive from rounds 1 and 2 and drive everything here:

  * comb shapes are letters. A spine with two crossbars is an F, with three an
    E, two prongs on a base is a U. Any mark built as bar + parallel arms will
    be read as a letter before it is read as an object. Either own the letter
    or break the parallelism.
  * detail must degrade by disappearing, not by mushing. Cut slots and notches
    out of a solid silhouette rather than assembling the mark from strokes,
    so that at 16px the outline is still exactly right and only the detail is
    gone.

So: own the letter (L, which is the brand's own initial, not an invented
symbol) and treat it as an instrument; or go fully abstract with a single
solid diagonal silhouette that has no letter to collide with.
"""
from pathlib import Path

OUT = Path(__file__).parent / "explore3"
OUT.mkdir(parents=True, exist_ok=True)
HEAD = ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32" '
        'fill="none" stroke="none" role="img">')


def write(name, body, note):
    (OUT / f"{name}.svg").write_text(f"{HEAD}<title>{name}</title><desc>{note}</desc>{body}</svg>")


F = 'fill-rule="evenodd" fill="currentColor"'

# 1. try square: the letter L and a machinist's square are the same shape.
write("try-l", f'<path {F} d="M4 3h8v18h16v8H4z"/>', "solid try-square L")

# 2. same, with tick slots cut into the inner edges. Detail vanishes at 16px
#    and the silhouette is untouched.
write("try-l-ticks",
      f'<path {F} d="M4 3h8v18h16v8H4z'
      'M12 6h-3v3h3zM12 11h-3v3h3zM12 16h-3v3h3z'
      'M17 21v3h-3v-3zM22 21v3h-3v-3z"/>',
      "try-square with measuring ticks cut out of the inner edges")

# 3. vertical caliper: beam right, fixed jaw and sliding jaw left. Included
#    specifically to see whether it reads as an F.
write("caliper-v",
      f'<path {F} d="M21 3h6v26h-6z'
      'M5 3h16v6H5z'
      'M5 17h14v6H5zM19 15h10v10H19z"/>',
      "vertical caliper, fixed jaw and slider")

# 4. diagonal caliper: the same object at 38 degrees, where no letter lives.
write("caliper-d",
      f'<g transform="rotate(-38 16 16)"><path {F} d="M21 3h6v26h-6z'
      'M6 3h15v6H6zM6 17h13v6H6zM19 15h10v10H19z"/></g>',
      "caliper rotated off the letter axes")

# 5. stepped wedge: a solid triangle whose rise is cut into steps.
write("wedge",
      f'<path {F} d="M3 29V21h7v-6h7v-6h7V3h5v26z"/>',
      "solid wedge with a stepped rise")

# 6. two slabs either side of a seam, offset so they are near-mirrors rather
#    than a pause button.
write("seam",
      f'<path {F} d="M4 6l10-3v23l-10 3zM28 3l-10 3v23l10-3z"/>',
      "vertical seam between two offset slabs")

# 7. asymmetric caliper jaws, from round 1, with one jaw extended.
write("jaws",
      f'<path {F} d="M13 4H6v24h7v-5H11V9h2zM19 4h7v24h-7v-5h2V9h-2z"/>'
      '<path fill="currentColor" d="M14 12h4v8h-4z"/>',
      "opposed jaws, unequal, on a measured object")

# 8. arrowhead / delta with a bite out of the base.
write("delta", f'<path {F} d="M16 2l14 28-14-7-14 7z"/>', "solid delta with a notched base")

# 9. the L knocked out of a plate, for the app-icon form.
write("plate-l",
      f'<path {F} d="M8 0h16a8 8 0 0 1 8 8v16a8 8 0 0 1-8 8H8a8 8 0 0 1-8-8V8a8 8 0 0 1 8-8z'
      'M9 7v18h15v-6H15V7z"/>',
      "L knocked out of a rounded-square plate")

# 10. a rising slab: one solid parallelogram, sheared, with a slot cut through.
write("slab",
      f'<path {F} d="M6 22l18-14 5 5-18 14zM3 27l18-14 3 3L6 30z"/>',
      "two sheared slabs rising to the right")

print("\n".join(sorted(p.name for p in OUT.glob("*.svg"))))
