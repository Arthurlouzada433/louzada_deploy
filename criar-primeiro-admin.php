<?php
/**
 * Rode este arquivo UMA VEZ (acessando pelo navegador) para criar o admin inicial.
 * Depois de usar, APAGUE este arquivo do servidor — ele não deve ficar publicado.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/crypto.php';
header('Content-Type: text/plain; charset=utf-8');

// >>> ALTERE ESTES 3 VALORES ANTES DE RODAR <<<
// (o usuário precisa continuar "arthur" enquanto OWNER_USERNAME em relatorio.html for 'arthur')
$usuario_admin = 'arthur';
$nome_admin = 'Arthur';
$senha_admin = 'TROQUE-ESTA-SENHA';

if ($senha_admin === 'TROQUE-ESTA-SENHA' || strlen($senha_admin) < 8) {
    http_response_code(400);
    exit('Edite este arquivo e defina uma senha forte (mínimo 8 caracteres) em $senha_admin antes de rodar.');
}

$pdo = conectarBanco();

// Trava: só cria se ainda não existir nenhum administrador (evita que o arquivo esquecido no servidor vire porta aberta).
if ((int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE tipo = 'admin'")->fetchColumn() > 0) {
    http_response_code(403);
    exit('Já existe um administrador. APAGUE este arquivo do servidor.');
}

$stmt = $pdo->prepare('INSERT INTO usuarios (tipo, usuario, nome, senha_hash, permissoes) VALUES (?, ?, ?, ?, ?)');
$stmt->execute(['admin', $usuario_admin, $nome_admin, gerarHashSenha($senha_admin), json_encode(['pedidos', 'produtos', 'admins', 'excluir'])]);

echo 'Administrador criado com sucesso! Agora APAGUE este arquivo do servidor.';
