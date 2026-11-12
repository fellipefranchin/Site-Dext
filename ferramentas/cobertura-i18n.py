#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Cobertura PT -> EN do index.html.

O português vive no HTML e é a fonte da verdade; o inglês vem do DIC_EN, que é
chaveado pelo PRÓPRIO texto em português. Quer dizer: mudar uma frase em
português muda a chave, a tradução para de casar e o site cai de volta para o
português sem avisar ninguém a não ser pelo console.

Este script é o aviso. Ele lê os nós de texto e os atributos traduzíveis do
index.html com as mesmas regras do aplicarIdioma() do site, e compara com o
dicionário:

  SEM TRADUÇÃO · frase no HTML que o dicionário não conhece (vai aparecer em
                 português para quem está em inglês)
  ÓRFÃ         · chave no dicionário que não existe mais no HTML (sobra inútil,
                 ou sinal de que a frase mudou e a tradução ficou para trás)

Nem toda linha é um erro: títulos de aba e mensagens de status são escritos
pelo JS e não estão no HTML, então aparecem como órfãs de propósito. Valores de
meta og: (urls, "website") aparecem como sem tradução pelo mesmo motivo ao
contrário. O que importa é o que muda de uma edição para a outra.

Uso:  python3 ferramentas/cobertura-i18n.py
"""
import json
import os
import re
import sys
from html.parser import HTMLParser

AQUI = os.path.dirname(os.path.abspath(__file__))
HTML = os.path.join(os.path.dirname(AQUI), 'index.html')
ATRIB = ('placeholder', 'aria-label', 'alt', 'title')


def carrega_dicionario(fonte):
    """Devolve o DIC_EN como dict. Conta chaves em vez de confiar em regex:
    o dicionário tem aspas e chaves dentro dos valores."""
    i = fonte.index('const DIC_EN=')
    ini = fonte.index('{', i)
    prof, dentro, esc = 0, False, False
    for j in range(ini, len(fonte)):
        c = fonte[j]
        if dentro:
            if esc:
                esc = False
            elif c == '\\':
                esc = True
            elif c == '"':
                dentro = False
            continue
        if c == '"':
            dentro = True
        elif c == '{':
            prof += 1
        elif c == '}':
            prof -= 1
            if prof == 0:
                return json.loads(fonte[ini:j + 1])
    raise SystemExit('não achei o fim do DIC_EN')


class Leitor(HTMLParser):
    """As mesmas regras do nosDeTexto() do site: só dentro do <body>, fora de
    <script> e <style>, e fora do seletor de idioma e do aviso de cookies."""

    PULA = ('lang',)

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.pilha = []
        self.fora = 0
        self.mudo = 0
        self.corpo = False
        self.textos = []
        self.atributos = []

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'body':
            self.corpo = True
        if tag in ('script', 'style'):
            self.mudo += 1
        classes = (a.get('class') or '').split()
        ignora = any(c in self.PULA for c in classes) or a.get('id') == 'dext-cookies'
        if ignora:
            self.fora += 1
        self.pilha.append((tag, ignora, tag in ('script', 'style')))
        if self.corpo:
            for k in ATRIB:
                if a.get(k) and a[k].strip():
                    self.atributos.append(a[k].strip())
        if tag == 'meta':
            nome, prop = a.get('name', ''), a.get('property', '')
            if (nome == 'description' or prop.startswith('og:') or nome.startswith('twitter:')) and a.get('content'):
                self.atributos.append(a['content'].strip())

    def handle_endtag(self, tag):
        for i in range(len(self.pilha) - 1, -1, -1):
            t, ignorava, mudava = self.pilha[i]
            if t == tag:
                if ignorava:
                    self.fora -= 1
                if mudava:
                    self.mudo -= 1
                del self.pilha[i:]
                return

    def handle_data(self, d):
        if self.corpo and not self.mudo and not self.fora and d.strip():
            self.textos.append(d.strip())


def main():
    fonte = open(HTML, encoding='utf-8').read()
    dic = carrega_dicionario(fonte)

    p = Leitor()
    p.feed(fonte)

    vistos, ordem = set(), []
    for t in p.textos + p.atributos:
        if t not in vistos:
            vistos.add(t)
            ordem.append(t)

    tem_letra = lambda t: re.search(r'[A-Za-zÀ-ÿ]{2}', t) is not None
    faltando = [t for t in ordem if tem_letra(t) and t not in dic]
    orfas = [k for k in dic if k not in vistos]

    print('nós de texto e atributos únicos: %d' % len(ordem))
    print('entradas no dicionário: %d' % len(dic))
    print('\n--- SEM TRADUÇÃO (%d) ---' % len(faltando))
    for t in faltando:
        print('   %s' % t[:150])
    print('\n--- ÓRFÃS no dicionário (%d) ---' % len(orfas))
    for k in orfas:
        print('   %s' % k[:150])

    return 0


if __name__ == '__main__':
    sys.exit(main())
