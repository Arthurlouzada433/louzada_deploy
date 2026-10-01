<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../crypto.php';
require_once __DIR__ . '/../email.php';

session_start();
aplicarCors();

$pdo = conectarBanco();
$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';
$dados = json_decode(file_get_contents('php://input'), true) ?? [];

function normalizarTelefone(?string $t): string {
    return preg_replace('/\D/', '', $t ?? '');
}
function gerarCodigo(): string {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}
function hashCurto(string $valor): string {
    return hash('sha256', $valor);
}

// ---------------------------------------------------------------
// ETAPA 1 — pessoa informa o e-mail (login) da conta. Se existir uma
// conta de cliente com esse e-mail, o código é enviado para esse mesmo e-mail.
// ---------------------------------------------------------------
if ($acao === 'solicitar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($dados['usuario'] ?? '');

    // Resposta genérica, exista ou não a conta — evita que alguém use isto
    // pra descobrir quais e-mails estão cadastrados.
    $respostaGenerica = ['ok' => true, 'mensagem' => 'Se o e-mail conferir com uma conta, enviamos um código para ele.'];

    if ($usuario === '') {
        http_response_code(400);
        echo json_encode(['erro' => 'Informe o e-mail.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE usuario = ? AND tipo = "cliente" LIMIT 1');
    $stmt->execute([$usuario]);
    $u = $stmt->fetch();

    if (!$u) { echo json_encode($respostaGenerica); exit; }

    // Evita spam: só deixa pedir um código novo depois de um intervalo mínimo.
    $stmt = $pdo->prepare('SELECT criado_em FROM recuperacoes_senha WHERE usuario_id = ? ORDER BY criado_em DESC LIMIT 1');
    $stmt->execute([$u['id']]);
    $ultimo = $stmt->fetchColumn();
    if ($ultimo && (time() - strtotime($ultimo)) < RECUPERACAO_INTERVALO_REENVIO_SEG) {
        http_response_code(429);
        echo json_encode(['erro' => 'Aguarde um pouco antes de pedir um novo código.']);
        exit;
    }

    $codigo = gerarCodigo();
    $expiraEm = date('Y-m-d H:i:s', time() + RECUPERACAO_CODIGO_TTL_MIN * 60);

    $pdo->prepare('DELETE FROM recuperacoes_senha WHERE usuario_id = ?')->execute([$u['id']]);
    $stmt = $pdo->prepare('INSERT INTO recuperacoes_senha (usuario_id, codigo_hash, expira_em, criado_em) VALUES (?, ?, ?, ?)');
    $stmt->execute([$u['id'], hashCurto($codigo), $expiraEm, date('Y-m-d H:i:s')]);

    $ttl = RECUPERACAO_CODIGO_TTL_MIN;
    $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#222">'
          . '<h2 style="color:#2e7d32">Louzada Frutas &amp; Legumes</h2>'
          . '<p>Use o código abaixo para redefinir a sua senha:</p>'
          . '<p style="font-size:32px;font-weight:bold;letter-spacing:6px;margin:16px 0">' . $codigo . '</p>'
          . "<p>Ele vale por {$ttl} minutos. Não compartilhe com ninguém.</p>"
          . '<p style="color:#777;font-size:12px">Se você não pediu a troca de senha, pode ignorar este e-mail.</p>'
          . '</div>';
    $texto = "Louzada Frutas & Legumes: seu código para redefinir a senha é {$codigo}. Ele vale por {$ttl} minutos. Não compartilhe com ninguém.";

    $enviado = enviarEmail($u['usuario'], 'Seu código para redefinir a senha', $html, $texto);

    if (!$enviado) {
        // O código já existe no banco, mas o envio falhou de verdade — veja o log de erros
        // (normalmente as credenciais GOOGLE_* ainda não configuradas em config.php, ou refresh token expirado).
        error_log('[recuperar-senha.php] Código gerado mas envio do e-mail falhou para usuario_id=' . $u['id']);
    }

    echo json_encode($respostaGenerica);
    exit;
}

// ---------------------------------------------------------------
// ETAPA 2 — pessoa informa o código recebido; se bater, liberamos
// um token de curta duração pra ela poder definir a nova senha.
// ---------------------------------------------------------------
if ($acao === 'verificar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($dados['usuario'] ?? '');
    $codigo = trim($dados['codigo'] ?? '');

    $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE usuario = ? LIMIT 1');
    $stmt->execute([$usuario]);
    $u = $stmt->fetch();
    if (!$u) { http_response_code(400); echo json_encode(['erro' => 'Código inválido ou expirado.']); exit; }

    $stmt = $pdo->prepare('SELECT * FROM recuperacoes_senha WHERE usuario_id = ? ORDER BY criado_em DESC LIMIT 1');
    $stmt->execute([$u['id']]);
    $r = $stmt->fetch();

    if (!$r || strtotime($r['expira_em']) < time()) {
        http_response_code(400);
        echo json_encode(['erro' => 'Código inválido ou expirado. Peça um novo código.']);
        exit;
    }
    if ($r['tentativas'] >= RECUPERACAO_MAX_TENTATIVAS) {
        http_response_code(429);
        echo json_encode(['erro' => 'Muitas tentativas erradas. Peça um novo código.']);
        exit;
    }
    if (!hash_equals($r['codigo_hash'], hashCurto($codigo))) {
        $pdo->prepare('UPDATE recuperacoes_senha SET tentativas = tentativas + 1 WHERE id = ?')->execute([$r['id']]);
        http_response_code(400);
        echo json_encode(['erro' => 'Código incorreto. Você pode pedir um novo código.']);
        exit;
    }

    // Código certo: gera o token que libera a troca de senha (etapa 3).
    $token = bin2hex(random_bytes(32));
    $novaExpiracao = date('Y-m-d H:i:s', time() + RECUPERACAO_TOKEN_TTL_MIN * 60);
    $stmt = $pdo->prepare('UPDATE recuperacoes_senha SET token_hash = ?, expira_em = ?, tentativas = 0 WHERE id = ?');
    $stmt->execute([hashCurto($token), $novaExpiracao, $r['id']]);

    echo json_encode(['ok' => true, 'token' => $token]);
    exit;
}

// ---------------------------------------------------------------
// ETAPA 3 — pessoa define a nova senha, usando o token da etapa 2.
// ---------------------------------------------------------------
if ($acao === 'redefinir' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($dados['usuario'] ?? '');
    $token = trim($dados['token'] ?? '');
    $novaSenha = $dados['nova_senha'] ?? '';

    if (strlen($novaSenha) < 6) {
        http_response_code(400);
        echo json_encode(['erro' => 'A senha precisa ter pelo menos 6 caracteres.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE usuario = ? LIMIT 1');
    $stmt->execute([$usuario]);
    $u = $stmt->fetch();
    if (!$u) { http_response_code(400); echo json_encode(['erro' => 'Não foi possível redefinir a senha. Peça um novo código.']); exit; }

    $stmt = $pdo->prepare('SELECT * FROM recuperacoes_senha WHERE usuario_id = ? ORDER BY criado_em DESC LIMIT 1');
    $stmt->execute([$u['id']]);
    $r = $stmt->fetch();

    if (!$r || !$r['token_hash'] || strtotime($r['expira_em']) < time() || !hash_equals($r['token_hash'], hashCurto($token))) {
        http_response_code(400);
        echo json_encode(['erro' => 'Não foi possível redefinir a senha. Peça um novo código.']);
        exit;
    }

    $stmt = $pdo->prepare('UPDATE usuarios SET senha_hash = ?, tentativas_login = 0, bloqueado_ate = NULL WHERE id = ?');
    $stmt->execute([gerarHashSenha($novaSenha), $u['id']]);
    $pdo->prepare('DELETE FROM recuperacoes_senha WHERE usuario_id = ?')->execute([$u['id']]);

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['erro' => 'Ação inválida.']);