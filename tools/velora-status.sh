#!/usr/bin/env bash
# ==============================================================================
# VELORA — گزارش وضعیت زنده (READ-ONLY)
# ==============================================================================
# هدف: یک agent جدید با یک اجرا، وضعیت واقعی پروژه را بفهمد — بدون اتکا به
#       حافظهٔ جلسات قبل و بدون اینکه مالک چیزی توضیح دهد.
#
# ── تضمین‌های این اسکریپت ─────────────────────────────────────────────────────
#   ✓ فقط خواندنی — هیچ فایلی نوشته/حذف نمی‌شود، هیچ workflow ای اجرا نمی‌شود
#   ✓ فقط GET به API گیت‌هاب — هیچ POST/PUT/PATCH/DELETE
#   ✓ هیچ مقدار Secret چاپ نمی‌شود (API گیت‌هاب هم آن را برنمی‌گرداند)
#   ✓ توکن در خروجی ماسک می‌شود
#   ✓ اگر توکن نباشد، بخش‌های آفلاین همچنان کار می‌کنند
#
# ── استفاده ───────────────────────────────────────────────────────────────────
#   bash tools/velora-status.sh              # گزارش متنی
#   GH_TOKEN=xxx bash tools/velora-status.sh # با بخش‌های آنلاین
#   bash tools/velora-status.sh --json       # خروجی JSON (برای PROJECT_STATE.json)
#
# مرجع: docs/README.md §7 — این اسکریپت جایگزین خواندن سند نیست، مکمل آن است.
# ==============================================================================

set -uo pipefail

REPO="veloratrade/veloratrade"
API="https://api.github.com/repos/$REPO"
JSON_MODE=0
[ "${1:-}" = "--json" ] && JSON_MODE=1

TOKEN="${GH_TOKEN:-${GITHUB_TOKEN:-}}"
have_token=0
[ -n "$TOKEN" ] && have_token=1

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
[ "$have_token" = 0 ] && echo "⚠️  بدون GH_TOKEN — بخش‌های آنلاین رد می‌شوند (git محلی کار می‌کند)"
echo

# ── ۱. مخزن ───────────────────────────────────────────────────────────────────
hr; echo "۱. مخزن"; hr
echo "  برنچ         : $(git rev-parse --abbrev-ref HEAD 2>/dev/null)"
echo "  کامیت        : $(git rev-parse --short HEAD 2>/dev/null)"
echo "  تاریخ        : $(git log -1 --format='%ad' --date=iso 2>/dev/null)"
echo "  پیام         : $(git log -1 --format='%s' 2>/dev/null | cut -c1-60)"
dirty=$(git status --porcelain 2>/dev/null | wc -l)
echo "  working tree : $([ "$dirty" = 0 ] && echo 'تمیز ✅' || echo "$dirty فایل تغییرکرده ⚠️")"
echo "  فایل‌ها      : $(git ls-files 2>/dev/null | wc -l)"
echo "  نسخه README  : $(grep -m1 '| نسخه |' docs/README.md 2>/dev/null | tr -dc '0-9.')"
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
            last=f\"{r['created_at'][:16]} {r['event']} → {r['conclusion']}\"
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
else
  echo "  (نیازمند GH_TOKEN)"
fi

# ── ۴. سلامت محیط‌ها ──────────────────────────────────────────────────────────
echo; hr; echo "۴. سلامت محیط‌ها (GET امن)"; hr
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

# ── ۵. موانع و کارهای باز ─────────────────────────────────────────────────────
echo; hr; echo "۵. موانع و کارهای باز (از docs/README.md)"; hr
grep -E '^\| B-[0-9]+ \|' docs/README.md 2>/dev/null | while IFS='|' read -r _ id rest; do
  st=$(echo "$rest" | awk -F'|' '{print $NF}' | sed 's/^ *//;s/ *$//' | cut -c1-46)
  printf '  %-6s %s\n' "$(echo "$id"|tr -d ' ')" "$st"
done
echo
echo "  موانع فعال‌سازی تولید:"
sed -n '/#### چهار مانع/,/#### تحلیل عدد/p' docs/README.md 2>/dev/null \
  | grep -E '^\| [۱۲۳۴]' | sed 's/|/ /g' | cut -c1-72 | sed 's/^/    /'

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
echo
hr
echo "گام بعد: docs/README.md را کامل بخوان (§3 ماتریس FTP • §6 درس‌ها • §7 کار نیمه‌تمام)"
hr
exit 0
fi

# ══════════════════════════════════════════════════════════════════════════════
# حالت JSON
# ══════════════════════════════════════════════════════════════════════════════
export TOK="$TOKEN" HAVE="$have_token" API_URL="$API"
python3 <<'PY' | mask
import json, os, subprocess, urllib.request, datetime

TOK=os.environ.get('TOK',''); HAVE=os.environ.get('HAVE')=='1'; API=os.environ['API_URL']

def sh(c):
    try: return subprocess.run(c,shell=True,capture_output=True,text=True).stdout.strip()
    except Exception: return ''

def api(p):
    if not HAVE: return {}
    try:
        r=urllib.request.Request(API+p); r.add_header('Authorization','Bearer '+TOK)
        return json.load(urllib.request.urlopen(r,timeout=15))
    except Exception: return {}

def probe(u):
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
   "regenerate":"bash tools/velora-status.sh --json > docs/PROJECT_STATE.json",
   "authority":"SNAPSHOT ONLY — منبع حقیقت نیست. مرجع: docs/README.md و P1/P2",
   "staleness_warning":"اگر generated_from_commit با HEAD فعلی فرق دارد، این فایل کهنه است — بازتولید کن",
   "contains_secrets": False
 },
 "repository":{
   "name":"veloratrade/veloratrade","private":True,
   "branch": sh('git rev-parse --abbrev-ref HEAD'),
   "head": sh('git rev-parse --short HEAD'),
   "head_date": sh("git log -1 --format=%ad --date=iso"),
   "head_subject": sh("git log -1 --format=%s"),
   "working_tree_clean": sh('git status --porcelain')=='',
   "tracked_files": int(sh('git ls-files | wc -l') or 0),
   "readme_version": sh("grep -m1 '| نسخه |' docs/README.md | tr -dc '0-9.'"),
 },
 "environments":{
   "production":{
     "url":"https://veloratrade.ir","docroot":"public_html/",
     "private_root":"/home/piknet/velora_private/",
     "behind_cloudflare":True,
     "ftp_host_note":"از veloratrade.ir برای FTP استفاده نشود — پشت CDN است",
     "deploy_workflow":"deploy.yml","status":"UNTOUCHED",
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

if HAVE:
    s=api('/actions/secrets')
    names=[x['name'] for x in s.get('secrets',[])]
    out['secrets']['names']=names
    out['secrets']['production_ftp_configured']=any(
        n in names for n in ('FTP_SERVER','FTP_USERNAME','FTP_PASSWORD'))
    for w in api('/actions/workflows').get('workflows',[]):
        n=w['path'].split('/')[-1]
        try:
            r=urllib.request.Request(w['url']+'/runs?per_page=1')
            r.add_header('Authorization','Bearer '+TOK)
            d=json.load(urllib.request.urlopen(r,timeout=15))
            last=d['workflow_runs'][0] if d['workflow_runs'] else None
            out['workflows'][n]={"state":w['state'],"total_runs":d['total_count'],
              "last_run":{"at":last['created_at'],"event":last['event'],
                          "conclusion":last['conclusion']} if last else None}
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
            p=[x.strip() for x in line.split('|')]
            if len(p)>4: bl.append({"id":p[1],"status":p[4][:90]})
except Exception: pass
out['backlog']=bl
out['next_step']="docs/README.md را کامل بخوان — §3 ماتریس FTP، §6 درس‌ها، §7 کار نیمه‌تمام"

print(json.dumps(out, ensure_ascii=False, indent=2))
PY
