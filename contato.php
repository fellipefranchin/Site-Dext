<?php
/* ===== Dext · recebe o formulário do site =====
   Envia dois e-mails: o aviso interno para o comercial e a confirmação para
   quem preencheu. Envia por SMTP autenticado, porque o mail() deste servidor
   devolve sucesso sem entregar — com SMTP o servidor confirma com 250 e dá para
   saber se realmente foi. As credenciais ficam em contato-config.php, que está
   no .gitignore e é bloqueado pelo .htaccess: nunca vai para o repositório.
   Responde JSON no formato que o front-end espera: {"success":"true"|"false"}. */

declare(strict_types=1);

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

/* ---- moldura comum aos dois e-mails: cabeçalho preto, logo, filete vermelho ---- */
function molde(string $titulo, string $miolo, string $rodape): string {
  $t = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
  return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
  . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light only">'
  . '<title>' . $t . '</title></head><body style="margin:0;padding:0;background:#f5f5f4">'
  . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f5f4"><tr><td align="center" style="padding:32px 16px">'
  . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:100%;background:#ffffff;border-radius:8px;overflow:hidden">'
  . '<tr><td style="background:#111111;padding:30px 36px">'
  /* o estilo no <img> também vale para o texto alternativo: com imagem bloqueada,
     aparece "Dext" em branco sobre a faixa preta, e não um quadro vazio */
  . '<img src="' . SITE . '/assets/img/logo-email.png" width="200" height="63" alt="Dext"'
  . ' style="display:block;width:200px;height:63px;border:0;color:#ffffff;font:700 30px/63px Helvetica,Arial,sans-serif;letter-spacing:-.02em">'
  . '</td></tr>'
  . '<tr><td style="background:#e81840;height:3px;line-height:3px;font-size:0">&nbsp;</td></tr>'
  . $miolo
  . '<tr><td style="background:#f5f5f4;padding:22px 36px;border-top:1px solid #ececeb">'
  . '<div style="font:400 12px/1.6 Helvetica,Arial,sans-serif;color:#9a9a9a">' . $rodape . '</div>'
  . '</td></tr></table></td></tr></table></body></html>';
}

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

$htmlInterno = molde('Novo contato pelo site · Dext', $miolo,
  'Enviado pelo formulário de <a href="' . SITE . '" style="color:#9a9a9a">dextdigital.com.br</a>'
  . '<br>Dext Estratégias Digitais LTDA · Tecnologia e gestão para restaurantes');

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

$htmlLead = molde('Recebemos o seu contato · Dext', $mioloLead,
  'Dext · Tecnologia e gestão para restaurantes'
  . '<br><a href="' . SITE . '" style="color:#9a9a9a">dextdigital.com.br</a> · comercial@dextdigital.com.br · (11) 94085-2805');

$textoLead = "Recebemos a sua mensagem, $primeiro.\n\n"
  . "Nossa equipe vai ler com calma e entrar em contato pelo e-mail ou pelo WhatsApp.\n"
  . "Atendimento de segunda a sexta, das 9h às 18h.\n\n"
  . "Falar agora no WhatsApp: https://wa.me/" . WHATSAPP . "\n\n"
  . "Dext · Tecnologia e gestão para restaurantes\n" . SITE;

/* =========================================================================
   envio
   ========================================================================= */
/* ---- credenciais do SMTP, num arquivo à parte que não vai para o repositório ---- */
function config(): array {
  static $c = null;
  if ($c === null) {
    $arq = __DIR__ . '/contato-config.php';
    $c = is_file($arq) ? (array) require $arq : [];
  }
  return $c;
}

/* ---- lê uma resposta do servidor SMTP (pode vir em várias linhas: "250-" continua) ---- */
function smtpLe($sock): array {
  $texto = '';
  while (($linha = fgets($sock, 515)) !== false) {
    $texto .= $linha;
    if (strlen($linha) < 4 || $linha[3] !== '-') { break; }
  }
  return [(int) substr($texto, 0, 3), trim($texto)];
}

function smtpFala($sock, string $comando, int $esperado, string &$erro): bool {
  if ($comando !== '') { fwrite($sock, $comando . "\r\n"); }
  [$codigo, $resposta] = smtpLe($sock);
  if ($codigo !== $esperado) {
    $visivel = preg_replace('/^AUTH.*/i', 'AUTH ...', $comando);  // não registra a senha
    $erro = "esperava $esperado, veio $codigo ($resposta) em: " . mb_substr($visivel, 0, 40);
    return false;
  }
  return true;
}

/* ---- envia de verdade: o servidor confirma com 250, então dá para saber se entregou ---- */
function envia(string $para, string $assunto, string $html, string $texto, string $replyTo, ?string &$erro = null): bool {
  $cfg = config();
  foreach (['host', 'porta', 'usuario', 'senha'] as $k) {
    if (empty($cfg[$k])) { $erro = "SMTP não configurado (falta '$k' em contato-config.php)"; return false; }
  }

  $limite = 'dext' . bin2hex(random_bytes(12));
  $cab = [
    'Date: ' . date('r'),
    'From: Dext <' . REMETENTE . '>',
    'To: ' . $para,
    'Subject: =?UTF-8?B?' . base64_encode($assunto) . '?=',
    'Message-ID: <' . bin2hex(random_bytes(10)) . '@dextdigital.com.br>',
    'MIME-Version: 1.0',
    'Content-Type: multipart/alternative; boundary="' . $limite . '"',
    'X-Mailer: Dext',
  ];
  if ($replyTo !== '') { $cab[] = 'Reply-To: ' . $replyTo; }

  $corpo = "--$limite\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$texto\r\n\r\n"
    . "--$limite\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$html\r\n\r\n--$limite--";

  $mensagem = implode("\r\n", $cab) . "\r\n\r\n" . $corpo;
  /* linha iniciada por ponto precisa ser duplicada, senão encerra o DATA no meio */
  $mensagem = preg_replace('/^\./m', '..', str_replace("\n", "\r\n", str_replace("\r\n", "\n", $mensagem)));

  $esquema = (($cfg['seguranca'] ?? 'ssl') === 'ssl') ? 'ssl://' : 'tcp://';
  $sock = @stream_socket_client($esquema . $cfg['host'] . ':' . $cfg['porta'], $n, $m, 15, STREAM_CLIENT_CONNECT);
  if (!$sock) { $erro = "não conectou em {$cfg['host']}:{$cfg['porta']} ($m)"; return false; }
  stream_set_timeout($sock, 15);

  $ok = smtpFala($sock, '', 220, $erro)
     && smtpFala($sock, 'EHLO dextdigital.com.br', 250, $erro);

  if ($ok && ($cfg['seguranca'] ?? 'ssl') === 'tls') {
    $ok = smtpFala($sock, 'STARTTLS', 220, $erro)
       && @stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)
       && smtpFala($sock, 'EHLO dextdigital.com.br', 250, $erro);
  }

  $ok = $ok
     && smtpFala($sock, 'AUTH LOGIN', 334, $erro)
     && smtpFala($sock, base64_encode((string) $cfg['usuario']), 334, $erro)
     && smtpFala($sock, base64_encode((string) $cfg['senha']), 235, $erro)
     && smtpFala($sock, 'MAIL FROM:<' . REMETENTE . '>', 250, $erro)
     && smtpFala($sock, 'RCPT TO:<' . $para . '>', 250, $erro)
     && smtpFala($sock, 'DATA', 354, $erro)
     && smtpFala($sock, $mensagem . "\r\n.", 250, $erro);

  @fwrite($sock, "QUIT\r\n");
  @fclose($sock);
  return $ok;
}

/* o aviso interno é o que não pode falhar: é ele que decide a resposta ao site */
$erro = '';
if (!envia(DESTINO, 'Novo contato pelo site · Dext — ' . $limpa($empresa), $htmlInterno, $textoInterno,
           $limpa($nome) . ' <' . $limpa($email) . '>', $erro)) {
  error_log('[dext] aviso interno falhou (' . $email . '): ' . $erro);
  responde(false, 'Não foi possível enviar agora. ' . $erro, 502);
}

/* a confirmação é bônus: se falhar, o lead já está salvo e o site não deve acusar erro */
$erro2 = '';
if (!envia($limpa($email), 'Recebemos o seu contato · Dext', $htmlLead, $textoLead, DESTINO, $erro2)) {
  error_log('[dext] aviso interno ok, mas confirmação falhou para ' . $email . ': ' . $erro2);
}

responde(true);
