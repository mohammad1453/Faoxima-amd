<?php

// Per-panel inbound selection for products on x-ui_single panels.
// Mapping rows are keyed by marzban_panel.id (never the name), so panel renames cannot orphan them.
// Product-managed services live in xui_sync_service (product_id IS NOT NULL) and are reconciled by the
// existing XuiInboundSync worker; panel-wide sync only ever touches rows with product_id IS NULL.

require_once __DIR__ . '/XuiInboundSync.php';

if (!defined('XPI_LEGACY_SCAN_INTERVAL')) {
    define('XPI_LEGACY_SCAN_INTERVAL', 1800);
}
if (!defined('XPI_DISCOVER_LIMIT')) {
    define('XPI_DISCOVER_LIMIT', 5000);
}

function xpi_driver($pdo)
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
}

// MySQL's default comparison is case/accent-insensitive; names must match exactly.
function xpi_bin_eq($pdo, $column)
{
    return xpi_driver($pdo) === 'mysql' ? "$column = BINARY ?" : "$column = ?";
}

function xpi_ensure_tables($pdo)
{
    static $done = array();
    $mysql = xpi_driver($pdo) === 'mysql';
    $key = spl_object_id($pdo);
    if ($mysql && isset($done[$key])) {
        return;
    }
    $pk = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_panel_inbound (
        id $pk,
        product_id INT UNSIGNED NOT NULL,
        panel_id INT UNSIGNED NOT NULL,
        inbound_id INT NOT NULL,
        UNIQUE (product_id, panel_id, inbound_id)" . ($mysql ? ", KEY idx_ppi_panel (panel_id)" : '') . "
    )$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_panel_inbound_state (
        product_id INT UNSIGNED NOT NULL,
        panel_id INT UNSIGNED NOT NULL,
        version INT UNSIGNED NOT NULL DEFAULT 1,
        baseline_ids TEXT NULL,
        sync_paused TINYINT NOT NULL DEFAULT 0,
        last_scan_at BIGINT NOT NULL DEFAULT 0,
        updated_at BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY (product_id, panel_id)
    )$tail");
    $done[$key] = true;
}

function xpi_ids($raw)
{
    return xsync_ids(xui_parse_inbound_ids($raw));
}

function xpi_location_list($product)
{
    $list = array();
    foreach (explode(',', (string) (is_array($product) ? ($product['Location'] ?? '') : '')) as $item) {
        $item = trim($item);
        if ($item !== '') {
            $list[] = $item;
        }
    }
    return $list;
}

function xpi_is_all($product)
{
    return in_array('/all', xpi_location_list($product), true);
}

// The mapping applies only when the product names the panel explicitly; '/all' keeps legacy behaviour.
function xpi_covers($product, $panelName)
{
    return !xpi_is_all($product) && in_array((string) $panelName, xpi_location_list($product), true);
}

function xpi_get_mapping($pdo, $productId, $panelId)
{
    try {
        $stmt = $pdo->prepare('SELECT inbound_id FROM product_panel_inbound WHERE product_id = ? AND panel_id = ? ORDER BY inbound_id');
        $stmt->execute(array((int) $productId, (int) $panelId));
        return xsync_ids($stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (\Throwable $e) {
        // Table missing (table.php not run yet) means no mappings: callers fall back to legacy behaviour.
        return array();
    }
}

function xpi_get_mappings_for_product($pdo, $productId)
{
    $out = array();
    try {
        $stmt = $pdo->prepare('SELECT panel_id, inbound_id FROM product_panel_inbound WHERE product_id = ? ORDER BY panel_id, inbound_id');
        $stmt->execute(array((int) $productId));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['panel_id']][] = (int) $row['inbound_id'];
        }
    } catch (\Throwable $e) {
    }
    return $out;
}

function xpi_get_state($pdo, $productId, $panelId)
{
    try {
        $stmt = $pdo->prepare('SELECT * FROM product_panel_inbound_state WHERE product_id = ? AND panel_id = ?');
        $stmt->execute(array((int) $productId, (int) $panelId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (\Throwable $e) {
        return null;
    }
}

function xpi_load_product($pdo, $productId)
{
    $stmt = $pdo->prepare('SELECT * FROM product WHERE id = ?');
    $stmt->execute(array((int) $productId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

// What a product used before it had an explicit mapping (same precedence as client creation).
function xpi_legacy_ids($product, array $panel)
{
    if (is_array($product) && isset($product['inbounds']) && $product['inbounds'] != null) {
        return xpi_ids($product['inbounds']);
    }
    return xpi_ids($panel['inboundid'] ?? '');
}

// Single resolver used for client creation. 'mapping' wins only for explicit panel names on x-ui_single.
function xpi_resolve_inbounds($pdo, $product, array $panel, array $dataConfig = array())
{
    $hasProductInbounds = is_array($product) && isset($product['inbounds']) && $product['inbounds'] != null;
    $result = array(
        'source' => $hasProductInbounds ? 'product' : 'panel',
        'raw' => $hasProductInbounds ? $product['inbounds'] : ($panel['inboundid'] ?? null),
        'ids' => array(),
        'product_id' => null,
    );
    if (($panel['type'] ?? '') !== 'x-ui_single') {
        return $result;
    }
    $productRow = null;
    if (is_array($product) && !empty($product['id'])) {
        $productRow = $product;
    } elseif (!empty($dataConfig['mapping_product_id'])) {
        $productRow = xpi_load_product($pdo, (int) $dataConfig['mapping_product_id']);
    }
    if (!is_array($productRow) || empty($panel['id']) || !xpi_covers($productRow, $panel['name_panel'] ?? '')) {
        return $result;
    }
    $ids = xpi_get_mapping($pdo, (int) $productRow['id'], (int) $panel['id']);
    if (empty($ids)) {
        return $result;
    }
    if (!xui_panel_uses_token($panel) && count($ids) !== 1) {
        return array('error' => 'This panel uses legacy login and supports exactly one inbound per product; reconfigure the product mapping.');
    }
    return array('source' => 'mapping', 'raw' => implode(',', $ids), 'ids' => $ids, 'product_id' => (int) $productRow['id']);
}

// Inbound list for the admin UI and save-time validation. Returns only non-sensitive fields.
function xpi_fetch_panel_inbounds(array $panel)
{
    if (($panel['type'] ?? '') !== 'x-ui_single') {
        return array('ok' => false, 'msg' => 'unsupported_panel_type', 'inbounds' => array());
    }
    if (xui_panel_uses_token($panel)) {
        $resp = xui_api_token_request($panel, 'GET', '/panel/api/inbounds/list', null, 8, 'xpi_inbounds_list');
    } else {
        $login = login($panel['code_panel']);
        if (is_array($login) && array_key_exists('success', $login) && $login['success'] === false) {
            @unlink(xuisingle_cookie_path());
            return array('ok' => false, 'msg' => 'login_failed', 'inbounds' => array());
        }
        $resp = panel_get_inbounds_list($panel['url_panel']);
        @unlink(xuisingle_cookie_path());
    }
    if (!is_array($resp) || !empty($resp['error'])) {
        return array('ok' => false, 'msg' => 'panel_unreachable', 'inbounds' => array());
    }
    $status = (int) ($resp['status'] ?? 0);
    $decoded = json_decode((string) ($resp['body'] ?? ''), true);
    if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['success']) || !is_array($decoded['obj'] ?? null)) {
        return array('ok' => false, 'msg' => 'panel_bad_response', 'inbounds' => array());
    }
    $list = array();
    foreach ($decoded['obj'] as $inbound) {
        if (!is_array($inbound) || !isset($inbound['id']) || (int) $inbound['id'] <= 0) {
            continue;
        }
        $id = (int) $inbound['id'];
        // settings (client UUIDs) is deliberately dropped.
        $list[$id] = array(
            'id' => $id,
            'remark' => (string) ($inbound['remark'] ?? ''),
            'protocol' => (string) ($inbound['protocol'] ?? ''),
            'port' => (int) ($inbound['port'] ?? 0),
            'enable' => !empty($inbound['enable']),
        );
    }
    return array('ok' => true, 'msg' => '', 'inbounds' => $list);
}

function xpi_count_services($pdo, $product, array $panel)
{
    if (!is_array($product) || empty($product['id'])) {
        return 0;
    }
    $statuses = xsync_eligible_statuses();
    $marks = implode(',', array_fill(0, count($statuses), '?'));
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM invoice WHERE Service_location = ? AND ' . xpi_bin_eq($pdo, 'name_product') . " AND Status IN ($marks) AND username IS NOT NULL AND username <> ''");
    $stmt->execute(array_merge(array($panel['name_panel'], $product['name_product']), $statuses));
    return (int) $stmt->fetchColumn();
}

function xpi_post_ids($post, $key, $panelId)
{
    $raw = (isset($post[$key]) && is_array($post[$key]) && isset($post[$key][$panelId]) && is_array($post[$key][$panelId])) ? $post[$key][$panelId] : array();
    return xsync_ids($raw);
}

// Computes what a product save would change, validating against the live panel. Never writes.
// $fetch is injectable for tests. $locationNames is the already-normalised new Location list.
function xpi_plan($pdo, $product, array $locationNames, array $post, $fetch = null)
{
    $fetch = $fetch ?: 'xpi_fetch_panel_inbounds';
    $plan = array('errors' => array(), 'warnings' => array(), 'panels' => array(), 'delete_panels' => array(), 'needs_confirm' => false, 'hash' => '');
    $productId = (is_array($product) && !empty($product['id'])) ? (int) $product['id'] : 0;
    $stored = $productId ? xpi_get_mappings_for_product($pdo, $productId) : array();
    $wasAll = is_array($product) && xpi_is_all($product);
    $isAll = in_array('/all', $locationNames, true);

    $coveredIds = array();
    if (!$isAll) {
        foreach ($locationNames as $name) {
            $stmt = $pdo->prepare('SELECT * FROM marzban_panel WHERE name_panel = ?');
            $stmt->execute(array($name));
            $panel = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($panel)) {
                // The product edit form stores HTML-escaped names; such panels cannot be matched at runtime.
                $decoded = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
                if ($decoded !== $name) {
                    $stmt->execute(array($decoded));
                    $special = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (is_array($special) && ($special['type'] ?? '') === 'x-ui_single' && (!empty($post['inb_loaded'][$special['id']]) || !empty($post['inb_remove'][$special['id']]))) {
                        $plan['errors'][] = "نام پنل «{$decoded}» شامل نویسه‌های خاص است و برای نگاشت اینباند پشتیبانی نمی‌شود؛ نام پنل را ساده کنید.";
                    }
                }
                continue;
            }
            if (($panel['type'] ?? '') !== 'x-ui_single') {
                continue;
            }
            $pid = (int) $panel['id'];
            $coveredIds[$pid] = true;
            $token = xui_panel_uses_token($panel);
            $cur = $stored[$pid] ?? array();
            $loaded = !empty($post['inb_loaded'][$pid]);
            $remove = !empty($post['inb_remove'][$pid]);
            $new = $cur;
            $action = 'keep';
            $restore = false;
            if ($remove) {
                if (!empty($cur)) {
                    $new = array();
                    $action = 'remove';
                }
            } elseif ($loaded) {
                $sel = xpi_post_ids($post, 'inb', $pid);
                if (empty($sel)) {
                    if (!empty($cur)) {
                        $plan['errors'][] = "پنل «{$name}»: حداقل یک اینباند انتخاب کنید یا گزینه حذف نگاشت را فعال کنید.";
                    }
                } else {
                    if (!$token && count($sel) > 1) {
                        $plan['errors'][] = "پنل «{$name}» با روش ورود قدیمی کار می‌کند و فقط یک اینباند را پشتیبانی می‌کند. برای چند اینباند، پنل را به حالت توکن ببرید.";
                    }
                    if ($sel !== $cur) {
                        $new = $sel;
                        $action = 'set';
                    }
                }
            }
            if ($action === 'keep' && !empty($cur) && $wasAll) {
                $restore = true; // saved mapping comes back after the product leaves '/all'
            }
            $checkIds = $action === 'set' ? $new : ($restore ? $cur : array());
            if (!empty($checkIds)) {
                $live = call_user_func($fetch, $panel);
                if (empty($live['ok'])) {
                    $plan['errors'][] = "پنل «{$name}» در دسترس نیست؛ امکان اعتبارسنجی اینباندها وجود ندارد. تغییری ذخیره نشد.";
                } else {
                    $missing = array_values(array_diff($checkIds, array_keys($live['inbounds'])));
                    if ($missing) {
                        $plan['errors'][] = "پنل «{$name}»: اینباندهای " . implode(', ', $missing) . ' روی این پنل وجود ندارند.';
                    }
                    foreach ($checkIds as $cid) {
                        if (isset($live['inbounds'][$cid]) && !$live['inbounds'][$cid]['enable']) {
                            $plan['warnings'][] = "پنل «{$name}»: اینباند {$cid} روی پنل غیرفعال است.";
                        }
                    }
                }
            }
            $services = ($productId && ($action !== 'keep' || $restore)) ? xpi_count_services($pdo, $product, $panel) : 0;
            $baseline = null;
            $baselineRemoval = array();
            if ($action === 'set' && empty($cur)) {
                $baseline = xpi_legacy_ids($product, $panel);
                $baselineRemoval = array_values(array_diff($baseline, $new));
            }
            $plan['panels'][$pid] = array(
                'name' => $name, 'token' => $token, 'current' => $cur, 'new' => $new, 'action' => $action, 'restore' => $restore,
                'added' => array_values(array_diff($new, $cur)), 'removed' => array_values(array_diff($cur, $new)),
                'services' => $services, 'baseline' => $baseline, 'baseline_removal' => $baselineRemoval,
            );
            // Restoring a mapping after '/all' also schedules reconciliation of existing customers.
            if (($action !== 'keep' || $restore) && $services > 0) {
                $plan['needs_confirm'] = true;
            }
        }
        // Panels dropped from Location lose their mapping; a product on '/all' keeps its rows (ignored).
        foreach ($stored as $pid => $ids) {
            if (!isset($coveredIds[$pid])) {
                $plan['delete_panels'][] = (int) $pid;
            }
        }
        if ($plan['delete_panels'] && $productId) {
            $plan['needs_confirm'] = $plan['needs_confirm'] || xpi_has_services($pdo, $product, $plan['delete_panels']);
        }
    }
    $core = array();
    foreach ($plan['panels'] as $pid => $p) {
        $core[$pid] = array($p['action'], $p['new'], $p['restore']);
    }
    $plan['hash'] = hash('sha256', json_encode(array($core, $plan['delete_panels'], $isAll)));
    return $plan;
}

function xpi_has_services($pdo, $product, array $panelIds)
{
    foreach ($panelIds as $pid) {
        $stmt = $pdo->prepare('SELECT * FROM marzban_panel WHERE id = ?');
        $stmt->execute(array($pid));
        $panel = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($panel) && xpi_count_services($pdo, $product, $panel) > 0) {
            return true;
        }
    }
    return false;
}

function xpi_bump_state($pdo, $productId, $panelId, $baselineJson = null)
{
    $state = xpi_get_state($pdo, $productId, $panelId);
    $now = time();
    if (!is_array($state)) {
        $stmt = $pdo->prepare('INSERT INTO product_panel_inbound_state (product_id, panel_id, version, baseline_ids, updated_at) VALUES (?, ?, 1, ?, ?)');
        $stmt->execute(array((int) $productId, (int) $panelId, $baselineJson ?? '[]', $now));
        return;
    }
    if ($baselineJson !== null) {
        $stmt = $pdo->prepare('UPDATE product_panel_inbound_state SET version = version + 1, baseline_ids = ?, updated_at = ? WHERE product_id = ? AND panel_id = ?');
        $stmt->execute(array($baselineJson, $now, (int) $productId, (int) $panelId));
    } else {
        $stmt = $pdo->prepare('UPDATE product_panel_inbound_state SET version = version + 1, updated_at = ? WHERE product_id = ? AND panel_id = ?');
        $stmt->execute(array($now, (int) $productId, (int) $panelId));
    }
}

// Writes a validated plan in one transaction. Bumps the version only when the effective set changed.
function xpi_apply_plan($pdo, $productId, array $plan)
{
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        foreach ($plan['panels'] as $pid => $p) {
            if ($p['action'] === 'keep' && empty($p['restore'])) {
                continue;
            }
            if ($p['action'] === 'keep') {
                xpi_bump_state($pdo, $productId, $pid);
                continue;
            }
            $current = xpi_get_mapping($pdo, $productId, $pid);
            foreach (array_diff($current, $p['new']) as $gone) {
                $pdo->prepare('DELETE FROM product_panel_inbound WHERE product_id = ? AND panel_id = ? AND inbound_id = ?')->execute(array($productId, $pid, $gone));
            }
            foreach (array_diff($p['new'], $current) as $add) {
                $pdo->prepare('INSERT INTO product_panel_inbound (product_id, panel_id, inbound_id) VALUES (?, ?, ?)')->execute(array($productId, $pid, $add));
            }
            $baseline = (empty($current) && !empty($p['new'])) ? json_encode(xsync_ids($p['baseline'] ?? array())) : null;
            xpi_bump_state($pdo, $productId, $pid, $baseline);
        }
        foreach ($plan['delete_panels'] as $pid) {
            $pdo->prepare('DELETE FROM product_panel_inbound WHERE product_id = ? AND panel_id = ?')->execute(array($productId, $pid));
            if (xpi_get_state($pdo, $productId, $pid) !== null) {
                xpi_bump_state($pdo, $productId, $pid);
            }
        }
        if ($own) {
            $pdo->commit();
        }
    } catch (\Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function xpi_set_paused($pdo, $productId, $panelId, $paused)
{
    $stmt = $pdo->prepare('UPDATE product_panel_inbound_state SET sync_paused = ?, updated_at = ? WHERE product_id = ? AND panel_id = ?');
    $stmt->execute(array($paused ? 1 : 0, time(), (int) $productId, (int) $panelId));
}

function xpi_requeue_review($pdo, $productId, $panelName)
{
    $stmt = $pdo->prepare("UPDATE xui_sync_service SET state = 'unclassified', review_reason = NULL, attempts = 0, next_attempt_at = 0, last_error = NULL, updated_at = ? WHERE product_id = ? AND panel_name = ? AND state = 'review'");
    $stmt->execute(array(time(), (int) $productId, $panelName));
    return $stmt->rowCount();
}

function xpi_delete_for_products($pdo, array $productIds)
{
    $ids = array_values(array_filter(array_map('intval', $productIds), function ($v) { return $v > 0; }));
    if (!$ids) {
        return;
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    try {
        $pdo->prepare("DELETE FROM product_panel_inbound WHERE product_id IN ($marks)")->execute($ids);
        $pdo->prepare("DELETE FROM product_panel_inbound_state WHERE product_id IN ($marks)")->execute($ids);
    } catch (\Throwable $e) {
        error_log('[xpi] delete_for_products: ' . $e->getMessage());
    }
}

function xpi_cascade_panel_delete($pdo, $panelId, $panelName)
{
    try {
        $pdo->prepare('DELETE FROM product_panel_inbound WHERE panel_id = ?')->execute(array((int) $panelId));
        $pdo->prepare('DELETE FROM product_panel_inbound_state WHERE panel_id = ?')->execute(array((int) $panelId));
        $pdo->prepare('DELETE FROM xui_sync_service WHERE panel_name = ? AND product_id IS NOT NULL')->execute(array($panelName));
    } catch (\Throwable $e) {
        error_log('[xpi] cascade_panel_delete: ' . $e->getMessage());
    }
}

// xui_sync_* are keyed by panel name (svc_key hashes it), so a rename must rewrite both.
function xpi_cascade_panel_rename($pdo, $oldName, $newName)
{
    try {
        $pdo->prepare('UPDATE xui_sync_panel SET panel_name = ? WHERE panel_name = ?')->execute(array($newName, $oldName));
        $rows = $pdo->prepare('SELECT svc_key, email FROM xui_sync_service WHERE panel_name = ?');
        $rows->execute(array($oldName));
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            try {
                $pdo->prepare('UPDATE xui_sync_service SET panel_name = ?, svc_key = ? WHERE svc_key = ?')->execute(array($newName, xsync_svc_key($newName, $row['email']), $row['svc_key']));
            } catch (\Throwable $e) {
                $pdo->prepare('DELETE FROM xui_sync_service WHERE svc_key = ?')->execute(array($row['svc_key']));
            }
        }
    } catch (\Throwable $e) {
        error_log('[xpi] cascade_panel_rename: ' . $e->getMessage());
    }
}

// Product update and invoice rename in one transaction; only invoices carrying the exact old name change.
function xpi_rename_product($pdo, $productId, $oldName, $newName)
{
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare('UPDATE product SET name_product = ? WHERE id = ?')->execute(array($newName, (int) $productId));
        $pdo->prepare('UPDATE invoice SET name_product = ? WHERE ' . xpi_bin_eq($pdo, 'name_product'))->execute(array($newName, $oldName));
        if ($own) {
            $pdo->commit();
        }
    } catch (\Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// Records a client created through an explicit mapping, independent of panel-wide sync being enabled.
function xpi_record_creation($panelName, $panelId, $email, $productId, $ids, $invoiceId = null)
{
    $pdo = xsync_pdo();
    if (!$pdo) {
        return false;
    }
    try {
        xsync_ensure_tables($pdo);
        $ids = xsync_ids($ids);
        $state = xpi_get_state($pdo, $productId, $panelId);
        $version = is_array($state) ? (int) $state['version'] : 0;
        $synced = ($ids === xpi_get_mapping($pdo, $productId, $panelId)) ? $version : 0;
        $key = xsync_svc_key($panelName, $email);
        $exists = $pdo->prepare('SELECT 1 FROM xui_sync_service WHERE svc_key = ?');
        $exists->execute(array($key));
        $now = time();
        if ($exists->fetchColumn()) {
            $stmt = $pdo->prepare("UPDATE xui_sync_service SET id_invoice = ?, state = 'pending', managed_ids = ?, synced_version = ?, ownership_source = 'product_creation', product_id = ?, intent_ids = '[]', review_reason = NULL, attempts = 0, next_attempt_at = 0, last_error = NULL, updated_at = ? WHERE svc_key = ?");
            $stmt->execute(array($invoiceId, json_encode($ids), $synced, (int) $productId, $now, $key));
        } else {
            $stmt = $pdo->prepare("INSERT INTO xui_sync_service (svc_key, panel_name, email, id_invoice, state, managed_ids, synced_version, ownership_source, product_id, intent_ids, updated_at) VALUES (?, ?, ?, ?, 'pending', ?, ?, 'product_creation', ?, '[]', ?)");
            $stmt->execute(array($key, $panelName, $email, $invoiceId, json_encode($ids), $synced, (int) $productId, $now));
        }
        return true;
    } catch (\Throwable $e) {
        error_log('[xpi] record_creation failed: ' . $e->getMessage());
        return false;
    }
}

/* ---------------------------------------------------------------------------------------------
 * Worker side: product-managed services (xui_sync_service.product_id IS NOT NULL)
 * ------------------------------------------------------------------------------------------- */

function xpi_mark($pdo, $svc, $state, $reason, $error = null)
{
    xsync_update_service($pdo, $svc['svc_key'], array('state' => $state, 'review_reason' => $reason, 'last_error' => $error === null ? null : xsync_sanitize($error, 400), 'next_attempt_at' => 0));
    return $state;
}

// Re-reads everything from the database so a stale queue entry can never act on old settings.
function xpi_revalidate($pdo, $svc)
{
    $deny = function ($state, $reason) {
        return array('ok' => false, 'state' => $state, 'reason' => $reason);
    };
    if ((string) ($svc['id_invoice'] ?? '') === '') {
        return $deny('retry', 'invoice_not_linked'); // discovery links it once the invoice shows up on this panel
    }
    $stmt = $pdo->prepare('SELECT * FROM invoice WHERE id_invoice = ?');
    $stmt->execute(array($svc['id_invoice']));
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($invoice) || (string) $invoice['Service_location'] !== (string) $svc['panel_name']
        || !in_array($invoice['Status'], xsync_eligible_statuses(), true) || strcasecmp((string) $invoice['username'], (string) $svc['email']) !== 0) {
        return $deny('excluded', 'invoice_moved_or_removed');
    }
    $product = xpi_load_product($pdo, (int) $svc['product_id']);
    if (!$product) {
        return $deny('excluded', 'product_missing');
    }
    if ((string) $invoice['name_product'] !== (string) $product['name_product']) {
        return $deny('excluded', 'invoice_product_mismatch');
    }
    $panel = xsync_load_panel($svc['panel_name']);
    if (!is_array($panel) || !xpi_covers($product, $svc['panel_name'])) {
        return $deny('excluded', 'product_not_mapped');
    }
    $ids = xpi_get_mapping($pdo, (int) $product['id'], (int) $panel['id']);
    $state = xpi_get_state($pdo, (int) $product['id'], (int) $panel['id']);
    if (empty($ids) || !is_array($state)) {
        return $deny('excluded', 'product_not_mapped');
    }
    $statuses = xsync_eligible_statuses();
    $marks = implode(',', array_fill(0, count($statuses), '?'));
    $dup = $pdo->prepare("SELECT id_invoice, name_product FROM invoice WHERE Service_location = ? AND LOWER(username) = LOWER(?) AND id_invoice <> ? AND Status IN ($marks)");
    $dup->execute(array_merge(array($svc['panel_name'], $svc['email'], $svc['id_invoice']), $statuses));
    foreach ($dup->fetchAll(PDO::FETCH_ASSOC) as $other) {
        if ((string) $other['name_product'] !== (string) $product['name_product']) {
            return $deny('review', 'duplicate_invoices_ambiguous');
        }
    }
    return array('ok' => true, 'product' => $product, 'invoice' => $invoice, 'panel' => $panel, 'ids' => $ids, 'state' => $state);
}

// Creates/adopts one sync record per (panel, email) for eligible invoices of mapped products.
function xpi_discover($pdo, array $panel)
{
    $panelName = $panel['name_panel'];
    $states = $pdo->prepare('SELECT product_id FROM product_panel_inbound_state WHERE panel_id = ?');
    $states->execute(array((int) $panel['id']));
    $statuses = xsync_eligible_statuses();
    $marks = implode(',', array_fill(0, count($statuses), '?'));
    $created = 0;
    foreach ($states->fetchAll(PDO::FETCH_COLUMN) as $productId) {
        if (!xsync_time_left()) {
            break;
        }
        $product = xpi_load_product($pdo, (int) $productId);
        if (!$product || !xpi_covers($product, $panelName) || empty(xpi_get_mapping($pdo, (int) $productId, (int) $panel['id']))) {
            continue;
        }
        $pdo->prepare("UPDATE xui_sync_service SET state = 'unclassified', review_reason = NULL, attempts = 0, next_attempt_at = 0, updated_at = ? WHERE product_id = ? AND panel_name = ? AND state = 'excluded' AND review_reason = 'product_not_mapped'")->execute(array(time(), (int) $productId, $panelName));
        $stmt = $pdo->prepare('SELECT id_invoice, username FROM invoice WHERE Service_location = ? AND ' . xpi_bin_eq($pdo, 'name_product') . " AND Status IN ($marks) AND username IS NOT NULL AND username <> '' ORDER BY time_sell DESC LIMIT " . (int) XPI_DISCOVER_LIMIT);
        $stmt->execute(array_merge(array($panelName, $product['name_product']), $statuses));
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach (array_chunk($invoices, 400) as $chunk) {
            $keys = array();
            foreach ($chunk as $row) {
                $keys[xsync_svc_key($panelName, $row['username'])] = true;
            }
            $existing = array();
            $in = implode(',', array_fill(0, count($keys), '?'));
            $q = $pdo->prepare("SELECT svc_key, product_id, review_reason, id_invoice FROM xui_sync_service WHERE svc_key IN ($in)");
            $q->execute(array_keys($keys));
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $existing[$row['svc_key']] = $row;
            }
            foreach ($chunk as $row) {
                $key = xsync_svc_key($panelName, $row['username']);
                $now = time();
                if (!isset($existing[$key])) {
                    $pdo->prepare("INSERT INTO xui_sync_service (svc_key, panel_name, email, id_invoice, state, managed_ids, ownership_source, product_id, intent_ids, updated_at) VALUES (?, ?, ?, ?, 'unclassified', '[]', 'product_discovered', ?, '[]', ?)")
                        ->execute(array($key, $panelName, $row['username'], $row['id_invoice'], (int) $productId, $now));
                    $existing[$key] = array('svc_key' => $key, 'product_id' => $productId, 'review_reason' => null);
                    $created++;
                } elseif ($existing[$key]['product_id'] === null && $existing[$key]['review_reason'] !== 'reference_client') {
                    // Product-specific desired state takes over from panel-wide sync for this client.
                    $pdo->prepare("UPDATE xui_sync_service SET id_invoice = ?, state = 'unclassified', managed_ids = '[]', synced_version = 0, attempts = 0, next_attempt_at = 0, last_error = NULL, ownership_source = 'product_discovered', review_reason = NULL, product_id = ?, intent_ids = '[]', updated_at = ? WHERE svc_key = ?")
                        ->execute(array($row['id_invoice'], (int) $productId, $now, $key));
                    $existing[$key]['product_id'] = $productId;
                } elseif ($existing[$key]['product_id'] !== null && (int) $existing[$key]['product_id'] === (int) $productId) {
                    if ((string) ($existing[$key]['id_invoice'] ?? '') === '') {
                        $pdo->prepare('UPDATE xui_sync_service SET id_invoice = ?, updated_at = ? WHERE svc_key = ?')->execute(array($row['id_invoice'], $now, $key));
                        $existing[$key]['id_invoice'] = $row['id_invoice'];
                    }
                } elseif ($existing[$key]['product_id'] !== null) {
                    $pdo->prepare("UPDATE xui_sync_service SET state = 'review', review_reason = 'duplicate_invoices_ambiguous', updated_at = ? WHERE svc_key = ?")->execute(array($now, $key));
                }
            }
        }
    }
    return $created;
}

// First contact with an existing client: decide which inbounds we may later detach.
// Ownership is only inferred; unless the client holds every baseline inbound we never detach.
function xpi_classify($pdo, $panel, $svc, $ctx)
{
    $read = xsync_fetch_client_retry($panel, $svc['email']);
    if (!$read['ok']) {
        return xsync_fail_service($pdo, $svc, 'classification_read_' . $read['kind']);
    }
    $baseline = xsync_decode_ids($ctx['state']['baseline_ids'] ?? '[]');
    $confident = !empty($baseline) && empty(array_diff($baseline, $read['ids']));
    xsync_update_service($pdo, $svc['svc_key'], array(
        'state' => 'pending',
        'managed_ids' => json_encode($confident ? $baseline : array()),
        'ownership_source' => $confident ? 'adopt_baseline' : 'adopt_ambiguous',
        'review_reason' => null, 'attempts' => 0, 'next_attempt_at' => 0, 'last_error' => null,
    ));
    return 'classified';
}

function xpi_process_service($pdo, $panel, $svc)
{
    $ctx = xpi_revalidate($pdo, $svc);
    if (!$ctx['ok'] && $ctx['state'] === 'retry') {
        xsync_update_service($pdo, $svc['svc_key'], array('next_attempt_at' => time() + 120));
        return 'superseded';
    }
    if (!$ctx['ok']) {
        return xpi_mark($pdo, $svc, $ctx['state'], $ctx['reason']);
    }
    if ($svc['state'] === 'unclassified') {
        if (xpi_classify($pdo, $panel, $svc, $ctx) === 'failed') {
            return 'failed';
        }
        $fresh = $pdo->prepare('SELECT * FROM xui_sync_service WHERE svc_key = ?');
        $fresh->execute(array($svc['svc_key']));
        $svc = $fresh->fetch(PDO::FETCH_ASSOC);
    }
    $productId = (int) $ctx['product']['id'];
    $panelId = (int) $ctx['panel']['id'];
    $ambiguous = $svc['ownership_source'] === 'adopt_ambiguous';
    $desired = array(
        'version' => (int) $ctx['state']['version'],
        'ids' => $ctx['ids'],
        'allow_detach' => !$ambiguous,
        // Re-read on every step: a changed or paused mapping aborts the run without further panel writes.
        'current' => function () use ($pdo, $productId, $panelId) {
            $state = xpi_get_state($pdo, $productId, $panelId);
            $ids = xpi_get_mapping($pdo, $productId, $panelId);
            if (!is_array($state) || (int) $state['sync_paused'] === 1 || empty($ids)) {
                return null;
            }
            return array('version' => (int) $state['version'], 'ids' => $ids);
        },
    );
    $outcome = xsync_reconcile_service($pdo, $panel, $svc, $desired);
    if ($outcome === 'synced' && $ambiguous) {
        $read = xsync_fetch_client_retry($panel, $svc['email']);
        $baseline = xsync_decode_ids($ctx['state']['baseline_ids'] ?? '[]');
        $candidates = $read['ok'] ? array_values(array_intersect($read['ids'], array_diff($baseline, $ctx['ids']))) : array();
        if ($candidates) {
            xpi_mark($pdo, $svc, 'review', 'ownership_ambiguous', 'not detached, ownership unclear: ' . implode(',', $candidates));
            return 'review';
        }
    }
    return $outcome;
}

// Legacy (cookie) panels cannot attach/detach: report drift only, never touch the panel.
function xpi_legacy_scan($pdo, array $panel, array $stateRows)
{
    $due = false;
    foreach ($stateRows as $row) {
        if ((int) $row['last_scan_at'] < time() - XPI_LEGACY_SCAN_INTERVAL) {
            $due = true;
        }
    }
    if (!$due) {
        return 0;
    }
    $pdo->prepare('UPDATE product_panel_inbound_state SET last_scan_at = ? WHERE panel_id = ?')->execute(array(time(), (int) $panel['id']));
    $live = xpi_fetch_clients_by_inbound($panel);
    if ($live === null) {
        return 0;
    }
    $flagged = 0;
    foreach ($stateRows as $row) {
        $ids = xpi_get_mapping($pdo, (int) $row['product_id'], (int) $panel['id']);
        if (count($ids) !== 1) {
            continue;
        }
        $target = $ids[0];
        $svcs = $pdo->prepare("SELECT * FROM xui_sync_service WHERE panel_name = ? AND product_id = ? AND state NOT IN ('excluded')");
        $svcs->execute(array($panel['name_panel'], (int) $row['product_id']));
        foreach ($svcs->fetchAll(PDO::FETCH_ASSOC) as $svc) {
            $actual = $live[strtolower($svc['email'])] ?? null;
            if ($actual === null) {
                continue;
            }
            if ($actual === $target) {
                xsync_update_service($pdo, $svc['svc_key'], array('state' => 'synced', 'synced_version' => (int) $row['version'], 'review_reason' => null, 'last_error' => null));
            } else {
                xsync_update_service($pdo, $svc['svc_key'], array('state' => 'review', 'review_reason' => 'legacy_mode_manual_migration', 'last_error' => "on inbound {$actual}, product wants {$target}; legacy mode cannot migrate automatically (switch the panel to token mode)"));
                $flagged++;
            }
        }
    }
    return $flagged;
}

// email(lowercase) => inbound id, from one inbounds/list call. Returns null when the panel cannot be read.
function xpi_fetch_clients_by_inbound(array $panel)
{
    $login = login($panel['code_panel']);
    if (is_array($login) && array_key_exists('success', $login) && $login['success'] === false) {
        @unlink(xuisingle_cookie_path());
        return null;
    }
    $resp = panel_get_inbounds_list($panel['url_panel']);
    @unlink(xuisingle_cookie_path());
    $decoded = json_decode((string) (is_array($resp) ? ($resp['body'] ?? '') : ''), true);
    if (!is_array($decoded) || empty($decoded['success']) || !is_array($decoded['obj'] ?? null)) {
        return null;
    }
    $map = array();
    foreach ($decoded['obj'] as $inbound) {
        $settings = is_string($inbound['settings'] ?? null) ? json_decode($inbound['settings'], true) : ($inbound['settings'] ?? array());
        foreach ((is_array($settings) ? ($settings['clients'] ?? array()) : array()) as $client) {
            if (!empty($client['email'])) {
                $map[strtolower((string) $client['email'])] = (int) $inbound['id'];
            }
        }
    }
    return $map;
}

function xpi_mapped_panel_names($pdo)
{
    try {
        $stmt = $pdo->query("SELECT DISTINCT p.name_panel FROM product_panel_inbound_state s JOIN marzban_panel p ON p.id = s.panel_id WHERE p.type = 'x-ui_single'");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        return array();
    }
}

// Called by xsync_run_panel while it holds the panel lock, so only one path touches a panel at a time.
function xpi_run_panel_products($pdo, array $panel)
{
    $summary = array('synced' => 0, 'failed' => 0, 'review' => 0, 'excluded' => 0, 'superseded' => 0, 'classified' => 0);
    try {
        $stmt = $pdo->prepare('SELECT * FROM product_panel_inbound_state WHERE panel_id = ?');
        $stmt->execute(array((int) $panel['id']));
        $stateRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return $summary; // tables not created yet: nothing product-managed to do
    }
    if (!$stateRows) {
        return $summary;
    }
    xpi_discover($pdo, $panel);
    if (!xui_panel_uses_token($panel)) {
        $summary['review'] = xpi_legacy_scan($pdo, $panel, $stateRows);
        return $summary;
    }
    $sel = $pdo->prepare("SELECT s.* FROM xui_sync_service s JOIN product_panel_inbound_state p ON p.product_id = s.product_id AND p.panel_id = ? WHERE s.panel_name = ? AND s.product_id IS NOT NULL AND s.next_attempt_at <= ? AND p.sync_paused = 0 AND (s.state IN ('unclassified','pending','error') OR (s.state = 'synced' AND s.synced_version < p.version)) ORDER BY s.next_attempt_at ASC, s.updated_at ASC LIMIT " . (int) XSYNC_BATCH_SIZE);
    $sel->execute(array((int) $panel['id'], $panel['name_panel'], time()));
    foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $svc) {
        if (!xsync_time_left()) {
            break;
        }
        try {
            $outcome = xpi_process_service($pdo, $panel, $svc);
        } catch (\Throwable $e) {
            $outcome = xsync_fail_service($pdo, $svc, 'exception: ' . $e->getMessage());
        }
        if (isset($summary[$outcome])) {
            $summary[$outcome]++;
        }
    }
    return $summary;
}

// Read-only status for the admin UI: counts per state, stale services, recent errors.
function xpi_status($pdo, $productId, $panelId, $panelName)
{
    $out = array('counts' => array(), 'stale' => 0, 'errors' => array(), 'review' => array());
    $state = xpi_get_state($pdo, $productId, $panelId);
    if (!is_array($state)) {
        return $out;
    }
    $c = $pdo->prepare('SELECT state, COUNT(*) AS c FROM xui_sync_service WHERE panel_name = ? AND product_id = ? GROUP BY state');
    $c->execute(array($panelName, (int) $productId));
    foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out['counts'][$row['state']] = (int) $row['c'];
    }
    $s = $pdo->prepare("SELECT COUNT(*) FROM xui_sync_service WHERE panel_name = ? AND product_id = ? AND state IN ('unclassified','pending','error','synced') AND (state <> 'synced' OR synced_version < ?)");
    $s->execute(array($panelName, (int) $productId, (int) $state['version']));
    $out['stale'] = (int) $s->fetchColumn();
    $e = $pdo->prepare("SELECT email, state, last_error, review_reason FROM xui_sync_service WHERE panel_name = ? AND product_id = ? AND state IN ('error','review') ORDER BY updated_at DESC LIMIT 5");
    $e->execute(array($panelName, (int) $productId));
    $out['errors'] = $e->fetchAll(PDO::FETCH_ASSOC);
    $out['state'] = $state;
    return $out;
}
