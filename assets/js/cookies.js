/* ===== Dext · aviso de cookies e porteiro do Analytics =====

   A Política de Privacidade promete que o visitante vê um aviso e que o
   Analytics só liga se ele aceitar. Então o Analytics não pode carregar
   sozinho no <head>: quem decide se ele carrega é este arquivo.

   Por que um arquivo só, fora da página, num projeto em que tudo é inline:
   a escolha do visitante vale para o site inteiro. Se cada página tivesse a
   sua cópia, bastaria uma ficar para trás numa edição para o site aceitar
   numa página o que recusou na outra, e o erro seria invisível.

   O aviso aparece para todo mundo, não só para quem está na Europa. O fuso
   horário era um palpite: errava com VPN e com relógio trocado, e a frase
   publicada na Política continua verdadeira perguntando a mais gente.

   Consent Mode v2: os quatro sinais entram negados antes de qualquer tag
   existir. Quando o Pixel da Meta e o Google Ads entrarem, obedecem ao
   mesmo aviso sem precisar de código novo aqui.

   window.gtag existe sempre, mesmo sem consentimento, porque o resto do
   site chama gtag() direto. Sem consentimento ele engole a chamada em vez
   de estourar, e nada é enviado nem fica guardado para enviar depois. */

(function () {
  'use strict';

  var MEDIDA = 'G-BF7BPXKE4Z';
  var CHAVE  = 'dext_cookies';              // 'sim|<quando>' ou 'nao|<quando>'
  var SEIS_MESES = 183 * 24 * 60 * 60 * 1000;

  function guarda(v) {
    try { localStorage.setItem(CHAVE, v + '|' + Date.now()); } catch (e) {}
  }

  /* A escolha vale 6 meses. Depois disso o aviso volta, porque consentimento
     guardado para sempre deixa de ser escolha e vira cadastro. */
  function le() {
    var bruto = null;
    try { bruto = localStorage.getItem(CHAVE); } catch (e) { return null; }
    if (!bruto) return null;

    var parte = bruto.split('|');
    var valor = parte[0];
    var quando = Number(parte[1] || 0);
    if (valor !== 'sim' && valor !== 'nao') return null;

    /* escolha feita antes de existir data guardada: vale, e os 6 meses
       passam a contar de hoje */
    if (!quando) { guarda(valor); return valor; }

    if (Date.now() - quando > SEIS_MESES) return null;
    return valor;
  }

  /* ---- Analytics ---- */
  window.dataLayer = window.dataLayer || [];

  /* sinal() entra na fila sempre, com ou sem consentimento: é por ele que o
     Consent Mode fala. gtag() é o que o resto do site usa, e esse fica mudo
     até o visitante aceitar. */
  function sinal() { window.dataLayer.push(arguments); }

  sinal('consent', 'default', {
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    analytics_storage: 'denied'
  });

  window.gtag = window.gtag || function () {
    if (window.__dextGA) { window.dataLayer.push(arguments); }
  };

  function ligaAnalytics() {
    if (window.__dextGA) return;
    window.__dextGA = true;
    sinal('consent', 'update', { analytics_storage: 'granted' });
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + MEDIDA;
    document.head.appendChild(s);
    gtag('js', new Date());
    gtag('config', MEDIDA);
  }

  /* ---- o aviso ---- */
  var TEXTO = {
    pt: {
      corpo: 'Usamos cookies do Google Analytics para entender como o site é usado. Eles só ligam se você aceitar.',
      sim: 'Aceitar', nao: 'Recusar', mais: 'Saber mais'
    },
    en: {
      corpo: 'We use Google Analytics cookies to understand how the site is used. They only turn on if you accept.',
      sim: 'Accept', nao: 'Decline', mais: 'Learn more'
    }
  };

  function idioma() {
    var l = '';
    try { l = new URLSearchParams(location.search).get('lang') || localStorage.getItem('dext_lang') || ''; } catch (e) {}
    return l === 'en' ? 'en' : 'pt';
  }

  var ESTILO = ''
    + '#dext-cookies{position:fixed;left:0;right:0;bottom:0;z-index:9999;background:#161616;'
    + 'border-top:1px solid #262626;box-shadow:0 -10px 30px rgba(0,0,0,.45);'
    + 'font-family:Montserrat,system-ui,sans-serif;font-weight:300;color:#dddddd;'
    + 'transform:translateY(100%);transition:transform .35s ease}'
    + '#dext-cookies.ver{transform:translateY(0)}'
    + '#dext-cookies .dentro{max-width:1100px;margin:0 auto;padding:20px 24px;display:flex;'
    + 'align-items:center;gap:26px;flex-wrap:wrap;justify-content:space-between}'
    + '#dext-cookies p{margin:0;font-size:14.5px;line-height:1.6;max-width:620px}'
    + '#dext-cookies a{color:#9a9a9a;text-decoration:underline}'
    + '#dext-cookies a:hover{color:#fff}'
    + '#dext-cookies .bts{display:flex;gap:10px;flex-wrap:wrap}'
    /* os dois botões têm o mesmo tamanho e o mesmo peso de propósito: recusar
       não pode ser mais difícil de achar do que aceitar */
    + '#dext-cookies button{font-family:inherit;font-size:12px;font-weight:600;letter-spacing:.09em;'
    + 'text-transform:uppercase;padding:12px 24px;border-radius:3px;cursor:pointer;transition:.25s;'
    + 'border:1.5px solid transparent;white-space:nowrap}'
    + '#dext-cookies .sim{background:#e81840;color:#fff}'
    + '#dext-cookies .sim:hover{background:#c91234}'
    + '#dext-cookies .nao{background:none;border-color:#555;color:#dddddd}'
    + '#dext-cookies .nao:hover{border-color:#dddddd}'
    + '@media(max-width:700px){#dext-cookies .dentro{gap:16px}#dext-cookies .bts{width:100%}'
    + '#dext-cookies button{flex:1}}'
    + '@media print{#dext-cookies{display:none}}';

  var caixa = null;

  function monta() {
    if (caixa) { caixa.classList.add('ver'); return; }
    var t = TEXTO[idioma()];

    var css = document.createElement('style');
    css.textContent = ESTILO;
    document.head.appendChild(css);

    caixa = document.createElement('div');
    caixa.id = 'dext-cookies';
    caixa.setAttribute('role', 'dialog');
    caixa.setAttribute('aria-label', t.mais);

    var dentro = document.createElement('div');
    dentro.className = 'dentro';

    var p = document.createElement('p');
    p.appendChild(document.createTextNode(t.corpo + ' '));
    var a = document.createElement('a');
    a.href = '/privacidade#c6';
    a.textContent = t.mais;
    p.appendChild(a);

    var bts = document.createElement('div');
    bts.className = 'bts';
    var nao = document.createElement('button');
    nao.type = 'button'; nao.className = 'nao'; nao.textContent = t.nao;
    var sim = document.createElement('button');
    sim.type = 'button'; sim.className = 'sim'; sim.textContent = t.sim;

    /* nenhum dos dois vem marcado: a escolha só existe depois do clique */
    nao.addEventListener('click', function () { guarda('nao'); esconde(); });
    sim.addEventListener('click', function () { guarda('sim'); ligaAnalytics(); esconde(); });

    bts.appendChild(nao); bts.appendChild(sim);
    dentro.appendChild(p); dentro.appendChild(bts);
    caixa.appendChild(dentro);
    document.body.appendChild(caixa);

    requestAnimationFrame(function () { caixa.classList.add('ver'); });
  }

  function esconde() { if (caixa) caixa.classList.remove('ver'); }

  /* ---- decide ---- */
  function decide() {
    var escolha = le();

    if (escolha === 'sim') { ligaAnalytics(); return; }
    if (escolha === 'nao') { return; }

    /* sinal de privacidade do próprio navegador: é uma recusa já dada, e
       perguntar de novo a quem já respondeu não é perguntar, é insistir */
    if (navigator.globalPrivacyControl === true) { guarda('nao'); return; }

    monta();
  }

  /* o rodapé de cada página tem um [data-cookies] para reabrir a escolha,
     como a Política promete */
  function liga() {
    document.querySelectorAll('[data-cookies]').forEach(function (el) {
      el.addEventListener('click', function (e) { e.preventDefault(); monta(); });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { decide(); liga(); });
  } else {
    decide(); liga();
  }

  window.dextCookies = { abrir: monta, escolha: le };
})();
