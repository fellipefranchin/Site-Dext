<?php
/* ===== Dext · avisos de assinatura =====
   A Stripe chama este arquivo quando alguma coisa acontece com uma
   assinatura. Ele confere que a chamada é mesmo da Stripe e manda o aviso
   para quem cuida das contas.

   São quatro eventos, e os três últimos existem porque sem eles a Dext só
   sabe quando alguém entra, e nunca quando a receita quebra:

   | evento                              | o que significa                  |
   | checkout.session.completed          | vendeu: criar a conta à mão      |
   | invoice.payment_failed              | o cartão não passou              |
   | customer.subscription.deleted       | cancelou: fechar o acesso        |
   | customer.subscription.trial_will_end| o teste acaba em 3 dias          |

   Por que checkout.session.completed e não customer.subscription.created:
   o nome do restaurante é um campo nosso do checkout e o telefone é
   coletado lá também. Os dois vivem na sessão, não na assinatura.

   O segredo de assinatura fica em stripe-config.php, que está no .gitignore
   e é negado pelo .htaccess. */

declare(strict_types=1);

require __DIR__ . '/smtp.php';
require __DIR__ . '/stripe.php';

const DESTINO    = 'adm@dextdigital.com.br';
const REMETENTE  = 'comercial@dextdigital.com.br';  // do domínio, por causa do SPF
const SITE       = 'https://dextdigital.com.br';
const PAINEL     = 'https://dashboard.stripe.com/subscriptions/';
const TOLERANCIA = 300;  // segundos de diferença aceitos no carimbo da Stripe

/* ---- o painel do Dext Sistema de Gestão ----
   Quem abre a conta do cliente, suspende por falta de pagamento e fecha no
   cancelamento é o painel. Até esta chamada existir, tudo isso era um e-mail
   para alguém fazer à mão.

   O segredo mora FORA do public_html, no mesmo servidor, e nunca passou por
   e-mail nem por este repositório: o Fellipe gerou nos dois lados. O arquivo
   só faz `return '...';`. */
const PAINEL_URL = 'https://app.dextdigital.com.br/dext/assinaturas';
const SEGREDO_ARQ = __DIR__ . '/../dext-assinaturas-segredo.php';

/* A Stripe repete a chamada até receber 200. Mesmo quando não há nada a fazer
   a resposta é 200, senão ela fica reenviando um evento que a gente ignora
   de propósito. O texto da resposta aparece em Entregas de eventos, no
   painel, e é lá que se descobre se o aviso saiu. */
function encerra(int $status, string $msg = 'ok'): never {
  http_response_code($status);
  header('Content-Type: text/plain; charset=utf-8');
  echo $msg;
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { encerra(405, 'Método não permitido.'); }

$cfg     = stripeConfig();
$segredo = (string) ($cfg['webhook_secret'] ?? '');
$chave   = (string) ($cfg['secret'] ?? '');
if ($segredo === '') {
  error_log('[dext] webhook sem webhook_secret em stripe-config.php');
  encerra(500, 'não configurado');
}

$corpo    = file_get_contents('php://input') ?: '';
$assinado = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

/* =========================================================================
   1) é mesmo a Stripe?
   ========================================================================= */
/* Este endereço é público: qualquer um pode chamar. Sem conferir a assinatura,
   qualquer um conseguiria forjar uma venda e fazer o comercial criar conta de
   graça. O cabeçalho vem como "t=carimbo,v1=assinatura". */
function confere(string $corpo, string $cabecalho, string $segredo): bool {
  $carimbo = 0;
  $vistos  = [];
  foreach (explode(',', $cabecalho) as $parte) {
    $par = explode('=', trim($parte), 2);
    if (count($par) !== 2) { continue; }
    if ($par[0] === 't')  { $carimbo = (int) $par[1]; }
    if ($par[0] === 'v1') { $vistos[] = $par[1]; }
  }
  if ($carimbo === 0 || $vistos === []) { return false; }

  /* carimbo velho é chamada repetida fora de hora, ou gravada e reenviada */
  if (abs(time() - $carimbo) > TOLERANCIA) { return false; }

  $esperado = hash_hmac('sha256', $carimbo . '.' . $corpo, $segredo);
  foreach ($vistos as $v) {
    /* hash_equals compara em tempo constante: comparar com == deixaria medir
       o tempo da resposta e descobrir a assinatura byte a byte */
    if (hash_equals($esperado, $v)) { return true; }
  }
  return false;
}

if (!confere($corpo, $assinado, $segredo)) {
  error_log('[dext] webhook com assinatura inválida de ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
  encerra(400, 'assinatura inválida');
}

/* =========================================================================
   2) o evento
   ========================================================================= */
$evento = json_decode($corpo, true);
if (!is_array($evento)) { encerra(400, 'corpo inválido'); }

$tipo = (string) ($evento['type'] ?? '');
$id   = (string) ($evento['id'] ?? '');
$obj  = (array) ($evento['data']['object'] ?? []);

/* O modo de teste manda evento pela mesma porta e o e-mail chega na mesma
   caixa do de verdade. Em 06 e 10/11 um "Cartão recusado" de uma fatura de
   teste foi lido como cliente real. O assunto passa a avisar, e enquanto a
   conta não virar para produção isso vale para todo e-mail que sair daqui. */
$ehTeste = array_key_exists('livemode', $evento) && $evento['livemode'] === false;
$assuntoDe = static fn(string $a): string => $ehTeste ? '[TESTE] ' . $a : $a;

if (!in_array($tipo, [
  'checkout.session.completed', 'invoice.payment_failed', 'invoice.paid',
  'customer.subscription.deleted', 'customer.subscription.updated',
  'customer.subscription.trial_will_end',
], true)) {
  encerra(200, 'ignorado: ' . $tipo);
}

/* A Stripe reenvia o mesmo evento quando a resposta demora ou dá erro. A marca
   impede que o mesmo aviso chegue três vezes ao adm@.

   Ela NÃO corta o resto: se o painel estiver fora do ar, esta resposta vai ser
   500 de propósito, para a Stripe tentar de novo, e na volta a chamada ao
   painel precisa acontecer outra vez. O painel ignora id_evento repetido, então
   repetir lá é seguro; repetir o e-mail é que não é. */
$marca    = sys_get_temp_dir() . '/dext-stripe-' . preg_replace('/[^a-zA-Z0-9_]/', '', $id);
$repetido = $id !== '' && is_file($marca) && filemtime($marca) > time() - 86400;
@touch($marca);

/* =========================================================================
   utilidades
   ========================================================================= */
date_default_timezone_set('America/Sao_Paulo');
$e     = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$limpa = static fn(string $t): string => str_replace(["\r", "\n"], ' ', $t);

/* WhatsApp do cliente, para responder em um clique */
function zap(string $telefone): string {
  $d = preg_replace('/\D+/', '', $telefone) ?? '';
  if (strlen($d) >= 10 && strlen($d) <= 11) { $d = '55' . $d; }
  return (strlen($d) >= 12 && strlen($d) <= 13) ? 'https://wa.me/' . $d : '';
}

function reais(?int $centavos): string {
  return $centavos === null ? '' : 'R$ ' . number_format($centavos / 100, 2, ',', '.');
}

function quando(?int $ts): string {
  return ($ts === null || $ts <= 0) ? '' : date('d/m/Y', $ts);
}

/* Os eventos de assinatura trazem só o id do cliente, então o nome, o e-mail
   e o telefone vêm de uma busca. Falha em silêncio: aviso sem telefone ainda
   serve, e derrubar o webhook faria a Stripe repetir o evento. */
function buscaCliente(string $idCliente, string $chave): array {
  if ($idCliente === '' || $chave === '') { return []; }
  $c = stripeBusca('/v1/customers/' . rawurlencode($idCliente), $chave);
  return [
    'nome'     => trim((string) ($c['name']  ?? '')),
    'email'    => trim((string) ($c['email'] ?? '')),
    'telefone' => trim((string) ($c['phone'] ?? '')),
  ];
}

/* O nome do plano chega em minúscula, do jeito que o botão do site mandou.
   No e-mail do cliente ele aparece como nome próprio. */
const PLANOS = [
  'completo'    => 'Completo',
  'clientes'    => 'Clientes',
  'pessoas'     => 'Pessoas',
  'financeiro'  => 'Financeiro',
  'operacional' => 'Operacional',
];

/* O portal da Stripe pergunta o motivo ao cancelar, e a resposta vem em
   inglês, em código. Traduzido vale como pesquisa de perda. */
const MOTIVOS = [
  'too_expensive'    => 'Achou caro',
  'missing_features' => 'Faltou funcionalidade',
  'switched_service' => 'Foi para outro serviço',
  'unused'           => 'Não estava usando',
  'customer_service' => 'Atendimento',
  'too_complex'      => 'Achou complicado',
  'low_quality'      => 'Qualidade abaixo do esperado',
  'other'            => 'Outro',
];

/* =========================================================================
   3) o que dizer em cada caso
   ========================================================================= */
/* Cada evento devolve a mesma forma: o aviso é montado uma vez só, lá embaixo. */

$aviso  = null;   // o que vai para o adm@
$boas   = null;   // o que vai para o cliente, só na venda
$painel = null;   // o que vai para o Dext Sistema de Gestão, em todos

/* A data que o painel chama de fim_periodo. Nas versões novas da API ela
   mudou de lugar: saiu da assinatura e foi para cada item dela. Lemos os dois
   para a conta não quebrar no dia em que a Stripe virar a versão da conta. */
function fimDoPeriodo(array $assinatura): string {
  $ts = (int) ($assinatura['current_period_end']
            ?? $assinatura['items']['data'][0]['current_period_end'] ?? 0);
  return $ts > 0 ? date('Y-m-d', $ts) : '';
}

if ($tipo === 'checkout.session.completed') {
  $cli         = (array) ($obj['customer_details'] ?? []);
  $nome        = trim((string) ($cli['name']  ?? ''));
  $email       = trim((string) ($cli['email'] ?? ''));
  $telefone    = trim((string) ($cli['phone'] ?? ''));
  $plano       = trim((string) ($obj['metadata']['plano'] ?? ''));
  $assinatura  = trim((string) ($obj['subscription'] ?? ''));
  $restaurante = '';
  foreach ((array) ($obj['custom_fields'] ?? []) as $campo) {
    if (($campo['key'] ?? '') === 'restaurante') {
      $restaurante = trim((string) ($campo['text']['value'] ?? ''));
    }
  }

  /* Grava o restaurante na assinatura e traz a assinatura de volta. Sem a
     gravação, o aviso de cancelamento e o de cartão recusado chegariam sabendo
     o nome da pessoa e não o da casa, que é como o comercial reconhece o
     cliente. O expand pede o cartão junto: a resposta deste POST já é tudo o
     que o e-mail de boas-vindas precisa, e evita uma segunda ida à Stripe. */
  $assin = [];
  if ($assinatura !== '' && $chave !== '') {
    $campos = ['expand[0]' => 'default_payment_method'];
    if ($restaurante !== '') { $campos['metadata[restaurante]'] = $restaurante; }
    if ($plano !== '')       { $campos['metadata[plano]']       = $plano; }

    [$st, $cru, $falha] = stripeChama('POST', '/v1/subscriptions/' . rawurlencode($assinatura), $campos, $chave);
    if ($falha !== '' || $st !== 200) {
      error_log('[dext] não li a assinatura ' . $assinatura . ' (' . $st . ') ' . $falha);
    } else {
      $assin = (array) (json_decode($cru, true) ?: []);
    }
  }

  /* As datas e o valor que o cliente precisa ver só existem na assinatura: a
     sessão do checkout não sabe quando o teste acaba nem quanto vai custar. */
  $fimTeste = isset($assin['trial_end']) ? (int) $assin['trial_end'] : 0;
  $preco    = (array) ($assin['items']['data'][0]['price'] ?? []);
  $mensal   = isset($preco['unit_amount']) ? (int) $preco['unit_amount'] : null;
  /* default_payment_method vem objeto com o expand e string sem ele. Ler
     ['card'] de uma string acende um warning, e warning impresso antes da
     resposta é o jeito conhecido de quebrar este projeto. */
  $pm     = $assin['default_payment_method'] ?? null;
  $cartao = is_array($pm) ? trim((string) ($pm['card']['last4'] ?? '')) : '';

  if ($email !== '') {
    $boas = ['nome' => $nome, 'restaurante' => $restaurante, 'email' => $email,
             'plano' => PLANOS[$plano] ?? $plano, 'fim_teste' => $fimTeste,
             'mensal' => $mensal, 'cartao' => $cartao];
  }

  /* O país decide idioma, fuso e documento fiscal do sistema do cliente. Vem
     do endereço que a Stripe coletou; sem ele o painel assume Brasil, que é
     onde está quase toda a base. */
  $painel = [
    'customer'     => trim((string) ($obj['customer'] ?? '')),
    'subscription' => $assinatura,
    'plano'        => $plano,
    'restaurante'  => $restaurante,
    'nome'         => $nome,
    'email'        => $email,
    'telefone'     => $telefone,
    'pais'         => strtoupper(trim((string) ($cli['address']['country'] ?? ''))),
    'fim_periodo'  => fimDoPeriodo($assin),
  ];

  $aviso = [
    'kicker'  => 'Nova assinatura',
    'titulo'  => $restaurante !== '' ? $restaurante : $nome,
    'sub'     => trim($plano . ' · ' . date('d/m/Y \à\s H:i'), ' ·'),
    'linhas'  => ['Nome' => $nome, 'Restaurante' => $restaurante, 'E-mail' => $email,
                  'WhatsApp' => $telefone, 'Plano' => $plano, 'Assinatura' => $assinatura],
    'recado'  => 'A conta no sistema é criada à mão, em até 1 dia útil.',
    'assunto' => 'Nova assinatura · ' . ($restaurante !== '' ? $restaurante : $nome) . ' · ' . $plano,
    'email'   => $email,
    'telefone'=> $telefone,
    'link'    => $assinatura !== '' ? PAINEL . $assinatura : '',
  ];
}

elseif ($tipo === 'invoice.payment_failed') {
  /* A fatura já traz o cliente: não precisa buscar. */
  $nome       = trim((string) ($obj['customer_name']  ?? ''));
  $email      = trim((string) ($obj['customer_email'] ?? ''));
  $telefone   = trim((string) ($obj['customer_phone'] ?? ''));
  $assinatura = trim((string) ($obj['subscription'] ?? ''));
  $tentativa  = (int) ($obj['attempt_count'] ?? 0);
  $proxima    = quando(isset($obj['next_payment_attempt']) ? (int) $obj['next_payment_attempt'] : null);
  $valor      = reais(isset($obj['amount_due']) ? (int) $obj['amount_due'] : null);

  /* A fatura traz o nome só quando ele foi preenchido na venda. Sem ele o
     aviso saía como "Cliente", que não diz a quem ligar. O cadastro na
     Stripe sabe: uma ida a mais vale mais do que um aviso cego. */
  if ($nome === '' || $email === '' || $telefone === '') {
    $c        = buscaCliente((string) ($obj['customer'] ?? ''), $chave);
    $nome     = $nome     !== '' ? $nome     : trim((string) ($c['nome']     ?? ''));
    $email    = $email    !== '' ? $email    : trim((string) ($c['email']    ?? ''));
    $telefone = $telefone !== '' ? $telefone : trim((string) ($c['telefone'] ?? ''));
  }

  /* o restaurante foi gravado na assinatura na hora da venda */
  $restaurante = '';
  $a = [];
  if ($assinatura !== '' && $chave !== '') {
    $a = stripeBusca('/v1/subscriptions/' . rawurlencode($assinatura), $chave);
    $restaurante = trim((string) ($a['metadata']['restaurante'] ?? ''));
  }

  $painel = [
    'customer'     => trim((string) ($obj['customer'] ?? '')),
    'subscription' => $assinatura,
    'plano'        => trim((string) ($a['metadata']['plano'] ?? '')),
    'restaurante'  => $restaurante,
    'nome'         => $nome,
    'email'        => $email,
    'telefone'     => $telefone,
    'pais'         => strtoupper(trim((string) ($obj['customer_address']['country'] ?? ''))),
    'fim_periodo'  => fimDoPeriodo($a),
  ];

  $plano    = trim((string) ($a['metadata']['plano'] ?? ''));
  $cliente  = trim((string) ($obj['customer'] ?? ''));
  /* Sem nome, sem restaurante e sem e-mail, o aviso ainda precisa dizer de
     quem se trata: o ID do cliente é o que resta, e é o que abre o cadastro
     na Stripe. "Cliente" sozinho não leva a lugar nenhum. */
  $quem = $restaurante !== '' ? $restaurante
        : ($nome !== '' ? $nome
        : ($email !== '' ? $email
        : ($cliente !== '' ? $cliente : 'Cliente')));

  $aviso = [
    'kicker'  => 'Cartão recusado',
    'titulo'  => $quem,
    'sub'     => trim($valor . ' · tentativa ' . $tentativa, ' ·'),
    'linhas'  => ['Nome' => $nome, 'Restaurante' => $restaurante, 'E-mail' => $email,
                  'WhatsApp' => $telefone, 'Plano' => PLANOS[$plano] ?? $plano,
                  'Valor' => $valor,
                  'Tentativa' => $tentativa > 0 ? (string) $tentativa : '',
                  'Próxima tentativa' => $proxima, 'Assinatura' => $assinatura,
                  'Cliente' => $cliente],
    'recado'  => 'A Stripe tenta de novo sozinha. No 7.º dia sem pagar, o painel suspende o '
               . 'sistema do cliente, com os dados guardados. Vale ligar antes disso.',
    'assunto' => 'Cartão recusado · ' . $quem,
    'email'   => $email,
    'telefone'=> $telefone,
    'link'    => $assinatura !== '' ? PAINEL . $assinatura : '',
  ];
}

/* Os dois eventos abaixo só interessam ao painel: um reabre o acesso de quem
   pagou depois de uma falha, o outro troca as partes do sistema quando o
   cliente muda de plano. Nenhum dos dois vira e-mail, porque acontecem no
   funcionamento normal e encheriam a caixa do adm@ até ninguém mais ler. */
elseif ($tipo === 'invoice.paid' || $tipo === 'customer.subscription.updated') {
  $ehFatura   = $tipo === 'invoice.paid';
  $assinatura = trim((string) ($ehFatura ? ($obj['subscription'] ?? '') : ($obj['id'] ?? '')));

  $a = $ehFatura && $assinatura !== '' && $chave !== ''
     ? stripeBusca('/v1/subscriptions/' . rawurlencode($assinatura), $chave)
     : $obj;

  $painel = [
    'customer'     => trim((string) ($obj['customer'] ?? '')),
    'subscription' => $assinatura,
    'plano'        => trim((string) ($a['metadata']['plano'] ?? '')),
    'restaurante'  => trim((string) ($a['metadata']['restaurante'] ?? '')),
    'nome'         => '', 'email' => '', 'telefone' => '', 'pais' => '',
    'fim_periodo'  => fimDoPeriodo($a),
  ];
}

elseif ($tipo === 'customer.subscription.deleted') {
  $cli         = buscaCliente((string) ($obj['customer'] ?? ''), $chave);
  $plano       = trim((string) ($obj['metadata']['plano'] ?? ''));
  $restaurante = trim((string) ($obj['metadata']['restaurante'] ?? ''));
  $assinatura  = trim((string) ($obj['id'] ?? ''));
  $det         = (array) ($obj['cancellation_details'] ?? []);

  $painel = [
    'customer'     => trim((string) ($obj['customer'] ?? '')),
    'subscription' => $assinatura,
    'plano'        => $plano,
    'restaurante'  => $restaurante,
    'nome'         => $cli['nome'] ?? '',
    'email'        => $cli['email'] ?? '',
    'telefone'     => $cli['telefone'] ?? '',
    'pais'         => '',
    'fim_periodo'  => fimDoPeriodo($obj),
  ];
  $motivo      = MOTIVOS[(string) ($det['feedback'] ?? '')] ?? '';
  $comentario  = trim((string) ($det['comment'] ?? ''));

  $aviso = [
    'kicker'  => 'Cancelamento',
    'titulo'  => $restaurante !== '' ? $restaurante : ($cli['nome'] ?? 'Cliente'),
    'sub'     => trim($plano . ' · ' . date('d/m/Y'), ' ·'),
    'linhas'  => ['Nome' => $cli['nome'] ?? '', 'Restaurante' => $restaurante,
                  'E-mail' => $cli['email'] ?? '', 'WhatsApp' => $cli['telefone'] ?? '',
                  'Plano' => $plano, 'Motivo' => $motivo, 'Comentário' => $comentario,
                  'Assinatura' => $assinatura],
    'recado'  => 'O painel já fechou o acesso. Começaram a contar 30 dias para o cliente '
               . 'pedir a exportação dos dados e 90 para a exclusão.',
    'assunto' => 'Cancelamento · ' . ($restaurante !== '' ? $restaurante : ($cli['nome'] ?? 'cliente')),
    'email'   => $cli['email'] ?? '',
    'telefone'=> $cli['telefone'] ?? '',
    'link'    => $assinatura !== '' ? PAINEL . $assinatura : '',
  ];
}

elseif ($tipo === 'customer.subscription.trial_will_end') {
  $cli         = buscaCliente((string) ($obj['customer'] ?? ''), $chave);
  $plano       = trim((string) ($obj['metadata']['plano'] ?? ''));
  $restaurante = trim((string) ($obj['metadata']['restaurante'] ?? ''));
  $assinatura  = trim((string) ($obj['id'] ?? ''));
  $fim         = quando(isset($obj['trial_end']) ? (int) $obj['trial_end'] : null);

  $painel = [
    'customer'     => trim((string) ($obj['customer'] ?? '')),
    'subscription' => $assinatura,
    'plano'        => $plano,
    'restaurante'  => $restaurante,
    'nome'         => $cli['nome'] ?? '',
    'email'        => $cli['email'] ?? '',
    'telefone'     => $cli['telefone'] ?? '',
    'pais'         => '',
    'fim_periodo'  => fimDoPeriodo($obj),
  ];

  $aviso = [
    'kicker'  => 'O teste está acabando',
    'titulo'  => $restaurante !== '' ? $restaurante : ($cli['nome'] ?? 'Cliente'),
    'sub'     => trim($plano . ($fim !== '' ? ' · primeira cobrança em ' . $fim : ''), ' ·'),
    'linhas'  => ['Nome' => $cli['nome'] ?? '', 'Restaurante' => $restaurante,
                  'E-mail' => $cli['email'] ?? '', 'WhatsApp' => $cli['telefone'] ?? '',
                  'Plano' => $plano, 'Primeira cobrança' => $fim, 'Assinatura' => $assinatura],
    'recado'  => 'Boa hora para ligar e perguntar se está tudo certo, antes de a primeira '
               . 'mensalidade cair. Quem é ouvido antes da cobrança cancela menos.',
    'assunto' => 'Teste acabando · ' . ($restaurante !== '' ? $restaurante : ($cli['nome'] ?? 'cliente')),
    'email'   => $cli['email'] ?? '',
    'telefone'=> $cli['telefone'] ?? '',
    'link'    => $assinatura !== '' ? PAINEL . $assinatura : '',
  ];
}

/* =========================================================================
   4) o painel do sistema
   =========================================================================
   Vai primeiro, antes dos e-mails: é ele que abre, suspende e fecha a conta
   do cliente. Se falhar, esta resposta é 500 e a Stripe traz o evento de
   volta mais tarde. O e-mail não repete nessa volta, porque a marca lá em
   cima já foi posta; a chamada aqui repete, e o painel ignora id_evento
   repetido justamente para isso. */
function chamaPainel(array $dados, string $tipo, string $id, int $criado): array {
  if (!is_file(SEGREDO_ARQ)) { return [0, 'sem o arquivo do segredo (' . SEGREDO_ARQ . ')']; }
  $segredo = trim((string) require SEGREDO_ARQ);
  if (strlen($segredo) < 32) { return [0, 'segredo com menos de 32 caracteres']; }

  $corpo = json_encode(
    ['evento' => $tipo, 'id_evento' => $id, 'criado' => (string) $criado] + $dados,
    JSON_UNESCAPED_UNICODE);

  $ts    = (string) time();
  $nonce = bin2hex(random_bytes(16));   // 32 caracteres, letras e números

  $ch = curl_init(PAINEL_URL);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $corpo,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER     => [
      'Content-Type: application/json',
      'X-Dext-Ts: ' . $ts,
      'X-Dext-Nonce: ' . $nonce,
      'X-Dext-Assinatura: ' . hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $corpo, $segredo),
    ],
  ]);
  $resp = curl_exec($ch);
  $erro = curl_error($ch);
  $st   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  /* sem curl_close() de propósito, pelo mesmo motivo do stripe.php */

  if ($resp === false) { return [0, 'cURL: ' . ($erro ?: 'falhou')]; }
  return [$st, mb_substr((string) $resp, 0, 200)];
}

/* A chamada ao painel é a ÚLTIMA coisa que acontece, depois dos e-mails, e
   esta função é quem encerra a resposta nos dois caminhos.

   A ordem importa e eu errei nela antes de testar: com o painel na frente,
   enquanto o segredo não existisse a resposta seria 500 e nenhum e-mail
   sairia. Ou seja, no dia da primeira venda de verdade, ninguém ficaria
   sabendo dela. Agora o aviso humano sai sempre, e a Stripe traz o evento de
   volta até o painel aceitar. */
function fechaComPainel(?array $painel, string $tipo, string $id, int $criado, string $resto): never {
  if ($painel === null) { encerra(200, $resto); }

  [$st, $diz] = chamaPainel($painel, $tipo, $id, $criado);

  if ($st === 200) { encerra(200, 'painel ok, ' . $resto); }

  if ($st === 400) {
    /* 400 é dado mal montado deste lado: insistir não conserta e só faria a
       Stripe repetir por três dias. Responde 200, mas grita no log, porque
       quer dizer que uma venda não virou conta e alguém precisa olhar. */
    error_log('[dext] PAINEL RECUSOU 400 em ' . $tipo . ' (' . $id . '): ' . $diz);
    encerra(200, 'painel 400, dado errado deste lado (' . $diz . '), ' . $resto);
  }

  error_log('[dext] painel falhou (' . $st . ') em ' . $tipo . ' (' . $id . '): ' . $diz);
  encerra(500, 'painel falhou (' . $st . '): ' . $diz . ' | ' . $resto . ' | tente de novo');
}

$criado = (int) ($evento['created'] ?? time());

/* Eventos que só interessam ao painel acabam aqui, e o repetido também: o
   e-mail dele já saiu na primeira passagem. */
if ($aviso === null || $repetido) {
  fechaComPainel($painel, $tipo, $id, $criado, ($repetido ? 'repetido' : $tipo));
}

/* =========================================================================
   5) o aviso para o adm@
   ========================================================================= */
const R_ROTULO = 'font:600 11px/1.4 Helvetica,Arial,sans-serif;letter-spacing:.12em;text-transform:uppercase;color:#9a9a9a';
const R_TEXTO  = 'font:400 16px/1.6 Helvetica,Arial,sans-serif;color:#111111';

$wa = zap((string) $aviso['telefone']);

$linhas = '';
foreach ($aviso['linhas'] as $rotulo => $valor) {
  $valor = trim((string) $valor);
  if ($valor === '') { continue; }          // campo vazio não vira linha em branco
  $mostra = $e($valor);
  if ($rotulo === 'E-mail')   { $mostra = '<a href="mailto:' . $e($valor) . '" style="color:#e81840;text-decoration:none">' . $mostra . '</a>'; }
  if ($rotulo === 'WhatsApp' && $wa !== '') { $mostra = '<a href="' . $e($wa) . '" style="color:#e81840;text-decoration:none">' . $mostra . '</a>'; }
  if ($rotulo === 'Assinatura' || $rotulo === 'Cliente') { $mostra = '<span style="font-family:ui-monospace,Menlo,monospace;font-size:13px;color:#9a9a9a">' . $mostra . '</span>'; }
  $linhas .= '<tr>'
    . '<td valign="top" style="padding:14px 0;border-bottom:1px solid #ececeb;' . R_ROTULO . ';white-space:nowrap">' . $e((string) $rotulo) . '</td>'
    . '<td valign="top" style="padding:14px 0 14px 24px;border-bottom:1px solid #ececeb;' . R_TEXTO . '">' . $mostra . '</td>'
    . '</tr>';
}

$botoes = '';
if (($aviso['email'] ?? '') !== '') {
  $botoes .= '<td><a href="mailto:' . $e((string) $aviso['email']) . '" style="display:inline-block;background:#e81840;color:#ffffff;font:600 14px/1 Helvetica,Arial,sans-serif;padding:15px 24px;border-radius:4px;text-decoration:none">Responder por e-mail</a></td>';
}
if ($wa !== '') {
  $botoes .= '<td style="padding-left:10px"><a href="' . $e($wa) . '" style="display:inline-block;background:#ffffff;border:1px solid #262626;color:#111111;font:600 14px/1 Helvetica,Arial,sans-serif;padding:14px 22px;border-radius:4px;text-decoration:none">Chamar no WhatsApp</a></td>';
}
if (($aviso['link'] ?? '') !== '') {
  $botoes .= '<td style="padding-left:10px"><a href="' . $e((string) $aviso['link']) . '" style="display:inline-block;background:#ffffff;border:1px solid #262626;color:#111111;font:600 14px/1 Helvetica,Arial,sans-serif;padding:14px 22px;border-radius:4px;text-decoration:none">Ver na Stripe</a></td>';
}

$miolo = '<tr><td style="padding:36px 36px 0">'
. '<div style="font:700 11px/1.4 Helvetica,Arial,sans-serif;letter-spacing:.18em;text-transform:uppercase;color:#e81840;margin-bottom:12px">' . $e((string) $aviso['kicker']) . '</div>'
. '<div style="font:700 26px/1.2 Helvetica,Arial,sans-serif;color:#111111;letter-spacing:-.02em">' . $e((string) $aviso['titulo']) . '</div>'
. '<div style="font:400 15px/1.5 Helvetica,Arial,sans-serif;color:#9a9a9a;margin-top:6px">' . $e((string) $aviso['sub']) . '</div></td></tr>'
. '<tr><td style="padding:26px 36px 0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $linhas . '</table></td></tr>'
. '<tr><td style="padding:26px 36px 0">'
. '<div style="border-left:3px solid #e81840;padding:4px 0 4px 18px;' . R_TEXTO . '">' . $e((string) $aviso['recado']) . '</div></td></tr>'
. ($botoes === '' ? '' : '<tr><td style="padding:26px 36px 36px"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>' . $botoes . '</tr></table></td></tr>');

$html = smtpMolde($aviso['kicker'] . ' · Dext', $miolo,
  'Disparado pela Stripe · evento ' . $e($tipo)
  . '<br>Dext Estratégias Digitais LTDA · Tecnologia e gestão para restaurantes', SITE);

$texto = mb_strtoupper((string) $aviso['kicker']) . "\n\n" . $aviso['titulo'] . "\n" . $aviso['sub'] . "\n\n";
foreach ($aviso['linhas'] as $rotulo => $valor) {
  $valor = trim((string) $valor);
  if ($valor !== '') { $texto .= $rotulo . ': ' . $valor . "\n"; }
}
$texto .= "\n" . $aviso['recado'] . "\n\n" . SITE;

$cfgSmtp = smtpConfig(__DIR__ . '/contato-config.php');

$erro = '';
$saiuAviso = smtpEnvia($cfgSmtp, REMETENTE, DESTINO,
  $assuntoDe($limpa((string) $aviso['assunto'])), $html, $texto,
  ($aviso['email'] ?? '') !== '' ? $limpa((string) $aviso['email']) : '', $erro);

/* O aviso falhou, mas o evento aconteceu. Responder erro faria a Stripe
   repetir, e o comercial receberia o mesmo aviso várias vezes. Então a
   resposta segue 200 e o motivo vai junto: no painel da Stripe, em
   Entregas de eventos, dá para ler o que houve sem o log do servidor.
   A senha nunca entra aqui: o smtpFala mascara o AUTH. */
if (!$saiuAviso) { error_log('[dext] aviso "' . $tipo . '" não saiu: ' . $erro); }

/* =========================================================================
   5) e o cliente
   =========================================================================
   As regras da Visa e da Mastercard para teste grátis que vira assinatura
   pedem confirmação por e-mail com os termos e um jeito de cancelar, e o
   Decreto 7.962/2013 pede confirmação imediata com o contrato à mão. A
   Stripe manda o recibo dela; este é o nosso, e é o que cumpre a cláusula 6
   dos Termos, que promete o link de cancelamento por e-mail. */

$saiuBoas  = null;    // null = não havia o que mandar
$erroBoas  = '';

if ($boas !== null) {
  $portal = trim((string) ($cfg['portal_link'] ?? ''));
  if ($portal === '') {
    /* sem o link, o e-mail ainda vale a pena: ele confirma a venda e as datas.
       Mas a promessa de cancelar sozinho fica sem endereço, e isso precisa
       gritar em algum lugar. */
    error_log('[dext] ATENCAO: falta portal_link em stripe-config.php, '
      . 'o e-mail de boas-vindas saiu sem o link de cancelamento');
  }

  $dIni  = date('d/m/Y');
  $dFim  = quando($boas['fim_teste'] > 0 ? $boas['fim_teste'] : null);
  $vMes  = reais($boas['mensal']);
  $casa  = $boas['restaurante'] !== '' ? $boas['restaurante'] : 'o seu restaurante';
  $prim  = $boas['nome'] !== '' ? explode(' ', $boas['nome'])[0] : '';

  /* Cada frase só é dita quando o dado existe. Melhor uma linha a menos do que
     uma linha com buraco no meio, que é o que mais parece golpe num e-mail de
     cobrança. */
  $qualCartao = $boas['cartao'] !== '' ? 'o cartão final ' . $boas['cartao'] : 'o cartão cadastrado';
  $itens = [];
  $itens[] = 'Os 30 dias de teste começam hoje, ' . $dIni
           . ($dFim !== '' ? ', e vão até ' . $dFim : '') . '. Nesse período nada é cobrado.';
  if ($dFim !== '' && $vMes !== '') {
    $itens[] = 'No dia ' . $dFim . ', ' . $qualCartao . ' é cobrado em ' . $vMes
             . ' por mês, e assim todo mês, até você cancelar.';
  } elseif ($vMes !== '') {
    $itens[] = 'Terminado o teste, ' . $qualCartao . ' é cobrado em ' . $vMes
             . ' por mês, e assim todo mês, até você cancelar.';
  } else {
    $itens[] = 'Terminado o teste, ' . $qualCartao . ' passa a ser cobrado todo mês, até você cancelar.';
  }
  $itens[] = 'Sete dias antes do fim do teste, mandamos um lembrete.';
  $itens[] = $portal !== ''
    /* "link" e não "botão": na versão em texto puro, que é o que alguns
       programas de e-mail mostram, botão nenhum aparece */
    ? 'Sem fidelidade: você cancela quando quiser, sozinho, pelo link abaixo.'
    : 'Sem fidelidade: você cancela quando quiser. É só responder este e-mail ou chamar no WhatsApp.';

  $lista = '';
  foreach ($itens as $i) {
    $lista .= '<tr><td valign="top" style="padding:0 12px 12px 0;' . R_TEXTO . ';color:#e81840">&bull;</td>'
            . '<td valign="top" style="padding:0 0 12px 0;' . R_TEXTO . '">' . $e($i) . '</td></tr>';
  }

  $mioloB = '<tr><td style="padding:36px 36px 0">'
  . '<div style="font:700 11px/1.4 Helvetica,Arial,sans-serif;letter-spacing:.18em;text-transform:uppercase;color:#e81840;margin-bottom:12px">Assinatura confirmada</div>'
  . '<div style="font:700 26px/1.2 Helvetica,Arial,sans-serif;color:#111111;letter-spacing:-.02em">'
  . ($prim !== '' ? 'Oi, ' . $e($prim) . '.' : 'Boas-vindas à Dext.') . '</div>'
  . '<div style="' . R_TEXTO . ';margin-top:14px">Que bom ter ' . $e($casa)
  . ' com a gente. A sua assinatura do <strong>Dext Sistema de Gestão · ' . $e((string) $boas['plano'])
  . '</strong> está confirmada.</div></td></tr>'

  . '<tr><td style="padding:30px 36px 0">'
  . '<div style="' . R_ROTULO . ';margin-bottom:16px">Como funciona daqui pra frente</div>'
  . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $lista . '</table></td></tr>'

  . ($portal === '' ? '' : '<tr><td style="padding:14px 36px 0">'
      . '<a href="' . $e($portal) . '" style="display:inline-block;background:#ffffff;border:1px solid #262626;color:#111111;font:600 14px/1 Helvetica,Arial,sans-serif;padding:14px 22px;border-radius:4px;text-decoration:none">Gerenciar ou cancelar a assinatura</a></td></tr>')

  . '<tr><td style="padding:30px 36px 0">'
  . '<div style="border-left:3px solid #e81840;padding:4px 0 4px 18px;' . R_TEXTO . '">'
  . '<strong>Próximo passo:</strong> em até 1 dia útil, você recebe aqui o acesso ao sistema, já com a cara do seu restaurante.</div></td></tr>'

  . '<tr><td style="padding:30px 36px 36px">'
  . '<div style="font:400 14px/1.6 Helvetica,Arial,sans-serif;color:#9a9a9a">'
  . 'Os <a href="' . SITE . '/termos" style="color:#e81840;text-decoration:none">Termos de Uso</a>, o '
  . '<a href="' . SITE . '/termos#anexo" style="color:#e81840;text-decoration:none">Anexo de Tratamento de Dados</a> e a '
  . '<a href="' . SITE . '/privacidade" style="color:#e81840;text-decoration:none">Política de Privacidade</a> que você aceitou estão no nosso site.'
  . '<br><br>Qualquer dúvida, é só responder este e-mail ou chamar no WhatsApp '
  . '<a href="https://wa.me/5511940852805" style="color:#e81840;text-decoration:none">+55 11 94085-2805</a>.'
  . '<br><br>Abraço,<br>Equipe Dext</div></td></tr>';

  $htmlB = smtpMolde('Bem-vindo à Dext', $mioloB,
    'Dext Estratégias Digitais LTDA · CNPJ 60.477.038/0001-81'
    . '<br>Rua Bom Pastor, 529, sala 03, Ipiranga, São Paulo/SP, CEP 04203-050', SITE);

  $textoB = ($prim !== '' ? 'Oi, ' . $prim . ',' : 'Boas-vindas à Dext,') . "\n\n"
    . 'Que bom ter ' . $casa . ' com a gente. A sua assinatura do Dext Sistema de Gestão · '
    . $boas['plano'] . " está confirmada.\n\n"
    . "COMO FUNCIONA DAQUI PRA FRENTE\n";
  foreach ($itens as $i) { $textoB .= '- ' . $i . "\n"; }
  if ($portal !== '') { $textoB .= "\nGerenciar ou cancelar: " . $portal . "\n"; }
  $textoB .= "\nPróximo passo: em até 1 dia útil, você recebe aqui o acesso ao sistema, "
    . "já com a cara do seu restaurante.\n\n"
    . 'Os Termos de Uso, o Anexo de Tratamento de Dados e a Política de Privacidade que você '
    . 'aceitou estão em ' . SITE . '/termos e ' . SITE . "/privacidade.\n\n"
    . "Qualquer dúvida, é só responder este e-mail ou chamar no WhatsApp +55 11 94085-2805.\n\n"
    . "Abraço,\nEquipe Dext\n"
    . "Dext Estratégias Digitais LTDA · CNPJ 60.477.038/0001-81";

  $saiuBoas = smtpEnvia($cfgSmtp, REMETENTE, $limpa((string) $boas['email']),
    $assuntoDe('Bem-vindo à Dext · seus 30 dias grátis começaram'), $htmlB, $textoB, '', $erroBoas);

  if (!$saiuBoas) { error_log('[dext] boas-vindas não saiu para ' . $boas['email'] . ': ' . $erroBoas); }
}

/* =========================================================================
   6) o que dizer à Stripe
   =========================================================================
   Sempre 200: o evento foi recebido e processado, e pedir reenvio por causa
   de e-mail faria o aviso chegar repetido. O texto conta o que saiu e o que
   não saiu, e é ele que aparece em Entregas de eventos. */
$placar = 'aviso ' . ($saiuAviso ? 'ok' : 'FALHOU');
if ($saiuBoas !== null) { $placar .= ', boas-vindas ' . ($saiuBoas ? 'ok' : 'FALHOU'); }
if (!$saiuAviso || $saiuBoas === false) {
  $placar .= ' (' . mb_substr($erro !== '' ? $erro : $erroBoas, 0, 160) . ')';
}

fechaComPainel($painel, $tipo, $id, $criado, $tipo . ': ' . $placar);
