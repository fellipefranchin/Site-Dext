<?php
/* ===== Dext · envio de e-mail por SMTP autenticado =====
   O mail() deste servidor devolve sucesso sem entregar. Falando SMTP na mão o
   servidor confirma com 250, e aí dá para saber se foi de verdade.

   Este código nasceu dentro do contato.php e foi extraído para cá quando o
   webhook da Stripe passou a precisar da mesma coisa. Durante algumas semanas
   houve duas cópias, e a nota aqui pedia para corrigir as duas. Em 04/11/2026
   elas foram comparadas linha a linha, se provaram idênticas, e o contato.php
   passou a usar este arquivo. Agora é uma só: quem usa é o contato.php e o
   stripe-webhook.php, e qualquer correção aqui vale para os dois. */

declare(strict_types=1);

/* ---- lê as credenciais de um arquivo que não vai para o repositório ---- */
function smtpConfig(string $arquivo): array {
  static $cache = [];
  if (!isset($cache[$arquivo])) {
    $cache[$arquivo] = is_file($arquivo) ? (array) require $arquivo : [];
  }
  return $cache[$arquivo];
}

/* ---- lê uma resposta do servidor (pode vir em várias linhas: "250-" continua) ---- */
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

/* ---- envia de verdade ---- */
function smtpEnvia(
  array $cfg, string $remetente, string $para, string $assunto,
  string $html, string $texto, string $replyTo = '', ?string &$erro = null
): bool {
  foreach (['host', 'porta', 'usuario', 'senha'] as $k) {
    if (empty($cfg[$k])) { $erro = "SMTP não configurado (falta '$k')"; return false; }
  }

  $limite = 'dext' . bin2hex(random_bytes(12));
  $cab = [
    'Date: ' . date('r'),
    'From: Dext <' . $remetente . '>',
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
     && smtpFala($sock, 'MAIL FROM:<' . $remetente . '>', 250, $erro)
     && smtpFala($sock, 'RCPT TO:<' . $para . '>', 250, $erro)
     && smtpFala($sock, 'DATA', 354, $erro)
     && smtpFala($sock, $mensagem . "\r\n.", 250, $erro);

  @fwrite($sock, "QUIT\r\n");
  @fclose($sock);
  return $ok;
}

/* ---- moldura visual comum: cabeçalho preto, logo, filete vermelho ---- */
function smtpMolde(string $titulo, string $miolo, string $rodape, string $site = 'https://dextdigital.com.br'): string {
  $t = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
  return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
  . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light only">'
  . '<title>' . $t . '</title></head><body style="margin:0;padding:0;background:#f5f5f4">'
  . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f5f4"><tr><td align="center" style="padding:32px 16px">'
  . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:100%;background:#ffffff;border-radius:8px;overflow:hidden">'
  . '<tr><td style="background:#111111;padding:30px 36px">'
  /* o estilo no <img> também vale para o texto alternativo: com imagem bloqueada,
     aparece "Dext" em branco sobre a faixa preta, e não um quadro vazio */
  . '<img src="' . $site . '/assets/img/logo-email.png" width="200" height="63" alt="Dext"'
  . ' style="display:block;width:200px;height:63px;border:0;color:#ffffff;font:700 30px/63px Helvetica,Arial,sans-serif;letter-spacing:-.02em">'
  . '</td></tr>'
  . '<tr><td style="background:#e81840;height:3px;line-height:3px;font-size:0">&nbsp;</td></tr>'
  . $miolo
  . '<tr><td style="background:#f5f5f4;padding:22px 36px;border-top:1px solid #ececeb">'
  . '<div style="font:400 12px/1.6 Helvetica,Arial,sans-serif;color:#9a9a9a">' . $rodape . '</div>'
  . '</td></tr></table></td></tr></table></body></html>';
}
