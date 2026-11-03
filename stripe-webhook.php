<?php
/* ===== Dext · aviso de nova assinatura =====
   A Stripe chama este arquivo quando alguém termina o checkout. Ele confere
   que a chamada é mesmo da Stripe, lê os dados do cliente e manda o aviso
   para o comercial, que cria a conta no sistema à mão.

   Por que checkout.session.completed e não customer.subscription.created:
   o nome do restaurante é um campo nosso do checkout e o telefone é coletado
   lá também. Os dois vivem na sessão, não na assinatura. Ouvindo a assinatura
   o aviso chegaria sem restaurante e sem telefone, que é metade do que o
   comercial precisa para ligar. Os dois eventos acontecem no mesmo instante
   quando a venda nasce de um checkout, então não se perde nada.

   O segredo de assinatura fica em stripe-config.php, que está no .gitignore e
   é negado pelo .htaccess. */

declare(strict_types=1);

require __DIR__ . '/smtp.php';

const DESTINO    = 'adm@dextdigital.com.br';
const REMETENTE  = 'comercial@dextdigital.com.br';  // do domínio, por causa do SPF
const SITE       = 'https://dextdigital.com.br';
const TOLERANCIA = 300;  // segundos de diferença aceitos no carimbo da Stripe

/* A Stripe repete a chamada até receber 200. Mesmo quando não há nada a fazer
   a resposta é 200, senão ela fica reenviando um evento que a gente ignora
   de propósito. */
function encerra(int $status, string $msg = 'ok'): never {
  http_response_code($status);
  header('Content-Type: text/plain; charset=utf-8');
  echo $msg;
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { encerra(405, 'Método não permitido.'); }

$cfg     = is_file(__DIR__ . '/stripe-config.php') ? (array) require __DIR__ . '/stripe-config.php' : [];
$segredo = (string) ($cfg['webhook_secret'] ?? '');
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

if ($tipo !== 'checkout.session.completed') { encerra(200, 'ignorado'); }

/* A Stripe reenvia o mesmo evento quando a resposta demora. Sem esta marca o
   comercial receberia o mesmo aviso três vezes. */
$marca = sys_get_temp_dir() . '/dext-stripe-' . preg_replace('/[^a-zA-Z0-9_]/', '', $id);
if ($id !== '' && is_file($marca) && filemtime($marca) > time() - 86400) { encerra(200, 'repetido'); }
@touch($marca);

$s = (array) ($evento['data']['object'] ?? []);

/* assinatura ainda não paga (período de teste) é o caso normal aqui, então
   não se olha payment_status: o que importa é que o checkout foi concluído */
$cliente   = (array) ($s['customer_details'] ?? []);
$nome      = trim((string) ($cliente['name']  ?? ''));
$email     = trim((string) ($cliente['email'] ?? ''));
$telefone  = trim((string) ($cliente['phone'] ?? ''));
$plano     = trim((string) ($s['metadata']['plano'] ?? ''));
$assinatura= trim((string) ($s['subscription'] ?? ''));

$restaurante = '';
foreach ((array) ($s['custom_fields'] ?? []) as $campo) {
  if (($campo['key'] ?? '') === 'restaurante') {
    $restaurante = trim((string) ($campo['text']['value'] ?? ''));
  }
}

/* =========================================================================
   3) o aviso
   ========================================================================= */
date_default_timezone_set('America/Sao_Paulo');
$quando = date('d/m/Y \à\s H:i');
$e = static fn(string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$limpa = static fn(string $t): string => str_replace(["\r", "\n"], ' ', $t);

/* WhatsApp do assinante, para o comercial responder em um clique */
$digitos = preg_replace('/\D+/', '', $telefone) ?? '';
if (strlen($digitos) >= 10 && strlen($digitos) <= 11) { $digitos = '55' . $digitos; }
$wa = (strlen($digitos) >= 12 && strlen($digitos) <= 13) ? 'https://wa.me/' . $digitos : '';

const R_ROTULO = 'font:600 11px/1.4 Helvetica,Arial,sans-serif;letter-spacing:.12em;text-transform:uppercase;color:#9a9a9a';
const R_TEXTO  = 'font:400 16px/1.6 Helvetica,Arial,sans-serif;color:#111111';

$linhas = '';
foreach ([
  'Nome' => $nome, 'Restaurante' => $restaurante, 'E-mail' => $email,
  'WhatsApp' => $telefone, 'Plano' => $plano, 'Assinatura' => $assinatura,
] as $rotulo => $valor) {
  if ($valor === '') { continue; }
  $mostra = $e($valor);
  if ($rotulo === 'E-mail')   { $mostra = '<a href="mailto:' . $e($email) . '" style="color:#e81840;text-decoration:none">' . $mostra . '</a>'; }
  if ($rotulo === 'WhatsApp' && $wa !== '') { $mostra = '<a href="' . $e($wa) . '" style="color:#e81840;text-decoration:none">' . $mostra . '</a>'; }
  if ($rotulo === 'Assinatura') { $mostra = '<span style="font-family:ui-monospace,Menlo,monospace;font-size:13px;color:#9a9a9a">' . $mostra . '</span>'; }
  $linhas .= '<tr>'
    . '<td valign="top" style="padding:14px 0;border-bottom:1px solid #ececeb;' . R_ROTULO . ';white-space:nowrap">' . $e($rotulo) . '</td>'
    . '<td valign="top" style="padding:14px 0 14px 24px;border-bottom:1px solid #ececeb;' . R_TEXTO . '">' . $mostra . '</td>'
    . '</tr>';
}

$botaoWa = $wa === '' ? '' :
  '<td style="padding-left:10px"><a href="' . $e($wa) . '" style="display:inline-block;background:#ffffff;border:1px solid #262626;color:#111111;font:600 14px/1 Helvetica,Arial,sans-serif;padding:14px 22px;border-radius:4px;text-decoration:none">Chamar no WhatsApp</a></td>';

$miolo = '<tr><td style="padding:36px 36px 0">'
. '<div style="font:700 11px/1.4 Helvetica,Arial,sans-serif;letter-spacing:.18em;text-transform:uppercase;color:#e81840;margin-bottom:12px">Nova assinatura</div>'
. '<div style="font:700 26px/1.2 Helvetica,Arial,sans-serif;color:#111111;letter-spacing:-.02em">' . $e($restaurante !== '' ? $restaurante : $nome) . '</div>'
. '<div style="font:400 15px/1.5 Helvetica,Arial,sans-serif;color:#9a9a9a;margin-top:6px">' . $e($plano) . ' · ' . $e($quando) . '</div></td></tr>'
. '<tr><td style="padding:26px 36px 0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $linhas . '</table></td></tr>'
. '<tr><td style="padding:26px 36px 0">'
. '<div style="border-left:3px solid #e81840;padding:4px 0 4px 18px;' . R_TEXTO . '">A conta no sistema é criada à mão, em até 1 dia útil.</div></td></tr>'
. '<tr><td style="padding:26px 36px 36px"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
. '<td><a href="mailto:' . $e($email) . '?subject=' . rawurlencode('Sua conta na Dext') . '" style="display:inline-block;background:#e81840;color:#ffffff;font:600 14px/1 Helvetica,Arial,sans-serif;padding:15px 24px;border-radius:4px;text-decoration:none">Responder por e-mail</a></td>'
. $botaoWa . '</tr></table></td></tr>';

$html = smtpMolde('Nova assinatura · Dext', $miolo,
  'Disparado pela Stripe quando o checkout foi concluído'
  . '<br>Dext Estratégias Digitais LTDA · Tecnologia e gestão para restaurantes', SITE);

$texto = "NOVA ASSINATURA\n\nNome: $nome\nRestaurante: $restaurante\nE-mail: $email\n"
  . "WhatsApp: $telefone\nPlano: $plano\nAssinatura: $assinatura\n\n"
  . "A conta no sistema é criada à mão, em até 1 dia útil.\n\nRecebido em $quando · " . SITE;

$erro = '';
$assunto = 'Nova assinatura · ' . ($restaurante !== '' ? $limpa($restaurante) : $limpa($nome)) . ' · ' . $limpa($plano);

if (!smtpEnvia(smtpConfig(__DIR__ . '/contato-config.php'), REMETENTE, DESTINO, $assunto,
               $html, $texto, $email !== '' ? $limpa($email) : '', $erro)) {
  /* O aviso falhou, mas a venda aconteceu. Responder erro faria a Stripe
     repetir o evento, e o comercial receberia a mesma venda várias vezes.
     Então a resposta segue 200 e o motivo vai junto: no painel da Stripe,
     em Entregas de eventos, dá para ler o que houve sem precisar do log do
     servidor. É esse texto que diz se o problema é conexão, autenticação ou
     caixa de destino. A senha nunca entra aqui: o smtpFala mascara o AUTH. */
  error_log('[dext] aviso de assinatura não saiu (' . $email . ' / ' . $assinatura . '): ' . $erro);
  encerra(200, 'venda ok, aviso NAO saiu: ' . mb_substr($erro, 0, 300));
}

encerra(200, 'ok, aviso enviado para ' . DESTINO);
