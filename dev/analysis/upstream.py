import os
HERE = os.path.dirname(os.path.abspath(__file__))
import pandas as pd, numpy as np, warnings, itertools
warnings.filterwarnings('ignore')
exec(open(os.path.join(HERE, 'events.py')).read().split('# Hourly rain')[0])
T=pd.read_pickle(os.path.join(HERE, 'events.pkl'))
ev=pd.read_csv(f'{B}/events.csv',parse_dates=['start_utc','end_utc'])
H={}
for _,e in ev.iterrows():
    for s in SITES:
        h=rd(f'{B}/events/{e.event}_{s}_H.csv')
        if h is not None and not h.empty: H[(e.event,s)]=h
# Peak travel times in flood events (Rifle Range Rd >= minor).
print('Peak-to-peak travel times to Rifle Range Rd (hours), floods only:')
lags={}
for _,e in T[T['143210B_class']>=1].iterrows():
    rr=e['143210B_peak_t']; out=[]
    for s in ['143203C','143212A','143229A','143213C','143233A','143207B']:
        if f'{s}_peak_t' in e and not pd.isna(e[f'{s}_peak_t']):
            lag=(rr-e[f'{s}_peak_t']).total_seconds()/3600; lags.setdefault(s,[]).append(lag); out.append(f'{SITES[s]} {lag:+.0f}')
    print(' ',e.event, 'RR peak', e['143210B_peak'], '|', ', '.join(out))
for s,v in lags.items(): print(f'  {SITES[s]}: median {np.median(v):.0f} h (range {min(v):.0f} to {max(v):.0f})')

# Rule: any upstream gauge reaches its threshold -> expect Rifle Range Rd minor flooding.
ups=['143203C','143212A','143229A']
def evaluate(th):
    hits=miss=fa=0; leads=[]; missed=[]
    for _,e in T.iterrows():
        trig=None
        for s,x in zip(ups,th):
            h=H.get((e.event,s))
            if h is None: continue
            over=h[h>=x]
            if len(over) and (trig is None or over.index[0]<trig): trig=over.index[0]
        flood=(e.get('143210B_class',0) or 0)>=1
        if flood and trig is not None:
            hits+=1; leads.append((e['143210B_minor_t']-trig).total_seconds()/3600)
        elif flood: miss+=1; missed.append(e.event)
        elif trig is not None: fa+=1
    return hits,miss,fa,leads,missed
res=[]
for th in itertools.product([2.0,2.5,3.0,3.5,4.0,5.0],[2.0,2.5,3.0,3.5,4.0,4.5],[2.5,3.0,3.5,4.0,4.5]):
    h,m,f,l,mi=evaluate(th); res.append((h,f,th,l,mi,m))
res.sort(key=lambda r:(-r[0],r[1],-np.median(r[3]) if r[3] else 0))
print('\nRule: Helidon >= a OR Tenthill >= b OR Laidley Ck (Warrego Hwy) >= c  ->  Rifle Range Rd minor flooding')
print(' hits miss false   Helidon Tenthill Laidley   median lead h  shortest lead h  missed')
seen=set()
for h,f,th,l,mi,m in res:
    if (h,f) in seen: continue
    seen.add((h,f)); print(f' {h:4} {m:4} {f:5}   {th[0]:7} {th[1]:8} {th[2]:7}   {np.median(l):13.1f}  {min(l):15.1f}  {mi}')
    if len(seen)>=10: break

print('\nFalse alarms for the first rule (Rifle Range Rd peak; minor is 10.5 m):')
th=(4.0,3.5,4.5)
for _,e in T.iterrows():
    if (e.get('143210B_class',0) or 0)>=1: continue
    for s,x in zip(ups,th):
        h=H.get((e.event,s))
        if h is not None and (h>=x).any():
            print(f"  {e.event}: {SITES[s]} reached {h.max():.2f}; Rifle Range Rd peaked at {e.get('143210B_peak')}")
            break
# Same rule, how much warning before MODERATE (12.0 m) at Rifle Range Rd?
lead=[]
for _,e in T[T['143210B_class']>=2].iterrows():
    hh=H[(e.event,'143210B')]; mt=hh[hh>=12.0].index[0]; trig=None
    for s,x in zip(ups,th):
        h=H.get((e.event,s))
        if h is not None and (h>=x).any():
            t=h[h>=x].index[0]; trig=t if trig is None or t<trig else trig
    lead.append(round((mt-trig).total_seconds()/3600,1))
print('\nWarning before Rifle Range Rd reached MODERATE (12.0 m), hours:', sorted(lead))
