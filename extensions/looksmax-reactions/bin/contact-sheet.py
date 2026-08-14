#!/usr/bin/env python3
"""
Render the processed icons the way they will actually be seen, so a human can
judge them instead of trusting the pipeline's own numbers.

Two sheets, both on the forum's real post surface (#161d28), because the whole
point of cutting the background was that these land on a dark card:

  sizes.png   every icon at 20/24/32/48px, drawn 1:1 AND magnified 6x with
              nearest-neighbour so the actual delivered pixels are visible.
              This is the sheet that answers "which faces survive at 20px".

  edges.png   the masters composited over magenta and over white. A white
              fringe on magenta means un-premultiplication failed; a magenta
              fringe on white means alpha is too aggressive. Either is a defect
              you cannot see on a dark background but users on light mode can.
"""
from __future__ import annotations

import argparse
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

SURFACE = (0x16, 0x1D, 0x28, 255)
INK = (0xEE, 0xF2, 0xF7, 255)
DIM = (0xAA, 0xB5, 0xC6, 255)


def font(sz: int):
    for p in (
        "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
        "/usr/share/fonts/TTF/DejaVuSans.ttf",
        "/usr/share/fonts/dejavu/DejaVuSans.ttf",
    ):
        if Path(p).exists():
            return ImageFont.truetype(p, sz)
    return ImageFont.load_default()


def sizes_sheet(build: Path, out: Path, sizes=(20, 24, 32, 48), zoom=6) -> None:
    names = sorted(p.stem for p in (build / "master").glob("*.png") if ".square" not in p.name)
    f = font(15)
    fs = font(12)

    label_w = 130
    cellw = max(s * zoom for s in sizes) + 24
    colw = [s * zoom + 28 for s in sizes]
    oneone_w = sum(sizes) + len(sizes) * 14 + 24
    W = label_w + sum(colw) + oneone_w + 40
    rowh = max(sizes) * zoom + 26
    H = 52 + rowh * len(names)

    img = Image.new("RGBA", (W, H), SURFACE)
    d = ImageDraw.Draw(img)
    d.text((16, 16), "chrigger reaction icons on --surface-1 #161d28", INK, font=f)

    x = label_w
    for i, s in enumerate(sizes):
        d.text((x + 4, 34), f"{s}px @{zoom}x", DIM, font=fs)
        x += colw[i]
    d.text((x + 4, 34), "1:1 as delivered", DIM, font=fs)

    y = 52
    for n in names:
        d.text((14, y + rowh // 2 - 8), n, INK, font=f)
        x = label_w
        for i, s in enumerate(sizes):
            ic = Image.open(build / str(s) / f"{n}.png").convert("RGBA")
            big = ic.resize((ic.width * zoom, ic.height * zoom), Image.NEAREST)
            img.alpha_composite(big, (x + 8, y + 8))
            x += colw[i]
        # the 1:1 row: this is the honest one
        xx = x + 12
        for s in sizes:
            ic = Image.open(build / str(s) / f"{n}.png").convert("RGBA")
            img.alpha_composite(ic, (xx, y + rowh // 2 - ic.height // 2))
            xx += ic.width + 14
        d.line([(0, y), (W, y)], fill=(0x2F, 0x3B, 0x4C, 255))
        y += rowh

    img.convert("RGB").save(out, "PNG")
    print(f"  {out}  {img.size[0]}x{img.size[1]}")


def edges_sheet(build: Path, out: Path, side=190) -> None:
    names = sorted(p.stem for p in (build / "master").glob("*.png") if ".square" not in p.name)
    f = font(13)
    cols = len(names)
    W = cols * (side + 8) + 8
    H = side * 3 + 70
    img = Image.new("RGBA", (W, H), (0, 0, 0, 255))
    d = ImageDraw.Draw(img)
    for i, n in enumerate(names):
        ic = Image.open(build / "master" / f"{n}.png").convert("RGBA").resize((side, side), Image.LANCZOS)
        x = 8 + i * (side + 8)
        d.text((x, 6), n, (255, 255, 255, 255), font=f)
        for j, bg in enumerate([(255, 0, 255, 255), (255, 255, 255, 255), SURFACE]):
            tile = Image.new("RGBA", (side, side), bg)
            tile.alpha_composite(ic)
            img.paste(tile, (x, 24 + j * side))
    d.text((8, 24 + 3 * side + 6), "rows: magenta (white fringe = bad un-premultiply) / white (magenta fringe = alpha too hot) / surface-1", (200, 200, 200, 255), font=f)
    img.convert("RGB").save(out, "PNG")
    print(f"  {out}  {img.size[0]}x{img.size[1]}")


def strip_sheet(build: Path, out: Path) -> None:
    """What a real reaction strip looks like: chips at 20px with a count."""
    names = sorted(p.stem for p in (build / "master").glob("*.png") if ".square" not in p.name)
    f = font(12)
    W, H = 1100, 60 * 2 + 20
    img = Image.new("RGBA", (W, H), SURFACE)
    d = ImageDraw.Draw(img)
    for row, sz in enumerate((20, 24)):
        y = 14 + row * 60
        x = 16
        for i, n in enumerate(names):
            ic = Image.open(build / str(sz) / f"{n}.png").convert("RGBA")
            cw = ic.width + 30
            d.rounded_rectangle([x, y, x + cw, y + sz + 12], radius=(sz + 12) // 2,
                                fill=(0x1E, 0x28, 0x36, 255), outline=(0x2F, 0x3B, 0x4C, 255))
            img.alpha_composite(ic, (x + 7, y + 6))
            d.text((x + ic.width + 11, y + sz // 2 - 2), str((i * 7) % 40 + 1), (0xAA, 0xB5, 0xC6, 255), font=f)
            x += cw + 8
            if x > W - 70:
                break
    img.convert("RGB").save(out, "PNG")
    print(f"  {out}  {img.size[0]}x{img.size[1]}")


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--build", required=True)
    ap.add_argument("--out", required=True)
    a = ap.parse_args()
    b, o = Path(a.build), Path(a.out)
    o.mkdir(parents=True, exist_ok=True)
    sizes_sheet(b, o / "sizes.png")
    edges_sheet(b, o / "edges.png")
    strip_sheet(b, o / "strip.png")
