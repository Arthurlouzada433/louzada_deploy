<?php
require_once __DIR__ . '/../config.php';

session_start();
aplicarCors();
$pdo = conectarBanco();

if (($_SESSION['tipo'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['erro' => 'Apenas o administrador pode ver o histórico.']);
    exit;
}

$produto_id = (int)($_GET['produto_id'] ?? 0);

$sql = 'SELECT h.*, u.nome AS alterado_por_nome
        FROM produtos_historico h
        LEFT JOIN usuarios u ON u.id = h.alterado_por';
$params = [];
if ($produto_id > 0) {
    $sql .= ' WHERE h.produto_id = ?';
    $params[] = $produto_id;
}
$sql .= ' ORDER BY h.alterado_em DESC LIMIT 500';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll());
