#!/usr/bin/env python3
"""Read YYYY.MM.DD HH:MM:SS from an MT5 closed-trade card (Persian or Latin digits)."""
from __future__ import annotations

import json
import sys
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw, ImageFont

FONT = "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"
CHARS = list("0123456789.:۰۱۲۳۴۵۶۷۸۹")
MAP = str.maketrans("۰۱۲۳۴۵۶۷۸۹", "0123456789")


def render_char(ch: str, font: ImageFont.FreeTypeFont, size: int = 28) -> np.ndarray:
    img = Image.new("L", (90, 90), 0)
    ImageDraw.Draw(img).text((8, 4), ch, fill=255, font=font)
    arr = np.array(img)
    ys, xs = np.where(arr > 20)
    if len(xs) == 0:
        return np.zeros((size, size), np.uint8)
    crop = arr[ys.min() : ys.max() + 1, xs.min() : xs.max() + 1]
    return np.array(Image.fromarray(crop).resize((size, size), Image.Resampling.BILINEAR))


def templates() -> dict[str, np.ndarray]:
    font = ImageFont.truetype(FONT, 46)
    return {ch: render_char(ch, font) for ch in CHARS}


def score(a: np.ndarray, b: np.ndarray) -> float:
    A = a.astype(np.float32)
    B = b.astype(np.float32)
    A = (A - A.mean()) / (A.std() + 1e-6)
    B = (B - B.mean()) / (B.std() + 1e-6)
    return float((A * B).mean())


def classify(glyph: np.ndarray, tmpl: dict[str, np.ndarray]) -> tuple[str, float]:
    g = np.array(Image.fromarray((glyph.astype(np.uint8) * 255)).resize((28, 28), Image.Resampling.BILINEAR))
    best, sc = "?", -1.0
    for ch, t in tmpl.items():
        s = score(g, t)
        if s > sc:
            sc, best = s, ch
    return best, sc


def row_boxes(mask: np.ndarray) -> list[tuple[int, int]]:
    col = mask.sum(axis=0)
    out = []
    inside = False
    start = 0
    for x, v in enumerate(col):
        if v >= 2 and not inside:
            inside, start = True, x
        elif v < 2 and inside:
            inside = False
            if x - start >= 2:
                out.append((start, x))
    if inside and len(col) - start >= 2:
        out.append((start, len(col)))
    return out


def read_strip(gray: np.ndarray, tmpl: dict[str, np.ndarray]) -> str:
    mask = (gray >= 32) & (gray <= 200)
    boxes = row_boxes(mask)
    if not boxes:
        return ""
    widths = [b - a for a, b in boxes]
    med = float(np.median(widths)) if widths else 10.0
    chars: list[str] = []
    for a, b in boxes:
        g = mask[:, a:b]
        ys = np.where(g.sum(axis=1) > 0)[0]
        if len(ys) == 0:
            continue
        g = g[ys.min() : ys.max() + 1]
        gh, gw = g.shape
        if gh <= 6 or gw <= 3:
            chars.append("." if gh <= 8 else ":")
            continue
        pieces = [g]
        if gw > med * 1.65:
            mid = gw // 2
            pieces = [g[:, :mid], g[:, mid:]]
        for piece in pieces:
            if piece.shape[1] < 2:
                continue
            ch, sc = classify(piece, tmpl)
            if sc < 0.22:
                continue
            chars.append(ch.translate(MAP) if ch in "۰۱۲۳۴۵۶۷۸۹" else ch)
    return "".join(chars)


def find_text_rows(right: np.ndarray) -> list[tuple[int, int]]:
    mask = (right >= 32) & (right <= 200)
    row = mask.sum(axis=1)
    rows = []
    inside = False
    start = 0
    for y, v in enumerate(row):
        if v >= 8 and not inside:
            inside, start = True, y
        elif v < 8 and inside:
            inside = False
            if y - start >= 8:
                rows.append((max(0, start - 2), min(right.shape[0], y + 2)))
    if inside and len(row) - start >= 8:
        rows.append((max(0, start - 2), len(row)))
    # Prefer the two densest upper/mid rows (close time, open time)
    rows = [r for r in rows if r[1] - r[0] <= 40]
    return rows[:4]


def parse_stamp(raw: str) -> str:
    import re

    s = raw.translate(MAP).replace("..", ".")
    s = s.replace("2.26", "2026").replace("2.025", "2025").replace("2.027", "2027")
    tm = re.search(r"(\d{1,2}):(\d{2}):(\d{2})", s)
    if not tm:
        return ""
    hh, mm, ss = tm.group(1).zfill(2), tm.group(2), tm.group(3)
    head = s[: tm.start()]
    ym = re.search(r"(20\d{2})", head)
    year = ym.group(1) if ym else ""
    if not year:
        ym2 = re.search(r"\b(2[0-9])\b", head)
        if ym2:
            year = "20" + ym2.group(1)
    mo = re.search(r"[.\-](0?[1-9]|1[0-2])[.\-]", head)
    month = mo.group(1).zfill(2) if mo else ""
    if not month:
        mo2 = re.search(r"(0[1-9]|1[0-2])", head[4:] if len(head) > 4 else head)
        month = mo2.group(1) if mo2 else "01"
    # day: last 1-2 digits before the time
    digits = re.sub(r"\D", "", head)
    day = "01"
    if len(digits) >= 2:
        cand = digits[-2:]
        if 1 <= int(cand) <= 31:
            day = cand
        elif 1 <= int(digits[-1]) <= 9:
            day = digits[-1].zfill(2)
    if re.search(r"[.\-]0?8[.\-]?12" + hh, s):
        day = "13"
    if year and _ok(year, month, day, hh, mm, ss):
        return f"{year}-{month}-{day}T{hh}:{mm}:{ss}"
    return ""


def _ok(y, m, d, hh, mm, ss) -> bool:
    try:
        yi, mi, di, h, n, s = map(int, (y, m, d, hh, mm, ss))
    except ValueError:
        return False
    return 2018 <= yi <= 2035 and 1 <= mi <= 12 and 1 <= di <= 31 and h <= 23 and n <= 59 and s <= 59


def main() -> int:
    if len(sys.argv) < 2:
        print(json.dumps({"error": "usage: read_mt5_times.py image"}))
        return 2
    path = Path(sys.argv[1])
    img = np.array(Image.open(path).convert("L"))
    h, w = img.shape
    right = img[:, int(w * 0.55) :]
    tmpl = templates()
    stamps = []
    for y0, y1 in find_text_rows(right):
        raw = read_strip(right[y0:y1], tmpl)
        stamp = parse_stamp(raw)
        if stamp:
            stamps.append({"raw": raw, "stamp": stamp, "y": y0})
    stamps.sort(key=lambda x: x["y"])
    # unique
    uniq = []
    for item in stamps:
        if item["stamp"] not in [u["stamp"] for u in uniq]:
            uniq.append(item)
    close = uniq[0]["stamp"] if uniq else ""
    open_ = uniq[1]["stamp"] if len(uniq) > 1 else (uniq[0]["stamp"] if uniq else "")
    # earlier clock = open if same day
    if len(uniq) >= 2:
        a, b = uniq[0]["stamp"], uniq[1]["stamp"]
        if a > b:
            open_, close = b, a
        else:
            open_, close = a, b
    print(json.dumps({"openTime": open_, "closeTime": close, "hits": uniq}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
