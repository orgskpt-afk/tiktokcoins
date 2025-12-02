<?php

session_start();

// Lê o corpo apenas uma vez
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true) ?: [];

// Dados vindos do front
$cpf      = preg_replace('/\D/', '', $data['cpf'] ?? '');
$valor    = intval($data['valor'] ?? 0);
$utms     = $data['utms'] ?? [];

$nome     = trim($data['nome'] ?? '') ?: 'Cliente';
$email    = trim($data['email'] ?? '') ?: 'teste@email.com';
$telefone = preg_replace('/\D/', '', $data['telefone'] ?? '') ?: '11999999999';

// Salva em sessão
$_SESSION['cpf']   = $cpf;
$_SESSION['valor'] = $valor;
$_SESSION['utms']  = $utms;

if (empty($cpf) || $valor < 1000) {
    http_response_code(400);
    echo json_encode(['erro' => 'Dados inválidos']);
    exit;
}

// ==========================
// POSTBACK (WEBHOOK)
// ==========================
// >>> AQUI é o postback correto, na MESMA pasta do gerar.php
$scheme   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
$host     = $_SERVER['HTTP_HOST'];
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); // ex: /api/pagamento/pix

$postbackUrl = $scheme . $host . $basePath . '/webhook.php';

// ==========================
// CRIA TRANSAÇÃO PIX (SkalePay)
// ==========================

$skaleSecretKey = 'sk_live_v2XBQly8jgPwTuThg9WXg5t6qUbYqkTtTUBCBL8gV2';

$payloadSkale = [
    'amount'        => $valor,          // em centavos
    'paymentMethod' => 'pix',
    'postbackUrl'   => $postbackUrl,
    'traceable'     => true,
    'customer'      => [
        'name'   => $nome,
        'email'  => $email,
        'phone'  => $telefone,
        'document' => [
            'type'   => 'cpf',
            'number' => $cpf
        ]
    ],
    'items' => [
        [
            'title'     => 'Compra Online',
            'unitPrice' => $valor,
            'quantity'  => 1,
            'tangible'  => false
        ]
    ],
    'metadata' => [
        'cpf'  => $cpf,
        'nome' => $nome,
        'utms' => $utms
    ]
];

$gatewayUrl = 'https://api.conta.skalepay.com.br/v1/transactions';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $gatewayUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payloadSkale));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: ' . 'Basic ' . base64_encode($skaleSecretKey . ':x')
]);

$resposta   = curl_exec($ch);
$httpcode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

file_put_contents('debug_gerar.txt', date('[d/m/Y H:i:s] ') . "HTTP $httpcode - $resposta" . PHP_EOL, FILE_APPEND);

if ($httpcode < 200 || $httpcode >= 300 || !$resposta) {
    http_response_code(500);
    echo json_encode(['erro' => 'Falha ao gerar pagamento (gateway)', 'httpcode' => $httpcode]);
    exit;
}

$resposta_array = json_decode($resposta, true);

// ==========================
// MAPEIA ID E PIX (SkalePay)
// ==========================

$transactionId = $resposta_array['id'] ?? null;
$pixQrcode     = $resposta_array['pix']['qrcode'] ?? null;

if (!$transactionId || !$pixQrcode) {
    http_response_code(500);
    echo json_encode(['erro' => 'Resposta PIX inválida do gateway', 'raw' => $resposta]);
    file_put_contents(
        'debug_gerar_error.txt',
        date('[d/m/Y H:i:s] ') . "Resposta inválida: " . $resposta . PHP_EOL,
        FILE_APPEND
    );
    exit;
}

// Vamos usar o mesmo conteúdo tanto para copia-e-cola quanto para gerar o QR
$pixCode   = $pixQrcode; // copia e cola
$pixQrCode = $pixQrcode; // será usado pelo front para gerar o QRCode

// Salva dados principais na sessão
$_SESSION['id_transacao'] = $transactionId;
$_SESSION['pix_code']     = $pixCode;
$_SESSION['pix_qrcode']   = $pixQrCode;
$_SESSION['valor']        = $valor;
$_SESSION['cpf']          = $cpf;
$_SESSION['nome']         = $nome;
$_SESSION['utms']         = $utms;

file_put_contents('debug_gerar_log.txt', date('[d/m/Y H:i:s] ') . json_encode($_SESSION) . PHP_EOL, FILE_APPEND);

// ==========================
// UTMIFY - VENDA PENDENTE
// ==========================

$agoraUtc = gmdate('Y-m-d H:i:s'); // UTC no formato exigido

$dados_utmify = [
    'orderId'       => (string)$transactionId,
    'platform'      => 'SkalePay',
    'paymentMethod' => 'pix',
    'status'        => 'waiting_payment',
    'createdAt'     => $agoraUtc,
    'approvedDate'  => null,
    'refundedAt'    => null,
    'customer'      => [
        'name'     => $nome,
        'email'    => $email,
        'phone'    => $telefone ?: null,
        'document' => $cpf ?: null,
        'country'  => 'BR',
        'ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
    ],
    'products' => [[
        'id'           => 'compra-online-001',
        'name'         => 'Compra Online',
        'planId'       => null,
        'planName'     => null,
        'quantity'     => 1,
        'priceInCents' => $valor,
    ]],
    'trackingParameters' => [
        'src'          => null,
        'sck'          => null,
        'utm_source'   => $utms['utm_source']   ?? null,
        'utm_campaign' => $utms['utm_campaign'] ?? null,
        'utm_medium'   => $utms['utm_medium']   ?? null,
        'utm_content'  => $utms['utm_content']  ?? null,
        'utm_term'     => $utms['utm_term']     ?? null,
    ],
    'commission' => [
        'totalPriceInCents'     => $valor,
        'gatewayFeeInCents'     => 0,
        'userCommissionInCents' => $valor,
    ],
    'isTest' => false,
];

// garante que a pasta logs existe
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

// salva createdAt + utms para o webhook
$trackingFile = $logDir . "/tracking_{$transactionId}.json";
file_put_contents($trackingFile, json_encode([
    'createdAt'    => $agoraUtc,
    'utm_source'   => $utms['utm_source']   ?? null,
    'utm_campaign' => $utms['utm_campaign'] ?? null,
    'utm_medium'   => $utms['utm_medium']   ?? null,
    'utm_content'  => $utms['utm_content']  ?? null,
    'utm_term'     => $utms['utm_term']     ?? null,
], JSON_UNESCAPED_UNICODE));

file_put_contents(
    'debug_utmify_payload.txt',
    date('[d/m/Y H:i:s] ') . json_encode($dados_utmify, JSON_PRETTY_PRINT) . PHP_EOL,
    FILE_APPEND
);

$ch2 = curl_init();
curl_setopt($ch2, CURLOPT_URL, 'https://api.utmify.com.br/api-credentials/orders');
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_POST, true);
curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($dados_utmify));
curl_setopt($ch2, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'x-api-token: jxrrdDyUvLYpmWiOXGV7D8YuK2vHj9ExfW5Y'
]);

$resposta_utmify  = curl_exec($ch2);
$httpcode_utmify  = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

file_put_contents('debug_utmify_response.txt', date('[d/m/Y H:i:s] ') . $resposta_utmify . PHP_EOL, FILE_APPEND);
file_put_contents('debug_utmify_httpcode.txt', date('[d/m/Y H:i:s] ') . $httpcode_utmify . PHP_EOL, FILE_APPEND);

if ($httpcode_utmify !== 200) {
    file_put_contents(
        'debug_utmify_error.txt',
        date('[d/m/Y H:i:s] ') . "Erro ao enviar para Utmify: HTTP $httpcode_utmify - $resposta_utmify" . PHP_EOL,
        FILE_APPEND
    );
}

header('Content-Type: application/json');
echo json_encode([
    'id'        => $transactionId,
    'pixCode'   => $pixCode,   // copia e cola
    'pixQrCode' => $pixQrCode  // string EMV para gerar o QR
]);
