<?php
/**
 * services/ContratoService.php
 * Regras de negócio para a aba "Contratos" do Google Sheets.
 *
 * Cada linha representa um contrato de prestação de serviço voluntário
 * vinculado a um voluntário (coluna "ID Voluntario"). Hoje a interface
 * trabalha com um único contrato "vigente" por voluntário (o mais
 * recente), mas o modelo já suporta histórico de múltiplos contratos
 * por voluntário, caso o sistema evolua nesse sentido no futuro.
 *
 * O "Status" é sempre recalculado a partir das datas (nunca é lido como
 * verdade absoluta) — a coluna na planilha existe apenas para que quem
 * abrir a planilha diretamente consiga ler o status sem precisar do
 * sistema (ver especificação, seção 12).
 */

declare(strict_types=1);

class ContratoService
{
    /** camelCase (front-end) => cabeçalho da coluna na planilha "Contratos". */
    private const MAPA_COLUNAS = [
        'cargaHoraria' => 'Carga Horaria',
        'cargo' => 'Cargo',
        'localPrestacao' => 'Local Prestacao',
        'dataInicio' => 'Data Inicio',
        'dataTermino' => 'Data Termino',
        'horarioInicio' => 'Horario Inicio',
        'horarioTermino' => 'Horario Termino',
        'diasSemana' => 'Dias Semana',
        'secretaria' => 'Secretaria',
        'nomeSecretario' => 'Nome Secretario',
    ];

    private RepositorioDados $dados;

    public function __construct(?RepositorioDados $dados = null)
    {
        $this->dados = $dados ?? repositorioDados();
    }

    /** @return array<int, array<string, mixed>> histórico de contratos do voluntário, mais recente primeiro. */
    public function listarPorVoluntario(int $idVoluntario): array
    {
        $linhas = array_map([$this, 'paraCamelCase'], $this->dados->getContratos($idVoluntario));
        usort($linhas, fn (array $a, array $b) => $b['id'] <=> $a['id']);
        return $linhas;
    }

    /** Contrato vigente (o mais recente) usado para compor a visão consolidada do voluntário. */
    public function buscarAtualPorVoluntario(int $idVoluntario): ?array
    {
        return $this->listarPorVoluntario($idVoluntario)[0] ?? null;
    }

    /** @return array<int, array<string, mixed>> todos os contratos de todos os voluntários (uso administrativo/relatórios). */
    public function listarTodos(): array
    {
        $linhas = array_map([$this, 'paraCamelCase'], $this->dados->getContratos());
        usort($linhas, fn (array $a, array $b) => $b['id'] <=> $a['id']);
        return $linhas;
    }

    public function criar(int $idVoluntario, array $dados): array
    {
        $linha = $this->paraColunas($dados);
        $linha['ID Voluntario'] = (string) $idVoluntario;
        $linha['Status'] = self::calcularStatus((string) ($dados['dataInicio'] ?? ''), (string) ($dados['dataTermino'] ?? ''));
        $linha['Data Cadastro'] = date('Y-m-d H:i:s');

        return $this->paraCamelCase($this->dados->createContrato($linha));
    }

    /** Atualiza o contrato vigente do voluntário. Nunca cria um novo registro numa edição. */
    public function atualizar(int $idVoluntario, array $dados): array
    {
        $atual = $this->buscarLinhaAtual($idVoluntario);
        if ($atual === null) {
            return $this->criar($idVoluntario, $dados);
        }

        $linha = $this->paraColunas($dados);
        $linha['ID'] = (string) $atual['ID'];
        $linha['ID Voluntario'] = (string) $idVoluntario;
        $linha['Status'] = self::calcularStatus(
            (string) ($dados['dataInicio'] ?? ($atual['Data Inicio'] ?? '')),
            (string) ($dados['dataTermino'] ?? ($atual['Data Termino'] ?? ''))
        );
        $linha['Data Cadastro'] = $atual['Data Cadastro'] ?? date('Y-m-d H:i:s');

        $this->dados->updateContrato((int) $atual['ID'], $linha);
        return $this->paraCamelCase($linha);
    }

    /** Remove todos os contratos vinculados a um voluntário (usado ao excluir o voluntário). */
    public function excluirPorVoluntario(int $idVoluntario): void
    {
        foreach ($this->dados->getContratos($idVoluntario) as $linha) {
            $this->dados->deleteContrato((int) $linha['ID']);
        }
    }

    /** Status automático conforme a especificação (seção 12): A_INICIAR, ATIVO ou ENCERRADO. */
    public static function calcularStatus(string $dataInicio, string $dataTermino): string
    {
        $hoje = date('Y-m-d');
        if ($dataInicio !== '' && $hoje < $dataInicio) {
            return 'A_INICIAR';
        }
        if ($dataTermino !== '' && $hoje > $dataTermino) {
            return 'ENCERRADO';
        }
        return 'ATIVO';
    }

    // ------------------------------------------------------------------

    private function buscarLinhaAtual(int $idVoluntario): ?array
    {
        $linhas = $this->dados->getContratos($idVoluntario);
        if (empty($linhas)) {
            return null;
        }
        usort($linhas, fn (array $a, array $b) => ((int) $b['ID']) <=> ((int) $a['ID']));
        return $linhas[0];
    }

    private function paraColunas(array $dados): array
    {
        $linha = [];
        foreach (self::MAPA_COLUNAS as $camel => $coluna) {
            if (!array_key_exists($camel, $dados)) {
                continue;
            }
            $valor = $dados[$camel];
            if ($camel === 'diasSemana' && is_array($valor)) {
                $valor = implode(',', $valor);
            }
            $linha[$coluna] = $valor;
        }
        return $linha;
    }

    private function paraCamelCase(array $linha): array
    {
        $resultado = [
            'id' => (int) ($linha['ID'] ?? 0),
            'idVoluntario' => (int) ($linha['ID Voluntario'] ?? 0),
        ];
        foreach (self::MAPA_COLUNAS as $camel => $coluna) {
            $resultado[$camel] = $linha[$coluna] ?? '';
        }
        // Sempre recalculado: a coluna "Status" da planilha fica desatualizada com o passar dos dias.
        $resultado['status'] = self::calcularStatus((string) $resultado['dataInicio'], (string) $resultado['dataTermino']);
        $resultado['dataCadastro'] = $linha['Data Cadastro'] ?? '';
        return $resultado;
    }
}
