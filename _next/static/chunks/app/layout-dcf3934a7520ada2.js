(self.webpackChunk_N_E=self.webpackChunk_N_E||[]).push([[177],{8082:(e,t,a)=>{Promise.resolve().then(a.bind(a,1069)),Promise.resolve().then(a.bind(a,83677)),Promise.resolve().then(a.t.bind(a,78212,23)),Promise.resolve().then(a.t.bind(a,61820,23)),Promise.resolve().then(a.t.bind(a,19324,23)),Promise.resolve().then(a.bind(a,30814))},25739:(e,t,a)=>{"use strict";a.d(t,{Ti:()=>d,V$:()=>i,hD:()=>o,nr:()=>l,rw:()=>r,wC:()=>u});let r={access:"tj_access_token",refresh:"tj_refresh_token",user:"tj_user"};class o extends Error{constructor(e,t,a,r){super(e),this.name="ApiError",this.status=t,this.code=null!=a?a:null,this.details=r}}let n=null;async function s(){return n||(n=(async()=>{let e=localStorage.getItem(r.refresh);if(!e)return!1;try{var t;let a=await fetch("".concat("","/api/v1/auth/refresh"),{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({refreshToken:e})});if(!a.ok)return!1;let o=await a.json(),n=null==o?void 0:null===(t=o.data)||void 0===t?void 0:t.tokens;if(!(null==n?void 0:n.accessToken)||!(null==n?void 0:n.refreshToken))return!1;return localStorage.setItem(r.access,n.accessToken),localStorage.setItem(r.refresh,n.refreshToken),n.user&&localStorage.setItem(r.user,JSON.stringify(n.user)),!0}catch(e){return!1}finally{n=null}})())}async function l(e){let t=arguments.length>1&&void 0!==arguments[1]?arguments[1]:{},{method:a="GET",body:n,auth:l=!0,retryOn401:i=!0}=t,d={"Content-Type":"application/json"};if(l){let e=localStorage.getItem(r.access);e&&(d.Authorization="Bearer ".concat(e))}let c=await fetch("".concat("").concat(e),{method:a,headers:d,body:void 0!==n?JSON.stringify(n):void 0});if(401===c.status&&i&&l&&await s()){let t=localStorage.getItem(r.access);t&&(d.Authorization="Bearer ".concat(t)),c=await fetch("".concat("").concat(e),{method:a,headers:d,body:void 0!==n?JSON.stringify(n):void 0})}let u=await c.json().catch(()=>null);if(!c.ok||(null==u?void 0:u.status)==="error"){var h,m,v,f;throw new o(null!==(f=null==u?void 0:null===(h=u.error)||void 0===h?void 0:h.message)&&void 0!==f?f:"خطای ناشناخته از سمت سرور",c.status,null==u?void 0:null===(m=u.error)||void 0===m?void 0:m.code,null==u?void 0:null===(v=u.error)||void 0===v?void 0:v.details)}return u.data}function i(){localStorage.removeItem(r.access),localStorage.removeItem(r.refresh),localStorage.removeItem(r.user)}function d(){try{let e=localStorage.getItem(r.user);return e?JSON.parse(e):null}catch(e){return null}}let c=["entryPrice","exitPrice","volume","commission","swap","profitLoss","rMultiple","stopLoss","takeProfit"];function u(e){let t={...e};for(let e of c){let a=t[e];"string"==typeof a&&(t[e]=parseFloat(a))}return t}},1069:(e,t,a)=>{"use strict";a.d(t,{A:()=>i,AuthProvider:()=>l});var r=a(95155),o=a(12115),n=a(25739);let s=o.createContext(null);function l(e){var t;let{children:a}=e,[l,i]=o.useState(null),[d,c]=o.useState(!1);o.useEffect(()=>{let e=!1;return(async()=>{let t=(0,n.Ti)();t&&i(t);try{let t=await (0,n.nr)("/api/v1/auth/me");e||(i(t.user),localStorage.setItem(n.rw.user,JSON.stringify(t.user)))}catch(a){a instanceof n.hD&&401===a.status?((0,n.V$)(),e||i(null)):e||i(null!=t?t:null)}finally{e||c(!0)}})(),()=>{e=!0}},[]);let u=o.useCallback(async(e,t)=>{let a=await (0,n.nr)("/api/v1/auth/login",{method:"POST",body:{email:e,password:t},auth:!1});localStorage.setItem(n.rw.access,a.tokens.accessToken),localStorage.setItem(n.rw.refresh,a.tokens.refreshToken),localStorage.setItem(n.rw.user,JSON.stringify(a.tokens.user)),i(a.tokens.user)},[]),h=o.useCallback(async(e,t,a)=>{let r=await (0,n.nr)("/api/v1/auth/register",{method:"POST",body:{email:e,password:t,fullName:a},auth:!1});!r.verificationRequired&&r.tokens&&(localStorage.setItem(n.rw.access,r.tokens.accessToken),localStorage.setItem(n.rw.refresh,r.tokens.refreshToken),localStorage.setItem(n.rw.user,JSON.stringify(r.tokens.user)),i(r.tokens.user))},[]),m=o.useCallback(async()=>{try{let e=localStorage.getItem(n.rw.refresh);e&&await (0,n.nr)("/api/v1/auth/logout",{method:"POST",body:{refreshToken:e},auth:!1})}catch(e){}(0,n.V$)(),i(null)},[]);return(0,r.jsx)(s.Provider,{value:{isAuthenticated:null!==l,user:l,role:null!==(t=null==l?void 0:l.role)&&void 0!==t?t:"user",ready:d,login:u,register:h,logout:m},children:a})}function i(){let e=o.useContext(s);if(!e)throw Error("useAuth باید داخل AuthProvider استفاده شود");return e}},83677:(e,t,a)=>{"use strict";a.d(t,{I18nProvider:()=>i,s:()=>d});var r=a(95155),o=a(12115);let n={fa:{"nav.dashboard":"داشبورد","nav.analysis":"تحلیل","nav.newTrade":"ثبت معامله","nav.profile":"پروفایل","nav.settings":"تنظیمات","nav.calendar":"تقویم","nav.notifications":"اعلان‌ها","nav.home":"خانه","nav.journal":"ژورنال","nav.performance":"آنالیز عملکرد","header.balance":"بالانس فعلی","header.search":"جستجو در معاملات، نمادها...","dash.totalPnl":"سود / زیان کل","dash.winRate":"نرخ برد","dash.trades":"معامله","dash.profitFactor":"پروفیت فکتور","dash.avgR":"میانگین R","dash.bestTrade":"بهترین معامله","dash.equityCurve":"منحنی سود (۳۰ روز اخیر)","dash.recentTrades":"معاملات اخیر","dash.viewAll":"مشاهده همه","dash.noTrades":"هنوز معامله‌ای ثبت نشده است","dash.firstTrade":"ثبت اولین معامله","dash.noData":"داده‌ای برای نمایش وجود ندارد","trade.mainInfo":"اطلاعات اصلی معامله","trade.symbol":"نماد","trade.direction":"جهت معامله","trade.buy":"خرید","trade.sell":"فروش","trade.volume":"حجم (لات)","trade.commission":"کمیسیون (دلار)","trade.entry":"قیمت ورود","trade.exit":"قیمت خروج","trade.tp":"حد سود (TP)","trade.sl":"حد ضرر (SL)","trade.openTime":"زمان ورود","trade.closeTime":"زمان خروج","trade.advanced":"نمایش تنظیمات پیشرفته","trade.journal":"ژورنال و روان‌شناسی معامله","trade.strategy":"تگ استراتژی","trade.notes":"یادداشت","trade.screenshot":"چارت اسکرین‌شات","trade.submit":"ثبت معامله","trade.cancel":"انصراف","trade.searchSymbol":"جستجو و انتخاب نماد...","auth.welcome":"خوش آمدید","auth.loginSubtitle":"وارد حساب خود شوید","auth.email":"ایمیل","auth.password":"رمز عبور","auth.login":"ورود به حساب","auth.register":"ساخت حساب","auth.forgotPassword":"فراموشی رمز؟","auth.fullName":"نام و نام‌خانوادگی","auth.confirmPassword":"تکرار رمز عبور","auth.noAccount":"هنوز حساب نساخته‌اید؟","auth.registerNow":"ثبت‌نام کنید","auth.haveAccount":"حساب دارید؟","auth.loginNow":"وارد شوید","pwd.changeTitle":"تغییر رمز عبور","pwd.current":"رمز فعلی","pwd.new":"رمز جدید","pwd.update":"به‌روزرسانی رمز","pwd.forgotTitle":"بازیابی رمز عبور","pwd.forgotSubtitle":"ایمیل خود را وارد کنید تا لینک بازیابی برایتان ارسال شود","pwd.send":"ارسال لینک بازیابی","pwd.resetTitle":"تنظیم رمز جدید","pwd.resetSubtitle":"رمز جدید خود را وارد کنید","pwd.reset":"بازنشانی رمز عبور","pwd.backToLogin":"بازگشت به ورود","common.save":"ذخیره تغییرات","common.loading":"در حال بارگذاری...","common.pro":"Pro Plan","common.free":"پلن رایگان"},en:{"nav.dashboard":"Dashboard","nav.analysis":"Analysis","nav.newTrade":"New Trade","nav.profile":"Profile","nav.settings":"Settings","nav.calendar":"Calendar","nav.notifications":"Notifications","nav.home":"Home","nav.journal":"Journal","nav.performance":"Performance","header.balance":"Current Balance","header.search":"Search trades, symbols...","dash.totalPnl":"Total P&L","dash.winRate":"Win Rate","dash.trades":"trades","dash.profitFactor":"Profit Factor","dash.avgR":"Avg R","dash.bestTrade":"Best Trade","dash.equityCurve":"Equity Curve (30 days)","dash.recentTrades":"Recent Trades","dash.viewAll":"View all","dash.noTrades":"No trades yet","dash.firstTrade":"Add your first trade","dash.noData":"No data available","trade.mainInfo":"Trade Information","trade.symbol":"Symbol","trade.direction":"Direction","trade.buy":"Buy","trade.sell":"Sell","trade.volume":"Volume (lots)","trade.commission":"Commission (USD)","trade.entry":"Entry Price","trade.exit":"Exit Price","trade.tp":"Take Profit (TP)","trade.sl":"Stop Loss (SL)","trade.openTime":"Open Time","trade.closeTime":"Close Time","trade.advanced":"Show advanced settings","trade.journal":"Journal & Psychology","trade.strategy":"Strategy Tag","trade.notes":"Notes","trade.screenshot":"Chart Screenshot","trade.submit":"Save Trade","trade.cancel":"Cancel","trade.searchSymbol":"Search and select symbol...","auth.welcome":"Welcome","auth.loginSubtitle":"Sign in to your account","auth.email":"Email","auth.password":"Password","auth.login":"Sign In","auth.register":"Create Account","auth.forgotPassword":"Forgot password?","auth.fullName":"Full Name","auth.confirmPassword":"Confirm Password","auth.noAccount":"Don't have an account?","auth.registerNow":"Sign up","auth.haveAccount":"Already have an account?","auth.loginNow":"Sign in","pwd.changeTitle":"Change Password","pwd.current":"Current Password","pwd.new":"New Password","pwd.update":"Update Password","pwd.forgotTitle":"Reset Password","pwd.forgotSubtitle":"Enter your email and we'll send you a recovery link","pwd.send":"Send Recovery Link","pwd.resetTitle":"Set New Password","pwd.resetSubtitle":"Enter your new password","pwd.reset":"Reset Password","pwd.backToLogin":"Back to login","common.save":"Save Changes","common.loading":"Loading...","common.pro":"Pro Plan","common.free":"Free Plan"}},s=o.createContext(null),l="velora_lang";function i(e){let{children:t}=e,[a,i]=o.useState("fa");o.useEffect(()=>{try{let e=localStorage.getItem(l),t="en"===e||"fa"===e?e:"fa";i(t),document.documentElement.dir="fa"===t?"rtl":"ltr",document.documentElement.lang=t}catch(e){}},[]);let d=o.useCallback(e=>{i(e);try{localStorage.setItem(l,e)}catch(e){}document.documentElement.dir="fa"===e?"rtl":"ltr",document.documentElement.lang=e},[]),c=o.useCallback(e=>{var t,r;return null!==(r=null!==(t=n[a][e])&&void 0!==t?t:n.fa[e])&&void 0!==r?r:e},[a]);return(0,r.jsx)(s.Provider,{value:{lang:a,setLang:d,t:c},children:t})}function d(){let e=o.useContext(s);if(!e)throw Error("useI18n must be used within I18nProvider");return e}},19324:()=>{},61820:e=>{e.exports={style:{fontFamily:"'JetBrains Mono', 'JetBrains Mono Fallback'",fontStyle:"normal"},className:"__className_3c557b",variable:"__variable_3c557b"}},78212:e=>{e.exports={style:{fontFamily:"'Vazirmatn', 'Vazirmatn Fallback'",fontStyle:"normal"},className:"__className_2e86e3",variable:"__variable_2e86e3"}}},e=>{var t=t=>e(e.s=t);e.O(0,[141,814,441,517,358],()=>t(8082)),_N_E=e.O()}]);
;/**
 * VELORA TRADE — Frontend Authentication Enhancer v35 (Universal Registration & 3-Request Limit Edition)
 * نمایش اتوماتیک مودال در ثبت‌نام موفق و همچنین نمایش فوری کارت قرمز محدودیت هنگام عبور از سقف ۳ بار در ثبت‌نام یا ارسال مجدد
 */
(function () {
  'use strict';

  if (window.__veloraAuthEnhancerLoadedV35) return;
  window.__veloraAuthEnhancerLoadedV35 = true;

  const styles = `
    #velora-auth-modal-backdrop {
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      background: rgba(7, 11, 18, 0.85);
      backdrop-filter: blur(8px);
      z-index: 2147483647;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 16px;
      font-family: Tahoma, Arial, sans-serif;
      direction: rtl;
      opacity: 0;
      transition: opacity 0.3s ease;
    }
    #velora-auth-modal-backdrop.show { opacity: 1; }
    #velora-auth-modal-box {
      background: #111827;
      border: 1px solid #334155;
      border-radius: 20px;
      max-width: 540px;
      width: 100%;
      padding: 32px 28px;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6);
      text-align: center;
      transform: translateY(20px);
      transition: transform 0.3s ease;
      position: relative;
      max-height: 90vh;
      overflow-y: auto;
    }
    #velora-auth-modal-backdrop.show #velora-auth-modal-box { transform: translateY(0); }
    .velora-modal-logo { margin: 0 auto 18px; display: flex; justify-content: center; }
    .velora-modal-title { color: #ffffff; font-size: 22px; font-weight: bold; margin-bottom: 12px; }
    .velora-modal-text { color: #cbd5e1; font-size: 14px; line-height: 1.9; margin-bottom: 16px; }
    .velora-email-badge {
      background: #0f172a; border: 1px solid #d4af37; color: #d4af37; font-weight: bold;
      padding: 12px 18px; border-radius: 10px; margin: 16px 0; direction: ltr;
      display: inline-block; font-size: 15px; box-shadow: 0 4px 15px rgba(212, 175, 55, 0.15);
    }
    .velora-help-box {
      background: #1e293b; border-left: 4px solid #38bdf8; color: #e2e8f0;
      padding: 16px 18px; border-radius: 12px; font-size: 13px; line-height: 1.9;
      text-align: right; margin-top: 24px; display: flex; gap: 14px; align-items: flex-start;
    }
    .velora-btn-gold {
      background: linear-gradient(135deg, #fce38a 0%, #d4af37 50%, #b88d1d 100%);
      color: #0b121e; border: none; padding: 12px 24px; border-radius: 10px;
      font-family: inherit; font-size: 14px; font-weight: bold; cursor: pointer;
      display: inline-block; text-decoration: none; margin-top: 12px;
      box-shadow: 0 4px 14px rgba(212, 175, 55, 0.25); transition: all 0.2s;
    }
    .velora-btn-outline {
      background: transparent; color: #cbd5e1; border: 1px solid #475569;
      padding: 11px 22px; border-radius: 10px; font-size: 13px; font-weight: bold;
      margin-top: 12px; cursor: pointer; transition: all 0.2s;
    }
    .velora-btn-outline:disabled { opacity: 0.5; cursor: not-allowed; }
    .velora-status-card {
      display: flex; align-items: flex-start; gap: 16px; background: #0f172a;
      border: 1px solid #334155; border-radius: 14px; padding: 18px 20px;
      margin-top: 20px; text-align: right;
    }
    .velora-status-card.success { border-color: #10b981; background: rgba(16,185,129,0.05); }
    .velora-status-card.warning { border-color: #f59e0b; background: rgba(245,158,11,0.05); }
    .velora-status-card.danger { border-color: #ef4444; background: rgba(239,68,68,0.06); }
    .velora-status-card.verified { border-color: #38bdf8; background: rgba(56,189,248,0.05); }
    .velora-status-icon { flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
    .velora-status-text strong { display: block; color: #ffffff; font-size: 14px; margin-bottom: 4px; }
    .velora-status-text span { color: #cbd5e1; font-size: 13px; line-height: 1.8; }
    .velora-close-btn {
      position: absolute; top: 16px; left: 16px; background: transparent;
      border: none; color: #64748b; font-size: 22px; cursor: pointer;
    }
  `;

  const styleEl = document.createElement('style');
  styleEl.innerHTML = styles;
  document.head.appendChild(styleEl);

  let cooldownTimer = null;
  let lastEmail = '';
  let registerSubmittedTime = 0;

  const SVG_ICONS = {
    heroBadge: `
      <svg width="68" height="68" viewBox="0 0 78 78" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect width="78" height="78" rx="20" fill="#0f172a" stroke="#d4af37" stroke-width="2"/>
        <circle cx="38" cy="38" r="26" fill="#d4af37" fill-opacity="0.15"/>
        <circle cx="38" cy="32" r="10" fill="#d4af37" fill-opacity="0.3" stroke="#d4af37" stroke-width="2"/>
        <path d="M22 56C22 47 29 45 38 45C47 45 54 47 54 56" stroke="#d4af37" stroke-width="2.5" stroke-linecap="round"/>
        <circle cx="56" cy="26" r="11" fill="#10b981" stroke="#0f172a" stroke-width="2.5"/>
        <path d="M51.5 26L54 28.5L59.5 22.5" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    `,
    mailDispatch: `
      <svg width="48" height="48" viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect width="56" height="56" rx="14" fill="#064e3b" stroke="#10b981" stroke-width="1.5"/>
        <circle cx="28" cy="28" r="18" fill="#047857" fill-opacity="0.4"/>
        <path d="M16 20C16 18.3431 17.3431 17 19 17H37C38.6569 17 40 18.3431 40 20V34C40 35.6569 38.6569 37 37 37H19C17.3431 37 16 35.6569 16 34V20Z" fill="#065f46" stroke="#34d399" stroke-width="2"/>
        <path d="M19 20L28 27L37 20" stroke="#a7f3d0" stroke-width="2" stroke-linecap="round"/>
        <circle cx="39" cy="35" r="9" fill="#10b981" stroke="#064e3b" stroke-width="2"/>
        <path d="M35.5 35L38 37.5L42.5 32.5" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    `,
    alertShield: `
      <svg width="48" height="48" viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect width="56" height="56" rx="14" fill="#450a0a" stroke="#ef4444" stroke-width="1.5"/>
        <circle cx="28" cy="28" r="18" fill="#791616" fill-opacity="0.4"/>
        <path d="M28 12L42 18V28C42 37.5 36 44 28 47C20 44 14 37.5 14 28V18L28 12Z" fill="#7f1d1d" stroke="#f87171" stroke-width="2"/>
        <path d="M28 21V29" stroke="#ffffff" stroke-width="3" stroke-linecap="round"/>
        <circle cx="28" cy="35" r="2" fill="#ffffff"/>
      </svg>
    `,
    hourglassTimer: `
      <svg width="48" height="48" viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect width="56" height="56" rx="14" fill="#451a03" stroke="#f59e0b" stroke-width="1.5"/>
        <circle cx="28" cy="28" r="18" fill="#78350f" fill-opacity="0.4"/>
        <circle cx="28" cy="28" r="12" stroke="#fbbf24" stroke-width="2" stroke-dasharray="4 4"/>
        <path d="M28 20V28L33 31" stroke="#fde68a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        <circle cx="28" cy="28" r="2.5" fill="#f59e0b"/>
      </svg>
    `,
    verifiedShield: `
      <svg width="48" height="48" viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect width="56" height="56" rx="14" fill="#0f172a" stroke="#38bdf8" stroke-width="1.5"/>
        <circle cx="28" cy="28" r="18" fill="#0369a1" fill-opacity="0.3"/>
        <path d="M28 14L40 19V28C40 36.5 35 43 28 46C21 43 16 36.5 16 28V19L28 14Z" fill="#0c4a6e" stroke="#38bdf8" stroke-width="2"/>
        <circle cx="28" cy="29" r="8" fill="#10b981" stroke="#0f172a" stroke-width="1.5"/>
        <path d="M24.5 29L27 31.5L32 26.5" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    `
  };

  /**
   * مخفی‌سازی ایمن نوتیفیکیشن‌های پیش‌فرض سایت بدون حذف از DOM ری‌اکت
   */
  function hideReactToastSafe(el) {
    if (!el || !el.style) return;
    el.style.setProperty('display', 'none', 'important');
    el.style.setProperty('visibility', 'hidden', 'important');
    el.style.setProperty('opacity', '0', 'important');
    el.style.setProperty('pointer-events', 'none', 'important');
    el.style.setProperty('height', '0px', 'important');
    el.style.setProperty('margin', '0px', 'important');
    el.style.setProperty('padding', '0px', 'important');
    el.style.setProperty('position', 'absolute', 'important');
    el.style.setProperty('z-index', '-9999', 'important');
  }

  function stopSpinningSubmitButtons() {
    const btns = document.querySelectorAll('button[type="submit"], button');
    btns.forEach(btn => {
      const txt = btn.textContent || '';
      if (txt.includes('ساخت حساب')) {
        btn.disabled = false;
        btn.innerHTML = 'ساخت حساب';
      }
    });
  }

  function suppressRegistrationToastsFor3Seconds() {
    window.__veloraSuppressToastsUntil = Date.now() + 3500;
    const hideExistingToasts = () => {
      const toasts = document.querySelectorAll('[data-sonner-toast], [data-sonner-toaster] li, [role="status"], [role="alert"], .toast, .notification');
      toasts.forEach(el => {
        const txt = el.textContent || '';
        if (
          (txt.includes('حساب') || txt.includes('لینک') || txt.includes('تأیید') || txt.includes('تایید') || txt.includes('ارسال')) &&
          !txt.includes('خطا') && !txt.includes('اشتباه') && !txt.includes('نامعتبر')
        ) {
          hideReactToastSafe(el);
        }
      });
    };
    hideExistingToasts();
    const interval = setInterval(() => {
      if (Date.now() > window.__veloraSuppressToastsUntil) {
        clearInterval(interval);
      } else {
        hideExistingToasts();
      }
    }, 50);
  }

  function showSignupModal(userEmail) {
    if (userEmail && typeof userEmail === 'string') lastEmail = userEmail;
    else userEmail = lastEmail || window.__lastVeloraRegisterEmail || 'user@example.com';

    suppressRegistrationToastsFor3Seconds();

    let backdrop = document.getElementById('velora-auth-modal-backdrop');
    if (backdrop) backdrop.remove();

    backdrop = document.createElement('div');
    backdrop.id = 'velora-auth-modal-backdrop';

    backdrop.innerHTML = `
      <div id="velora-auth-modal-box">
        <button class="velora-close-btn" onclick="document.getElementById('velora-auth-modal-backdrop').remove()">×</button>
        <div class="velora-modal-logo">
          ${SVG_ICONS.heroBadge}
        </div>
        <h2 class="velora-modal-title">حساب شما با موفقیت ایجاد شد!</h2>
        <p class="velora-modal-text">
          برای فعال‌سازی حساب کاربری و دسترسی به داشبورد معاملاتی، لینک تأیید ۲۴ ساعته به ایمیل زیر ارسال شد:
        </p>
        <div class="velora-email-badge">${userEmail}</div>
        <p class="velora-modal-text">
          لطفاً روی لینک موجود در ایمیل کلیک کنید تا حساب شما فعال شود.
        </p>
        
        <div class="velora-help-box">
          <div style="flex-shrink:0;margin-top:2px;">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#38bdf8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
          </div>
          <div>
            <strong>ایمیل به دستتان نرسید؟ چه کار باید کرد:</strong><br>
            ۱. لطفاً پوشه <b>Spam (هرزنامه)</b> یا <b>Junk</b> ایمیل خود را بررسی کنید.<br>
            ۲. اعتبار لینک ارسالی <b>۲۴ ساعت</b> است.<br>
            ۳. هر کاربر حداکثر <b>۳ بار در ۲۴ ساعت</b> می‌تواند ایمیل تأیید دریافت کند (با فاصله ۱ دقیقه بین هر درخواست).
          </div>
        </div>

        <div id="velora-resend-status-area"></div>
        
        <div style="margin-top:24px; display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
          <button id="velora-resend-btn" class="velora-btn-outline" disabled>ارسال مجدد ایمیل تأیید (01:00)</button>
          <a href="https://veloratrade.ir/login" class="velora-btn-gold">ورود به صفحه لاگین</a>
        </div>
      </div>
    `;

    stopSpinningSubmitButtons();
    (document.documentElement || document.body).appendChild(backdrop);
    setTimeout(() => backdrop.classList.add('show'), 10);
    startResendCooldown(60, userEmail);
  }

  function startResendCooldown(seconds, userEmail) {
    const btn = document.getElementById('velora-resend-btn');
    if (!btn) return;
    if (cooldownTimer) clearInterval(cooldownTimer);

    let remaining = seconds;
    btn.disabled = true;

    const updateLabel = () => {
      const min = String(Math.floor(remaining / 60)).padStart(2, '0');
      const sec = String(remaining % 60).padStart(2, '0');
      btn.innerText = `ارسال مجدد ایمیل تأیید (${min}:${sec})`;
    };

    updateLabel();

    cooldownTimer = setInterval(() => {
      remaining--;
      if (remaining <= 0) {
        clearInterval(cooldownTimer);
        btn.disabled = false;
        btn.innerText = 'ارسال مجدد ایمیل تأیید';
        btn.onclick = () => triggerResend(userEmail);
      } else {
        updateLabel();
      }
    }, 1000);
  }

  async function triggerResend(userEmail) {
    const statusArea = document.getElementById('velora-resend-status-area');
    const btn = document.getElementById('velora-resend-btn');
    if (!statusArea || !btn) return;

    window.__veloraResendAttempts = (window.__veloraResendAttempts || 0) + 1;
    btn.disabled = true;
    btn.innerText = 'در حال ارسال...';

    try {
      const res = await fetch('/api/v1/auth/resend-verification', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: userEmail })
      });
      const data = await res.json();
      const payload = data.data || data || {};
      const errMsg =
        (typeof data.error === 'string' ? data.error : (data.error && data.error.message)) ||
        data.message ||
        data.msg ||
        data.detail ||
        payload.message ||
        payload.error ||
        '';

      if (res.ok && (payload.sent || (payload.message && payload.message.includes('ارسال شد')))) {
        statusArea.innerHTML = `
          <div class="velora-status-card success">
            <div class="velora-status-icon">${SVG_ICONS.mailDispatch}</div>
            <div class="velora-status-text">
              <strong>لینک تأیید جدید با موفقیت ارسال شد!</strong>
              <span>${payload.message || 'لطفاً پوشه Inbox و Spam ایمیل خود را بررسی کنید.'}</span>
            </div>
          </div>
        `;
        startResendCooldown(60, userEmail);
      } else if (payload.alreadyVerified) {
        statusArea.innerHTML = `
          <div class="velora-status-card verified">
            <div class="velora-status-icon">${SVG_ICONS.verifiedShield}</div>
            <div class="velora-status-text">
              <strong>حساب شما قبلاً تأیید شده است!</strong>
              <span>${payload.message || 'می‌توانید مستقیماً وارد حساب کاربری خود شوید.'}</span>
            </div>
          </div>
        `;
        btn.innerText = 'حساب تأیید شده است';
      } else {
        showErrorCard(errMsg || 'خطا در ارسال مجدد ایمیل.', res.status);
      }
    } catch (err) {
      showErrorCard('شما به حداکثر تعداد مجاز ارسال ایمیل تأیید (۳ بار در ۲۴ ساعت) رسیده‌اید.', 429);
    }
  }

  function showErrorCard(msg, statusCode) {
    const statusArea = document.getElementById('velora-resend-status-area');
    const btn = document.getElementById('velora-resend-btn');
    if (!statusArea) return;

    const errMsgStr = typeof msg === 'string' && msg.trim() !== '' ? msg : 'شما به حداکثر تعداد مجاز ارسال ایمیل تأیید (۳ بار در ۲۴ ساعت) رسیده‌اید.';
    if (
      errMsgStr.includes('۳ بار') ||
      errMsgStr.includes('سقف') ||
      errMsgStr.includes('حداکثر') ||
      errMsgStr.includes('محدودیت') ||
      errMsgStr.includes('روزانه') ||
      statusCode === 429 ||
      statusCode === 400 ||
      statusCode === 422 ||
      (window.__veloraResendAttempts && window.__veloraResendAttempts >= 4)
    ) {
      statusArea.innerHTML = `
        <div class="velora-status-card danger">
          <div class="velora-status-icon">${SVG_ICONS.alertShield}</div>
          <div class="velora-status-text">
            <strong>محدودیت امنیتی (حداکثر ۳ بار در ۲۴ ساعت):</strong>
            <span>${errMsgStr}</span>
          </div>
        </div>
      `;
      if (btn) btn.innerText = 'رسیدن به سقف مجاز روزانه';
    } else {
      statusArea.innerHTML = `
        <div class="velora-status-card warning">
          <div class="velora-status-icon">${SVG_ICONS.hourglassTimer}</div>
          <div class="velora-status-text">
            <strong>محدودیت زمانی بین درخواست‌ها:</strong>
            <span>${errMsgStr}</span>
          </div>
        </div>
      `;
      startResendCooldown(60, '');
    }
  }

  // ۱. ضبط زنده ایمیل از فرم
  document.addEventListener('input', function (e) {
    const el = e.target;
    if (el && (el.type === 'email' || el.name === 'email' || el.id === 'email' || (el.placeholder && el.placeholder.includes('@')))) {
      if (el.value && el.value.includes('@')) {
        window.__lastVeloraRegisterEmail = el.value.trim();
      }
    }
  }, true);

  // ۲. رهگیری کلیک دکمه ساخت حساب + تایمر ایمنی ۲.۵ ثانیه‌ای
  document.addEventListener('click', function (e) {
    const target = e.target;
    const btn = target && target.closest ? target.closest('button, [type="submit"]') : null;
    const text = (btn ? btn.textContent : target ? target.textContent : '') || '';
    if (text.includes('ساخت حساب') || (btn && btn.type === 'submit')) {
      registerSubmittedTime = Date.now();
      const emailInput = document.querySelector('input[type="email"], input[name="email"], input[id="email"]');
      if (emailInput && emailInput.value) {
        window.__lastVeloraRegisterEmail = emailInput.value.trim();
      }
      setTimeout(() => {
        if (!document.getElementById('velora-auth-modal-backdrop')) {
          stopSpinningSubmitButtons();
          showSignupModal(window.__lastVeloraRegisterEmail || 'user@example.com');
        }
      }, 2500);
    }
  }, true);

  // ۳. رهگیری fetch برای موفقیت یا خطای سقف ۳ بار
  const origFetch = window.fetch;
  window.fetch = async function (...args) {
    let url = '';
    if (typeof args[0] === 'string') url = args[0];
    else if (args[0] && args[0].url) url = args[0].url;

    let reqEmail = '';
    if (args[1] && args[1].body && typeof args[1].body === 'string') {
      try {
        const bodyObj = JSON.parse(args[1].body);
        if (bodyObj && bodyObj.email) reqEmail = bodyObj.email;
      } catch (e) {}
    }

    const res = await origFetch.apply(this, args);

    if (url && url.includes('register')) {
      if (res.status === 201 || res.status === 200 || res.ok) {
        let respEmail = '';
        try {
          const clone = res.clone();
          const data = await clone.json();
          respEmail = (data && data.tokens && data.tokens.email) || (data && data.email) || '';
        } catch (e) {}
        const email = respEmail || reqEmail || window.__lastVeloraRegisterEmail || 'user@example.com';
        setTimeout(() => showSignupModal(email), 100);
      } else if (res.status === 422 || res.status === 429 || res.status === 400) {
        // بررسی خطای عبور از سقف ۳ بار در ثبت‌نام مجدد
        try {
          const clone = res.clone();
          const errData = await clone.json();
          const errMsg = (errData.error && errData.error.message) || errData.message || '';
          if (errMsg.includes('۳ بار') || errMsg.includes('سقف') || errMsg.includes('حداکثر') || errMsg.includes('روزانه')) {
            const email = reqEmail || window.__lastVeloraRegisterEmail || 'user@example.com';
            setTimeout(() => {
              showSignupModal(email);
              setTimeout(() => {
                showErrorCard(errMsg, 429);
              }, 100);
            }, 50);
          }
        } catch (e) {}
      }
    }
    return res;
  };

  // ۴. رهگیری XMLHttpRequest (XHR)
  const origOpen = XMLHttpRequest.prototype.open;
  const origSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function (method, url, ...rest) {
    this._veloraUrl = typeof url === 'string' ? url : '';
    return origOpen.call(this, method, url, ...rest);
  };
  XMLHttpRequest.prototype.send = function (body) {
    if (this._veloraUrl && this._veloraUrl.includes('register')) {
      let reqEmail = '';
      if (typeof body === 'string') {
        try {
          const bodyObj = JSON.parse(body);
          if (bodyObj && bodyObj.email) reqEmail = bodyObj.email;
        } catch (e) {}
      }
      this.addEventListener('load', function () {
        if (this.status === 201 || this.status === 200 || (this.status >= 200 && this.status < 300)) {
          let respEmail = '';
          try {
            const data = JSON.parse(this.responseText);
            respEmail = (data && data.tokens && data.tokens.email) || (data && data.email) || '';
          } catch (e) {}
          const email = respEmail || reqEmail || window.__lastVeloraRegisterEmail || 'user@example.com';
          setTimeout(() => showSignupModal(email), 100);
        } else if (this.status === 422 || this.status === 429 || this.status === 400) {
          try {
            const errData = JSON.parse(this.responseText);
            const errMsg = (errData.error && errData.error.message) || errData.message || '';
            if (errMsg.includes('۳ بار') || errMsg.includes('سقف') || errMsg.includes('حداکثر') || errMsg.includes('روزانه')) {
              const email = reqEmail || window.__lastVeloraRegisterEmail || 'user@example.com';
              setTimeout(() => {
                showSignupModal(email);
                setTimeout(() => {
                  showErrorCard(errMsg, 429);
                }, 100);
              }, 50);
            }
          } catch (e) {}
        }
      });
    }
    return origSend.call(this, body);
  };

  // ۵. ناظر صفحه: مخفی‌سازی ایمن نوتیفیکیشن پیش‌فرض (بدون removeChild) و نمایش فقط مودال اختصاصی
  const observer = new MutationObserver((mutations) => {
    for (const m of mutations) {
      for (const node of m.addedNodes) {
        if (node.nodeType === 1) {
          if (node.id === 'velora-auth-modal-backdrop' || (node.closest && node.closest('#velora-auth-modal-backdrop'))) continue;
          
          const isToastContainer = 
            node.hasAttribute('data-sonner-toast') ||
            node.hasAttribute('data-sonner-toaster') ||
            node.getAttribute('role') === 'status' ||
            node.getAttribute('role') === 'alert' ||
            (node.className && typeof node.className === 'string' && (node.className.includes('toast') || node.className.includes('notification'))) ||
            (node.closest && (node.closest('[data-sonner-toaster]') || node.closest('[aria-label="Notifications"]')));

          if (isToastContainer) {
            const text = node.textContent || '';
            if (
              (text.includes('حساب') && (text.includes('ساخت') || text.includes('ایجاد') || text.includes('موفق'))) ||
              (text.includes('لینک') && (text.includes('تأیید') || text.includes('تایید'))) ||
              (text.includes('ایمیل') && (text.includes('ارسال') || text.includes('تأیید') || text.includes('تایید')))
            ) {
              if (!text.includes('خطا') && !text.includes('اشتباه') && !text.includes('نامعتبر') && text.length > 5) {
                if (window.location.pathname.includes('register') || Date.now() - registerSubmittedTime < 30000) {
                  hideReactToastSafe(node);
                  const email = window.__lastVeloraRegisterEmail || 'user@example.com';
                  setTimeout(() => showSignupModal(email), 50);
                }
              }
            }
          }
        }
      }
    }
  });
  observer.observe(document.body, { childList: true, subtree: true });

  window.veloraShowSignupModal = showSignupModal;
})();
