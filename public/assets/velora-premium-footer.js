/* Adds one consistent, product-grade footer to legacy linked pages. */
(function (document) {
  function init() {
    if (document.querySelector('.velora-premium-footer') || document.body.dataset.noPremiumFooter) return;
    var lang=(document.documentElement.lang||'fa').toLowerCase(), en=lang.indexOf('en')===0;
    var c=en?{
      copy:'A focused workspace for recording, analysing and improving every trading decision.',
      product:'PRODUCT',resources:'RESOURCES',company:'COMPANY',dash:'Dashboard',journal:'Trading Journal',ai:'AI Intelligence',performance:'Performance',markets:'Markets',support:'Help Center',blog:'Blog',privacy:'Privacy',terms:'Terms',home:'Home',rights:'© 2026 VELORA. All rights reserved.',status:'Privacy-first product experience'
    }:{
      copy:'فضایی متمرکز برای ثبت، تحلیل و بهبود هر تصمیم معاملاتی؛ با حریم خصوصی در قلب محصول.',
      product:'محصول',resources:'منابع',company:'شرکت',dash:'داشبورد',journal:'ژورنال معاملات',ai:'هوش معاملاتی',performance:'عملکرد',markets:'بازارها',support:'مرکز پشتیبانی',blog:'وبلاگ',privacy:'حریم خصوصی',terms:'شرایط استفاده',home:'صفحه اصلی',rights:'© 2026 VELORA. تمامی حقوق محفوظ است.',status:'تجربه‌ای امن و متمرکز بر حریم خصوصی'
    };
    // Replace simple legacy footers; keep the dedicated new Privacy footer intact.
    var existing=document.querySelector('footer');
    if(existing && !document.querySelector('body[data-route="privacy"]') && !/privacy/i.test(document.title||'')) existing.remove();
    var f=document.createElement('footer');f.className='velora-premium-footer';f.innerHTML='<div class="vpf-inner"><div class="vpf-grid"><div class="vpf-brand-wrap"><a class="vpf-brand" href="/"><img src="/public/velora-logo.svg" alt="">VELORA</a><p class="vpf-copy">'+c.copy+'</p></div><div><span class="vpf-title">'+c.product+'</span><nav class="vpf-links"><a href="/dashboard/">'+c.dash+'</a><a href="/trades/">'+c.journal+'</a><a href="/intelligence/">'+c.ai+'</a><a href="/performance/">'+c.performance+'</a><a href="/markets/">'+c.markets+'</a></nav></div><div><span class="vpf-title">'+c.resources+'</span><nav class="vpf-links"><a href="/support/">'+c.support+'</a><a href="/blog/">'+c.blog+'</a><a href="/privacy/">'+c.privacy+'</a></nav></div><div><span class="vpf-title">'+c.company+'</span><nav class="vpf-links"><a href="/">'+c.home+'</a><a href="/terms/">'+c.terms+'</a></nav></div></div><div class="vpf-bottom"><span>'+c.rights+'</span><span class="vpf-status">'+c.status+'</span></div></div>';
    document.body.appendChild(f);
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})(document);
