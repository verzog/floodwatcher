# Testing Flood Watcher without the real services

Everything here is for development only (kept out of release archives by `.gitattributes`).

- `mock/` — stand-ins for each outside service, run with `php -S` (and pyftpdlib for BoM's FTP):
  `bcc.php` (Brisbane City Council open data, 8099), `wmip.php` (state water monitoring, 8101),
  `arcgis.php` (state and council map services, the drainage basins, QLDTraffic events and the
  two Seqwater pages, 8103), `push.php` (a push service, 8105), `wu.php` (Weather Underground,
  8106). BoM's FTP (2121) serves `fixtures/bom/`.
- `fixtures/` — real pages saved from the services in October 2026: BoM river and rain maps,
  warnings and reading files; Seqwater's dam levels and release pages; the council sensors CSV.
- `config.test.php` — settings pointing every script at the mocks (`SFW_CONFIG`).
- `start.sh` / `stop.sh` — start and stop the mocks and a test site on http://127.0.0.1:8102/.
- `screenshots.py` — desktop, dark and phone screenshots of the test site.

The mock drainage basins are rough shapes (boxes and a hand-drawn Mary), so some gauges near
their edges land in the wrong catchment in tests. The mock basins service refuses filtered
queries and always fails on shape 5, like the real one's habits (see CLAUDE.md, lessons).

A run through:

```bash
mysql -e 'CREATE DATABASE floodwatcher_test'
dev/start.sh
export SFW_CONFIG=$PWD/dev/config.test.php
php bin/migrate.php
php bin/import_sensors.php
php bin/fetch_catchments.php
php bin/collect_bcc.php; php bin/collect_bom.php refresh; php bin/collect_qld.php
php bin/collect_warnings.php; php bin/collect_traffic.php; php bin/collect_dams.php
php bin/fetch_overlays.php
php bin/build_json.php
python3 dev/screenshots.py mary
```

The saved readings are from 22/10/2026, so the page says the data is out of date; that's
expected. To fake a flood, insert readings just before the newest data time and rebuild.

The saved pages in `fixtures/` belong to their publishers (Bureau of Meteorology; Seqwater;
Brisbane City Council open data, CC BY) and are here only as test material.
