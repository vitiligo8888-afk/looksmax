#!/usr/bin/env python3
"""
Put every face at the same size in the same place.

The measured problem (bin/legibility.py, box 24px):

    happy     distinct  3.90   nearest twin: surprised
    surprised distinct  3.90   nearest twin: happy
    neutral   distinct  4.85   nearest twin: sad
    sad       distinct  4.85   nearest twin: neutral
    angry     distinct  8.87   nearest twin: neutral

Five of thirteen are perceptually indistinguishable at chip size. The cause is
not the downscale on its own -- it is that the SOURCE FRAMING is inconsistent.
Trimming to content gives 591x727 for neutral/sad/angry (cropped near the jaw)
but 810x816 for happy/surprised/devil (a lot more hair). Fitting each of those
to a common box then renders the same character's head at two different scales,
and the pixels left over for a mouth in the wide-framed ones are fewer than the
pixels the narrow-framed ones spend on the same mouth.

So: find the face in each drawing, and scale and position every icon so the
face lands at the same size in the same spot. Everything else -- halo, horns,
keffiyeh, money bags, the raised hand -- is then allowed to overflow and clip,
which is correct: those are the identifying marks and they read at any size,
whereas an eyebrow does not.

Face detection here is a skin-chroma rule, not a cascade: the subject is one
cartoon character on transparent ground, so the largest connected skin-coloured
component IS the face, and that is far more robust on illustration than a
detector trained on photographs. Verified against all 13 by eye -- the reported
face box is printed for every icon so a wrong one is visible.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path

import numpy as np
from PIL import Image
from scipy import ndimage

# Where the face should land in the output box, as fractions of the box.
FACE_CX = 0.50  # face centre x
FACE_CY = 0.52  # face centre y (eyes sit above centre; this puts the head high)

# Per-icon policy, and it has to be per-icon -- a single rule is wrong twice.
#
# Normalising every face to a common 72% of the box was measured at 24px to
# take happy 3.90 -> 12.11 and surprised 3.90 -> 13.93 distinct, a 3x win. But
# looking at the result showed what the number could not: it had clipped the
# halo off angel, the horns off devil and the error badge off error, which are
# precisely the marks that made those three legible in the first place. devil's
# distinct fell 9.68 -> 7.36 at 20px, the only regression, and it was real.
#
# So: an icon whose identity lives OUTSIDE the face is fitted whole and keeps
# its mark. An icon whose identity is only an expression is zoomed hard onto
# the face, because for those there is nothing else to lose and the mouth is
# the entire signal.
FACE_FILL: dict[str, float | None] = {
    # expression is the only signal -> zoom onto the face
    "angry": 0.86, "neutral": 0.86, "sad": 0.86,
    "happy": 0.86, "surprised": 0.86, "chrigga": 0.86,
    # the mark is outside the face -> fit the whole drawing, never clip it
    "angel": None,        # halo
    "devil": None,        # horns
    "error": None,        # red error badge
    "nerd": None,         # glasses + raised finger
    "rich": None,         # sunglasses + money bags
    "chrishammed": None,  # keffiyeh
    "thinking": None,     # hand on chin
}
DEFAULT_FILL = 0.86


def skin_mask(rgb: np.ndarray, alpha: np.ndarray) -> np.ndarray:
    """Kovac's rule, which is defined for uint8 sRGB and holds up well on flat
    illustration. Restricted to opaque pixels so the cut-out edge cannot leak."""
    r, g, b = rgb[..., 0], rgb[..., 1], rgb[..., 2]
    mx = rgb.max(axis=2)
    mn = rgb.min(axis=2)
    return (
        (r > 95) & (g > 40) & (b > 20)
        & ((mx - mn) > 15)
        & (np.abs(r - g) > 15)
        & (r > g) & (r > b)
        & (alpha > 0.6)
    )


def face_box(img: Image.Image) -> tuple[int, int, int, int, float]:
    a = np.asarray(img, dtype=np.float64)
    rgb, al = a[..., :3], a[..., 3] / 255.0
    m = skin_mask(rgb, al)
    # close small holes (eyes, glasses, teeth) so the face is one component
    m = ndimage.binary_closing(m, structure=np.ones((9, 9)))
    lab, n = ndimage.label(m)
    if n == 0:
        h, w = al.shape
        return 0, 0, w, h, 0.0
    areas = np.bincount(lab.ravel())
    areas[0] = 0
    big = int(areas.argmax())
    ys, xs = np.nonzero(lab == big)
    frac = float(areas[big]) / float(al.size)
    return int(xs.min()), int(ys.min()), int(xs.max()) + 1, int(ys.max()) + 1, frac


def normalise(img: Image.Image, box: int, fb: tuple[int, int, int, int],
              fill: float | None) -> Image.Image:
    x0, y0, x1, y1 = fb
    fw = max(1, x1 - x0)
    if fill is None:
        scale = box / max(img.width, img.height)
    else:
        scale = (fill * box) / fw
    nw, nh = max(1, int(round(img.width * scale))), max(1, int(round(img.height * scale)))

    a = np.asarray(img, dtype=np.float64)
    al = a[..., 3:4] / 255.0
    pre = Image.fromarray(np.clip(np.dstack([a[..., :3] * al, a[..., 3]]), 0, 255).astype(np.uint8), "RGBA")
    big = np.asarray(pre.resize((nw, nh), Image.LANCZOS), dtype=np.float64)
    sa = big[..., 3:4] / 255.0
    rgb = np.where(sa > 1e-4, big[..., :3] / np.maximum(sa, 1e-4), 0.0)
    scaled = Image.fromarray(np.dstack([np.clip(rgb, 0, 255), big[..., 3]]).astype(np.uint8), "RGBA")

    canvas = Image.new("RGBA", (box, box), (0, 0, 0, 0))
    if fill is None:
        # whole drawing, centred, nothing clipped
        ox, oy = (box - nw) // 2, (box - nh) // 2
    else:
        fcx, fcy = ((x0 + x1) / 2) * scale, ((y0 + y1) / 2) * scale
        ox, oy = int(round(FACE_CX * box - fcx)), int(round(FACE_CY * box - fcy))
    canvas.paste(scaled, (ox, oy), scaled)
    return canvas


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--build", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--sizes", default="20,24,32,40,48,64,96,128,256")
    a = ap.parse_args()
    build, out = Path(a.build), Path(a.out)
    sizes = [int(s) for s in a.sizes.split(",")]
    (out / "master").mkdir(parents=True, exist_ok=True)

    boxes, meta = {}, {}
    for p in sorted((build / "master").glob("*.png")):
        if ".square" in p.name:
            continue
        img = Image.open(p).convert("RGBA")
        x0, y0, x1, y1, frac = face_box(img)
        boxes[p.stem] = (x0, y0, x1, y1)
        meta[p.stem] = {
            "art_px": list(img.size),
            "face_px": [x1 - x0, y1 - y0],
            "face_frac_of_art": round((x1 - x0) / img.width, 3),
            "skin_area_frac": round(frac, 4),
        }
        print(f"  {p.stem:<13} art {img.width}x{img.height}  face {x1-x0}x{y1-y0} "
              f"at ({x0},{y0})  face/art width {meta[p.stem]['face_frac_of_art']}")

    ref = float(np.median([m["face_frac_of_art"] for m in meta.values()]))
    print(f"\n  median face/art width {ref:.3f}\n")
    for n in sorted(boxes):
        f = FACE_FILL.get(n, DEFAULT_FILL)
        meta[n]["policy"] = "fit-whole" if f is None else f"face-{f:.2f}"
        print(f"    {n:<13} {meta[n]['policy']}")
    print()

    total = 0
    for name, fb in boxes.items():
        img = Image.open(build / "master" / f"{name}.png").convert("RGBA")
        fill = FACE_FILL.get(name, DEFAULT_FILL)
        normalise(img, 512, fb, fill).save(out / "master" / f"{name}.png", "PNG", optimize=True)
        for s in sizes:
            d = out / str(s)
            d.mkdir(exist_ok=True)
            im = normalise(img, s, fb, fill)
            if s <= 64:
                from PIL import ImageFilter
                arr = np.asarray(im, dtype=np.uint8)
                rgbf = Image.fromarray(arr[..., :3], "RGB").filter(
                    ImageFilter.UnsharpMask(radius=1.0, percent=55, threshold=0))
                im = Image.fromarray(np.dstack([np.asarray(rgbf), arr[..., 3]]), "RGBA")
            im.save(d / f"{name}.png", "PNG", optimize=True)
            im.save(d / f"{name}.webp", "WEBP", lossless=(s <= 96), quality=100 if s <= 96 else 90,
                    method=6, exact=True)
            total += (d / f"{name}.webp").stat().st_size

    (out / "faces.json").write_text(json.dumps(meta, indent=2))
    print(f"  normalised {len(boxes)} icons x {len(sizes)} sizes, webp total {total} B")


if __name__ == "__main__":
    main()
