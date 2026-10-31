<?php
declare(strict_types=1);
// Run: php scripts/tests/xui-product-inbounds.php
// Exercises the per-panel inbound mapping, plan/validate/apply, and the product reconciliation worker against
// an in-memory SQLite database and a fake token-mode 3x-ui API. MySQL-only SQL (GET_LOCK emulated, DDL,
// SHA1/INSERT IGNORE in panel-wide discovery) and real panel behaviour are NOT covered here.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('XSYNC_SLEEP_ENABLED', false);

function testLoadFunction(string $source, string $name): void
{
    $tokens = token_get_all($source, TOKEN_PARSE);
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
        if (!is_array($tokens[$j] ?? null) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $name) continue;
        $code = ''; $depth = 0; $body = false;
        for ($j = $i; $j < $n; $j++) {
            $token = $tokens[$j];
            $code .= is_array($token) ? $token[1] : $token;
            if ($token === '{') { $depth++; $body = true; }
            if ($token === '}' && --$depth === 0 && $body) { eval($code); return; }
        }
    }
    throw new RuntimeException('Production function not found: ' . $name);
}
function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$root = dirname(__DIR__, 2);
$xuiSource = file_get_contents($root . '/x-ui_single.php');
foreach (['xui_parse_inbound_ids', 'xui_panel_uses_token', 'xui_panel_token'] as $fn) testLoadFunction($xuiSource, $fn);

/* ---- fake token-mode panel -------------------------------------------------------------------- */
$fake = ['clients' => [], 'inbounds' => [], 'log' => [], 'refuse' => [], 'down' => false, 'after_attach' => null, 'throw_after_attach' => false];
function fake_reset(array $clients = [], array $inbounds = [1, 2, 3, 4, 5]): void
{
    $GLOBALS['fake'] = ['clients' => $clients, 'inbounds' => $inbounds, 'log' => [], 'refuse' => [], 'down' => false, 'after_attach' => null, 'throw_after_attach' => false];
}
fake_reset();
function xui_api_token_request($panel, $method, $path, $jsonBody = null, $timeout = 8, $source = '', $username = null)
{
    $f = &$GLOBALS['fake'];
    if ($f['down']) return ['status' => 0, 'error' => 'down'];
    $ok = function ($obj) { return ['status' => 200, 'body' => json_encode(['success' => true, 'obj' => $obj])]; };
    if ($method === 'GET' && $path === '/panel/api/inbounds/list') {
        $list = [];
        foreach ($f['inbounds'] as $id) $list[] = ['id' => $id, 'remark' => "ib$id", 'protocol' => 'vless', 'port' => 1000 + $id, 'enable' => true, 'settings' => '{"clients":[{"id":"SECRET"}]}'];
        return $ok($list);
    }
    if (preg_match('#^/panel/api/clients/get/(.+)$#', $path, $m)) {
        $email = rawurldecode($m[1]);
        $f['log'][] = ['get', $email];
        if (!isset($f['clients'][$email])) return ['status' => 200, 'body' => json_encode(['success' => false, 'msg' => 'not found'])];
        return $ok(['client' => ['email' => $email], 'inboundIds' => array_values($f['clients'][$email])]);
    }
    if (preg_match('#^/panel/api/clients/(.+)/(attach|detach)$#', $path, $m)) {
        $email = rawurldecode($m[1]);
        $ids = $jsonBody['inboundIds'];
        $f['log'][] = [$m[2], $email, $ids];
        foreach ($ids as $id) {
            if ($m[2] === 'attach') {
                if (in_array($id, $f['refuse'], true) || !in_array($id, $f['inbounds'], true)) continue;
                $f['clients'][$email][] = $id;
                $f['clients'][$email] = array_values(array_unique($f['clients'][$email]));
            } else {
                $f['clients'][$email] = array_values(array_diff($f['clients'][$email], [$id]));
            }
        }
        if ($m[2] === 'attach' && $f['after_attach']) { ($f['after_attach'])(); }
        if ($m[2] === 'attach' && $f['throw_after_attach']) { $f['throw_after_attach'] = false; throw new RuntimeException('worker died'); }
        return $ok(null);
    }
    throw new RuntimeException("Unexpected panel call: $method $path");
}
function fake_mutations(): array
{
    return array_values(array_filter($GLOBALS['fake']['log'], function ($e) { return $e[0] !== 'get'; }));
}
function xuisingle_cookie_path() { return sys_get_temp_dir() . '/xpi_test_cookie'; }
function login($code, $verify = true) { return null; }
function panel_get_inbounds_list($url)
{
    $f = $GLOBALS['fake'];
    $obj = [];
    foreach ($f['legacy'] ?? [] as $inboundId => $emails) {
        $clients = [];
        foreach ($emails as $e) $clients[] = ['email' => $e];
        $obj[] = ['id' => $inboundId, 'settings' => json_encode(['clients' => $clients])];
    }
    return ['status' => 200, 'body' => json_encode(['success' => true, 'obj' => $obj])];
}

require_once $root . '/lib/XuiInboundSync.php';
require_once $root . '/lib/XuiProductInbounds.php';

/* ---- sqlite fixture --------------------------------------------------------------------------- */
function new_db(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->sqliteCreateFunction('GET_LOCK', function () { return 1; }, 2);
    $pdo->sqliteCreateFunction('RELEASE_LOCK', function () { return 1; }, 1);
    $pdo->exec("CREATE TABLE marzban_panel (id INTEGER PRIMARY KEY AUTOINCREMENT, name_panel TEXT, code_panel TEXT, type TEXT, url_panel TEXT, inboundid TEXT, xui_api_token TEXT, xui_api_mode TEXT)");
    $pdo->exec("CREATE TABLE product (id INTEGER PRIMARY KEY AUTOINCREMENT, name_product TEXT, Location TEXT, inbounds TEXT)");
    $pdo->exec("CREATE TABLE invoice (id_invoice TEXT PRIMARY KEY, username TEXT, Service_location TEXT, name_product TEXT, Status TEXT, time_sell TEXT)");
    $pdo->exec("CREATE TABLE xui_sync_panel (panel_name TEXT PRIMARY KEY, enabled INT, reference_email TEXT, baseline_ids TEXT, ref_ids TEXT, ref_version INT DEFAULT 0, ref_validated_at INT DEFAULT 0, pending_empty_at INT, last_check_at INT DEFAULT 0, last_status TEXT, last_error TEXT, migration_rev INT DEFAULT 0, updated_at INT DEFAULT 0)");
    $pdo->exec("CREATE TABLE xui_sync_service (svc_key TEXT PRIMARY KEY, panel_name TEXT, email TEXT, id_invoice TEXT, state TEXT DEFAULT 'unclassified', managed_ids TEXT, synced_version INT DEFAULT 0, attempts INT DEFAULT 0, next_attempt_at INT DEFAULT 0, last_error TEXT, ownership_source TEXT, review_reason TEXT, updated_at INT DEFAULT 0, product_id INT, intent_ids TEXT)");
    xpi_ensure_tables($pdo);
    $GLOBALS['pdo'] = $pdo;
    return $pdo;
}
function add_panel(PDO $pdo, string $name, string $mode = 'token', string $default = '1'): array
{
    $pdo->prepare("INSERT INTO marzban_panel (name_panel, code_panel, type, url_panel, inboundid, xui_api_token, xui_api_mode) VALUES (?, ?, 'x-ui_single', 'http://p', ?, ?, ?)")->execute([$name, 'c' . $name, $default, $mode === 'token' ? 'tok' : '', $mode]);
    return $pdo->query("SELECT * FROM marzban_panel WHERE name_panel = " . $pdo->quote($name))->fetch();
}
function add_product(PDO $pdo, string $name, string $loc, ?string $inbounds = null): array
{
    $pdo->prepare("INSERT INTO product (name_product, Location, inbounds) VALUES (?, ?, ?)")->execute([$name, $loc, $inbounds]);
    return $pdo->query("SELECT * FROM product WHERE name_product = " . $pdo->quote($name))->fetch();
}
function add_invoice(PDO $pdo, string $id, string $user, string $panel, string $product, string $status = 'active'): void
{
    $pdo->prepare("INSERT INTO invoice VALUES (?, ?, ?, ?, ?, ?)")->execute([$id, $user, $panel, $product, $status, (string) time()]);
}
function save_mapping(PDO $pdo, array $product, array $panel, array $ids): void
{
    $plan = xpi_plan($pdo, $product, xpi_location_list($product), ['inb_loaded' => [$panel['id'] => 1], 'inb' => [$panel['id'] => $ids]], function () use ($panel) { return ['ok' => true, 'inbounds' => array_fill_keys($GLOBALS['fake']['inbounds'], ['enable' => true])]; });
    check(!$plan['errors'], 'plan errors: ' . json_encode($plan['errors']));
    xpi_apply_plan($pdo, (int) $product['id'], $plan);
}
function svc(PDO $pdo, string $panel, string $email): array
{
    return $pdo->query("SELECT * FROM xui_sync_service WHERE panel_name = " . $pdo->quote($panel) . " AND email = " . $pdo->quote($email))->fetch();
}
function ids_of(string $json): array { return xsync_decode_ids($json); }
function cleanup_run(PDO $pdo, array $panel): array { return xpi_run_panel_products($pdo, $panel); }

$tests = [];

/* ---- resolver --------------------------------------------------------------------------------- */
$tests['resolver precedence: mapping > product.inbounds > panel default'] = function () {
    $pdo = new_db();
    $de = add_panel($pdo, 'Germany', 'token', '9');
    $fr = add_panel($pdo, 'France', 'token', '8');
    $p = add_product($pdo, 'P', 'Germany,France', '7');
    $r = xpi_resolve_inbounds($pdo, $p, $de);
    check($r['source'] === 'product' && $r['raw'] === '7', 'product.inbounds used when no mapping');
    $p2 = add_product($pdo, 'Q', 'Germany');
    $r = xpi_resolve_inbounds($pdo, $p2, $de);
    check($r['source'] === 'panel' && $r['raw'] === '9', 'panel default used when nothing else');
    save_mapping($pdo, $p, $de, [1, 3, 5]);
    save_mapping($pdo, $p, $fr, [1, 3]); // identical numeric IDs on a different panel
    $r = xpi_resolve_inbounds($pdo, $p, $de);
    check($r['source'] === 'mapping' && $r['ids'] === [1, 3, 5] && $r['raw'] === '1,3,5', 'mapping wins for Germany');
    $r = xpi_resolve_inbounds($pdo, $p, $fr);
    check($r['ids'] === [1, 3], 'France keeps its own set, never Germany\'s');
    $pdo->exec("UPDATE product SET Location = '/all' WHERE id = {$p['id']}");
    $p['Location'] = '/all';
    $r = xpi_resolve_inbounds($pdo, $p, $de);
    check($r['source'] === 'product' && $r['raw'] === '7', '/all ignores saved mapping');
    check(count(xpi_get_mapping($pdo, (int) $p['id'], (int) $de['id'])) === 3, '/all keeps rows');
};
$tests['resolver: legacy panel with several inbounds is rejected, not truncated'] = function () {
    $pdo = new_db();
    $lg = add_panel($pdo, 'Legacy', 'legacy');
    $p = add_product($pdo, 'P', 'Legacy');
    $pdo->exec("INSERT INTO product_panel_inbound (product_id, panel_id, inbound_id) VALUES ({$p['id']}, {$lg['id']}, 1), ({$p['id']}, {$lg['id']}, 2)");
    $r = xpi_resolve_inbounds($pdo, $p, $lg);
    check(!empty($r['error']), 'error for >1 inbound on legacy');
};
$tests['resolver: other panel types and test accounts untouched'] = function () {
    $pdo = new_db();
    $pdo->exec("INSERT INTO marzban_panel (name_panel, type, inboundid) VALUES ('M', 'marzban', '1')");
    $m = $pdo->query("SELECT * FROM marzban_panel")->fetch();
    $p = add_product($pdo, 'P', 'M', '{"vmess":["a"]}');
    $r = xpi_resolve_inbounds($pdo, $p, $m);
    check($r['source'] === 'product' && $r['raw'] === '{"vmess":["a"]}', 'marzban product.inbounds passes through');
    $de = add_panel($pdo, 'Germany', 'token', '4');
    $r = xpi_resolve_inbounds($pdo, ['name_product' => 'usertest'], $de, []);
    check($r['source'] === 'panel' && $r['raw'] === '4', 'test account stub uses panel default');
};
$tests['resolver: change-location uses mapping of the real product on the target panel'] = function () {
    $pdo = new_db();
    $de = add_panel($pdo, 'Germany', 'token', '9');
    $p = add_product($pdo, 'P', 'Germany');
    save_mapping($pdo, $p, $de, [2, 3]);
    $r = xpi_resolve_inbounds($pdo, ['name_product' => 'usertest'], $de, ['mapping_product_id' => (int) $p['id']]);
    check($r['source'] === 'mapping' && $r['ids'] === [2, 3], 'mapping resolved via mapping_product_id');
};

/* ---- plan / apply ------------------------------------------------------------------------------ */
$tests['plan validates against the live panel and never trusts submitted IDs'] = function () {
    $pdo = new_db();
    fake_reset();
    $de = add_panel($pdo, 'Germany');
    $p = add_product($pdo, 'P', 'Germany');
    $fetch = function ($panel) { return xpi_fetch_panel_inbounds($panel); };
    $plan = xpi_plan($pdo, $p, ['Germany'], ['inb_loaded' => [$de['id'] => 1], 'inb' => [$de['id'] => [1, 99]]], $fetch);
    check(count($plan['errors']) === 1 && strpos($plan['errors'][0], '99') !== false, 'unknown inbound rejected');
    $GLOBALS['fake']['down'] = true;
    $plan = xpi_plan($pdo, $p, ['Germany'], ['inb_loaded' => [$de['id'] => 1], 'inb' => [$de['id'] => [1]]], $fetch);
    check(count($plan['errors']) === 1, 'unreachable panel refuses the save');
    $info = xpi_fetch_panel_inbounds($de);
    check(!$info['ok'], 'fetch reports failure');
    $GLOBALS['fake']['down'] = false;
    $info = xpi_fetch_panel_inbounds($de);
    check($info['ok'] && !isset($info['inbounds'][1]['settings']) && count($info['inbounds'][1]) === 5, 'only non-sensitive fields are returned');
};
$tests['plan: legacy panel allows only one inbound'] = function () {
    $pdo = new_db();
    fake_reset();
    $lg = add_panel($pdo, 'Legacy', 'legacy');
    $p = add_product($pdo, 'P', 'Legacy');
    $plan = xpi_plan($pdo, $p, ['Legacy'], ['inb_loaded' => [$lg['id'] => 1], 'inb' => [$lg['id'] => [1, 2]]], function () { return ['ok' => true, 'inbounds' => [1 => ['enable' => true], 2 => ['enable' => true]]]; });
    check(count($plan['errors']) === 1, 'two inbounds on legacy rejected');
    $plan = xpi_plan($pdo, $p, ['Legacy'], ['inb_loaded' => [$lg['id'] => 1], 'inb' => [$lg['id'] => [2]]], function () { return ['ok' => true, 'inbounds' => [1 => ['enable' => true], 2 => ['enable' => true]]]; });
    check(!$plan['errors'], 'one inbound accepted');
};
$tests['unrelated edit with panel down keeps mapping; unchanged section is not re-validated'] = function () {
    $pdo = new_db();
    fake_reset();
    $de = add_panel($pdo, 'Germany');
    $p = add_product($pdo, 'P', 'Germany');
    save_mapping($pdo, $p, $de, [1, 3]);
    $calls = 0;
    $fetch = function () use (&$calls) { $calls++; return ['ok' => false, 'inbounds' => []]; };
    $plan = xpi_plan($pdo, $p, ['Germany'], [], $fetch); // section not submitted (panel was down in the browser)
    check(!$plan['errors'] && $plan['panels'][$de['id']]['action'] === 'keep' && $calls === 0, 'no section = keep, no live call');
    $plan = xpi_plan($pdo, $p, ['Germany'], ['inb_loaded' => [$de['id'] => 1], 'inb' => [$de['id'] => [1, 3]]], $fetch);
    check(!$plan['errors'] && $calls === 0 && $plan['panels'][$de['id']]['action'] === 'keep', 'identical selection needs no validation');
    xpi_apply_plan($pdo, (int) $p['id'], $plan);
    check(xpi_get_mapping($pdo, (int) $p['id'], (int) $de['id']) === [1, 3], 'rows intact');
    check((int) xpi_get_state($pdo, (int) $p['id'], (int) $de['id'])['version'] === 1, 'no version bump for no-op');
};
$tests['apply: version bumps only on change, baseline captured on first mapping, removal needs explicit flag'] = function () {
    $pdo = new_db();
    fake_reset();
    $de = add_panel($pdo, 'Germany', 'token', '1,2');
    $p = add_product($pdo, 'P', 'Germany');
    save_mapping($pdo, $p, $de, [1, 3]);
    $st = xpi_get_state($pdo, (int) $p['id'], (int) $de['id']);
    check((int) $st['version'] === 1 && ids_of($st['baseline_ids']) === [1, 2], 'baseline = previous panel default, version 1');
    save_mapping($pdo, $p, $de, [1, 4]);
    $st = xpi_get_state($pdo, (int) $p['id'], (int) $de['id']);
    check((int) $st['version'] === 2 && ids_of($st['baseline_ids']) === [1, 2], 'second change bumps, baseline kept');
    $plan = xpi_plan($pdo, $p, ['Germany'], ['inb_loaded' => [$de['id'] => 1], 'inb' => []], null);
    check(count($plan['errors']) === 1, 'empty selection without remove flag is an error');
    $plan = xpi_plan($pdo, $p, ['Germany'], ['inb_remove' => [$de['id'] => 1]], null);
    check(!$plan['errors'] && $plan['panels'][$de['id']]['action'] === 'remove', 'explicit removal');
    xpi_apply_plan($pdo, (int) $p['id'], $plan);
    check(xpi_get_mapping($pdo, (int) $p['id'], (int) $de['id']) === [], 'rows removed');
    check((int) xpi_get_state($pdo, (int) $p['id'], (int) $de['id'])['version'] === 3, 'removal bumps so services can be released');
};
$tests['/all keeps rows; returning to specific panels validates and restores; dropped panels lose rows'] = function () {
    $pdo = new_db();
    fake_reset();
    $de = add_panel($pdo, 'Germany');
    $fr = add_panel($pdo, 'France');
    $p = add_product($pdo, 'P', 'Germany,France');
    save_mapping($pdo, $p, $de, [1, 3]);
    save_mapping($pdo, $p, $fr, [2]);
    $plan = xpi_plan($pdo, $p, ['/all'], [], null);
    check($plan['delete_panels'] === [] && !$plan['errors'], 'switching to /all deletes nothing');
    $pdo->exec("UPDATE product SET Location = '/all' WHERE id = {$p['id']}");
    $pAll = array_merge($p, ['Location' => '/all']);
    $GLOBALS['fake']['inbounds'] = [1, 2]; // inbound 3 vanished meanwhile
    $plan = xpi_plan($pdo, $pAll, ['Germany', 'France'], [], 'xpi_fetch_panel_inbounds');
    check(count($plan['errors']) === 1 && strpos($plan['errors'][0], '3') !== false, 'restore validates saved rows against the live panel');
    $GLOBALS['fake']['inbounds'] = [1, 2, 3];
    $plan = xpi_plan($pdo, $pAll, ['Germany'], [], 'xpi_fetch_panel_inbounds');
    check(!$plan['errors'] && $plan['delete_panels'] === [(int) $fr['id']] && $plan['panels'][$de['id']]['restore'], 'France dropped, Germany restored');
    xpi_apply_plan($pdo, (int) $p['id'], $plan);
    check(xpi_get_mapping($pdo, (int) $p['id'], (int) $fr['id']) === [] && xpi_get_mapping($pdo, (int) $p['id'], (int) $de['id']) === [1, 3], 'rows follow Location');
};
$tests['confirmation required only when existing services are affected'] = function () {
    $pdo = new_db();
    fake_reset();
    $de = add_panel($pdo, 'Germany');
    $p = add_product($pdo, 'P', 'Germany');
    $live = function () { return ['ok' => true, 'inbounds' => array_fill_keys([1, 2, 3], ['enable' => true])]; };
    $post = ['inb_loaded' => [$de['id'] => 1], 'inb' => [$de['id'] => [1, 2]]];
    check(!xpi_plan($pdo, $p, ['Germany'], $post, $live)['needs_confirm'], 'no customers, no confirmation');
    add_invoice($pdo, 'i1', 'u1', 'Germany', 'P');
    add_invoice($pdo, 'i2', 'u2', 'Germany', 'P', 'removebyuser');
    add_invoice($pdo, 'i3', 'u3', 'Germany', 'OtherProduct');
    $plan = xpi_plan($pdo, $p, ['Germany'], $post, $live);
    check($plan['needs_confirm'] && $plan['panels'][$de['id']]['services'] === 1, 'counts only eligible invoices of this product on this panel');
};
$tests['restoring a mapping after /all with existing customers requires confirmation'] = function () {
    $pdo = new_db();
    fake_reset();
    $de = add_panel($pdo, 'Germany');
    $p = add_product($pdo, 'P', 'Germany');
    save_mapping($pdo, $p, $de, [1, 3]);
    $pdo->exec("UPDATE product SET Location = '/all' WHERE id = {$p['id']}");
    $pAll = array_merge($p, ['Location' => '/all']);
    $plan = xpi_plan($pdo, $pAll, ['Germany'], [], 'xpi_fetch_panel_inbounds');
    check(!$plan['errors'] && $plan['panels'][$de['id']]['restore'] && !$plan['needs_confirm'], 'no customers: restore without confirmation');
    add_invoice($pdo, 'i1', 'u1', 'Germany', 'P');
    $plan = xpi_plan($pdo, $pAll, ['Germany'], [], 'xpi_fetch_panel_inbounds');
    check($plan['needs_confirm'] && $plan['panels'][$de['id']]['services'] === 1, 'restore with customers needs confirmation');
};
$tests['product rename updates exactly the matching invoices in one transaction'] = function () {
    $pdo = new_db();
    $p = add_product($pdo, 'Gold', 'Germany');
    add_product($pdo, 'Gold Plus', 'Germany');
    add_invoice($pdo, 'a', 'u1', 'Germany', 'Gold');
    add_invoice($pdo, 'b', 'u2', 'Germany', 'Gold Plus');
    add_invoice($pdo, 'c', 'u3', 'Germany', 'gold');
    xpi_rename_product($pdo, (int) $p['id'], 'Gold', 'Platinum');
    $names = $pdo->query("SELECT id_invoice, name_product FROM invoice ORDER BY id_invoice")->fetchAll(PDO::FETCH_KEY_PAIR);
    check($names === ['a' => 'Platinum', 'b' => 'Gold Plus', 'c' => 'gold'], 'only the exact old name renamed: ' . json_encode($names));
    $pdo->exec("DROP TABLE invoice");
    $threw = false;
    try { xpi_rename_product($pdo, (int) $p['id'], 'Platinum', 'Broken'); } catch (Throwable $e) { $threw = true; }
    check($threw && $pdo->query("SELECT name_product FROM product WHERE id = {$p['id']}")->fetchColumn() === 'Platinum', 'failure rolls the product rename back');
};
$tests['panel delete/rename cascades'] = function () {
    $pdo = new_db();
    $de = add_panel($pdo, 'Germany');
    $p = add_product($pdo, 'P', 'Germany');
    save_mapping($pdo, $p, $de, [1]);
    xpi_record_creation('Germany', (int) $de['id'], 'u1', (int) $p['id'], [1], 'i1');
    xpi_cascade_panel_rename($pdo, 'Germany', 'Berlin');
    check(svc($pdo, 'Berlin', 'u1')['svc_key'] === xsync_svc_key('Berlin', 'u1'), 'svc_key recomputed on rename');
    check(xpi_get_mapping($pdo, (int) $p['id'], (int) $de['id']) === [1], 'mapping keyed by id survives rename');
    xpi_cascade_panel_delete($pdo, (int) $de['id'], 'Berlin');
    check(xpi_get_mapping($pdo, (int) $p['id'], (int) $de['id']) === [], 'mapping removed with panel');
    check((int) $pdo->query("SELECT COUNT(*) FROM xui_sync_service")->fetchColumn() === 0, 'product-managed sync rows removed with panel');
};

/* ---- reconciliation worker ---------------------------------------------------------------------- */
function setup_service(array $actual, array $mapping, string $default = '1,2', array $inbounds = [1, 2, 3, 4, 5]): array
{
    $pdo = new_db();
    fake_reset(['u1' => $actual], $inbounds);
    $de = add_panel($pdo, 'Germany', 'token', $default);
    $p = add_product($pdo, 'P', 'Germany');
    add_invoice($pdo, 'i1', 'u1', 'Germany', 'P');
    save_mapping($pdo, $p, $de, $mapping);
    return [$pdo, $de, $p];
}
$tests['replace 1,2 -> 1,3: attach before detach, other inbounds untouched'] = function () {
    [$pdo, $de, $p] = setup_service([1, 2, 9], [1, 3], '1,2', [1, 2, 3, 9]);
    $r = cleanup_run($pdo, $de);
    $ops = fake_mutations();
    check($r['synced'] === 1, 'synced: ' . json_encode($r));
    check($ops[0][0] === 'attach' && $ops[0][2] === [3] && $ops[1][0] === 'detach' && $ops[1][2] === [2] && count($ops) === 2, 'attach 3 verified, then detach 2: ' . json_encode($ops));
    sort($GLOBALS['fake']['clients']['u1']);
    check($GLOBALS['fake']['clients']['u1'] === [1, 3, 9], 'manual inbound 9 preserved');
    $before = count($GLOBALS['fake']['log']);
    cleanup_run($pdo, $de);
    $ops = array_slice($GLOBALS['fake']['log'], $before);
    check(count(array_filter($ops, function ($e) { return $e[0] !== 'get'; })) === 0, 'second run is a no-op (idempotent)');
};
$tests['failed attach never detaches; retry succeeds later'] = function () {
    [$pdo, $de, $p] = setup_service([1, 2], [1, 3]);
    $GLOBALS['fake']['refuse'] = [3];
    $r = cleanup_run($pdo, $de);
    check($r['failed'] === 1, 'failed outcome');
    foreach (fake_mutations() as $op) check($op[0] === 'attach', 'no detach after failed attach');
    check($GLOBALS['fake']['clients']['u1'] === [1, 2], 'customer keeps working memberships');
    $s = svc($pdo, 'Germany', 'u1');
    check($s['state'] === 'error' && (int) $s['next_attempt_at'] > time(), 'error with backoff');
    $GLOBALS['fake']['refuse'] = [];
    $pdo->exec("UPDATE xui_sync_service SET next_attempt_at = 0");
    $r = cleanup_run($pdo, $de);
    sort($GLOBALS['fake']['clients']['u1']);
    check($r['synced'] === 1 && $GLOBALS['fake']['clients']['u1'] === [1, 3], 'retry converges');
};
$tests['unreachable panel: nothing changes, service retried later'] = function () {
    [$pdo, $de, $p] = setup_service([1, 2], [1, 3]);
    $GLOBALS['fake']['down'] = true;
    $r = cleanup_run($pdo, $de);
    check($r['failed'] === 1 && fake_mutations() === [] && svc($pdo, 'Germany', 'u1')['state'] === 'error', 'no writes while down');
};
$tests['ambiguous ownership: attach only, detach nothing, mark for manual review'] = function () {
    [$pdo, $de, $p] = setup_service([2, 5], [1, 3]); // baseline {1,2} but client only has 2 (+5)
    $r = cleanup_run($pdo, $de);
    $s = svc($pdo, 'Germany', 'u1');
    check($s['ownership_source'] === 'adopt_ambiguous' && $s['state'] === 'review' && $s['review_reason'] === 'ownership_ambiguous', 'review: ' . json_encode($s));
    foreach (fake_mutations() as $op) check($op[0] === 'attach', 'no detach');
    sort($GLOBALS['fake']['clients']['u1']);
    check($GLOBALS['fake']['clients']['u1'] === [1, 2, 3, 5], 'old memberships all kept, new ones added');
};
$tests['ambiguous with nothing to remove still syncs'] = function () {
    [$pdo, $de, $p] = setup_service([5], [1, 3]);
    cleanup_run($pdo, $de);
    check(svc($pdo, 'Germany', 'u1')['state'] === 'synced', 'synced');
    sort($GLOBALS['fake']['clients']['u1']);
    check($GLOBALS['fake']['clients']['u1'] === [1, 3, 5], 'only additions');
};
$tests['crash after attach: write-ahead intent keeps ownership'] = function () {
    [$pdo, $de, $p] = setup_service([1, 2], [1, 3]);
    $GLOBALS['fake']['throw_after_attach'] = true;
    $svcRow = null;
    $pdo->prepare("UPDATE xui_sync_service SET state = 'pending'")->execute();
    xpi_discover($pdo, $de);
    $svcRow = svc($pdo, 'Germany', 'u1');
    $threw = false;
    try { xpi_process_service($pdo, $de, $svcRow); } catch (Throwable $e) { $threw = true; }
    check($threw, 'simulated worker death');
    $s = svc($pdo, 'Germany', 'u1');
    check(ids_of($s['intent_ids']) === [3], 'intent persisted before attach: ' . $s['intent_ids']);
    $s['intent_ids'] = $s['intent_ids'];
    xpi_process_service($pdo, $de, $s);
    sort($GLOBALS['fake']['clients']['u1']);
    check($GLOBALS['fake']['clients']['u1'] === [1, 3], 'next run completes the replacement');
    $s = svc($pdo, 'Germany', 'u1');
    check($s['state'] === 'synced' && ids_of($s['managed_ids']) === [1, 3], 'ownership of 3 not lost: ' . $s['managed_ids']);
};
$tests['mapping changed mid-run: attached id stays owned, run is superseded, reverting detaches it'] = function () {
    [$pdo, $de, $p] = setup_service([1, 2], [1, 3, 4]);
    $GLOBALS['fake']['refuse'] = [4];
    $GLOBALS['fake']['after_attach'] = function () use ($pdo, $p, $de) {
        // admin edits the mapping while the job runs: back to the original set
        $pdo->exec("DELETE FROM product_panel_inbound WHERE product_id = {$p['id']} AND inbound_id IN (3, 4)");
        $pdo->exec("INSERT INTO product_panel_inbound (product_id, panel_id, inbound_id) VALUES ({$p['id']}, {$de['id']}, 2)");
        $pdo->exec("UPDATE product_panel_inbound_state SET version = version + 1");
        $GLOBALS['fake']['after_attach'] = null;
    };
    $r = cleanup_run($pdo, $de);
    $s = svc($pdo, 'Germany', 'u1');
    check(ids_of($s['managed_ids']) === [1, 2, 3], 'attached 3 recorded as ours: ' . $s['managed_ids']);
    foreach (fake_mutations() as $op) check($op[0] === 'attach', 'no detach with stale target');
    $GLOBALS['fake']['refuse'] = [];
    $pdo->exec("UPDATE xui_sync_service SET next_attempt_at = 0");
    cleanup_run($pdo, $de);
    sort($GLOBALS['fake']['clients']['u1']);
    check($GLOBALS['fake']['clients']['u1'] === [1, 2], 'latest saved mapping wins; our stray attach is removed');
};
$tests['pause stops existing-customer sync but not the saved mapping'] = function () {
    [$pdo, $de, $p] = setup_service([1, 2], [1, 3]);
    xpi_set_paused($pdo, (int) $p['id'], (int) $de['id'], true);
    cleanup_run($pdo, $de);
    check(fake_mutations() === [], 'paused: no panel writes');
    $r = xpi_resolve_inbounds($pdo, $p, $de);
    check($r['source'] === 'mapping' && $r['ids'] === [1, 3], 'new purchases still use the mapping');
    xpi_set_paused($pdo, (int) $p['id'], (int) $de['id'], false);
    cleanup_run($pdo, $de);
    check(count(fake_mutations()) === 2, 'resumes after unpausing');
};
$tests['mapping removed or product on /all: service released without touching the panel'] = function () {
    [$pdo, $de, $p] = setup_service([1, 2], [1, 3]);
    cleanup_run($pdo, $de);
    $plan = xpi_plan($pdo, $p, ['Germany'], ['inb_remove' => [$de['id'] => 1]], null);
    xpi_apply_plan($pdo, (int) $p['id'], $plan);
    $before = fake_mutations();
    cleanup_run($pdo, $de);
    check(svc($pdo, 'Germany', 'u1')['review_reason'] === 'product_not_mapped' && fake_mutations() === $before, 'excluded, memberships unchanged');
    save_mapping($pdo, $p, $de, [1, 4]);
    cleanup_run($pdo, $de);
    check(svc($pdo, 'Germany', 'u1')['state'] === 'synced', 're-adding the mapping re-adopts the service');
};
$tests['invoice moved/removed or status ineligible: excluded; eligible statuses all covered'] = function () {
    [$pdo, $de, $p] = setup_service([1, 2], [1, 3]);
    foreach (['end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold', 'disabled', 'disablebyadmin'] as $i => $status) {
        add_invoice($pdo, "x$i", "ux$i", 'Germany', 'P', $status);
        $GLOBALS['fake']['clients']["ux$i"] = [1, 2];
    }
    add_invoice($pdo, 'gone', 'ugone', 'Germany', 'P', 'removebyuser');
    xpi_discover($pdo, $de);
    $emails = $pdo->query("SELECT email FROM xui_sync_service ORDER BY email")->fetchAll(PDO::FETCH_COLUMN);
    check(count($emails) === 7 && !in_array('ugone', $emails, true), 'all seven statuses discovered, others not: ' . json_encode($emails));
    $pdo->exec("UPDATE invoice SET Service_location = 'Other' WHERE id_invoice = 'i1'");
    cleanup_run($pdo, $de);
    check(svc($pdo, 'Germany', 'u1')['review_reason'] === 'invoice_moved_or_removed', 'moved invoice excluded');
};
$tests['duplicate invoices: one record per service, conflicting products go to review'] = function () {
    [$pdo, $de, $p] = setup_service([1, 2], [1, 3]);
    add_invoice($pdo, 'i1b', 'u1', 'Germany', 'P'); // same product duplicate: harmless
    xpi_discover($pdo, $de);
    check((int) $pdo->query("SELECT COUNT(*) FROM xui_sync_service WHERE email = 'u1'")->fetchColumn() === 1, 'single record');
    add_invoice($pdo, 'old', 'U1', 'Germany', 'Legacy Product', 'disabled'); // historical invoice of another product
    cleanup_run($pdo, $de);
    $s = svc($pdo, 'Germany', 'u1');
    check($s['state'] === 'review' && $s['review_reason'] === 'duplicate_invoices_ambiguous' && fake_mutations() === [], 'ambiguous -> review, no panel writes');
};
$tests['product-specific desired state takes over from panel-wide rows'] = function () {
    [$pdo, $de, $p] = setup_service([1, 2], [1, 3]);
    $pdo->exec("DELETE FROM xui_sync_service");
    $pdo->prepare("INSERT INTO xui_sync_service (svc_key, panel_name, email, id_invoice, state, managed_ids, synced_version, ownership_source) VALUES (?, 'Germany', 'u1', 'i1', 'synced', '[1,2]', 5, 'migration_exact_baseline')")->execute([xsync_svc_key('Germany', 'u1')]);
    xpi_discover($pdo, $de);
    $s = svc($pdo, 'Germany', 'u1');
    check((int) $s['product_id'] === (int) $p['id'] && $s['state'] === 'unclassified' && $s['managed_ids'] === '[]', 'taken over: ' . json_encode($s));
    $isRef = $pdo->prepare("UPDATE xui_sync_service SET product_id = NULL, state = 'excluded', review_reason = 'reference_client'");
    $isRef->execute();
    xpi_discover($pdo, $de);
    check(svc($pdo, 'Germany', 'u1')['product_id'] === null, 'reference client is never adopted');
};
$tests['record_creation: purchases are tracked without panel-wide sync'] = function () {
    [$pdo, $de, $p] = setup_service([1, 3], [1, 3]);
    $pdo->exec("DELETE FROM xui_sync_service");
    xpi_record_creation('Germany', (int) $de['id'], 'u1', (int) $p['id'], [1, 3], 'i1');
    $s = svc($pdo, 'Germany', 'u1');
    check($s['ownership_source'] === 'product_creation' && ids_of($s['managed_ids']) === [1, 3] && (int) $s['synced_version'] === 1, 'recorded: ' . json_encode($s));
    xpi_run_panel_products($pdo, $de);
    check(fake_mutations() === [] && svc($pdo, 'Germany', 'u1')['state'] === 'synced', 'verification pass makes no changes');
};
$tests['legacy panel: report-only, never writes'] = function () {
    $pdo = new_db();
    fake_reset();
    $lg = add_panel($pdo, 'Legacy', 'legacy');
    $p = add_product($pdo, 'P', 'Legacy');
    add_invoice($pdo, 'i1', 'u1', 'Legacy', 'P');
    add_invoice($pdo, 'i2', 'u2', 'Legacy', 'P');
    save_mapping($pdo, $p, $lg, [3]);
    $GLOBALS['fake']['legacy'] = [3 => ['u1'], 1 => ['u2']];
    xpi_run_panel_products($pdo, $lg);
    check(svc($pdo, 'Legacy', 'u1')['state'] === 'synced', 'matching client ok');
    $s = svc($pdo, 'Legacy', 'u2');
    check($s['state'] === 'review' && $s['review_reason'] === 'legacy_mode_manual_migration', 'mismatch flagged for manual review');
    check($GLOBALS['fake']['log'] === [], 'no token API calls, no mutations');
};
$tests['run_all/run_panel: product-only panels are processed under the shared lock; other panel types ignored'] = function () {
    [$pdo, $de, $p] = setup_service([1, 2], [1, 3]);
    $pdo->exec("INSERT INTO marzban_panel (name_panel, type, inboundid) VALUES ('Marz', 'marzban', '1')");
    $r = xsync_run_all();
    check(isset($r['Germany']) && !isset($r['Marz']), 'only x-ui panels with mappings: ' . json_encode(array_keys($r)));
    check(($r['Germany']['products']['synced'] ?? 0) === 1, 'processed: ' . json_encode($r));
    sort($GLOBALS['fake']['clients']['u1']);
    check($GLOBALS['fake']['clients']['u1'] === [1, 3], 'client updated');
};

$failed = 0;
foreach ($tests as $name => $fn) {
    try { $fn(); echo "PASS  $name\n"; }
    catch (Throwable $e) { $failed++; echo "FAIL  $name\n      " . $e->getMessage() . "\n"; }
}
echo ($failed === 0 ? "\nAll " . count($tests) . " tests passed\n" : "\n$failed of " . count($tests) . " tests failed\n");
exit($failed === 0 ? 0 : 1);
