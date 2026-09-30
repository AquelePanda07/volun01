<?php
/**
 * services/VoluntarioService.php
 * Regras de negócio para a aba "Voluntarios" do Google Sheets: CRUD,
 * conversão de/para o formato usado pelo front-end (camelCase) e
 * armazenamento local das fotos em uploads/voluntarios/.
 *
 * Esta classe NÃO conhece contratos (ver services/ContratoService.php) —
 * quem junta voluntário + contrato em um único objeto "achatado" (como
 * o front-end espera) é o src/VoluntarioRepository.php.
 */

declare(strict_types=1);

class VoluntarioService
{
    /** camelCase (front-end) => cabeçalho da coluna na planilha "Voluntarios". */
    private const MAPA_COLUNAS = [
        'nome' => 'Nome',
        'cpf' => 'CPF',
        'rg' => 'RG',
        'rgOrgaoExpedidor' => 'Orgao Expedidor',
        'rgDataExpedicao' => 'Data Expedicao RG',
        'dataNascimento' => 'Data Nascimento',
        'sexo' => 'Sexo',
        'escolaridade' => 'Escolaridade',
        'estadoCivil' => 'Estado Civil',
        'cep' => 'CEP',
        'logradouro' => 'Logradouro',
        'numero' => 'Numero',
        'complemento' => 'Complemento',
        'bairro' => 'Bairro',
        'cidade' => 'Cidade',
        'estado' => 'Estado',
    ];

    private RepositorioDados $dados;
    private string $dirFotos;

    public function __construct(?RepositorioDados $dados = null)
    {
        $this->dados = $dados ?? repositorioDados();
        $this->dirFotos = __DIR__ . '/../uploads/voluntarios';
        if (!is_dir($this->dirFotos)) {
            mkdir($this->dirFotos, 0775, true);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function listar(): array
    {
        $linhas = array_map([$this, 'paraCamelCase'], $this->dados->getVoluntarios());
        usort($linhas, fn (array $a, array $b) => strcasecmp((string) ($a['nome'] ?? ''), (string) ($b['nome'] ?? '')));
        return $linhas;
    }

    public function buscarPorId(int $id): ?array
    {
        $linha = $this->dados->getVoluntarioById($id);
        return $linha ? $this->paraCamelCase($linha) : null;
    }

    public function existeCpf(string $cpf, ?int $idIgnorar = null): bool
    {
        $cpfNormalizado = preg_replace('/\D/', '', $cpf);
        foreach ($this->dados->getVoluntarios() as $linha) {
            if ($idIgnorar !== null && (int) ($linha['ID'] ?? 0) === $idIgnorar) {
                continue;
            }
            if (preg_replace('/\D/', '', (string) ($linha['CPF'] ?? '')) === $cpfNormalizado) {
                return true;
            }
        }
        return false;
    }

    public function criar(array $dados): array
    {
        $foto = $this->salvarFotoSeNecessario((string) ($dados['nome'] ?? ''), $dados['foto'] ?? null);

        $linha = $this->paraColunas($dados);
        $linha['Foto'] = $foto ?? '';
        $linha['Idade'] = !empty($dados['dataNascimento']) ? (string) Validator::calcularIdade((string) $dados['dataNascimento']) : '';
        $linha['Data Cadastro'] = date('Y-m-d H:i:s');

        return $this->paraCamelCase($this->dados->createVoluntario($linha));
    }

    public function atualizar(int $id, array $dados): array
    {
        $atual = $this->dados->getVoluntarioById($id);
        if ($atual === null) {
            throw new RuntimeException('Voluntário não encontrado.');
        }

        if (!empty($dados['foto']) && str_starts_with((string) $dados['foto'], 'data:')) {
            $dados['foto'] = $this->salvarFotoSeNecessario((string) ($dados['nome'] ?? $atual['Nome'] ?? ''), $dados['foto']);
        } else {
            unset($dados['foto']);
        }

        $linha = $this->paraColunas($dados);
        $linha['ID'] = (string) $id;
        // Mantém foto/data de cadastro existentes quando não enviadas na atualização.
        $linha['Foto'] = $dados['foto'] ?? ($atual['Foto'] ?? '');
        $dataNascimento = $dados['dataNascimento'] ?? ($atual['Data Nascimento'] ?? null);
        $linha['Idade'] = !empty($dataNascimento) ? (string) Validator::calcularIdade((string) $dataNascimento) : ($atual['Idade'] ?? '');
        $linha['Data Cadastro'] = $atual['Data Cadastro'] ?? date('Y-m-d H:i:s');

        $this->dados->updateVoluntario($id, $linha);
        return $this->paraCamelCase($linha);
    }

    public function excluir(int $id): void
    {
        $atual = $this->dados->getVoluntarioById($id);
        if ($atual === null) {
            return;
        }
        $this->dados->deleteVoluntario($id);

        // Remove também a foto local (se houver), para não acumular arquivos órfãos.
        $caminhoFoto = (string) ($atual['Foto'] ?? '');
        if ($caminhoFoto !== '' && str_starts_with($caminhoFoto, 'uploads/voluntarios/')) {
            $caminhoAbsoluto = __DIR__ . '/../' . $caminhoFoto;
            if (is_file($caminhoAbsoluto)) {
                @unlink($caminhoAbsoluto);
            }
        }
    }

    // ------------------------------------------------------------------

    /**
     * Decodifica uma imagem base64 (data URL) e salva em uploads/voluntarios/,
     * com nome no formato "joao_da_silva_<sufixo único>.jpg".
     */
    private function salvarFotoSeNecessario(string $nomeVoluntario, ?string $foto): ?string
    {
        if (!$foto) {
            return null;
        }
        if (!str_starts_with($foto, 'data:')) {
            return $foto; // já é um caminho existente (edição sem nova foto)
        }

        if (!preg_match('/^data:image\/(png|jpe?g|gif|webp);base64,(.+)$/', $foto, $m)) {
            throw new InvalidArgumentException('Formato de imagem inválido para a foto do voluntário.');
        }
        $extensao = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
        $binario = base64_decode($m[2]);
        if ($binario === false) {
            throw new InvalidArgumentException('Não foi possível decodificar a foto enviada.');
        }

        $nomeArquivo = $this->slug($nomeVoluntario) . '_' . uniqid() . '.' . $extensao;
        file_put_contents($this->dirFotos . '/' . $nomeArquivo, $binario);

        return 'uploads/voluntarios/' . $nomeArquivo;
    }

    /** "João da Silva" -> "joao_da_silva" (nome de arquivo seguro). */
    private function slug(string $texto): string
    {
        $texto = strtr(mb_strtolower($texto, 'UTF-8'), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e',
            'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        $texto = trim((string) preg_replace('/[^a-z0-9]+/', '_', $texto), '_');
        return $texto !== '' ? substr($texto, 0, 60) : 'voluntario';
    }

    /** @return array<string, string> [nomeDaColuna => valor] apenas para as colunas mapeadas presentes em $dados. */
    private function paraColunas(array $dados): array
    {
        $linha = [];
        foreach (self::MAPA_COLUNAS as $camel => $coluna) {
            if (!array_key_exists($camel, $dados)) {
                continue;
            }
            $linha[$coluna] = $dados[$camel];
        }
        return $linha;
    }

    /** Converte uma linha da planilha (cabeçalho => valor) para o formato camelCase usado pelo front-end. */
    private function paraCamelCase(array $linha): array
    {
        $resultado = ['id' => (int) ($linha['ID'] ?? 0), 'foto' => $linha['Foto'] ?? ''];
        foreach (self::MAPA_COLUNAS as $camel => $coluna) {
            $resultado[$camel] = $linha[$coluna] ?? '';
        }
        $resultado['dataCadastro'] = $linha['Data Cadastro'] ?? '';
        $resultado['idade'] = !empty($resultado['dataNascimento']) ? Validator::calcularIdade((string) $resultado['dataNascimento']) : null;
        return $resultado;
    }
}
