<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../crypto.php';

session_start();
aplicarCors();

$pdo = conectarBanco();
$acao = $_GET['acao'] ?? $_POST['acao'] ?? '';

/**
 * O endereço é salvo no banco como uma única string "rua, número - bairro"
 * (ver api/usuarios.php e pedidos.html). Esta função desfaz essa junção pra
 * devolver rua/número/bairro separados pro front-end (usado na mensagem do
 * WhatsApp em index.html).
 */
function separarEndereco(?string $enderecoCompleto): array {
    $enderecoCompleto = trim((string)$enderecoCompleto);
    if ($enderecoCompleto === '') {
        return ['endereco' => '', 'numero' => '', 'bairro' => ''];
    }

    $bairro = '';
    $resto = $enderecoCompleto;
    $posTraco = strrpos($enderecoCompleto, ' - ');
    if ($posTraco !== false) {
        $resto = substr($enderecoCompleto, 0, $posTraco);
        $bairro = trim(substr($enderecoCompleto, $posTraco + 3));
    }

    $numero = '';
    $endereco = $resto;
    $posVirgula = strrpos($resto, ',');
    if ($posVirgula !== false) {
        $endereco = trim(substr($resto, 0, $posVirgula));
        $numero = trim(substr($resto, $posVirgula + 1));
    }

    return ['endereco' => $endereco, 'numero' => $numero, 'bairro' => $bairro];
}

/**
 * Busca (e descriptografa) os dados de contato/entrega do usuário logado.
 */
function buscarDadosDoUsuario(PDO $pdo, int $usuarioId): array {
    $stmt = $pdo->prepare('SELECT nome, usuario, telefone_enc, endereco_enc FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([$usuarioId]);
    $u = $stmt->fetch();
    if (!$u) return [];

    $partesEndereco = separarEndereco(descriptografar($u['endereco_enc']));

    return [
        'nome' => $u['nome'],
        'email' => $u['usuario'],
        'telefone' => descriptografar($u['telefone_enc']) ?? '',
        'endereco' => $partesEndereco['endereco'],
        'numero' => $partesEndereco['numero'],
        'bairro' => $partesEndereco['bairro'],
    ];
}

if ($acao === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dados = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $usuario = trim($dados['usuario'] ?? '');
    $senha = $dados['senha'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE usuario = ? LIMIT 1');
    $stmt->execute([$usuario]);
    $u = $stmt->fetch();

    if (!$u) {
        http_response_code(401);
        echo json_encode(['erro' => 'Usuário ou senha incorretos.']);
        exit;
    }

    // Bloqueio ativo?
    if ($u['bloqueado_ate'] && strtotime($u['bloqueado_ate']) > time()) {
        http_response_code(423);
        echo json_encode(['erro' => 'Conta bloqueada por tentativas incorretas. Fale com o administrador.']);
        exit;
    }

    if (!verificarSenha($senha, $u['senha_hash'])) {
        $tentativas = $u['tentativas_login'] + 1;
        $bloqueado_ate = null;
        if ($tentativas >= 5) {
            $bloqueado_ate = date('Y-m-d H:i:s', time() + 30 * 60); // 30 min de bloqueio
        }
        $stmt = $pdo->prepare('UPDATE usuarios SET tentativas_login = ?, bloqueado_ate = ? WHERE id = ?');
        $stmt->execute([$tentativas, $bloqueado_ate, $u['id']]);

        http_response_code(401);
        echo json_encode(['erro' => 'Usuário ou senha incorretos.']);
        exit;
    }

    // Migra hash antigo (bcrypt simples) para o hash duplo, agora que temos a senha em mãos
    if (senhaPrecisaAtualizar($u['senha_hash'])) {
        $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')
            ->execute([gerarHashSenha($senha), $u['id']]);
    }

    // Login OK: zera tentativas
    $stmt = $pdo->prepare('UPDATE usuarios SET tentativas_login = 0, bloqueado_ate = NULL WHERE id = ?');
    $stmt->execute([$u['id']]);

    session_regenerate_id(true); // evita fixação de sessão
    $_SESSION['usuario_id'] = $u['id'];
    $_SESSION['tipo'] = $u['tipo'];

    $partesEndereco = separarEndereco(descriptografar($u['endereco_enc']));

    echo json_encode([
        'ok' => true,
        'tipo' => $u['tipo'],
        'nome' => $u['nome'],
        'email' => $u['usuario'],
        'telefone' => descriptografar($u['telefone_enc']) ?? '',
        'endereco' => $partesEndereco['endereco'],
        'numero' => $partesEndereco['numero'],
        'bairro' => $partesEndereco['bairro'],
    ]);
    exit;
}

if ($acao === 'logout') {
    $_SESSION = [];
    session_destroy();
    echo json_encode(['ok' => true]);
    exit;
}

if ($acao === 'status') {
    echo json_encode([
        'logado' => isset($_SESSION['usuario_id']),
        'tipo' => $_SESSION['tipo'] ?? null,
    ]);
    exit;
}

// Dados do próprio usuário logado (nome, telefone, endereço/número/bairro),
// usado pra reconstruir o currentUser no front-end quando a página é recarregada
// (o status acima só confirma a sessão, não traz os dados de entrega).
if ($acao === 'meus_dados') {
    if (!isset($_SESSION['usuario_id'])) {
        http_response_code(401);
        echo json_encode(['erro' => 'Não autenticado.']);
        exit;
    }
    $dados = buscarDadosDoUsuario($pdo, (int)$_SESSION['usuario_id']);
    if (!$dados) {
        http_response_code(404);
        echo json_encode(['erro' => 'Usuário não encontrado.']);
        exit;
    }
    echo json_encode(array_merge(['ok' => true], $dados));
    exit;
}

// Só o administrador logado pode desbloquear outra conta
if ($acao === 'desbloquear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_SESSION['tipo'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['erro' => 'Apenas o administrador pode desbloquear contas.']);
        exit;
    }
    $dados = json_decode(file_get_contents('php://input'), true) ?? [];
    $stmt = $pdo->prepare('UPDATE usuarios SET tentativas_login = 0, bloqueado_ate = NULL WHERE id = ?');
    $stmt->execute([(int)($dados['usuario_id'] ?? 0)]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['erro' => 'Ação inválida.']);