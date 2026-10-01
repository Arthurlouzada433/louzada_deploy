<?php
require_once __DIR__ . '/config.php';

/**
 * Envia uma mensagem de WhatsApp. Retorna true se o serviço respondeu com sucesso.
 *
 * Configure WHATSAPP_API_URL e WHATSAPP_API_TOKEN em config.php.
 * O formato abaixo (JSON com "phone" e "message" + token Bearer) é genérico:
 * ajuste o $payload e os headers conforme a documentação do serviço que você usar
 * (Z-API, Evolution API, WhatsApp Cloud API, etc.).
 */
function enviarWhatsApp(string $telefone, string $mensagem): bool {
    if (WHATSAPP_API_URL === '') {
        error_log('[whatsapp.php] WHATSAPP_API_URL não configurado em config.php — mensagem não enviada.');
        return false;
    }

    // Só dígitos; se vier sem DDI (10 ou 11 dígitos), assume Brasil (55).
    $telefone = preg_replace('/\D/', '', $telefone);
    if (strlen($telefone) === 10 || strlen($telefone) === 11) {
        $telefone = '55' . $telefone;
    }

    $payload = json_encode(['phone' => $telefone, 'message' => $mensagem]);

    $ch = curl_init(WHATSAPP_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . WHATSAPP_API_TOKEN,
        ],
    ]);
    $resposta = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);
    curl_close($ch);

    if ($resposta === false || $status < 200 || $status >= 300) {
        error_log("[whatsapp.php] Falha no envio (HTTP {$status}) {$erro} {$resposta}");
        return false;
    }
    return true;
}
