<?php
/**
 * submit.php — receives metric POSTs from monitor clients (Mac or Linux).
 * POST body: JSON {
 *   token, host, ts, cpu, gpu, ram_percent,
 *   ram_used_gb?, ram_total_gb?,
 *   ollama?, shelly_power?,
 *   tokens_per_second?, lm_studio_tps?,
 *   server_id?   // 'mac' | 'evo-x3'
 * }
 *
 * Backward compatible: if server_id is absent, the server is resolved
 * from the host name via SERVERS registry in config.php.
 */
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'POST required']);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    json_response(400, ['error' => 'invalid JSON']);
}

// auth
if (!hash_equals(SECRET_TOKEN, (string)($data['token'] ?? ''))) {
    json_response(401, ['error' => 'bad token']);
}

// required fields
$required = ['host', 'ts', 'cpu', 'gpu', 'ram_percent'];
foreach ($required as $f) {
    if (!array_key_exists($f, $data)) {
        json_response(400, ['error' => "missing field: $f"]);
    }
}

$host = trim((string)$data['host']);
if (ALLOWED_HOSTS !== null && !in_array($host, ALLOWED_HOSTS, true)) {
    json_response(403, ['error' => 'host not allowed']);
}

// Resolve server_id (falls back to host-name mapping or 'mac')
$serverId = resolve_server_id(
    isset($data['server_id']) ? trim((string)$data['server_id']) : null,
    $host
);

$ts  = (int)$data['ts'];
// Sanity: reject timestamps too far in the future or before 2020.
if ($ts < 1577836800 || $ts > time() + 3600) {
    json_response(400, ['error' => 'ts out of range']);
}

$cpu = max(-1.0, min(100.0, (float)$data['cpu']));
$gpu = max(-1.0, min(100.0, (float)$data['gpu']));
$ram = max(0.0,  min(100.0, (float)$data['ram_percent']));
$ramUsed  = isset($data['ram_used_gb'])  ? (float)$data['ram_used_gb']  : null;
$ramTotal = isset($data['ram_total_gb']) ? (float)$data['ram_total_gb'] : null;
$vramUsed  = isset($data['vram_used_gb'])  ? (float)$data['vram_used_gb']  : null;
$vramTotal = isset($data['vram_total_gb']) ? (float)$data['vram_total_gb'] : null;
$ollama          = isset($data['ollama'])         ? json_encode($data['ollama'])  : null;
$shellyPower    = isset($data['shelly_power'])     ? max(0.0, (float)$data['shelly_power']) : null;

// eGPU (NVIDIA RTX 4070 SUPER via nvidia-smi) + Gaming-PC-Strom (Home Assistant)
$egpuPercent    = isset($data['egpu_percent'])      && is_numeric($data['egpu_percent'])      ? max(0.0, min(100.0, (float)$data['egpu_percent'])) : null;
$egpuVramUsed   = isset($data['egpu_vram_used_mb']) && is_numeric($data['egpu_vram_used_mb']) ? max(0.0, (float)$data['egpu_vram_used_mb']) : null;
$egpuVramTotal  = isset($data['egpu_vram_total_mb'])&& is_numeric($data['egpu_vram_total_mb'])? max(0.0, (float)$data['egpu_vram_total_mb']) : null;
$egpuWatt       = isset($data['egpu_watt'])         && is_numeric($data['egpu_watt'])         ? max(0.0, (float)$data['egpu_watt']) : null;
$gamingPcWatt   = isset($data['gaming_pc_watt'])    && is_numeric($data['gaming_pc_watt'])    ? max(0.0, (float)$data['gaming_pc_watt']) : null;

// NVIDIA llama-server KV/Slot-KPIs (llama-nvidia.service :8750, /metrics)
$nvidiaAlive   = isset($data['nvidia_alive'])        && is_numeric($data['nvidia_alive'])        ? (int)$data['nvidia_alive'] : null;
$nvidiaKvPct   = isset($data['nvidia_kv_pct'])       && is_numeric($data['nvidia_kv_pct'])       ? max(0.0, min(100.0, (float)$data['nvidia_kv_pct'])) : null;
$nvidiaKvTok   = isset($data['nvidia_kv_tokens'])    && is_numeric($data['nvidia_kv_tokens'])    ? (int)$data['nvidia_kv_tokens'] : null;
$nvidiaDecode  = isset($data['nvidia_decode_tps'])   && is_numeric($data['nvidia_decode_tps'])   ? (float)$data['nvidia_decode_tps'] : null;
$nvidiaPrompt  = isset($data['nvidia_prompt_tps'])   && is_numeric($data['nvidia_prompt_tps'])   ? (float)$data['nvidia_prompt_tps'] : null;
$nvidiaBusy    = isset($data['nvidia_requests_busy'])&& is_numeric($data['nvidia_requests_busy'])? (int)$data['nvidia_requests_busy'] : null;

// TPS: einzelne Float-Werte pro Messpunkt (wie CPU/GPU/RAM)
$ollamaTps     = isset($data['tokens_per_second']) && is_numeric($data['tokens_per_second']) ? (float)$data['tokens_per_second'] : null;
$lmStudioTps   = isset($data['lm_studio_tps'])     && is_numeric($data['lm_studio_tps'])     ? (float)$data['lm_studio_tps']     : null;

// Gehirn-CT (07.11.): Engine-Lebenszeichen als JSON (Nodes + Flow)
$brain          = isset($data['brain']) ? json_encode($data['brain']) : null;

// Halogen KV-Pool (optional)
$kvPoolUsed  = isset($data['halogen_kv_pool_used'])  && is_numeric($data['halogen_kv_pool_used'])  ? (int)$data['halogen_kv_pool_used']  : null;
$kvPoolTotal = isset($data['halogen_kv_pool_total']) && is_numeric($data['halogen_kv_pool_total']) ? (int)$data['halogen_kv_pool_total'] : null;
$kvPoolPct   = isset($data['halogen_kv_pool_pct'])   && is_numeric($data['halogen_kv_pool_pct'])   ? (float)$data['halogen_kv_pool_pct'] : null;
$halogenTps   = isset($data['halogen_tps'])         && is_numeric($data['halogen_tps'])         ? (float)$data['halogen_tps']         : null;
// Halogen Prefill/Decode-Split (Prompt-Verarbeitung vs. Generierung)
$halogenPrefill = isset($data['halogen_prefill_tps']) && is_numeric($data['halogen_prefill_tps']) ? (float)$data['halogen_prefill_tps'] : null;
$halogenDecode  = isset($data['halogen_decode_tps'])  && is_numeric($data['halogen_decode_tps'])  ? (float)$data['halogen_decode_tps']  : null;
// Halogen kumulierter Request-Counter (alle Caller: Jarvis, voice-fast, subagents, extern)
$halogenReqTotal = isset($data['halogen_requests_total']) && is_numeric($data['halogen_requests_total']) ? (int)$data['halogen_requests_total'] : null;

try {
    $pdo = db();

    $stmt = $pdo->prepare("
        INSERT INTO metrics (ts, host, server_id, cpu, gpu, gpu_temp, tokens_per_second, ram_percent, ram_used_gb, ram_total_gb, vram_used_gb, vram_total_gb, ollama, shelly_power, ollama_tps, lm_studio_tps, halogen_kv_pool_used, halogen_kv_pool_total, halogen_kv_pool_pct, halogen_tps, halogen_prefill_tps, halogen_decode_tps, halogen_requests_total, brain, egpu_percent, egpu_vram_used_mb, egpu_vram_total_mb, egpu_watt, gaming_pc_watt, nvidia_alive, nvidia_kv_pct, nvidia_kv_tokens, nvidia_decode_tps, nvidia_prompt_tps, nvidia_requests_busy)
        VALUES (:ts, :host, :server_id, :cpu, :gpu, :gpu_temp, :tokens_per_second, :ram, :used, :total, :vram_used, :vram_total, :ollama, :shelly_power, :ollama_tps, :lm_studio_tps, :kv_used, :kv_total, :kv_pct, :halogen_tps, :halogen_prefill, :halogen_decode, :halogen_requests_total, :brain, :egpu_percent, :egpu_vram_used_mb, :egpu_vram_total_mb, :egpu_watt, :gaming_pc_watt, :nvidia_alive, :nvidia_kv_pct, :nvidia_kv_tokens, :nvidia_decode_tps, :nvidia_prompt_tps, :nvidia_requests_busy)
    ");
    $stmt->execute([
        ':ts'           => $ts,
        ':host'         => $host,
        ':server_id'    => $serverId,
        ':cpu'          => $cpu,
        ':gpu'          => $gpu,
        ':gpu_temp'     => isset($data['gpu_temp']) ? (int)$data['gpu_temp'] : null,
        ':tokens_per_second' => $ollamaTps,
        ':ram'          => $ram,
        ':used'         => $ramUsed,
        ':total'        => $ramTotal,
        ':vram_used'    => $vramUsed,
        ':vram_total'   => $vramTotal,
        ':ollama'       => $ollama,
        ':shelly_power' => $shellyPower,
        ':ollama_tps'   => $ollamaTps,
        ':lm_studio_tps' => $lmStudioTps,
        ':kv_used'      => $kvPoolUsed,
        ':kv_total'     => $kvPoolTotal,
        ':kv_pct'       => $kvPoolPct,
        ':halogen_tps' => $halogenTps,
        ':halogen_prefill' => $halogenPrefill,
        ':halogen_decode'  => $halogenDecode,
        ':halogen_requests_total' => $halogenReqTotal,
        ':brain'        => $brain,
        ':egpu_percent'      => $egpuPercent,
        ':egpu_vram_used_mb' => $egpuVramUsed,
        ':egpu_vram_total_mb'=> $egpuVramTotal,
        ':egpu_watt'         => $egpuWatt,
        ':gaming_pc_watt'    => $gamingPcWatt,
        ':nvidia_alive'        => $nvidiaAlive,
        ':nvidia_kv_pct'       => $nvidiaKvPct,
        ':nvidia_kv_tokens'    => $nvidiaKvTok,
        ':nvidia_decode_tps'   => $nvidiaDecode,
        ':nvidia_prompt_tps'   => $nvidiaPrompt,
        ':nvidia_requests_busy'=> $nvidiaBusy,
    ]);

    // prune anything older than retention window
    $cutoff = time() - (RETENTION_HOURS * 3600);
    $pdo->prepare('DELETE FROM metrics WHERE ts < :c')
        ->execute([':c' => $cutoff]);
    $pdo->prepare('DELETE FROM requests WHERE ts < :c')
        ->execute([':c' => $cutoff]);

    json_response(200, ['ok' => true, 'server_id' => $serverId]);
} catch (Throwable $e) {
    json_response(500, ['error' => 'db error', 'detail' => $e->getMessage()]);
}
