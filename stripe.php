<?php
/* ===== Dext · fala com a API da Stripe =====
   Chamada HTTP direta, sem biblioteca. A hospedagem é compartilhada e o
   projeto não tem dependência instalada: o contato.php já faz SMTP na mão
   pelo mesmo motivo e está no ar há meses.

   Quem usa: o stripe-checkout.php, para abrir a sessão de pagamento, e o
   stripe-webhook.php, para buscar o cliente quando o evento só traz o id.

   As credenciais ficam em stripe-config.php, que está no .gitignore e é
   negado pelo .htaccess: nunca vai para o repositório nem é servido pela web. */

declare(strict_types=1);

/* ---- credenciais e Price IDs, num arquivo à parte ---- */
function stripeConfig(): array {
  static $c = null;
  if ($c === null) {
    $arq = __DIR__ . '/stripe-config.php';
    $c = is_file($arq) ? (array) require $arq : [];
  }
  return $c;
}

/* ---- a chamada. Devolve [status, corpo, falha] ----
   Sem Stripe-Version de propósito: assim a chamada usa a versão padrão da
   conta, que é a mesma em que o webhook foi criado. Fixar um número aqui
   faria o checkout falar uma versão e o aviso de venda chegar em outra, e
   o dia em que os campos mudassem de forma ninguém ia entender por quê. */
function stripeChama(string $metodo, string $caminho, array $campos, string $chave, string $idem = ''): array {
  $url   = 'https://api.stripe.com' . $caminho;
  $corpo = $campos === [] ? '' : http_build_query($campos, '', '&', PHP_QUERY_RFC3986);
  $cab   = ['Authorization: Bearer ' . $chave];

  if ($metodo === 'GET') {
    if ($corpo !== '') { $url .= '?' . $corpo; $corpo = ''; }
  } else {
    $cab[] = 'Content-Type: application/x-www-form-urlencoded';
  }
  /* idempotência: se a resposta se perder no caminho e a chamada for
     repetida, a Stripe devolve o mesmo objeto em vez de criar outro */
  if ($idem !== '') { $cab[] = 'Idempotency-Key: ' . $idem; }

  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_CUSTOMREQUEST  => $metodo,
      CURLOPT_HTTPHEADER     => $cab,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 20,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($corpo !== '') { curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo); }
    $resp = curl_exec($ch);
    $erro = curl_error($ch);
    $st   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    /* sem curl_close() de propósito: no PHP 8.0 ele virou inútil e no 8.5
       virou um aviso de "depreciado" impresso ANTES do JSON. Com a resposta
       suja o site não consegue ler o endereço do checkout e acusa falha numa
       compra que deu certo. O recurso se fecha sozinho. */
    return $resp === false ? [0, '', $erro ?: 'cURL falhou'] : [$st, (string) $resp, ''];
  }

  $ctx = stream_context_create(['http' => [
    'method'        => $metodo,
    'header'        => implode("\r\n", $cab),
    'content'       => $corpo,
    'timeout'       => 20,
    'ignore_errors' => true,   // sem isto o PHP engole o corpo do 4xx, que é onde a Stripe explica
  ]]);
  $resp = @file_get_contents($url, false, $ctx);
  if ($resp === false) { return [0, '', 'stream falhou']; }

  $st = 0;
  foreach ($http_response_header ?? [] as $linha) {
    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linha, $m)) { $st = (int) $m[1]; }
  }
  return [$st, $resp, ''];
}

/* ---- busca um objeto e devolve o array já decodificado, ou [] ----
   Usado quando o evento traz só o id do cliente. Falha em silêncio de
   propósito: um aviso sem o telefone ainda serve, e derrubar o webhook por
   causa disso faria a Stripe repetir o evento. */
function stripeBusca(string $caminho, string $chave): array {
  [$st, $corpo, $falha] = stripeChama('GET', $caminho, [], $chave);
  if ($falha !== '' || $st !== 200) {
    error_log('[dext] não consegui buscar ' . $caminho . ' (' . $st . ') ' . $falha);
    return [];
  }
  $j = json_decode($corpo, true);
  return is_array($j) ? $j : [];
}
