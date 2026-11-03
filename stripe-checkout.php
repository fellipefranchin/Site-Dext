<?php
/* ===== Dext · abre o checkout da Stripe =====
   O site manda o plano escolhido, este arquivo cria a Checkout Session na
   Stripe e devolve a URL para onde o navegador deve ir.

   Por que existe: a chave secreta não pode viver no navegador, porque tudo que
   está no navegador o visitante lê. Então o site nunca fala com a Stripe
   direto: fala com este arquivo, e só ele conhece a chave.

   Sem biblioteca, chamada HTTP direta. A hospedagem é compartilhada e o
   projeto não tem dependência instalada: o contato.php já faz SMTP na mão
   pelo mesmo motivo, e está no ar há meses.

   As credenciais e os Price IDs ficam em stripe-config.php, que está no
   .gitignore e é negado pelo .htaccess: nunca vai para o repositório nem é
   servido pela web. Responde JSON: {"ok":true,"url":"..."} ou {"ok":false,...} */

declare(strict_types=1);

const SITE    = 'https://dextdigital.com.br';
const CANCELA = SITE . '/#sistema';
const TESTE   = 30;     // dias de teste antes da primeira cobrança
const LIMITE  = 10;     // aberturas de checkout por IP
const JANELA  = 3600;   // ...por hora

/* Mensalidade de cada plano, só para o relatório de vendas do Analytics. Não
   é isto que cobra: quem cobra é o Price ID lá na Stripe. Os dois precisam
   andar juntos, e é por isso que os valores também estão no site. */
const VALORES = [
  'completo' => 690, 'clientes' => 159, 'pessoas' => 189,
  'financeiro' => 369, 'operacional' => 349,
];

/* O aceite obrigatório depende de a Stripe ter o endereço dos termos nos
   dados públicos da conta, e esse endereço só pode ser cadastrado quando a
   página /termos estiver no ar. Enquanto não estiver, a Stripe recusa a
   sessão e nada pode ser testado.

   Por isso esta chave: deixe false para testar o fluxo antes dos textos
   saírem da revisão jurídica, e volte para true no mesmo dia em que as
   páginas forem publicadas. Vender de verdade com ela em false é vender sem
   contrato aceito. */
const ACEITE_OBRIGATORIO = true;
const ACEITE = 'Li e aceito os [Termos de Uso](' . SITE . '/termos) e a '
  . '[Política de Privacidade](' . SITE . '/privacidade) da Dext. '
  . 'Sem fidelidade: o cancelamento é feito pelo próprio cliente, a qualquer '
  . 'momento, pelo link enviado por e-mail.';

header('Content-Type: application/json; charset=utf-8');

function responde(bool $ok, array $extra = [], int $status = 200): never {
  http_response_code($status);
  echo json_encode(['ok' => $ok] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  responde(false, ['erro' => 'Método não permitido.'], 405);
}

/* ---- credenciais e Price IDs, num arquivo à parte ---- */
function config(): array {
  static $c = null;
  if ($c === null) {
    $arq = __DIR__ . '/stripe-config.php';
    $c = is_file($arq) ? (array) require $arq : [];
  }
  return $c;
}

$cfg    = config();
$chave  = (string) ($cfg['secret'] ?? '');
$precos = (array)  ($cfg['precos'] ?? []);

if ($chave === '' || $precos === []) {
  error_log('[dext] stripe-config.php ausente ou incompleto');
  responde(false, ['erro' => 'Pagamento indisponível no momento.'], 503);
}

/* ---- qual plano o visitante escolheu ---- */
$bruto = file_get_contents('php://input') ?: '';
$dados = json_decode($bruto, true);
if (!is_array($dados)) { $dados = $_POST; }

$plano = is_string($dados['plano'] ?? null) ? trim($dados['plano']) : '';

/* O plano vem do navegador, então vale o que a lista diz, não o que ele manda:
   sem isto daria para inventar um preço que não é nosso. */
if (!isset($precos[$plano]) || !is_string($precos[$plano]) || $precos[$plano] === '') {
  responde(false, ['erro' => 'Plano inválido.'], 422);
}
$priceId = $precos[$plano];

/* ---- limite por IP: abrir sessão custa nada para quem abusa e polui o painel ---- */
$ip   = (string) ($_SERVER['REMOTE_ADDR'] ?? '0');
$arq  = sys_get_temp_dir() . '/dext-checkout-' . md5($ip);
$hoje = time();
$hist = [];
if (is_file($arq)) {
  foreach (explode(',', (string) file_get_contents($arq)) as $t) {
    $t = (int) $t;
    if ($t > $hoje - JANELA) { $hist[] = $t; }
  }
}
if (count($hist) >= LIMITE) {
  responde(false, ['erro' => 'Muitas tentativas seguidas. Tente novamente mais tarde.'], 429);
}
$hist[] = $hoje;
@file_put_contents($arq, implode(',', $hist), LOCK_EX);

/* =========================================================================
   a sessão
   ========================================================================= */
/* O nome e o e-mail a Stripe já coleta sozinha. O telefone e o nome do
   restaurante não: sem as duas linhas abaixo, o aviso de nova assinatura
   chegaria sem o que o comercial precisa para ligar. */
$campos = [
  'mode'                                  => 'subscription',
  'line_items[0][price]'                  => $priceId,
  'line_items[0][quantity]'               => '1',
  'subscription_data[trial_period_days]'  => (string) TESTE,
  'phone_number_collection[enabled]'      => 'true',
  'custom_fields[0][key]'                 => 'restaurante',
  'custom_fields[0][label][type]'         => 'custom',
  'custom_fields[0][label][custom]'       => 'Nome do restaurante',
  'custom_fields[0][type]'                => 'text',
  'custom_fields[0][text][maximum_length]'=> '100',
  /* o plano e o valor voltam na URL porque a página de obrigado precisa deles
     para o evento de venda do Analytics, e ela é estática */
  'success_url'                           => SITE . '/obrigado/?s={CHECKOUT_SESSION_ID}'
                                             . '&p=' . rawurlencode($plano)
                                             . '&v=' . (VALORES[$plano] ?? 0),
  'cancel_url'                            => CANCELA,
  'locale'                                => 'pt-BR',
  /* volta no webhook: diz qual plano foi vendido sem depender de adivinhar
     pelo Price ID na hora de escrever o e-mail */
  'metadata[plano]'                       => $plano,
  'subscription_data[metadata][plano]'    => $plano,
];

if (ACEITE_OBRIGATORIO) {
  $campos['consent_collection[terms_of_service]'] = 'required';
  $campos['custom_text[terms_of_service_acceptance][message]'] = ACEITE;
} else {
  error_log('[dext] checkout aberto SEM aceite de termos (ACEITE_OBRIGATORIO está false)');
}

/* Chave de idempotência: se o visitante clicar duas vezes ou a resposta se
   perder no caminho, a Stripe devolve a mesma sessão em vez de abrir outra. */
$idem = 'dext-' . hash('sha256', $ip . '|' . $plano . '|' . floor($hoje / 300));

[$status, $corpo, $falha] = postStripe('https://api.stripe.com/v1/checkout/sessions', $campos, $chave, $idem);

if ($falha !== '') {
  error_log('[dext] checkout não saiu (' . $plano . '): ' . $falha);
  responde(false, ['erro' => 'Não foi possível abrir o pagamento agora.'], 502);
}

$resposta = json_decode($corpo, true);
if (!is_array($resposta)) { $resposta = []; }

if ($status !== 200 || empty($resposta['url'])) {
  /* a mensagem da Stripe vai para o log, nunca para a tela: ela cita Price ID,
     conta e modo, e isso não é assunto do visitante */
  error_log('[dext] Stripe recusou (' . $status . ', plano ' . $plano . '): '
    . (string) ($resposta['error']['message'] ?? substr($corpo, 0, 300)));
  responde(false, ['erro' => 'Não foi possível abrir o pagamento agora.'], 502);
}

responde(true, ['url' => (string) $resposta['url']]);

/* =========================================================================
   envio
   ========================================================================= */
/* Devolve [status, corpo, falha]. Usa cURL quando existe e cai para stream
   quando não existe, porque hospedagem compartilhada varia. */
function postStripe(string $url, array $campos, string $chave, string $idem): array {
  $corpo = http_build_query($campos, '', '&', PHP_QUERY_RFC3986);
  /* Sem Stripe-Version de propósito: assim a chamada usa a versão padrão da
     conta, que é a mesma em que o webhook foi criado. Fixar um número aqui
     faria o checkout falar uma versão e o aviso de venda chegar em outra, e
     o dia em que os campos mudassem de forma ninguém ia entender por quê. */
  $cab   = [
    'Authorization: Bearer ' . $chave,
    'Content-Type: application/x-www-form-urlencoded',
    'Idempotency-Key: ' . $idem,
  ];

  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST           => true,
      CURLOPT_POSTFIELDS     => $corpo,
      CURLOPT_HTTPHEADER     => $cab,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 20,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
    ]);
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
    'method'        => 'POST',
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
