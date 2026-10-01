<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../crypto.php';

session_start();
aplicarCors();
$pdo = conectarBanco();

function falhar(string $msg, int $codigo = 400): void {
    http_response_code($codigo);
    echo json_encode(['erro' => $msg]);
    exit;
}

function exigirLogin(): int {
    if (!isset($_SESSION['usuario_id'])) {
        falhar('Faça login para finalizar o pedido.', 401);
    }
    return (int) $_SESSION['usuario_id'];
}

function exigirAdmin(): void {
    if (($_SESSION['tipo'] ?? '') !== 'admin') {
        falhar('Apenas o administrador pode ver ou alterar pedidos.', 403);
    }
}

/** "5,99" -> 5.99 */
function textoParaNumero($txt): float {
    return (float) str_replace(',', '.', str_replace('.', '', (string) $txt));
}

/** O endereço é guardado como "rua, número - bairro". Separa de volta em partes. */
function dividirEndereco(?string $completo): array {
    $completo = trim((string) $completo);
    if ($completo === '') return ['endereco' => '', 'numero' => '', 'bairro' => ''];
    $bairro = '';
    $resto = $completo;
    $pos = strrpos($completo, ' - ');
    if ($pos !== false) {
        $resto = substr($completo, 0, $pos);
        $bairro = trim(substr($completo, $pos + 3));
    }
    $numero = '';
    $endereco = $resto;
    $virg = strrpos($resto, ',');
    if ($virg !== false) {
        $endereco = trim(substr($resto, 0, $virg));
        $numero = trim(substr($resto, $virg + 1));
    }
    return ['endereco' => $endereco, 'numero' => $numero, 'bairro' => $bairro];
}

$metodo = $_SERVER['REQUEST_METHOD'];
$acao = $_GET['acao'] ?? '';

// ---------------------------------------------------------------
// IMPORTAR pedidos antigos que estavam só no navegador do admin
// ---------------------------------------------------------------
if ($metodo === 'POST' && $acao === 'importar') {
    exigirAdmin();
    $d = json_decode(file_get_contents('php://input'), true);
    $lista = is_array($d) && is_array($d['pedidos'] ?? null) ? array_slice($d['pedidos'], 0, 2000) : [];

    $insPedido = $pdo->prepare(
        'INSERT INTO pedidos (cliente_nome, cliente_email, cliente_telefone_enc, cliente_endereco_enc, subtotal,
                              entrega_tipo, entrega_periodo, pagamento_tipo, pagamento_cartao, pagamento_troco, criado_em)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insItem = $pdo->prepare(
        'INSERT INTO pedidos_itens (pedido_id, produto_id, nome, variedade, categoria, qtd, preco_unit, total_linha)
         VALUES (?, NULL, ?, ?, ?, ?, ?, ?)'
    );

    $importados = 0;
    $pdo->beginTransaction();
    try {
        foreach ($lista as $o) {
            if (!is_array($o) || !is_array($o['items'] ?? null) || count($o['items']) === 0) continue;
            $cli = is_array($o['customer'] ?? null) ? $o['customer'] : [];
            $ent = is_array($o['delivery'] ?? null) ? $o['delivery'] : [];
            $pag = is_array($o['payment'] ?? null) ? $o['payment'] : [];

            $enderecoTxt = '';
            if (!empty($cli['address'])) {
                $enderecoTxt = trim((string) $cli['address'])
                    . (!empty($cli['number']) ? ', ' . trim((string) $cli['number']) : '')
                    . (!empty($cli['neighborhood']) ? ' - ' . trim((string) $cli['neighborhood']) : '');
            }
            $ts = strtotime((string) ($o['date'] ?? ''));
            $criado = date('Y-m-d H:i:s', $ts !== false ? $ts : time());

            $insPedido->execute([
                mb_substr((string) ($cli['name'] ?? 'Cliente não identificado'), 0, 150),
                mb_substr((string) ($cli['email'] ?? ''), 0, 100),
                criptografar((string) ($cli['phone'] ?? '')),
                criptografar($enderecoTxt),
                round((float) ($o['subtotal'] ?? 0), 2),
                mb_substr((string) ($ent['type'] ?? 'entrega'), 0, 20),
                isset($ent['period']) ? mb_substr((string) $ent['period'], 0, 20) : null,
                mb_substr((string) ($pag['type'] ?? 'pix'), 0, 20),
                isset($pag['cardType']) ? mb_substr((string) $pag['cardType'], 0, 20) : null,
                isset($pag['change']) ? mb_substr((string) $pag['change'], 0, 60) : null,
                $criado,
            ]);
            $pedidoId = (int) $pdo->lastInsertId();

            foreach ($o['items'] as $it) {
                if (!is_array($it)) continue;
                $insItem->execute([
                    $pedidoId,
                    mb_substr((string) ($it['name'] ?? ''), 0, 150),
                    isset($it['variety']) && $it['variety'] !== '' ? mb_substr((string) $it['variety'], 0, 100) : null,
                    isset($it['cat']) ? mb_substr((string) $it['cat'], 0, 80) : null,
                    round((float) ($it['qty'] ?? 0), 3),
                    round((float) ($it['unitPrice'] ?? 0), 2),
                    round((float) ($it['lineTotal'] ?? 0), 2),
                ]);
            }
            $importados++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[pedidos.php importar] ' . $e->getMessage());
        falhar('Não foi possível importar os pedidos.', 500);
    }
    echo json_encode(['ok' => true, 'importados' => $importados]);
    exit;
}

// ---------------------------------------------------------------
// CRIAR pedido (cliente logado). Preços e totais vêm SEMPRE do banco.
// ---------------------------------------------------------------
if ($metodo === 'POST') {
    $usuarioId = exigirLogin();
    $d = json_decode(file_get_contents('php://input'), true);
    $d = is_array($d) ? $d : [];

    $itens = $d['items'] ?? null;
    if (!is_array($itens) || count($itens) === 0 || count($itens) > 100) {
        falhar('Carrinho vazio ou inválido.');
    }

    $ent = is_array($d['delivery'] ?? null) ? $d['delivery'] : [];
    $tipoEntrega = (string) ($ent['type'] ?? '');
    if (!in_array($tipoEntrega, ['entrega', 'retirada'], true)) falhar('Forma de recebimento inválida.');
    $periodo = null;
    if ($tipoEntrega === 'retirada') {
        $periodo = (string) ($ent['period'] ?? '');
        if (!in_array($periodo, ['manha', 'tarde'], true)) falhar('Informe o período da retirada.');
    }

    $pag = is_array($d['payment'] ?? null) ? $d['payment'] : [];
    $tipoPag = (string) ($pag['type'] ?? '');
    if (!in_array($tipoPag, ['cartao', 'dinheiro', 'pix'], true)) falhar('Forma de pagamento inválida.');
    $cartao = null;
    if ($tipoPag === 'cartao') {
        $cartao = (string) ($pag['cardType'] ?? '');
        if (!in_array($cartao, ['debito', 'credito'], true)) falhar('Informe se o cartão é débito ou crédito.');
    }
    $troco = null;
    if ($tipoPag === 'dinheiro') {
        $t = trim((string) ($pag['change'] ?? ''));
        $troco = $t !== '' ? mb_substr($t, 0, 60) : null;
    }

    // Produtos do carrinho, direto do banco
    $ids = [];
    foreach ($itens as $it) {
        $ids[] = is_array($it) ? (int) ($it['productId'] ?? 0) : 0;
    }
    $ids = array_values(array_unique($ids));
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM produtos WHERE ativo = 1 AND id IN ($marcas)");
    $stmt->execute($ids);
    $produtos = [];
    foreach ($stmt as $p) $produtos[(int) $p['id']] = $p;

    $linhas = [];
    $subtotal = 0.0;
    foreach ($itens as $it) {
        if (!is_array($it)) falhar('Item inválido no carrinho.');
        $p = $produtos[(int) ($it['productId'] ?? 0)] ?? null;
        if (!$p) falhar('Um dos produtos do carrinho não está mais disponível. Atualize a página e tente de novo.', 409);

        $qtd = (float) ($it['qty'] ?? 0);
        if (!is_finite($qtd) || $qtd <= 0 || $qtd > 1000) falhar('Quantidade inválida.');
        $qtd = round($qtd, 3);

        $preco = (float) $p['preco'];
        $varNome = null;
        $vn = isset($it['variety']) && is_string($it['variety']) ? $it['variety'] : '';
        if ($vn !== '') {
            $lista = !empty($p['variedades']) ? json_decode($p['variedades'], true) : [];
            $achou = null;
            foreach ((array) $lista as $v) {
                if (is_array($v) && ($v['name'] ?? null) === $vn) { $achou = $v; break; }
            }
            if (!$achou) falhar('Um dos tipos escolhidos não está mais disponível. Atualize a página e tente de novo.', 409);
            $varNome = $achou['name'];
            $pv = textoParaNumero($achou['price'] ?? '');
            if ($pv > 0) $preco = $pv;
        }

        $totalLinha = round($preco * $qtd, 2);
        $subtotal += $totalLinha;
        $linhas[] = [(int) $p['id'], $p['nome'], $varNome, $p['categoria'], $qtd, round($preco, 2), $totalLinha];
    }
    $subtotal = round($subtotal, 2);

    $stmt = $pdo->prepare('SELECT nome, usuario, telefone_enc, endereco_enc FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([$usuarioId]);
    $u = $stmt->fetch();
    if (!$u) falhar('Usuário não encontrado. Faça login novamente.', 401);

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO pedidos (usuario_id, cliente_nome, cliente_email, cliente_telefone_enc, cliente_endereco_enc, subtotal,
                                  entrega_tipo, entrega_periodo, pagamento_tipo, pagamento_cartao, pagamento_troco, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $usuarioId, $u['nome'], $u['usuario'], $u['telefone_enc'], $u['endereco_enc'], $subtotal,
            $tipoEntrega, $periodo, $tipoPag, $cartao, $troco, date('Y-m-d H:i:s'),
        ]);
        $pedidoId = (int) $pdo->lastInsertId();

        $insItem = $pdo->prepare(
            'INSERT INTO pedidos_itens (pedido_id, produto_id, nome, variedade, categoria, qtd, preco_unit, total_linha)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($linhas as $l) {
            $insItem->execute(array_merge([$pedidoId], $l));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[pedidos.php criar] ' . $e->getMessage());
        falhar('Não foi possível registrar o pedido.', 500);
    }

    echo json_encode(['ok' => true, 'id' => $pedidoId, 'subtotal' => $subtotal]);
    exit;
}

// ---------------------------------------------------------------
// LISTAR pedidos (admin) — mesmo formato que relatorio.html já usava
// ---------------------------------------------------------------
if ($metodo === 'GET') {
    exigirAdmin();
    header('Cache-Control: no-store');
    $pedidos = $pdo->query('SELECT * FROM pedidos ORDER BY criado_em DESC, id DESC LIMIT 1000')->fetchAll();
    if (!$pedidos) { echo '[]'; exit; }

    $ids = array_map(fn($p) => (int) $p['id'], $pedidos);
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM pedidos_itens WHERE pedido_id IN ($marcas) ORDER BY id");
    $stmt->execute($ids);
    $itensPorPedido = [];
    foreach ($stmt as $i) {
        $itensPorPedido[(int) $i['pedido_id']][] = [
            'productId' => $i['produto_id'] !== null ? (int) $i['produto_id'] : null,
            'name'      => $i['nome'],
            'variety'   => $i['variedade'],
            'cat'       => $i['categoria'],
            'qty'       => (float) $i['qtd'],
            'unitPrice' => (float) $i['preco_unit'],
            'lineTotal' => (float) $i['total_linha'],
        ];
    }

    $saida = [];
    foreach ($pedidos as $p) {
        $end = dividirEndereco(descriptografar($p['cliente_endereco_enc']));
        $saida[] = [
            'id'       => (int) $p['id'],
            'date'     => date('c', strtotime($p['criado_em'])),
            'subtotal' => (float) $p['subtotal'],
            'items'    => $itensPorPedido[(int) $p['id']] ?? [],
            'delivery' => ['type' => $p['entrega_tipo'], 'period' => $p['entrega_periodo']],
            'payment'  => ['type' => $p['pagamento_tipo'], 'cardType' => $p['pagamento_cartao'], 'change' => $p['pagamento_troco']],
            'customer' => [
                'name'         => $p['cliente_nome'],
                'email'        => $p['cliente_email'],
                'phone'        => descriptografar($p['cliente_telefone_enc']) ?? '',
                'address'      => $end['endereco'],
                'number'       => $end['numero'],
                'neighborhood' => $end['bairro'],
            ],
        ];
    }
    echo json_encode($saida, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------
// EXCLUIR pedido (admin): ?id=N  ou  ?todos=1
// ---------------------------------------------------------------
if ($metodo === 'DELETE') {
    exigirAdmin();
    if (!empty($_GET['todos'])) {
        $pdo->exec('DELETE FROM pedidos');
        echo json_encode(['ok' => true]);
        exit;
    }
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) falhar('Pedido inválido.');
    $pdo->prepare('DELETE FROM pedidos WHERE id = ?')->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['erro' => 'Método não permitido.']);
