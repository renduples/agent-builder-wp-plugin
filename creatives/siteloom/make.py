from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
import os
F = "./fonts/"
fonts = {"bold": TTFont(F + "NotoSans-Bold.ttf"), "reg": TTFont(F + "NotoSans-Regular.ttf")}

def text_path(text, font, size, x, y, tracking=0):
    f = fonts[font]; gs = f.getGlyphSet(); cmap = f.getBestCmap(); upm = f["head"].unitsPerEm
    s = size / upm; pen = SVGPathPen(gs); cx = 0.0
    for ch in text:
        gn = cmap[ord(ch)]
        tp = TransformPen(pen, (s, 0, 0, -s, x + cx, y))
        gs[gn].draw(tp)
        cx += gs[gn].width * s + tracking
    return pen.getCommands(), cx

def width(text, font, size, tracking=0):
    return text_path(text, font, size, 0, 0, tracking)[1]

GRAD = '<linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0.15" stop-color="#5b56db"/><stop offset="1" stop-color="#ab4ff3"/></linearGradient>'

def mark(scale=1.0, tx=0, ty=0, bg=True, uid="m"):
    # Loom: three warp threads (the site) with one S-shaped weft (the agents) weaving over and under.
    weft = "M46 84 H180 A22 22 0 0 1 180 128 H76 A22 22 0 0 0 76 172 H210"
    warps = (96, 128, 160)
    gaps = [(96, 84), (160, 84), (128, 128), (96, 172), (160, 172)]  # warp passes over the weft
    mask = "".join(f'<rect x="{x-10}" y="{y-12}" width="20" height="24" fill="black"/>' for x, y in gaps)
    if bg:
        col, inner = "#ffffff", "url(#g)"
        body = [f'<rect width="256" height="256" rx="56" fill="url(#g)"/>']
    else:
        col, inner = "url(#g)", "#ffffff"
        body = []
    for x in warps:
        body.append(f'<line x1="{x}" y1="50" x2="{x}" y2="206" stroke="{col}" stroke-opacity="0.92" stroke-width="10" stroke-linecap="round"/>')
    body.append(f'<mask id="{uid}k" maskUnits="userSpaceOnUse" x="0" y="0" width="256" height="256"><rect width="256" height="256" fill="white"/>{mask}</mask>')
    body.append(f'<path d="{weft}" fill="none" stroke="{col}" stroke-width="15" stroke-linecap="round" stroke-linejoin="round" mask="url(#{uid}k)"/>')
    for x, y in ((46, 84), (210, 172)):
        body.append(f'<circle cx="{x}" cy="{y}" r="16" fill="{col}"/><circle cx="{x}" cy="{y}" r="7" fill="{inner}"/>')
    return f'<g transform="translate({tx} {ty}) scale({scale})">' + "".join(body) + "</g>"

def svg(w, h, inner):
    return f'<svg xmlns="http://www.w3.org/2000/svg" width="{w}" height="{h}" viewBox="0 0 {w} {h}"><defs>{GRAD}</defs>{inner}</svg>'

open("./icon.svg", "w").write(svg(256, 256, mark()))

def banner(W, H):
    k = W / 1544
    inner = [f'<rect width="{W}" height="{H}" fill="#ffffff"/>', f'<rect width="{W}" height="{10*k}" fill="url(#gb)"/>']
    ms = 270 * k
    inner.append(mark(scale=ms / 256, tx=100 * k, ty=(H - ms) / 2 + 6 * k, uid="b"))
    tx = 100 * k + ms + 64 * k
    t1, w1 = text_path("Siteloom", "bold", 94 * k, tx, 248 * k, -1 * k)
    t2, w2 = text_path("AI Agents", "bold", 94 * k, tx + w1 + 28 * k, 248 * k, -1 * k)
    t3, _ = text_path("AI agents that do real work on your site, with approvals built in.", "reg", 30 * k, tx + 3 * k, 312 * k)
    inner.append(f'<path d="{t1}" fill="#1f2328"/><path d="{t2}" fill="url(#gt)"/><path d="{t3}" fill="#5a6370"/>')
    defs = (f'<linearGradient id="gb" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#5b56db"/><stop offset="1" stop-color="#ab4ff3"/></linearGradient>'
            f'<linearGradient id="gt" gradientUnits="userSpaceOnUse" x1="{tx + w1}" y1="0" x2="{tx + w1 + w2 + 34*k}" y2="0"><stop offset="0" stop-color="#5b56db"/><stop offset="1" stop-color="#ab4ff3"/></linearGradient>')
    return f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" viewBox="0 0 {W} {H}"><defs>{GRAD}{defs}</defs>' + "".join(inner) + "</svg>", tx + w1 + 28 * k + w2

for W, H in ((1544, 500), (772, 250)):
    s, right = banner(W, H)
    open(f"./banner-{W}x{H}.svg", "w").write(s)
    print(W, "text right edge", round(right), "of", W)
