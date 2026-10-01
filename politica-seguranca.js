/* ==========================================================
   Louzada Frutas & Legumes — Política de Segurança (aceite único)
   Incluir nas páginas com:  <script src="politica-seguranca.js"></script>
   - Aparece na 1ª visita (index.html ou pedidos.html).
   - A pessoa marca "Li e concordo" e clica em "Confirmar".
   - O aceite fica salvo neste aparelho/navegador e o aviso não volta mais.
   - Se mudar o texto da política, troque POLITICA_VERSAO para pedir novo aceite.
   ========================================================== */
(function () {
  'use strict';

  var POLITICA_VERSAO = '1';
  var CHAVE = 'louzada-politica-seguranca';

  function jaAceitou() {
    try {
      var raw = localStorage.getItem(CHAVE);
      if (!raw) return false;
      var dado = JSON.parse(raw);
      return !!(dado && dado.aceito === true && dado.versao === POLITICA_VERSAO);
    } catch (e) { return false; }
  }

  function salvarAceite() {
    try {
      localStorage.setItem(CHAVE, JSON.stringify({
        aceito: true,
        versao: POLITICA_VERSAO,
        data: new Date().toISOString()
      }));
    } catch (e) { /* navegador bloqueou o armazenamento: o aviso volta na próxima visita */ }
  }

  if (jaAceitou()) return;

  var CSS = '' +
    '.ps-overlay{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;' +
      'padding:16px;background:rgba(14,26,16,.72);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);}' +
    '.ps-box{width:100%;max-width:560px;max-height:92vh;display:flex;flex-direction:column;overflow:hidden;' +
      'background:var(--cream,#FBF7EC);color:var(--ink,#22271D);border-radius:22px;' +
      'box-shadow:0 30px 80px -20px rgba(0,0,0,.55);font-family:"Plus Jakarta Sans",system-ui,sans-serif;}' +
    '.ps-head{padding:22px 24px 14px;border-bottom:1px solid var(--line,rgba(30,68,41,.14));}' +
    '.ps-head h2{margin:0;font-family:"Fraunces",Georgia,serif;font-weight:600;font-size:24px;line-height:1.15;' +
      'color:var(--forest-2,var(--forest,#1E4429));}' +
    '.ps-head p{margin:6px 0 0;font-size:13.5px;color:var(--ink-soft,#5B6156);}' +
    '.ps-body{padding:16px 24px;overflow-y:auto;-webkit-overflow-scrolling:touch;font-size:14px;line-height:1.55;}' +
    '.ps-item{display:flex;gap:12px;margin-bottom:14px;}' +
    '.ps-item:last-child{margin-bottom:0;}' +
    '.ps-num{flex:none;width:26px;height:26px;border-radius:50%;background:var(--orange,#EE7B10);color:#fff;' +
      'font-weight:800;font-size:13px;display:flex;align-items:center;justify-content:center;margin-top:1px;}' +
    '.ps-item b{display:block;color:var(--forest-2,var(--forest,#1E4429));margin-bottom:2px;}' +
    '.ps-item span{color:var(--ink-soft,#5B6156);}' +
    '.ps-aviso{margin-top:16px;padding:12px 14px;border-radius:14px;font-size:13px;' +
      'background:rgba(238,123,16,.12);border:1px solid rgba(238,123,16,.35);color:var(--ink,#22271D);}' +
    '.ps-foot{padding:16px 24px 20px;border-top:1px solid var(--line,rgba(30,68,41,.14));' +
      'background:var(--card,#fff);}' +
    '.ps-check{display:flex;align-items:flex-start;gap:12px;cursor:pointer;font-size:14px;font-weight:600;' +
      'line-height:1.4;margin-bottom:14px;user-select:none;}' +
    '.ps-check input{flex:none;width:22px;height:22px;margin:0;cursor:pointer;accent-color:var(--orange,#EE7B10);}' +
    '.ps-btn{width:100%;padding:14px 20px;border:none;border-radius:999px;font-family:inherit;font-size:15px;' +
      'font-weight:800;cursor:pointer;background:var(--orange,#EE7B10);color:#fff;transition:.2s;' +
      'box-shadow:0 10px 24px -8px rgba(238,123,16,.55);}' +
    '.ps-btn:hover:not(:disabled){transform:translateY(-2px);}' +
    '.ps-btn:disabled{opacity:.45;cursor:not-allowed;box-shadow:none;}' +
    '.ps-btn:focus-visible,.ps-check input:focus-visible{outline:3px solid var(--forest,#1E4429);outline-offset:2px;}' +
    'html.ps-lock,html.ps-lock body{overflow:hidden;}' +
    '@media(max-width:480px){.ps-head{padding:18px 18px 12px}.ps-body{padding:14px 18px}.ps-foot{padding:14px 18px 18px}' +
      '.ps-head h2{font-size:21px}}';

  var ITENS = [
    ['Antivírus sempre atualizado',
     'Use um antivírus ativo e com as definições em dia no celular ou computador que você usa para acessar o site. ' +
     'Mantenha também o sistema e o navegador atualizados.'],
    ['Senha forte',
     'Sua senha deve ter no mínimo 8 caracteres, com letras MAIÚSCULAS e minúsculas, números e caracteres ' +
     'especiais (como ! @ # $ %). Evite datas de aniversário, nomes e sequências como 123456.'],
    ['Senha é pessoal',
     'Não compartilhe sua senha com ninguém e não use a mesma senha de outros sites ou do seu e-mail. ' +
     'A Louzada nunca pede sua senha por WhatsApp, telefone ou e-mail.'],
    ['Cuidado com redes públicas',
     'Evite fazer pedidos ou acessar sua conta em Wi-Fi público ou aparelhos de terceiros. ' +
     'Se usar um aparelho compartilhado, saia da sua conta ao terminar.'],
    ['Confira antes de enviar',
     'Desconfie de links recebidos de desconhecidos e confira o endereço do site. ' +
     'Revise seu pedido, endereço e bairro antes de enviar pelo WhatsApp.'],
    ['Seus dados',
     'Usamos seu nome, endereço e telefone somente para separar, entregar e confirmar seus pedidos.']
  ];

  function montar() {
    if (document.getElementById('ps-overlay')) return;

    var style = document.createElement('style');
    style.textContent = CSS;
    document.head.appendChild(style);

    var itensHtml = ITENS.map(function (it, i) {
      return '<div class="ps-item"><div class="ps-num">' + (i + 1) + '</div>' +
             '<div><b>' + it[0] + '</b><span>' + it[1] + '</span></div></div>';
    }).join('');

    var overlay = document.createElement('div');
    overlay.className = 'ps-overlay';
    overlay.id = 'ps-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'ps-titulo');
    overlay.innerHTML =
      '<div class="ps-box">' +
        '<div class="ps-head">' +
          '<h2 id="ps-titulo">Política de Segurança</h2>' +
          '<p>Leia com atenção antes de continuar. Isso aparece só uma vez neste aparelho.</p>' +
        '</div>' +
        '<div class="ps-body">' + itensHtml +
          '<div class="ps-aviso"><b>Importante:</b> se essas medidas de segurança não forem seguidas, ' +
          'a Louzada Frutas &amp; Legumes não se responsabiliza por problemas causados por ' +
          'vírus, invasão de conta ou uso indevido da sua senha, dentro do que a lei permitir.</div>' +
        '</div>' +
        '<div class="ps-foot">' +
          '<label class="ps-check" for="ps-li">' +
            '<input type="checkbox" id="ps-li">' +
            '<span>Li e concordo com a Política de Segurança</span>' +
          '</label>' +
          '<button type="button" class="ps-btn" id="ps-confirmar" disabled>Confirmar</button>' +
        '</div>' +
      '</div>';

    document.body.appendChild(overlay);
    document.documentElement.classList.add('ps-lock');

    var check = document.getElementById('ps-li');
    var botao = document.getElementById('ps-confirmar');

    check.addEventListener('change', function () { botao.disabled = !check.checked; });

    botao.addEventListener('click', function () {
      if (!check.checked) return;
      salvarAceite();
      document.documentElement.classList.remove('ps-lock');
      overlay.remove();
      document.removeEventListener('keydown', prender, true);
    });

    // Mantém o foco dentro da janela (não dá pra usar o site por trás sem aceitar)
    function prender(e) {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); return; }
      if (e.key !== 'Tab') return;
      var foc = [check, botao].filter(function (el) { return !el.disabled; });
      var pos = foc.indexOf(document.activeElement);
      e.preventDefault();
      if (e.shiftKey) foc[(pos <= 0 ? foc.length : pos) - 1].focus();
      else foc[(pos + 1) % foc.length].focus();
    }
    document.addEventListener('keydown', prender, true);

    check.focus();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', montar);
  else montar();
})();
