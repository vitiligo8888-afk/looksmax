#!/usr/bin/env python3
"""
Answer "which faces survive at 20px" with a number instead of an opinion.

Thirteen drawings of the SAME character differing only in expression will not
all read at chip size, and the ones that fail are not obvious by looking at the
source art -- at 992px every one of them is unmistakable. So measure it.

Two metrics per size, both computed on the icon composited over the real post
surface (#161d28) and scaled to a common box, because that is the comparison a
reader's eye actually makes:

  distinct   the smallest perceptual distance from this icon to ANY other icon
             in the set. Low = there is a twin it can be confused with.
  detail     RMS of the Laplacian, i.e. how much structure is left after the
             downscale. Low = the face has turned to mush regardless of twins.

Distance is RMS over CIE L*a*b*, which tracks "looks different to a human"
far better than RGB does -- two faces differing only in a slightly darker
eyebrow are close in Lab, and that is the answer we want.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path

import numpy as np
from PIL import Image
from scipy import ndimage

SURFACE = np.array([0x16, 0x1D, 0x28], dtype=np.float64)


def srgb_to_lab(rgb: np.ndarray) -> np.ndarray:
    c = rgb / 255.0
    c = np.where(c <= 0.04045, c / 12.92, ((c + 0.055) / 1.055) ** 2.4)
    m = np.array([[0.4124, 0.3576, 0.1805],
                  [0.2126, 0.7152, 0.0722],
                  [0.0193, 0.1192, 0.9505]])
    xyz = c @ m.T / np.array([0.95047, 1.0, 1.08883])
    f = np.where(xyz > 0.008856, np.cbrt(xyz), 7.787 * xyz + 16 / 116)
    return np.stack([116 * f[..., 1] - 16,
                     500 * (f[..., 0] - f[..., 1]),
                     200 * (f[..., 1] - f[..., 2])], axis=-1)


def on_surface(p: Path, box: int) -> np.ndarray:
    """Composite over the post surface and letterbox into a common box so two
    icons of different aspect are compared where they actually sit."""
    im = Image.open(p).convert("RGBA")
    canvas = Image.new("RGBA", (box, box), (0, 0, 0, 0))
    canvas.paste(im, ((box - im.width) // 2, (box - im.height) // 2))
    a = np.asarray(canvas, dtype=np.float64)
    al = a[..., 3:4] / 255.0
    return a[..., :3] * al + SURFACE * (1 - al)


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--build", required=True)
    ap.add_argument("--sizes", default="20,24,32,48")
    ap.add_argument("--out", required=True)
    a = ap.parse_args()
    build = Path(a.build)
    sizes = [int(s) for s in a.sizes.split(",")]

    names = sorted(p.stem for p in (build / "master").glob("*.png") if ".square" not in p.name)
    report: dict = {}

    for box in sizes:
        labs, dets = {}, {}
        for n in names:
            rgb = on_surface(build / str(box) / f"{n}.png", box)
            labs[n] = srgb_to_lab(rgb)
            g = rgb.mean(axis=2)
            dets[n] = float(np.sqrt((ndimage.laplace(g) ** 2).mean()))

        rows = {}
        for n in names:
            best, who = 1e9, None
            for m in names:
                if m == n:
                    continue
                d = float(np.sqrt(((labs[n] - labs[m]) ** 2).sum(axis=-1).mean()))
                if d < best:
                    best, who = d, m
            rows[n] = {"distinct": round(best, 2), "nearest": who,
                       "detail": round(dets[n], 2)}
        report[str(box)] = rows

        print(f"\n  box {box}px")
        for n, r in sorted(rows.items(), key=lambda kv: kv[1]["distinct"]):
            verdict = "MUSH " if r["distinct"] < 9 else ("weak " if r["distinct"] < 14 else "ok   ")
            print(f"    {verdict} {n:<13} distinct {r['distinct']:>6}  "
                  f"(nearest twin: {r['nearest']:<12}) detail {r['detail']:>6}")

    Path(a.out).write_text(json.dumps(report, indent=2))
    print(f"\n  wrote {a.out}")


if __name__ == "__main__":
    main()
