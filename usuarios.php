<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../crypto.php';

session_start();
aplicarCors();
$pdo = conectarBanco();

function usuarioLogadoEhAdmin(): bool {
    return ($_SESSION['tipo'] ?? '') === 'admin';
}

function exigirAdmin(): void {
    if (!usuarioLogadoEhAdmin()) {
        http_response_code(403);
        echo json_encode(['erro' => 'Apenas o administrador pode gerenciar administradores.']);
        exit;
    }
}

$metodo = $_SERVER['REQUEST_METHOD'];

// ---- Criar (cliente público OU administrador, se quem está logado já é admin) ----
if ($metodo === 'POST') {
    $dados = json_decode(file_get_contents('php://input'), true) ?? [];
    $usuario = trim($dados['usuario'] ?? '');
    $nome = trim($dados['nome'] ?? '');
    $senha = $dados['senha'] ?? '';
    $telefone = $dados['telefone'] ?? '';
    $endereco = $dados['endereco'] ?? '';

    // Só cria um administrador se quem está fazendo a chamada já estiver logado como admin.
    // Qualquer outra chamada (cadastro público na loja) sempre vira 'cliente'.
    $quereAdmin = ($dados['tipo'] ?? 'cliente') === 'admin';
    if ($quereAdmin) {
        exigirAdmin();
    }
    $tipo = $quereAdmin ? 'admin' : 'cliente';

    $permissoes = null;
    if ($tipo === 'admin') {
        $perms = is_array($dados['permissoes'] ?? null) ? array_values($dados['permissoes']) : [];
        $permissoes = json_encode($perms);
    }

    if ($usuario === '' || $nome === '' || strlen($senha) < 6) {
        http_response_code(400);
        echo json_encode(['erro' => 'Preencha usuário, nome e uma senha com pelo menos 6 caracteres.']);
        exit;
    }
    if ($tipo === 'admin' && empty(json_decode($permissoes, true))) {
        http_response_code(400);
        echo json_encode(['erro' => 'Selecione pelo menos uma permissão de acesso.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO usuarios (tipo, usuario, nome, senha_hash, telefone_enc, endereco_enc, permissoes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $tipo,
            $usuario,
            $nome,
            gerarHashSenha($senha),
            criptografar($telefone),
            criptografar($endereco),
            $permissoes,
        ]);
        echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            http_response_code(409);
            echo json_encode(['erro' => 'Esse nome de usuário já existe.']);
        } else {
            http_response_code(500);
            echo json_encode(['erro' => 'Erro ao cadastrar.']);
        }
    }
    exit;
}

// ---- Editar administrador (apenas admin) ----
if ($metodo === 'PUT') {
    exigirAdmin();
    $dados = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($dados['id'] ?? 0);
    $usuario = trim($dados['usuario'] ?? '');
    $nome = trim($dados['nome'] ?? '');
    $senha = $dados['senha'] ?? '';
    $perms = is_array($dados['permissoes'] ?? null) ? array_values($dados['permissoes']) : [];

    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ? AND tipo = 'admin'");
    $stmt->execute([$id]);
    $atual = $stmt->fetch();
    if (!$atual) {
        http_response_code(404);
        echo json_encode(['erro' => 'Administrador não encontrado.']);
        exit;
    }

    if ($usuario === '' || $nome === '' || count($perms) === 0) {
        http_response_code(400);
        echo json_encode(['erro' => 'Preencha nome, e-mail e ao menos uma permissão.']);
        exit;
    }
    if ($senha !== '' && strlen($senha) < 6) {
        http_response_code(400);
        echo json_encode(['erro' => 'A senha precisa ter pelo menos 6 caracteres.']);
        exit;
    }

    try {
        if ($senha !== '') {
            $stmt = $pdo->prepare('UPDATE usuarios SET usuario = ?, nome = ?, senha_hash = ?, permissoes = ? WHERE id = ?');
            $stmt->execute([$usuario, $nome, gerarHashSenha($senha), json_encode($perms), $id]);
        } else {
            $stmt = $pdo->prepare('UPDATE usuarios SET usuario = ?, nome = ?, permissoes = ? WHERE id = ?');
            $stmt->execute([$usuario, $nome, json_encode($perms), $id]);
        }
        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            http_response_code(409);
            echo json_encode(['erro' => 'Esse nome de usuário já existe.']);
        } else {
            http_response_code(500);
            echo json_encode(['erro' => 'Erro ao salvar administrador.']);
        }
    }
    exit;
}

// ---- Excluir administrador (apenas admin) ----
if ($metodo === 'DELETE') {
    exigirAdmin();
    $id = (int)($_GET['id'] ?? 0);

    if ($id === (int)($_SESSION['usuario_id'] ?? 0)) {
        http_response_code(400);
        echo json_encode(['erro' => 'Você não pode excluir a própria conta enquanto está logado nela.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = ? AND tipo = 'admin'");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['erro' => 'Administrador não encontrado.']);
        exit;
    }

    $stmt = $pdo->prepare('DELETE FROM usuarios WHERE id = ?');
    $stmt->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

// ---- Listagem (apenas admin) — descriptografa telefone/endereço só aqui ----
if ($metodo === 'GET') {
    exigirAdmin();
    $stmt = $pdo->query('SELECT id, tipo, usuario, nome, telefone_enc, endereco_enc, permissoes, criado_em FROM usuarios ORDER BY criado_em DESC');
    $lista = [];
    foreach ($stmt as $u) {
        $lista[] = [
            'id' => $u['id'],
            'tipo' => $u['tipo'],
            'usuario' => $u['usuario'],
            'nome' => $u['nome'],
            'telefone' => descriptografar($u['telefone_enc']),
            'endereco' => descriptografar($u['endereco_enc']),
            'permissoes' => $u['permissoes'] ? json_decode($u['permissoes'], true) : [],
            'criado_em' => $u['criado_em'],
        ];
    }
    echo json_encode($lista);
    exit;
}

http_response_code(405);
echo json_encode(['erro' => 'Método não permitido.']);