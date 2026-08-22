#!/usr/bin/env bash
# ==============================================================================
# VELORA — Bootstrap Session (Step Zero)
# ==============================================================================
# منبع: AGENTS.md §2/§2.1 و docs/README.md §8
# هدف: اجرای «گامِ صفر» به‌صورتِ کد، بدون اتکا به انضباطِ agent.
#
# این اسکریپت خودش باید به‌صورت تک‌فایل از API واکشی و اجرا شود — یعنی
# نخستین واکشیِ هر جلسه فقط همین فایل (یا AGENTS.md) است، نه کلونِ کامل.
#
# ترتیب:
#   ۱) واکشیِ تک‌فایلِ AGENTS.md (هیچ کلونی تا اینجا)         — بند ۱۳.۱
#   ۲) کلونِ حداقلیِ scoped: shallow + blobless + sparse      — بند ۱۳.۲
#   ۳) پاک‌سازی credential از remote (هیچ PAT در .git/config) — NP-2/§8
#   ۴) اجرای گارد (velora-status.sh) + چاپِ کد یا رد          — §8/BR-6
#
# استفاده:
#   bash tools/velora-bootstrap.sh [dest-dir] [sparse-paths...]
#   bash tools/velora-bootstrap.sh ./velora docs tools
#
# این اسکریپت کاملاً فقط‌خواندنی است نسبت به مخزنِ راه‌دور: هیچ push/commit
# انجام نمی‌دهد. مخزن عمومی است ⟹ خواندن ناشناس، بدون credential.
# ==============================================================================
set -uo pipefail

REPO="veloratrade/veloratrade"
RAW_BASE="https://raw.githubusercontent.com/$REPO/main"
CLONE_URL="https://github.com/$REPO.git"
DEST="${1:-./velora}"
shift 2>/dev/null || true
SPARSE_PATHS=("$@")
[ "${#SPARSE_PATHS[@]}" -eq 0 ] && SPARSE_PATHS=(docs tools)

echo "▸ گام ۱: واکشیِ تک‌فایلِ AGENTS.md (هیچ کلون کاملی تا اینجا)"
if ! curl -fsSL "$RAW_BASE/AGENTS.md" -o /tmp/AGENTS.md 2>/dev/null; then
  echo "  ⛔ واکشیِ AGENTS.md ناموفق — دسترسی به شبکه/مخزن را بررسی کن."
  exit 2
fi
echo "  ✓ AGENTS.md واکشی شد ($(wc -l < /tmp/AGENTS.md) خط). آن را کامل بخوان."

echo "▸ گام ۲: کلونِ حداقلیِ scoped (shallow + blobless + sparse) ⟹ $DEST"
if [ -e "$DEST/.git" ]; then
  echo "  ⚠️  $DEST از قبل وجود دارد؛ از همان استفاده می‌کنیم (کلونِ کامل نباشد!)."
else
  if ! git clone --depth 1 --filter=blob:none --sparse "$CLONE_URL" "$DEST" 2>/dev/null; then
    echo "  ⛔ clone ناموفق."
    exit 2
  fi
fi
cd "$DEST" || exit 2
git sparse-checkout set "${SPARSE_PATHS[@]}" 2>/dev/null || \
  git sparse-checkout init --cone 2>/dev/null
echo "  ✓ working tree محدود به: ${SPARSE_PATHS[*]}"

echo "▸ گام ۳: پاک‌سازی credential از remote (هیچ PAT در .git/config)"
git remote set-url origin "$CLONE_URL" 2>/dev/null || true
if grep -rqE '(github_pat_|gh[pousr]_)[A-Za-z0-9]{10,}|://[^/@[:space:]]+@' .git/config 2>/dev/null; then
  echo "  ⛔ باز هم credential در .git/config یافت شد — دستی پاک کن."
  exit 2
fi
echo "  ✓ remote.url بدون credential."

echo "▸ گام ۴: گاردِ وضعیت و کدِ اثبات (یا رد)"
echo
if [ -x tools/velora-status.sh ]; then
  bash tools/velora-status.sh
else
  echo "  ⚠️  tools/velora-status.sh در sparse set نیست — اضافه‌اش کن و دوباره اجرا کن."
fi
