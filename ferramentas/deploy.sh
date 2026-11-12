#!/usr/bin/env bash
#
# ===== Dext · deploy do site para a Hostinger =====
#
#   ./ferramentas/deploy.sh            ensaio: mostra o que mudaria, não sobe nada
#   ./ferramentas/deploy.sh --subir    sobe de verdade
#
# Por que não é um rsync de pasta inteira:
#
# O public_html de dextdigital.com.br não é só o site. Dentro dele moram o
# Dext Sistema de Gestão (dext/, painel/, demo/), as credenciais de produção
# (*-config.php) e o .private/. Um --delete ali não derruba o site: derruba a
# operação de restaurante que está com o salão cheio.
#
# Então o deploy sobe uma lista escrita à mão, em arquivos-do-site.txt, e
# NUNCA apaga. O que não está na lista não é tocado. Arquivo que sai do site
# tem de ser removido à mão, de propósito, olhando para ele.

set -euo pipefail

HOST=212.85.6.148
PORTA=65002
USUARIO=u331806937
CHAVE=~/.ssh/dext_hostinger
DESTINO=/home/u331806937/domains/dextdigital.com.br/public_html

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LISTA="$RAIZ/ferramentas/arquivos-do-site.txt"

SUBIR=0
[[ "${1:-}" == "--subir" ]] && SUBIR=1

# ---- a lista, sem comentários nem linhas em branco ----
TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT
grep -vE '^\s*(#|$)' "$LISTA" > "$TMP"

# ---- trava 1: nenhuma credencial, aconteça o que acontecer ----
if grep -qiE '(^|/)[^/]*config[^/]*\.php$' "$TMP"; then
  echo "ABORTADO: a lista tem arquivo de configuração. Credencial não sobe." >&2
  exit 1
fi

# ---- trava 2: nada que pertença ao sistema ou ao servidor ----
if grep -qE '^(dext|painel|demo|backups)/|^\.private/' "$TMP"; then
  echo "ABORTADO: a lista alcança pasta do Dext Sistema de Gestão." >&2
  exit 1
fi

# ---- trava 3: todo arquivo da lista existe aqui ----
FALTA=0
while IFS= read -r a; do
  [[ -f "$RAIZ/$a" ]] || { echo "não existe no repositório: $a" >&2; FALTA=1; }
done < "$TMP"
[[ $FALTA -eq 0 ]] || { echo "ABORTADO: lista desatualizada." >&2; exit 1; }

SSH_CMD="ssh -i $CHAVE -p $PORTA -o BatchMode=yes -o ConnectTimeout=20"

# ---- trava 4: o destino é mesmo o site, e não uma pasta vazia por engano ----
if ! $SSH_CMD "$USUARIO@$HOST" "test -f $DESTINO/index.html && test -f $DESTINO/.htaccess"; then
  echo "ABORTADO: $DESTINO não parece o site (sem index.html ou .htaccess)." >&2
  exit 1
fi

echo "destino: $USUARIO@$HOST:$DESTINO"
echo "arquivos na lista: $(wc -l < "$TMP" | tr -d ' ')"
if [[ $SUBIR -eq 1 ]]; then
  echo "modo: SUBINDO DE VERDADE"
else
  echo "modo: ensaio (nada é escrito). Para subir: ./ferramentas/deploy.sh --subir"
fi
echo

# --checksum compara conteúdo, não data: o relógio da máquina e o do servidor
# não são o mesmo, e a lista é pequena o bastante para o custo não importar.
#
# Sem --chmod e sem -p de propósito. O macOS 15 troca o rsync pelo openrsync,
# que não tem --chmod, e mandar a permissão local junto só serviria para
# levar o umask daqui para lá. Arquivo que já existe mantém a permissão que
# tem; arquivo novo nasce com a do servidor, que é 644.
#
# Sem -t também: com --checksum, quem decide é o conteúdo, e manter a data
# faria todo arquivo idêntico aparecer no ensaio só porque o relógio difere.
# Ensaio que lista 31 linhas quando 3 mudaram não é lido por ninguém.
rsync -rlzv \
  --checksum \
  --itemize-changes \
  --files-from="$TMP" \
  $( [[ $SUBIR -eq 1 ]] || echo --dry-run ) \
  -e "$SSH_CMD" \
  "$RAIZ/" "$USUARIO@$HOST:$DESTINO/"

echo
if [[ $SUBIR -eq 1 ]]; then
  echo "feito. Confira no ar: https://dextdigital.com.br"
else
  echo "foi só ensaio. Nada mudou no servidor."
fi
