<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

// ID da transação: querystring ou sessão
$transactionId = isset($_GET['id']) ? trim($_GET['id']) : ($_SESSION['id_transacao'] ?? null);

if (!$transactionId) {
    http_response_code(400);
    echo json_encode(['erro' => 'ID da transação não informado']);
    exit;
}

// ==========================
// CONSULTA TRANSAÇÃO NA SKALEPAY
// ==========================
$skaleSecretKey = 'sk_live_v2XBQly8jgPwTuThg9WXg5t6qUbYqkTtTUBCBL8gV2';

$url = 'https://api.conta.skalepay.com.br/v1/transactions/' . urlencode($transactionId);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Basic ' . base64_encode($skaleSecretKey . ':x'),
    'Content-Type: application/json'
]);

$resposta  = curl_exec($ch);
$httpcode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Log detalhado
file_put_contents(
    __DIR__ . '/debug_status.txt',
    date('[d/m/Y H:i:s] ') .
        "ID: $transactionId | HTTP: $httpcode | CURL_ERR: $curlError | BODY: $resposta" . PHP_EOL,
    FILE_APPEND
);

if ($httpcode < 200 || $httpcode >= 300 || !$resposta) {
    http_response_code(500);
    echo json_encode([
        'erro'      => 'Falha ao consultar status no gateway',
        'httpcode'  => $httpcode,
        'curlError' => $curlError
    ]);
    exit;
}

$data = json_decode($resposta, true);
if (!is_array($data)) {
    http_response_code(500);
    echo json_encode([
        'erro' => 'Resposta inválida do gateway (não é JSON)',
        'raw'  => $resposta
    ]);
    exit;
}

$statusGateway = $data['status'] ?? null;
$idGateway     = $data['id'] ?? $transactionId;

if (!$statusGateway) {
    http_response_code(500);
    echo json_encode(['erro' => 'Resposta sem status do gateway', 'raw' => $resposta]);
    exit;
}

// ==========================
// MAPEIA STATUS PRO FRONT
// ==========================

$mappedStatus = 'pending';

switch ($statusGateway) {
    case 'paid':
    case 'partially_paid':
        $mappedStatus = 'paid';
        break;

    case 'refused':
    case 'canceled':
    case 'chargedback':
    case 'in_protest':
        $mappedStatus = 'failed';
        break;

    case 'refunded':
        $mappedStatus = 'refunded';
        break;

    default:
        $mappedStatus = 'pending';
        break;
}

// Guarda último status na sessão (se quiser usar em outro lugar)
$_SESSION['status_transacao'] = $statusGateway;

// ==========================
// RESPOSTA JSON PRO FRONT
// ==========================
echo json_encode([
    'id'        => $idGateway,
    'status'    => $mappedStatus,
    'rawStatus' => $statusGateway,
    'amount'    => $data['paidAmount'] ?? ($data['amount'] ?? null)
]);
