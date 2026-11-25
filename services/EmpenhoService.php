<?php
/**
 * services/EmpenhoService.php
 * Regras de negócio do "Controle de Empenhos" dos voluntários
 * (abas/tabelas "Empenhos" e "Movimentos Empenho").
 *
 *   - Saldo do empenho    = valor − pagamentos − anulações
 *   - Saldo do voluntário = soma dos saldos dos seus empenhos
 *
 * Toda conta é feita em CENTAVOS (inteiros) para não acumular erro de
 * arredondamento; os valores são gravados como texto com 2 casas e ponto
 * decimal ("1102.50"), no padrão VARCHAR do projeto.
 *
 * Cada pagamento mensal vira UM movimento por empenho de onde saiu o
 * dinheiro: a distribuição automática debita primeiro do empenho mais
 * antigo que ainda tem saldo (ver sugerirDistribuicao()).
 *
 * Usa apenas RepositorioDados (funciona com MySQL e com Google Sheets).
 */

declare(strict_types=1);

/** Erros de validação (422) ou avisos que pedem confirmação (409) do Controle de Empenhos. */
class EmpenhoValidacaoException extends RuntimeException
{
    /** @param string[] $erros @param string[] $avisos */
    public function __construct(public readonly array $erros, public readonly array $avisos = [])
    {
        parent::__construct($erros[0] ?? ($avisos[0] ?? 'Dados inválidos.'));
    }

    public function ehAviso(): bool
    {
        return empty($this->erros) && !empty($this->avisos);
    }
}

class EmpenhoService
{
    public const TIPOS_EMPENHO = ['ORIGINAL', 'REFORCO'];
    public const PAGAMENTO = 'PAGAMENTO';
    public const ANULACAO = 'ANULACAO';

    private RepositorioDados $dados;
    private array $config;

    /** Cache por requisição (zerado a cada gravação). */
    private ?array $cacheEmpenhos = null;
    private ?array $cacheMovimentos = null;
    private ?array $cacheVoluntarios = null;
    private ?array $cacheContratoAtual = null;

    public function __construct(?RepositorioDados $dados = null)
    {
        $this->dados = $dados ?? repositorioDados();
        $this->config = require __DIR__ . '/../config/empenhos.php';
    }

    // ==================================================================
    // Valores em centavos
    // ==================================================================

    /**
     * "1.102,50" | "1102,50" | "1102.50" | 1102.5 -> 110250. Vazio -> 0.
     * Devolve null quando o texto não é um valor válido.
     */
    public static function centavos($valor): ?int
    {
        if (is_int($valor)) {
            return $valor * 100;
        }
        if (is_float($valor)) {
            return (int) round($valor * 100);
        }
        $texto = str_replace(['R$', ' ', "\xc2\xa0"], '', trim((string) $valor));
        if ($texto === '' || $texto === '-') {
            return 0;
        }
        if (str_contains($texto, ',')) {
            $texto = str_replace(',', '.', str_replace('.', '', $texto));
        }
        if (!preg_match('/^-?\d+(\.\d+)?$/', $texto)) {
            return null;
        }
        // Sem passar por float: "1102.505" arredonda pela 3ª casa.
        $negativo = $texto[0] === '-';
        [$inteiro, $fracao] = array_pad(explode('.', ltrim($texto, '-')), 2, '');
        $fracao = str_pad($fracao, 3, '0');
        $c = ((int) $inteiro) * 100 + (int) substr($fracao, 0, 2) + ((int) $fracao[2] >= 5 ? 1 : 0);
        return $negativo ? -$c : $c;
    }

    /** 110250 -> "1102.50" (formato gravado no banco). */
    public static function reais(int $centavos): string
    {
        $sinal = $centavos < 0 ? '-' : '';
        $abs = abs($centavos);
        return sprintf('%s%d.%02d', $sinal, intdiv($abs, 100), $abs % 100);
    }

    // ==================================================================
    // Parâmetros (config/empenhos.php)
    // ==================================================================

    /** Valor de 1 dia trabalhado (centavos) para a carga horária do contrato; null se desconhecida. */
    public function valorDia(string $cargaHoraria): ?int
    {
        $carga = preg_replace('/\D/', '', $cargaHoraria);
        $valor = $this->config['valor_dia'][$carga] ?? null;
        return $valor === null ? null : self::centavos($valor);
    }

    /** dias × valor do dia (centavos). Dias em passos de meio dia. */
    public function valorSugerido(string $cargaHoraria, float $dias): ?int
    {
        $dia = $this->valorDia($cargaHoraria);
        if ($dia === null) {
            return null;
        }
        return (int) round($dia * $dias);
    }

    /** Valor do Empenho 1 do semestre (6 meses × 20 dias × valor do dia). */
    public function valorOriginalSugerido(string $cargaHoraria): ?int
    {
        $dia = $this->valorDia($cargaHoraria);
        if ($dia === null) {
            return null;
        }
        return $dia * (int) $this->config['dias_mes_referencia'] * (int) $this->config['meses_empenho_original'];
    }

    /** Parâmetros usados pelo front-end. */
    public function parametros(): array
    {
        return [
            'valorDia' => $this->config['valor_dia'],
            'horasDia' => $this->config['horas_dia'],
            'valorHora' => $this->config['valor_hora'],
            'mesesEmpenhoOriginal' => (int) $this->config['meses_empenho_original'],
            'diasMesReferencia' => (int) $this->config['dias_mes_referencia'],
            'passoDias' => (float) $this->config['passo_dias'],
        ];
    }

    /** Dias do contrato no mês (dias da semana do contrato, dentro do período dele). */
    public function diasDoContratoNoMes(array $contrato, string $competencia): int
    {
        [$ano, $mes] = array_map('intval', explode('-', $competencia));
        $codigos = ['', 'SEG', 'TER', 'QUA', 'QUI', 'SEX', 'SAB', 'DOM'];
        $permitidos = array_values(array_intersect(
            array_map('trim', explode(',', (string) ($contrato['Dias Semana'] ?? ''))),
            $codigos
        ));
        if (empty(array_filter($permitidos))) {
            $permitidos = ['SEG', 'TER', 'QUA', 'QUI', 'SEX'];
        }
        $inicio = (string) ($contrato['Data Inicio'] ?? '');
        $termino = (string) ($contrato['Data Termino'] ?? '');
        $total = 0;
        $ultimo = (int) date('t', mktime(0, 0, 0, $mes, 1, $ano));
        for ($dia = 1; $dia <= $ultimo; $dia++) {
            $iso = sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
            if (($inicio !== '' && $iso < $inicio) || ($termino !== '' && $iso > $termino)) {
                continue;
            }
            if (in_array($codigos[(int) date('N', mktime(0, 0, 0, $mes, $dia, $ano))], $permitidos, true)) {
                $total++;
            }
        }
        return $total;
    }

    // ==================================================================
    // Empenhos
    // ==================================================================

    /**
     * @param array{ano?: int|string, lotacao?: string, idVoluntario?: int|string} $filtros
     * @return array<int, array<string, mixed>> empenhos com pago/anulado/saldo, do mais antigo ao mais novo.
     */
    public function listarEmpenhos(array $filtros = []): array
    {
        $ano = (string) ($filtros['ano'] ?? '');
        $lotacao = self::chave((string) ($filtros['lotacao'] ?? ''));
        $idVoluntario = (int) ($filtros['idVoluntario'] ?? 0);

        $resultado = [];
        foreach ($this->empenhosOrdenados() as $linha) {
            if ($ano !== '' && (string) $linha['Ano'] !== $ano) {
                continue;
            }
            if ($lotacao !== '' && self::chave((string) $linha['Lotacao']) !== $lotacao) {
                continue;
            }
            if ($idVoluntario > 0 && (int) $linha['ID Voluntario'] !== $idVoluntario) {
                continue;
            }
            $resultado[] = $this->empenhoParaSaida($linha);
        }
        return $resultado;
    }

    public function buscarEmpenho(int $id): ?array
    {
        foreach ($this->empenhos() as $linha) {
            if ((int) $linha['ID'] === $id) {
                return $this->empenhoParaSaida($linha);
            }
        }
        return null;
    }

    public function criarEmpenho(array $dados): array
    {
        $linha = $this->validarEmpenho($dados, null);
        $linha['Data Cadastro'] = date('Y-m-d H:i:s');
        $gravado = $this->dados->inserir('empenhos', $linha);
        $this->limparCache();
        return $this->buscarEmpenho((int) $gravado['ID']) ?? [];
    }

    public function atualizarEmpenho(int $id, array $dados): array
    {
        $atual = $this->linhaEmpenho($id);
        if ($atual === null) {
            throw new EmpenhoValidacaoException(['Empenho não encontrado.']);
        }
        $linha = $this->validarEmpenho($dados, $atual);
        $this->dados->atualizar('empenhos', $id, $linha);
        $this->limparCache();
        return $this->buscarEmpenho($id) ?? [];
    }

    /** Só exclui empenho sem movimentos (para corrigir um cadastro errado). */
    public function excluirEmpenho(int $id): void
    {
        if ($this->linhaEmpenho($id) === null) {
            throw new EmpenhoValidacaoException(['Empenho não encontrado.']);
        }
        if (!empty($this->movimentosDoEmpenho($id))) {
            throw new EmpenhoValidacaoException([
                'Este empenho já tem pagamentos ou anulações. Exclua os movimentos antes (ou anule o saldo).',
            ]);
        }
        $this->dados->excluir('empenhos', $id);
        $this->limparCache();
    }

    // ==================================================================
    // Pagamentos
    // ==================================================================

    /**
     * Sugere de quais empenhos sai um pagamento: primeiro o mais antigo com
     * saldo, dividindo entre empenhos quando um só não basta.
     *
     * @param array<int, int> $reservado centavos já comprometidos por empenho (ex.: outras linhas do mesmo lote)
     * @return array{distribuicao: array<int, array{idEmpenho:int, numero:string, valor:string}>, faltando: string}
     */
    public function sugerirDistribuicao(int $idVoluntario, int $valor, array $reservado = []): array
    {
        $distribuicao = [];
        $restante = $valor;
        foreach ($this->empenhosOrdenados() as $linha) {
            if ($restante <= 0) {
                break;
            }
            if ((int) $linha['ID Voluntario'] !== $idVoluntario) {
                continue;
            }
            $id = (int) $linha['ID'];
            $saldo = $this->saldoEmpenho($linha) - ($reservado[$id] ?? 0);
            if ($saldo <= 0) {
                continue;
            }
            $parte = min($saldo, $restante);
            $distribuicao[] = ['idEmpenho' => $id, 'numero' => (string) $linha['Numero Empenho'], 'valor' => self::reais($parte)];
            $restante -= $parte;
        }
        return ['distribuicao' => $distribuicao, 'faltando' => self::reais(max(0, $restante))];
    }

    /**
     * Lança o pagamento de uma competência para um voluntário.
     *
     * @param array{idVoluntario:int, competencia:string, valor:string, dias?:string|float,
     *              distribuicao?: array<int, array{idEmpenho:int, valor:string}>, data?:string, observacao?:string} $dados
     * @return array<int, array<string, mixed>> movimentos gravados
     */
    public function lancarPagamento(array $dados, bool $confirmar = false): array
    {
        $movimentos = $this->validarPagamento($dados, [], $erros, $avisos);
        $this->exigirValido($erros, $avisos, $confirmar);
        return $this->gravarMovimentos($movimentos);
    }

    /**
     * Prepara o lançamento em lote: voluntários com contrato na competência e
     * na lotação, com os dias do contrato no mês, o valor sugerido e a
     * distribuição entre empenhos já calculada.
     */
    public function prepararLote(string $competencia, string $lotacao): array
    {
        if (!self::competenciaValida($competencia)) {
            throw new EmpenhoValidacaoException(['Informe uma competência válida (mês e ano).']);
        }
        $chaveLotacao = self::chave($lotacao);
        $primeiroDia = $competencia . '-01';
        $ultimoDia = date('Y-m-t', strtotime($primeiroDia));

        // Voluntários da lotação: pelo contrato atual ou por ter empenho nela.
        $ids = [];
        foreach ($this->contratosAtuais() as $idVol => $contrato) {
            if ($chaveLotacao === '' || self::chave((string) $contrato['Local Prestacao']) === $chaveLotacao) {
                $ids[$idVol] = true;
            }
        }
        foreach ($this->empenhos() as $e) {
            if ($chaveLotacao === '' || self::chave((string) $e['Lotacao']) === $chaveLotacao) {
                $ids[(int) $e['ID Voluntario']] = true;
            }
        }

        $itens = [];
        $reservado = [];
        foreach (array_keys($ids) as $idVol) {
            $voluntario = $this->voluntario($idVol);
            $contrato = $this->contratosAtuais()[$idVol] ?? null;
            if ($voluntario === null || $contrato === null || self::naoAtivo($voluntario)) {
                continue;
            }
            // Só quem tinha contrato em algum dia da competência.
            $inicio = (string) $contrato['Data Inicio'];
            $termino = (string) $contrato['Data Termino'];
            if (($inicio !== '' && $inicio > $ultimoDia) || ($termino !== '' && $termino < $primeiroDia)) {
                continue;
            }

            $carga = (string) $contrato['Carga Horaria'];
            $dias = $this->diasDoContratoNoMes($contrato, $competencia);
            $valor = $this->valorSugerido($carga, $dias) ?? 0;
            $jaLancado = $this->pagoNaCompetencia($idVol, $competencia);
            $sugestao = $this->sugerirDistribuicao($idVol, $valor, $reservado);
            foreach ($sugestao['distribuicao'] as $parte) {
                $reservado[$parte['idEmpenho']] = ($reservado[$parte['idEmpenho']] ?? 0) + self::centavos($parte['valor']);
            }

            $itens[] = [
                'idVoluntario' => $idVol,
                'nome' => (string) $voluntario['Nome'],
                'cargaHoraria' => $carga,
                'lotacao' => (string) $contrato['Local Prestacao'],
                'valorDia' => $this->valorDia($carga) === null ? '' : self::reais($this->valorDia($carga)),
                'dias' => $dias,
                'valor' => self::reais($valor),
                'saldo' => self::reais($this->saldoVoluntario($idVol)),
                'empenhos' => array_values(array_filter(
                    $this->listarEmpenhos(['idVoluntario' => $idVol]),
                    fn (array $e) => self::centavos($e['saldo']) > 0
                )),
                'distribuicao' => $sugestao['distribuicao'],
                'faltando' => $sugestao['faltando'],
                'jaLancado' => self::reais($jaLancado),
            ];
        }
        usort($itens, fn ($a, $b) => strcmp(self::chave($a['nome']), self::chave($b['nome'])));
        return $itens;
    }

    /**
     * Grava os pagamentos de vários voluntários de uma vez (tudo ou nada no MySQL).
     *
     * @param array<int, array<string, mixed>> $itens cada um no formato de lancarPagamento()
     * @return array<int, array<string, mixed>> movimentos gravados
     */
    public function lancarLote(string $competencia, array $itens, bool $confirmar = false): array
    {
        if (empty($itens)) {
            throw new EmpenhoValidacaoException(['Selecione pelo menos um voluntário.']);
        }
        $erros = [];
        $avisos = [];
        $movimentos = [];
        $reservado = [];
        foreach ($itens as $item) {
            $item['competencia'] = $competencia;
            $nome = (string) ($this->voluntario((int) ($item['idVoluntario'] ?? 0))['Nome'] ?? 'Voluntário');
            $errosItem = [];
            $avisosItem = [];
            $novos = $this->validarPagamento($item, $reservado, $errosItem, $avisosItem);
            foreach ($errosItem as $e) {
                $erros[] = "{$nome}: {$e}";
            }
            foreach ($avisosItem as $a) {
                $avisos[] = "{$nome}: {$a}";
            }
            foreach ($novos as $m) {
                $reservado[(int) $m['ID Empenho']] = ($reservado[(int) $m['ID Empenho']] ?? 0) + self::centavos($m['Valor']);
            }
            $movimentos = array_merge($movimentos, $novos);
        }
        $this->exigirValido($erros, $avisos, $confirmar);
        return $this->gravarMovimentos($movimentos);
    }

    // ==================================================================
    // Anulação
    // ==================================================================

    /**
     * Anula saldo de empenho.
     *   - total:   idVoluntario (+ idEmpenho opcional) -> anula TODO o saldo restante
     *   - parcial: idEmpenho + valor (<= saldo)
     * O motivo é obrigatório.
     *
     * @return array<int, array<string, mixed>> movimentos gravados
     */
    public function anular(array $dados): array
    {
        $erros = [];
        $motivo = trim((string) ($dados['motivo'] ?? ''));
        $competencia = (string) ($dados['competencia'] ?? '');
        $parcial = ($dados['modo'] ?? 'total') === 'parcial';
        $idVoluntario = (int) ($dados['idVoluntario'] ?? 0);
        $idEmpenho = (int) ($dados['idEmpenho'] ?? 0);
        $data = (string) ($dados['data'] ?? '') ?: date('Y-m-d');

        if ($motivo === '') {
            $erros[] = 'Informe o motivo da anulação.';
        }
        if (!self::competenciaValida($competencia)) {
            $erros[] = 'Informe a competência (mês e ano) da anulação.';
        }
        if (!self::dataValida($data)) {
            $erros[] = 'Data da anulação inválida.';
        }

        $alvos = [];
        if ($idEmpenho > 0) {
            $linha = $this->linhaEmpenho($idEmpenho);
            if ($linha === null || ($idVoluntario > 0 && (int) $linha['ID Voluntario'] !== $idVoluntario)) {
                $erros[] = 'Empenho não encontrado para este voluntário.';
            } else {
                $alvos[] = $linha;
            }
        } elseif ($parcial) {
            $erros[] = 'Na anulação parcial, escolha o empenho.';
        } elseif ($this->voluntario($idVoluntario) === null) {
            $erros[] = 'Voluntário não encontrado.';
        } else {
            $alvos = array_values(array_filter(
                $this->empenhosOrdenados(),
                fn (array $e) => (int) $e['ID Voluntario'] === $idVoluntario
            ));
        }

        $movimentos = [];
        foreach ($alvos as $linha) {
            $saldo = $this->saldoEmpenho($linha);
            if ($parcial) {
                $valor = self::centavos($dados['valor'] ?? '');
                if ($valor === null || $valor <= 0) {
                    $erros[] = 'Informe o valor a anular.';
                    continue;
                }
                if ($valor > $saldo) {
                    $erros[] = sprintf('O valor a anular (R$ %s) é maior que o saldo do empenho %s (R$ %s).',
                        self::br($valor), $linha['Numero Empenho'], self::br($saldo));
                    continue;
                }
            } else {
                $valor = $saldo;
            }
            if ($valor <= 0) {
                continue;
            }
            $movimentos[] = $this->novoMovimento($linha, self::ANULACAO, $competencia, $valor, '', $data, $motivo);
        }
        if (empty($erros) && empty($movimentos)) {
            $erros[] = 'Não há saldo para anular.';
        }

        $this->exigirValido($erros, [], true);
        return $this->gravarMovimentos($movimentos);
    }

    public function excluirMovimento(int $id): void
    {
        $existe = false;
        foreach ($this->movimentos() as $m) {
            if ((int) $m['ID'] === $id) {
                $existe = true;
                break;
            }
        }
        if (!$existe) {
            throw new EmpenhoValidacaoException(['Movimento não encontrado.']);
        }
        $this->dados->excluir('movimentos_empenho', $id);
        $this->limparCache();
    }

    // ==================================================================
    // Consultas
    // ==================================================================

    /** Extrato do voluntário: empenhos com saldo, histórico de movimentos e alerta. */
    public function extrato(int $idVoluntario): array
    {
        $voluntario = $this->voluntario($idVoluntario);
        if ($voluntario === null) {
            throw new EmpenhoValidacaoException(['Voluntário não encontrado.']);
        }
        $contrato = $this->contratosAtuais()[$idVoluntario] ?? null;
        $empenhos = $this->listarEmpenhos(['idVoluntario' => $idVoluntario]);
        $numeros = array_column($empenhos, 'numero', 'id');

        $movimentos = [];
        foreach ($this->movimentos() as $m) {
            if ((int) $m['ID Voluntario'] !== $idVoluntario) {
                continue;
            }
            $movimentos[] = $this->movimentoParaSaida($m) + ['numeroEmpenho' => (string) ($numeros[(int) $m['ID Empenho']] ?? '')];
        }
        usort($movimentos, fn ($a, $b) => [$b['competencia'], $b['id']] <=> [$a['competencia'], $a['id']]);

        $totais = ['empenhado' => 0, 'pago' => 0, 'anulado' => 0, 'saldo' => 0];
        foreach ($empenhos as $e) {
            foreach ($totais as $k => $_) {
                $totais[$k] += self::centavos($e[$k === 'empenhado' ? 'valor' : $k]);
            }
        }
        $carga = (string) ($contrato['Carga Horaria'] ?? '');

        return [
            'voluntario' => [
                'id' => $idVoluntario,
                'nome' => (string) $voluntario['Nome'],
                'naoAtivo' => self::naoAtivo($voluntario),
                'cargaHoraria' => $carga,
                'valorDia' => $this->valorDia($carga) === null ? '' : self::reais($this->valorDia($carga)),
                'lotacao' => (string) ($contrato['Local Prestacao'] ?? ''),
                'idContrato' => (int) ($contrato['ID'] ?? 0),
                'dataInicio' => (string) ($contrato['Data Inicio'] ?? ''),
                'dataTermino' => (string) ($contrato['Data Termino'] ?? ''),
                'statusContrato' => $contrato ? ContratoService::calcularStatus((string) $contrato['Data Inicio'], (string) $contrato['Data Termino']) : '',
                'valorOriginalSugerido' => $this->valorOriginalSugerido($carga) === null ? '' : self::reais($this->valorOriginalSugerido($carga)),
            ],
            'empenhos' => $empenhos,
            'movimentos' => $movimentos,
            'totais' => array_map([self::class, 'reais'], $totais),
            'situacao' => $this->situacao($idVoluntario, $totais['saldo'], $totais['anulado']),
        ];
    }

    /**
     * Painel anual (substitui a planilha): uma linha por voluntário e lotação
     * (a lotação é a do EMPENHO), com os 12 meses, totais por lotação e geral.
     */
    public function resumoAnual(int $ano, string $lotacao = ''): array
    {
        $chaveFiltro = self::chave($lotacao);
        $grupos = [];

        foreach ($this->empenhosOrdenados() as $e) {
            if ((int) $e['Ano'] !== $ano) {
                continue;
            }
            $nomeLotacao = trim((string) $e['Lotacao']) ?: 'Sem lotação';
            $chaveLot = self::chave($nomeLotacao);
            if ($chaveFiltro !== '' && $chaveLot !== $chaveFiltro) {
                continue;
            }
            $idVol = (int) $e['ID Voluntario'];
            $grupos[$chaveLot]['lotacao'] ??= $nomeLotacao;
            $linha = &$grupos[$chaveLot]['linhas'][$idVol];
            if ($linha === null) {
                $linha = [
                    'idVoluntario' => $idVol,
                    'nome' => (string) ($this->voluntario($idVol)['Nome'] ?? "Voluntário #{$idVol}"),
                    'empenhos' => [],
                    'meses' => array_fill(1, 12, ['pago' => 0, 'anulado' => 0]),
                    'empenhado' => 0, 'pago' => 0, 'anulado' => 0, 'saldo' => 0,
                ];
            }
            $saida = $this->empenhoParaSaida($e);
            $linha['empenhos'][] = $saida;
            $linha['empenhado'] += self::centavos($saida['valor']);
            $linha['pago'] += self::centavos($saida['pago']);
            $linha['anulado'] += self::centavos($saida['anulado']);
            $linha['saldo'] += self::centavos($saida['saldo']);
            foreach ($this->movimentosDoEmpenho((int) $e['ID']) as $m) {
                [$anoMov, $mes] = array_map('intval', explode('-', (string) $m['Competencia']) + [1 => 0]);
                if ($anoMov !== $ano || $mes < 1 || $mes > 12) {
                    continue; // movimento de outro ano: entra nos totais, não nas colunas dos meses
                }
                $campo = $m['Tipo'] === self::ANULACAO ? 'anulado' : 'pago';
                $linha['meses'][$mes][$campo] += (int) self::centavos($m['Valor']);
            }
            unset($linha);
        }

        $zerar = fn () => ['empenhado' => 0, 'pago' => 0, 'anulado' => 0, 'saldo' => 0, 'meses' => array_fill(1, 12, ['pago' => 0, 'anulado' => 0])];
        $somar = function (array &$total, array $linha) {
            foreach (['empenhado', 'pago', 'anulado', 'saldo'] as $k) {
                $total[$k] += $linha[$k];
            }
            foreach ($linha['meses'] as $mes => $v) {
                $total['meses'][$mes]['pago'] += $v['pago'];
                $total['meses'][$mes]['anulado'] += $v['anulado'];
            }
        };
        $paraReais = function (array $t) {
            foreach (['empenhado', 'pago', 'anulado', 'saldo'] as $k) {
                $t[$k] = self::reais($t[$k]);
            }
            $t['meses'] = array_map(fn ($v) => ['pago' => self::reais($v['pago']), 'anulado' => self::reais($v['anulado'])], $t['meses']);
            return $t;
        };

        $geral = $zerar();
        $saidaGrupos = [];
        foreach ($grupos as $grupo) {
            $total = $zerar();
            $linhas = [];
            foreach ($grupo['linhas'] as $linha) {
                $somar($total, $linha);
                $linha['situacao'] = $this->situacao($linha['idVoluntario'], $linha['saldo'], $linha['anulado']);
                $linhas[] = $paraReais($linha);
            }
            $somar($geral, $total);
            usort($linhas, fn ($a, $b) => strcmp(self::chave($a['nome']), self::chave($b['nome'])));
            $saidaGrupos[] = ['lotacao' => $grupo['lotacao'], 'linhas' => $linhas, 'total' => $paraReais($total)];
        }
        usort($saidaGrupos, fn ($a, $b) => strcmp(self::chave($a['lotacao']), self::chave($b['lotacao'])));

        return ['ano' => $ano, 'grupos' => $saidaGrupos, 'total' => $paraReais($geral)];
    }

    /** Anos que têm empenhos (para o filtro do painel), do mais novo ao mais antigo. */
    public function anos(): array
    {
        $anos = array_unique(array_map(fn ($e) => (int) $e['Ano'], $this->empenhos()));
        $anos[] = (int) date('Y');
        $anos = array_values(array_unique(array_filter($anos)));
        rsort($anos);
        return $anos;
    }

    // ==================================================================
    // Validação
    // ==================================================================

    /** Valida e monta a linha do empenho (criação quando $atual = null). */
    private function validarEmpenho(array $dados, ?array $atual): array
    {
        $erros = [];
        $pega = fn (string $camel, string $coluna) => array_key_exists($camel, $dados)
            ? trim((string) $dados[$camel])
            : (string) ($atual[$coluna] ?? '');

        $idVoluntario = (int) $pega('idVoluntario', 'ID Voluntario');
        $voluntario = $this->voluntario($idVoluntario);
        if ($voluntario === null) {
            $erros[] = 'Voluntário não encontrado.';
        }
        if ($atual !== null && $idVoluntario !== (int) $atual['ID Voluntario']) {
            $erros[] = 'Não é possível trocar o voluntário de um empenho.';
        }
        $contrato = $this->contratosAtuais()[$idVoluntario] ?? null;

        $ano = (int) $pega('ano', 'Ano');
        if ($ano < (int) $this->config['ano_minimo'] || $ano > 2100) {
            $erros[] = 'Informe um ano válido.';
        }
        $numero = $pega('numero', 'Numero Empenho');
        if ($numero === '') {
            $erros[] = 'Informe o número do empenho.';
        } elseif (!preg_match('/^[\w\/.\-]{1,30}$/u', $numero)) {
            $erros[] = 'Número do empenho inválido.';
        } else {
            foreach ($this->empenhos() as $e) {
                if ((int) $e['Ano'] === $ano && self::chave((string) $e['Numero Empenho']) === self::chave($numero)
                    && (int) $e['ID'] !== (int) ($atual['ID'] ?? 0)) {
                    $nomeOutro = (string) ($this->voluntario((int) $e['ID Voluntario'])['Nome'] ?? '');
                    $erros[] = "O empenho nº {$numero} já existe em {$ano}" . ($nomeOutro !== '' ? " ({$nomeOutro})." : '.');
                    break;
                }
            }
        }

        $valor = self::centavos($pega('valor', 'Valor'));
        if ($valor === null || $valor <= 0) {
            $erros[] = 'Informe o valor do empenho.';
        } elseif ($atual !== null) {
            $usado = $this->somaMovimentos((int) $atual['ID']);
            if ($valor < $usado['pago'] + $usado['anulado']) {
                $erros[] = sprintf('O valor não pode ser menor que o já pago e anulado neste empenho (R$ %s).',
                    self::br($usado['pago'] + $usado['anulado']));
            }
        }

        $tipo = strtoupper($pega('tipo', 'Tipo'));
        if ($tipo === '') {
            $tipo = empty($this->listarEmpenhos(['idVoluntario' => $idVoluntario, 'ano' => $ano])) ? 'ORIGINAL' : 'REFORCO';
        }
        if (!in_array($tipo, self::TIPOS_EMPENHO, true)) {
            $erros[] = 'Tipo de empenho inválido (Original ou Reforço).';
        }

        $data = $pega('dataEmpenho', 'Data Empenho');
        if ($data !== '' && !self::dataValida($data)) {
            $erros[] = 'Data do empenho inválida.';
        }

        $lotacao = $pega('lotacao', 'Lotacao');
        if ($lotacao === '') {
            $lotacao = (string) ($contrato['Local Prestacao'] ?? '');
        }
        if ($lotacao === '') {
            $erros[] = 'Informe a lotação do empenho.';
        }

        if (!empty($erros)) {
            throw new EmpenhoValidacaoException($erros);
        }

        $idContrato = $pega('idContrato', 'ID Contrato');
        return [
            'ID Voluntario' => (string) $idVoluntario,
            'ID Contrato' => $idContrato !== '' && $idContrato !== '0' ? $idContrato : (string) ($contrato['ID'] ?? ''),
            'Ano' => (string) $ano,
            'Numero Empenho' => $numero,
            'Data Empenho' => $data,
            'Valor' => self::reais($valor),
            'Tipo' => $tipo,
            'Lotacao' => $lotacao,
            'Fonte Recurso' => mb_substr($pega('fonteRecurso', 'Fonte Recurso'), 0, 100),
            'Observacao' => mb_substr($pega('observacao', 'Observacao'), 0, 250),
        ];
    }

    /**
     * Valida um pagamento e devolve os movimentos a gravar (um por empenho).
     * @param array<int, int> $reservado centavos já usados por empenho (linhas anteriores do lote)
     */
    private function validarPagamento(array $dados, array $reservado, ?array &$erros, ?array &$avisos): array
    {
        $erros = [];
        $avisos = [];
        $idVoluntario = (int) ($dados['idVoluntario'] ?? 0);
        $competencia = (string) ($dados['competencia'] ?? '');
        $valor = self::centavos($dados['valor'] ?? '');
        $data = (string) ($dados['data'] ?? '') ?: date('Y-m-d');
        $observacao = mb_substr(trim((string) ($dados['observacao'] ?? '')), 0, 250);

        $dias = trim(str_replace(',', '.', (string) ($dados['dias'] ?? '')));
        if ($dias !== '') {
            $passo = (float) $this->config['passo_dias'];
            if (!is_numeric($dias) || (float) $dias < 0 || (float) $dias > 31
                || abs(fmod((float) $dias, $passo)) > 0.0001) {
                $erros[] = 'Dias trabalhados inválidos (de 0 a 31, em passos de meio dia).';
            } else {
                $dias = rtrim(rtrim(number_format((float) $dias, 1, '.', ''), '0'), '.');
            }
        }

        if ($this->voluntario($idVoluntario) === null) {
            $erros[] = 'Voluntário não encontrado.';
            return [];
        }
        if (!self::competenciaValida($competencia)) {
            $erros[] = 'Informe uma competência válida (mês e ano).';
        }
        if (!self::dataValida($data)) {
            $erros[] = 'Data do pagamento inválida.';
        }
        if ($valor === null || $valor <= 0) {
            $erros[] = 'Informe o valor do pagamento.';
            return [];
        }

        // Distribuição informada pelo usuário ou a automática.
        $distribuicao = $dados['distribuicao'] ?? null;
        if (!is_array($distribuicao) || empty($distribuicao)) {
            $sugestao = $this->sugerirDistribuicao($idVoluntario, $valor, $reservado);
            if (self::centavos($sugestao['faltando']) > 0) {
                $erros[] = sprintf('Saldo insuficiente: faltam R$ %s. Cadastre um reforço de empenho.', self::br(self::centavos($sugestao['faltando'])));
                return [];
            }
            $distribuicao = $sugestao['distribuicao'];
        }

        $soma = 0;
        $porEmpenho = [];
        foreach ($distribuicao as $parte) {
            $idEmpenho = (int) ($parte['idEmpenho'] ?? 0);
            $valorParte = self::centavos($parte['valor'] ?? '');
            if ($valorParte === null || $valorParte < 0) {
                $erros[] = 'Valor inválido na distribuição entre empenhos.';
                continue;
            }
            if ($valorParte === 0) {
                continue;
            }
            $linha = $this->linhaEmpenho($idEmpenho);
            if ($linha === null || (int) $linha['ID Voluntario'] !== $idVoluntario) {
                $erros[] = 'A distribuição usa um empenho que não é deste voluntário.';
                continue;
            }
            $porEmpenho[$idEmpenho] = ($porEmpenho[$idEmpenho] ?? 0) + $valorParte;
            $soma += $valorParte;
        }
        if ($soma !== $valor) {
            $erros[] = sprintf('A soma da distribuição (R$ %s) é diferente do valor do pagamento (R$ %s).', self::br($soma), self::br($valor));
        }

        $movimentos = [];
        foreach ($porEmpenho as $idEmpenho => $valorParte) {
            $linha = $this->linhaEmpenho($idEmpenho);
            $saldo = $this->saldoEmpenho($linha) - ($reservado[$idEmpenho] ?? 0);
            if ($valorParte > $saldo) {
                $erros[] = sprintf('O valor do empenho %s (R$ %s) é maior que o saldo dele (R$ %s).',
                    $linha['Numero Empenho'], self::br($valorParte), self::br(max(0, $saldo)));
            }
            if (self::competenciaValida($competencia)) {
                foreach ($this->movimentosDoEmpenho($idEmpenho) as $m) {
                    if ($m['Tipo'] === self::PAGAMENTO && $m['Competencia'] === $competencia) {
                        $avisos[] = sprintf('Já existe pagamento de %s pelo empenho %s (R$ %s).',
                            self::competenciaBR($competencia), $linha['Numero Empenho'], self::br((int) self::centavos($m['Valor'])));
                        break;
                    }
                }
            }
            $movimentos[] = $this->novoMovimento($linha, self::PAGAMENTO, $competencia, $valorParte, (string) $dias, $data, $observacao);
        }
        return $movimentos;
    }

    private function exigirValido(array $erros, array $avisos, bool $confirmar): void
    {
        if (!empty($erros)) {
            throw new EmpenhoValidacaoException(array_values(array_unique($erros)));
        }
        if (!empty($avisos) && !$confirmar) {
            throw new EmpenhoValidacaoException([], array_values(array_unique($avisos)));
        }
    }

    // ==================================================================
    // Gravação
    // ==================================================================

    private function novoMovimento(array $empenho, string $tipo, string $competencia, int $valor, string $dias, string $data, string $observacao): array
    {
        return [
            'ID Empenho' => (string) $empenho['ID'],
            'ID Voluntario' => (string) $empenho['ID Voluntario'],
            'Tipo' => $tipo,
            'Competencia' => $competencia,
            'Valor' => self::reais($valor),
            'Dias Trabalhados' => $dias,
            'Data' => $data,
            'Observacao' => $observacao,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function gravarMovimentos(array $movimentos): array
    {
        // As tabelas precisam existir ANTES da transação (CREATE TABLE fecha a transação no MySQL).
        $this->movimentos();
        $gravados = $this->dados->emTransacao(function () use ($movimentos) {
            $gravados = [];
            foreach ($movimentos as $m) {
                $m['Data Cadastro'] = date('Y-m-d H:i:s');
                $gravados[] = $this->dados->inserir('movimentos_empenho', $m);
            }
            return $gravados;
        });
        $this->limparCache();
        return array_map([$this, 'movimentoParaSaida'], $gravados);
    }

    // ==================================================================
    // Leitura e cálculos internos
    // ==================================================================

    private function empenhos(): array
    {
        return $this->cacheEmpenhos ??= $this->dados->listar('empenhos');
    }

    private function movimentos(): array
    {
        return $this->cacheMovimentos ??= $this->dados->listar('movimentos_empenho');
    }

    /**
     * Empenhos do mais antigo ao mais novo: ano, data do empenho (sem ela, a
     * data em que foi cadastrado) e ID.
     */
    private function empenhosOrdenados(): array
    {
        $lista = $this->empenhos();
        $data = fn (array $e) => (string) $e['Data Empenho'] ?: substr((string) $e['Data Cadastro'], 0, 10);
        usort($lista, fn ($a, $b) => [(int) $a['Ano'], $data($a), (int) $a['ID']]
            <=> [(int) $b['Ano'], $data($b), (int) $b['ID']]);
        return $lista;
    }

    private function linhaEmpenho(int $id): ?array
    {
        foreach ($this->empenhos() as $e) {
            if ((int) $e['ID'] === $id) {
                return $e;
            }
        }
        return null;
    }

    private function movimentosDoEmpenho(int $idEmpenho): array
    {
        return array_values(array_filter($this->movimentos(), fn ($m) => (int) $m['ID Empenho'] === $idEmpenho));
    }

    /** @return array{pago:int, anulado:int} */
    private function somaMovimentos(int $idEmpenho): array
    {
        $soma = ['pago' => 0, 'anulado' => 0];
        foreach ($this->movimentosDoEmpenho($idEmpenho) as $m) {
            $soma[$m['Tipo'] === self::ANULACAO ? 'anulado' : 'pago'] += (int) self::centavos($m['Valor']);
        }
        return $soma;
    }

    private function saldoEmpenho(array $linha): int
    {
        $soma = $this->somaMovimentos((int) $linha['ID']);
        return (int) self::centavos($linha['Valor']) - $soma['pago'] - $soma['anulado'];
    }

    private function saldoVoluntario(int $idVoluntario): int
    {
        $total = 0;
        foreach ($this->empenhos() as $e) {
            if ((int) $e['ID Voluntario'] === $idVoluntario) {
                $total += $this->saldoEmpenho($e);
            }
        }
        return $total;
    }

    private function pagoNaCompetencia(int $idVoluntario, string $competencia): int
    {
        $total = 0;
        foreach ($this->movimentos() as $m) {
            if ((int) $m['ID Voluntario'] === $idVoluntario && $m['Tipo'] === self::PAGAMENTO && $m['Competencia'] === $competencia) {
                $total += (int) self::centavos($m['Valor']);
            }
        }
        return $total;
    }

    /**
     * ANULADO        -> já anulado: sem saldo, com anulação e voluntário fora (inativo/contrato encerrado)
     * SALDO_A_ANULAR -> voluntário fora que ainda tem saldo
     * ''             -> normal
     */
    private function situacao(int $idVoluntario, int $saldo, int $anulado): string
    {
        $voluntario = $this->voluntario($idVoluntario);
        $contrato = $this->contratosAtuais()[$idVoluntario] ?? null;
        $fora = $voluntario === null || self::naoAtivo($voluntario) || $contrato === null
            || ContratoService::calcularStatus((string) $contrato['Data Inicio'], (string) $contrato['Data Termino']) === 'ENCERRADO';
        if ($fora && $saldo > 0) {
            return 'SALDO_A_ANULAR';
        }
        if ($anulado > 0 && $saldo === 0 && $fora) {
            return 'ANULADO';
        }
        return '';
    }

    private function voluntario(int $id): ?array
    {
        if ($this->cacheVoluntarios === null) {
            $this->cacheVoluntarios = [];
            foreach ($this->dados->getVoluntarios() as $v) {
                $this->cacheVoluntarios[(int) $v['ID']] = $v;
            }
        }
        return $this->cacheVoluntarios[$id] ?? null;
    }

    /** @return array<int, array<string, mixed>> contrato mais recente de cada voluntário. */
    private function contratosAtuais(): array
    {
        if ($this->cacheContratoAtual === null) {
            $this->cacheContratoAtual = [];
            foreach ($this->dados->getContratos() as $c) {
                $idVol = (int) $c['ID Voluntario'];
                if (!isset($this->cacheContratoAtual[$idVol]) || (int) $c['ID'] > (int) $this->cacheContratoAtual[$idVol]['ID']) {
                    $this->cacheContratoAtual[$idVol] = $c;
                }
            }
        }
        return $this->cacheContratoAtual;
    }

    private function limparCache(): void
    {
        $this->cacheEmpenhos = null;
        $this->cacheMovimentos = null;
    }

    private function empenhoParaSaida(array $linha): array
    {
        $soma = $this->somaMovimentos((int) $linha['ID']);
        $valor = (int) self::centavos($linha['Valor']);
        return [
            'id' => (int) $linha['ID'],
            'idVoluntario' => (int) $linha['ID Voluntario'],
            'idContrato' => (int) $linha['ID Contrato'],
            'ano' => (int) $linha['Ano'],
            'numero' => (string) $linha['Numero Empenho'],
            'dataEmpenho' => (string) $linha['Data Empenho'],
            'valor' => self::reais($valor),
            'tipo' => (string) $linha['Tipo'],
            'lotacao' => (string) $linha['Lotacao'],
            'fonteRecurso' => (string) $linha['Fonte Recurso'],
            'observacao' => (string) $linha['Observacao'],
            'dataCadastro' => (string) $linha['Data Cadastro'],
            'pago' => self::reais($soma['pago']),
            'anulado' => self::reais($soma['anulado']),
            'saldo' => self::reais($valor - $soma['pago'] - $soma['anulado']),
        ];
    }

    private function movimentoParaSaida(array $m): array
    {
        return [
            'id' => (int) $m['ID'],
            'idEmpenho' => (int) $m['ID Empenho'],
            'idVoluntario' => (int) $m['ID Voluntario'],
            'tipo' => (string) $m['Tipo'],
            'competencia' => (string) $m['Competencia'],
            'valor' => self::reais((int) self::centavos($m['Valor'])),
            'dias' => (string) $m['Dias Trabalhados'],
            'data' => (string) $m['Data'],
            'observacao' => (string) $m['Observacao'],
            'dataCadastro' => (string) $m['Data Cadastro'],
        ];
    }

    // ==================================================================
    // Utilitários
    // ==================================================================

    /** Chave para comparar nomes/lotações/números: minúsculas, sem acento e com espaços simples. */
    public static function chave(string $texto): string
    {
        $texto = str_replace("\xc2\xa0", ' ', $texto);
        $semAcento = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($texto), 'UTF-8'));
        return (string) preg_replace('/\s+/', ' ', trim((string) ($semAcento === false ? $texto : $semAcento)));
    }

    public static function competenciaValida(string $competencia): bool
    {
        return (bool) preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $competencia);
    }

    private static function dataValida(string $data): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $data, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function naoAtivo(array $voluntario): bool
    {
        return strtoupper(trim((string) ($voluntario['Nao Ativo'] ?? ''))) === 'TRUE';
    }

    /** 110250 -> "1.102,50" (mensagens). */
    public static function br(int $centavos): string
    {
        return number_format($centavos / 100, 2, ',', '.');
    }

    private static function competenciaBR(string $competencia): string
    {
        [$ano, $mes] = explode('-', $competencia);
        return "{$mes}/{$ano}";
    }
}
