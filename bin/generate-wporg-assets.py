#!/usr/bin/env python3
"""Build .wordpress-org/ plugin-directory assets from existing brand + screenshots.

Reuses assets/icon.svg (the Agent Builder mark). Banners are composed with
Pillow using that mark plus colours already used in the mark and admin.css:

  #5b56db / #ab4ff3  mark gradient (assets/icon.svg)
  #1d2327            admin body text
  #646970            admin muted text
  #ffffff            admin card / page white

Icons and banners are generated here. Screenshots are recaptured from the
live admin by bin/screenshot-wporg.js into .wordpress-org/screenshot-N.png
(readme.txt == Screenshots == order) so a banner regen does not clobber them.

Requires: cairosvg, Pillow, Noto Sans (Regular + Bold) on the generator host.
Outputs are committed so reviewers do not need those tools.
"""
from __future__ import annotations

import shutil
import sys
from io import BytesIO
from pathlib import Path

try:
    import cairosvg
except ImportError:
    sys.exit("cairosvg is required to rasterize assets/icon.svg (pip install cairosvg)")

try:
    from PIL import Image, ImageDraw, ImageFont
except ImportError:
    sys.exit("Pillow is required to compose banners (pip install Pillow)")

ROOT = Path(__file__).resolve().parent.parent
ASSETS = ROOT / ".wordpress-org"
MARK_SVG = ROOT / "assets/icon.svg"

# Screenshots live in .wordpress-org/screenshot-N.png and are recaptured from
# the live admin by `bin/screenshot-wporg.js` (not copied from screenshots/).
# Running this generator must not clobber those captures with older m3-polish
# files that still include the host Apple Pay notice.
SCREENSHOT_COUNT = 11

BRAND_LEFT = (91, 86, 219)    # #5b56db
BRAND_RIGHT = (171, 79, 243)  # #ab4ff3
TEXT = (29, 35, 39)           # #1d2327
MUTED = (100, 105, 112)       # #646970
WHITE = (255, 255, 255)
FONT_BOLD = "/usr/share/fonts/truetype/noto/NotoSans-Bold.ttf"
FONT_REG = "/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf"


def raster_mark(px: int) -> Image.Image:
    png = cairosvg.svg2png(
        url=str(MARK_SVG),
        output_width=px,
        output_height=px,
    )
    return Image.open(BytesIO(png)).convert("RGBA")


def write_icon_png(mark: Image.Image, size: int, dest: Path) -> None:
    canvas = Image.new("RGBA", (size, size), (*WHITE, 255))
    pad = int(size * 0.08)
    inner = size - 2 * pad
    scaled = mark.resize((inner, inner), Image.Resampling.LANCZOS)
    canvas.alpha_composite(scaled, (pad, pad))
    canvas.convert("RGB").save(dest, "PNG", optimize=True)


def write_banners(mark: Image.Image) -> None:
    width, height = 1544, 500
    banner = Image.new("RGB", (width, height), WHITE)
    draw = ImageDraw.Draw(banner)

    bar = 10
    for x in range(width):
        t = x / (width - 1)
        colour = tuple(
            int(a + (b - a) * t) for a, b in zip(BRAND_LEFT, BRAND_RIGHT)
        )
        draw.line([(x, 0), (x, bar - 1)], fill=colour)

    icon_size = 320
    icon = mark.resize((icon_size, icon_size), Image.Resampling.LANCZOS)
    banner.paste(icon, (90, (height - icon_size) // 2 + 8), icon)

    bold = ImageFont.truetype(FONT_BOLD, 84)
    regular = ImageFont.truetype(FONT_REG, 32)
    text_x = 430
    draw.text((text_x, 168), "Agent Builder", font=bold, fill=TEXT)
    draw.text(
        (text_x, 278),
        "AI agents for WordPress, with built-in safety.",
        font=regular,
        fill=MUTED,
    )

    hi = ASSETS / "banner-1544x500.png"
    lo = ASSETS / "banner-772x250.png"
    banner.save(hi, "PNG", optimize=True)
    banner.resize((772, 250), Image.Resampling.LANCZOS).save(lo, "PNG", optimize=True)


def copy_screenshots() -> None:
    """Keep live WP.org screenshots. Recapture with bin/screenshot-wporg.js."""
    for index in range(1, SCREENSHOT_COUNT + 1):
        dest = ASSETS / f"screenshot-{index}.png"
        if not dest.is_file():
            sys.exit(
                f"Missing {dest.name}; recapture with bin/screenshot-wporg.js"
            )
        print(f"keep {dest.name}")


def main() -> None:
    if not MARK_SVG.is_file():
        sys.exit(f"Missing brand mark: {MARK_SVG}")
    for path in (FONT_BOLD, FONT_REG):
        if not Path(path).is_file():
            sys.exit(f"Missing font: {path}")

    ASSETS.mkdir(parents=True, exist_ok=True)
    shutil.copy2(MARK_SVG, ASSETS / "icon.svg")

    mark = raster_mark(512)
    write_icon_png(mark, 256, ASSETS / "icon-256x256.png")
    write_icon_png(mark, 128, ASSETS / "icon-128x128.png")
    write_banners(mark)
    copy_screenshots()

    print("wrote", ASSETS)


if __name__ == "__main__":
    main()
