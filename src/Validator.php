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
        'secretaria' => 'Secretaria',
        'nomeSecretario' => 'Nome do Secretário',
    ];

    /**
     * Valida os dados de um voluntário.
     * @return string[] Lista de mensagens de erro (vazia quando válido).
     */
    /**
     * @param int $mesesMaximos duração máxima do contrato (6 meses; 12 para
     *                          contrato renovado, que já foi prorrogado uma vez).
     */
    public static function validarVoluntario(array $dados, int $mesesMaximos = 6): array
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

        if (!empty($dados['cpf']) && !self::validarCPF((string) $dados['cpf'])) {
            $erros[] = 'Informe um CPF válido.';
        }

        if (!empty($dados['contato']) && !in_array(strlen((string) preg_replace('/\D/', '', (string) $dados['contato'])), [10, 11], true)) {
            $erros[] = 'Informe um contato válido, com DDD.';
        }

        if (!empty($dados['cep']) && preg_replace('/\D/', '', (string) $dados['cep']) === '') {
            $erros[] = 'Informe um CEP válido.';
        } elseif (!empty($dados['cep']) && strlen(preg_replace('/\D/', '', (string) $dados['cep'])) !== 8) {
            $erros[] = 'Informe um CEP válido.';
        }

        if (!empty($dados['dataInicio']) && !empty($dados['dataTermino'])) {
            if ($dados['dataTermino'] < $dados['dataInicio']) {
                $erros[] = 'A data de término deve ser posterior à data de início.';
            } elseif (self::diferencaEmMeses($dados['dataInicio'], $dados['dataTermino']) > $mesesMaximos) {
                $erros[] = "O período do contrato não pode ultrapassar {$mesesMaximos} meses.";
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

    /**
     * Padroniza nomes de pessoas: "adriana lima  DA silva" -> "Adriana Lima da Silva".
     * Partículas (da, de, do, das, dos, e) ficam em minúsculas, exceto no início.
     */
    public static function formatarNome(string $nome): string
    {
        $nome = trim((string) preg_replace('/\s+/u', ' ', $nome));
        if ($nome === '') {
            return '';
        }
        $particulas = ['da', 'de', 'do', 'das', 'dos', 'e', 'di', 'du'];
        $palavras = explode(' ', mb_convert_case($nome, MB_CASE_TITLE, 'UTF-8'));
        foreach ($palavras as $i => $palavra) {
            $minuscula = mb_strtolower($palavra, 'UTF-8');
            if ($i > 0 && in_array($minuscula, $particulas, true)) {
                $palavras[$i] = $minuscula;
            }
        }
        return implode(' ', $palavras);
    }
}
