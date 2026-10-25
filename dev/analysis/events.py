import os
HERE = os.path.dirname(os.path.abspath(__file__))
import os, glob, pandas as pd, numpy as np
B=os.environ.get('FW_HIST', 'var/history/lockyer')  # A history_fetch.php region folder.
SITES={'143203C':'Helidon','143207B':'Clarendon','143209B':'Mulgowie','143210B':'Rifle Range Rd',
       '143212A':'Tenthill','143213C':'Ma Ma Ck','143229A':'Laidley Ck Warrego Hwy','143233A':'Flagstone Ck'}
LEVELS={'143203C':(4.0,6.5,8.0),'143209B':(5.0,6.0,7.0),'143210B':(10.5,12.0,13.5),
        '143212A':(4.5,5.0,6.5),'143229A':(4.5,5.2,5.5)}
def rd(p):
    if not os.path.exists(p): return None
    with open(p) as f:
        if f.readline().startswith('# none'): return None
    d=pd.read_csv(p); d.index=pd.to_datetime(d.pop('time_utc')); return d['value'].sort_index()
# Hourly rain: Helidon + Tenthill (the two long records), zeros filled within their years.
rain={}
for s in ['143203C','143212A']:
    parts=[]
    for f in sorted(glob.glob(f'{B}/rain/{s}_*.csv')):
        y=int(f[-8:-4]); r=rd(f)
        if r is None or len(r)==0: continue
        idx=pd.date_range(f'{y}-01-01', f'{y+1}-01-01', freq='h', inclusive='left')
        parts.append(r.groupby(r.index.floor('h')).sum().reindex(idx, fill_value=0.0))
    rain[s]=pd.concat(parts)
R=pd.concat(rain,axis=1).mean(axis=1,skipna=True)   # mean of available stations each hour
ev=pd.read_csv(f'{B}/events.csv',parse_dates=['start_utc','end_utc','first_trigger_utc','last_trigger_utc'])
daily={s:(rd(f'{B}/daily/{s}_H.csv'),rd(f'{B}/daily/{s}_F.csv')) for s in SITES}
rows=[]
for _,e in ev.iterrows():
    w=R[e.start_utc:e.end_utc]
    if w.empty or w.sum()==0: continue
    r24=w.rolling(24).sum(); r72=w.rolling(72).sum()
    t72=r72.idxmax()                     # end of the wettest 72 hours
    burst_start=t72-pd.Timedelta(hours=72)
    ante=lambda d: R[burst_start-pd.Timedelta(days=d):burst_start].sum()
    # Hour the 72-hour total first reached 50 mm (a candidate "trigger" time).
    over=r72[r72>=50]
    row=dict(event=e.event, rain24=round(r24.max(),1), rain48=round(w.rolling(48).sum().max(),1),
             rain72=round(r72.max(),1), event_rain=round(w.sum(),1), ante7=round(ante(7),1),
             ante14=round(ante(14),1), ante30=round(ante(30),1), wettest72_end=t72,
             t50=over.index[0] if len(over) else pd.NaT)
    worst=0
    for s in SITES:
        h=rd(f'{B}/events/{e.event}_{s}_H.csv')
        if h is None or h.empty: continue
        pk=h.idxmax(); row[f'{s}_peak']=round(h.max(),2); row[f'{s}_peak_t']=pk
        row[f'{s}_start']=round(h.iloc[0],2)
        if s in LEVELS:
            mi,mo,ma=LEVELS[s]; v=h.max()
            c=3 if v>=ma else 2 if v>=mo else 1 if v>=mi else 0
            row[f'{s}_class']=c; worst=max(worst,c)
            hit=h[h>=mi]; row[f'{s}_minor_t']=hit.index[0] if len(hit) else pd.NaT
        f=rd(f'{B}/events/{e.event}_{s}_F.csv')
        if f is not None and not f.empty: row[f'{s}_qpeak']=round(f.max(),1); row[f'{s}_qstart']=round(f.iloc[0],1)
    row['worst']=worst
    rows.append(row)
T=pd.DataFrame(rows)
T.to_pickle(os.path.join(HERE, 'events.pkl'))
pd.set_option('display.width',250); pd.set_option('display.max_rows',200)
cols=['event','rain24','rain72','ante14','ante30','worst']+[f'{s}_class' for s in LEVELS]
print(T[cols].sort_values('worst',ascending=False).head(40).to_string(index=False))
print('\nEvents:',len(T),' with any Lockyer gauge >= minor:',(T.worst>=1).sum(), ' moderate+:',(T.worst>=2).sum(),' major:',(T.worst>=3).sum())
print('Rain record coverage:', R.first_valid_index(), R.last_valid_index())
