"""Erzeugt das Logo und die LoxBerry-Plugin-Icons im Stil von Senec2Lox / Nut2Lox.

Aufruf (aus dem Repo-Ordner):  python tools/make_logo.py
Benoetigt Pillow. Schrift: Arial Bold (Windows) bzw. DejaVu Sans Bold.

Ausgabe:
  docs/logo/lox2pvoutput.png                      gruener, runder LoxBerry-Stil
  lox2pvoutput/icons/icon_{64,128,256,512}.png     dasselbe als Plugin-Icons

Links der Miniserver (eigene, vereinfachte Darstellung ohne Herstellerlogo, wie bei Senec2Lox),
rechts ein Solarmodul mit Sonne (PVOutput), dazwischen ein Pfeil, darunter der Name "Lox2PVOutput".
"""
import math
import os
from PIL import Image, ImageDraw, ImageFont, ImageOps

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SS = 4  # Supersampling

G_LIGHT, G_DARK = (141, 198, 63), (63, 125, 16)        # Gruenverlauf
LABEL = "Lox2PVOutput"
LABEL_WIDTH = 330      # Breite der Beschriftung im 512er-Raster
LABEL_BASE = 352       # Grundlinie
OX, OY = 0, 0          # Verschiebung der ganzen Gruppe (Geraete + Pfeil + Name); wird unten aus den Umrissen berechnet

MINISERVER = (86, 152, 242, 240)           # x1, y1, x2, y2 (gruenes Gehaeuse, Draufsicht)
PANEL = (296, 142, 414, 246)               # Solarmodul
LINK_Y = 196                               # Verbindungslinie (Mitte beider Geraete)
LINK_X = (252, 288)


def miniserver_shapes():
    """Miniserver als gruenes Gehaeuse (Draufsicht), identisch zu Senec2Lox.

    Formen: ("rrect", x1, y1, x2, y2, radius, rgba) | ("circle", cx, cy, r, rgba) | ("line", x1, y1, x2, y2, breite, rgba)
    """
    x1, y1, x2, y2 = MINISERVER
    white = (255, 255, 255, 255)
    green = (104, 190, 64, 255)
    dark = (38, 48, 38, 255)
    teal, orange, blue = (38, 172, 172, 255), (242, 140, 28, 255), (40, 120, 200, 255)
    red = (226, 42, 72, 255)
    sh = []
    sh.append(("rrect", x1 + 70, y1 - 15, x1 + 98, y1 + 6, 0, white))
    sh.append(("rrect", x1 + 72, y1 - 13, x1 + 96, y1 + 6, 0, teal))
    sh.append(("rrect", x1 + 116, y1 - 15, x1 + 134, y1 + 6, 0, white))
    sh.append(("rrect", x1 + 118, y1 - 13, x1 + 132, y1 + 6, 0, orange))
    for i, c in enumerate((orange, blue, white)):
        tx = x1 + 12 + i * 15
        sh.append(("rrect", tx - 2, y2 - 4, tx + 10, y2 + 16, 0, white))
        sh.append(("rrect", tx, y2 - 4, tx + 8, y2 + 14, 0, c))
    sh.append(("rrect", x1 + 78, y2 - 6, x2 - 6, y2 + 12, 0, white))
    sh.append(("rrect", x1 + 80, y2 - 4, x2 - 8, y2 + 10, 0, red))
    for i in range(12):
        sh.append(("circle", x1 + 88 + i * 5.6, y2 + 3, 1.6, white))
    sh.append(("rrect", x1 - 3, y1 - 3, x2 + 3, y2 + 3, 2, white))
    sh.append(("rrect", x1, y1, x2, y2, 0, green))
    sh.append(("rrect", x1 + 52, y1 + 36, x2 - 14, y1 + 46, 0, dark))
    sh.append(("rrect", x1 + 60, y1 + 39, x1 + 92, y1 + 43, 0, (200, 210, 200, 255)))
    sh.append(("rrect", x2 - 50, y1 + 39, x2 - 22, y1 + 43, 0, (120, 135, 120, 255)))
    return sh


def panel_shapes():
    """Solarmodul: weisser Rahmen, dunkelblaue Zellen (3 x 3), Sonne mit Strahlen an der oberen rechten Ecke."""
    x1, y1, x2, y2 = PANEL
    white = (255, 255, 255, 255)
    cell = (28, 66, 138, 255)
    glint = (92, 140, 214, 255)
    sh = [("rrect", x1, y1, x2, y2, 6, white)]
    pad, gap = 6, 3
    cols, rows = 3, 3
    cw = (x2 - x1 - 2 * pad - (cols - 1) * gap) / cols
    ch = (y2 - y1 - 2 * pad - (rows - 1) * gap) / rows
    for r in range(rows):
        for c in range(cols):
            ax = x1 + pad + c * (cw + gap)
            ay = y1 + pad + r * (ch + gap)
            sh.append(("rrect", ax, ay, ax + cw, ay + ch, 2, cell))
            sh.append(("rrect", ax + 3, ay + 3, ax + cw * 0.55, ay + 6, 1, glint))     # Lichtreflex je Zelle
    # Sonne an der oberen rechten Ecke (ragt aus dem Modul heraus)
    sx, sy, rr = x2 - 6, y1 - 6, 17
    sun = (255, 214, 64, 255)
    for k in range(8):
        a = math.radians(k * 45 + 22.5)
        sh.append(("line", sx + math.cos(a) * (rr + 4), sy + math.sin(a) * (rr + 4),
                   sx + math.cos(a) * (rr + 13), sy + math.sin(a) * (rr + 13), 4, sun))
    sh.append(("circle", sx, sy, rr + 3, white))
    sh.append(("circle", sx, sy, rr, sun))
    return sh


def _font(px: int) -> ImageFont.FreeTypeFont:
    for path in (r"C:\Windows\Fonts\arialbd.ttf", "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
                 "/Library/Fonts/Arial Bold.ttf"):
        if os.path.exists(path):
            return ImageFont.truetype(path, px)
    return ImageFont.load_default()


def label_px() -> float:
    """Schriftgroesse, bei der LABEL genau LABEL_WIDTH breit ist."""
    f = _font(200)
    try:
        w = ImageDraw.Draw(Image.new("L", (10, 10))).textlength(LABEL, font=f)
    except Exception:
        return 60.0
    return 200 * LABEL_WIDTH / w if w else 60.0


def content_bbox():
    """Umriss aller gezeichneten Elemente (Geraete, Pfeil, Beschriftung) im 512er-Raster, ohne Verschiebung."""
    xs, ys = [], []
    for sh in miniserver_shapes() + panel_shapes():
        if sh[0] == "rrect":
            xs += [sh[1], sh[3]]
            ys += [sh[2], sh[4]]
        elif sh[0] == "circle":
            xs += [sh[1] - sh[3], sh[1] + sh[3]]
            ys += [sh[2] - sh[3], sh[2] + sh[3]]
        else:                                           # Linie (Sonnenstrahlen)
            half = sh[5] / 2
            xs += [min(sh[1], sh[3]) - half, max(sh[1], sh[3]) + half]
            ys += [min(sh[2], sh[4]) - half, max(sh[2], sh[4]) + half]
    xs += [LINK_X[0], LINK_X[1] + 8]                    # Pfeil samt Spitze
    ys += [LINK_Y - 11, LINK_Y + 11]
    f = _font(int(label_px()))
    l, t, r, b = ImageDraw.Draw(Image.new("L", (10, 10))).textbbox((256, LABEL_BASE), LABEL, font=f, anchor="ms")
    xs += [l, r]
    ys += [t, b]
    return min(xs), min(ys), max(xs), max(ys)


def centered_offset():
    """Verschiebung, die die Mitte des Umrisses auf die Kreismitte (256, 256) legt."""
    x1, y1, x2, y2 = content_bbox()
    return round(256 - (x1 + x2) / 2, 1), round(256 - (y1 + y2) / 2, 1)


OX, OY = centered_offset()


def render(size: int) -> Image.Image:
    k = size * SS / 512.0
    S = size * SS
    D = int(480 * k)
    off = int(16 * k)

    # Kreis mit Radialverlauf (Lichtpunkt oben links)
    g = Image.radial_gradient("L").resize((int(1.8 * D), int(1.8 * D)), Image.BICUBIC)
    left, top = int(0.9 * D - 0.40 * D), int(0.9 * D - 0.34 * D)
    g = g.crop((left, top, left + D, top + D))
    disc = ImageOps.colorize(g, G_LIGHT, G_DARK).convert("RGBA")
    mask = Image.new("L", (D, D), 0)
    ImageDraw.Draw(mask).ellipse((0, 0, D - 1, D - 1), fill=255)
    img = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    img.paste(disc, (off, off), mask)

    ov = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    d = ImageDraw.Draw(ov)

    def r(x1, y1, x2, y2):
        return [x1 * k, y1 * k, x2 * k, y2 * k]

    # dezenter Glanz oben und feiner Innenring
    d.ellipse(r(60, 24, 452, 250), fill=(255, 255, 255, 34))
    d.ellipse(r(34, 34, 478, 478), outline=(255, 255, 255, 70), width=max(1, int(3 * k)))

    def draw(shapes):
        for sh in shapes:
            if sh[0] == "rrect":
                _, x1, y1, x2, y2, rad, c = sh
                d.rounded_rectangle(r(x1 + OX, y1 + OY, x2 + OX, y2 + OY), radius=rad * k, fill=c)
            elif sh[0] == "circle":
                _, cx, cy, rr, c = sh
                d.ellipse(r(cx - rr + OX, cy - rr + OY, cx + rr + OX, cy + rr + OY), fill=c)
            else:
                _, x1, y1, x2, y2, w, c = sh
                d.line([((x1 + OX) * k, (y1 + OY) * k), ((x2 + OX) * k, (y2 + OY) * k)], fill=c, width=max(1, int(w * k)))

    draw(miniserver_shapes())
    draw(panel_shapes())
    # Verbindung mit Pfeilspitze (Miniserver -> PVOutput)
    ly = (LINK_Y + OY) * k
    d.line([((LINK_X[0] + OX) * k, ly), ((LINK_X[1] - 4 + OX) * k, ly)], fill="white", width=int(7 * k))
    d.polygon([((LINK_X[1] + 8 + OX) * k, ly), ((LINK_X[1] - 6 + OX) * k, ly - 11 * k),
               ((LINK_X[1] - 6 + OX) * k, ly + 11 * k)], fill="white")
    # Beschriftung: Name in einer Zeile
    font = _font(int(label_px() * k))
    d.text((256 * k, (LABEL_BASE + OY) * k), LABEL, font=font, fill="white", anchor="ms")
    img = Image.alpha_composite(img, ov)
    return img.resize((size, size), Image.LANCZOS)


if __name__ == "__main__":
    logo_dir = os.path.join(ROOT, "docs", "logo")
    icon_dir = os.path.join(ROOT, "lox2pvoutput", "icons")
    os.makedirs(logo_dir, exist_ok=True)
    os.makedirs(icon_dir, exist_ok=True)
    render(512).save(os.path.join(logo_dir, "lox2pvoutput.png"))
    for s in (64, 128, 256, 512):
        render(s).save(os.path.join(icon_dir, "icon_%d.png" % s))
    print("Logo und Icons geschrieben")
