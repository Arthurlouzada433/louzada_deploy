-- ============================================================
-- BANCO DE DADOS — Louzada Frutas & Legumes
-- Importe este arquivo pelo phpMyAdmin: aba "Importar" -> escolher arquivo -> Executar
-- ============================================================

CREATE TABLE IF NOT EXISTS usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tipo ENUM('admin','cliente') NOT NULL DEFAULT 'cliente',
  usuario VARCHAR(100) NOT NULL UNIQUE,       -- login (nome de usuário ou e-mail)
  nome VARCHAR(150) NOT NULL,                 -- nome de exibição (não é sigiloso)
  senha_hash VARCHAR(255) NOT NULL,           -- hash da senha (bcrypt), NUNCA a senha em texto puro
  telefone_enc VARBINARY(512) NULL,           -- telefone criptografado (AES-256-GCM)
  endereco_enc VARBINARY(1024) NULL,          -- endereço criptografado (AES-256-GCM)
  tentativas_login INT NOT NULL DEFAULT 0,
  bloqueado_ate DATETIME NULL,                -- bloqueio após 5 tentativas erradas
  permissoes TEXT NULL,                       -- JSON com as permissões do admin (ex: ["pedidos","produtos"])
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Se o banco já existia antes desta coluna ser adicionada, rode esta linha uma vez:
-- ALTER TABLE usuarios ADD COLUMN permissoes TEXT NULL AFTER bloqueado_ate;

CREATE TABLE IF NOT EXISTS produtos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(150) NOT NULL,
  categoria VARCHAR(80) NULL,
  descricao VARCHAR(255) NOT NULL DEFAULT '',
  badge VARCHAR(60) NOT NULL DEFAULT '',        -- selo do card (ex: 'Mais vendido')
  preco DECIMAL(10,2) NOT NULL DEFAULT 0,
  unidade VARCHAR(30) NOT NULL DEFAULT '/unid.', -- ex: /kg, /bandeja, /unid.
  estoque DECIMAL(10,2) NOT NULL DEFAULT 0,
  em_oferta TINYINT(1) NOT NULL DEFAULT 0,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  imagem TEXT NULL,
  variedades TEXT NULL,                          -- JSON: [{"name":"Nanica","price":"5,49","img":"..."}]
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  atualizado_por INT NULL,
  FOREIGN KEY (atualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Histórico: toda alteração em um produto vira uma linha aqui (auditoria)
CREATE TABLE IF NOT EXISTS produtos_historico (
  id INT AUTO_INCREMENT PRIMARY KEY,
  produto_id INT NOT NULL,
  campo_alterado VARCHAR(50) NOT NULL,   -- ex: 'preco', 'estoque', 'nome'
  valor_antigo TEXT NULL,
  valor_novo TEXT NULL,
  alterado_por INT NULL,                 -- usuário (admin) que fez a alteração
  alterado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE,
  FOREIGN KEY (alterado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_historico_produto ON produtos_historico(produto_id);
CREATE INDEX idx_produtos_ativo ON produtos(ativo);

-- Recuperação de senha por código no WhatsApp
CREATE TABLE IF NOT EXISTS recuperacoes_senha (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  codigo_hash CHAR(64) NOT NULL,          -- SHA-256 do código de 6 dígitos (o código em si não é guardado)
  token_hash CHAR(64) NULL,               -- SHA-256 do token liberado após acertar o código
  tentativas INT NOT NULL DEFAULT 0,
  expira_em DATETIME NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_recuperacoes_usuario ON recuperacoes_senha(usuario_id);

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

-- Produtos iniciais da vitrine

INSERT INTO produtos (nome, categoria, descricao, badge, preco, unidade, em_oferta, imagem, variedades)
SELECT 'Maçã Fuji', 'frutas', 'Crocante e docinha', 'Mais vendido', 8.99, '/kg', 0, 'https://images.unsplash.com/photo-1560806887-1e4cd0b6cbd6?q=80&w=600&auto=format&fit=crop', NULL
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM produtos WHERE nome = 'Maçã Fuji' AND ativo = 1);

INSERT INTO produtos (nome, categoria, descricao, badge, preco, unidade, em_oferta, imagem, variedades)
SELECT 'Morango', 'frutas', 'Bandeja selecionada', '', 12.90, '/bandeja', 0, 'https://images.unsplash.com/photo-1518635017498-87f514b751ba?q=80&w=600&auto=format&fit=crop', NULL
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM produtos WHERE nome = 'Morango' AND ativo = 1);

INSERT INTO produtos (nome, categoria, descricao, badge, preco, unidade, em_oferta, imagem, variedades)
SELECT 'Maracujá', 'frutas', 'Perfeito para sucos', 'Da estação', 9.49, '/kg', 0, 'https://images.unsplash.com/photo-1615485500704-8e990f9900f7?q=80&w=600&auto=format&fit=crop', NULL
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM produtos WHERE nome = 'Maracujá' AND ativo = 1);

INSERT INTO produtos (nome, categoria, descricao, badge, preco, unidade, em_oferta, imagem, variedades)
SELECT 'Cesta Orgânica', 'cestas', 'Seleção da semana', '', 39.90, '/cesta', 1, 'https://images.unsplash.com/photo-1610832958506-aa56368176cf?q=80&w=600&auto=format&fit=crop', NULL
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM produtos WHERE nome = 'Cesta Orgânica' AND ativo = 1);

INSERT INTO produtos (nome, categoria, descricao, badge, preco, unidade, em_oferta, imagem, variedades)
SELECT 'Alface Crespa', 'verduras', 'Colhida na hora', '', 3.49, '/unid.', 0, 'https://images.unsplash.com/photo-1622206151226-18ca2c9d680b?q=80&w=600&auto=format&fit=crop', NULL
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM produtos WHERE nome = 'Alface Crespa' AND ativo = 1);

INSERT INTO produtos (nome, categoria, descricao, badge, preco, unidade, em_oferta, imagem, variedades)
SELECT 'Tomate Italiano', 'legumes', 'Maduro no ponto', '', 6.90, '/kg', 0, 'https://images.unsplash.com/photo-1592924357228-91a4daadcfea?q=80&w=600&auto=format&fit=crop', NULL
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM produtos WHERE nome = 'Tomate Italiano' AND ativo = 1);

INSERT INTO produtos (nome, categoria, descricao, badge, preco, unidade, em_oferta, imagem, variedades)
SELECT 'Cenoura', 'legumes', 'Doce e crocante', '', 4.29, '/kg', 0, 'https://images.unsplash.com/photo-1447175008436-054170c2e979?q=80&w=600&auto=format&fit=crop', NULL
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM produtos WHERE nome = 'Cenoura' AND ativo = 1);

INSERT INTO produtos (nome, categoria, descricao, badge, preco, unidade, em_oferta, imagem, variedades)
SELECT 'Banana', 'frutas', 'Direto do produtor', '', 5.49, '/kg', 0, 'https://images.unsplash.com/photo-1571771894821-ce9b6c11b08e?q=80&w=600&auto=format&fit=crop', '[{"name":"Nanica","price":"5,49","img":"https://images.unsplash.com/photo-1571771894821-ce9b6c11b08e?q=80&w=200&auto=format&fit=crop"},{"name":"Prata","price":"5,99","img":"https://images.unsplash.com/photo-1571771894821-ce9b6c11b08e?q=80&w=200&auto=format&fit=crop"},{"name":"Terra","price":"6,49","img":"https://images.unsplash.com/photo-1571771894821-ce9b6c11b08e?q=80&w=200&auto=format&fit=crop"}]'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM produtos WHERE nome = 'Banana' AND ativo = 1);

