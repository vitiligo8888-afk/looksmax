#!/usr/bin/env python3
"""Turn a chrigger illustration into the brand mark.

The operator's direction: the mark is one of the chrigger faces.  A detailed
illustrated face does not survive a 16px browser tab — that was measured, not
assumed; see BRAND.md §1 and the sheets under design/chrigger/.  So the
illustration is not shipped as the mark.  It is reduced to a two-tone
engraving, by the rule the first mark search produced:

    detail must degrade by DISAPPEARING, not by mushing — cut the detail out
    of a solid silhouette instead of assembling the mark from strokes.

Pipeline, in order:

  1. cut the studio background to true transparency.  It is near-white but not
     flat: the border ring measures 247-255 across the 13 files and each
     carries a soft drop shadow under the hair and jaw, so a global colour key
     either leaves a grey halo or eats the forehead highlight.  A flood fill
     from the border is a connectivity test, not a colour test, and follows the
     shadow; the artwork's continuous ink outline stops it leaking inside.
  2. un-premultiply the anti-aliased ring against white, or every edge pixel
     ships with white mixed in and glows on a dark surface.
  3. separate horns (saturated red), the hair mass (dark, thick — survives a
     13px opening) and the facial features (dark, thin — does not).
  4. fill the whole silhouette with brass and knock the boundaries back out as
     lines.  At >=48px that reads as an engraved mark; at 16px the lines go
     sub-pixel and what is left is the horned silhouette, which is still right.
  5. potrace the two bitmaps into path data on the 32-unit grid master.py uses.

    python3 design/chrigger.py [face]        # writes design/mark-paths.json

Both source and output are committed, so master.py does not need scipy or
potrace on a machine that only wants to rebuild the SVGs.
"""
from __future__ import annotations

import json
import re
import subprocess
import sys
import tempfile
from pathlib import Path

import numpy as np
from PIL import Image
from scipy import ndimage

HERE = Path(__file__).parent
REPO = HERE.parent.parent.parent           # /root/flarum-stack
SRC_DIR = REPO / "assets" / "chrigger"
OUT = HERE / "mark-paths.json"

BG_L = 252.0      # what the paper reads as
CUT_L = 224.0     # below this a pixel is entirely ink
GRID = 32.0       # master.py's coordinate grid


# --------------------------------------------------------------------- cutout
def cut_background(path: Path, flood_thresh: int = 205, ring: int = 3) -> Image.Image:
    im = Image.open(path).convert("RGB")
    # several files carry a 1-3px dark frame from the generator (devil's left
    # column reads 198 against a 250 field); it is not artwork, and it anchors
    # the bounding box at x=0 if it survives.
    im = im.crop((4, 4, im.width - 4, im.height - 4))
    a = np.asarray(im).astype(np.float32)
    L = a.min(axis=2)                       # min channel: catches coloured ink
    light = L >= flood_thresh

    seed = np.zeros_like(light)
    seed[0, :] = seed[-1, :] = True
    seed[:, 0] = seed[:, -1] = True
    seed &= light                           # thinking.png runs an arm off the edge
    lab, _ = ndimage.label(light)
    bg_labels = np.unique(lab[seed])
    bg = np.isin(lab, bg_labels[bg_labels > 0])

    alpha = (~bg).astype(np.float32)
    soft = np.clip((BG_L - L) / (BG_L - CUT_L), 0.0, 1.0)
    near = ndimage.binary_dilation(bg, iterations=ring) & ~bg
    alpha[near] = np.minimum(alpha[near], soft[near])

    rgb = a.copy()
    m = (alpha > 0.004) & (alpha < 0.999)
    for c in range(3):
        rgb[..., c][m] = np.clip((a[..., c][m] - (1 - alpha[m]) * 255.0) / alpha[m], 0, 255)

    out = np.dstack([rgb, alpha * 255.0]).astype(np.uint8)
    return despeckle(Image.fromarray(out, "RGBA"))


def despeckle(im: Image.Image, min_px: int = 400) -> Image.Image:
    a = np.asarray(im).copy()
    lab, n = ndimage.label(a[..., 3] > 8)
    if n:
        sizes = ndimage.sum(np.ones_like(lab), lab, range(1, n + 1))
        kill = np.isin(lab, [i + 1 for i, s in enumerate(sizes) if s < min_px])
        a[..., 3][kill] = 0
    return Image.fromarray(a, "RGBA")


# ------------------------------------------------------------------- engraving
def analyse(im: Image.Image) -> dict:
    a = np.asarray(im).astype(np.float32)
    R, G, B = a[..., 0], a[..., 1], a[..., 2]
    L = 0.2126 * R + 0.7152 * G + 0.0722 * B
    inside = ndimage.binary_fill_holes(a[..., 3] / 255.0 > 0.5)
    horn = inside & (R - G > 55) & (R > 90) & (G < 150)
    horn = ndimage.binary_closing(horn, np.ones((7, 7)))
    dark = inside & (L < 135) & ~horn
    hair = ndimage.binary_opening(dark, np.ones((13, 13)))
    lab, n = ndimage.label(hair)
    if n:
        sz = ndimage.sum(np.ones_like(lab), lab, range(1, n + 1))
        hair = ndimage.binary_fill_holes(lab == int(np.argmax(sz)) + 1)
    feat = dark & ~hair
    lab2, n2 = ndimage.label(feat)
    if n2:
        s2 = ndimage.sum(np.ones_like(lab2), lab2, range(1, n2 + 1))
        feat = np.isin(lab2, [i + 1 for i, s in enumerate(s2) if s > 250])
    return dict(inside=inside, horn=horn, hair=hair, feat=feat)


def _edge(m: np.ndarray, w: int) -> np.ndarray:
    return ndimage.binary_dilation(m, iterations=w) & ~ndimage.binary_erosion(m, iterations=w)


def engraved(im: Image.Image, line_w: int = 4, feat_w: int = 1) -> tuple[np.ndarray, np.ndarray]:
    """Return (full, simple) boolean bitmaps: ink is True."""
    d = analyse(im)
    inside = d["inside"]
    cut = (_edge(d["hair"], line_w) | _edge(d["horn"], line_w)) & inside
    cut |= ndimage.binary_dilation(d["feat"], iterations=feat_w) & inside
    return inside & ~cut, inside


# ---------------------------------------------------------------------- trace
_CMDS = {"M": 2, "L": 2, "C": 6, "S": 4, "Q": 4, "T": 2, "H": 1, "V": 1, "Z": 0, "A": 7}


def _transform_path(d: str, sx: float, sy: float, tx: float, ty: float) -> str:
    """Bake potrace's translate+scale into the coordinates.

    Absolute commands take the full affine, relative ones only the linear part.
    The transform potrace emits is a pure scale and translate, so there is no
    rotation to worry about and h/v stay h/v.
    """
    toks = re.findall(r"[A-Za-z]|-?\d*\.?\d+(?:e-?\d+)?", d)
    out, i, cmd = [], 0, "M"
    while i < len(toks):
        t = toks[i]
        if re.match(r"[A-Za-z]", t):
            cmd = t
            out.append(t)
            i += 1
            if cmd.upper() == "Z":
                continue
        n = _CMDS[cmd.upper()]
        if n == 0:
            continue
        rel = cmd.islower()
        vals = [float(v) for v in toks[i:i + n]]
        i += n
        if cmd.upper() in ("H",):
            vals = [vals[0] * sx + (0 if rel else tx)]
        elif cmd.upper() in ("V",):
            vals = [vals[0] * sy + (0 if rel else ty)]
        else:
            for k in range(0, len(vals), 2):
                vals[k] = vals[k] * sx + (0 if rel else tx)
                vals[k + 1] = vals[k + 1] * sy + (0 if rel else ty)
        out.append(" ".join(f"{v:.3f}".rstrip("0").rstrip(".") or "0" for v in vals))
    return " ".join(out)


def trace(mask: np.ndarray, box: tuple[int, int, int, int], canvas: int = 720,
          turdsize: int = 26, alphamax: float = 1.2, opttolerance: float = 0.8,
          smooth: int = 2) -> str:
    """potrace a boolean bitmap into one path on the 0..32 grid.

    `box` is the crop applied to every mask in the set, so the full and simple
    drawings stay in register: two independently tightened crops would put the
    small mark a pixel off the large one, and the header would jump when the
    breakpoint changed which file it used.
    """
    x0, y0, x1, y1 = box
    sub = mask[y0:y1, x0:x1]
    h, w = sub.shape
    side = max(w, h)
    sq = np.zeros((side, side), bool)
    sq[(side - h) // 2:(side - h) // 2 + h, (side - w) // 2:(side - w) // 2 + w] = sub
    # Area-average down to the trace canvas and re-threshold, then a median
    # pass: potrace faithfully follows every jagged pixel of a hard mask, and a
    # ragged input is what turns a head into a 120KB path.
    small = np.asarray(Image.fromarray(sq.astype(np.uint8) * 255)
                       .resize((canvas, canvas), Image.BOX)) > 127
    if smooth:
        small = ndimage.median_filter(small, size=smooth * 2 + 1)
    bw = Image.fromarray((~small).astype(np.uint8) * 255).convert("1")

    with tempfile.TemporaryDirectory() as td:
        pbm, svg = Path(td) / "m.pbm", Path(td) / "m.svg"
        bw.save(pbm)
        subprocess.run(["potrace", "-b", "svg", "--flat", "-t", str(turdsize),
                        "-a", str(alphamax), "-O", str(opttolerance), "-o", str(svg), str(pbm)],
                       check=True)
        text = svg.read_text()

    m = re.search(r'transform="translate\(([-\d.]+),([-\d.]+)\) scale\(([-\d.]+),([-\d.]+)\)"', text)
    tx, ty, sx, sy = (float(g) for g in m.groups())
    d = " ".join(re.findall(r'<path[^>]*\sd="([^"]+)"', text))
    # potrace works in 1/10 units off a bottom-left origin; bake that in, then
    # scale the whole square canvas onto the 32-unit grid master.py draws on.
    k = GRID / canvas
    return _transform_path(d, sx * k, sy * k, tx * k, ty * k)


def _jaw(mask: np.ndarray, box: tuple[int, int, int, int], frac: float = 0.55) -> int:
    """Row at which the neck starts, so the mark is a head and not a bust.

    Rendered with the neck attached, the 16px favicon read as a guitar pick:
    the taper below the jaw is the widest thing in the bottom third and it
    swallows the horns proportionally. Measured on the built favicon-16.png
    before this cut.
    """
    x0, y0, x1, y1 = box
    widths = mask[y0:y1, x0:x1].sum(axis=1)
    top = int(np.argmax(widths))
    for i in range(top, len(widths)):
        if widths[i] < widths[top] * frac:
            return y0 + i
    return y1


# ----------------------------------------------------------------------- main
def main(face: str = "devil") -> None:
    src = SRC_DIR / f"{face}.png"
    if not src.exists():
        sys.exit(f"no such face: {src}")
    im = cut_background(src)
    full, simple = engraved(im)

    ys, xs = np.where(simple)
    box = (int(xs.min()), int(ys.min()), int(xs.max()) + 1, int(ys.max()) + 1)
    box = (box[0], box[1], box[2], _jaw(simple, box))

    data = {
        "face": face,
        "source": str(src.relative_to(REPO)),
        "grid": GRID,
        "full": trace(full, box),
        "simple": trace(simple, box),
    }
    OUT.write_text(json.dumps(data, indent=2) + "\n")
    print(f"  {OUT.name}: face={face} full={len(data['full'])}b simple={len(data['simple'])}b")

    # a PNG of the cut-out illustration, for the surfaces big enough to carry
    # it (the OG card and the e-mail band) where the full colour is an asset
    # rather than mush.
    art = im.crop(box)
    art.save(HERE.parent / "assets" / "chrigger-face.png")
    print(f"  chrigger-face.png {art.size}")


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "devil")
