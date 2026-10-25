# Flood Watcher — briefing for Claude Code

Read this first. It covers what the project is, how it fits together, how to release and deploy,
how to add a catchment, and the mistakes already made so they aren't made again.
Last updated 25/10/2026, at version 0.19.7.

## 1. The project and the person

- **Flood Watcher** is a community flood dashboard for Queensland catchments: river levels, rain,
  BoM flood warnings, gauge alerts, predictive flood alerts, dams, flood maps, road closures,
  flood cameras and push notifications. Live at https://floodwatcher.thefeaturecreep.noho.st.
- Owner: Vernon Spain (GitHub `verzog`), Brisbane. His own house flooded in 2011 and 2022; this
  is personal. **GPLv3, non-commercial, no user accounts.** Not an official warning service:
  the page must always say so and point to BoM.
- Catchments: **Brisbane River** and **Mary River** are live; every other Queensland catchment
  is listed as "under construction" (outline only).

How Vernon wants to work (follow these):

- Australian English (colour, organise, kerb), dates DD/MM/YYYY, times Brisbane (UTC+10, no
  daylight saving).
- **Ask clarifying questions before non-trivial work.** He answers quickly; offer clear options.
- Plain-language explanations. He is not an experienced coder but runs his own server well.
- **Correct your own mistakes openly**; don't defend a wrong analysis.
- Diagnose before fixing (see lesson 1 below).
- Show screenshots of visual changes (desktop, phone, dark) before asking him to release.
- When giving him a fixed file or query, give the whole thing, not just the changed lines.
- Never ask him to paste secrets (keys, tokens, passwords) into chat. API keys go in the
  YunoHost settings page or `config.local.php`.

## 2. Repositories and where things live

| What | Where |
|---|---|
| Code | github.com/verzog/floodwatcher (this repo), branch `main` |
| YunoHost package | github.com/verzog/floodwatcher_ynh, branch `main` |
| Server | YunoHost VPS (`vmi3313830`), app id `floodwatcher`, PHP 8.3, MariaDB |
| Code on the server | `/var/www/floodwatcher` (replaced on every upgrade; `www/` is the web root) |
| Data on the server | `/home/yunohost.app/floodwatcher/var` (private: state, push keys `vapid.json`, history downloads, `catchments.json`) and `.../public` (served as `/data/`) |
| His own settings | `/home/yunohost.app/floodwatcher/config.local.php` (kept on upgrade; `config.php` is rewritten by the package) |
| Log | `/var/log/floodwatcher/collector.log` |
| Schedule | `/etc/cron.d/floodwatcher` (from the package's `conf/cron`) |
| Run a script | `cd /var/www/floodwatcher && sudo -u floodwatcher php8.3 bin/<script>.php` |
| Database | `sudo mysql floodwatcher` |

The package repo: `manifest.toml` (version, source URL and sha256), `scripts/` (install,
upgrade, remove, backup, restore, change_url, config), `conf/` (config.php template, nginx,
cron), `config_panel.toml` (settings page: push on/off, community rain, WU key, QLDTraffic key),
`tools/bump.sh`, and `.github/workflows/update.yml` (follows new code releases, see section 5).
YunoHost helpers version 2.1; check helper names against the YunoHost source before using one.

## 3. How it fits together

```
collectors (cron)  ->  MariaDB (sensors, readings, runs, push sign-ups)  ->  bin/build_json.php
   -> static JSON in public/data/  ->  www/app.js (Leaflet map; reads only the JSON files)
```

Visitors never touch the database; only `www/push.php` (sign-ups) and `www/wu.php` (community
rain lookups) run PHP per request.

Collectors (`bin/`), all logging to the run log and `collector.log`:

| Script | Source | How often |
|---|---|---|
| `collect_bcc.php` | Brisbane City Council open data (98 city gauges) | 10 min |
| `collect_bom.php` | BoM anonymous FTP: map pages (gauge lists, flood levels) + IDZ reading files | 15 min |
| `collect_warnings.php` | BoM flood warnings (IDQ2xxxx .amoc.xml + .txt) | 15 min |
| `collect_qld.php` | State water monitoring (WMIP) web service, stations in `data/qld_sites.csv` | 30 min |
| `collect_traffic.php` | QLDTraffic events (flooding, closures) | 10 min |
| `collect_dams.php` | Seqwater dam levels + release update pages (no feed; HTML parsed) | 30 min |
| `collect_wu.php` | Weather Underground community stations (weekly check; key needed) | 15 min |
| `fetch_overlays.php` | Flood map layers (state FloodCheck, BCC, QRA) | weekly + `if-missing` |
| `fetch_catchments.php` | State drainage basins: every Queensland catchment outline | weekly + `if-missing` hourly |
| `build_json.php` | Builds sensors.json, alerts.json, series/, cameras.json; sends push | after collectors |

Other scripts: `migrate.php` (database upgrades from `sql/upgrade_*.sql`), `build_floodmask.php`
(flood-prone land grid; run by fetch_overlays), `discover_catchment.php` (section 4),
`history_fetch.php` (WMIP history for working out prediction rules), `push_setup.php`,
`import_sensors.php`.

Key library files (`lib/`): `bootstrap.php` (settings + database handle), `store.php` (sensor,
reading and run-log helpers), `http.php` (HTTP/FTP), `defaults.php` (**every setting**, with
comments), `catchments.php` (outlines, which catchment a point is in), `bom.php`, `warnings.php`,
`alerts.php` (gauge alerts; it consumes the spike-doubt flag, which is computed in
`build_json.php`), `potential.php` (prediction rules), `network.php` (river network),
`dams.php`, `floodmask.php`, `push.php`, `traffic.php`, `arcgis.php`, `wu.php`.

Data files (`data/`): `network.csv` (which gauge flows into which; `dam` flag when the downstream
gauge is a dam lake; "check" notes for unconfirmed joins), `travel_times.csv` (measured peak
travel times), `qld_sites.csv` (state stations with catchment), `bom_exclude.csv` (BoM gauges
left out of the Brisbane group), `bom_tidal.csv`, `bcc_sensors.csv`, `cameras.csv`.

Gauge ids on the map: `B<BoM id>-H` / `-R` (river / rain), `Q<state id>-H|R|F`, council ids as
is. Gauges within 150 m (or the same station) merge into one marker.

### Catchments (0.19)

- `lib/defaults.php` → `catchments` → `live`: key → name and **state basin number** (Brisbane 143,
  Mary 138). `fetch_catchments.php` downloads all ~77 basins; live ones get a detailed outline and
  their own key, the rest are `b<number>` with status `construction`.
- Everything carries a `catchment`: sensors (database column, set by the collectors), dams, flood
  map layers, prediction rules, road events, cameras, community stations; warnings carry
  `catchments` (a list).
- Brisbane River BoM gauges = BoM group IDQ65389 minus `bom_exclude.csv` (historical; keeps the
  bayside city creeks). **Every other catchment takes the BoM gauges inside its outline.**
- The page shows one catchment at a time: `?c=<key>` in the address (the `#` is used for an open
  gauge), remembered in the browser. A link to a gauge in another catchment switches to it. The
  alerts card has "This catchment" / "All catchments". Push "All BoM flood warnings" is for one
  catchment (`push_subscriptions.warn_catchment`, or `all`).
- Older settings files with plain lists (`warning_keywords`, `traffic` → `councils`) still work as
  the Brisbane River's.

### Features worth knowing before changing them

- **Spike doubt**: a jump of ≥3 m within 90 min is doubted (no alert, "?" marker) unless a
  same-river gauge within 25 km rose ≥0.3 m, or 2 more readings stay up. Brassall's 13 m false
  jump on 24/10/2026 is why.
- **Predictions** (`potential`): lower Lockyer rule (Helidon 4.0, Tenthill 3.5, Laidley Warrego
  Hwy 6.0 m triggers; rain watch 70 mm/72 h ending after 30 dry hours unless a trigger rises).
  Shown as a pulsing purple "!" on the map; the area is the flood-prone land joined to the
  trigger gauges (flood mask region fill).
- **"Water coming here" score** for any place/area: local gauges, nearest gauge per waterway
  within 6 km (up to 4), everything upstream on the network (weights major 3, moderate 2, minor 1,
  rising 0.5; half above a dam), dam releases, predictions. Only flooding counts towards Minor.
- **Dams**: states unknown/normal/full/flood_storage/releasing from Seqwater + BoM lake and
  tailwater gauges. Reduced full supply levels (Wivenhoe, Somerset, North Pine, Lake Macdonald)
  mean `fsl` is null; flood storage comes from Seqwater's table. Somerset and Cressbrook have
  `into` Wivenhoe.
- **Side panel** (0.19.6): hidden by default on all screens; the "Details" tab opens it; a dot
  on the tab when a gauge is chosen while closed. Search is inside the panel.

## 4. Adding a new catchment

Allow a couple of releases; work with Vernon running commands on the server and pasting output.

1. **Live entry.** Add it to `catchments` → `live` in `lib/defaults.php` with its basin number
   (find numbers in `public/data/catchments.json`, or the state's drainage basins map). Release,
   upgrade, run `fetch_catchments.php`: the log names the state basin each number matched.
2. **Discover.** On the server: `sudo -u floodwatcher php8.3 bin/discover_catchment.php <key>`.
   It reports BoM pages with gauges inside the outline (with positions), state stations with
   recent heights, the FloodCheck sub-basin names in the box, Seqwater dams, and councils of
   current road events. Saved to `var/discover_<key>.txt`.
3. **BoM pages.** Add the river map page to `bom` → `river_maps`, and the rain pages to
   `rain_maps` (past hour) and `rain_9am_maps` (since 9am). Rain pages come in threes: 24 hours,
   since 9am, past hour. **Check which is which** from the period on each gauge (the 6th popup
   argument, `hours` in `sfw_bom_parse_rain_map`): 24 → 24 h, 1 for all → past hour, mixed small
   numbers → since 9am. Known: south-east Qld river IDQ65412, rain 65413/65414/65415; Wide Bay
   and Burnett river IDQ65420, rain 65421/65422/65423.
4. **State stations.** Add the discover lines (`site,name,catchment`) to `data/qld_sites.csv`.
5. **Flood map.** Add a FloodCheck layer for the catchment with
   `'where' => "sub_name IN ('…')"` and `'catchment' => '<key>'` (copy `floodplain_mary`), and a
   `--ov-<key>` colour in `www/style.css` (both light and dark). Council flood maps are separate
   jobs, if the council publishes an ArcGIS service at all.
6. **Dams.** Add the catchment's Seqwater dams to `dams` → `list` (lake gauge `level`, `tail` if
   there's a gauge below, `node` on the network, `catchment`). Outside south-east Queensland
   Seqwater doesn't apply; Sunwater has no equivalent page yet.
7. **Warnings and roads.** Add river and town names to `bom` → `warning_keywords` → `<key>`, and
   the councils to `traffic` → `councils` → `<key>`. Events in a council shared with another
   catchment are kept only inside the outline.
8. **River network.** Add a section to `data/network.csv`, ordered by gauge position from the
   discover report (upstream to downstream; `dam` when the next gauge is a dam lake). Mark every
   join you're not sure of with "check" and ask Vernon (or a local) to confirm.
9. **Cameras** (`data/cameras.csv`), **history regions** (`history` → `regions`), **predictions**
   and **travel times**: later, from `history_fetch.php` downloads and analysis
   (`dev/analysis/`).
10. **Release**, upgrade, then on the server:
    `collect_bom.php refresh`, `fetch_overlays.php if-missing` (or `fetch_overlays.php <layer key>`),
    `collect_qld.php`, `build_json.php`. New gauges load the last few hours of BoM readings;
    trends appear within an hour and rain totals fill over a day.
11. Check the page (`?c=<key>`) and send screenshots.

## 5. Releasing and deploying

1. Make the change in this repo; bump `version.php` (and the version and "Changes in" section
   at the top of `README.md`); commit; push to `main`. CI (`.github/workflows/ci.yml`) checks PHP
   syntax on 8.2–8.4 and `node --check` on the page scripts.
2. **Vernon publishes the GitHub release** with tag `v<version>`, matching `version.php` exactly
   (CI fails a mismatched tag). Sessions so far couldn't push tags (HTTP 403), so ask him.
3. The package follows by itself: the package repo's "Update to the latest release" workflow runs
   every 15 minutes, runs `tools/bump.sh` and commits the new version and checksum. To do it at
   once: `gh api -X POST repos/verzog/floodwatcher_ynh/actions/workflows/update.yml/dispatches -f ref=main`
   (or Actions tab → "Update to the latest release" → "Run workflow").
4. Vernon upgrades: `sudo yunohost app upgrade floodwatcher -u https://github.com/verzog/floodwatcher_ynh`,
   then runs any one-off commands. Remind him to `cd /var/www/floodwatcher` again after an upgrade
   (the folder is replaced, hence "getcwd() failed" warnings), and to hard-refresh the page.
5. Package changes (cron lines, scripts) go in floodwatcher_ynh. A cron line for a script that
   doesn't exist yet in the released code just logs errors until the code release arrives, so
   release the code first or together.

Database changes: add `sql/upgrade_<version>.sql` (idempotent: `ADD COLUMN IF NOT EXISTS`) and
update `sql/schema.sql`. The package's upgrade script runs `bin/migrate.php`.

## 6. Testing without the real services

`dev/` has mocks for every outside service, real saved pages from October 2026, test settings,
start/stop scripts and a screenshot script. See `dev/README.md`. In short:

```bash
mysql -e 'CREATE DATABASE floodwatcher_test'
dev/start.sh && export SFW_CONFIG=$PWD/dev/config.test.php
php bin/migrate.php && php bin/import_sensors.php && php bin/fetch_catchments.php
php bin/collect_bom.php refresh && php bin/collect_qld.php && php bin/collect_dams.php
php bin/fetch_overlays.php && php bin/build_json.php
python3 dev/screenshots.py mary
```

Also check PHP 8.2 compatibility (PHPCompatibility, `testVersion 8.2-`) when using newer PHP
features; the existing "trim() default characters in PHP 8.6" notices are known and harmless.

**The mocks only behave as well as what's known about the real services.** Three releases went
out on 25/10/2026 that passed against mocks and failed on the real basins service. When a real
service fails, get its actual behaviour first (curl tests on the server), then make the mock
behave the same, then fix.

## 7. Lessons learned (don't repeat these)

1. **Diagnose real services before rewriting code.** The state drainage basins service
   (`spatial-gis.information.qld.gov.au/.../InlandWaters/DrainageBasins/MapServer/1`) refuses
   any query with a filter (`where basin_number = '138'` fails), refuses `outFields=*` with a
   filter, and fails now and then on particular shapes (56–60 once). What works: `where=1=1`
   with `returnIdsOnly`, and shapes by `objectIds` with `outFields=basin_number,basin_name`. The
   fix (0.19.4) uses only those, retries a failed batch, then goes one shape at a time, and skips
   and names a shape that still fails. It took four releases because the first three were
   guesses; one curl test run on the server would have found it in one.
2. **BoM groups aren't catchments.** Group IDQ65390 holds the Mary but also the Maroochy and
   Noosa. Use outlines for anything but the historical Brisbane rule.
3. **BoM map pages have no `<title>`.** Identify rain pages by the period on each gauge (lesson
   in section 4, step 3).
4. **BoM gauge lists refresh hourly**, and reading files are processed once by name, so new
   gauges started empty. Now: `collect_bom.php refresh`, and new gauges reload the last few
   hours.
5. **WMIP**: one station without the requested variable fails a whole multi-station request
   (collect_qld checks station by station once a day). Per-station requests can time out (HTTP
   408, "client took too long"); skip and carry on. `get_site_list` with `MATCH(138*)` lists a
   basin's stations (352 for the Mary). Times are Brisbane time.
6. **Seqwater** has no feed. Parse tables by their headings; a row belongs to a dam only if it
   starts with the dam's name (Somerset's note mentions Wivenhoe); flood releases differ from
   operational releases ("Somerset into Wivenhoe"); "are not expected" is not a release.
7. **The run log's `source` column is VARCHAR(8)**: names like `catchments` don't fit (used
   `basins`).
8. **Settings merge by position for lists** (`array_replace_recursive`). A settings file that
   overrides `overlays` → `layers` must list them in the same order as `lib/defaults.php`. When
   a layer was inserted in 0.19, the test settings silently gave layers the wrong URLs.
9. **Don't truncate a file you're about to read.** `open(p,'w').write(open(p).read()…)` emptied
   `version.php` once (caught before commit). Read first, then write.
10. **CSS `display` beats the `hidden` attribute.** Rows with `display: flex` stayed visible when
    hidden; add `[hidden] { display: none; }` for such elements.
11. **Leaflet**: popups are sized by the map width (`popupWidth()`), not the window; resetting
    an element's `className` wipes other classes (the pulse); redraw markers only when their
    content changes, or open popups close on every map move.
12. **Flood mask maths**: the rasteriser uses integers (1e-5 degree units); floating point made
    stray stripes. Prediction areas are a region fill from seed gauges inside a box, so other
    rivers in the box are left out.
13. **QLDTraffic** sometimes sends road names that aren't Queensland ones (a Vietnamese street
    name once): names with characters beyond Latin Extended-A are shown only as a note.
14. **Day boundaries and 9am**: Brisbane has no daylight saving; "since 9am" is 9am UTC+10.
15. **YunoHost packaging**: no path question for a full-domain app; `more_set_headers` in
    nginx; don't delete logs in remove; helpers 2.1 names (`ynh_config_change_url_nginx`, not
    `ynh_change_url_nginx_config`). Run the package linter.
16. **Tags must match `version.php`.** A release once went out as v0.17.4 holding 0.18.0 code; CI
    failed and the package didn't move. Delete and re-publish with the right tag.
17. **Mock outlines are rough.** In tests, Maroochy gauges show up in the "Mary"; that's the mock
    shape, not the code. Don't "fix" real code for mock artefacts.

## 8. Open items and ideas

- **Mary River**: confirm the "check" joins in `data/network.csv`; rerun
  `discover_catchment.php mary` for more state stations (the first run stopped at a time-out
  after 9 of 352); history download, travel times and prediction rules (Gympie, Tiaro,
  Maryborough); council flood maps (Gympie, Fraser Coast) if published; flood cameras.
- **Brisbane predictions**: proposed "Brisbane River, Fernvale to Savages Crossing" rule from
  history: Rifle Range Rd ≥ 13.5 m gave Savages Crossing minor (9 m) in 6 of 7 floods (≥ 12 m:
  8 of 12), lag about 11 h (4–16). Vernon hasn't said whether to add it. History for bremer,
  stanley, upper_brisbane and brisbane_mid is downloaded (server `var/history/`) but not yet
  analysed. Wivenhoe release history isn't available from WMIP.
- Next catchments: likely Logan–Albert, Pine/Caboolture, Gold Coast rivers (ask Vernon).
- Search sits inside the hidden panel; moving it onto the map was offered.
- QLDTraffic: Vernon applied to TMR for his own API key (shared public key in use meanwhile).
- Ideas not started: SILO rainfall, QLDTraffic cameras, Sunwater dams outside SEQ.

## 9. Security and privacy

- `config.php` (database password, keys) is never in git or the web root; it's chmod 600 on the
  server and written by the package. Keys go through the YunoHost settings page.
- `var/vapid.json` (push keys) must be kept and backed up: losing it breaks every sign-up.
- Push sign-ups store only the browser's push address and keys, watched gauge ids, saved areas
  and choices. No names, emails or IP addresses; community station names aren't stored.
- Everything from outside sources is shown with `textContent`, never as raw HTML. The few map
  tooltips that take a string escape the name first (`esc()` in `www/app.js`), because Leaflet
  renders a tooltip/popup string as HTML.
- Web server logs and GoAccess reports contain IP addresses: don't publish them.
