#!/usr/bin/env python3
"""
Turn the chrigger source art into reaction icons.

The source is 13 PNGs, 992x1056, ~1.5MB each: one cartoon face per expression,
drawn on a near-white background. None of that is usable in a reaction strip,
and the reasons are worth writing down because they dictate the method.

  1. The background is NOT uniform white. Sampling the border ring gives a
     luminance range of 198..255 across the set (devil bottoms out at RGB
     205,203,198; nerd at 211,210,206; happy at 214,215,210). There is a soft
     vignette/drop-shadow behind the figure. A flat colour key -- "make white
     transparent, fuzz 5%" -- therefore leaves a grey halo everywhere the
     vignette is darker than the fuzz, and widening the fuzz until the halo
     goes starts eating the light parts of the drawing.

  2. The art is not flat. 27k-86k unique colours for cartoon line art means it
     went through JPEG at some point. So the "white" is speckled and any
     per-pixel threshold produces confetti at the edges.

  3. thinking.png has artwork touching the frame (a border pixel reads
     212,155,127 -- skin). Anything that assumes a clean margin is wrong.

  4. The figures contain large light regions -- eye whites, teeth, the halo on
     angel -- that a global "light means background" rule would punch holes in.

So the method is:

  * classify background by CONNECTIVITY, not by colour alone. Label every pixel
    that is plausibly background (light and desaturated, generous thresholds),
    keep only the components that touch the image border. Eye whites are
    enclosed by the outline, so they are never in a border-touching component.

  * estimate the background as a smooth FIELD rather than a constant. Normalised
    convolution over the background mask gives B(x,y), which follows the
    vignette. Alpha then comes from how far a pixel is from its LOCAL
    background, so the vignette dissolves to zero and a dark edge does not.

  * only soften alpha inside the background region dilated by a few pixels.
    Everywhere else alpha is 1. That is what keeps the interior solid while
    still giving genuinely antialiased edges instead of a jagged 1-bit cut.

  * un-premultiply the edge pixels against the local background. Without this
    every antialiased pixel keeps the white it was blended with and the icon
    wears a white fringe on a dark surface -- which is exactly the failure the
    transparency was supposed to fix.

  * resize on PREMULTIPLIED alpha. Lanczos over straight alpha mixes the colour
    of fully transparent pixels into the visible ones; on a white-matted source
    that is a bright halo at every size.

Run:  ./.venv/bin/python process-assets.py --src SRC --out OUT
"""
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
from pathlib import Path

import numpy as np
from PIL import Image
from scipy import ndimage

# --- background classification -------------------------------------------
# Deliberately generous: a pixel only becomes background if it ALSO connects to
# the frame, so false positives in the interior are harmless.
BG_LUMA = 196.0     # darkest ring pixel measured across the set was ~198
BG_SAT = 30.0       # max(rgb)-min(rgb); the vignette is grey, the art is not
BAND = 4            # px of dilation: the antialiased edge lives just outside
FIELD_SIGMA = 48.0  # background field smoothness, in source pixels
DELTA = 46.0        # luma below local background at which a pixel is fully opaque
ALPHA_FLOOR = 0.02  # below this, call it empty rather than un-premultiplying noise

SIZES = [20, 24, 32, 40, 48, 64, 96, 128, 256]
AVATAR_SIZES = [128, 256, 512]
SPECK_FRAC = 2e-4   # components smaller than this fraction of the frame are noise


def luma(rgb: np.ndarray) -> np.ndarray:
    return 0.2126 * rgb[..., 0] + 0.7152 * rgb[..., 1] + 0.0722 * rgb[..., 2]


def background_mask(rgb: np.ndarray) -> tuple[np.ndarray, int, int]:
    """Border-connected light+desaturated region. Returns (mask, kept, total)."""
    L = luma(rgb)
    sat = rgb.max(axis=2) - rgb.min(axis=2)
    plausible = (L >= BG_LUMA) & (sat <= BG_SAT)

    lab, n = ndimage.label(plausible)
    border = np.concatenate([lab[0, :], lab[-1, :], lab[:, 0], lab[:, -1]])
    keep = set(int(v) for v in np.unique(border) if v != 0)
    mask = np.isin(lab, list(keep)) if keep else np.zeros_like(plausible)
    return mask, len(keep), n


def background_field(rgb: np.ndarray, mask: np.ndarray) -> np.ndarray:
    """Normalised convolution: smooth the background over itself, inpainting
    across the figure so every pixel has a local background estimate."""
    m = mask.astype(np.float64)
    denom = ndimage.gaussian_filter(m, FIELD_SIGMA, mode="nearest")
    denom = np.maximum(denom, 1e-6)
    out = np.empty_like(rgb)
    for c in range(3):
        num = ndimage.gaussian_filter(rgb[..., c] * m, FIELD_SIGMA, mode="nearest")
        out[..., c] = num / denom
    return out


def cut(path: Path) -> tuple[Image.Image, dict]:
    im = Image.open(path).convert("RGBA")
    rgb = np.asarray(im, dtype=np.float64)[..., :3]
    h, w = rgb.shape[:2]

    mask, kept, total = background_mask(rgb)
    field = background_field(rgb, mask)
    Lb = luma(field)
    L = luma(rgb)

    band = ndimage.binary_dilation(mask, iterations=BAND)

    alpha = np.ones((h, w), dtype=np.float64)
    soft = np.clip((Lb - L) / DELTA, 0.0, 1.0)
    alpha[band] = soft[band]
    alpha[alpha < ALPHA_FLOOR] = 0.0

    # un-premultiply the transitional pixels against their local background so
    # the white they were blended with does not survive onto a dark surface
    out = rgb.copy()
    tr = (alpha > 0.0) & (alpha < 1.0)
    a = alpha[tr][:, None]
    out[tr] = np.clip((rgb[tr] - (1.0 - a) * field[tr]) / a, 0.0, 255.0)

    # Speck removal, and it is not cosmetic. The JPEG history leaves isolated
    # pixels a few levels darker than their neighbours; each one survives the
    # alpha rule and one of them in a corner defeats the trim entirely. First
    # pass produced eight icons "trimmed" to the full 992x1056 frame with the
    # face occupying half of it, which is most of why 20px looked like mush.
    solid = alpha > 0.10
    lab, n = ndimage.label(solid)
    if n:
        areas = np.bincount(lab.ravel())
        areas[0] = 0
        tiny = np.flatnonzero(areas < SPECK_FRAC * w * h)
        if len(tiny):
            killed = np.isin(lab, tiny)
            alpha[killed] = 0.0
            solid &= ~killed
        specks = int(len(tiny))
    else:
        specks = 0

    rgba = np.dstack([out, alpha * 255.0]).astype(np.uint8)
    img = Image.fromarray(rgba, "RGBA")

    ys, xs = np.nonzero(solid)
    if len(xs) == 0:
        raise SystemExit(f"{path.name}: nothing survived the cut")
    x0, x1, y0, y1 = int(xs.min()), int(xs.max()) + 1, int(ys.min()), int(ys.max()) + 1
    img = img.crop((x0, y0, x1, y1))

    stats = {
        "source_px": [w, h],
        "trimmed_px": list(img.size),
        "trim_saved_pct": round(100 * (1 - (img.size[0] * img.size[1]) / (w * h)), 1),
        "aspect": round(img.size[0] / img.size[1], 3),
        "specks_removed": specks,
        "bg_components_kept": kept,
        "bg_components_total": total,
        "bg_coverage": round(float(mask.mean()), 4),
        "opaque_coverage": round(float((alpha > 0.5).mean()), 4),
        "ring_luma_min": round(float(min(L[0].min(), L[-1].min(), L[:, 0].min(), L[:, -1].min())), 1),
    }
    return img, stats


def squareify(img: Image.Image, pad_frac: float = 0.02) -> Image.Image:
    """Centre on a square canvas. Used for the AVATAR only. Deliberately NOT
    used for the reaction icons: these faces are portrait (aspect ~0.8), so
    padding them to square spends 20% of a 20px-tall chip on empty pixels and
    shrinks the face by the same amount. The strip constrains height, not
    width, so the icons ship at their native aspect and `width: auto`."""
    w, h = img.size
    side = int(round(max(w, h) * (1 + 2 * pad_frac)))
    canvas = Image.new("RGBA", (side, side), (0, 0, 0, 0))
    canvas.paste(img, ((side - w) // 2, (side - h) // 2))
    return canvas


def resize_premultiplied(img: Image.Image, size: tuple[int, int]) -> Image.Image:
    """Lanczos over straight alpha drags the colour of transparent pixels into
    the visible ones. Premultiply, resize, un-premultiply."""
    a = np.asarray(img, dtype=np.float64)
    al = a[..., 3:4] / 255.0
    pre = np.dstack([a[..., :3] * al, a[..., 3]])
    small = np.asarray(
        Image.fromarray(np.clip(pre, 0, 255).astype(np.uint8), "RGBA").resize(
            size, Image.LANCZOS
        ),
        dtype=np.float64,
    )
    sa = small[..., 3:4] / 255.0
    rgb = np.where(sa > 1e-4, small[..., :3] / np.maximum(sa, 1e-4), 0.0)
    return Image.fromarray(
        np.dstack([np.clip(rgb, 0, 255), small[..., 3]]).astype(np.uint8), "RGBA"
    )


def sharpen(img: Image.Image, amount: float) -> Image.Image:
    """A face that is legible at 992px is mush at 24px: the downscale is a
    low-pass filter and the eyes and mouth are the high frequencies. Unsharp on
    the premultiplied colour only -- sharpening alpha produces ringing at the
    silhouette that reads as a dotted outline."""
    if amount <= 0:
        return img
    from PIL import ImageFilter

    a = np.asarray(img, dtype=np.uint8)
    rgb = Image.fromarray(a[..., :3], "RGB").filter(
        ImageFilter.UnsharpMask(radius=1.0, percent=int(amount * 100), threshold=0)
    )
    return Image.fromarray(np.dstack([np.asarray(rgb), a[..., 3]]), "RGBA")


def encode(img: Image.Image, stem: Path, lossless: bool) -> dict[str, int]:
    """PNG + WebP + AVIF, then report the byte cost of each so the choice of
    what to ship is measured rather than assumed."""
    out: dict[str, int] = {}

    png = stem.with_suffix(".png")
    img.save(png, "PNG", optimize=True)
    out["png"] = png.stat().st_size

    webp = stem.with_suffix(".webp")
    if lossless:
        img.save(webp, "WEBP", lossless=True, quality=100, method=6, exact=True)
    else:
        img.save(webp, "WEBP", lossless=False, quality=90, method=6)
    out["webp"] = webp.stat().st_size

    avif = stem.with_suffix(".avif")
    r = subprocess.run(
        ["magick", str(png), "-quality", "80" if not lossless else "100", str(avif)],
        capture_output=True,
    )
    out["avif"] = avif.stat().st_size if avif.exists() and r.returncode == 0 else -1
    return out


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--src", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--sharpen", type=float, default=0.55)
    args = ap.parse_args()

    src = Path(args.src)
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    (out / "master").mkdir(exist_ok=True)

    report: dict = {"icons": {}, "totals": {}}
    before = after_png = after_webp = after_avif = 0

    for f in sorted(src.glob("*.png")):
        name = f.stem
        src_bytes = f.stat().st_size
        before += src_bytes

        img, stats = cut(f)
        img.save(out / "master" / f"{name}.png", "PNG", optimize=True)
        squareify(img).save(out / "master" / f"{name}.square.png", "PNG", optimize=True)

        aspect = img.size[0] / img.size[1]
        per_size: dict[str, dict] = {}
        for s in SIZES:
            d = out / str(s)
            d.mkdir(exist_ok=True)
            # s is the BOX: the art is scaled to fit inside s x s at its native
            # aspect, never padded to it. Padding to square would spend a fifth
            # of a 24px chip on nothing for the portrait faces; letting the file
            # be 14x24 and giving CSS `max-width:24px;max-height:24px` gets the
            # same alignment for free and a bigger face.
            if aspect >= 1.0:
                wpx, s_h = s, max(1, int(round(s / aspect)))
            else:
                wpx, s_h = max(1, int(round(s * aspect))), s
            # small sizes lose the eyes and mouth to the low-pass; large ones
            # do not need help and oversharpening them shows as halos
            amt = args.sharpen if s <= 64 else (args.sharpen * 0.4 if s <= 128 else 0.0)
            im = sharpen(resize_premultiplied(img, (wpx, s_h)), amt)
            b = encode(im, d / name, lossless=(s <= 96))
            b["px"] = [wpx, s_h]
            per_size[str(s)] = b
            after_png += b["png"]
            after_webp += b["webp"]
            after_avif += max(b["avif"], 0)

        # the avatar wants a square, because Flarum crops avatars to a circle
        ad = out / "avatar"
        ad.mkdir(exist_ok=True)
        sq = squareify(img, pad_frac=0.04)
        for s in AVATAR_SIZES:
            encode(resize_premultiplied(sq, (s, s)), ad / f"{name}-{s}", lossless=False)

        stats["bytes_source"] = src_bytes
        stats["bytes"] = per_size
        report["icons"][name] = stats
        print(
            f"  {name:<13} {stats['source_px'][0]}x{stats['source_px'][1]} "
            f"-> trim {stats['trimmed_px'][0]}x{stats['trimmed_px'][1]} "
            f"(-{stats['trim_saved_pct']}% area, aspect {stats['aspect']}, "
            f"{stats['specks_removed']} specks)  "
            f"24px {per_size['24']['px'][0]}x24 webp {per_size['24']['webp']}B",
            flush=True,
        )

    report["totals"] = {
        "source_bytes": before,
        "png_bytes_all_sizes": after_png,
        "webp_bytes_all_sizes": after_webp,
        "avif_bytes_all_sizes": after_avif,
        "sizes": SIZES,
        "count": len(report["icons"]),
    }
    (out / "report.json").write_text(json.dumps(report, indent=2))
    print(json.dumps(report["totals"], indent=2))


if __name__ == "__main__":
    main()
