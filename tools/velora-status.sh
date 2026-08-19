#!/usr/bin/env bash
# ==============================================================================
# VELORA — گزارش وضعیت زنده (READ-ONLY)
# ==============================================================================
# هدف: یک agent جدید با یک اجرا، وضعیت واقعی پروژه را بفهمد — بدون اتکا به
#       حافظهٔ جلسات قبل و بدون اینکه مالک چیزی توضیح دهد.
#
# ── تضمین‌های این اسکریپت ─────────────────────────────────────────────────────
#   ✓ حالت‌های text/json/check/offline فقط خواندنی‌اند؛ workflow اجرا نمی‌شود
#   ✓ فقط GET به API گیت‌هاب — هیچ POST/PUT/PATCH/DELETE
#   ✓ --context فقط artifactهای ignored در .session/ می‌سازد؛ فایل پروژه دست‌نخورده
#   ✓ هیچ مقدار Secret چاپ یا ذخیره نمی‌شود (API هم مقدار را برنمی‌گرداند)
#   ✓ توکن در خروجی ماسک می‌شود و نبود توکن، گزارش آفلاین را متوقف نمی‌کند
#   ✓ --json قدیمی برای بازتولید PROJECT_STATE.json سازگار باقی می‌ماند
#
# ── استفاده ───────────────────────────────────────────────────────────────────
#   bash tools/velora-status.sh              # گزارش کامل شروع جلسه
#   GH_TOKEN=xxx bash tools/velora-status.sh # همراه وضعیت زنده GitHub خصوصی
#   bash tools/velora-status.sh --offline    # بدون هیچ درخواست شبکه
#   bash tools/velora-status.sh --check      # اعتبار context/drift؛ خطا = exit 1
#   bash tools/velora-status.sh --context    # ساخت .session/SESSION_CONTEXT.{md,json}
#   bash tools/velora-status.sh --json       # stdout JSON؛ سازگار با روش قبلی
#   bash tools/velora-status.sh --help
#
# مرجع: docs/README.md §7/§8 و docs/SESSION_STATE.json.
# ==============================================================================

set -uo pipefail

# از هر working directory قابل اجرا باشد، اما فقط داخل همین checkout کار کند.
SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(git -C "$SCRIPT_DIR" rev-parse --show-toplevel 2>/dev/null || true)"
if [ -z "$REPO_ROOT" ]; then
  echo "ERROR: velora-status.sh باید داخل checkout گیت اجرا شود." >&2
  exit 2
fi
cd "$REPO_ROOT" || exit 2

REPO="veloratrade/veloratrade"
API="https://api.github.com/repos/$REPO"
JSON_MODE=0
OFFLINE_MODE=0
CHECK_MODE=0
CONTEXT_MODE=0

usage() {
  cat <<'USAGE'
VELORA live session bootstrap

Usage:
  bash tools/velora-status.sh              Full live session report
  GH_TOKEN=... bash tools/velora-status.sh Include private GitHub live evidence
  bash tools/velora-status.sh --offline    No network requests
  bash tools/velora-status.sh --check      Validate freshness/drift (nonzero on failure)
  bash tools/velora-status.sh --context    Generate ignored .session context files
  bash tools/velora-status.sh --json       JSON on stdout (PROJECT_STATE compatible)
  bash tools/velora-status.sh --help
USAGE
}

for arg in "$@"; do
  case "$arg" in
    --json)    JSON_MODE=1 ;;
    --offline) OFFLINE_MODE=1 ;;
    --check)   CHECK_MODE=1 ;;
    --context) CONTEXT_MODE=1 ;;
    --help|-h) usage; exit 0 ;;
    *) echo "ERROR: گزینه ناشناخته: $arg" >&2; usage >&2; exit 2 ;;
  esac
done

if [ "$JSON_MODE" = 1 ] && { [ "$CHECK_MODE" = 1 ] || [ "$CONTEXT_MODE" = 1 ]; }; then
  echo "ERROR: --json را با --check یا --context ترکیب نکن." >&2
  exit 2
fi

TOKEN="${GH_TOKEN:-${GITHUB_TOKEN:-}}"
have_token=0
[ -n "$TOKEN" ] && [ "$OFFLINE_MODE" = 0 ] && have_token=1

# ماسک‌کردن هر چیزی که شبیه توکن است
mask() { sed -E 's/(gh[pousr]_|github_pat_)[A-Za-z0-9_]+/\1***MASKED***/g'; }

gh_get() {  # فقط GET
  [ "$have_token" = 1 ] || { echo '{}'; return; }
  curl -s -H "Authorization: Bearer $TOKEN" \
       -H "Accept: application/vnd.github+json" \
       -X GET "$1" 2>/dev/null || echo '{}'
}

hr() { printf '%s\n' "────────────────────────────────────────────────────────────"; }

# ══════════════════════════════════════════════════════════════════════════════
if [ "$JSON_MODE" = 0 ]; then
cat <<BANNER
╔════════════════════════════════════════════════════════════╗
║  VELORA — وضعیت زنده پروژه            (READ-ONLY snapshot) ║
╚════════════════════════════════════════════════════════════╝
تولید: $(date -u '+%Y-%m-%d %H:%M UTC')
BANNER
if [ "$OFFLINE_MODE" = 1 ]; then
  echo "ℹ️  حالت آفلاین — هیچ درخواست شبکه‌ای انجام نمی‌شود"
elif [ "$have_token" = 0 ]; then
  echo "⚠️  بدون GH_TOKEN — بخش‌های GitHub خصوصی رد می‌شوند (Git محلی کار می‌کند)"
fi
echo

# ── ۱. مخزن ───────────────────────────────────────────────────────────────────
hr; echo "۱. مخزن و همگامی نسخه"; hr
branch=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo '?')
head_short=$(git rev-parse --short HEAD 2>/dev/null || echo '?')
head_full=$(git rev-parse HEAD 2>/dev/null || echo '')
origin_ref="origin/$branch"
origin_short=$(git rev-parse --short "$origin_ref" 2>/dev/null || true)
ahead=0; behind=0
if [ -n "$origin_short" ]; then
  read -r ahead behind <<EOF_SYNC
$(git rev-list --left-right --count "HEAD...$origin_ref" 2>/dev/null || echo '0 0')
EOF_SYNC
fi

echo "  برنچ         : $branch"
echo "  کامیت محلی   : $head_short"
echo "  origin محلی  : ${origin_short:-در دسترس نیست}"
echo "  فاصله        : ahead=$ahead / behind=$behind"
echo "  تاریخ        : $(git log -1 --format='%ad' --date=iso 2>/dev/null)"
echo "  پیام         : $(git log -1 --format='%s' 2>/dev/null | cut -c1-60)"
dirty=$(git status --porcelain 2>/dev/null | wc -l | tr -d ' ')
echo "  working tree : $([ "$dirty" = 0 ] && echo 'تمیز ✅' || echo "$dirty فایل تغییرکرده ⚠️")"
echo "  فایل‌ها      : $(git ls-files 2>/dev/null | wc -l | tr -d ' ')"
echo "  نسخه README  : $(grep -m1 '| نسخه |' docs/README.md 2>/dev/null | tr -dc '0-9.')"
if [ "$have_token" = 1 ]; then
  remote_sha=$(gh_get "$API/commits/main" | python3 -c "import sys,json; print(json.load(sys.stdin).get('sha',''))" 2>/dev/null || true)
  if [ -n "$remote_sha" ]; then
    echo "  main زنده    : ${remote_sha:0:7} $([ "$remote_sha" = "$head_full" ] && echo '✅ همگام' || echo '⚠️ متفاوت با HEAD محلی')"
  else
    echo "  main زنده    : دریافت نشد"
  fi
else
  echo "  main زنده    : بررسی نشد (آخرین origin محلی نمایش داده شد)"
fi
echo
echo "  ۵ کامیت آخر:"
git log --oneline -5 --format='    %h %s' 2>/dev/null | cut -c1-70

# ── ۲. Workflowها ─────────────────────────────────────────────────────────────
echo; hr; echo "۲. Workflowها و آخرین اجرا"; hr
if [ "$have_token" = 1 ]; then
  export TOK="$TOKEN"
  gh_get "$API/actions/workflows" | python3 -c "
import sys,json,urllib.request,os
try: wfs=json.load(sys.stdin).get('workflows',[])
except Exception: wfs=[]
tok=os.environ.get('TOK','')
for w in sorted(wfs,key=lambda x:x['path']):
    name=w['path'].split('/')[-1]
    try:
        req=urllib.request.Request(w['url']+'/runs?per_page=1')
        req.add_header('Authorization','Bearer '+tok)
        d=json.load(urllib.request.urlopen(req,timeout=15))
        n=d['total_count']
        if d['workflow_runs']:
            r=d['workflow_runs'][0]
            result=r.get('conclusion') or r.get('status') or '?'
            sha=(r.get('head_sha') or '')[:7]
            last=f\"run={r.get('id')} {r['created_at'][:16]} {r['event']} → {result} sha={sha}\"
        else: last='هرگز اجرا نشده'
    except Exception: n,last='?','?'
    print(f'  {name:32s} runs={str(n):>3s}  {last}')
" TOK="$TOKEN" 2>/dev/null || echo "  (خطا در دریافت)"
else
  ls .github/workflows/*.yml 2>/dev/null | sed 's|.*/|  |'
fi

# ── ۳. Environment ────────────────────────────────────────────────────────────
echo; hr; echo "۳. Environment و محافظت‌ها"; hr
if [ "$have_token" = 1 ]; then
  gh_get "$API/environments/production" | python3 -c "
import sys,json
try: d=json.load(sys.stdin)
except Exception: d={}
if d.get('name'):
    rules=[r['type'] for r in d.get('protection_rules',[])]
    print('  Environment       : production')
    print('  protection_rules  :', rules or 'هیچ 🔴')
    print('  can_admins_bypass :', d.get('can_admins_bypass'))
else: print('  (در دسترس نیست)')
"
  gh_get "$API/environments/production/deployment-branch-policies" | python3 -c "
import sys,json
try: d=json.load(sys.stdin)
except Exception: d={}
b=[x['name'] for x in d.get('branch_policies',[])]
print('  branch policy     :', b or 'ندارد')
"
  # فقط نام Secretها — هرگز مقدار (API هم مقدار نمی‌دهد)
  gh_get "$API/actions/secrets" | python3 -c "
import sys,json
try: d=json.load(sys.stdin)
except Exception: d={}
names=[s['name'] for s in d.get('secrets',[])]
prod=[n for n in names if not n.startswith('STAGING')]
print('  Secrets (فقط نام) :', names or 'هیچ')
print('  Secrets تولید     :', prod or 'هیچ — Deploy Production اجرا نمی‌شود ✅')
"
  gh_get "$API/actions/permissions" | python3 -c "
import sys,json
try: d=json.load(sys.stdin)
except Exception: d={}
print('  GitHub Actions    :', 'فعال' if d.get('enabled') else 'غیرفعال — مصرف جدید صفر ✅')
"
else
  echo "  (نیازمند GH_TOKEN)"
fi

# ── ۴. سلامت محیط‌ها ──────────────────────────────────────────────────────────
echo; hr; echo "۴. سلامت محیط‌ها (GET امن)"; hr
if [ "$OFFLINE_MODE" = 1 ]; then
  echo "  (در حالت --offline عمداً probe نشد؛ هیچ ادعای سلامت زنده‌ای ساخته نشد)"
else
  for pair in "Production|https://veloratrade.ir/" "Staging|https://staging.veloratrade.ir/health"; do
    label="${pair%%|*}"; url="${pair#*|}"
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 12 "$url" 2>/dev/null)
    case "$code" in
      000) note="بی‌پاسخ — احتمالاً OC-1 (فایروال، IP خارج از ایران) نه خرابی" ;;
      200) note="سالم ✅" ;;
      *)   note="بررسی لازم" ;;
    esac
    printf '  %-12s %-4s %s\n' "$label" "$code" "$note"
  done
  echo "  ⚠️ کد 000 از سندباکس شاهد خرابی نیست — تست معتبر فقط از GitHub Actions"
fi

# ── ۵. موانع و کارهای باز ─────────────────────────────────────────────────────
echo; hr; echo "۵. موانع و کارهای باز (از docs/README.md)"; hr
# ردیف مارک‌داون با | تمام می‌شود ⇒ آخرین سلول خالی است، وضعیت = یکی مانده به آخر.
# برش در python3 انجام می‌شود چون awk بایت می‌شمارد و فارسی/ایموجی را می‌شکند.
python3 - <<'PY' 2>/dev/null || echo "  (docs/README.md خوانده نشد)"
import re, sys

def cells(line):
    p = [c.strip() for c in line.rstrip('\n').split('|')]
    while p and p[-1] == '':
        p.pop()
    return p

def clean(s, n):
    s = s.replace('**', '').replace('`', '')
    s = re.sub(r'\s+', ' ', s).strip()
    return s if len(s) <= n else s[:n - 1] + '…'

try:
    lines = open('docs/README.md', encoding='utf-8').readlines()
except OSError:
    sys.exit(1)

for ln in lines:
    if re.match(r'^\| B-\d+ \|', ln):
        p = cells(ln)
        if len(p) >= 3:
            print('  %-6s %s' % (p[1], clean(p[-1], 62)))

print()
print('  موانع فعال‌سازی تولید:')
inblk = False
for ln in lines:
    if ln.startswith('#### چهار مانع'):
        inblk = True
        continue
    if inblk and ln.startswith('#### تحلیل عدد'):
        break
    if inblk and re.match(r'^\| [۱۲۳۴] \|', ln):
        p = cells(ln)
        if len(p) >= 4:
            print('    %-2s %-22s %s' % (p[1], clean(p[2], 22), clean(p[-1], 40)))
PY

# ── ۶. قواعد ──────────────────────────────────────────────────────────────────
echo; hr; echo "۶. قواعد غیرقابل‌نقض"; hr
cat <<'RULES'
  NP-1  هیچ تغییر Production بدون تأیید صریح مالک
  NP-2  هیچ Secret واقعی در مخزن/سند/لاگ/چت
  NP-6  Deployment فقط از نسخه ردیابی‌پذیر و از طریق workflow
  C-2   مقصد mirror باید hardcode بماند — هرگز ورودی dispatch
  BR-2  نتیجهٔ ابزار را پیش از گزارش راستی‌آزمایی کن
  BR-5  فرض دسترسی نکن — آزمایش کن
RULES

# ── ۷. مأموریت فعال و حافظهٔ تحویل ───────────────────────────────────────────
echo; hr; echo "۷. مأموریت فعال (docs/SESSION_STATE.json)"; hr
python3 - <<'PY' 2>/dev/null || echo "  🔴 SESSION_STATE نامعتبر یا خوانده‌نشدنی است"
import json
p='docs/SESSION_STATE.json'
d=json.load(open(p, encoding='utf-8'))
t=d.get('active_task', {})
print(f"  کار فعال      : {t.get('id','?')} — {t.get('title','?')}")
print(f"  وضعیت         : {t.get('status','?')}")
print(f"  محیط          : {t.get('environment','?')}")
print(f"  مسئول مانع    : {t.get('blocker_owner','?')}")
print(f"  تشخیص جاری    : {t.get('current_diagnosis','?')}")
print("\n  انجام‌شده:")
for x in t.get('completed',[]): print('    ✓ '+x)
print("\n  اقدام دقیق بعدی:")
for i,x in enumerate(t.get('next_actions',[]),1): print(f'    {i}. {x}')
print("\n  پرهیز:")
for x in t.get('do_not_do',[]): print('    ⛔ '+x)
PY

# ── ۸. Drift و اعتبار context ────────────────────────────────────────────────
echo; hr; echo "۸. تازگی اسناد، تعارض‌ها و Drift"; hr
snapshot_commit=$(python3 -c "import json; print(json.load(open('docs/PROJECT_STATE.json'))['_meta'].get('generated_from_commit',''))" 2>/dev/null || true)
session_commit=$(python3 -c "import json; print(json.load(open('docs/SESSION_STATE.json'))['_meta'].get('based_on_commit',''))" 2>/dev/null || true)
context_errors=0

# یک snapshot نمی‌تواند hash کامیتی را که خودش داخل آن commit می‌شود از پیش بداند.
# بنابراین اختلافی که فقط از فایل خود snapshot/handoff تشکیل شده، تازه محسوب می‌شود.
covers_head_with_only() {
  local base="$1" allowed_regex="$2" changed
  [ -n "$base" ] && git cat-file -e "$base^{commit}" 2>/dev/null || return 1
  git merge-base --is-ancestor "$base" HEAD 2>/dev/null || return 1
  changed=$(git diff --name-only "$base..HEAD" 2>/dev/null || return 1)
  [ -n "$changed" ] || return 0
  if printf '%s\n' "$changed" | grep -Ev "$allowed_regex" | grep -q .; then
    return 1
  fi
  return 0
}

if [ -z "$snapshot_commit" ]; then
  echo "  🔴 docs/PROJECT_STATE.json نامعتبر یا فاقد generated_from_commit"
  context_errors=$((context_errors+1))
elif [ "$snapshot_commit" = "$head_full" ] || covers_head_with_only "$snapshot_commit" '^docs/(PROJECT_STATE|SESSION_STATE)\.json$|^docs/README\.md$'; then
  echo "  ✅ PROJECT_STATE وضعیت فنی HEAD را پوشش می‌دهد"
else
  echo "  ⚠️  PROJECT_STATE کهنه: ${snapshot_commit:0:7} پوشش‌دهنده HEAD $head_short نیست"
  echo "      بازتولید: bash tools/velora-status.sh --json > docs/PROJECT_STATE.json"
  context_errors=$((context_errors+1))
fi

if [ -z "$session_commit" ]; then
  echo "  🔴 docs/SESSION_STATE.json نامعتبر یا فاقد based_on_commit"
  context_errors=$((context_errors+1))
elif [ "$session_commit" = "$head_full" ] || covers_head_with_only "$session_commit" '^docs/(SESSION_STATE|PROJECT_STATE)\.json$|^docs/README\.md$'; then
  echo "  ✅ SESSION_STATE وضعیت تحویل HEAD را پوشش می‌دهد"
else
  echo "  ⚠️  SESSION_STATE نیازمند بازبینی: ${session_commit:0:7} پوشش‌دهنده HEAD $head_short نیست"
  context_errors=$((context_errors+1))
fi

if [ "$behind" -gt 0 ] 2>/dev/null; then
  echo "  🔴 checkout محلی $behind کامیت از $origin_ref عقب است"
  context_errors=$((context_errors+1))
fi

if [ "$have_token" = 1 ]; then
  prod_secret_count=$(gh_get "$API/actions/secrets" | python3 -c "import sys,json; n={x.get('name') for x in json.load(sys.stdin).get('secrets',[])}; print(len(n & {'FTP_SERVER','FTP_USERNAME','FTP_PASSWORD'}))" 2>/dev/null || echo 0)
  if [ "$prod_secret_count" -gt 0 ] && grep -q 'بدون Secrets\|Secrets ندارد\|هیچ.*FTP_SERVER' docs/README.md; then
    echo "  ⚠️  تعارض مستند: README نبود Secrets تولید را ذکر می‌کند، GitHub نام $prod_secret_count Secret تولید را گزارش می‌دهد"
  fi
else
  echo "  ℹ️  تعارض‌های وابسته به GitHub در حالت بدون توکن قابل‌اثبات نیستند"
fi

echo "  نتیجه check  : $([ "$context_errors" = 0 ] && echo 'معتبر ✅' || echo "$context_errors خطای تازگی/اعتبار ⚠️")"

# ── ۹. مرز اختیار جلسه ────────────────────────────────────────────────────────
echo; hr; echo "۹. مرز اختیار و امنیت جلسه"; hr
cat <<'BOUNDARIES'
  ✅ مجاز بدون اقدام نوشتاری: خواندن، تحلیل، گزارش، تست محلی غیرمخرب
  ⚠️ نیازمند تأیید مالک: تغییر فایل، commit، push، اجرای workflow، نوشتن روی Staging
  ⛔ نیازمند تأیید صریح Production: deploy/POST/FTP/DB/config روی Production
  🔐 Secret values: هرگز خوانده، چاپ، ذخیره یا درخواست نشوند
  🔑 دسترسی ترجیحی: Deploy key موقت؛ PAT حداقلی کوتاه‌عمر فقط در صورت نیاز
BOUNDARIES

echo
hr
echo "گام بعد: مأموریت فعال بالا را با docs/README.md §3/§6/§7 تطبیق بده؛ قبل از تغییر برنامه و دامنه را اعلام کن"
hr

# ── ۱۰. توکن جلسه (اثبات اجرا) ───────────────────────────────────────────────
# این کد از داده‌های زندهٔ همین لحظه ساخته می‌شود و حدس‌زدنی نیست.
# agent موظف است آن را در اولین پیام خود به مالک نقل کند (§8، گام صفر).
_sess=$(printf '%s|%s|%s|%s' \
          "$(git rev-parse --short HEAD 2>/dev/null)" \
          "$(date -u '+%Y-%m-%dT%H')" \
          "$(git ls-files 2>/dev/null | wc -l | tr -d ' ')" \
          "$(git status --porcelain 2>/dev/null | wc -l | tr -d ' ')" \
        | sha256sum | cut -c1-8)
echo
hr
echo "۱۰. توکن اثبات اجرا (SESSION PROOF)"
hr
echo "  VELORA-RUN-$_sess"
echo "  Context HEAD: $head_short | branch: $branch | dirty: $dirty | errors: $context_errors"
echo
echo "  ⚠️ این کد را در اولین پیام به مالک بنویس."
echo "     بدون آن، هیچ گزارشی از تو معتبر نیست (BR-6)."
hr

# --context تنها حالت نوشتاری است و فقط artifactهای ignored می‌سازد.
if [ "$CONTEXT_MODE" = 1 ]; then
  mkdir -p .session
  context_args=(--json)
  [ "$OFFLINE_MODE" = 1 ] && context_args+=(--offline)
  bash "${BASH_SOURCE[0]}" "${context_args[@]}" > .session/SESSION_CONTEXT.json
  SESSION_PROOF="VELORA-RUN-$_sess" python3 - <<'PY'
import datetime, json, os
live=json.load(open('.session/SESSION_CONTEXT.json', encoding='utf-8'))
state=json.load(open('docs/SESSION_STATE.json', encoding='utf-8'))
r=live.get('repository',{}); task=state.get('active_task',{})
lines=[
 '# VELORA — Live Session Context','',
 f"- Generated: `{live.get('_meta',{}).get('generated_at','?')}`",
 f"- Session proof: `{os.environ.get('SESSION_PROOF','?')}`",
 f"- Context commit: `{r.get('head','?')}`",
 f"- Branch: `{r.get('branch','?')}`",
 f"- Working tree clean: `{r.get('working_tree_clean')}`",
 f"- Network mode: `{'online' if live.get('_meta',{}).get('github_live') else 'offline/local'}`",'',
 '## Project Core','',
 f"- Repository: `{state.get('project_core',{}).get('repository','?')}`",
 f"- Architecture: {state.get('project_core',{}).get('architecture','?')}",
 f"- Operating model: `{state.get('project_core',{}).get('operating_model','?')}`",'',
 '## Active Task','',
 f"- **{task.get('id','?')} — {task.get('title','?')}**",
 f"- Status: `{task.get('status','?')}`",
 f"- Environment: `{task.get('environment','?')}`",
 f"- Diagnosis: {task.get('current_diagnosis','?')}",'',
 '### Completed','']
lines += [f'- {x}' for x in task.get('completed',[])]
lines += ['', '### Exact Next Actions','']+[f'{i}. {x}' for i,x in enumerate(task.get('next_actions',[]),1)]
lines += ['', '## Live Workflow Evidence','']
for name,w in sorted(live.get('workflows',{}).items()):
    last=w.get('last_run') or {}
    lines.append(f"- `{name}`: **{last.get('conclusion','never')}** — run `{last.get('id','—')}`, sha `{last.get('head_sha','—')}`")
lines += ['', '## Drift','']+[f"- {x.get('severity','info').upper()}: {x.get('message')}" for x in live.get('drift',[])]
lines += ['', '## Safety Boundaries','']+[f"- `{k}`: {v}" for k,v in state.get('approval_boundaries',{}).items()]
lines += ['', '> This file is generated and ignored by Git. Live evidence overrides stale technical snapshots; owner decisions remain authoritative.','']
open('.session/SESSION_CONTEXT.md','w',encoding='utf-8').write('\n'.join(lines))
PY
  echo
  echo "  Context ساخته شد:"
  echo "    .session/SESSION_CONTEXT.md"
  echo "    .session/SESSION_CONTEXT.json"
fi

if [ "$CHECK_MODE" = 1 ] && [ "$context_errors" -ne 0 ]; then
  exit 1
fi
exit 0
fi

# ══════════════════════════════════════════════════════════════════════════════
# حالت JSON
# ══════════════════════════════════════════════════════════════════════════════
export TOK="$TOKEN" HAVE="$have_token" OFFLINE="$OFFLINE_MODE" API_URL="$API"
python3 <<'PY' | mask
import json, os, subprocess, urllib.request, urllib.error, datetime

TOK=os.environ.get('TOK',''); HAVE=os.environ.get('HAVE')=='1'; OFFLINE=os.environ.get('OFFLINE')=='1'; API=os.environ['API_URL']

def sh(c):
    try: return subprocess.run(c,shell=True,capture_output=True,text=True).stdout.strip()
    except Exception: return ''

def api(p):
    if not HAVE: return {}
    try:
        r=urllib.request.Request(API+p); r.add_header('Authorization','Bearer '+TOK)
        return json.load(urllib.request.urlopen(r,timeout=15))
    except Exception: return {}

def covers_head_with_only(base, allowed):
    """True when commits after base only update self-describing snapshot files."""
    if not base: return False
    ok=subprocess.run(['git','merge-base','--is-ancestor',base,'HEAD'],capture_output=True).returncode==0
    if not ok: return False
    paths=sh(f'git diff --name-only {base}..HEAD').splitlines()
    return all(p in allowed for p in paths)

def probe(u):
    if OFFLINE: return None
    try:
        rq=urllib.request.Request(u,method='GET'); rq.add_header('User-Agent','velora-status')
        return urllib.request.urlopen(rq,timeout=12).status
    except urllib.error.HTTPError as e: return e.code
    except Exception: return 0

out={
 "_meta":{
   "schema":"velora-project-state/1",
   "generated_at": datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='seconds'),
   "generated_from_commit": sh('git rev-parse HEAD'),
   "generator":"tools/velora-status.sh --json",
   "github_live": HAVE,
   "network_mode":"offline" if OFFLINE else ("online" if HAVE else "online-no-github-auth"),
   "regenerate":"bash tools/velora-status.sh --json > docs/PROJECT_STATE.json",
   "authority":"SNAPSHOT ONLY — منبع حقیقت نیست. مرجع: docs/README.md و P1/P2",
   "staleness_warning":"اگر generated_from_commit با HEAD فعلی فرق دارد، این فایل کهنه است — بازتولید کن",
   "contains_secrets": False
 },
 "repository":{
   "name":"veloratrade/veloratrade","private":False,"visibility":"public",
   "branch": sh('git rev-parse --abbrev-ref HEAD'),
   "head": sh('git rev-parse --short HEAD'),
   "head_full": sh('git rev-parse HEAD'),
   "head_date": sh("git log -1 --format=%ad --date=iso"),
   "head_subject": sh("git log -1 --format=%s"),
   "working_tree_clean": sh('git status --porcelain')=='',
   "changed_files": [x for x in sh('git status --porcelain').splitlines() if x],
   "tracked_files": int(sh('git ls-files | wc -l') or 0),
   "readme_version": sh("grep -m1 '| نسخه |' docs/README.md | tr -dc '0-9.'"),
   "origin_tracking_head": sh("git rev-parse --short origin/$(git rev-parse --abbrev-ref HEAD) 2>/dev/null"),
 },
 "session_state":{},
 "drift":[],
 "environments":{
   "production":{
     "url":"https://veloratrade.ir","docroot":"public_html/",
     "private_root":"/home/piknet/velora_private/",
     "behind_cloudflare":True,
     "ftp_host_note":"از veloratrade.ir برای FTP استفاده نشود — پشت CDN است",
     "deploy_workflow":"deploy.yml","status":"OPERATIONAL",
     "live_probe_status": probe("https://veloratrade.ir/"),
   },
   "staging":{
     "url":"https://staging.veloratrade.ir","docroot":"public_html/staging.veloratrade.ir/",
     "private_root":"/home/piknet/velora_private_staging/",
     "behind_cloudflare":False,
     "deploy_workflow":"deploy-staging.yml","status":"OPERATIONAL",
     "live_probe_status": probe("https://staging.veloratrade.ir/health"),
     "probe_note":"0 = OC-1 firewall از سندباکس، نه خرابی",
   },
   "shared":{
     "ftp_host":"185.164.72.148","ftp_account":"piknet",
     "ftp_login_point":"/home/piknet/",
     "login_point_proof":"setup-staging-private.yml خواهرِ public_html می‌نویسد",
     "protocol":"ftp + ssl-allow no","tool":"lftp mirror --continue retry x12",
     "single_account_risk":"هر دو محیط یک اکانت — تنها تفاوت، مسیر مقصد (OC-10/OC-11)",
   },
 },
 "secrets":{"note":"فقط نام — مقدار هرگز خوانده نمی‌شود و API هم برنمی‌گرداند"},
 "workflows":{}, "protection":{},
 "invariants":[
   "NP-1 هیچ تغییر Production بدون تأیید صریح مالک",
   "NP-2 هیچ Secret واقعی در مخزن/سند/لاگ/چت",
   "NP-6 Deployment فقط از نسخه ردیابی‌پذیر و از طریق workflow",
   "C-2 مقصد mirror hardcode — هرگز ورودی dispatch",
   "BR-2 نتیجهٔ ابزار را پیش از گزارش راستی‌آزمایی کن",
   "BR-5 فرض دسترسی نکن — آزمایش کن",
 ],
 "traps":[
   "OC-1 کد 000 از سندباکس ≠ خرابی سرویس (فایروال IP خارج از ایران)",
   "BR-2 تست قرمز ≠ خرابی محیط — دو بار نقص خودِ تست بود",
   "OC-8 Required Reviewers مسدود توسط پلن، نه کمبود دسترسی",
   "OC-12 منیفست‌های ریشه مرجع بستهٔ انتشار نیستند",
 ],
}

# حافظهٔ انسانی جلسه + drift محلی؛ شکست parse صریح ثبت می‌شود، پنهان نمی‌ماند.
try:
    state=json.load(open('docs/SESSION_STATE.json',encoding='utf-8'))
    out['session_state']=state
    based=state.get('_meta',{}).get('based_on_commit','')
    if based != out['repository']['head_full'] and not covers_head_with_only(
        based, {'docs/SESSION_STATE.json','docs/PROJECT_STATE.json','docs/README.md'}):
        out['drift'].append({"id":"SESSION_STATE_STALE","severity":"warning",
          "message":f"SESSION_STATE based_on {based[:7] or '?'} does not cover HEAD {out['repository']['head']}"})
except Exception as e:
    out['drift'].append({"id":"SESSION_STATE_INVALID","severity":"error",
      "message":"docs/SESSION_STATE.json is missing or invalid"})

try:
    snap=json.load(open('docs/PROJECT_STATE.json',encoding='utf-8'))
    snap_head=snap.get('_meta',{}).get('generated_from_commit','')
    if snap_head != out['repository']['head_full'] and not covers_head_with_only(
        snap_head, {'docs/PROJECT_STATE.json'}):
        out['drift'].append({"id":"PROJECT_STATE_STALE","severity":"warning",
          "message":f"PROJECT_STATE generated from {snap_head[:7] or '?'} does not cover HEAD {out['repository']['head']}"})
except Exception:
    out['drift'].append({"id":"PROJECT_STATE_INVALID","severity":"error",
      "message":"docs/PROJECT_STATE.json is missing or invalid"})

branch=out['repository']['branch']
counts=sh(f'git rev-list --left-right --count HEAD...origin/{branch} 2>/dev/null').split()
if len(counts)==2:
    out['repository']['ahead_by'],out['repository']['behind_by']=map(int,counts)
    if out['repository']['behind_by']:
        out['drift'].append({"id":"LOCAL_BEHIND_ORIGIN","severity":"error",
          "message":f"local HEAD is {out['repository']['behind_by']} commit(s) behind local origin/{branch}"})

if HAVE:
    repository_meta=api('')
    if repository_meta.get('full_name'):
        out['repository']['private']=bool(repository_meta.get('private'))
        out['repository']['visibility']=repository_meta.get('visibility') or ('private' if repository_meta.get('private') else 'public')
    actions_policy=api('/actions/permissions')
    out['actions_policy']={
        'enabled':actions_policy.get('enabled'),
        'cost_guard':'standard Ubuntu runners only; no schedule/cache/packages-write; artifacts <=14d',
    }
    remote=api('/commits/main').get('sha','')
    out['repository']['remote_main_head']=remote[:7] if remote else None
    out['repository']['remote_main_matches_head']=(remote==out['repository']['head_full']) if remote else None
    if remote and remote != out['repository']['head_full']:
        out['drift'].append({"id":"REMOTE_MAIN_DIFFERS","severity":"error",
          "message":f"GitHub main {remote[:7]} differs from local HEAD {out['repository']['head']}"})
    s=api('/actions/secrets')
    names=[x['name'] for x in s.get('secrets',[])]
    out['secrets']['names']=names
    out['secrets']['production_ftp_configured']=all(
        n in names for n in ('FTP_SERVER','FTP_USERNAME','FTP_PASSWORD'))
    if out['secrets']['production_ftp_configured']:
        try: readme=open('docs/README.md',encoding='utf-8').read()
        except Exception: readme=''
        if ('Secrets ندارد' in readme or 'هیچ (`FTP_SERVER`' in readme or
            'هیچ (`FTP_SERVER`/`FTP_USERNAME`/`FTP_PASSWORD`' in readme):
            out['drift'].append({"id":"README_PRODUCTION_SECRETS_CONFLICT","severity":"warning",
              "message":"README says production FTP secrets are absent, but GitHub reports all three names configured"})
    for w in api('/actions/workflows').get('workflows',[]):
        n=w['path'].split('/')[-1]
        try:
            r=urllib.request.Request(w['url']+'/runs?per_page=1')
            r.add_header('Authorization','Bearer '+TOK)
            d=json.load(urllib.request.urlopen(r,timeout=15))
            last=d['workflow_runs'][0] if d['workflow_runs'] else None
            out['workflows'][n]={"state":w['state'],"total_runs":d['total_count'],
              "last_run":{"id":last['id'],"at":last['created_at'],"event":last['event'],
                          "status":last.get('status'),"conclusion":last.get('conclusion'),
                          "head_sha":(last.get('head_sha') or '')[:7],
                          "url":last.get('html_url')} if last else None}
        except Exception:
            out['workflows'][n]={"state":w['state']}
    e=api('/environments/production')
    if e.get('name'):
        out['protection']={"environment":"production",
          "protection_rules":[r['type'] for r in e.get('protection_rules',[])],
          "can_admins_bypass":e.get('can_admins_bypass'),
          "branch_policies":[b['name'] for b in
              api('/environments/production/deployment-branch-policies').get('branch_policies',[])]}

# موانع از README
bl=[]
try:
    for line in open('docs/README.md'):
        if line.startswith('| B-'):
            # ردیف با | تمام می‌شود ⇒ آخرین عنصر خالی است؛ وضعیت = یکی مانده به آخر
            p=[x.strip() for x in line.rstrip('\n').split('|')]
            while p and p[-1]=='': p.pop()
            if len(p)>=3:
                bl.append({"id":p[1],
                           "status":p[-1].replace('**','')[:90]})
except Exception: pass
out['backlog']=bl
out['next_step']="docs/README.md را کامل بخوان — §3 ماتریس FTP، §6 درس‌ها، §7 کار نیمه‌تمام"

print(json.dumps(out, ensure_ascii=False, indent=2))
PY
