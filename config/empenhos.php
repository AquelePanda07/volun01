<?php
/**
 * config/empenhos.php
 *
 * Parâmetros fixos do "Controle de Empenhos" (services/EmpenhoService.php).
 * Edite aqui se a bolsa mudar.
 *
 * O valor do dia de cada jornada é a referência oficial; equivale a
 * R$ 8,75 por hora (40h = 8h/dia, 30h = 6h/dia, 20h = 4h/dia).
 */

declare(strict_types=1);

return [
    // Carga horária semanal do contrato => valor de 1 dia trabalhado (R$).
    'valor_dia' => [
        '40' => '70.00',
        '30' => '52.50',
        '20' => '35.00',
    ],

    // Horas por dia de cada jornada (só informativo: aparece no extrato e no README).
    'horas_dia' => [
        '40' => 8,
        '30' => 6,
        '20' => 4,
    ],
    'valor_hora' => '8.75',

    // Empenho 1 (original) cobre o semestre: meses x dias de referência x valor do dia.
    // Ex.: 40h -> 6 x 20 x 70,00 = R$ 8.400,00.
    'meses_empenho_original' => 6,
    'dias_mes_referencia' => 20,

    // Os dias trabalhados aceitam meio dia (ex.: 21,5 dias).
    'passo_dias' => 0.5,

    // Ano mínimo aceito nos lançamentos.
    'ano_minimo' => 2020,
];
