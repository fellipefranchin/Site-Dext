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
    if (!positivo(faturamento)) return naoSei('quanto a casa vende por mês', 'Financeiro');
    if (!ehNumero(compras)) return naoSei('quanto você gastou comprando mercadoria no mês', 'Operacional');

    const temContagem = ehNumero(estoqueInicial) && ehNumero(estoqueFinal);
    const valor = temContagem ? estoqueInicial + compras - estoqueFinal : compras;
    const pct = valor / faturamento;

    return {
      reais: medida(valor,
        temContagem
          ? 'o que você já tinha guardado, mais o que comprou, menos o que sobrou'
          : 'contado só pelas compras do mês, porque não houve contagem de estoque',
        temContagem ? 'exato' : 'aproximado'),
      percentual: medida(pct, 'em cima de tudo que a casa vendeu no mês', temContagem ? 'exato' : 'aproximado'),
      temContagem,
    };
  }

  /** Quanto vale um ponto percentual de CMV na casa dele. */
  function valorDoPonto(faturamento) {
    if (!positivo(faturamento)) return naoSei('quanto a casa vende por mês', 'Financeiro');
    return {
      mes: medida(faturamento * 0.01, 'é quanto vale 1% do que a casa vende por mês'),
      ano: medida(faturamento * 0.12, 'o mesmo 1%, somado ao longo de doze meses'),
    };
  }

  /* ---------- pessoal ---------- */

  function custoPessoal({ faturamento, pessoal }) {
    if (!positivo(faturamento)) return naoSei('quanto a casa vende por mês', 'Financeiro');
    if (!ehNumero(pessoal)) return naoSei('quanto a equipe custa, com encargos', 'Pessoas');
    return {
      reais: medida(pessoal, 'o que a equipe custou no mês, com encargos'),
      percentual: medida(pessoal / faturamento, 'em cima de tudo que a casa vendeu no mês'),
    };
  }

  /* ---------- prato ---------- */

  /**
   * Margem de contribuição: o que sobra do prato depois do insumo e do que
   * sai sobre a venda. Preço menos custo, sozinho, não é margem: ignora
   * imposto, cartão e comissão, que incidem sobre o preço.
   */
  function margemDoPrato({ preco, custo, variaveisPct }) {
    if (!positivo(preco)) return naoSei('por quanto você vende o prato que mais sai', 'Operacional');
    if (!ehNumero(custo)) return naoSei('quanto custa para montar esse prato', 'Operacional');

    const temVariaveis = ehNumero(variaveisPct);
    const custoVenda = temVariaveis ? preco * variaveisPct : 0;
    const contribuicao = preco - custo - custoVenda;

    return {
      reais: medida(contribuicao,
        temVariaveis
          ? 'o preço, menos o que vai dentro do prato, menos imposto e taxas da venda'
          : 'o preço menos o que vai dentro do prato, ainda sem imposto e taxas',
        temVariaveis ? 'exato' : 'incompleto'),
      percentual: medida(contribuicao / preco, 'em cima do preço que o cliente paga',
        temVariaveis ? 'exato' : 'incompleto'),
      cmvDoPrato: medida(custo / preco, 'o que vai dentro do prato, em cima do preço'),
      incluiVariaveis: temVariaveis,
    };
  }

  /* ---------- resultado e equilíbrio ---------- */

  function resultadoDoMes({ faturamento, cmvReais, variaveisPct, pessoal, fixas }) {
    if (!positivo(faturamento)) return naoSei('quanto a casa vende por mês', 'Financeiro');
    if (!ehNumero(cmvReais)) return naoSei('quanto a comida come do que você vende', 'Operacional');
    if (!ehNumero(pessoal)) return naoSei('quanto a equipe custa por mês', 'Pessoas');
    if (!ehNumero(fixas)) return naoSei('quanto pesam as contas fixas do mês', 'Financeiro');

    const temVariaveis = ehNumero(variaveisPct);
    const custoVenda = temVariaveis ? faturamento * variaveisPct : 0;
    const valor = faturamento - cmvReais - custoVenda - pessoal - fixas;

    return {
      reais: medida(valor,
        temVariaveis
          ? 'o que entrou, menos a comida, o imposto e as taxas, a equipe e as contas fixas'
          : 'o que entrou, menos a comida, a equipe e as contas fixas, ainda sem imposto e taxas',
        temVariaveis ? 'exato' : 'incompleto'),
      percentual: medida(valor / faturamento, 'em cima de tudo que a casa vendeu no mês',
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
    if (!ehNumero(cmvPct)) return naoSei('quanto a comida come do que você vende', 'Operacional');
    if (!ehNumero(pessoal)) return naoSei('quanto a equipe custa por mês', 'Pessoas');
    if (!ehNumero(fixas)) return naoSei('quanto pesam as contas fixas do mês', 'Financeiro');

    const temVariaveis = ehNumero(variaveisPct);
    const margem = 1 - cmvPct - (temVariaveis ? variaveisPct : 0);

    if (margem <= 0) {
      return {
        impossivel: true,
        margemUnitaria: medida(margem, 'o que sobra de cada real que o cliente paga'),
        premissa: 'com esse custo em cada real vendido, nenhum movimento fecha a conta: o problema está no preço ou no custo, não na quantidade de clientes',
      };
    }

    return {
      impossivel: false,
      reais: medida((fixas + pessoal) / margem,
        temVariaveis
          ? 'as contas fixas e a equipe, divididas pelo que sobra de cada real vendido'
          : 'as contas fixas e a equipe, divididas pelo que sobra de cada real vendido, ainda sem imposto e taxas',
        temVariaveis ? 'exato' : 'incompleto'),
      margemUnitaria: medida(margem, 'o que sobra de cada real que o cliente paga'),
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
    if (!ehNumero(compras)) return naoSei('quanto você gastou comprando mercadoria no mês', 'Operacional');
    if (!ehNumero(percentual)) return naoSei('a porcentagem do cenário', 'Operacional');
    return {
      mes: medida(compras * percentual, 'em cima do que você comprou no mês', 'cenário'),
      ano: medida(compras * percentual * 12, 'o mesmo, somado ao longo de doze meses', 'cenário'),
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
    lacuna(!oCMV.temContagem, 'o quanto a comida come de verdade, que só a contagem de estoque mostra', 'Operacional e Financeiro');
    lacuna(!ehNumero(d.variaveisPct), 'quanto sai de imposto, taxa de cartão e comissão de delivery', 'Financeiro');
    lacuna(!ehNumero(d.custoPrato), 'quanto custa para montar cada prato', 'Operacional');
    lacuna(!ehNumero(d.pessoal), 'quanto a equipe custa em cada turno', 'Pessoas');
    lacuna(!ehNumero(d.fixas), 'quanto pesam as contas fixas do mês', 'Financeiro');
    lacuna(d.contagemBate === 'nunca' || d.contagemBate === 'nao_faco',
      'se o que sobra na geladeira bate com o que foi vendido', 'Operacional');

    /* o CMV que ele acha contra o que a conta mostra */
    if (ehNumero(d.cmvDeclarado) && temCMV) {
      out.diferencaCMV = medida(oCMV.percentual.valor - d.cmvDeclarado,
        'a diferença entre o que a conta mostra e o que você chutou');
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
