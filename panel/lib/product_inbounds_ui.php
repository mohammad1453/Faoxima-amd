<?php
// Admin-panel rendering helpers for the per-panel product inbound mapping.

if (!function_exists('fx_pi_meta')) {
    // name => id/type/token flag only. Credentials never reach the browser.
    function fx_pi_meta(array $panels): array
    {
        $meta = [];
        foreach ($panels as $row) {
            $meta[(string) $row['name_panel']] = [
                'id' => (int) $row['id'],
                'type' => (string) $row['type'],
                'token' => ($row['type'] ?? '') === 'x-ui_single' ? xui_panel_uses_token($row) : false,
            ];
        }
        return $meta;
    }
}

if (!function_exists('fx_pi_h')) {
    function fx_pi_h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('fx_pi_hidden_fields')) {
    // Re-emits a (nested) POST array as hidden inputs for the confirmation step.
    function fx_pi_hidden_fields(array $data, string $prefix = ''): string
    {
        $html = '';
        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
            if (is_array($value)) {
                $html .= fx_pi_hidden_fields($value, $name);
            } else {
                $html .= '<input type="hidden" name="' . fx_pi_h($name) . '" value="' . fx_pi_h($value) . '">';
            }
        }
        return $html;
    }
}

if (!function_exists('fx_pi_confirm_html')) {
    function fx_pi_confirm_html(array $plan, array $post, string $action): string
    {
        $out = '<div class="alert alert-error"><span><strong>تأیید لازم است:</strong> این تغییر علاوه بر خریدهای جدید، روی مشتریان فعال فعلی این محصول هم اعمال می‌شود. مشتریان به‌صورت دسته‌ای و در پس‌زمینه همگام می‌شوند؛ ابتدا اینباند جدید اضافه و بررسی می‌شود و بعد اینباند قدیمی حذف می‌گردد.</span></div>';
        foreach ($plan['panels'] as $p) {
            if ($p['action'] === 'keep' && empty($p['restore'])) {
                continue;
            }
            $out .= '<div class="form-group" style="border:1px solid var(--border,#ddd);border-radius:8px;padding:10px;margin:8px 0;">';
            $out .= '<div style="font-weight:600;">پنل «' . fx_pi_h($p['name']) . '» — حدود ' . (int) $p['services'] . ' سرویس بررسی خواهد شد (فقط سرویس‌هایی که با تنظیمات جدید تفاوت دارند تغییر می‌کنند)</div>';
            if ($p['action'] === 'remove') {
                $out .= '<div>نگاشت صریح حذف می‌شود. خریدهای بعدی به منطق قدیمی برمی‌گردند و مشتریان فعلی <strong>به‌صورت خودکار به اینباند قدیمی برنمی‌گردند</strong> و همگام‌سازی آن‌ها متوقف می‌شود.</div>';
            } else {
                $out .= '<div>اینباندهای جدید: ' . fx_pi_h(implode(', ', $p['new']) ?: '—') . ' | افزوده: ' . fx_pi_h(implode(', ', $p['added']) ?: '—') . ' | کنار گذاشته: ' . fx_pi_h(implode(', ', $p['removed']) ?: '—') . '</div>';
                if ($p['baseline'] !== null) {
                    $out .= '<div>برای نخستین نگاشت، اینباندهای قبلی محصول (<em>برآورد، نه قطعی</em>): ' . fx_pi_h(implode(', ', $p['baseline']) ?: '—') . '. اینباندهایی که ممکن است از مشتریان حذف شوند: ' . fx_pi_h(implode(', ', $p['baseline_removal']) ?: '—') . '. حذف فقط برای مشتریانی انجام می‌شود که همه اینباندهای قبلی را دارند؛ بقیه برای بررسی دستی علامت می‌خورند و اینباندی از آن‌ها حذف نمی‌شود. اینباندهای دستی خارج از این فهرست دست‌نخورده می‌مانند.</div>';
                }
                if (!$p['token']) {
                    $out .= '<div style="color:#b9770e;">این پنل با ورود قدیمی (کوکی) کار می‌کند: مشتریان فعلی جابه‌جا نمی‌شوند و فقط برای بررسی دستی گزارش می‌شوند. برای همگام‌سازی خودکار، پنل را به حالت توکن ببرید.</div>';
                }
            }
            $out .= '</div>';
        }
        if ($plan['delete_panels']) {
            $out .= '<div class="form-group">نگاشت پنل‌هایی که از محصول حذف شده‌اند پاک می‌شود؛ مشتریان فعلی آن‌ها دست‌نخورده می‌مانند.</div>';
        }
        foreach ($plan['warnings'] as $w) {
            $out .= '<div style="color:#b9770e;">' . fx_pi_h($w) . '</div>';
        }
        $out .= '<form action="' . fx_pi_h($action) . '" method="POST">' . fx_csrf_field() . fx_pi_hidden_fields($post)
            . '<input type="hidden" name="confirm_mapping" value="1"><input type="hidden" name="confirm_hash" value="' . fx_pi_h($plan['hash']) . '">'
            . '<button type="submit" class="btn btn-primary">تأیید و ذخیره</button> '
            . '<a class="btn btn-soft-info" href="' . fx_pi_h($action) . '">انصراف</a></form>';
        return $out;
    }
}

if (!function_exists('fx_pi_status_html')) {
    // Per-panel mapping status. Pausing only stops syncing existing customers; new purchases keep using the mapping.
    function fx_pi_status_html(PDO $pdo, array $product, array $panels, string $baseUrl): string
    {
        $maps = xpi_get_mappings_for_product($pdo, (int) $product['id']);
        if (!$maps) {
            return '';
        }
        $html = '<div class="card" style="margin-top:16px;"><div class="page-head__title" style="font-size:16px;">وضعیت همگام‌سازی اینباند مشتریان فعلی</div>';
        if (xpi_is_all($product)) {
            $html .= '<div class="alert">این محصول روی «تمامی پنل‌ها» است؛ نگاشت‌های ذخیره‌شده نگه داشته شده‌اند ولی استفاده یا همگام‌سازی نمی‌شوند.</div>';
        }
        foreach ($panels as $panel) {
            $pid = (int) $panel['id'];
            if (empty($maps[$pid])) {
                continue;
            }
            $status = xpi_status($pdo, (int) $product['id'], $pid, $panel['name_panel']);
            $state = $status['state'] ?? null;
            $paused = is_array($state) && (int) $state['sync_paused'] === 1;
            $counts = [];
            foreach ($status['counts'] as $k => $v) {
                $counts[] = fx_pi_h($k) . ': ' . (int) $v;
            }
            $html .= '<div style="border-top:1px solid var(--border,#ddd);padding-top:8px;margin-top:8px;">';
            $html .= '<div style="font-weight:600;">پنل «' . fx_pi_h($panel['name_panel']) . '» — اینباندها: ' . fx_pi_h(implode(', ', $maps[$pid])) . ' — نسخه تنظیمات: ' . (int) ($state['version'] ?? 0) . '</div>';
            $html .= '<div>سرویس‌ها: ' . ($counts ? implode(' | ', $counts) : 'هنوز بررسی نشده') . ' | در انتظار همگام‌سازی: ' . (int) $status['stale'] . '</div>';
            if (!xui_panel_uses_token($panel)) {
                $html .= '<div style="color:#b9770e;">حالت ورود قدیمی: فقط گزارش اختلاف (بدون جابه‌جایی خودکار). برای همگام‌سازی خودکار پنل را به حالت توکن ببرید.</div>';
            }
            $html .= $paused
                ? '<div style="color:#c0392b;"><strong>همگام‌سازی مشتریان فعلی متوقف است.</strong> خریدهای جدید همچنان از نگاشت ذخیره‌شده استفاده می‌کنند.</div>'
                : '<div style="color:var(--text-muted);">همگام‌سازی مشتریان فعلی فعال است.</div>';
            foreach ($status['errors'] as $err) {
                $html .= '<div style="font-size:12px;color:#c0392b;">' . fx_pi_h($err['email']) . ' — ' . fx_pi_h($err['state']) . ': ' . fx_pi_h($err['review_reason'] ?: $err['last_error']) . ($err['review_reason'] && $err['last_error'] ? ' (' . fx_pi_h($err['last_error']) . ')' : '') . '</div>';
            }
            $html .= '<form method="POST" action="' . fx_pi_h($baseUrl) . '&action=pi_pause" style="display:inline;">' . fx_csrf_field()
                . '<input type="hidden" name="panel_id" value="' . $pid . '"><input type="hidden" name="paused" value="' . ($paused ? 0 : 1) . '">'
                . '<button type="submit" class="btn btn-sm btn-soft-info">' . ($paused ? 'ادامه همگام‌سازی مشتریان فعلی' : 'توقف همگام‌سازی مشتریان فعلی (نگاشت خریدهای جدید تغییر نمی‌کند)') . '</button></form> ';
            if (!empty($status['counts']['review'])) {
                $html .= '<form method="POST" action="' . fx_pi_h($baseUrl) . '&action=pi_requeue" style="display:inline;">' . fx_csrf_field()
                    . '<input type="hidden" name="panel_id" value="' . $pid . '"><button type="submit" class="btn btn-sm btn-soft-info">بررسی مجدد سرویس‌های «نیازمند بررسی»</button></form>';
            }
            $html .= '</div>';
        }
        return $html . '</div>';
    }
}
