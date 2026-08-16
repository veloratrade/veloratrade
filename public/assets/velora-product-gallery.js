/* Turn static product screenshots into a focused product-story gallery. */
(function(document){
 function init(){
  var section=document.getElementById('screenshots'), grid=section&&section.querySelector('.shots'); if(!grid)return;
  var items=[].slice.call(grid.querySelectorAll('.shot')); if(items.length<2)return;
  var fa=(document.documentElement.lang||'fa').toLowerCase().indexOf('fa')===0;
  var data=fa?[
   ['AI Coach','مربی AI','خطای تکراری را پیش از معاملهٔ بعدی ببین.','در سه معاملهٔ اخیر، خروج زودهنگام از سود تشخیص داده شد.'],
   ['Portfolio','پورتفولیو و پراپ','چند حساب را در یک نمای واحد و قابل‌فهم مدیریت کن.','Drawdown تمام حساب‌ها را هم‌زمان زیر نظر داشته باش.'],
   ['Mobile','اپلیکیشن موبایل','ژورنال و Insightها همیشه کنار تو هستند.','ثبت سریع و اعلان‌های ضروری، خارج از میز معامله.'],
   ['Data Engine','موتور داده','همگام‌سازی خودکار؛ بدون ورود دستی و دادهٔ پراکنده.','تاریخچه و معاملات جدید در یک جریان امن وارد ژورنال می‌شوند.']
  ]:[
   ['AI Coach','AI Coach','Spot repeated mistakes before the next trade.','Early profit exits were detected across your last three trades.'],
   ['Portfolio','Portfolio & Prop','Manage several accounts in one clear view.','Monitor drawdown across every account at once.'],
   ['Mobile','Mobile App','Your journal and insights stay close at all times.','Quick logging and essential alerts, away from the desk.'],
   ['Data Engine','Data Engine','Automatic synchronization, without manual entry.','History and new trades enter the journal through one secure flow.']
  ];
  grid.classList.add('story-gallery');
  var tabs=document.createElement('div');tabs.className='product-story-tabs';
  items.forEach(function(item,i){var d=data[i]||data[0],b=document.createElement('button');b.type='button';b.className='product-story-tab';b.innerHTML='<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="M8 12h8"/></svg><span>'+d[0]+'</span>';b.onclick=function(){select(i)};tabs.appendChild(b);var image=item.querySelector('.shot-img');if(image){var overlay=document.createElement('div');overlay.className='story-overlay';overlay.textContent=fa?['تحلیل رفتار زنده','نمای یکپارچهٔ حساب‌ها','Insight در هر لحظه','همگام‌سازی امن'][i]:['LIVE BEHAVIOUR ANALYSIS','UNIFIED ACCOUNT VIEW','INSIGHTS, ANYWHERE','SECURE LIVE SYNC'][i];image.appendChild(overlay)}var cap=item.querySelector('figcaption');if(cap){var insight=document.createElement('div');insight.className='story-insight';insight.innerHTML='<small>'+ (fa?'نتیجهٔ کلیدی':'KEY OUTCOME') +'</small>'+d[3];cap.appendChild(insight)}});
  grid.parentNode.insertBefore(tabs,grid);
  var cta=document.createElement('div');cta.className='story-cta';cta.innerHTML='<a href="/register/"><span>'+(fa?'داشبورد خودت را بساز':'Build your dashboard')+'</span><svg viewBox="0 0 24 24"><path d="M5 12h14m0 0-6-6m6 6-6 6"/></svg></a>';grid.parentNode.insertBefore(cta,grid.nextSibling);
  function select(i){items.forEach(function(item,n){item.classList.remove('story-enter');item.classList.toggle('story-active',n===i)});var active=items[i];void active.offsetWidth;active.classList.add('story-enter');[].slice.call(tabs.children).forEach(function(tab,n){tab.classList.toggle('active',n===i)});}
  select(0);
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})(document);
