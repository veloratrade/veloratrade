from pathlib import Path
from bs4 import BeautifulSoup, Tag, NavigableString
from difflib import SequenceMatcher
import json,re

PAIRS=[
('index.html','en/index.html'),
('blog/index.html','en/blog/index.html'),
('blog/what-is-trading-journal/index.html','en/blog/what-is-a-trading-journal/index.html'),
('blog/risk-management-trading/index.html','en/blog/risk-management-in-trading/index.html'),
('blog/forex-trading-journal/index.html','en/blog/forex-trading-journal/index.html'),
('blog/mt4-mt5-trading-journal/index.html','en/blog/mt4-mt5-trading-journal/index.html'),
('blog/why-traders-need-journal/index.html','en/blog/why-traders-need-a-journal/index.html'),
]
SKIP={'script','style','noscript','svg','path'}

def norm(x): return ' '.join(str(x).split())
def sig(t):
    if not isinstance(t,Tag): return '#text'
    ident=t.get('id','')
    cl='.'.join(sorted(t.get('class',[])))
    attrs=[]
    for k in ('role','name','type'):
      if t.get(k):attrs.append(k+'='+str(t[k]))
    return t.name+'#'+ident+'.'+cl+'['+','.join(attrs)+']'

def direct_texts(t):
    return [x for x in t.children if isinstance(x,NavigableString) and norm(x)]

out={}

def add(a,b,why):
    a=norm(a);b=norm(b)
    if not a or not b or a==b:return
    if re.search(r'[\u0600-\u06ff]',a) and re.search(r'[A-Za-z]',b):
      if a not in out:out[a]=(b,why)

def walk(a,b,path=''):
    # align direct text where direct text node cardinality agrees
    at=direct_texts(a);bt=direct_texts(b)
    if len(at)==len(bt):
      for x,y in zip(at,bt):add(x,y,path)
    ac=[x for x in a.children if isinstance(x,Tag) and x.name not in SKIP]
    bc=[x for x in b.children if isinstance(x,Tag) and x.name not in SKIP]
    sa=[sig(x) for x in ac];sb=[sig(x) for x in bc]
    sm=SequenceMatcher(None,sa,sb,autojunk=False)
    for block in sm.get_matching_blocks():
      for i in range(block.size):
        walk(ac[block.a+i],bc[block.b+i],path+'/'+sa[block.a+i])

for ap,bp in PAIRS:
    a=BeautifulSoup(Path(ap).read_text('utf8'),'html.parser')
    b=BeautifulSoup(Path(bp).read_text('utf8'),'html.parser')
    # metadata attrs
    for an,bn in zip(a.find_all(['meta','input','textarea']),b.find_all(['meta','input','textarea'])):
      if sig(an)==sig(bn):
       for attr in ('content','placeholder','aria-label','title','alt'):
        if an.get(attr) and bn.get(attr):add(an[attr],bn[attr],ap+':@'+attr)
    walk(a.body or a,b.body or b,ap)

json.dump({k:v[0] for k,v in out.items()},open('/tmp/aligned.json','w'),ensure_ascii=False,indent=2)
print('aligned',len(out))
