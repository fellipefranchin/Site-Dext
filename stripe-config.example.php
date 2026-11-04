<?php
/* ===== Modelo do stripe-config.php =====
   Copie este arquivo como stripe-config.php NO SERVIDOR e preencha lá. O
   arquivo real nunca entra no repositório (.gitignore) e nunca é servido pela
   web (.htaccess nega qualquer *config*.php). Aqui não vai segredo nenhum.

   As chaves você digita direto no servidor. Não passe por e-mail, nem por
   mensagem, nem por captura de tela.

   Onde achar cada coisa, no painel da Stripe:
   - secret ............ Desenvolvedores > Chaves de API > chave secreta
   - webhook_secret .... Desenvolvedores > Webhooks > o seu endpoint > segredo
   - precos ............ Catálogo de produtos > o produto > o preço (price_...)
   - portal_link ....... Configurações > Faturamento > Portal do cliente > Link
                         do portal. Use o botão de copiar que fica ao lado: um
                         caractere trocado à mão vira um link quebrado dentro
                         do e-mail de boas-vindas.

   Atenção ao modo: em teste as chaves começam com sk_test_ e os Price IDs são
   outros. Ao virar para produção, troca tudo: chave, segredo do webhook e os
   cinco Price IDs. */

return [
  'secret'         => 'sk_test_...',
  'webhook_secret' => 'whsec_...',

  /* Para onde o cliente vai quando quiser trocar o cartão ou cancelar. Vai
     dentro do e-mail de boas-vindas, e é o que a cláusula 6 dos Termos promete
     ("o cancelamento é feito pelo próprio cliente, pelo link enviado por
     e-mail"). Não é segredo: é uma página pública que pede o e-mail do cliente
     e manda um código. No modo de teste o endereço tem "test_" no meio; na
     virada para produção ele muda. */
  'portal_link'    => 'https://billing.stripe.com/p/login/...',

  /* A chave da esquerda é o que o site manda; a Price ID da direita é o que
     vale. O site nunca escolhe preço, só escolhe um nome desta lista.

     Estes são os IDs do MODO DE TESTE da conta Dext (acct_1UTggtGpAmzkTzhz).
     Price ID não é segredo: ele não compra nada sozinho, só funciona com a
     chave secreta. Na virada para produção, os cinco mudam. */
  'precos' => [
    'completo'    => 'price_1UXLpEGpAmzkTzhzknVyFwIH',   // Completo     R$ 690
    'clientes'    => 'price_1UXLpqGpAmzkTzhzSRgfd7rf',   // Clientes     R$ 159
    'pessoas'     => 'price_1UXLqfGpAmzkTzhzvPZQ4Wpo',   // Pessoas      R$ 189
    'financeiro'  => 'price_1UXLr8GpAmzkTzhzdDvMVs7X',   // Financeiro   R$ 369
    'operacional' => 'price_1UXLrZGpAmzkTzhzWdV5NlYK',   // Operacional  R$ 349
  ],
];
