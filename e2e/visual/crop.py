#!/usr/bin/env python3
"""Crop a region out of a sweep screenshot so it can be inspected at real size.

A full-page shot of a 12000px discussion is unreadable when scaled to fit; the
defects the operator sees live in a 600px band. Usage:

    crop.py in.png out.png X Y W H [SCALE]
"""
import sys
from PIL import Image

src, dst, x, y, w, h = sys.argv[1], sys.argv[2], *map(int, sys.argv[3:7])
scale = float(sys.argv[7]) if len(sys.argv) > 7 else 1.0
im = Image.open(src)
x2, y2 = min(x + w, im.width), min(y + h, im.height)
box = im.crop((max(0, x), max(0, y), x2, y2))
if scale != 1.0:
    box = box.resize((int(box.width * scale), int(box.height * scale)), Image.LANCZOS)
box.save(dst)
print(f"{dst} {box.width}x{box.height} from {im.width}x{im.height}")
