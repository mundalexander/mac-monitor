<?php
/**
 * capacity.php — „Welches Gehirn kann gerade arbeiten?“ (1.3)
 *
 * GET [?engine=halogen]
 *  -> {ok, server_time, engines:[{key, machine, machine_name, engine, profile, label, status,
 *        reason, endpoint, ram_free_gb, ram_need_gb, kv_pct, decode_tps, busy_until, next_slot}]}
 *
 * status: free (sofort nutzbar) · cold (startbar, Modell wird geladen) · booked (Slot läuft)
 *         · busy (arbeitet gerade) · full (zu wenig Speicher) · offline
 * Sortierung: free, cold, booked, busy, full, offline.
 *
 * Zugriff: Browser-Session oder Geräteschlüssel (Jarvis & Co.).
 * Buchen: slots.php mit machine=<machine> und model=<profile>, dann Endpoint direkt nutzen.
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
mm_require_api_access();

$pdo = db();
mm_control_table($pdo);
$now = time();
$onlyEngine = preg_replace('/[^a-z0-9_.-]/', '', strtolower((string)($_GET['engine'] ?? '')));

$latest = function (string $sid) use ($pdo): ?array {
    $st = $pdo->prepare('SELECT * FROM metrics WHERE server_id = ? ORDER BY ts DESC LIMIT 1');
    $st->execute([$sid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
};

$slots = [];
try {
    $st = $pdo->prepare('SELECT machine, model, start_unix, end_unix, owner, task FROM slots WHERE end_unix > ? AND start_unix < ? ORDER BY start_unix');
    $st->execute([$now, $now + 7 * 86400]);
    $slots = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $slots = []; }

/** Slot gehört zu Engine, wenn machine passt und model leer ist oder Profil/Engine nennt. */
$slotMatches = function (array $s, string $machine, string $engine, string $profile, string $label): bool {
    $m = strtolower((string)$s['machine']);
    if ($m === "$machine:$engine" || $m === "$machine:$profile") return true;
    if ($m !== $machine) return false;
    $model = strtolower(trim((string)$s['model']));
    if ($model === '') return true;
    foreach ([$profile, $engine, strtolower($label)] as $k) {
        if ($k !== '' && (str_contains($model, $k) || str_contains($k, $model))) return true;
    }
    return false;
};

$engines = [];
$rows = $pdo->query('SELECT server_id, ts, data FROM control_state')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $sid = (string)$row['server_id'];
    $state = json_decode((string)$row['data'], true);
    if (!is_array($state)) continue;
    $online = ($now - (int)$row['ts']) < 45;
    $lm = $latest($sid);
    $free = isset($state['ram_available_gb']) ? (float)$state['ram_available_gb'] : null;
    // Profile gruppiert nach Engine: laufendes Profil vertritt die Engine, sonst bestes startbares
    $byEngine = [];
    foreach (($state['profiles'] ?? []) as $p) $byEngine[$p['engine'] ?? 'default'][] = $p;
    foreach ($byEngine as $engine => $plist) {
        if ($onlyEngine !== '' && $engine !== $onlyEngine) continue;
        usort($plist, fn($a, $b) => ($b['running'] ?? false) <=> ($a['running'] ?? false));
        foreach ($plist as $p) {
            $running = !empty($p['running']);
            $kv = null; $dec = null; $busyReq = 0;
            if ($lm) {
                if ($engine === 'halogen') { $kv = $lm['halogen_kv_pool_pct'] ?? null; $dec = $lm['halogen_decode_tps'] ?? null; }
                elseif ($engine === 'nvidia') { $kv = $lm['nvidia_kv_pct'] ?? null; $dec = $lm['nvidia_decode_tps'] ?? null; $busyReq = (int)($lm['nvidia_requests_busy'] ?? 0); }
            }
            $need = isset($p['ram_gb']) ? (float)$p['ram_gb'] : null;
            $now_slot = null; $next_slot = null; $busy_until = null;
            foreach ($slots as $s) {
                if (!$slotMatches($s, $sid, $engine, (string)$p['id'], (string)($p['label'] ?? ''))) continue;
                if ((int)$s['start_unix'] <= $now && (int)$s['end_unix'] > $now) {
                    $now_slot = $s; $busy_until = (int)$s['end_unix'];
                } elseif ((int)$s['start_unix'] > $now && $next_slot === null) {
                    $next_slot = $s;
                }
            }
            // Slot-Kette zusammenziehen
            if ($busy_until) {
                foreach ($slots as $s) {
                    if ($slotMatches($s, $sid, $engine, (string)$p['id'], (string)($p['label'] ?? ''))
                        && (int)$s['start_unix'] <= $busy_until + 60 && (int)$s['end_unix'] > $busy_until) {
                        $busy_until = (int)$s['end_unix'];
                    }
                }
            }
            if (!$online) { $status = 'offline'; $reason = 'Gerät meldet sich nicht'; }
            elseif (!empty($state['busy'])) { $status = 'busy'; $reason = (string)($state['message'] ?? 'Steueraktion läuft'); }
            elseif ($now_slot) { $status = 'booked'; $reason = 'gebucht von ' . $now_slot['owner'] . ($now_slot['task'] ? ': ' . $now_slot['task'] : ''); }
            elseif ($running && (($dec !== null && (float)$dec > 0.5) || $busyReq > 0)) { $status = 'busy'; $reason = 'generiert gerade'; }
            elseif ($running && $kv !== null && (float)$kv >= 85) { $status = 'busy'; $reason = 'KV-Cache ' . round((float)$kv) . ' % belegt'; }
            elseif ($running) { $status = 'free'; $reason = 'geladen und im Leerlauf'; }
            elseif ($need !== null && $free !== null && $need > $free + 30) { $status = 'full'; $reason = sprintf('braucht %.0f GB, frei %.0f GB', $need, $free); }
            else { $status = 'cold'; $reason = 'startbar' . ($need ? sprintf(' (%.0f GB)', $need) : '') . ($p['load_s'] ?? null ? ', Ladezeit ~' . round((float)$p['load_s']) . ' s' : ''); }
            $engines[] = [
                'key' => "$sid:$engine:" . $p['id'], 'machine' => $sid,
                'machine_name' => SERVERS[$sid]['name'] ?? $sid, 'engine' => $engine,
                'profile' => $p['id'], 'label' => $p['label'] ?? $p['id'], 'running' => $running,
                'status' => $status, 'reason' => $reason, 'endpoint' => $p['endpoint'] ?? null,
                'ram_free_gb' => $free, 'ram_need_gb' => $need,
                'kv_pct' => $kv !== null ? round((float)$kv, 1) : null,
                'decode_tps' => $dec !== null ? round((float)$dec, 1) : null,
                'tps' => $p['tps'] ?? null, 'busy_until' => $busy_until,
                'next_slot' => $next_slot ? ['start' => (int)$next_slot['start_unix'], 'end' => (int)$next_slot['end_unix'], 'owner' => $next_slot['owner']] : null,
            ];
        }
    }
}
$rank = ['free' => 0, 'cold' => 1, 'booked' => 2, 'busy' => 3, 'full' => 4, 'offline' => 5];
usort($engines, function ($a, $b) use ($rank) {
    return [$rank[$a['status']], $a['running'] ? 0 : 1, -($a['tps'] ?? 0)] <=> [$rank[$b['status']], $b['running'] ? 0 : 1, -($b['tps'] ?? 0)];
});
$best = null;
foreach ($engines as $e) { if (in_array($e['status'], ['free', 'cold'], true)) { $best = $e; break; } }
json_response(200, ['ok' => true, 'server_time' => $now, 'recommended' => $best, 'engines' => $engines]);
