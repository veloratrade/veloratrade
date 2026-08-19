#!/usr/bin/env python3
"""Static and asset contract for dedicated transactional email CID icons."""

from pathlib import Path
import struct

ROOT = Path(__file__).resolve().parents[2]
NAMES = [
    "verification",
    "welcome",
    "password-reset",
    "password-changed",
    "security",
    "first-trade",
    "achievement",
]

notification = (ROOT / "api/src/Core/NotificationService.php").read_text(encoding="utf-8")
template = (ROOT / "api/src/Core/EmailTemplate.php").read_text(encoding="utf-8")
mailer = (ROOT / "api/src/Core/Mailer.php").read_text(encoding="utf-8")

for name in NAMES:
    svg = ROOT / "tools/email-icons" / f"{name}.svg"
    png = ROOT / "public/assets/email-icons" / f"{name}.png"
    if not svg.is_file() or not png.is_file():
        raise SystemExit(f"missing source/output icon: {name}")
    raw = png.read_bytes()
    if raw[:8] != b"\x89PNG\r\n\x1a\n" or raw[12:16] != b"IHDR":
        raise SystemExit(f"invalid PNG signature/IHDR: {name}")
    width, height, bit_depth, color_type = struct.unpack(">IIBB", raw[16:26])
    if (width, height) != (128, 128):
        raise SystemExit(f"wrong PNG size for {name}: {(width, height)}")
    if bit_depth != 8 or color_type not in (4, 6):
        raise SystemExit(f"PNG must be 8-bit with alpha for {name}: depth={bit_depth} type={color_type}")
    if len(raw) < 1000:
        raise SystemExit(f"PNG icon is unexpectedly empty/small: {name}")
    if f"'velora-' . $iconName => $iconPath" not in notification:
        raise SystemExit("NotificationService CID map contract missing")
    if f"'{name}'" not in notification:
        raise SystemExit(f"NotificationService icon mapping missing: {name}")

for emoji in ("✉️", "✔", "📊", "🏆"):
    if emoji in notification:
        raise SystemExit(f"legacy emoji remains in NotificationService: {emoji}")

if notification.count("return self::sendWithIcon(") != 7:
    raise SystemExit("all seven transactional methods must use sendWithIcon")
if 'src="cid:{$iconCidSafe}"' not in template:
    raise SystemExit("EmailTemplate CID image contract missing")
if "'content_id' => mb_substr((string) $cid" not in mailer:
    raise SystemExit("Resend attachment content_id contract missing")

print("Transactional CID icons: PASS (7/7 PNG assets, mappings, no legacy emoji)")
