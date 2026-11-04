<?php
/* ===== Dext · recebe o formulário do site =====
   Envia dois e-mails: o aviso interno para o comercial e a confirmação para
   quem preencheu. Envia por SMTP autenticado, porque o mail() deste servidor
   devolve sucesso sem entregar: com SMTP o servidor confirma com 250 e dá para
   saber se realmente foi. As credenciais ficam em contato-config.php, que está
   no .gitignore e é bloqueado pelo .htaccess: nunca vai para o repositório.
   Responde JSON no formato que o front-end espera: {"success":"true"|"false"}.

   O SMTP vive no smtp.php, compartilhado com o webhook da Stripe. Este arquivo
   teve a própria cópia disso até 04/11/2026, quando as duas foram comparadas
   linha a linha e se provaram idênticas. */

declare(strict_types=1);

require __DIR__ . '/smtp.php';

const DESTINO   = 'comercial@dextdigital.com.br';
const REMETENTE = 'comercial@dextdigital.com.br';  // precisa ser do domínio, por causa do SPF
const SITE      = 'https://dextdigital.com.br';
const WHATSAPP  = '5511940852805';
const LIMITE    = 5;     // envios por IP
const JANELA    = 3600;  // ...por hora

header('Content-Type: application/json; charset=utf-8');

function responde(bool $ok, string $msg = '', int $status = 200): never {
  http_response_code($status);
  echo json_encode(['success' => $ok ? 'true' : 'false', 'message' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  responde(false, 'Método não permitido.', 405);
}

/* ---- lê o corpo: aceita JSON (que é o que o site manda) ou form comum ---- */
$bruto = file_get_contents('php://input') ?: '';
$dados = json_decode($bruto, true);
if (!is_array($dados)) { $dados = $_POST; }

$campo = static function (string $nome) use ($dados): string {
  $v = $dados[$nome] ?? '';
  return is_string($v) ? trim($v) : '';
};

/* ---- honeypot: bot preencheu o campo escondido, encerra fingindo sucesso ---- */
if ($campo('_honey') !== '') { responde(true); }

$nome     = $campo('nome');
$empresa  = $campo('empresa');
$email    = $campo('email');
$telefone = $campo('telefone');
$cidade   = $campo('cidade');
$interesse= $campo('interesse');
$mensagem = $campo('mensagem');

if ($nome === '' || $empresa === '' || $email === '' || $telefone === '') {
  responde(false, 'Preencha os campos obrigatórios.', 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  responde(false, 'E-mail inválido.', 422);
}
if (mb_strlen($mensagem) > 5000) { $mensagem = mb_substr($mensagem, 0, 5000) . '…'; }

/* ---- limite por IP: a confirmação vai para um endereço que o visitante digita,
       então sem isto o formulário viraria um trampolim para disparar e-mail ---- */
$ip   = (string) ($_SERVER['REMOTE_ADDR'] ?? '0');
$arq  = sys_get_temp_dir() . '/dext-form-' . md5($ip);
$hoje = time();
$hist = [];
if (is_file($arq)) {
  foreach (explode(',', (string) file_get_contents($arq)) as $t) {
    $t = (int) $t;
    if ($t > $hoje - JANELA) { $hist[] = $t; }
  }
}
if (count($hist) >= LIMITE) {
  responde(false, 'Muitas tentativas seguidas. Tente novamente mais tarde.', 429);
}
$hist[] = $hoje;
@file_put_contents($arq, implode(',', $hist), LOCK_EX);

/* ---- utilidades ---- */
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$limpa = static fn(string $s): string => str_replace(["\r", "\n"], ' ', $s);

date_default_timezone_set('America/Sao_Paulo');
$quando = date('d/m/Y \à\s H:i');
$primeiro = explode(' ', trim($nome))[0];

/* WhatsApp do lead, para o comercial responder em um clique */
$digitos = preg_replace('/\D+/', '', $telefone) ?? '';
if (strlen($digitos) >= 10 && strlen($digitos) <= 11) { $digitos = '55' . $digitos; }
$waLead = (strlen($digitos) >= 12 && strlen($digitos) <= 13) ? 'https://wa.me/' . $digitos : '';

/* estilos repetidos */
const R_ROTULO = 'font:600 11px/1.4 Helvetica,Arial,sans-serif;letter-spacing:.12em;text-transform:uppercase;color:#9a9a9a';
const R_TEXTO  = 'font:400 16px/1.6 Helvetica,Arial,sans-serif;color:#111111';

/* =========================================================================
   1) AVISO INTERNO — para o comercial
   ========================================================================= */
$linhas = '';
foreach ([
  'Nome' => $nome, 'Restaurante' => $empresa, 'E-mail' => $email,
  'WhatsApp' => $telefone, 'Cidade' => $cidade, 'Interesse' => $interesse,
] as $rotulo => $valor) {
  if ($valor === '') { continue; }
  $mostra = $e($valor);
  if ($rotulo === 'E-mail') { $mostra = '<a href="mailto:' . $e($email) . '" style="color:#e81840;text-decoration:none">' . $mostra . '</a>'; }
  if ($rotulo === 'WhatsApp' && $waLead !== '') { $mostra = '<a href="' . $e($waLead) . '" style="color:#e81840;text-decoration:none">' . $mostra . '</a>'; }
  $linhas .= '<tr>'
    . '<td valign="top" style="padding:14px 0;border-bottom:1px solid #ececeb;' . R_ROTULO . ';white-space:nowrap">' . $e($rotulo) . '</td>'
    . '<td valign="top" style="padding:14px 0 14px 24px;border-bottom:1px solid #ececeb;' . R_TEXTO . '">' . $mostra . '</td>'
    . '</tr>';
}
if ($mensagem !== '') {
  $linhas .= '<tr><td colspan="2" style="padding:28px 0 0">'
    . '<div style="' . R_ROTULO . ';margin-bottom:10px">Mensagem</div>'
    . '<div style="border-left:3px solid #e81840;padding:4px 0 4px 18px;' . R_TEXTO . '">' . nl2br($e($mensagem)) . '</div></td></tr>';
}
$botaoWa = $waLead === '' ? '' :
  '<td style="padding-left:10px"><a href="' . $e($waLead) . '" style="display:inline-block;background:#ffffff;border:1px solid #262626;color:#111111;font:600 14px/1 Helvetica,Arial,sans-serif;padding:14px 22px;border-radius:4px;text-decoration:none">Chamar no WhatsApp</a></td>';

$miolo = '<tr><td style="padding:36px 36px 0">'
. '<div style="font:700 11px/1.4 Helvetica,Arial,sans-serif;letter-spacing:.18em;text-transform:uppercase;color:#e81840;margin-bottom:12px">Novo contato pelo site</div>'
. '<div style="font:700 26px/1.2 Helvetica,Arial,sans-serif;color:#111111;letter-spacing:-.02em">' . $e($nome) . '</div>'
. '<div style="font:400 15px/1.5 Helvetica,Arial,sans-serif;color:#9a9a9a;margin-top:6px">' . $e($empresa) . ' · ' . $e($quando) . '</div></td></tr>'
. '<tr><td style="padding:26px 36px 0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $linhas . '</table></td></tr>'
. '<tr><td style="padding:30px 36px 36px"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
. '<td><a href="mailto:' . $e($email) . '?subject=' . rawurlencode('Sobre o seu contato · Dext') . '" style="display:inline-block;background:#e81840;color:#ffffff;font:600 14px/1 Helvetica,Arial,sans-serif;padding:15px 24px;border-radius:4px;text-decoration:none">Responder por e-mail</a></td>'
. $botaoWa . '</tr></table></td></tr>';

$htmlInterno = smtpMolde('Novo contato pelo site · Dext', $miolo,
  'Enviado pelo formulário de <a href="' . SITE . '" style="color:#9a9a9a">dextdigital.com.br</a>'
  . '<br>Dext Estratégias Digitais LTDA · Tecnologia e gestão para restaurantes', SITE);

$textoInterno = "NOVO CONTATO PELO SITE\n\nNome: $nome\nRestaurante: $empresa\nE-mail: $email\nWhatsApp: $telefone\n"
  . ($cidade !== '' ? "Cidade: $cidade\n" : '')
  . ($interesse !== '' ? "Interesse: $interesse\n" : '')
  . ($mensagem !== '' ? "\nMensagem:\n$mensagem\n" : '')
  . "\nRecebido em $quando · " . SITE;

/* =========================================================================
   2) CONFIRMAÇÃO — para quem preencheu
   ========================================================================= */
$resumo = '';
foreach (['Restaurante' => $empresa, 'Interesse' => $interesse, 'WhatsApp' => $telefone] as $rotulo => $valor) {
  if ($valor === '') { continue; }
  $resumo .= '<tr>'
    . '<td valign="top" style="padding:11px 0;border-bottom:1px solid #ececeb;' . R_ROTULO . ';white-space:nowrap">' . $e($rotulo) . '</td>'
    . '<td valign="top" style="padding:11px 0 11px 24px;border-bottom:1px solid #ececeb;font:400 15px/1.5 Helvetica,Arial,sans-serif;color:#111111">' . $e($valor) . '</td>'
    . '</tr>';
}

$mioloLead = '<tr><td style="padding:36px 36px 0">'
. '<div style="font:700 11px/1.4 Helvetica,Arial,sans-serif;letter-spacing:.18em;text-transform:uppercase;color:#e81840;margin-bottom:12px">Contato recebido</div>'
. '<div style="font:700 26px/1.2 Helvetica,Arial,sans-serif;color:#111111;letter-spacing:-.02em">Recebemos a sua mensagem, ' . $e($primeiro) . '.</div>'
. '<div style="' . R_TEXTO . ';margin-top:16px">Nossa equipe vai ler com calma e entrar em contato pelo e-mail ou pelo WhatsApp. '
. 'O atendimento é de segunda a sexta, das 9h às 18h.</div>'
. '</td></tr>'

. ($resumo === '' ? '' : '<tr><td style="padding:30px 36px 0">'
  . '<div style="' . R_ROTULO . ';margin-bottom:6px">O que você nos enviou</div>'
  . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $resumo . '</table></td></tr>')

. '<tr><td style="padding:30px 36px 36px">'
. '<a href="https://wa.me/' . WHATSAPP . '" style="display:inline-block;background:#e81840;color:#ffffff;font:600 14px/1 Helvetica,Arial,sans-serif;padding:15px 24px;border-radius:4px;text-decoration:none">Falar agora no WhatsApp</a>'
. '<div style="font:400 14px/1.6 Helvetica,Arial,sans-serif;color:#9a9a9a;margin-top:18px">Se preferir, é só responder a este e-mail.</div>'
. '</td></tr>';

$htmlLead = smtpMolde('Recebemos o seu contato · Dext', $mioloLead,
  'Dext · Tecnologia e gestão para restaurantes'
  . '<br><a href="' . SITE . '" style="color:#9a9a9a">dextdigital.com.br</a> · comercial@dextdigital.com.br · (11) 94085-2805', SITE);

$textoLead = "Recebemos a sua mensagem, $primeiro.\n\n"
  . "Nossa equipe vai ler com calma e entrar em contato pelo e-mail ou pelo WhatsApp.\n"
  . "Atendimento de segunda a sexta, das 9h às 18h.\n\n"
  . "Falar agora no WhatsApp: https://wa.me/" . WHATSAPP . "\n\n"
  . "Dext · Tecnologia e gestão para restaurantes\n" . SITE;

/* =========================================================================
   envio
   ========================================================================= */
$cfgSmtp = smtpConfig(__DIR__ . '/contato-config.php');

/* o aviso interno é o que não pode falhar: é ele que decide a resposta ao site */
$erro = '';
if (!smtpEnvia($cfgSmtp, REMETENTE, DESTINO,
               'Novo contato pelo site · Dext — ' . $limpa($empresa), $htmlInterno, $textoInterno,
               $limpa($nome) . ' <' . $limpa($email) . '>', $erro)) {
  error_log('[dext] aviso interno falhou (' . $email . '): ' . $erro);
  responde(false, 'Não foi possível enviar agora. ' . $erro, 502);
}

/* a confirmação é bônus: se falhar, o lead já está salvo e o site não deve acusar erro */
$erro2 = '';
if (!smtpEnvia($cfgSmtp, REMETENTE, $limpa($email),
               'Recebemos o seu contato · Dext', $htmlLead, $textoLead, DESTINO, $erro2)) {
  error_log('[dext] aviso interno ok, mas confirmação falhou para ' . $email . ': ' . $erro2);
}

responde(true);
