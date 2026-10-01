# Banco de dados — Louzada Frutas & Legumes

## O que isto guarda
- **produtos**: catálogo atual (nome, preço, estoque, oferta, etc.)
- **produtos_historico**: toda alteração feita em um produto (quem mudou, o que mudou, valor antigo → novo, quando)
- **usuarios**: administradores e clientes do site
  - senha: guardada como **hash** (bcrypt) — irreversível, nem o administrador consegue "ver" a senha de ninguém
  - telefone e endereço: guardados **criptografados** (AES-256-GCM) — só a API, com a chave do servidor, consegue ler de volta

## Passo a passo para instalar

1. **Criar o banco**: no phpMyAdmin, crie um banco de dados (ex: `louzada`), abra a aba **Importar** e envie o arquivo `schema.sql`.

2. **Configurar `config.php`**:
   - Preencha `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` com os dados do seu banco (a hospedagem mostra isso no painel, perto de onde você achou o phpMyAdmin).
   - Gere a chave de criptografia. Se sua hospedagem tiver acesso a terminal/SSH, rode:
     ```
     php -r "echo bin2hex(random_bytes(32));"
     ```
     Se não tiver terminal, me avise que eu gero uma chave segura para você aqui.
   - Cole o resultado em `CHAVE_CRIPTOGRAFIA_HEX`.
   - Em `ORIGENS_PERMITIDAS`, coloque o endereço do seu site (ex: `https://louzadafrutas.com.br`).

3. **Enviar os arquivos**: suba `config.php`, `crypto.php` e a pasta `api/` para o servidor. **Idealmente `config.php` e `crypto.php` ficam fora da pasta pública** (fora de `public_html`), com um `require` apontando pra lá — assim ninguém consegue baixá-los pelo navegador. Se sua hospedagem só permite uma pasta pública, me avise que ajusto os caminhos.

4. **Criar o primeiro administrador**: edite `criar-primeiro-admin.php` (usuário, nome e senha forte), suba pro servidor, abra a URL dele **uma vez** no navegador, e depois **apague esse arquivo do servidor**.

5. **Testar**: acesse `api/auth.php?acao=status` no navegador — deve responder `{"logado":false,"tipo":null}`.

## Estado atual das páginas
- `pedidos.html` (login do administrador), `index.html` (vitrine, login/cadastro/recuperação e finalização do pedido) e `relatorio.html` (painel) conversam com a API em `api/`.
- **Produtos** ficam na tabela `produtos` (`api/produtos.php`): o que o admin cadastra/edita no painel aparece para todos os clientes. Toda alteração é registrada em `produtos_historico`.
- **Pedidos** ficam nas tabelas `pedidos` e `pedidos_itens` (`api/pedidos.php`). O servidor recalcula preços e totais a partir do banco e pega os dados do cliente da conta logada; telefone e endereço são guardados criptografados.
- Se o admin ainda tinha produtos/pedidos salvos só no navegador (versão antiga), ao abrir `relatorio.html` o site oferece enviá-los ao banco uma única vez.

## Migração para quem já tem o banco no ar
1. No phpMyAdmin, aba **Importar**, rode `migracao-produtos-pedidos.sql` **uma única vez** (cria as colunas/tabelas novas e cadastra os produtos iniciais se a tabela estiver vazia).
2. Suba os arquivos novos/alterados: `config.php`, `api/produtos.php`, `api/pedidos.php`, `index.html`, `relatorio.html`.
3. Abra `relatorio.html` logado como admin; se aparecer o aviso de importação, confirme.
Instalação nova: basta importar `schema.sql` (já inclui tudo).

## Produtos iniciais da vitrine
- `produtos-iniciais.sql` cadastra no banco os 8 produtos que estavam fixos no `index.html` (Maçã Fuji, Morango, Maracujá, Cesta Orgânica, Alface Crespa, Tomate Italiano, Cenoura, Banana com variedades).
- Pode rodar quantas vezes quiser no phpMyAdmin: só insere o que ainda não existe (mesmo nome, ativo).
- Instalação nova: `schema.sql` já inclui esses produtos. Banco já no ar: rode só `produtos-iniciais.sql`.
- Depois, o admin ajusta preço, estoque e fotos em `relatorio.html`.

## Antes de publicar (checklist)
1. `SENHA_PEPPER_HEX` no `config.php` precisa estar preenchido (sem ele, login e cadastro dão erro). Depois que houver usuários, **nunca troque** e guarde uma cópia.
2. Para o e-mail de recuperação de senha funcionar, preencha `GOOGLE_REFRESH_TOKEN` no `config.php`.
3. Não envie para o servidor: `schema.sql`, `migracao-*.sql`, `LEIA-ME.md`. O `.htaccess` deste pacote bloqueia o acesso direto a eles e a `config.php`, `crypto.php`, `email.php` e `whatsapp.php`.
4. Depois de criar o primeiro admin, **apague** `criar-primeiro-admin.php` do servidor.

## Arquivos deste pacote
| Arquivo | Função |
|---|---|
| `schema.sql` | Cria as 3 tabelas no banco |
| `config.php` | Conexão com o banco + chave de criptografia (preencher) |
| `crypto.php` | Funções de criptografar/descriptografar e hash de senha |
| `api/auth.php` | Login, logout, bloqueio após 5 tentativas erradas |
| `api/usuarios.php` | Cadastro de clientes; criar/editar/excluir/listar administradores (admin) |
| `api/recuperar-senha.php` | Recuperação de senha por código enviado por e-mail |
| `email.php` / `whatsapp.php` | Envio de e-mail (Gmail API) e WhatsApp (Z-API) |
| `api/produtos.php` | Listar (público) e criar/editar/excluir produtos (admin) |
| `api/pedidos.php` | Criar pedido (cliente logado); listar/excluir/importar (admin) |
| `migracao-produtos-pedidos.sql` | Atualiza um banco existente para produtos e pedidos no banco |
| `api/historico.php` | Consultar o histórico de alterações |
| `criar-primeiro-admin.php` | Rodar 1 vez para criar o admin, depois apagar |
