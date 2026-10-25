import os
HERE = os.path.dirname(os.path.abspath(__file__))
import pandas as pd, numpy as np, warnings
warnings.filterwarnings('ignore')
T=pd.read_pickle(os.path.join(HERE, 'events.pkl'))
exec(open(os.path.join(HERE, 'events.py')).read().split('ev=pd.read_csv')[0])   # Reload R (hourly mean rain).
r72=R.rolling(72).sum(); r24=R.rolling(24).sum(); r48=R.rolling(48).sum()
a30=R.rolling(24*30).sum().shift(72)  # Rain in the 30 days before the latest 72 hours.
a14=R.rolling(24*14).sum().shift(72)
# Floods outside any event window (missed because no heavy rain was recorded)?
d=rd(f'{B}/daily/143210B_H.csv'); fl=d[d>=10.5]
ev=pd.read_csv(f'{B}/events.csv',parse_dates=['start_utc','end_utc'])
inwin=lambda t: ((ev.start_utc<=t)&(ev.end_utc>=t)).any()
outside=sorted({str(t.date()) for t in fl.index if not inwin(t)})
print('Rifle Range Rd days >= minor outside any heavy-rain event:', outside)
def evaluate(target, X, Y, win=r72, ante=a30):
    hits=miss=fa=0; leads=[]
    for _,e in T.iterrows():
        w0=pd.Timestamp(ev.loc[ev.event==e.event,'start_utc'].iloc[0]); w1=pd.Timestamp(ev.loc[ev.event==e.event,'end_utc'].iloc[0])
        cond=(win[w0:w1]>=X)&(ante[w0:w1]>=Y)
        trig=cond[cond].index[0] if cond.any() else None
        flood=e.get(f'{target}_class',0)>=1 if not pd.isna(e.get(f'{target}_class',np.nan)) else False
        if flood and trig is not None:
            hits+=1; mt=e[f'{target}_minor_t']
            if not pd.isna(mt): leads.append((mt-trig).total_seconds()/3600)
        elif flood: miss+=1
        elif trig is not None: fa+=1
    return hits,miss,fa,leads
for target,name in [('143210B','Rifle Range Rd (lower Lockyer)'),('143203C','Helidon (upper Lockyer)')]:
    n=int((T[f'{target}_class']>=1).sum())
    print(f'\n== {name}: {n} floods (>= minor) in {len(T)} heavy-rain events')
    best=[]
    for X in [50,60,70,80,90,100,120,140]:
        for Y in [0,10,20,30,40,50,60,80,100]:
            h,m,f,l=evaluate(target,X,Y)
            best.append((h,m,f,X,Y,np.median(l) if l else None, min(l) if l else None))
    best.sort(key=lambda b:(-b[0], b[2]))
    print(' hits miss false  72h>=  ante30>=  median lead h  shortest lead h')
    shown=set()
    for b in best:
        if (b[0],b[2]) in shown: continue
        shown.add((b[0],b[2])); print(f' {b[0]:4} {b[1]:4} {b[2]:5}  {b[3]:5}  {b[4]:7}  {b[5] if b[5] is None else round(b[5],1):>12}  {b[6] if b[6] is None else round(b[6],1):>14}')
        if len(shown)>=12: break
