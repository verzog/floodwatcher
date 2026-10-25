# Flood history analysis (lower Lockyer, 2026)

The Python scripts behind the lower Lockyer flood potential rule and travel times (Helidon,
Tenthill and Laidley Creek triggers; rain watch with the 30-hour dry end). They read a
`bin/history_fetch.php` region folder (`var/history/<region>/`); copy it off the server and
point `FW_HIST` at it. Needs pandas and numpy.

1. `events.py` — loads the region's hourly rain and river heights, finds each heavy-rain event
   and what each gauge did (peak, time, flood class), saves `events.pkl`.
2. `rules.py` — tests trigger levels and rain totals against the floods at the target gauge
   (hits, misses, false alarms, lead times).
3. `upstream.py` — peak-to-peak travel times between gauges (for data/travel_times.csv).

Written for the Lockyer: the station ids, names and flood levels at the top of `events.py`
need changing for another region.
