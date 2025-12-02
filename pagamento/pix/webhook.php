<?php

// Debug de erros em arquivo separado
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');

// Lê o corpo do webhook
$rawBody = file_get_contents('php://input');

// Log início + RAW
file_put_contents('webhook.log', date('[d/m/Y H:i:s] ') . "== INICIO WEBHOOK ==\n", FILE_APPEND);
file_put_contents('webhook.log', date('[d/m/Y H:i:s] ') . "RAW: " . $rawBody . PHP_EOL, FILE_APPEND);

$data = json_decode($rawBody, true);

// Validação básica do JSON
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['erro' => 'JSON inválido']);
    file_put_contents('webhook.log', date('[d/m/Y H:i:s] ') . "ERRO: JSON inválido\n", FILE_APPEND);
    exit;
}

/**
 * Formato vindo da SkalePay (igual ao que aparece no webhook.log):
 * {
 *   "id": 237176781,
 *   "type": "transaction",
 *   "objectId": "122747970",
 *   "url": ".../webhook.php",
 *   "data": {
 *       "id": 122747970,
 *       "status": "paid",
 *       "amount": 500,
 *       "paidAmount": 500,
 *       "customer": {...},
 *       "metadata": {...}
 *   }
 * }
 */

// Transação real vem em "data"
$tx = $data['data'] ?? [];

// ID da transação (mesmo usado no gerar.php como orderId)
$transactionId = $tx['id'] ?? null;
// Status do pagamento
$status        = $tx['status'] ?? null;

file_put_contents(
    'webhook.log',
    date('[d/m/Y H:i:s] ') . "PARSE: transactionId={$transactionId}, status={$status}\n",
    FILE_APPEND
);

if (!$transactionId || !$status) {
    http_response_code(400);
    echo json_encode(['erro' => 'Dados inválidos (sem id ou status)']);
    file_put_contents('webhook.log', date('[d/m/Y H:i:s] ') . "ERRO: sem id ou status\n", FILE_APPEND);
    exit;
}

// Só processa vendas pagas
if ($status !== 'paid') {
    echo json_encode(['mensagem' => "Status não pago: {$status}"]);
    file_put_contents('webhook.log', date('[d/m/Y H:i:s] ') . "IGNORADO: status={$status}\n", FILE_APPEND);
    exit;
}

// Idempotência: se já processou esse ID, sai
$flagDir = __DIR__ . "/logs";
if (!is_dir($flagDir)) {
    mkdir($flagDir, 0777, true);
}

$flag = $flagDir . "/webhook_{$transactionId}.json";
if (file_exists($flag)) {
    echo json_encode(['mensagem' => 'Já processado']);
    file_put_contents('webhook.log', date('[d/m/Y H:i:s] ') . "IGNORADO: já processado\n", FILE_APPEND);
    exit;
}

// ==========================
// UTM E METADATA
// ==========================

$metadata = $tx['metadata'] ?? [];
$utms     = $metadata['utms'] ?? [];

// Tracking parameters default
$trackingParameters = [
    'utm_source'   => $utms['utm_source']   ?? null,
    'utm_campaign' => $utms['utm_campaign'] ?? null,
    'utm_medium'   => $utms['utm_medium']   ?? null,
    'utm_content'  => $utms['utm_content']  ?? null,
    'utm_term'     => $utms['utm_term']     ?? null,
];

// Também tenta puxar o tracking_{id}.json salvo no gerar.php
$utmCache = $flagDir . "/tracking_{$transactionId}.json";
if (file_exists($utmCache)) {
    $utmData = json_decode(file_get_contents($utmCache), true);
    if (is_array($utmData)) {
        $trackingParameters = array_merge($trackingParameters, $utmData);
    }
}

// ==========================
// CLIENTE E VALOR
// ==========================

$customerData = $tx['customer'] ?? [];

$cliente = [
    'name'  => $customerData['name']  ?? 'Cliente',
    'email' => $customerData['email'] ?? 'cliente@exemplo.com',
    'phone' => $customerData['phone'] ?? '11999999999',
    'doc'   => $customerData['document']['number'] ?? ($metadata['cpf'] ?? '00000000000')
];

// Valor em centavos
if (!empty($tx['paidAmount'])) {
    $amount = intval($tx['paidAmount']);
} elseif (!empty($tx['amount'])) {
    $amount = intval($tx['amount']);
} else {
    $amount = 0;
}

$valorCentavos = $amount;

// ==========================
// MONTANDO PAYLOAD UTMIFY
// ==========================

$utmifyToken = 'jxrrdDyUvLYpmWiOXGV7D8YuK2vHj9ExfW5Y';

// tenta reaproveitar createdAt salvo no gerar.php
$trackingFile = $flagDir . "/tracking_{$transactionId}.json";
$createdAt    = gmdate('Y-m-d H:i:s'); // fallback

if (file_exists($trackingFile)) {
    $trackData = json_decode(file_get_contents($trackingFile), true) ?: [];
    if (!empty($trackData['createdAt'])) {
        $createdAt = $trackData['createdAt'];
    }
}

$approvedDate = gmdate('Y-m-d H:i:s');

$payload = [
    "orderId"       => (string)$transactionId,
    "platform"      => "SkalePay",
    "paymentMethod" => "pix",
    "status"        => "paid",
    "createdAt"     => $createdAt,
    "approvedDate"  => $approvedDate,
    "refundedAt"    => null,
    "customer" => [
        "name"     => $cliente['name'],
        "email"    => $cliente['email'],
        "phone"    => preg_replace('/\D/', '', $cliente['phone']),
        "document" => preg_replace('/\D/', '', $cliente['doc']),
        "country"  => "BR",
        "ip"       => $_SERVER['REMOTE_ADDR'] ?? null,
    ],
    "products" => [[
        "id"           => "compra-online-001",
        "name"         => "Compra Online",
        "planId"       => null,
        "planName"     => null,
        "quantity"     => 1,
        "priceInCents" => $valorCentavos,
    ]],
    "trackingParameters" => [
        "src"          => $trackingParameters['src']          ?? null,
        "sck"          => $trackingParameters['sck']          ?? null,
        "utm_source"   => $trackingParameters['utm_source']   ?? null,
        "utm_campaign" => $trackingParameters['utm_campaign'] ?? null,
        "utm_medium"   => $trackingParameters['utm_medium']   ?? null,
        "utm_content"  => $trackingParameters['utm_content']  ?? null,
        "utm_term"     => $trackingParameters['utm_term']     ?? null,
    ],
    "commission" => [
        "totalPriceInCents"     => $valorCentavos,
        "gatewayFeeInCents"     => 0,
        "userCommissionInCents" => $valorCentavos,
    ],
    "isTest" => false,
];

// Log do payload que vai pra Utmify
file_put_contents(
    'webhook.log',
    date('[d/m/Y H:i:s] ') . "UTMIFY PAYLOAD: " . json_encode($payload) . PHP_EOL,
    FILE_APPEND
);

// ==========================
// ENVIA PARA UTMIFY
// ==========================

$ch = curl_init("https://api.utmify.com.br/api-credentials/orders");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        "x-api-token: $utmifyToken"
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload)
]);

$utmifyResponse = curl_exec($ch);
$httpCodeUtmify = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

file_put_contents(
    'webhook.log',
    date('[d/m/Y H:i:s] ') . "UTMIFY HTTP: $httpCodeUtmify RESP: $utmifyResponse" . PHP_EOL,
    FILE_APPEND
);

// Marca como processado
file_put_contents($flag, json_encode(['confirmed' => true, 'id' => $transactionId]));

http_response_code(200);
echo json_encode(['status' => 'ok']);

file_put_contents('webhook.log', date('[d/m/Y H:i:s] ') . "== FIM WEBHOOK ==\n", FILE_APPEND);
exit;
