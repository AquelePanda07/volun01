<?php
/**
 * Validator.php
 * Regras de validação do voluntário, espelhando (no servidor) as mesmas
 * regras aplicadas no front-end, para nunca confiar apenas no cliente.
 */

declare(strict_types=1);

class Validator
{
    /** Campos obrigatórios (chave => rótulo amigável para mensagens de erro). */
    public const CAMPOS_OBRIGATORIOS = [
        'nome' => 'Nome completo',
        'cpf' => 'CPF',
        'rg' => 'RG',
        'rgOrgaoExpedidor' => 'Órgão expedidor do RG',
        'rgDataExpedicao' => 'Data de expedição do RG',
        'dataNascimento' => 'Data de nascimento',
        'sexo' => 'Sexo',
        'escolaridade' => 'Grau de escolaridade',
        'estadoCivil' => 'Estado civil',
        'cep' => 'CEP',
        'logradouro' => 'Logradouro',
        'numero' => 'Número',
        'bairro' => 'Bairro',
        'cidade' => 'Cidade',
        'estado' => 'Estado',
        'cargaHoraria' => 'Carga horária',
        'cargo' => 'Cargo',
        'localPrestacao' => 'Local de prestação de serviço',
        'dataInicio' => 'Data de início',
        'dataTermino' => 'Data de término',
        'horarioInicio' => 'Horário de início',
        'horarioTermino' => 'Horário de término',
        'diasSemana' => 'Dias da semana',
        'secretaria' => 'Secretaria',
        'nomeSecretario' => 'Nome do Secretário',
    ];

    /**
     * Valida os dados de um voluntário.
     * @return string[] Lista de mensagens de erro (vazia quando válido).
     */
    public static function validarVoluntario(array $dados, bool $fotoObrigatoria): array
    {
        $erros = [];

        foreach (self::CAMPOS_OBRIGATORIOS as $campo => $rotulo) {
            $valor = $dados[$campo] ?? null;
            if (is_array($valor)) {
                if (count($valor) === 0) {
                    $erros[] = "Informe: {$rotulo}.";
                }
                continue;
            }
            if ($valor === null || trim((string) $valor) === '') {
                $erros[] = "Informe: {$rotulo}.";
            }
        }

        if ($fotoObrigatoria && empty($dados['foto'])) {
            $erros[] = 'Selecione uma foto para o voluntário.';
        }

        if (!empty($dados['cpf']) && !self::validarCPF((string) $dados['cpf'])) {
            $erros[] = 'Informe um CPF válido.';
        }

        if (!empty($dados['cep']) && preg_replace('/\D/', '', (string) $dados['cep']) === '') {
            $erros[] = 'Informe um CEP válido.';
        } elseif (!empty($dados['cep']) && strlen(preg_replace('/\D/', '', (string) $dados['cep'])) !== 8) {
            $erros[] = 'Informe um CEP válido.';
        }

        if (!empty($dados['dataInicio']) && !empty($dados['dataTermino'])) {
            if ($dados['dataTermino'] < $dados['dataInicio']) {
                $erros[] = 'A data de término deve ser posterior à data de início.';
            } elseif (self::diferencaEmMeses($dados['dataInicio'], $dados['dataTermino']) > 6) {
                $erros[] = 'O período do contrato não pode ultrapassar 6 meses.';
            }
        }

        if (!empty($dados['horarioInicio']) && !empty($dados['horarioTermino'])) {
            if ($dados['horarioTermino'] <= $dados['horarioInicio']) {
                $erros[] = 'O horário de término deve ser posterior ao horário de início.';
            }
        }

        if (!empty($dados['rgDataExpedicao']) && !empty($dados['dataNascimento'])) {
            if ($dados['rgDataExpedicao'] < $dados['dataNascimento']) {
                $erros[] = 'A data de expedição do RG não pode ser anterior à data de nascimento.';
            }
        }

        return $erros;
    }

    public static function validarCPF(string $cpfFormatado): bool
    {
        $cpf = preg_replace('/\D/', '', $cpfFormatado);
        if (strlen($cpf) !== 11) {
            return false;
        }
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $cpf[$i] * (($t + 1) - $i);
            }
            $digito = ((10 * $soma) % 11) % 10;
            if ((int) $cpf[$t] !== $digito) {
                return false;
            }
        }

        return true;
    }

    /** Diferença aproximada em meses entre duas datas no formato Y-m-d. */
    public static function diferencaEmMeses(string $dataInicio, string $dataTermino): float
    {
        $inicio = new DateTime($dataInicio);
        $termino = new DateTime($dataTermino);
        $intervalo = $inicio->diff($termino);
        return ($intervalo->y * 12) + $intervalo->m + ($intervalo->d > 0 ? $intervalo->d / 30 : 0);
    }

    public static function calcularIdade(string $dataNascimento): int
    {
        $nascimento = new DateTime($dataNascimento);
        $hoje = new DateTime('today');
        return (int) $nascimento->diff($hoje)->y;
    }
}
