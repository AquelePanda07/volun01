<?php
/**
 * scripts/gerar_modelo_docx.php
 *
 * Gera o arquivo templates/anexo_v_modelo.docx: uma transcrição fiel do
 * modelo oficial "ANEXO V - TERMO DE ADESÃO AO SERVIÇO VOLUNTÁRIO" da
 * Prefeitura do Município de Itapuã do Oeste/RO, fornecido pelo usuário.
 *
 * Os únicos trechos substituíveis são os campos ${...}; todo o restante
 * (cabeçalho, cláusulas 1 a 9, fundamentação legal e assinaturas) é
 * reproduzido literalmente, sem reescrita/resumo do conteúdo jurídico.
 *
 * Execute apenas quando for necessário recriar o modelo do zero:
 *   php scripts/gerar_modelo_docx.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

$phpWord = new PhpWord();
$phpWord->setDefaultFontName('Arial');
$phpWord->setDefaultFontSize(11);

$estiloParagrafoJustificado = ['alignment' => Jc::BOTH, 'spaceAfter' => 160];
$estiloClausulaTitulo = ['bold' => true];

$section = $phpWord->addSection([
    'marginTop' => 1000,
    'marginBottom' => 1000,
    'marginLeft' => 1200,
    'marginRight' => 1200,
]);

/* ---------------------------- Cabeçalho ---------------------------- */
$section->addText('PREFEITURA DO MUNICÍPIO DE ITAPUÃ DO OESTE/RO', ['bold' => true, 'size' => 13], ['alignment' => Jc::CENTER]);
$section->addText('GABINETE MUNICIPAL', ['size' => 11], ['alignment' => Jc::CENTER]);
$section->addTextBreak(1);
$section->addText('ANEXO V', ['bold' => true, 'size' => 13], ['alignment' => Jc::CENTER]);
$section->addTextBreak(1);

$tabelaTitulo = $section->addTable(['borderSize' => 4, 'borderColor' => '000000', 'alignment' => Jc::CENTER, 'width' => 100 * 50, 'unit' => 'pct']);
$tabelaTitulo->addRow();
$celulaTitulo = $tabelaTitulo->addCell(9000, ['shading' => ['fill' => 'D9D9D9']]);
$celulaTitulo->addText(
    'TERMO DE ADESÃO AO SERVIÇO VOLUNTÁRIO Nº ${NUMERO_TERMO}/${ANO_TERMO}.',
    ['bold' => true],
    ['alignment' => Jc::CENTER]
);
$section->addTextBreak(1);

/* ------------------------ Identificação das partes ------------------------ */
$section->addText(
    'Pelo presente instrumento, de um lado a PREFEITURA DO MUNICÍPIO ITAPUÃ DO OESTE, inscrito no ' .
    'CNPJ nº 63.761.936/0001-55, por intermédio da ${SECRETARIA}, representada pelo (a) Sr (a) ' .
    '${NOME_SECRETARIO}, com sede Rua Ayrton Sena, Nº 1425, neste ato ${QUALIFICACAO_SECRETARIO}, e do ' .
    'outro lado, o Sr(a) ${NOME_VOLUNTARIO}, CPF: ${CPF}, RG: ${RG}, expedido pelo órgão ${ORGAO_EXPEDIDOR}, ' .
    'em ${DATA_EXPEDICAO}, atualmente com ${IDADE} anos de idade, estado civil ${ESTADO_CIVIL}, do sexo ' .
    '${SEXO}, grau de escolaridade ${ESCOLARIDADE} residente e domiciliado ${ENDERECO} neste ato denominado ' .
    'VOLUNTÁRIO, resolvem, com fundamento na Lei Municipal nº 715, de 26 de setembro de 2019, respectivo ' .
    'Regulamento e na Lei Federal nº 9.608, de 1998, celebrar o presente TERMO DE ADESÃO AO SERVIÇO ' .
    'VOLUNTÁRIO, mediante as seguintes cláusulas:',
    null,
    $estiloParagrafoJustificado
);

/* ------------------------------ Cláusula 1ª ------------------------------ */
$section->addText('CLÁUSULA PRIMEIRA', $estiloClausulaTitulo);
$section->addText(
    'O VOLUNTÁRIO prestará as atividades discriminadas no respectivo Programa de Trabalho Voluntário, ' .
    'conforme anexo que integra este Termo, observadas as normas institucionais pertinentes no ' .
    '${LOCAL_PRESTACAO} (órgão/local de prestação do serviço), no período de ${DATA_INICIO} a ' .
    '${DATA_TERMINO} (máximo de 6 meses), no horário das ${HORARIO_INICIO} às ${HORARIO_TERMINO}, à(o)(s) ' .
    '${DIAS_SEMANA} (livre ajustes entre as partes).',
    null,
    $estiloParagrafoJustificado
);

/* ------------------------------ Cláusula 2ª ------------------------------ */
$section->addText('CLÁUSULA SEGUNDA', $estiloClausulaTitulo);
$section->addText(
    'O serviço voluntário não gera vínculo empregatício, funcional ou quaisquer obrigações trabalhistas, ' .
    'previdenciárias e será realizado de forma espontânea, não remunerada.',
    null,
    $estiloParagrafoJustificado
);

/* ------------------------------ Cláusula 3ª ------------------------------ */
$section->addText('CLÁUSULA TERCEIRA', $estiloClausulaTitulo);
$section->addText(
    'O exercício do trabalho voluntário não substituirá aqueles próprios de qualquer categoria funcional, ' .
    'servidor ou empregado público, havendo de ser respeitado o caráter complementar do serviço.',
    null,
    $estiloParagrafoJustificado
);

/* ------------------------------ Cláusula 4ª ------------------------------ */
$section->addText('CLÁUSULA QUARTA', $estiloClausulaTitulo);
$section->addText(
    'O VOLUNTÁRIO não poderá interferir em condutas definidas pelas equipes técnicas responsáveis pela ' .
    'prestação do serviço público no órgão em que exerce suas atividades.',
    null,
    $estiloParagrafoJustificado
);

/* ------------------------------ Cláusula 5ª ------------------------------ */
$section->addText('CLÁUSULA QUINTA', $estiloClausulaTitulo);
$section->addText('São direitos do VOLUNTÁRIO:', null, $estiloParagrafoJustificado);
$direitos = [
    'Escolher uma atividade, inserida no Programa de Trabalho Voluntário, para a qual tenha afinidade;',
    'Receber capacitação e/ou orientações para exercer adequadamente suas funções;',
    'Encaminhar sugestões e/ou reclamações ao responsável pelo corpo de voluntários do órgão, visando o ' .
        'aperfeiçoamento da prestação dos serviços;',
    'Ter acesso às informações institucionais para o bom desempenho de suas atividades;',
    'Ser apresentado ao corpo funcional e ao público beneficiário dos serviços prestados;',
    'Ter a divulgação periódica dos resultados alcançados no exercício de suas atividades;',
    'Receber um crachá de identificação para acesso ao trabalho e para sua apresentação à equipe da ' .
        'instituição e ao público beneficiário, sendo vedada a transferência a terceiros.',
    'Ao término da prestação dos serviços voluntários, receber certificado de participação no serviço ' .
        'voluntário.',
];
foreach ($direitos as $item) {
    $section->addText($item, null, $estiloParagrafoJustificado);
}

/* ------------------------------ Cláusula 6ª ------------------------------ */
$section->addText('CLÁUSULA SEXTA', $estiloClausulaTitulo);
$section->addText('São deveres do VOLUNTÁRIO, dentre outros:', null, $estiloParagrafoJustificado);
$deveres = [
    'Ser assíduo no desempenho de suas atividades;',
    'Manter comportamento ético, colaborativo e cordial no desempenho de suas atividades junto aos ' .
        'dirigentes e servidores públicos do órgão ou entidade em que exerce suas atividades, aos demais ' .
        'prestadores de serviços voluntários e ao público em geral;',
    'Identificar-se, mediante o uso do crachá que lhe for entregue, nas dependências do órgão no qual ' .
        'exerce suas atividades, ou fora delas, quando ao seu serviço;',
    'Exercer suas atribuições conforme previsto no termo de adesão e no programa de trabalho voluntário, ' .
        'sempre sob a orientação e coordenação do responsável designado pela direção do órgão ao qual se ' .
        'encontra vinculado;',
    'Comunicar previamente ao gestor do corpo de voluntários a impossibilidade de comparecimento nos dias ' .
        'em que estiver escalado para a prestação de serviço voluntário;',
    'Reparar eventuais danos que por sua culpa ou dolo vier a causar à administração pública estadual ou a ' .
        'terceiros, na execução dos serviços voluntários;',
    'Respeitar e cumprir as normas legais e regulamentares, bem como observar as normas impostas pelo ' .
        'órgão no qual se encontrar prestando serviços voluntários.',
];
foreach ($deveres as $item) {
    $section->addText($item, null, $estiloParagrafoJustificado);
}

/* ------------------------------ Cláusula 7ª ------------------------------ */
$section->addText('CLÁUSULA SÉTIMA', $estiloClausulaTitulo);
$section->addText('É vedado ao prestador de serviços voluntários:', null, $estiloParagrafoJustificado);
$vedacoes = [
    'Exercer de forma substitutiva funções privativas de servidor público, nos casos de licença, ' .
        'afastamentos legais e vacâncias;',
    'Identificar-se invocando sua condição de voluntário quando não estiver no pleno exercício das ' .
        'atividades voluntárias no órgão estadual a que se vincule;',
    'Receber, a qualquer título, remuneração pelos serviços prestados voluntariamente.',
];
foreach ($vedacoes as $item) {
    $section->addText($item, null, $estiloParagrafoJustificado);
}

/* ------------------------------ Cláusula 8ª ------------------------------ */
$section->addText('CLÁUSULA OITAVA', $estiloClausulaTitulo);
$section->addText(
    'Findo o período indicado na Cláusula Primeira, a prestação dos serviços voluntários poderá ser ' .
    'renovada a critério da Administração.',
    null,
    $estiloParagrafoJustificado
);
$section->addText(
    'Durante o período de sua vigência, o Termo de Adesão pode ser cancelado a qualquer tempo, por ' .
    'iniciativa de qualquer das partes, bastando para isso que uma delas notifique a outra e formalize o ' .
    'Termo de Desligamento.',
    null,
    $estiloParagrafoJustificado
);
$section->addText(
    'Será desligado formalmente do exercício de suas funções, o prestador de serviços voluntários que ' .
    'descumprir qualquer das cláusulas previstas neste Termo.',
    null,
    $estiloParagrafoJustificado
);

/* ------------------------------ Cláusula 9ª ------------------------------ */
$section->addText('CLÁUSULA NONA', $estiloClausulaTitulo);
$section->addText(
    'A prestação de serviços voluntários será acompanhada, coordenada e supervisionada pelo Diretor. ' .
    '(qualificar indicando cargo e matrícula). E, assim, por estarem justas e acertadas, formalizam as ' .
    'partes o presente TERMO DE ADESÃO AO SERVIÇO VOLUNTÁRIO, assinado em 02 (duas) vias de igual teor.',
    null,
    $estiloParagrafoJustificado
);

$section->addTextBreak(1);
$section->addText(
    'Itapuã do Oeste, RO, ${DIA_EMISSAO} de ${MES_EMISSAO} de ${ANO_EMISSAO}.',
    null,
    ['alignment' => Jc::CENTER]
);

/* -------------------------------- Assinaturas -------------------------------- */
$section->addTextBreak(3);
$section->addText('______________________________________________', null, ['alignment' => Jc::CENTER]);
$section->addText('Voluntário (a)', null, ['alignment' => Jc::CENTER]);

$section->addTextBreak(3);
$section->addText('______________________________________________', null, ['alignment' => Jc::CENTER]);
$section->addText('Diretor (a) Escolar', null, ['alignment' => Jc::CENTER]);

$section->addTextBreak(3);
$section->addText('______________________________________________', null, ['alignment' => Jc::CENTER]);
$section->addText('Secretário (a) ${AREA_SECRETARIO}', null, ['alignment' => Jc::CENTER]);

$destino = __DIR__ . '/../templates/anexo_v_modelo.docx';
$writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
$writer->save($destino);

echo "Modelo gerado com sucesso em: {$destino}" . PHP_EOL;
