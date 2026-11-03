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

if (!in_array($tipo, [
  'checkout.session.completed', 'invoice.payment_failed',
  'customer.subscription.deleted', 'customer.subscription.trial_will_end',
], true)) {
  encerra(200, 'ignorado: ' . $tipo);
}

/* A Stripe reenvia o mesmo evento quando a resposta demora. Sem esta marca
   o mesmo aviso chegaria três vezes. */
$marca = sys_get_temp_dir() . '/dext-stripe-' . preg_replace('/[^a-zA-Z0-9_]/', '', $id);
if ($id !== '' && is_file($marca) && filemtime($marca) > time() - 86400) { encerra(200, 'repetido'); }
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

$aviso = null;

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

  /* Grava o restaurante na assinatura. Sem isto, o aviso de cancelamento e o
     de cartão recusado chegariam sabendo o nome da pessoa e não o da casa,
     que é como o comercial reconhece o cliente. */
  if ($assinatura !== '' && $restaurante !== '' && $chave !== '') {
    [$st,, $falha] = stripeChama('POST', '/v1/subscriptions/' . rawurlencode($assinatura),
      ['metadata[restaurante]' => $restaurante, 'metadata[plano]' => $plano], $chave);
    if ($falha !== '' || $st !== 200) {
      error_log('[dext] não gravei o restaurante em ' . $assinatura . ' (' . $st . ') ' . $falha);
    }
  }

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

  /* o restaurante foi gravado na assinatura na hora da venda */
  $restaurante = '';
  if ($assinatura !== '' && $chave !== '') {
    $a = stripeBusca('/v1/subscriptions/' . rawurlencode($assinatura), $chave);
    $restaurante = trim((string) ($a['metadata']['restaurante'] ?? ''));
  }

  $aviso = [
    'kicker'  => 'Cartão recusado',
    'titulo'  => $restaurante !== '' ? $restaurante : ($nome !== '' ? $nome : 'Cliente'),
    'sub'     => trim($valor . ' · tentativa ' . $tentativa, ' ·'),
    'linhas'  => ['Nome' => $nome, 'Restaurante' => $restaurante, 'E-mail' => $email,
                  'WhatsApp' => $telefone, 'Valor' => $valor,
                  'Tentativa' => $tentativa > 0 ? (string) $tentativa : '',
                  'Próxima tentativa' => $proxima, 'Assinatura' => $assinatura],
    'recado'  => 'A Stripe tenta de novo sozinha. Se todas falharem, a assinatura é cancelada '
               . 'e o acesso ao sistema precisa ser fechado à mão. Vale ligar antes disso.',
    'assunto' => 'Cartão recusado · ' . ($restaurante !== '' ? $restaurante : $nome),
    'email'   => $email,
    'telefone'=> $telefone,
    'link'    => $assinatura !== '' ? PAINEL . $assinatura : '',
  ];
}

elseif ($tipo === 'customer.subscription.deleted') {
  $cli         = buscaCliente((string) ($obj['customer'] ?? ''), $chave);
  $plano       = trim((string) ($obj['metadata']['plano'] ?? ''));
  $restaurante = trim((string) ($obj['metadata']['restaurante'] ?? ''));
  $assinatura  = trim((string) ($obj['id'] ?? ''));
  $det         = (array) ($obj['cancellation_details'] ?? []);
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
    'recado'  => 'O acesso ao sistema continua aberto até alguém fechar à mão.',
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
   4) o aviso
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
  if ($rotulo === 'Assinatura') { $mostra = '<span style="font-family:ui-monospace,Menlo,monospace;font-size:13px;color:#9a9a9a">' . $mostra . '</span>'; }
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

$erro = '';
if (!smtpEnvia(smtpConfig(__DIR__ . '/contato-config.php'), REMETENTE, DESTINO,
               $limpa((string) $aviso['assunto']), $html, $texto,
               ($aviso['email'] ?? '') !== '' ? $limpa((string) $aviso['email']) : '', $erro)) {
  /* O aviso falhou, mas o evento aconteceu. Responder erro faria a Stripe
     repetir, e o comercial receberia o mesmo aviso várias vezes. Então a
     resposta segue 200 e o motivo vai junto: no painel da Stripe, em
     Entregas de eventos, dá para ler o que houve sem o log do servidor.
     A senha nunca entra aqui: o smtpFala mascara o AUTH. */
  error_log('[dext] aviso "' . $tipo . '" não saiu: ' . $erro);
  encerra(200, 'evento ok, aviso NAO saiu: ' . mb_substr($erro, 0, 300));
}

encerra(200, 'ok, aviso de ' . $tipo . ' enviado para ' . DESTINO);
