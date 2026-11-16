<?php
/**
 * Fleet-API für fleet.php ("Engine Room").
 * Liefert_latest + 24h-Historie beider Engines (Halogen iGPU/NPU + NVIDIA eGPU).
 * Datenvertrag exakt wie im dsh-Auftrag dokumentiert.
 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/mac-monitor-config.php';

try {
    $pdo = db();

    $latest = $pdo->query("SELECT * FROM metrics ORDER BY ts DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    // 24h-Historie, runtergebrochen auf ~120 Punkte (Ø je Chunk)
    $since = time() - 24 * 3600;
    $rows = $pdo->prepare("SELECT ts, halogen_kv_pool_pct, halogen_decode_tps, nvidia_kv_pct, nvidia_decode_tps, egpu_watt, gaming_pc_watt, egpu_percent FROM metrics WHERE ts >= ? ORDER BY ts ASC");
    $rows->execute([$since]);
    $all = $rows->fetchAll(PDO::FETCH_ASSOC);
    $step = max(1, intdiv(max(1, count($all)), 120));
    $history = [];
    for ($i = 0; $i < count($all); $i += $step) {
        $r = $all[$i];
        $history[] = [
            'ts'          => (int)$r['ts'],
            'halogen_kv'  => $r['halogen_kv_pool_pct'] !== null ? (float)$r['halogen_kv_pool_pct'] : null,
            'halogen_tps' => $r['halogen_decode_tps'] !== null ? (float)$r['halogen_decode_tps'] : null,
            'nvidia_kv'   => $r['nvidia_kv_pct'] !== null ? (float)$r['nvidia_kv_pct'] : null,
            'nvidia_tps'  => $r['nvidia_decode_tps'] !== null ? (float)$r['nvidia_decode_tps'] : null,
            'nvidia_watt' => $r['egpu_watt'] !== null ? (float)$r['egpu_watt'] : null,
            'gaming_watt' => $r['gaming_pc_watt'] !== null ? (float)$r['gaming_pc_watt'] : null,
        ];
    }

    // Autonomie-Schalter: autonomy_settings liegt in derselben DB (autonomy-api.php)
    $autonomy = null;
    try {
        $en = $pdo->query("SELECT v FROM autonomy_settings WHERE k = 'enabled'")->fetchColumn();
        // Achtung: '0' ist in PHP falsy — explizit vergleichen (Cockpit-Bug-Historie)
        $autonomy = ($en === false || $en === null) ? null : ($en === '1');
    } catch (Throwable $e) { $autonomy = null; }

    $num = function ($v) { return $v === null ? null : (float)$v; };

    echo json_encode([
        'ok' => true,
        'ts' => time(),
        'halogen' => [
            'alive'      => (bool)($latest && ($latest['halogen_decode_tps'] !== null || $latest['halogen_kv_pool_pct'] !== null)),
            'model'      => 'halogen-qwen3.8-flash-next',
            'decode_tps' => $num($latest['halogen_decode_tps'] ?? null),
            'prefill_tps'=> $num($latest['halogen_prefill_tps'] ?? null),
            'kv_pct'     => $num($latest['halogen_kv_pool_pct'] ?? null),
            'kv_used'    => isset($latest['halogen_kv_pool_used']) ? (int)$latest['halogen_kv_pool_used'] : null,
            'kv_total'   => isset($latest['halogen_kv_pool_total']) ? (int)$latest['halogen_kv_pool_total'] : null,
            'ram_pct'    => $num($latest['ram_percent'] ?? null),
            'ctx'        => 196608,
            'requests'   => isset($latest['halogen_requests_total']) ? (int)$latest['halogen_requests_total'] : null,
        ],
        'nvidia' => [
            'alive'      => (bool)($latest && (int)($latest['nvidia_alive'] ?? 0) === 1),
            'model'      => 'qwen3.8-27b-nvidia',
            'decode_tps' => $num($latest['nvidia_decode_tps'] ?? null),
            'prompt_tps' => $num($latest['nvidia_prompt_tps'] ?? null),
            'kv_pct'     => $num($latest['nvidia_kv_pct'] ?? null),
            'kv_tokens'  => isset($latest['nvidia_kv_tokens']) ? (int)$latest['nvidia_kv_tokens'] : null,
            'vram_used_mb'  => isset($latest['egpu_vram_used_mb']) ? (float)$latest['egpu_vram_used_mb'] : null,
            'vram_total_mb' => isset($latest['egpu_vram_total_mb']) ? (float)$latest['egpu_vram_total_mb'] : null,
            'watt'       => $num($latest['egpu_watt'] ?? null),
            'gpu_pct'    => $num($latest['egpu_percent'] ?? null),
            'ctx'        => 8192,
            'slots_busy' => isset($latest['nvidia_requests_busy']) ? (int)$latest['nvidia_requests_busy'] : null,
        ],
        'gaming_pc_watt' => $num($latest['gaming_pc_watt'] ?? null),
        'autonomy' => $autonomy,
        'history'  => $history,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db error']);
}
