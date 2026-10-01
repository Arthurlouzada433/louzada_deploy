<?php
require_once __DIR__ . '/config.php';

/**
 * Envia um e-mail pela API do Gmail usando OAuth 2.0 (Google).
 * Retorna true se o Gmail aceitou o envio.
 *
 * Configure em config.php: GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REFRESH_TOKEN
 * e EMAIL_REMETENTE (a conta Gmail que autorizou o acesso).
 * Escopo necessário: https://www.googleapis.com/auth/gmail.send
 */

/** Troca o refresh token por um access token (vale ~1 hora). Retorna null se falhar. */
function obterAccessTokenGoogle(): ?string {
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'refresh_token' => GOOGLE_REFRESH_TOKEN,
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resposta = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);
    curl_close($ch);

    $dados = is_string($resposta) ? json_decode($resposta, true) : null;
    if ($status !== 200 || empty($dados['access_token'])) {
        // Ex.: invalid_grant = refresh token expirado/revogado (veja o log).
        error_log("[email.php] Falha ao obter access token do Google (HTTP {$status}) {$erro} {$resposta}");
        return null;
    }
    return $dados['access_token'];
}

function codificarCabecalho(string $texto): string {
    return '=?UTF-8?B?' . base64_encode($texto) . '?=';
}

function base64url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function enviarEmail(string $para, string $assunto, string $html, string $texto = ''): bool {
    if (GOOGLE_CLIENT_ID === '' || GOOGLE_CLIENT_SECRET === '' || GOOGLE_REFRESH_TOKEN === '' || EMAIL_REMETENTE === '') {
        error_log('[email.php] Credenciais do Google não configuradas em config.php — e-mail não enviado.');
        return false;
    }
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
        error_log('[email.php] Destinatário com e-mail inválido.');
        return false;
    }

    $accessToken = obterAccessTokenGoogle();
    if ($accessToken === null) return false;

    if ($texto === '') $texto = trim(strip_tags($html));
    $limite = 'b_' . bin2hex(random_bytes(12));

    $mime  = 'From: ' . codificarCabecalho(EMAIL_REMETENTE_NOME) . ' <' . EMAIL_REMETENTE . ">\r\n";
    $mime .= 'To: ' . $para . "\r\n";
    $mime .= 'Subject: ' . codificarCabecalho($assunto) . "\r\n";
    $mime .= "MIME-Version: 1.0\r\n";
    $mime .= "Content-Type: multipart/alternative; boundary=\"{$limite}\"\r\n\r\n";
    $mime .= "--{$limite}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $mime .= chunk_split(base64_encode($texto)) . "\r\n";
    $mime .= "--{$limite}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $mime .= chunk_split(base64_encode($html)) . "\r\n";
    $mime .= "--{$limite}--";

    $ch = curl_init('https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['raw' => base64url($mime)]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
    ]);
    $resposta = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);
    curl_close($ch);

    if ($resposta === false || $status < 200 || $status >= 300) {
        error_log("[email.php] Falha no envio pelo Gmail (HTTP {$status}) {$erro} {$resposta}");
        return false;
    }
    return true;
}
