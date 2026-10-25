# Flood Watcher (SEQ Flood Watch) — version 0.19.6

A community flood dashboard for Queensland catchments, starting with the Brisbane River and the
Mary River. It collects rainfall and river heights from public sources, stores them, and shows
them on a map, one catchment at a time.

**Not an official warning service.** Always follow Bureau of Meteorology warnings.

## Installing on YunoHost (recommended)

Flood Watcher has its own YunoHost app package,
[floodwatcher_ynh](https://github.com/verzog/floodwatcher_ynh). It sets up the database, web
server, PHP, schedule, backups and upgrades, and has a settings page in the YunoHost admin for
the API keys. It needs a domain or subdomain of its own:

```bash
sudo yunohost app install https://github.com/verzog/floodwatcher_ynh
```

Moving an existing My Webapp install across: see
[doc/MOVING_FROM_MY_WEBAPP.md](https://github.com/verzog/floodwatcher_ynh/blob/main/doc/MOVING_FROM_MY_WEBAPP.md)
in the package.

The rest of this file covers the older manual set-up in My Webapp.

## Changes in 0.19.6

- **The side panel slides away.** Search, gauge details, notifications and map options are in a
  panel that's hidden until you tap the "Details" tab on the right edge of the map (and closes
  with the tab or Esc); the map gets the whole width. On phones the panel slides over the map
  too, and the map fills the screen. Choosing a gauge while it's closed puts a dot on the tab.
  The browser remembers whether it was open.
- New gauges (such as a newly added catchment's) load the last few hours of BoM readings straight
  away, instead of starting empty.

## Changes in 0.19.5: the whole Mary River

- **The Mary down to Maryborough**: BoM's Wide Bay and Burnett river and rain pages (IDQ65420,
  IDQ65422, IDQ65423) add Sexton, Miva, Home Park, Tiaro, Owanyilla, The Barrage, Maryborough,
  Munna Creek, Wide Bay Creek at Brooyar and six Tinana Creek gauges, plus the Mary's rain gauges.
- **State stations** for the Mary (Miva, Home Park, Fishermans Pocket, Wide Bay, Munna, Tinana,
  Glastonbury and Amamoor creeks) in `data/qld_sites.csv`.
- **River network** for the whole Mary, ordered by gauge position; "check" lines need confirming.
- **Flood map**: the state floodplain map's "Upper Mary River" and "Lower Mary River" sub-basins.
- `php bin/collect_bom.php refresh` re-reads the gauge list straight away. The discover report
  shows gauge positions and skips state stations that time out.

After upgrading: `php bin/collect_bom.php refresh`, `php bin/fetch_overlays.php floodplain_mary`,
`php bin/collect_qld.php`, then `php bin/build_json.php`.

## Changes in 0.19.4

- `bin/fetch_catchments.php` keeps going when the state's map service fails on some shapes: a
  failed batch is tried again, then one shape at a time (also without simplifying), and a shape
  that still fails is left out and named in the log.

## Changes in 0.19.3

- `bin/fetch_catchments.php` again: the state's drainage basins service refuses any filtered
  query (by basin number too). It now takes every shape's id, then downloads the shapes by id
  with their basin number and name, and fetches the live catchments again in more detail.

## Changes in 0.19.2

- `bin/fetch_catchments.php` still failed on the state's map service: it refuses a request for
  the shape ids alongside other fields, as well as one for every field. It now asks only for
  the basin numbers and names, then finds each basin's shapes by its number (the requests the
  Brisbane outline has always used).

## Changes in 0.19.1

- `bin/fetch_catchments.php` failed on the state's map service ("Error performing query
  operation"): it asked for every basin's details at once. It now reads the layer's field names,
  then the basin ids, then the names 25 at a time, and says which basin failed if an outline
  can't be downloaded.

## Changes in 0.19.0: catchments

- **A catchment chooser** in the header. The page shows one catchment at a time: its gauges, dams,
  road events, cameras, flood maps, predictions and alerts, with its outline. The choice goes in
  the address (`?c=mary`) and is remembered in the browser. A link to a gauge in another
  catchment opens that catchment.
- **The Mary River** is the second live catchment: BoM's gauges inside its outline (Conondale to
  Fishermans Pocket, Obi Obi, Yabba, Kandanga, Amamoor, Six Mile, Deep, Glastonbury, Wide Bay and
  Tinana creeks), Borumba, Baroon Pocket, Lake Macdonald and Cedar Pocket dams, BoM warnings that
  name its rivers, roads in its councils, and the state floodplain map. Its river network
  (`data/network.csv`) is drafted from gauge positions; the "check" lines need confirming.
- **Every other Queensland catchment** is listed as "under construction": choosing one shows its
  outline and a note, with no gauges yet.
- **Alerts card**: "This catchment" (as before, for the map view) or "All catchments" (every
  current alert and warning, each labelled with its catchment).
- **Notifications**: "All BoM flood warnings" is for the catchment on screen when it's ticked
  (or all catchments). Saved areas and watched gauges work anywhere.
- **New scripts**: `bin/fetch_catchments.php` downloads every Queensland drainage basin outline
  (weekly; `if-missing` hourly), and `bin/discover_catchment.php <key>` lists a catchment's BoM
  pages and gauges, state stations, floodplain map sub-basins, Seqwater dams and councils, to help
  set it up. Run it once for the Mary: `php bin/discover_catchment.php mary`.
- **Settings** (lib/defaults.php): new `catchments` section (`live` catchments by state basin
  number); `bom` now takes lists (`river_maps`, `rain_maps`, `rain_9am_maps`) and
  `warning_keywords` by catchment; `traffic` `councils` by catchment; each dam, flood map layer
  and flood potential rule has a `catchment`; `data/qld_sites.csv` has a catchment column. Older
  settings files (plain lists) still work, as the Brisbane River's.
- Database: `sql/upgrade_0.19.sql` adds the warnings catchment to push sign-ups (run
  `bin/migrate.php`).

My Webapp installs: run `bin/migrate.php`, then `bin/fetch_catchments.php`, then
`bin/fetch_overlays.php if-missing`, and add the two fetch_catchments.php lines from
cron/seqflood to /etc/cron.d/seqflood.

## Changes in 0.18.0

- **Predictive flood alerts are an icon on the map**, where the flooding is predicted, instead of a
  panel over the map: a pulsing purple "!" (dark for "flooding likely", pale for "watch"). Tap it
  for the details; the flood-prone land it covers is shaded while that's open. A small purple
  chip in the top corner says one is active and jumps to it. "Predictive flood alerts" in the
  map options switches them on and off.
- **"Water coming here"** for any place you search for or tap, and for each saved area: a level
  like the legend's (Low, Watch, Minor, Moderate, Major) with the reasons. It counts the gauges
  at the place (within 3 km, or inside the area), the nearest gauge on each river or creek
  within 6 km, everything upstream of those (each river or creek once, at its worst: major 3,
  moderate 2, minor 1; half for water held behind a dam), dam flood releases, and predictive
  alerts covering the place. Anything coming upstream makes it "Watch"; flooding upstream adding
  up to 4 or more makes it "Minor"; flooding measured at the place counts at its own level. An
  indicator from the gauges, not a forecast for the spot.
- **Oxley Creek** (Greenbank to Graceville, with Blunder and Stable Swamp creeks) is on the river
  network, so its gauges count for places like Oxley, Rocklea and Corinda.
- **Dams**: Wivenhoe, Somerset, Cressbrook, Atkinson, Moogerah and Lake Manchester on the map,
  coloured like the legend: blue below full, yellow full or spilling, orange holding flood water,
  red flood releases. From Seqwater's dam levels and release update pages (read every 30 minutes
  by the new bin/collect_dams.php; Seqwater has no data feed), BoM's gauge on each lake (level
  against full supply level, and trend), and BoM's gauge below Wivenhoe and Moogerah dams (a quick
  rise shows water being let out). Releases count in "water coming here" downstream. Settings:
  'dams' in lib/defaults.php.

My Webapp installs: add the new collect_dams.php line from cron/seqflood to /etc/cron.d/seqflood.

## Changes in 0.17.3

- **The "heavy rain" predictive watch ends once the danger has passed**, instead of staying on
  until the rain drops out of its 72-hour total (up to three days after it stopped). It now ends
  after 30 dry hours (under 1 mm an hour on average at Helidon and Tenthill) as long as none of
  the trigger gauges is rising. Since 1995 every flood at Rifle Range Rd came during the rain or
  within 21 hours of it stopping, and over 30 years of records the change misses none of the
  floods the watch caught before, while typical watches drop from 58 to 36 hours. While the
  rain has stopped the watch says so, and when it will end. Settings: 'dry_hours' and 'wet_mm'
  in the rule's 'rain' settings.

## Changes in 0.17.2

- A gauge whose reading is doubted (a sudden jump, or a gauge marked as possibly faulty) no longer
  shows "Major flooding" on its dial, "Rising fast" as its trend, or a flood level in the nearest
  gauges list of a map popup; they say the reading may be faulty instead.
- **Phones:** the map takes most of the screen, with a slimmer header and notice. The predictive
  alert starts as a one-line bar and the alerts as a button (which pulses when something new
  arrives) instead of covering the map; tap either to open it. Open, they take at most 40% of
  the screen and scroll inside.
- Buttons on touch screens are finger-sized (44 by 40 px or more).
- A minimised prediction stays minimised after a reload, and alerts already seen don't open the
  card again on a reload. A new or worse one still does (on a computer).
- When a popup opens, the alert panels step aside if they'd cover it, until it's closed.
- Popups are sized to fit the map (phones, and narrow windows beside the side panel), so their
  close button is never off the edge.

## Changes in 0.17.1

- **Sudden jumps in a river level are checked before they raise an alert.** A rise of 3 m or
  more between two readings (up to 90 minutes apart) is believed only if another gauge on the
  same river within 25 km rose too, or, where there's no such gauge, once two more readings stay
  at the new level. Until then the gauge shows "?" with a note, and has no alert or notification.
  On 24/10/2026 Brassall (Hancocks Bridge) jumped from 2 m to 15 m in one reading while Ipswich
  was falling, and raised a false "major flooding" alert. Settings: 'spike_m', 'spike_minutes',
  'spike_km' and 'spike_confirm' under 'alerts' in lib/defaults.php.

YunoHost: `yunohost app upgrade floodwatcher -u https://github.com/verzog/floodwatcher_ynh`.
My Webapp: unzip over the old files as below; no database changes.

## Upgrading a My Webapp install from 0.16.x to 0.17

As root:
```bash
cd /var/www/my_webapp
unzip -o seqflood-v0.17.0.zip && chown -R my_webapp:my_webapp seqflood
cp -r seqflood/www/. www/ && chown -R my_webapp:my_webapp www
sudo -u my_webapp php8.3 seqflood/bin/migrate.php
```
What changed:
- `bin/migrate.php` brings the database up to date by itself (no more `upgrade_0.x.sql` by
  hand). It remembers what it has applied; run it after every upgrade.
- New settings `var_dir` (private data folder, default `var/` as before) and `local_config` (an
  extra settings file), used by the YunoHost package. Nothing to change for My Webapp.
- `bin/fetch_overlays.php if-missing` downloads the flood maps only if they never have been.
- `version.php` holds the version; release tags must match it.

## Data sources

| Source | What | How often | Collector |
|---|---|---|---|
| Brisbane City Council open data | 98 city rain and creek gauges | every 10 min | `bin/collect_bcc.php` |
| Bureau of Meteorology (anonymous FTP) | River gauges with official flood levels, plus rain gauges (hourly totals, plus BoM's current past-hour and since-9am figures), live catchments only | every 15 min | `bin/collect_bom.php` |
| Queensland Government WMIP | Stations listed in `data/qld_sites.csv`: height, flow, rain | every 30 min | `bin/collect_qld.php` |
| State of Queensland drainage basins | Catchment outlines (all of Queensland) | weekly | `bin/fetch_catchments.php` |
| Brisbane City Council / State of Queensland (FloodCheck, QRA) | Flood map shading | weekly | `bin/fetch_overlays.php` |
| QLDTraffic (Transport and Main Roads) | Road closures and flooding | every 10 min | `bin/collect_traffic.php` |

Gauges from different sources at the same spot are shown as one map marker. The main reading
comes from a BoM gauge with official flood levels where there is one; the other readings (and
any state flow) are listed with it.

Which BoM gauges count as the Brisbane River catchment: BoM's Brisbane/Moreton group, minus the
Pine, Caboolture, Burpengary and Deception Bay systems listed in `data/bom_exclude.csv`. Brisbane
city creeks that drain to Moreton Bay are kept. Edit that file to change the boundary. Other live
catchments (the Mary River) take the BoM gauges inside their outline.

### Adding a catchment

1. Add it to `catchments` → `live` in your settings with its state basin number, and run
   `bin/fetch_catchments.php` (the log line says which basin name each number matched).
2. Run `bin/discover_catchment.php <key>` and copy what it finds: BoM pages into `river_maps` /
   `rain_maps`, state stations into `data/qld_sites.csv`, floodplain sub-basins into a flood map
   layer, dams into `dams`, councils into `traffic` → `councils`, and river names into
   `warning_keywords`.
3. Draft its river network in `data/network.csv`, then run `bin/fetch_overlays.php if-missing`
   and `bin/build_json.php`.

## What's in here

| Path | What it is |
|---|---|
| `bin/collect_*.php` | One collector per source (run by cron) |
| `bin/build_json.php` | Writes the dashboard's data files (run after each collector) |
| `bin/fetch_overlays.php` | Downloads the flood map shading (weekly) |
| `bin/collect_traffic.php` | Road closures and flooding from QLDTraffic |
| `bin/collect_warnings.php` | Current BoM flood warnings for the live catchments |
| `bin/fetch_catchments.php`, `lib/catchments.php` | Queensland catchment outlines, and which catchment a place is in |
| `bin/discover_catchment.php` | Lists a catchment's data sources, to help set it up |
| `bin/collect_wu.php` | Weekly check of community rain stations (optional, needs your key) |
| `bin/collect_dams.php`, `lib/dams.php` | Seqwater dam levels and releases, with BoM's lake and tailwater gauges |
| `www/wu.php` | Looks up community rain for the stations in the map view |
| `lib/potential.php` | Flood potential rules (lower Lockyer so far) |
| `bin/push_setup.php` | Creates the push notification keys (once) |
| `www/push.php`, `www/sw.js` | Notification sign-up, and the browser helper that shows them |
| `data/cameras.csv` | Flood cameras shown on the map |
| `data/network.csv`, `data/travel_times.csv` | Which gauge flows into which, and flood peak travel times |
| `bin/migrate.php` | Creates the database tables, then applies any `sql/upgrade_*.sql` not yet applied |
| `bin/build_floodmask.php`, `lib/floodmask.php` | Flood-prone land grid that keeps warnings to land that can flood |
| `bin/import_sensors.php` | Loads the council sensor list (first install only) |
| `data/` | Council sensor list, state station list, BoM exclusions and tidal gauges |
| `sql/schema.sql`, `sql/upgrade_0.3.sql` | Database tables; upgrade for 0.1/0.2 databases |
| `lib/` | Shared code; `lib/defaults.php` holds every setting's default |
| `www/` | The dashboard page — copied into My Webapp's public `www` folder |
| `cron/seqflood` | Ready-made schedule — copied to `/etc/cron.d/` |
| `config.example.php` | Settings template — copy to `config.php` (database password only) |
| `CLAUDE.md` | Briefing for developers (and AI coding assistants): how it fits together, releasing, adding a catchment, lessons learned |
| `dev/` | Mock services, saved real pages and scripts for testing without the real services; history analysis scripts |

## Upgrading from 0.16 to 0.16.1: fixes to the flood-prone shading, and odd road names

As root:
```bash
cd /var/www/my_webapp
unzip -o seqflood-v0.16.1.zip && chown -R my_webapp:my_webapp seqflood
cp -r seqflood/www/. www/ && chown -R my_webapp:my_webapp www
sudo -u my_webapp php8.3 seqflood/bin/build_floodmask.php
sudo -u my_webapp php8.3 seqflood/bin/build_json.php
```
What changed:
- **Fixed long purple stripes** across land that can't flood. Where a corner of a flood map
  shape sat exactly on the centre line of a row of squares, a rounding error made the mask fill
  the wrong gaps along that row. It now works in whole numbers, so this can't happen.
- **A prediction's shaded area is only the flood-prone land joined to the river it's about.**
  It starts from the gauges named in the prediction and spreads through joined flood-prone land
  inside the prediction's box, so other creeks and rivers in the box (for example the Bremer at
  Rosewood) are left out. BoM warnings are trimmed the same way, starting from the gauges on the
  rivers the warning names. Notifications for saved areas use the same areas.
- The lower Lockyer prediction's box now covers just above Glenore Grove down to where Lockyer
  Creek meets the Brisbane River (it used to reach Helidon and Rosewood). Change 'bbox' and
  'seeds' for it in `lib/defaults.php` (copy them into config.php) if you'd like it different.
- **Road names that don't look like Queensland ones** (QLDTraffic sent an Ipswich event named
  "Đường Vân Nội") are no longer used as the heading; the popup shows "Road event" and a note
  with the name QLDTraffic gave.

## Upgrading from 0.15 to 0.16: "Flood event upstream", and warnings kept to flood-prone land

As root:
```bash
cd /var/www/my_webapp
unzip -o seqflood-v0.16.zip && chown -R my_webapp:my_webapp seqflood
cp -r seqflood/www/. www/ && chown -R my_webapp:my_webapp www
sudo -u my_webapp php8.3 seqflood/bin/build_floodmask.php
sudo -u my_webapp php8.3 seqflood/bin/build_json.php
```
No database changes. `build_floodmask.php` should print a line like "Flood-prone mask: … squares
can flood". After this it runs by itself after each weekly flood map download.

What changed:
- **"⬆ Flood event upstream"** in a river gauge's details: a small bar that opens to list the
  gauges upstream that are flooding, rising fast, or rising within 1 m of their minor flood level,
  plus any predictive alert for that stretch of river. Each one says how long its peak usually
  takes to reach the gauge you're looking at, where that has been worked out from past floods
  (so far the Lockyer and Laidley creeks); otherwise "travel time not yet worked out". Gauges above
  Wivenhoe or Somerset dams say that what arrives depends on the dam's releases. When nothing
  upstream is happening it shows a quiet one-line "nothing flooding or rising".
- The river network is in `data/network.csv` (which gauge flows into which) and the travel times
  in `data/travel_times.csv`. Both are plain text you can edit. Lines with "check" are creek
  junctions I wasn't certain of; please correct any you know better, then run `build_json.php`.
- **Warnings and predictions are kept to land that can flood.** All the flood maps are merged into
  a grid of 200 m squares (`data/floodmask.json`). A prediction's or BoM warning's area is
  trimmed to the flood-prone land inside it; "Show area" now shades just that land instead of a
  big rectangle; and a saved notification area is only told about a warning or prediction if
  some of its own flood-prone land is inside the warning's area. People watching a particular
  gauge, or who chose "All BoM flood warnings", still get those as before.
- Predictions now only show at gauges downstream of the gauges that set them off.
- History downloads for four more areas (see "Flood potential rules" below).

## Upgrading from 0.14.x to 0.15: predictive flood alerts get their own panel

As root:
```bash
cd /var/www/my_webapp
unzip -o seqflood-v0.15.zip && chown -R my_webapp:my_webapp seqflood
mysql my_webapp < seqflood/sql/upgrade_0.15.sql
cp -r seqflood/www/. www/ && chown -R my_webapp:my_webapp www
sudo -u my_webapp php8.3 seqflood/bin/build_json.php
```
What changed:
- **Predictive flood alerts** (the flood potential rules) have their own purple panel at the top
  right of the map, above the other alerts, headed "Predictive flood alert". It shows whatever
  part of the map you're looking at, has a "Show area" button and outlines the area in purple,
  and can be minimised to a one-line bar (a new or worse prediction opens it again).
- An **On/Off switch** on the panel (remembered in this browser), and a separate
  **"Predictive flood alerts for your areas"** choice in Notifications.
- Predictions are no longer mixed into the ordinary alerts list.
- The two notification tick boxes are now remembered in the browser (before, a reload ticked
  "All BoM flood warnings" again and sent that to the server).
- Notifications for predictions are titled "Predictive flood alert (flooding likely)" or
  "(watch)" with the area's name.

## Upgrading from 0.14 to 0.14.1

The community station check now publishes working stations as it goes (every 20), instead of
only at the end; and the lower Lockyer flood potential alert covers an area (Helidon and Laidley
down to Lyons Bridge), so it shows whenever any of that is on screen, and reaches saved areas
that overlap it. As root:
```bash
cd /var/www/my_webapp
unzip -o seqflood-v0.14.1.zip && chown -R my_webapp:my_webapp seqflood
cp -r seqflood/www/. www/ && chown -R my_webapp:my_webapp www
sudo -u my_webapp php8.3 seqflood/bin/build_json.php
```

## Upgrading from 0.13.x to 0.14

As root:
```bash
cd /var/www/my_webapp
unzip -o seqflood-v0.14.zip && chown -R my_webapp:my_webapp seqflood
mysql my_webapp < seqflood/sql/upgrade_0.14.sql
cp -r seqflood/www/. www/ && chown -R my_webapp:my_webapp www
sudo -u my_webapp php8.3 seqflood/bin/build_json.php
nohup sudo -u my_webapp php8.3 seqflood/bin/collect_wu.php rediscover > /tmp/wu.out 2>&1 &
```
The last line checks every community station in the catchment (one request each, about 2.5
seconds apart, so 10–15 minutes for 300 stations); `cat /tmp/wu.out` shows the result.

What changed:
- **Flood potential: lower Lockyer.** From 30 years of state gauge history: when Helidon
  reaches 4.0 m, Tenthill Creek 3.5 m or Laidley Creek at the Warrego Highway 6.0 m, minor
  flooding at Rifle Range Rd has followed in all 14 cases since 1995 (2 false alarms), about 6 to
  28 hours later. Shown as "Likely" in the alerts card and sent as a notification to areas
  covering Rifle Range Rd. An earlier "Watch" comes from 70 mm in 72 hours at Helidon and Tenthill
  (a weaker sign: 12 of 14 floods, but also 24 times without one). Rules are under `'potential'`
  in `lib/defaults.php`. These are this site's estimates, not BoM warnings.
- **Community rain, rebuilt.** Every working station in the catchment is on the community layer;
  rain is looked up for the stations in view (up to 25, spread across it), shared between
  viewers for 15 minutes, within the free key's daily allowance. Each shows the station's own
  rain since midnight, rate and time. A weekly check keeps the station list current.

## Upgrading from 0.13.1 to 0.13.2

Community rain fixes: only stations reporting rainfall right now are chosen (a station quiet for
two hours is replaced, at most once a day); the "suspect" check now compares only the hours
collected so far (new gauges were wrongly flagged); station names are no longer stored or shown
("Community gauge" plus the public station ID), and positions are rounded to about 1 km. As root:
```bash
cd /var/www/my_webapp
unzip -o seqflood-v0.13.2.zip && chown -R my_webapp:my_webapp seqflood
cp -r seqflood/www/. www/ && chown -R my_webapp:my_webapp www
sudo -u my_webapp php8.3 seqflood/bin/collect_wu.php rediscover
sudo -u my_webapp php8.3 seqflood/bin/build_json.php
```

## Upgrading from 0.13 to 0.13.1

Fixes: community stations were chosen from a rough box (Sunshine Coast, Gold Coast hinterland)
when the catchment outline hadn't been downloaded yet; the station search now requires the outline.
Brisbane City Council's map server refused long requests (404), so its two flood maps now
download in smaller pieces. As root:
```bash
cd /var/www/my_webapp
unzip -o seqflood-v0.14.zip && chown -R my_webapp:my_webapp seqflood
sudo -u my_webapp php8.3 seqflood/bin/fetch_overlays.php
sudo -u my_webapp php8.3 seqflood/bin/collect_wu.php rediscover
sudo -u my_webapp php8.3 seqflood/bin/build_json.php
```

## Upgrading from 0.12 to 0.13: community rain (Weather Underground)

No database changes. As root:
```bash
cd /var/www/my_webapp
unzip -o seqflood-v0.13.zip
chown -R my_webapp:my_webapp seqflood
cp -r seqflood/www/. www/ && chown -R my_webapp:my_webapp www
cp seqflood/cron/seqflood /etc/cron.d/seqflood && chmod 644 /etc/cron.d/seqflood
```
Then add your Weather Underground API key to `seqflood/config.php` (inside `return [ ... ];`):
```php
    'wu' => ['enabled' => true, 'api_key' => 'YOUR-KEY'],
```
and run it once by hand (the first run searches the catchment, about a minute):
```bash
sudo -u my_webapp php8.3 /var/www/my_webapp/seqflood/bin/collect_wu.php
sudo -u my_webapp php8.3 /var/www/my_webapp/seqflood/bin/build_json.php
```
How it works: once a week it searches the catchment and picks up to 12 community stations that
best fill the gaps between official rain gauges, then reads them every 15 minutes (about 1,150
requests a day, within the free key's limits). The first reading of each station is a starting
point; rain is counted from the second. A station is flagged as suspect ("?") when its 24-hour
total is far below or above the official gauges within 10 km. They appear only on the
"Community rain (unofficial)" layer, and are never used for alerts or flood rules.
The free key is for personal, non-commercial use with credit to Weather Underground.

## Upgrading from 0.10 or 0.11 to 0.12

As root:
```bash
cd /var/www/my_webapp
unzip -o seqflood-v0.12.zip
chown -R my_webapp:my_webapp seqflood
mysql my_webapp < seqflood/sql/upgrade_0.12.sql
cp -r seqflood/www/. www/ && chown -R my_webapp:my_webapp www
```
What changed:
- **Your areas:** zoom the map to an area (one gauge, a town, a stretch of river), name it
  and add it (up to five). Notifications then cover gauge alerts inside each area and BoM
  warnings whose rivers run through it, with the area's name at the start of the title.
  Areas show as dashed outlines on the map. "All BoM flood warnings for the catchment" can
  be unticked to only hear about your areas and watched gauges.
- **Panel order:** search, then the selected gauge, then notifications, map options, flood
  maps and the legend.

## Flood potential rules: downloading the history (0.11)

`bin/history_fetch.php` downloads the history needed to work out flood potential rules for a
region. Regions: `lockyer` (eight state stations from Helidon to Rifle Range Road), `bremer`
(Bremer River and Warrill, Purga and Western creeks), `brisbane_mid` (Savages Crossing with the
lower Lockyer; Wivenhoe release history isn't available from this service), `stanley` (Stanley
River to Lake Somerset) and `upper_brisbane` (upper Brisbane River to Lake Wivenhoe). It saves as it
goes, so it can be stopped and re-run to carry on; it takes a while the first time (rainfall since
1990, then river data around each heavy-rain event). Run it in the background as root:
```bash
cd /var/www/my_webapp/seqflood
nohup sudo -u my_webapp php8.3 bin/history_fetch.php lockyer > /tmp/history.out 2>&1 &
tail -f var/history/lockyer/log.txt        # Ctrl+C stops watching (not the download).
```
To download the four newer regions one after another in one go (each takes a few hours):
```bash
cd /var/www/my_webapp/seqflood
nohup sh -c 'for r in bremer brisbane_mid stanley upper_brisbane; do sudo -u my_webapp php8.3 bin/history_fetch.php $r; done' \
  > /tmp/history.out 2>&1 &
tail -f /tmp/history.out                   # Ctrl+C stops watching (not the download).
```
When the log says "Finished", zip it up:
```bash
cd /var/www/my_webapp/seqflood/var/history && zip -r lockyer.zip lockyer && ls -lh lockyer.zip
```
For the four newer regions together:
```bash
cd /var/www/my_webapp/seqflood/var/history && zip -r more.zip bremer brisbane_mid stanley upper_brisbane && ls -lh more.zip
```

## Upgrading from 0.9 to 0.10

No database changes (but if you haven't yet, run the 0.9 steps below first). As root:
```bash
cd /var/www/my_webapp
unzip -o seqflood-v0.10.zip
chown -R my_webapp:my_webapp seqflood
cp -r seqflood/www/. www/ && chown -R my_webapp:my_webapp www
sudo -u my_webapp php8.3 seqflood/bin/build_json.php
```
What changed: alerts moved from the top of the page into a card at the top right of the map.
It opens on its own when the page loads or something new arrives, shrinks to a "⚠ Alerts"
button after 10 seconds (not while you're reading it), and has a – button to minimise it.
It only lists alerts for the part of the map on screen: gauge alerts by the gauge's position,
and BoM warnings by the gauges on the rivers and creeks each warning names (a warning that
names none of them is always shown). A line says how many more are elsewhere, with a link
to show the whole catchment.

## Upgrading from 0.8 to 0.9

As root:
```bash
cd /var/www/my_webapp
unzip -o seqflood-v0.10.zip
chown -R my_webapp:my_webapp seqflood
mysql my_webapp < seqflood/sql/upgrade_0.9.sql
cp -r seqflood/www/. www/ && chown -R my_webapp:my_webapp www
sudo -u my_webapp php8.3 seqflood/bin/push_setup.php
curl -s https://floodwatcher.thefeaturecreep.noho.st/push.php
```
The last line should print `{"publicKey":"..."}`. If it prints PHP code or an error page, the
web server isn't running PHP for this app (check My Webapp's PHP setting in YunoHost).

What changed (alerts, stage 2): **push notifications.** Under "Notifications" on the page, turn
them on for this device, choose whether to include BoM flood warnings, and use "Send a test".
You'll then be notified of new or worse alerts and the all clear for gauges on your watch list,
and of each new BoM flood warning issue. Nothing is sent for alerts already current at the
first run, and an alert that ends and comes back within an hour isn't sent twice.

- Built with PHP's own OpenSSL (no extra libraries; gmp, bcmath and Composer aren't needed).
- The server's push key pair is in `var/vapid.json`. Back it up: if it's lost, every device
  has to turn notifications on again.
- Stored per device: the push address and keys the browser issued, the watched gauge ids and the
  warnings choice. No names, emails, cookies or IP addresses.
- iPhone and iPad: add the site to the Home Screen first (Share, Add to Home Screen) and turn
  notifications on from there (iOS 16.4 or later).
- Settings are under `'push'` in `lib/defaults.php`.

## Upgrading from 0.7 to 0.8

No database changes. As root:
```bash
cd /var/www/my_webapp
unzip -o seqflood-v0.9.zip
chown -R my_webapp:my_webapp seqflood
cp -r seqflood/www/. www/ && chown -R my_webapp:my_webapp www
sudo -u my_webapp php8.3 seqflood/bin/collect_warnings.php
sudo -u my_webapp php8.3 seqflood/bin/build_json.php
cp seqflood/cron/seqflood /etc/cron.d/seqflood && chmod 644 /etc/cron.d/seqflood
```
What changed (alerts, stage 1):
- **BoM flood warnings** for the catchment, checked every 15 minutes (`bin/collect_warnings.php`),
  shown in a banner at the top with a link to each warning. Which warnings count as ours is set
  by `'bom' => ['warning_keywords' => [...]]`.
- **Gauge alerts** worked out on each data update: at or above a BoM flood level, rising and
  within 0.5 m of minor flood level, or rising fast (not for tidal gauges). Each alert says
  when it started; a gauge that stops reporting keeps its alert instead of being called all
  clear. Settings are under `'alerts'` in `lib/defaults.php`.
- **Watch list:** a ☆ Watch button on each gauge, saved in your browser only. Watched gauges
  get a gold ring on the map, come first in the banner, and get an "all clear" note.

## Upgrading from 0.6 or 0.6.1 to 0.7

No database changes. As root:
```bash
cd /var/www/my_webapp
unzip -o seqflood-v0.9.zip
chown -R my_webapp:my_webapp seqflood
cp -r seqflood/www/. www/ && chown -R my_webapp:my_webapp www
sudo -u my_webapp php8.3 seqflood/bin/fetch_overlays.php catchment
```
What changed: a **Reset** button on the map (whole catchment, clears the selected gauge,
search pin and popups), and a dashed **Brisbane River catchment boundary** (state drainage
basin 143) with its own checkbox. The hover box only appears with a mouse, not on phones.

## Upgrading from 0.5 or 0.5.1 to 0.6.1 (or 0.6 to 0.6.1)

No database changes. As root:
```bash
cd /var/www/my_webapp
unzip -o seqflood-v0.9.zip
chown -R my_webapp:my_webapp seqflood
cp -r seqflood/www/. www/ && chown -R my_webapp:my_webapp www
sudo -u my_webapp php8.3 seqflood/bin/collect_traffic.php
sudo -u my_webapp php8.3 seqflood/bin/fetch_overlays.php    # Only if you skipped it for 0.5.1.
sudo -u my_webapp php8.3 seqflood/bin/build_json.php
cp seqflood/cron/seqflood /etc/cron.d/seqflood && chmod 644 /etc/cron.d/seqflood
```
What changed:
- **Road closures and flooding** from QLDTraffic, every 10 minutes: flooding reports and roads
  closed by hazards or crashes in the catchment's councils (Brisbane, Ipswich, Lockyer Valley,
  Somerset, Toowoomba, Scenic Rim). Closed roads are red with a no-entry sign; flooding on an
  open road is blue. Mostly state-controlled roads, plus whatever councils send to QLDTraffic.
- Uses QLDTraffic's shared public key for now. When TMR sends your own key, add it to
  `config.php`: `'traffic' => ['api_key' => 'YOUR KEY'],` (keep config.php private).
- If QLDTraffic says the key is over its limit, collection pauses for an hour and the page says
  road information is unavailable (no red alert at the top). The shared public key is often over
  its limit, so ask TMR for your own key.
- Licensed GPLv3 or later (`LICENSE`).

## Upgrading from 0.4 to 0.5.1

No database changes. As root:
```bash
cd /var/www/my_webapp
unzip -o seqflood-v0.9.zip
chown -R my_webapp:my_webapp seqflood
cp -r seqflood/www/. www/ && chown -R my_webapp:my_webapp www
sudo -u my_webapp php8.3 seqflood/bin/fetch_overlays.php
sudo -u my_webapp php8.3 seqflood/bin/build_json.php
cp seqflood/cron/seqflood /etc/cron.d/seqflood && chmod 644 /etc/cron.d/seqflood
```
The flood map download takes a minute or two and prints each layer's size. What changed:
- **Whole-catchment floodplain (0.5.1):** the state's broad-scale "Rapid Hazard Assessment"
  floodplain map (FloodCheck) for the Brisbane, Bremer, Lockyer and Stanley sub-basins, so
  Lockyer Valley, Fernvale and the upper catchment get shading too. It's drawn from land shape
  (drainage and 10 m contours), not a flood model, so treat it as a rough guide. If its
  download times out (the shapes are large), add `'http' => ['timeout_seconds' => 120]` to
  `config.php` and run `fetch_overlays.php` again.
- **Flood maps:** faint shading for Brisbane City Council's flood awareness areas and past flood
  extents (January 2011 for Brisbane and Ipswich, February 2022 and January 1974 for Brisbane),
  each with its own checkbox. Hover (or tap on a phone) to see which apply at a spot; inside
  Brisbane, a tap also asks the council's map service for the flood likelihood there.
  The shapes are downloaded weekly and served with the site, so the page doesn't depend on the
  council or state servers during a flood (only the tap-for-likelihood check does).
- **Flood cameras:** camera markers that open the council's camera page. The list is
  `data/cameras.csv`; add a row for each camera you can place (position `exact`, `gauge` or
  `approx`). Rows without coordinates are skipped.
- **Place search:** find an address or suburb (OpenStreetMap Nominatim, one search per press
  of Find), see the flood maps at that spot and the three nearest river and creek gauges.
- **Legend:** steady gauges are green; gauges that may be faulty or aren't reporting show a "?".

To make a flood map file smaller, raise its `offset` (simplification, in degrees) in
`config.php`, e.g. `'overlays' => ['layers' => [0 => ['offset' => 0.00015]]]`, then re-run
`fetch_overlays.php`.

## Upgrading from 0.3.x to 0.4

No database changes. As root:
```bash
cd /var/www/my_webapp
unzip -o seqflood-v0.4.zip
chown -R my_webapp:my_webapp seqflood
cp -r seqflood/www/. www/ && chown -R my_webapp:my_webapp www
sudo -u my_webapp php8.3 seqflood/bin/build_json.php
```
What changed: a new gauge panel (dial, quick facts, rain chart with running total, river chart
with flood levels), light and dark colours following the device, the Atkinson Hyperlegible
typeface hosted with the site, and a link between rain and river gauges at the same spot.

## Upgrading from 0.3.2 to 0.3.3

```bash
cd /var/www/my_webapp
unzip -o seqflood-v0.3.3.zip
chown -R my_webapp:my_webapp seqflood
sudo -u my_webapp php8.3 seqflood/bin/build_json.php
```
What changed: a state gauge now joins the BoM gauge for the same station even when the two
sources' map positions differ by up to 1 km (same waterway name required), so its readings
and flow appear in that gauge's popup.

## Upgrading from version 0.3 or 0.3.1 to 0.3.2

No database changes. As root:
```bash
cd /var/www/my_webapp
unzip -o seqflood-v0.3.2.zip
chown -R my_webapp:my_webapp seqflood
cp -r seqflood/www/. www/ && chown -R my_webapp:my_webapp www
sudo -u my_webapp php8.3 seqflood/bin/collect_qld.php
sudo -u my_webapp php8.3 seqflood/bin/collect_bom.php
sudo -u my_webapp php8.3 seqflood/bin/build_json.php
```
What changed: BoM rain gauges show BoM's current "past hour" and "since 9am" figures (the
hourly files trail by up to an hour); every rain total says what time it runs to; a "since
9am" rain view; and the state collector finds the flow request settings the service accepts.

## Upgrading from version 0.3 to 0.3.1

No database changes. As root:
```bash
cd /var/www/my_webapp
unzip -o seqflood-v0.3.1.zip
chown -R my_webapp:my_webapp seqflood
cp -r seqflood/www/. www/ && chown -R my_webapp:my_webapp www
sudo -u my_webapp php8.3 seqflood/bin/collect_qld.php
```
The first state run checks each station once (a minute or so); later runs are quick.

## Upgrading from version 0.1 or 0.2

Your database, `config.php` and collected history are kept. Run as root:

1. **Upload `seqflood-v0.9.zip` to `/var/www/my_webapp/`** (upload only; don't extract it in
   Webmin — Webmin unpacks into a separate folder). Then unpack it over the old folder:
   ```bash
   cd /var/www/my_webapp
   unzip -o seqflood-v0.9.zip
   chown -R my_webapp:my_webapp seqflood
   ```
2. **Upgrade the database:**
   ```bash
   mysql my_webapp < seqflood/sql/upgrade_0.3.sql
   ```
3. **Install the new web page:**
   ```bash
   cp -r seqflood/www/. www/
   chown -R my_webapp:my_webapp www
   ```
4. **First run of the new collectors** (BoM takes a little longer the first time, as it loads
   the last few hours):
   ```bash
   cd /var/www/my_webapp/seqflood
   sudo -u my_webapp php8.3 bin/collect_bom.php
   sudo -u my_webapp php8.3 bin/collect_qld.php
   sudo -u my_webapp php8.3 bin/fetch_overlays.php
   sudo -u my_webapp php8.3 bin/build_json.php
   ```
5. **Install the new schedule** (replaces the old one-line cron file):
   ```bash
   cp /var/www/my_webapp/seqflood/cron/seqflood /etc/cron.d/seqflood
   chmod 644 /etc/cron.d/seqflood
   ```

## Fresh install on YunoHost (My Webapp)

My Webapp with **PHP 8.3** and **MySQL**, app id `my_webapp`. As root:

```bash
cd /var/www/my_webapp
unzip -o seqflood-v0.13.zip && chown -R my_webapp:my_webapp seqflood
php8.3 -m | grep -i pdo_mysql            # If php8.3 is missing: apt install php8.3-cli
cd seqflood
cp config.example.php config.php
yunohost app setting my_webapp db_pwd    # Put this password into config.php
nano config.php
chown my_webapp:my_webapp config.php && chmod 600 config.php
mysql my_webapp < sql/schema.sql
sudo -u my_webapp php8.3 bin/push_setup.php
sudo -u my_webapp php8.3 bin/import_sensors.php
sudo -u my_webapp php8.3 bin/collect_bcc.php
sudo -u my_webapp php8.3 bin/collect_bom.php
sudo -u my_webapp php8.3 bin/collect_qld.php
rm -f ../www/index.html && cp -r www/. ../www/ && chown -R my_webapp:my_webapp ../www
sudo -u my_webapp php8.3 bin/fetch_overlays.php
sudo -u my_webapp php8.3 bin/collect_traffic.php
sudo -u my_webapp php8.3 bin/collect_warnings.php
sudo -u my_webapp php8.3 bin/build_json.php
cp cron/seqflood /etc/cron.d/seqflood && chmod 644 /etc/cron.d/seqflood
```

## Checking it's working

```bash
tail -20 /var/www/my_webapp/seqflood/var/collector.log
mysql my_webapp -e "SELECT id, source, started_utc, status, records_fetched, readings_inserted, message
                    FROM collector_runs ORDER BY id DESC LIMIT 8;"
```
Log times are UTC (Brisbane is UTC+10). The page warns at the top if the data is more than
30 minutes old or a source's last collection failed.

## Notes

- Council and BoM gauges report when the level changes, so gaps are normal; the current level is
  the most recent reading. State gauges report on a schedule (about every 15–30 minutes).
- Heights are measured from either AHD (about mean sea level) or the gauge's own zero, shown per
  gauge. Readings from different sources at one spot can differ for this reason.
- Flood classes (minor, moderate, major) use BoM's official levels for that gauge.
- Fully tidal gauges are never shown red, because they rise with every incoming tide. Separating
  tide from floodwater needs tide predictions (planned).
- BoM state rainfall is hourly; council rainfall is every 5 minutes.
- Data licences: Brisbane City Council open data; Bureau of Meteorology (free, non-commercial
  use; river height and rainfall observations under the Water Regulations 2008, many CC BY);
  © State of Queensland (CC BY 4.0). Check terms before any commercial use.
- Flood maps: whole-catchment floodplain © State of Queensland (Department of Resources),
  FloodCheck, which says it is not for use in a disaster or emergency; © Brisbane City Council (Flood Awareness Map; awareness only, not depth or a
  prediction); 2011 extent © State of Queensland (Queensland Reconstruction Authority). Camera
  images stay on the councils' own sites; this page only links to them.
- Road closures and flooding: QLDTraffic, © State of Queensland (Department of Transport and
  Main Roads), CC BY 4.0. Display on third-party sites is approved by TMR case by case.
- Community rain: Weather Underground (wunderground.com), stations run by members of the public;
  unofficial. The free API key is for personal, non-commercial use with attribution. Station
  names are not stored or shown, and positions are rounded to about 1 km.
- Place search: OpenStreetMap Nominatim (free for light use: one search per click, no
  search-as-you-type). For heavy use, switch to a paid or self-hosted geocoder.
- Map: OpenStreetMap tiles (free for light use). Leaflet 1.9.4 is bundled in `www/vendor/leaflet`
  (BSD-2-Clause, unmodified).
- Typeface: Atkinson Hyperlegible by the Braille Institute, bundled in
  `www/vendor/atkinson-hyperlegible` (SIL Open Font License 1.1, unmodified, via Fontsource).

## Licence

Flood Watcher is free software: you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation, either version 3 of the
License, or (at your option) any later version. See `LICENSE`. Copyright (C) 2026 Vernon Spain.

Bundled third-party code keeps its own licence: Leaflet (BSD-2-Clause) and Atkinson Hyperlegible
(SIL Open Font License 1.1), both GPL-compatible. The data shown belongs to its providers and is
used under their terms (see Notes).
