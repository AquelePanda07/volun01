<?php
/**
 * templates/anexo_v_pdf.php
 *
 * Modelo HTML usado pelo Dompdf para gerar o Termo de Adesão em PDF.
 * Reproduz literalmente o mesmo texto do modelo oficial (ver também
 * templates/anexo_v_modelo.docx e scripts/gerar_modelo_docx.php); apenas
 * os campos abaixo são preenchidos dinamicamente. Nenhuma cláusula deve
 * ser reescrita, resumida ou renumerada.
 *
 * Espera uma variável $d (array associativo) com as chaves preenchidas
 * por TermoAdesaoService::montarDadosSubstituicao().
 */

if (!function_exists('tv')) {
    /** Escapa e imprime um valor do array de dados. */
    function tv(array $d, string $chave): string
    {
        return htmlspecialchars((string) ($d[$chave] ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 90px 70px 80px 70px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #111; line-height: 1.45; }
    h1, h2 { text-align: center; margin: 0; }
    .cabecalho-orgao { font-weight: bold; font-size: 13px; }
    .cabecalho-gabinete { font-size: 11px; margin-bottom: 14px; }
    .titulo-anexo { font-weight: bold; font-size: 13px; margin: 10px 0; }
    .caixa-titulo { border: 1px solid #000; background: #d9d9d9; padding: 8px; text-align: center; font-weight: bold; margin: 12px 0 18px 0; }
    p { text-align: justify; margin: 0 0 10px 0; }
    .clausula-titulo { font-weight: bold; margin: 14px 0 6px 0; }
    .data-emissao { text-align: center; margin: 24px 0; }
    .assinatura { text-align: center; margin-top: 46px; }
    .assinatura .linha { border-top: 1px solid #000; width: 320px; margin: 0 auto 4px auto; }
</style>
</head>
<body>
    <div class="cabecalho-orgao" style="text-align:center;">PREFEITURA DO MUNICÍPIO DE ITAPUÃ DO OESTE/RO</div>
    <div class="cabecalho-orgao" style="text-align:center;">GABINETE MUNICIPAL</div>

    <div class="titulo-anexo">ANEXO V</div>

    <div class="caixa-titulo">TERMO DE ADESÃO AO SERVIÇO VOLUNTÁRIO Nº <?= tv($d, 'numero_termo') ?>/<?= tv($d, 'ano_termo') ?>.</div>

    <p>
        Pelo presente instrumento, de um lado a PREFEITURA DO MUNICÍPIO ITAPUÃ DO OESTE, inscrito no CNPJ
        nº 63.761.936/0001-55, por intermédio da <strong><?= tv($d, 'secretaria') ?></strong>, representada pelo (a) Sr (a)
        <strong><?= tv($d, 'nome_secretario') ?></strong>, com sede Rua Ayrton Sena, Nº 1425, neste ato
        <strong><?= tv($d, 'qualificacao_secretario') ?></strong>, e do outro lado, o Sr(a) <strong><?= tv($d, 'nome_voluntario') ?></strong>,
        CPF: <strong><?= tv($d, 'cpf') ?></strong>, RG: <strong><?= tv($d, 'rg') ?></strong>, expedido pelo órgão <strong><?= tv($d, 'orgao_expedidor') ?></strong>,
        em <strong><?= tv($d, 'data_expedicao') ?></strong>, atualmente com <strong><?= tv($d, 'idade') ?></strong> anos de idade, estado civil
        <strong><?= tv($d, 'estado_civil') ?></strong>, do sexo <strong><?= tv($d, 'sexo') ?></strong>, grau de escolaridade
        <strong><?= tv($d, 'escolaridade') ?></strong> residente e domiciliado <strong><?= tv($d, 'endereco') ?></strong> neste ato denominado
        VOLUNTÁRIO, resolvem, com fundamento na Lei Municipal nº 715, de 26 de setembro de 2019, respectivo
        Regulamento e na Lei Federal nº 9.608, de 1998, celebrar o presente TERMO DE ADESÃO AO SERVIÇO
        VOLUNTÁRIO, mediante as seguintes cláusulas:
    </p>

    <div class="clausula-titulo">CLÁUSULA PRIMEIRA</div>
    <p>
        O VOLUNTÁRIO prestará as atividades discriminadas no respectivo Programa de Trabalho Voluntário,
        conforme anexo que integra este Termo, observadas as normas institucionais pertinentes no
        <?= tv($d, 'local_prestacao') ?> (órgão/local de prestação do serviço), no período de
        <?= tv($d, 'data_inicio') ?> a <?= tv($d, 'data_termino') ?> (máximo de 6 meses), no horário das
        <?= tv($d, 'horario_inicio') ?> às <?= tv($d, 'horario_termino') ?>, à(o)(s) <?= tv($d, 'dias_semana') ?>
        (livre ajustes entre as partes).
    </p>

    <div class="clausula-titulo">CLÁUSULA SEGUNDA</div>
    <p>
        O serviço voluntário não gera vínculo empregatício, funcional ou quaisquer obrigações trabalhistas,
        previdenciárias e será realizado de forma espontânea, não remunerada.
    </p>

    <div class="clausula-titulo">CLÁUSULA TERCEIRA</div>
    <p>
        O exercício do trabalho voluntário não substituirá aqueles próprios de qualquer categoria funcional,
        servidor ou empregado público, havendo de ser respeitado o caráter complementar do serviço.
    </p>

    <div class="clausula-titulo">CLÁUSULA QUARTA</div>
    <p>
        O VOLUNTÁRIO não poderá interferir em condutas definidas pelas equipes técnicas responsáveis pela
        prestação do serviço público no órgão em que exerce suas atividades.
    </p>

    <div class="clausula-titulo">CLÁUSULA QUINTA</div>
    <p>São direitos do VOLUNTÁRIO:</p>
    <p>Escolher uma atividade, inserida no Programa de Trabalho Voluntário, para a qual tenha afinidade;</p>
    <p>Receber capacitação e/ou orientações para exercer adequadamente suas funções;</p>
    <p>Encaminhar sugestões e/ou reclamações ao responsável pelo corpo de voluntários do órgão, visando o
        aperfeiçoamento da prestação dos serviços;</p>
    <p>Ter acesso às informações institucionais para o bom desempenho de suas atividades;</p>
    <p>Ser apresentado ao corpo funcional e ao público beneficiário dos serviços prestados;</p>
    <p>Ter a divulgação periódica dos resultados alcançados no exercício de suas atividades;</p>
    <p>Receber um crachá de identificação para acesso ao trabalho e para sua apresentação à equipe da
        instituição e ao público beneficiário, sendo vedada a transferência a terceiros.</p>
    <p>Ao término da prestação dos serviços voluntários, receber certificado de participação no serviço
        voluntário.</p>

    <div class="clausula-titulo">CLÁUSULA SEXTA</div>
    <p>São deveres do VOLUNTÁRIO, dentre outros:</p>
    <p>Ser assíduo no desempenho de suas atividades;</p>
    <p>Manter comportamento ético, colaborativo e cordial no desempenho de suas atividades junto aos
        dirigentes e servidores públicos do órgão ou entidade em que exerce suas atividades, aos demais
        prestadores de serviços voluntários e ao público em geral;</p>
    <p>Identificar-se, mediante o uso do crachá que lhe for entregue, nas dependências do órgão no qual
        exerce suas atividades, ou fora delas, quando ao seu serviço;</p>
    <p>Exercer suas atribuições conforme previsto no termo de adesão e no programa de trabalho voluntário,
        sempre sob a orientação e coordenação do responsável designado pela direção do órgão ao qual se
        encontra vinculado;</p>
    <p>Comunicar previamente ao gestor do corpo de voluntários a impossibilidade de comparecimento nos dias
        em que estiver escalado para a prestação de serviço voluntário;</p>
    <p>Reparar eventuais danos que por sua culpa ou dolo vier a causar à administração pública estadual ou a
        terceiros, na execução dos serviços voluntários;</p>
    <p>Respeitar e cumprir as normas legais e regulamentares, bem como observar as normas impostas pelo
        órgão no qual se encontrar prestando serviços voluntários.</p>

    <div class="clausula-titulo">CLÁUSULA SÉTIMA</div>
    <p>É vedado ao prestador de serviços voluntários:</p>
    <p>Exercer de forma substitutiva funções privativas de servidor público, nos casos de licença,
        afastamentos legais e vacâncias;</p>
    <p>Identificar-se invocando sua condição de voluntário quando não estiver no pleno exercício das
        atividades voluntárias no órgão estadual a que se vincule;</p>
    <p>Receber, a qualquer título, remuneração pelos serviços prestados voluntariamente.</p>

    <div class="clausula-titulo">CLÁUSULA OITAVA</div>
    <p>Findo o período indicado na Cláusula Primeira, a prestação dos serviços voluntários poderá ser
        renovada a critério da Administração.</p>
    <p>Durante o período de sua vigência, o Termo de Adesão pode ser cancelado a qualquer tempo, por
        iniciativa de qualquer das partes, bastando para isso que uma delas notifique a outra e formalize o
        Termo de Desligamento.</p>
    <p>Será desligado formalmente do exercício de suas funções, o prestador de serviços voluntários que
        descumprir qualquer das cláusulas previstas neste Termo.</p>

    <div class="clausula-titulo">CLÁUSULA NONA</div>
    <p>
        A prestação de serviços voluntários será acompanhada, coordenada e supervisionada pelo Diretor.
        (qualificar indicando cargo e matrícula). E, assim, por estarem justas e acertadas, formalizam as
        partes o presente TERMO DE ADESÃO AO SERVIÇO VOLUNTÁRIO, assinado em 02 (duas) vias de igual teor.
    </p>

    <div class="data-emissao">Itapuã do Oeste, RO, <?= tv($d, 'dia_emissao') ?> de <?= tv($d, 'mes_emissao') ?> de <?= tv($d, 'ano_emissao') ?>.</div>

    <div class="assinatura">
        <div class="linha"></div>
        Voluntário (a)
    </div>
    <div class="assinatura">
        <div class="linha"></div>
        Diretor (a) Escolar
    </div>
    <div class="assinatura">
        <div class="linha"></div>
        Secretário (a) <?= tv($d, 'area_secretario') ?>
    </div>
</body>
</html>
