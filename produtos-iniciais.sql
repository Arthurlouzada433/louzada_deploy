-- ============================================================
-- PRODUTOS INICIAIS DA VITRINE (os mesmos que estavam no index.html)
-- Rode no phpMyAdmin (aba "Importar" ou "SQL"). Pode rodar mais de uma vez:
-- cada produto só entra se ainda não existir um produto ativo com o mesmo nome.
-- ============================================================
SET NAMES utf8mb4;

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

