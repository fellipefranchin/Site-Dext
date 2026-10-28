/* ===== Raio-X Dext · motor de cálculo =====
 *
 * Todas as contas do Raio-X saem daqui. O agente de IA conversa e explica,
 * mas nunca calcula: ele recebe estes resultados prontos. Se um número
 * aparecer na tela sem ter saído deste arquivo, é bug.
 *
 * Duas regras que valem para tudo:
 *   1. "não sei" é uma resposta legítima. Entra como null e vira uma linha
 *      do bloco "o que você ainda não consegue ver", nunca um palpite.
 *   2. Todo resultado carrega a premissa que o gerou, para ir à tela junto
 *      com o número. Número sem premissa não se defende diante de quem
 *      vive de margem.
 *
 * Os custos variáveis de venda (imposto, taxa de cartão, comissão de
 * delivery) entram como linha própria, seguindo a mesma metodologia da
 * ficha técnica usada em gestão financeira em gastronomia, que separa
 * "custo var. venda (%PV)" do CMV para chegar à margem de contribuição.
 * Ignorá-los infla o resultado e derruba o ponto de equilíbrio.
 */

(function (raiz) {
  'use strict';

  const ehNumero = (v) => typeof v === 'number' && isFinite(v);
  const positivo = (v) => ehNumero(v) && v > 0;

  /** Resultado padrão: valor, premissa que o gerou e o grau de confiança. */
  function medida(valor, premissa, exatidao) {
    return { valor, premissa, exatidao: exatidao || 'exato' };
  }

  function naoSei(oQueFalta, areaDoSistema) {
    return { valor: null, falta: oQueFalta, area: areaDoSistema };
  }

  /* ---------- CMV ---------- */

  /**
   * Com contagem de estoque, o CMV é exato: o que entrou mais o que já havia,
   * menos o que sobrou. Sem contagem, as compras do mês são só uma
   * aproximação, porque confundem o que foi comprado com o que foi consumido.
   */
  function cmv({ faturamento, compras, estoqueInicial, estoqueFinal }) {
    if (!positivo(faturamento)) return naoSei('faturamento do mês', 'Financeiro');
    if (!ehNumero(compras)) return naoSei('compras de insumo do mês', 'Operacional');

    const temContagem = ehNumero(estoqueInicial) && ehNumero(estoqueFinal);
    const valor = temContagem ? estoqueInicial + compras - estoqueFinal : compras;
    const pct = valor / faturamento;

    return {
      reais: medida(valor,
        temContagem
          ? 'estoque inicial + compras − estoque final'
          : 'só as compras do mês, sem contagem de estoque',
        temContagem ? 'exato' : 'aproximado'),
      percentual: medida(pct, 'sobre o faturamento do mês', temContagem ? 'exato' : 'aproximado'),
      temContagem,
    };
  }

  /** Quanto vale um ponto percentual de CMV na casa dele. */
  function valorDoPonto(faturamento) {
    if (!positivo(faturamento)) return naoSei('faturamento do mês', 'Financeiro');
    return {
      mes: medida(faturamento * 0.01, '1% do faturamento do mês'),
      ano: medida(faturamento * 0.12, '1% do faturamento, em doze meses'),
    };
  }

  /* ---------- pessoal ---------- */

  function custoPessoal({ faturamento, pessoal }) {
    if (!positivo(faturamento)) return naoSei('faturamento do mês', 'Financeiro');
    if (!ehNumero(pessoal)) return naoSei('custo da equipe com encargos', 'Pessoas');
    return {
      reais: medida(pessoal, 'folha do mês, com encargos'),
      percentual: medida(pessoal / faturamento, 'sobre o faturamento do mês'),
    };
  }

  /* ---------- prato ---------- */

  /**
   * Margem de contribuição: o que sobra do prato depois do insumo e do que
   * sai sobre a venda. Preço menos custo, sozinho, não é margem: ignora
   * imposto, cartão e comissão, que incidem sobre o preço.
   */
  function margemDoPrato({ preco, custo, variaveisPct }) {
    if (!positivo(preco)) return naoSei('preço do prato que mais vende', 'Operacional');
    if (!ehNumero(custo)) return naoSei('custo do prato pela ficha técnica', 'Operacional');

    const temVariaveis = ehNumero(variaveisPct);
    const custoVenda = temVariaveis ? preco * variaveisPct : 0;
    const contribuicao = preco - custo - custoVenda;

    return {
      reais: medida(contribuicao,
        temVariaveis
          ? 'preço − custo do insumo − impostos e taxas sobre a venda'
          : 'preço − custo do insumo, antes de impostos e taxas',
        temVariaveis ? 'exato' : 'incompleto'),
      percentual: medida(contribuicao / preco, 'sobre o preço de venda',
        temVariaveis ? 'exato' : 'incompleto'),
      cmvDoPrato: medida(custo / preco, 'custo do insumo sobre o preço'),
      incluiVariaveis: temVariaveis,
    };
  }

  /* ---------- resultado e equilíbrio ---------- */

  function resultadoDoMes({ faturamento, cmvReais, variaveisPct, pessoal, fixas }) {
    if (!positivo(faturamento)) return naoSei('faturamento do mês', 'Financeiro');
    if (!ehNumero(cmvReais)) return naoSei('CMV do mês', 'Operacional');
    if (!ehNumero(pessoal)) return naoSei('custo da equipe', 'Pessoas');
    if (!ehNumero(fixas)) return naoSei('despesas fixas do mês', 'Financeiro');

    const temVariaveis = ehNumero(variaveisPct);
    const custoVenda = temVariaveis ? faturamento * variaveisPct : 0;
    const valor = faturamento - cmvReais - custoVenda - pessoal - fixas;

    return {
      reais: medida(valor,
        temVariaveis
          ? 'faturamento − CMV − impostos e taxas − pessoal − fixas'
          : 'faturamento − CMV − pessoal − fixas, antes de impostos e taxas',
        temVariaveis ? 'exato' : 'incompleto'),
      percentual: medida(valor / faturamento, 'sobre o faturamento do mês',
        temVariaveis ? 'exato' : 'incompleto'),
      incluiVariaveis: temVariaveis,
    };
  }

  /**
   * Quanto a casa precisa vender só para empatar. O denominador é o que
   * sobra de cada real vendido depois dos custos que acompanham a venda.
   * Se ele chega a zero, não existe volume que feche a conta: o problema
   * está no preço ou no custo, não no movimento.
   */
  function pontoDeEquilibrio({ cmvPct, variaveisPct, pessoal, fixas }) {
    if (!ehNumero(cmvPct)) return naoSei('CMV do mês', 'Operacional');
    if (!ehNumero(pessoal)) return naoSei('custo da equipe', 'Pessoas');
    if (!ehNumero(fixas)) return naoSei('despesas fixas do mês', 'Financeiro');

    const temVariaveis = ehNumero(variaveisPct);
    const margem = 1 - cmvPct - (temVariaveis ? variaveisPct : 0);

    if (margem <= 0) {
      return {
        impossivel: true,
        margemUnitaria: medida(margem, 'o que sobra de cada real vendido'),
        premissa: 'com esse custo por real vendido, nenhum volume de vendas fecha a conta',
      };
    }

    return {
      impossivel: false,
      reais: medida((fixas + pessoal) / margem,
        temVariaveis
          ? '(fixas + pessoal) ÷ (1 − CMV% − impostos e taxas%)'
          : '(fixas + pessoal) ÷ (1 − CMV%), antes de impostos e taxas',
        temVariaveis ? 'exato' : 'incompleto'),
      margemUnitaria: medida(margem, 'o que sobra de cada real vendido'),
      incluiVariaveis: temVariaveis,
    };
  }

  /* ---------- cenário de perda ---------- */

  /**
   * Cenário, não diagnóstico: o dono escolhe a diferença entre o que deveria
   * ter saído pelas vendas e o que a contagem encontrou, e vê quanto isso
   * vale em dinheiro sobre as compras dele.
   */
  function cenarioPerda({ compras, percentual }) {
    if (!ehNumero(compras)) return naoSei('compras de insumo do mês', 'Operacional');
    if (!ehNumero(percentual)) return naoSei('percentual do cenário', 'Operacional');
    return {
      mes: medida(compras * percentual, 'sobre as compras do mês', 'cenário'),
      ano: medida(compras * percentual * 12, 'sobre as compras, em doze meses', 'cenário'),
    };
  }

  /* ---------- diagnóstico completo ---------- */

  /** Junta tudo e separa o que foi calculado do que ficou sem resposta. */
  function diagnostico(d) {
    const oCMV = cmv(d);
    const temCMV = oCMV && oCMV.reais;

    const out = {
      cmv: oCMV,
      valorDoPonto: valorDoPonto(d.faturamento),
      pessoal: custoPessoal(d),
      prato: margemDoPrato({ preco: d.precoPrato, custo: d.custoPrato, variaveisPct: d.variaveisPct }),
      resultado: resultadoDoMes({
        faturamento: d.faturamento,
        cmvReais: temCMV ? oCMV.reais.valor : null,
        variaveisPct: d.variaveisPct,
        pessoal: d.pessoal,
        fixas: d.fixas,
      }),
      equilibrio: pontoDeEquilibrio({
        cmvPct: temCMV ? oCMV.percentual.valor : null,
        variaveisPct: d.variaveisPct,
        pessoal: d.pessoal,
        fixas: d.fixas,
      }),
      lacunas: [],
    };

    /* o que ficou sem resposta, já ligado à área do sistema que responde */
    const lacuna = (cond, falta, area) => { if (cond) out.lacunas.push({ falta, area }); };
    lacuna(!oCMV.temContagem, 'CMV real, com contagem de estoque', 'Operacional e Financeiro');
    lacuna(!ehNumero(d.variaveisPct), 'quanto sai em imposto, cartão e comissão', 'Financeiro');
    lacuna(!ehNumero(d.custoPrato), 'custo do prato pela ficha técnica', 'Operacional');
    lacuna(!ehNumero(d.pessoal), 'custo da equipe por turno', 'Pessoas');
    lacuna(!ehNumero(d.fixas), 'despesas fixas do mês', 'Financeiro');
    lacuna(d.contagemBate === 'nunca' || d.contagemBate === 'nao_faco',
      'se a contagem bate com o que deveria ter saído', 'Operacional');

    /* o CMV que ele acha contra o que a conta mostra */
    if (ehNumero(d.cmvDeclarado) && temCMV) {
      out.diferencaCMV = medida(oCMV.percentual.valor - d.cmvDeclarado,
        'CMV calculado menos o CMV que o dono estimou');
    }
    return out;
  }

  const Motor = {
    cmv, valorDoPonto, custoPessoal, margemDoPrato,
    resultadoDoMes, pontoDeEquilibrio, cenarioPerda, diagnostico,
  };

  if (typeof module === 'object' && module.exports) module.exports = Motor;
  else raiz.MotorRaioX = Motor;
})(typeof globalThis !== 'undefined' ? globalThis : this);
