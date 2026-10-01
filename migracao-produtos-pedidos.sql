-- ============================================================
-- MIGRAÇÃO: produtos e pedidos passam a viver no banco de dados
-- Rode UMA ÚNICA VEZ no phpMyAdmin (aba "Importar"), em um banco que já tem o schema antigo.
-- Se rodar de novo, o ALTER TABLE dará erro de "coluna já existe" — é só ignorar.
-- ============================================================
SET NAMES utf8mb4;

ALTER TABLE produtos
  ADD COLUMN descricao VARCHAR(255) NOT NULL DEFAULT '' AFTER categoria,
  ADD COLUMN badge VARCHAR(60) NOT NULL DEFAULT '' AFTER descricao,
  ADD COLUMN imagem TEXT NULL AFTER badge,
  ADD COLUMN variedades TEXT NULL AFTER imagem,
  MODIFY COLUMN unidade VARCHAR(30) NOT NULL DEFAULT '/unid.';

-- Pedidos feitos pelos clientes (telefone e endereço criptografados, como em usuarios)
CREATE TABLE IF NOT EXISTS pedidos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NULL,
  cliente_nome VARCHAR(150) NOT NULL,
  cliente_email VARCHAR(100) NOT NULL,
  cliente_telefone_enc VARBINARY(512) NULL,
  cliente_endereco_enc VARBINARY(1024) NULL,
  subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
  entrega_tipo VARCHAR(20) NOT NULL,          -- entrega | retirada
  entrega_periodo VARCHAR(20) NULL,           -- manha | tarde (só retirada)
  pagamento_tipo VARCHAR(20) NOT NULL,        -- cartao | dinheiro | pix
  pagamento_cartao VARCHAR(20) NULL,          -- debito | credito
  pagamento_troco VARCHAR(60) NULL,
  criado_em DATETIME NOT NULL,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_pedidos_criado ON pedidos(criado_em);

CREATE TABLE IF NOT EXISTS pedidos_itens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pedido_id INT NOT NULL,
  produto_id INT NULL,
  nome VARCHAR(150) NOT NULL,
  variedade VARCHAR(100) NULL,
  categoria VARCHAR(80) NULL,
  qtd DECIMAL(10,3) NOT NULL,
  preco_unit DECIMAL(10,2) NOT NULL,
  total_linha DECIMAL(10,2) NOT NULL,
  FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
  FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_itens_pedido ON pedidos_itens(pedido_id);

-- Produtos iniciais da vitrine (só entram se a tabela de produtos estiver vazia)
INSERT INTO produtos (nome, categoria, descricao, badge, preco, unidade, em_oferta, imagem, variedades)
SELECT * FROM (
  SELECT 'Maçã Fuji' AS nome, 'frutas' AS categoria, 'Crocante e docinha' AS descricao, 'Mais vendido' AS badge, 8.99 AS preco, '/kg' AS unidade, 0 AS em_oferta, 'https://images.unsplash.com/photo-1560806887-1e4cd0b6cbd6?q=80&w=600&auto=format&fit=crop' AS imagem, NULL AS variedades
  UNION ALL SELECT 'Morango', 'frutas', 'Bandeja selecionada', '', 12.90, '/bandeja', 0, 'https://images.unsplash.com/photo-1518635017498-87f514b751ba?q=80&w=600&auto=format&fit=crop', NULL
  UNION ALL SELECT 'Maracujá', 'frutas', 'Perfeito para sucos', 'Da estação', 9.49, '/kg', 0, 'https://images.unsplash.com/photo-1615485500704-8e990f9900f7?q=80&w=600&auto=format&fit=crop', NULL
  UNION ALL SELECT 'Cesta Orgânica', 'cestas', 'Seleção da semana', '', 39.90, '/cesta', 1, 'https://images.unsplash.com/photo-1610832958506-aa56368176cf?q=80&w=600&auto=format&fit=crop', NULL
  UNION ALL SELECT 'Alface Crespa', 'verduras', 'Colhida na hora', '', 3.49, '/unid.', 0, 'https://images.unsplash.com/photo-1622206151226-18ca2c9d680b?q=80&w=600&auto=format&fit=crop', NULL
  UNION ALL SELECT 'Tomate Italiano', 'legumes', 'Maduro no ponto', '', 6.90, '/kg', 0, 'https://images.unsplash.com/photo-1592924357228-91a4daadcfea?q=80&w=600&auto=format&fit=crop', NULL
  UNION ALL SELECT 'Cenoura', 'legumes', 'Doce e crocante', '', 4.29, '/kg', 0, 'https://images.unsplash.com/photo-1447175008436-054170c2e979?q=80&w=600&auto=format&fit=crop', NULL
  UNION ALL SELECT 'Banana', 'frutas', 'Direto do produtor', '', 5.49, '/kg', 0, 'https://images.unsplash.com/photo-1571771894821-ce9b6c11b08e?q=80&w=600&auto=format&fit=crop', '[{"name":"Nanica","price":"5,49","img":"https://images.unsplash.com/photo-1571771894821-ce9b6c11b08e?q=80&w=200&auto=format&fit=crop"},{"name":"Prata","price":"5,99","img":"https://images.unsplash.com/photo-1571771894821-ce9b6c11b08e?q=80&w=200&auto=format&fit=crop"},{"name":"Terra","price":"6,49","img":"https://images.unsplash.com/photo-1571771894821-ce9b6c11b08e?q=80&w=200&auto=format&fit=crop"}]'
) AS semente
WHERE NOT EXISTS (SELECT 1 FROM produtos);
