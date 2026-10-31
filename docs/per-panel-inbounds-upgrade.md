# Per-panel product inbounds — upgrade guide for the existing `/opt/faoxima` install

Branch: `feature/per-panel-inbounds` on `github.com/mohammad1453/Faoxima-amd`
Base commit (what production must currently match): `e17c188`

This upgrade replaces 16 application files and runs the existing `table.php` migration once.
It does **not** touch `.env`, `config.php`, `docker-compose.yml`, Nginx, certificates, ports
(`127.0.0.1:18080` / `127.0.0.1:18443`), `URL_PATH=faoxima`, or any Docker service definition.
No image rebuild, no `install.sh`, no `docker compose up/down`.

> Never use `install.sh` update paths for this: it downloads from the upstream `Mmd-Amir/Faoxima`
> releases, not from your fork, and would remove this feature.

All commands below run **as root** (`sudo -i` first). Run them one block at a time and read the output.

---

## 0. What changes

| Kind | Items |
|---|---|
| New tables | `product_panel_inbound` (product_id, panel_id, inbound_id), `product_panel_inbound_state` (version, baseline, pause) |
| New columns | `xui_sync_service.product_id` (INT NULL), `xui_sync_service.intent_ids` (TEXT NULL) |
| Existing data | Not modified by the migration. Existing products have no mapping, so they behave exactly as before. |
| Files | listed in step 3 |

Behaviour only changes for a product after you give it an explicit per-panel mapping in the admin panel.

---

## 1. Backups (root)

```bash
sudo -i
```

Create a timestamped backup folder outside the app directory:

```bash
TS=$(date +%Y%m%d-%H%M%S); B=/root/faoxima-backup-$TS; mkdir -p "$B"; echo "$B"
```

Write down the printed path; every later step uses `$B` (re-set it with `B=/root/faoxima-backup-...` if you open a new shell).

Full database dump, taken from inside the `db` container using its own credentials (no password typed on the host):

```bash
cd /opt/faoxima
```

```bash
docker compose exec -T db sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers --databases "$MYSQL_DATABASE"' | gzip > "$B/db.sql.gz"
```

Verify the dump is complete and readable (should print `OK`, a table count well above 20, and a `Dump completed` line):

```bash
gzip -t "$B/db.sql.gz" && echo OK
```

```bash
zcat "$B/db.sql.gz" | grep -c '^CREATE TABLE'
```

```bash
zcat "$B/db.sql.gz" | tail -n 1
```

Full copy of the application directory (code, `.env`, `config.php`, compose file, nginx conf dir), preserving owners and modes:

```bash
tar -C /opt -czpf "$B/faoxima-app.tar.gz" faoxima
```

```bash
tar -tzf "$B/faoxima-app.tar.gz" | grep -E 'faoxima/(\.env|config\.php|docker-compose\.yml|panels\.php)$'
```

Separate copies of the production configuration files, plus checksums:

```bash
cp -a /opt/faoxima/.env /opt/faoxima/config.php /opt/faoxima/docker-compose.yml "$B/"
```

```bash
cd "$B" && sha256sum * > SHA256SUMS && cat SHA256SUMS && cd /opt/faoxima
```

Optional, and what to do with the dump if you ever need a real restore test: load it into a throw-away MySQL container, never into production.

---

## 2. Get the code from your fork (root, no change to production yet)

```bash
git clone --branch feature/per-panel-inbounds --single-branch https://github.com/mohammad1453/Faoxima-amd.git /root/faoxima-src-$TS
```

```bash
cd /root/faoxima-src-$TS && git log --oneline -5 && php scripts/tests/xui-product-inbounds.php | tail -n 1
```

The second command needs `php` on the host; if it is not installed, run the test inside the container instead:

```bash
docker run --rm --entrypoint php -v /root/faoxima-src-$TS:/src -w /src faoxima_app:latest scripts/tests/xui-product-inbounds.php | tail -n 1
```

Expected: `All 28 tests passed`.

**Safety check — production must still be the base version.** This compares every file the upgrade touches with
its content at commit `e17c188`. Any `CHANGED` line means production was modified or is a different release:
stop and send me the output instead of continuing.

```bash
cd /root/faoxima-src-$TS
for f in $(git diff --name-only e17c188 HEAD); do
  if git cat-file -e e17c188:"$f" 2>/dev/null; then
    a=$(git show e17c188:"$f" | sha256sum | cut -d' ' -f1); b=$(sha256sum "/opt/faoxima/$f" 2>/dev/null | cut -d' ' -f1)
    [ "$a" = "$b" ] && echo "same     $f" || echo "CHANGED  $f"
  else
    [ -e "/opt/faoxima/$f" ] && echo "EXISTS   $f (new file already present)" || echo "new      $f"
  fi
done
```

---

## 3. Copy only the changed files (root)

Files updated: `api/handlers/ServiceActionHandler.php`, `api/product.php`, `lib/XuiInboundSync.php`,
`panel/product.php`, `panel/productedit.php`, `panels.php`, `re/rx/function/csv_matching_helpers.php`,
`re/rx/index/bootstrap.php`, `sales_readiness.php`, `table.php`, `x-ui_single.php`.

Files added: `lib/XuiProductInbounds.php`, `panel/product_inbounds.php`, `panel/js/product-inbounds.js`,
`panel/lib/product_inbounds_ui.php`, `scripts/tests/xui-product-inbounds.php`, `docs/per-panel-inbounds-upgrade.md`.

Never copied: `.env`, `config.php`, `docker-compose.yml`, `nginx/`, `docker/`, `storage/`, `logs/`.

```bash
cd /root/faoxima-src-$TS
OWNER=$(stat -c '%u:%g' /opt/faoxima/panels.php); echo "owner $OWNER"
for f in $(git diff --name-only e17c188 HEAD); do
  install -D -m 664 "$f" "/opt/faoxima/$f" && chown "$OWNER" "/opt/faoxima/$f" && echo "copied $f"
done
```

Syntax check of the copied PHP files with the production PHP:

```bash
cd /opt/faoxima && for f in $(cd /root/faoxima-src-$TS && git diff --name-only e17c188 HEAD | grep '\.php$'); do docker compose exec -T app php -l "$f"; done
```

Every line must say `No syntax errors detected`.

The bot loads `re/rx/*` through generated `.compiled.php` bundles. They rebuild automatically when a source file's
mtime/size/inode changes (which the copy above does). After the bot has handled one message (send `/start` to it),
confirm the rebuilt bundles contain the new code; both lines must print a number greater than 0:

```bash
grep -c mapping_product_id /opt/faoxima/re/rx/index/.compiled.php
```

```bash
grep -c xpi_cascade_panel_delete /opt/faoxima/re/rx/function/.compiled.php
```

If either prints `0` after the bot answered, delete the stale bundles (safe: they are regenerated on the next request):

```bash
rm -f /opt/faoxima/re/rx/index/.compiled.php /opt/faoxima/re/rx/index/.compiled.map /opt/faoxima/re/rx/function/.compiled.php /opt/faoxima/re/rx/function/.compiled.map
```

---

## 4. Database migration (root)

`table.php` is the project's own idempotent migration (the installer runs it on every update). It only creates
missing tables/columns; the new parts are `CREATE TABLE IF NOT EXISTS` and two `ADD COLUMN` on `xui_sync_service`.

```bash
cd /opt/faoxima && docker compose exec -T app php table.php; echo "exit=$?"
```

Confirm the new schema:

```bash
docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "SHOW TABLES LIKE \"product_panel_inbound%\"; SHOW COLUMNS FROM xui_sync_service WHERE Field IN (\"product_id\",\"intent_ids\"); SELECT COUNT(*) AS products FROM product;"'
```

Expected: both tables listed, both columns listed, product count unchanged from before.

---

## 5. Restart?

Not expected to be required: the `app` container bind-mounts `/opt/faoxima`, and this project ships no OPcache
configuration, so PHP's defaults (timestamp revalidation) apply. This was not verified on your server. Only if the new product UI does not appear after a hard browser refresh, restart
**just** the PHP container (same image, same ports, same config):

```bash
cd /opt/faoxima && docker compose restart app
```

Do not run `docker compose up`, `down`, `build`, or touch `nginx`.

---

## 6. Verify (admin panel + one test panel)

Use a test product and a test customer first.

1. Open the admin panel as you do today (`https://bot1.1mo.us:18443/faoxima/...`) → Products → **Add product**.
   Pick two 3x-ui panels. A separate inbound section must appear for each panel with that panel's own inbound
   names and IDs. Pick different inbounds per panel (e.g. Germany 1,3,5 / France 2,4). Save.
2. Edit the product: both sections must show the saved selections ticked.
3. Buy the product with a test account on each panel; in each 3x-ui panel the client must be on exactly the
   selected inbounds of **that** panel.
4. Edit the product and replace one inbound (e.g. Germany 1,3,5 → 1,3,6). You must see the confirmation page with
   the number of services to check. Confirm.
5. Wait 2–6 minutes (cron `xui_inbound_sync`, every 2 minutes; check that it is enabled in the bot's cron
   settings — default interval 2 minutes). To run one pass immediately and see it work:

   ```bash
   cd /opt/faoxima && docker compose exec -T app php cronbot/xui_inbound_sync.php
   ```

   ```bash
   docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "SELECT email, state, managed_ids, FROM_UNIXTIME(updated_at) updated FROM xui_sync_service WHERE product_id IS NOT NULL ORDER BY updated_at DESC LIMIT 10;"'
   ```

   `updated` must advance after each pass. The product edit page shows the per-panel sync
   status; the test client must gain inbound 6 first and only then lose 5.
6. Legacy-login panel (if you have one): only one inbound is selectable; mismatching existing clients appear
   as "review / legacy_mode_manual_migration", nothing is moved.

Logs:

```bash
cd /opt/faoxima && docker compose logs --since 15m app 2>&1 | grep -Ei 'xpi|xui-inbound-sync|productedit' | tail -n 50
```

Sync records of product-managed services:

```bash
docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "SELECT panel_name, product_id, state, review_reason, COUNT(*) c FROM xui_sync_service WHERE product_id IS NOT NULL GROUP BY 1,2,3,4;"'
```

---

## 7. Rollback

**a) Stop all existing-customer sync immediately (no code change):**

```bash
docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "UPDATE product_panel_inbound_state SET sync_paused = 1;"'
```

New purchases still use saved mappings while paused. To also stop that, remove the mapping in the product edit page
(or roll the code back as below).

**b) Inbound changes already applied to clients are not undone by a code rollback.** If you want customers back on
their previous inbounds, first re-save the previous selection in the product edit page and let the sync finish
(status block shows 0 pending), then roll back.

**c) Restore the previous code (root):**

```bash
cd /opt && for f in $(cd /root/faoxima-src-$TS && git diff --name-only --diff-filter=M e17c188 HEAD); do tar -xzpf "$B/faoxima-app.tar.gz" "faoxima/$f" && echo "restored $f"; done
```

```bash
cd /opt/faoxima && for f in $(cd /root/faoxima-src-$TS && git diff --name-only --diff-filter=A e17c188 HEAD); do rm -v -- "/opt/faoxima/$f"; done
```

The new tables/columns can stay: the old code never reads them. Panel-wide XuiInboundSync continues as before.

**d) Full database restore — last resort only.** It discards every order, payment and customer change made after
the backup. Only use it if data was damaged:

```bash
zcat "$B/db.sql.gz" | docker compose exec -T db sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'
```

---

## 8. What was tested and what was not

Executed (`php scripts/tests/xui-product-inbounds.php`, SQLite + fake token-mode panel API, 28 tests):
resolver precedence and panel isolation (identical IDs on different panels), `/all` behaviour, legacy single-inbound
rule, plan validation (unknown inbound, unreachable panel, unchanged/unloaded sections), version/baseline handling,
transactional product rename, panel rename/delete cascade, attach-before-detach ordering, failed attach never
detaching, unreachable panel, ambiguous ownership → review, write-ahead intent after a crash, mapping changed
mid-run, pause, mapping removal, invoice moved, eligible statuses, duplicate invoices, takeover from panel-wide
sync, purchase recording, legacy report-only scan, `xsync_run_all` integration. The browser component was exercised
in headless Chromium with a stubbed endpoint.

Not executed (needs your real environment):
- MySQL-specific SQL (DDL, `BINARY` comparisons, `GET_LOCK`, existing panel-wide discovery).
- Real 3x-ui responses: `/panel/api/clients/{email}/attach|detach`, `/clients/get/{email}`, `/inbounds/list`
  (token mode) and `inbounds/list` + cookie login (legacy). The attach/detach endpoints are the ones the existing
  XuiInboundSync already uses; confirm your panel version supports them before mapping many customers.
- The admin pages end-to-end (no MySQL in the development container).

## 9. Known limits

- Extra bots under `BOTS_DIR` (separate code copies and databases) are not upgraded by these steps.
- If a product's mapping is removed and later re-added, its existing services are re-adopted from the new
  baseline; inbounds the sync attached earlier are then treated as not owned and are never detached automatically
  (safe, but needs manual cleanup if you want them gone).
- Panels whose names contain HTML special characters (`& < > " '`) cannot be given a mapping (the product edit
  form stores such names escaped); the save is refused with a message.
- Sync speed: up to 40 services per pass every 2 minutes (roughly a few hundred services per hour, estimated).
