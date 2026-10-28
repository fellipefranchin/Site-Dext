/* Testes do motor do Raio-X.  Rodar:  node raio-x/motor.test.js
 *
 * Cada fórmula tem teste com número conferido à mão. O que mais importa
 * aqui são os casos de borda: "não sei", casa sem contagem de estoque e
 * casa que não fecha a conta em nenhum volume.
 */
const M = require('./motor.js');

let ok = 0, falhou = 0;
const perto = (a, b, tol = 0.01) => Math.abs(a - b) < tol;

function t(nome, real, esperado, tol) {
  const passou = typeof esperado === 'number' ? perto(real, esperado, tol) : real === esperado;
  if (passou) { ok++; }
  else { falhou++; console.log(`  ✗ ${nome}\n      esperado ${esperado}, veio ${real}`); }
}

/* Casa de referência, usada em vários testes */
const casa = {
  faturamento: 120000,
  compras: 40000,
  estoqueInicial: 15000,
  estoqueFinal: 16600,
  cmvDeclarado: 0.25,
  precoPrato: 48,
  custoPrato: 15,
  variaveisPct: 0.125,      // imposto + cartão + comissão
  pessoal: 36000,
  fixas: 24000,
  contagemBate: 'as_vezes',
};

console.log('CMV');
{
  const r = M.cmv(casa);
  t('reais com contagem (15000+40000-16600)', r.reais.valor, 38400);
  t('percentual', r.percentual.valor, 38400 / 120000);
  t('marcado como exato', r.reais.exatidao, 'exato');
  t('temContagem', r.temContagem, true);

  const sem = M.cmv({ faturamento: 120000, compras: 40000 });
  t('sem contagem usa as compras', sem.reais.valor, 40000);
  t('sem contagem é aproximado', sem.reais.exatidao, 'aproximado');

  const nada = M.cmv({ faturamento: 120000 });
  t('sem compras vira lacuna', nada.valor, null);
  t('lacuna aponta a área', nada.area, 'Operacional');
}

console.log('valor do ponto de CMV');
{
  const r = M.valorDoPonto(120000);
  t('1% ao mês', r.mes.valor, 1200);
  t('12% ao ano', r.ano.valor, 14400);
}

console.log('margem do prato');
{
  const r = M.margemDoPrato({ preco: 48, custo: 15, variaveisPct: 0.125 });
  t('contribuição 48-15-6', r.reais.valor, 27);
  t('percentual sobre o preço', r.percentual.valor, 27 / 48);
  t('CMV do prato', r.cmvDoPrato.valor, 15 / 48);

  const semVar = M.margemDoPrato({ preco: 48, custo: 15 });
  t('sem variáveis dá preço-custo', semVar.reais.valor, 33);
  t('sem variáveis fica incompleto', semVar.reais.exatidao, 'incompleto');
}

console.log('resultado do mês');
{
  const r = M.resultadoDoMes({ faturamento: 120000, cmvReais: 38400, variaveisPct: 0.125, pessoal: 36000, fixas: 24000 });
  t('120000-38400-15000-36000-24000', r.reais.valor, 6600);
  t('percentual', r.percentual.valor, 6600 / 120000);

  const semVar = M.resultadoDoMes({ faturamento: 120000, cmvReais: 38400, pessoal: 36000, fixas: 24000 });
  t('ignorar variáveis infla o resultado', semVar.reais.valor, 21600);
  t('e marca como incompleto', semVar.reais.exatidao, 'incompleto');
}

console.log('ponto de equilíbrio');
{
  const r = M.pontoDeEquilibrio({ cmvPct: 0.32, variaveisPct: 0.125, pessoal: 36000, fixas: 24000 });
  t('(24000+36000)/(1-0.32-0.125)', r.reais.valor, 60000 / 0.555, 1);
  t('margem por real vendido', r.margemUnitaria.valor, 0.555);
  t('não é impossível', r.impossivel, false);

  const semVar = M.pontoDeEquilibrio({ cmvPct: 0.32, pessoal: 36000, fixas: 24000 });
  t('ignorar variáveis derruba o equilíbrio', semVar.reais.valor, 60000 / 0.68, 1);

  // casa cujo custo por real vendido não deixa sobra: nenhum volume resolve
  const travada = M.pontoDeEquilibrio({ cmvPct: 0.78, variaveisPct: 0.25, pessoal: 30000, fixas: 20000 });
  t('margem negativa é sinalizada', travada.impossivel, true);
  t('e não devolve um número de vendas', travada.reais, undefined);
}

console.log('cenário de perda');
{
  const r = M.cenarioPerda({ compras: 40000, percentual: 0.04 });
  t('4% das compras no mês', r.mes.valor, 1600);
  t('no ano', r.ano.valor, 19200);
  t('marcado como cenário', r.mes.exatidao, 'cenário');
}

console.log('diagnóstico completo');
{
  const d = M.diagnostico(casa);
  t('resultado bate com o teste isolado', d.resultado.reais.valor, 6600);
  t('equilíbrio calculado', d.equilibrio.reais.valor, 60000 / (1 - 0.32 - 0.125), 2000);
  t('diferença entre CMV achado e real', d.diferencaCMV.valor, 0.32 - 0.25);
  t('casa completa quase não tem lacuna', d.lacunas.length, 0);

  const vago = M.diagnostico({ faturamento: 120000, compras: 40000 });
  t('casa sem respostas acumula lacunas', vago.lacunas.length >= 4, true);
  const areas = vago.lacunas.map(l => l.area).join(' ');
  t('lacunas apontam áreas do sistema', /Operacional|Financeiro|Pessoas/.test(areas), true);
  t('sem pessoal não inventa resultado', vago.resultado.valor, null);
}

console.log(`\n${ok} passaram, ${falhou} falharam`);
process.exit(falhou ? 1 : 0);
