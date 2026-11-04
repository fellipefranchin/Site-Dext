<?php
/* ===== Dext · o Raio-X fala com o modelo por aqui =====
   A página do Raio-X manda a conversa até agora, este arquivo chama o modelo
   e devolve a resposta mais a ficha de dados já estruturada.

   Por que existe: a chave de API não pode viver no navegador, porque tudo que
   está no navegador o visitante lê. E aqui o chamador é visitante anônimo em
   página pública, então o risco não é só vazar a chave: é alguém descobrir o
   endereço e gastar a conta do mês numa tarde. Daí os três limites abaixo.

   Sem biblioteca, chamada HTTP direta, pelo mesmo stripe.php que já fala com
   a Stripe: a hospedagem é compartilhada e o projeto não tem dependência
   instalada.

   O que ele NÃO faz: conta. Nenhum número sai daqui. O motor.js calcula no
   navegador e o modelo só apresenta o que recebe pronto. Essa separação é a
   garantia de que o diagnóstico não é inventado, e ela é de arquitetura, não
   de prompt: prompt é instrução, não trava.

   Responde JSON:
     {"ok":true,"resposta":"...","ficha":{...}}
     {"ok":false,"erro":"...","roteiro":true}   <- a página cai no roteiro fixo
*/

declare(strict_types=1);

require __DIR__ . '/stripe.php';   // só pelo stripeChama(); não tem Stripe nisto

const MODELO          = 'claude-sonnet-5-5';
const MAX_TOKENS      = 1024;
const PROMPT          = __DIR__ . '/raio-x/prompt-agente.md';

/* Três limites, cada um para um abuso diferente. */
const TURNOS_SESSAO   = 40;     // conversa que passa disto não é conversa
const TURNOS_IP_HORA  = 60;     // mesmo IP abrindo sessão atrás de sessão
const JANELA          = 3600;

header('Content-Type: application/json; charset=utf-8');

function responde(bool $ok, array $extra = [], int $status = 200): never {
  http_response_code($status);
  echo json_encode(['ok' => $ok] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

/* Qualquer recusa manda a página para o roteiro fixo, que faz as mesmas
   perguntas sem modelo nenhum. O visitante não fica sem Raio-X porque a API
   caiu ou porque o teto do dia estourou. */
function desiste(string $motivo, string $log = ''): never {
  if ($log !== '') { error_log('[dext][raio-x] ' . $log); }
  responde(false, ['erro' => $motivo, 'roteiro' => true]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  responde(false, ['erro' => 'Método não permitido.'], 405);
}

/* ---- configuração ---- */
$arq = __DIR__ . '/raio-x-config.php';
$cfg = is_file($arq) ? (array) require $arq : [];
$chave = (string) ($cfg['api_key'] ?? '');
if ($chave === '') { desiste('Raio-X indisponível.', 'falta api_key em raio-x-config.php'); }

/* ---- a conversa que o navegador mandou ---- */
$bruto = file_get_contents('php://input') ?: '';
$dados = json_decode($bruto, true);
if (!is_array($dados)) { responde(false, ['erro' => 'Corpo inválido.'], 422); }

$sessao = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($dados['sessao'] ?? ''));
if ($sessao === '' || strlen($sessao) > 64) { responde(false, ['erro' => 'Sessão inválida.'], 422); }

$turnos = [];
foreach ((array) ($dados['mensagens'] ?? []) as $m) {
  $papel = ($m['papel'] ?? '') === 'assistant' ? 'assistant' : 'user';
  $texto = is_string($m['texto'] ?? null) ? trim($m['texto']) : '';
  if ($texto === '') { continue; }
  /* O visitante digita isto. Um texto gigante custa caro e não melhora nada:
     ninguém descreve a casa em 4 mil caracteres. */
  if (mb_strlen($texto) > 4000) { $texto = mb_substr($texto, 0, 4000); }
  $turnos[] = ['role' => $papel, 'content' => $texto];
}
if ($turnos === []) { responde(false, ['erro' => 'Sem mensagem.'], 422); }

/* =========================================================================
   os limites
   ========================================================================= */
function contador(string $nome, int $janela): array {
  $arq  = sys_get_temp_dir() . '/dext-rx-' . md5($nome);
  $hoje = time();
  $hist = [];
  if (is_file($arq)) {
    foreach (explode(',', (string) file_get_contents($arq)) as $t) {
      $t = (int) $t;
      if ($t > $hoje - $janela) { $hist[] = $t; }
    }
  }
  return [$arq, $hist, $hoje];
}

function marca(string $arq, array $hist, int $hoje): void {
  $hist[] = $hoje;
  @file_put_contents($arq, implode(',', $hist), LOCK_EX);
}

[$arqS, $histS, $agora] = contador('sessao-' . $sessao, 86400);
if (count($histS) >= TURNOS_SESSAO) {
  desiste('Esta conversa já foi longe. Recarregue a página para começar outra.');
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0');
[$arqI, $histI] = contador('ip-' . $ip, JANELA);
if (count($histI) >= TURNOS_IP_HORA) {
  desiste('Muitas perguntas seguidas. Tente de novo mais tarde.');
}

/* Teto de gasto do dia. É o único limite que protege contra o caso que
   importa de verdade: o endereço descoberto e chamado em massa. Os outros
   dois só atrasam quem troca de IP. */
$teto   = (int) ($cfg['teto_dia_centavos'] ?? 0);
$arqDia = sys_get_temp_dir() . '/dext-rx-gasto-' . date('Y-m-d');
$gasto  = is_file($arqDia) ? (int) file_get_contents($arqDia) : 0;
if ($teto > 0 && $gasto >= $teto) {
  desiste('O Raio-X atingiu o limite de hoje.', 'teto do dia batido: ' . $gasto . ' centavos');
}

/* =========================================================================
   o prompt
   ========================================================================= */
/* Lido do prompt-agente.md, entre as duas linhas de três hífens. Assim existe
   um texto só: o arquivo que a Dext edita é o que vai ao modelo, sem cópia
   para esquecer de atualizar. */
function promptDoAgente(string $arquivo): string {
  $md = is_file($arquivo) ? (string) file_get_contents($arquivo) : '';
  if ($md === '') { return ''; }
  $i = strpos($md, "\n---\n");
  if ($i === false) { return ''; }
  $j = strpos($md, "\n---\n", $i + 5);
  return $j === false ? '' : trim(substr($md, $i + 5, $j - $i - 5));
}

$sistema = promptDoAgente(PROMPT);
if ($sistema === '') { desiste('Raio-X indisponível.', 'não consegui ler ' . PROMPT); }

/* =========================================================================
   a ficha
   ========================================================================= */
/* O modelo responde SEMPRE por esta ferramenta, nunca em texto solto. Assim a
   conversa pode ser adaptável e a coleta não: cada turno devolve a ficha
   inteira, com null no que ainda não sabe, e o comercial consegue comparar um
   lead com outro. Pedir "responda em JSON" no prompt dá quase sempre certo, e
   o quase é justamente o lead que se perde. */
$campos = [
  'dor'            => ['string',  'o que mais incomoda a pessoa hoje na casa'],
  'tipoCasa'       => ['string',  'tipo de operação: pizzaria, bistrô, bar, padaria e por aí'],
  'faturamento'    => ['number',  'quanto a casa vende por mês, em reais'],
  'compras'        => ['number',  'gasto com mercadoria no mês, em reais'],
  'temContagem'    => ['boolean', 'se a casa conta o estoque no fim do mês'],
  'estoqueInicial' => ['number',  'estoque no começo do mês, em reais'],
  'estoqueFinal'   => ['number',  'estoque no fim do mês, em reais'],
  'cmvDeclarado'   => ['number',  'o chute da pessoa para o CMV, de 0 a 1, antes de qualquer conta'],
  'precoPrato'     => ['number',  'preço de venda do prato que mais sai'],
  'custoPrato'     => ['number',  'quanto custa montar esse prato'],
  'contagemBate'   => ['boolean', 'se o estoque bate com o que foi vendido'],
  'pessoal'        => ['number',  'equipe no mês com encargos, em reais'],
  'fixas'          => ['number',  'aluguel, energia, internet, contador, em reais'],
  'variaveisPct'   => ['number',  'imposto, cartão e comissão, de 0 a 1 do faturamento'],
];

$ficha = [];
foreach ($campos as $nome => [$tipo, $diz]) {
  /* o null precisa ser um tipo válido, senão o modelo inventa zero para não
     deixar o campo vazio, e zero não é a mesma coisa que não saber */
  $ficha[$nome] = ['type' => [$tipo, 'null'], 'description' => $diz];
}

$ferramenta = [
  'name' => 'responder',
  'description' => 'Responde ao visitante e devolve a ficha de dados da casa.',
  'input_schema' => [
    'type' => 'object',
    'properties' => [
      'resposta' => ['type' => 'string', 'description' => 'O que dizer ao visitante agora. Uma pergunta por vez.'],
      'ficha'    => ['type' => 'object', 'properties' => $ficha, 'required' => array_keys($ficha)],
    ],
    'required' => ['resposta', 'ficha'],
  ],
];

/* =========================================================================
   a chamada
   ========================================================================= */
$corpo = json_encode([
  'model'       => MODELO,
  'max_tokens'  => MAX_TOKENS,
  'system'      => $sistema,
  'messages'    => $turnos,
  'tools'       => [$ferramenta],
  'tool_choice' => ['type' => 'tool', 'name' => 'responder'],
], JSON_UNESCAPED_UNICODE);

$cab = [
  'x-api-key: ' . $chave,
  'anthropic-version: 2023-06-01',
  'content-type: application/json',
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
  CURLOPT_POST           => true,
  CURLOPT_POSTFIELDS     => $corpo,
  CURLOPT_HTTPHEADER     => $cab,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT        => 45,
  CURLOPT_SSL_VERIFYPEER => true,
  CURLOPT_SSL_VERIFYHOST => 2,
]);
$resp = curl_exec($ch);
$erroC = curl_error($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
/* sem curl_close() de propósito: no PHP 8.5 ele imprime um aviso de
   "depreciado" ANTES do JSON, e com a resposta suja o navegador não consegue
   ler nada. O mesmo motivo do stripe.php. */

if ($resp === false) { desiste('Não consegui responder agora.', 'cURL: ' . $erroC); }

$j = json_decode((string) $resp, true);
if (!is_array($j)) { desiste('Não consegui responder agora.', 'resposta ilegível (' . $status . ')'); }

if ($status !== 200) {
  /* a mensagem do provedor vai para o log, nunca para a tela: ela cita modelo,
     conta e cota, e isso não é assunto do visitante */
  desiste('Não consegui responder agora.',
    'HTTP ' . $status . ': ' . mb_substr((string) ($j['error']['message'] ?? $resp), 0, 300));
}

/* ---- o que veio ---- */
$texto = '';
$fichaVolta = null;
foreach ((array) ($j['content'] ?? []) as $bloco) {
  if (($bloco['type'] ?? '') === 'tool_use' && ($bloco['name'] ?? '') === 'responder') {
    $texto      = trim((string) ($bloco['input']['resposta'] ?? ''));
    $fichaVolta = $bloco['input']['ficha'] ?? null;
  }
}
if ($texto === '') { desiste('Não consegui responder agora.', 'resposta sem a ferramenta responder'); }

/* ---- o que custou ---- */
$uso     = (array) ($j['usage'] ?? []);
$entrada = (int) ($uso['input_tokens'] ?? 0);
$saida   = (int) ($uso['output_tokens'] ?? 0);
/* Os preços vivem na configuração porque mudam, e um preço errado aqui não
   quebra nada: só faz o teto do dia valer mais ou menos do que se quis. */
$centavos = (int) ceil(
    $entrada / 1000000 * (float) ($cfg['preco_entrada_centavos_milhao'] ?? 0)
  + $saida   / 1000000 * (float) ($cfg['preco_saida_centavos_milhao']   ?? 0)
);
@file_put_contents($arqDia, (string) ($gasto + $centavos), LOCK_EX);

marca($arqS, $histS, $agora);
marca($arqI, $histI, $agora);

/* =========================================================================
   registro para a revisão semanal
   =========================================================================
   O prompt é instrução, não trava: ele vai ser desobedecido de vez em quando,
   e a única forma de descobrir é ler conversa de verdade. Uma linha por turno,
   JSON, para abrir em qualquer coisa.

   ATENÇÃO DE PRIVACIDADE: isto guarda o que o visitante digitou, inclusive
   faturamento e custo da casa dele. A Política de Privacidade que está no ar
   descreve o formulário de contato e o Analytics, e NÃO menciona o Raio-X.
   Antes de ligar isto em produção, a seção 2 da Política precisa dizer que o
   Raio-X existe, o que ele guarda e por quanto tempo. */
$pasta = (string) ($cfg['pasta_log'] ?? (__DIR__ . '/../dext-raio-x'));
if (!is_dir($pasta)) { @mkdir($pasta, 0700, true); }
if (is_dir($pasta) && is_writable($pasta)) {
  @file_put_contents($pasta . '/' . date('Y-m') . '.jsonl',
    json_encode([
      'quando'   => date('c'),
      'sessao'   => $sessao,
      'turno'    => count($histS) + 1,
      'pergunta' => end($turnos)['content'] ?? '',
      'resposta' => $texto,
      'ficha'    => $fichaVolta,
      'tokens'   => ['entrada' => $entrada, 'saida' => $saida],
      'centavos' => $centavos,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
    FILE_APPEND | LOCK_EX);
} else {
  error_log('[dext][raio-x] sem onde gravar a conversa: ' . $pasta);
}

responde(true, ['resposta' => $texto, 'ficha' => $fichaVolta]);
