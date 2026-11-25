<?php
/**
 * api/empenhos.php
 * Controle de Empenhos dos voluntários (services/EmpenhoService.php).
 *
 * Consultas (GET):
 *   ?acao=parametros                                  -> valor do dia por jornada, etc.
 *   ?acao=listar[&ano=2026][&lotacao=...][&idVoluntario=5]
 *   ?acao=resumo&ano=2026[&lotacao=...]               -> painel anual
 *   ?acao=extrato&idVoluntario=5
 *   ?acao=lote&competencia=2026-10[&lotacao=...]      -> prepara o lançamento em lote
 *   ?acao=distribuicao&idVoluntario=5&valor=1102.50   -> sugestão de divisão entre empenhos
 *   ?acao=exportar&ano=2026[&lotacao=...][&formato=xlsx|csv]
 *
 * Gravações (JSON):
 *   POST   ?acao=empenho                  -> novo empenho
 *   PUT    ?acao=empenho&id=7             -> altera empenho
 *   DELETE ?acao=empenho&id=7             -> exclui empenho sem movimentos
 *   POST   ?acao=pagamento                -> {idVoluntario, competencia, valor, dias, distribuicao:[{idEmpenho, valor}], confirmar}
 *   POST   ?acao=lote                     -> {competencia, itens:[...como pagamento...], confirmar}
 *   POST   ?acao=anular                   -> {modo: total|parcial, idVoluntario, idEmpenho, valor, competencia, motivo}
 *   DELETE ?acao=movimento&id=9           -> exclui um lançamento (correção)
 *
 * Validação -> 422 com "erros". Aviso que precisa de confirmação (ex.: competência
 * já lançada) -> 409 com "avisos"; reenviar com "confirmar": true para gravar.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
habilitarCors();
configurarTratamentoDeErros();

$servico = new EmpenhoService();
$metodo = $_SERVER['REQUEST_METHOD'];
$acao = (string) ($_GET['acao'] ?? '');
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

try {
    switch ("{$metodo} {$acao}") {
        case 'GET parametros':
            responderJson(['sucesso' => true, 'dados' => $servico->parametros() + ['anos' => $servico->anos()]]);

        case 'GET listar':
            responderJson(['sucesso' => true, 'dados' => $servico->listarEmpenhos($_GET)]);

        case 'GET resumo':
            $ano = (int) ($_GET['ano'] ?? date('Y'));
            responderJson(['sucesso' => true, 'dados' => $servico->resumoAnual($ano, (string) ($_GET['lotacao'] ?? ''))]);

        case 'GET extrato':
            responderJson(['sucesso' => true, 'dados' => $servico->extrato((int) ($_GET['idVoluntario'] ?? 0))]);

        case 'GET lote':
            responderJson(['sucesso' => true, 'dados' => $servico->prepararLote(
                (string) ($_GET['competencia'] ?? ''),
                (string) ($_GET['lotacao'] ?? '')
            )]);

        case 'GET distribuicao':
            $valor = EmpenhoService::centavos($_GET['valor'] ?? '');
            if ($valor === null || $valor <= 0) {
                responderJson(['sucesso' => false, 'mensagem' => 'Informe um valor válido.'], 422);
            }
            responderJson(['sucesso' => true, 'dados' => $servico->sugerirDistribuicao((int) ($_GET['idVoluntario'] ?? 0), $valor)]);

        case 'GET exportar':
            $ano = (int) ($_GET['ano'] ?? date('Y'));
            $lotacao = (string) ($_GET['lotacao'] ?? '');
            $exportacao = new EmpenhoExportacao($servico->resumoAnual($ano, $lotacao));
            ($_GET['formato'] ?? 'xlsx') === 'csv' ? $exportacao->enviarCsv() : $exportacao->enviarXlsx();
            exit;

        case 'POST empenho':
            responderJson(['sucesso' => true, 'mensagem' => 'Empenho cadastrado.', 'dados' => $servico->criarEmpenho(lerJsonDaRequisicao())], 201);

        case 'PUT empenho':
            exigirId($id, 'empenho');
            responderJson(['sucesso' => true, 'mensagem' => 'Empenho atualizado.', 'dados' => $servico->atualizarEmpenho($id, lerJsonDaRequisicao())]);

        case 'DELETE empenho':
            exigirId($id, 'empenho');
            $servico->excluirEmpenho($id);
            responderJson(['sucesso' => true, 'mensagem' => 'Empenho excluído.']);

        case 'POST pagamento':
            $dados = lerJsonDaRequisicao();
            $movimentos = $servico->lancarPagamento($dados, !empty($dados['confirmar']));
            responderJson(['sucesso' => true, 'mensagem' => 'Pagamento lançado.', 'dados' => $movimentos], 201);

        case 'POST lote':
            $dados = lerJsonDaRequisicao();
            $movimentos = $servico->lancarLote(
                (string) ($dados['competencia'] ?? ''),
                array_values((array) ($dados['itens'] ?? [])),
                !empty($dados['confirmar'])
            );
            $pessoas = count(array_unique(array_column($movimentos, 'idVoluntario')));
            responderJson(['sucesso' => true, 'mensagem' => "Pagamentos lançados para {$pessoas} voluntário(s).", 'dados' => $movimentos], 201);

        case 'POST anular':
            $movimentos = $servico->anular(lerJsonDaRequisicao());
            responderJson(['sucesso' => true, 'mensagem' => 'Anulação registrada.', 'dados' => $movimentos], 201);

        case 'DELETE movimento':
            exigirId($id, 'lançamento');
            $servico->excluirMovimento($id);
            responderJson(['sucesso' => true, 'mensagem' => 'Lançamento excluído.']);

        default:
            responderJson(['sucesso' => false, 'mensagem' => 'Ação ou método não suportado.'], 405);
    }
} catch (EmpenhoValidacaoException $e) {
    if ($e->ehAviso()) {
        responderJson(['sucesso' => false, 'mensagem' => $e->getMessage(), 'avisos' => $e->avisos, 'precisaConfirmar' => true], 409);
    }
    responderJson(['sucesso' => false, 'mensagem' => $e->getMessage(), 'erros' => $e->erros], 422);
}

function exigirId(int $id, string $oque): void
{
    if ($id <= 0) {
        responderJson(['sucesso' => false, 'mensagem' => "Informe o id do {$oque}."], 400);
    }
}
