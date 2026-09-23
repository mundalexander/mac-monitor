<?php
// Unload Queue Writer - receives model unload requests
header('Content-Type: application/json');
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$expected = 'Bearer macmon_token_2024';
if ($token !== $expected) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
$input = json_decode(file_get_contents('php://input'), true);
$model = $input['model'] ?? '';
$backend = $input['backend'] ?? 'ollama'; // ollama, lm_studio, llama.cpp
$action = $input['action'] ?? 'unload';
if (!$model) {
    echo json_encode(['error' => 'No model']);
    exit;
}
$queue_file = __DIR__ . '/unload_queue.json';
$queue = file_exists($queue_file) ? json_decode(file_get_contents($queue_file), true) : [];
// Add new request
$queue[] = [
    'model' => $model,
    'backend' => $backend,
    'action' => $action,
    'time' => date('c'),
    'done' => false
];
file_put_contents($queue_file, json_encode($queue, JSON_PRETTY_PRINT));
echo json_encode(['ok' => true, 'queue_len' => count($queue)]);
