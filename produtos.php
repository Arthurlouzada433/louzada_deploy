<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../crypto.php';

session_start();
aplicarCors();
$pdo = conectarBanco();

const CATEGORIAS_VALIDAS = ['frutas', 'verduras', 'legumes', 'cestas'];

function falhar(string $msg, int $codigo = 400): void {
    http_response_code($codigo);
    echo json_encode(['erro' => $msg]);
    exit;
}

function exigirAdmin(): void {
    if (($_SESSION['tipo'] ?? '') !== 'admin') {
        falhar('Apenas o administrador pode alterar produtos.', 403);
    }
}

/** "8,99" ou "8.99" -> "8.99" (formato do banco). Retorna null se o preço for inválido. */
function precoParaDecimal(string $p): ?string {
    $p = trim($p);
    if (!preg_match('/^\d+([.,]\d{1,2})?$/', $p)) return null;
    return number_format((float) str_replace(',', '.', $p), 2, '.', '');
}

/** "8.99" (banco) -> "8,99" (formato usado nas páginas). */
function precoParaTexto($decimal): string {
    return number_format((float) $decimal, 2, ',', '');
}

/** Converte uma linha do banco para o formato que index.html e relatorio.html já usam. */
function produtoParaFront(array $r): array {
    $out = [
        'id'     => (int) $r['id'],
        'cat'    => $r['categoria'] ?? '',
        'badge'  => $r['badge'] ?? '',
        'name'   => $r['nome'],
        'desc'   => $r['descricao'] ?? '',
        'price'  => precoParaTexto($r['preco']),
        'unit'   => $r['unidade'],
        'oferta' => (bool) $r['em_oferta'],
        'img'    => $r['imagem'] ?? '',
    ];
    $v = !empty($r['variedades']) ? json_decode($r['variedades'], true) : null;
    if (is_array($v) && count($v) > 0) $out['varieties'] = $v;
    return $out;
}

/** Valida o corpo da requisição e devolve as colunas prontas para gravar. Em caso de erro, responde 400 e encerra. */
function validarProduto(array $d): array {
    $nome  = trim((string) ($d['name'] ?? ''));
    $cat   = (string) ($d['cat'] ?? '');
    $desc  = trim((string) ($d['desc'] ?? ''));
    $unit  = trim((string) ($d['unit'] ?? ''));
    $img   = trim((string) ($d['img'] ?? ''));
    $badge = trim((string) ($d['badge'] ?? ''));

    if ($nome === '' || $desc === '' || $unit === '' || $img === '') {
        falhar('Preencha todos os campos obrigatórios.');
    }
    if (!in_array($cat, CATEGORIAS_VALIDAS, true)) {
        falhar('Categoria inválida.');
    }
    if (mb_strlen($nome) > 150 || mb_strlen($desc) > 255 || mb_strlen($unit) > 30 || mb_strlen($badge) > 60 || strlen($img) > 100000) {
        falhar('Algum campo passou do tamanho permitido.');
    }
    if (preg_match('/^\s*javascript:/i', $img)) {
        falhar('Endereço de imagem inválido.');
    }
    $preco = precoParaDecimal((string) ($d['price'] ?? ''));
    if ($preco === null) {
        falhar('Informe um preço válido, ex: 7,90.');
    }

    $variedades = [];
    if (isset($d['varieties']) && is_array($d['varieties'])) {
        foreach (array_slice($d['varieties'], 0, 30) as $v) {
            if (!is_array($v)) continue;
            $vn = trim((string) ($v['name'] ?? ''));
            $vi = trim((string) ($v['img'] ?? ''));
            if ($vn === '' || mb_strlen($vn) > 100) {
                falhar('Informe o nome de cada tipo adicionado (ex: Nanica, Prata).');
            }
            $vp = precoParaDecimal((string) ($v['price'] ?? ''));
            if ($vp === null) {
                falhar('Informe um preço válido para o tipo "' . $vn . '", ex: 5,49.');
            }
            if (preg_match('/^\s*javascript:/i', $vi)) {
                falhar('Endereço de imagem inválido em um dos tipos.');
            }
            $variedades[] = ['name' => $vn, 'price' => precoParaTexto($vp), 'img' => $vi !== '' ? $vi : $img];
        }
    }

    return [
        'nome'       => $nome,
        'categoria'  => $cat,
        'descricao'  => $desc,
        'badge'      => $badge,
        'preco'      => $preco,
        'unidade'    => $unit,
        'em_oferta'  => !empty($d['oferta']) ? 1 : 0,
        'imagem'     => $img,
        'variedades' => count($variedades) ? json_encode($variedades, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ];
}

$metodo = $_SERVER['REQUEST_METHOD'];

// ---- Listar produtos (público, para a vitrine) ----
if ($metodo === 'GET') {
    header('Cache-Control: no-store');
    $linhas = $pdo->query('SELECT * FROM produtos WHERE ativo = 1 ORDER BY id')->fetchAll();
    echo json_encode(array_map('produtoParaFront', $linhas), JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Criar produto (admin) ----
if ($metodo === 'POST') {
    exigirAdmin();
    $d = json_decode(file_get_contents('php://input'), true);
    $c = validarProduto(is_array($d) ? $d : []);
    $uid = (int) $_SESSION['usuario_id'];

    $stmt = $pdo->prepare(
        'INSERT INTO produtos (nome, categoria, descricao, badge, preco, unidade, em_oferta, imagem, variedades, ativo, atualizado_por)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
    );
    $stmt->execute([
        $c['nome'], $c['categoria'], $c['descricao'], $c['badge'], $c['preco'],
        $c['unidade'], $c['em_oferta'], $c['imagem'], $c['variedades'], $uid,
    ]);
    $id = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO produtos_historico (produto_id, campo_alterado, valor_antigo, valor_novo, alterado_por) VALUES (?, ?, NULL, ?, ?)')
        ->execute([$id, 'criado', $c['nome'], $uid]);

    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

// ---- Atualizar produto (admin) — registra cada campo alterado no histórico ----
if ($metodo === 'PUT') {
    exigirAdmin();
    $d = json_decode(file_get_contents('php://input'), true);
    $d = is_array($d) ? $d : [];
    $id = (int) ($d['id'] ?? 0);
    $uid = (int) $_SESSION['usuario_id'];

    $stmt = $pdo->prepare('SELECT * FROM produtos WHERE id = ? AND ativo = 1');
    $stmt->execute([$id]);
    $atual = $stmt->fetch();
    if (!$atual) falhar('Produto não encontrado.', 404);

    $c = validarProduto($d);

    $pdo->beginTransaction();
    try {
        $sets = [];
        $valores = [];
        $log = $pdo->prepare(
            'INSERT INTO produtos_historico (produto_id, campo_alterado, valor_antigo, valor_novo, alterado_por) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($c as $coluna => $novo) {          // $coluna vem de validarProduto(): nomes fixos, seguros
            if ((string) $novo !== (string) $atual[$coluna]) {
                $sets[] = "$coluna = ?";
                $valores[] = $novo;
                $log->execute([$id, $coluna, $atual[$coluna], $novo, $uid]);
            }
        }
        if ($sets) {
            $valores[] = $uid;
            $valores[] = $id;
            $pdo->prepare('UPDATE produtos SET ' . implode(', ', $sets) . ', atualizado_por = ? WHERE id = ?')->execute($valores);
        }
        $pdo->commit();
        echo json_encode(['ok' => true, 'alteracoes' => count($sets)]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[produtos.php] ' . $e->getMessage());
        falhar('Erro ao atualizar produto.', 500);
    }
    exit;
}

// ---- Excluir (soft delete — mantém histórico e pedidos antigos) ----
if ($metodo === 'DELETE') {
    exigirAdmin();
    $id = (int) ($_GET['id'] ?? 0);
    $uid = (int) $_SESSION['usuario_id'];
    $stmt = $pdo->prepare('UPDATE produtos SET ativo = 0, atualizado_por = ? WHERE id = ? AND ativo = 1');
    $stmt->execute([$uid, $id]);
    if ($stmt->rowCount() > 0) {
        $pdo->prepare('INSERT INTO produtos_historico (produto_id, campo_alterado, valor_antigo, valor_novo, alterado_por) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, 'ativo', '1', '0', $uid]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['erro' => 'Método não permitido.']);
