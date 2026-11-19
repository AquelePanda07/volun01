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
    /** Contrato renovado (renovado + terminoAnterior + dataRenovacao): ver validarRenovacao(). */
    public static function validarVoluntario(array $dados): array
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

        // Contrato renovado: o período original já é histórico (muitos vieram da
        // planilha antiga com mais de 6 meses), então só a renovação é validada.
        if (!empty($dados['renovado'])) {
            array_push($erros, ...self::validarRenovacao($dados));
        } elseif (!empty($dados['dataInicio']) && !empty($dados['dataTermino'])) {
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
    /**
     * Datas de um contrato renovado: o término anterior, a data da renovação e o
     * novo término (dataTermino), que vai até 6 meses além do término anterior
     * ou da data da renovação (a mais recente) — as mesmas regras do modal de renovação.
     * @return string[]
     */
    public static function validarRenovacao(array $dados): array
    {
        $anterior = (string) ($dados['terminoAnterior'] ?? '');
        $renovacao = (string) ($dados['dataRenovacao'] ?? '');
        $termino = (string) ($dados['dataTermino'] ?? '');
        $erros = [];
        if (!self::dataValida($anterior)) {
            $erros[] = 'Informe o término do contrato antes da renovação.';
        }
        if (!self::dataValida($renovacao)) {
            $erros[] = 'Informe a data da renovação.';
        }
        if ($erros || !self::dataValida($termino)) {
            return $erros;
        }
        if ($termino <= $anterior) {
            $erros[] = 'O novo término deve ser posterior ao término antes da renovação.';
        } elseif ($termino > self::somarMeses(max($anterior, $renovacao), 6)) {
            $erros[] = 'A renovação não pode prorrogar o contrato por mais de 6 meses.';
        }
        return $erros;
    }

    /** Soma meses a uma data "YYYY-MM-DD" sem transbordar o mês (31/08 + 6 meses = 28/02). */
    public static function somarMeses(string $data, int $meses): string
    {
        $base = new DateTime($data);
        $dia = (int) $base->format('d');
        $resultado = (clone $base)->modify("first day of +{$meses} months");
        $resultado->setDate((int) $resultado->format('Y'), (int) $resultado->format('m'), min($dia, (int) $resultado->format('t')));
        return $resultado->format('Y-m-d');
    }

    private static function dataValida(string $data): bool
    {
        $objeto = DateTime::createFromFormat('!Y-m-d', $data);
        return $objeto !== false && $objeto->format('Y-m-d') === $data;
    }

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
