<?php
/* ===== Modelo do raio-x-config.php =====
   Copie este arquivo como raio-x-config.php NO SERVIDOR e preencha lá. O
   arquivo real nunca entra no repositório (.gitignore) e nunca é servido pela
   web (.htaccess nega qualquer *config*.php). Aqui não vai segredo nenhum.

   A chave você digita direto no servidor. Não passe por e-mail, nem por
   mensagem, nem por captura de tela.

   ATENÇÃO: chave SEPARADA da que roda no Dext Sistema de Gestão, com teto
   próprio no painel do provedor. Aqui o chamador é visitante anônimo numa
   página pública: se um dia alguém descobrir o endereço e chamar em massa, o
   estrago fica contido no Raio-X e não derruba o sistema dos clientes. */

return [
  /* console.anthropic.com > API keys */
  'api_key' => 'sk-ant-...',

  /* Freio de mão do dia, em centavos de real. Zero desliga o freio, o que
     não é recomendado numa página aberta à internet. R$ 50 por dia dá
     algumas centenas de conversas e segura um abuso antes de doer. */
  'teto_dia_centavos' => 5000,

  /* Preço por milhão de tokens, em centavos de real, para o teto acima saber
     contar. Confira no provedor e converta pelo câmbio que você usa: errar
     aqui não quebra nada, só faz o teto valer mais ou menos do que se quis.
     Deixe com folga para cima, que é o lado seguro. */
  'preco_entrada_centavos_milhao' => 1800,
  'preco_saida_centavos_milhao'   => 9000,

  /* Onde guardar as conversas para a revisão semanal. O padrão fica FORA do
     public_html de propósito: lá dentro, qualquer um que adivinhasse o nome
     do arquivo leria o faturamento dos visitantes. Se a hospedagem não
     deixar escrever aí, aponte para outro lugar fora da web e nunca para uma
     pasta servida. */
  'pasta_log' => __DIR__ . '/../dext-raio-x',
];
