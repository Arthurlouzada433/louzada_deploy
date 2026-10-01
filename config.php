<?php
/**
 * CONFIGURAÇÃO — preencha com os dados da sua hospedagem antes de usar.
 * Este arquivo NUNCA deve ser acessível publicamente pelo navegador.
 * Se possível, coloque-o FORA da pasta pública (ex: uma pasta acima do public_html).
 */


// ---- Tratamento de erros: a API sempre responde JSON, sem mostrar detalhes técnicos ao visitante ----
ini_set('display_errors', '0');
date_default_timezone_set('America/Sao_Paulo'); // horários de pedidos, bloqueios e códigos usam sempre este fuso
set_exception_handler(function (Throwable $e): void {
    error_log('[louzada] ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['erro' => 'Erro interno no servidor. Tente novamente em instantes.']);
});

// ---- Dados de conexão com o MySQL (pegue no painel da hospedagem / phpMyAdmin) ----
define('DB_HOST', 'sql302.infinityfree.com');
define('DB_NAME', 'if0_42863219_bd_louzada');
define('DB_USER', 'if0_42863219');
define('DB_PASS', 'Ar33252813'); // XAMPP local: root sem senha mesmo mais

// ---- Chave de criptografia (32 bytes = AES-256) ----
// Gere UMA VEZ com o comando abaixo (no terminal da hospedagem, ou peça pra mim gerar)
// e cole o resultado aqui. NUNCA compartilhe essa chave nem a coloque em nenhum HTML.
//   php -r "echo bin2hex(random_bytes(32));"
define('CHAVE_CRIPTOGRAFIA_HEX', 'f470dc5b91cb5d0d48013d67c4de64cb015cf4e397ab5ce126a720c3dabd0d39');

// ---- Pepper do hash duplo de senha (32 bytes = 64 caracteres hex) ----
// Gere UMA VEZ com:  php -r "echo bin2hex(random_bytes(32));"
// Use um valor DIFERENTE da chave de criptografia acima. Depois que usuários
// tiverem senhas salvas, NÃO troque mais: todas as senhas deixariam de funcionar.
define('SENHA_PEPPER_HEX', 'fabe5b984f5b184d0a0f554f46eeacda031ae29c218ae3bafb0318891d654ab4');

// ---- CORS: domínio(s) que podem chamar esta API (o domínio do seu site) ----
// Enquanto testa no XAMPP, mantenha o http://localhost aqui.
// Quando for publicar, troque/adicione o domínio real (ex: https://seudominio.com.br)
// e pode remover a linha do localhost.
define('ORIGENS_PERMITIDAS', [
    'http://localhost',
    'https://louzada-hortifruti.free.nf',
    'http://louzada-hortifruti.free.nf',
]);

// ---- Recuperação de senha (api/recuperar-senha.php) ----
define('RECUPERACAO_CODIGO_TTL_MIN', 10);        // validade do código de 6 dígitos (minutos)
define('RECUPERACAO_TOKEN_TTL_MIN', 15);         // validade do token para trocar a senha (minutos)
define('RECUPERACAO_MAX_TENTATIVAS', 5);         // erros de código antes de exigir um novo
define('RECUPERACAO_INTERVALO_REENVIO_SEG', 60); // intervalo mínimo entre pedidos de código

// ---- Envio de e-mail (email.php, via Gmail API + OAuth do Google) ----
// Vazio = não envia (só registra no log). NUNCA compartilhe estes valores com ninguém.
define('GOOGLE_CLIENT_ID', '353777211172-ipn31qcuvd62aa4hp4a01130sdn9btbi.apps.googleusercontent.com');       // ID do cliente OAuth (Google Cloud Console)
define('GOOGLE_CLIENT_SECRET', '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');   // Chave secreta do cliente OAuth
define('GOOGLE_REFRESH_TOKEN', '');   // COLE AQUI o refresh token gerado no OAuth Playground (escopo gmail.send). Não é uma URL.
define('EMAIL_REMETENTE', 'arthurgarecilou@gmail.com');        // a conta Gmail que autorizou o acesso (ex: loja@gmail.com)
define('EMAIL_REMETENTE_NOME', 'Louzada Frutas & Legumes');

// ---- WhatsApp (whatsapp.php, via Z-API) ----
// Vazio = não envia (só registra no log). Preencha só se for usar o whatsapp.php.
define('WHATSAPP_API_URL', '');    // ex: https://api.z-api.io/instances/SEU_ID/token/SEU_TOKEN/send-text
define('WHATSAPP_API_TOKEN', '');  // Client-Token da conta Z-API

function conectarBanco(): PDO {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function aplicarCors(): void {
    $origem = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origem, ORIGENS_PERMITIDAS, true)) {
        header('Access-Control-Allow-Origin: ' . $origem);
    }
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

$_ehHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
         || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    // Secure é ligado sozinho quando a página é aberta em HTTPS; em http://localhost (XAMPP)
    // continua desligado, senão o navegador descarta o cookie e o login nunca "gruda".
    'secure'   => $_ehHttps,
]);
