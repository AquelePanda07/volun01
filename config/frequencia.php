<?php
/**
 * config/frequencia.php
 *
 * Dados fixos do "Quadro de Frequência" (Relatório e Recibo Mensal de
 * Atividades Desenvolvidas por Voluntário), usados por
 * src/QuadroFrequenciaService.php. Edite aqui se algum dado mudar.
 *
 * O responsável que certifica cada relatório NÃO fica aqui: é definido por
 * lotação na própria tela "Quadro de Frequência" (gravado no cadastro do
 * Local). Lotação sem responsável -> assina o(a) Secretário(a) do contrato.
 */

declare(strict_types=1);

return [
    'cabecalho' => [
        // Caminho de uma imagem dentro do projeto (ex.: 'img/logo_fnde.png'). Vazio = sem logo.
        'logo' => '',
        'orgao' => 'FUNDO NACIONAL DE DESENVOLVIMENTO DA EDUCAÇÃO',
        'programa' => 'PROGRAMA VOLUNTARIADO',
        'programa_nome' => 'Programa Voluntariado',
    ],
    'unidade' => [
        'razao_social' => 'PREFEITURA MUNICIPAL DE ITAPUÃ DO OESTE',
        'cnpj' => '63.761.936/0001-55',
        'municipio' => 'Itapuã do Oeste',
        'uf' => 'RO',
    ],
    'legislacao' => [
        'lei' => 'Lei Nº 715, de 26 de setembro de 2019',
        'decreto' => 'Decreto nº 2.291, de 27 de maio de 2021',
    ],
];
