/**
 * cadastro.js
 * Lógica do formulário de cadastro/edição de voluntários.
 * Responsável por: preview de foto (Base64), máscaras de CPF/CEP,
 * cálculo automático de idade, validações, carregamento das listas
 * dinâmicas (Cargos/Secretarias/Locais/Secretarios) e persistência via
 * API (PHP + Google Sheets).
 */

let FOTO_BASE64 = '';
let ID_EDICAO = null;

document.addEventListener('DOMContentLoaded', async () => {
  const params = new URLSearchParams(location.search);
  ID_EDICAO = params.get('id');

  configurarMascaras();
  configurarCalculoIdade();
  configurarUploadFoto();
  configurarSecretariaOutra();
  configurarNomeSecretarioOutra();
  configurarLocalPrestacaoOutra();

  await carregarListasDinamicas();

  if (ID_EDICAO) {
    carregarParaEdicao(ID_EDICAO);
  }

  document.getElementById('form-voluntario').addEventListener('submit', aoSubmeter);
});

/* ------------------------------------------------------------------ */
/* Listas dinâmicas (Cargos, Secretarias, Locais, Secretarios)          */
/* Carregadas do Google Sheets via api/*.php e usadas para popular os   */
/* campos de seleção. Em caso de falha (ex.: credenciais do Google      */
/* Sheets ainda não configuradas), mantém as opções já existentes no    */
/* HTML como alternativa, para o formulário continuar utilizável.       */
/* ------------------------------------------------------------------ */
async function carregarListasDinamicas() {
  await Promise.all([
    popularSelect('cargo', 'api/cargos.php?ativos=1', item => ({ valor: item.nome, texto: item.nome })),
    popularSelectComOutra('secretaria', 'api/secretarias.php?ativos=1', item => ({ valor: item.nome, texto: item.nome })),
    popularSelectComOutra('nomeSecretario', 'api/secretarios.php?ativos=1', item => ({ valor: item.nome, texto: item.nome })),
    popularSelectComOutra('localPrestacao', 'api/locais.php?ativos=1', item => ({ valor: item.nome, texto: item.nome })),
  ]);
}

/** Preenche um <select> simples (sem opção "outra") com os itens vindos da API. */
async function popularSelect(idSelect, endpoint, mapear) {
  const select = document.getElementById(idSelect);
  if (!select) return;
  try {
    const resposta = await apiFetch(endpoint);
    const valorAtual = select.value;
    const existentes = new Set(Array.from(select.options).map(o => o.value));
    (resposta.dados || []).forEach(item => {
      const { valor, texto } = mapear(item);
      if (existentes.has(valor)) return;
      const opt = document.createElement('option');
      opt.value = valor;
      opt.textContent = texto;
      select.appendChild(opt);
    });
    if (valorAtual) select.value = valorAtual;
  } catch (erro) {
    // Sem conexão com o Google Sheets: mantém as opções fixas do HTML.
  }
}

/** Preenche um <select> que possui a opção "Outra (especificar)" por último. */
async function popularSelectComOutra(idSelect, endpoint, mapear) {
  const select = document.getElementById(idSelect);
  if (!select) return;
  try {
    const resposta = await apiFetch(endpoint);
    const valorAtual = select.value;
    const opcaoOutra = select.querySelector('option[value="__outra"]');
    const existentes = new Set(Array.from(select.options).map(o => o.value));
    (resposta.dados || []).forEach(item => {
      const { valor, texto } = mapear(item);
      if (existentes.has(valor)) return;
      const opt = document.createElement('option');
      opt.value = valor;
      opt.textContent = texto;
      select.insertBefore(opt, opcaoOutra);
    });
    if (valorAtual) select.value = valorAtual;
  } catch (erro) {
    // Sem conexão com o Google Sheets: mantém as opções fixas do HTML.
  }
}

/* ------------------------------------------------------------------ */
/* Modo edição: ajusta títulos e preenche o formulário                 */
/* ------------------------------------------------------------------ */
async function carregarParaEdicao(id) {
  const voluntario = await buscarVoluntario(id);
  if (!voluntario) {
    showToast('Voluntário não encontrado para edição.', 'erro');
    setTimeout(() => { location.href = 'voluntarios.html'; }, 800);
    return;
  }

  document.getElementById('titulo-pagina').textContent = `Editar ${voluntario.nome} — Sistema de Gestão de Voluntários`;
  document.getElementById('topo-titulo').textContent = 'Editar Voluntário';
  document.getElementById('form-titulo').textContent = 'Editar Voluntário';
  document.querySelector('.page-header__subtitulo').textContent = 'Atualize os dados do voluntário e salve as alterações';
  document.querySelector('button[type="submit"]').innerHTML = `${icon('checkCircle')} Salvar Alterações`;

  const campos = ['nome', 'cpf', 'rg', 'rgOrgaoExpedidor', 'rgDataExpedicao', 'dataNascimento', 'sexo',
    'escolaridade', 'estadoCivil', 'cargaHoraria', 'cargo', 'cep', 'logradouro', 'numero', 'complemento',
    'bairro', 'cidade', 'estado', 'dataInicio', 'dataTermino'];

  campos.forEach(campo => {
    const el = document.getElementById(campo);
    if (el && voluntario[campo] !== undefined && voluntario[campo] !== null) el.value = voluntario[campo];
  });

  // Horários vêm do banco como "HH:MM:SS"; os campos <input type="time"> esperam "HH:MM".
  if (voluntario.horarioInicio) document.getElementById('horarioInicio').value = voluntario.horarioInicio.slice(0, 5);
  if (voluntario.horarioTermino) document.getElementById('horarioTermino').value = voluntario.horarioTermino.slice(0, 5);

  // Dias da semana: marca as caixas correspondentes aos códigos salvos (ex.: "SEG,TER,QUA").
  if (voluntario.diasSemana) {
    const codigos = voluntario.diasSemana.split(',').map(c => c.trim());
    document.querySelectorAll('input[name="diaSemana"]').forEach(cb => {
      cb.checked = codigos.includes(cb.value);
    });
  }

  // Secretaria: pode ser uma opção da lista ou "outra"
  const selectSecretaria = document.getElementById('secretaria');
  const opcaoExisteSecretaria = Array.from(selectSecretaria.options).some(o => o.value === voluntario.secretaria);
  if (opcaoExisteSecretaria) {
    selectSecretaria.value = voluntario.secretaria;
  } else if (voluntario.secretaria) {
    selectSecretaria.value = '__outra';
    document.getElementById('secretariaOutra').style.display = 'block';
    document.getElementById('secretariaOutra').value = voluntario.secretaria;
  }

  // Nome do Secretário: pode ser uma opção da lista ou "outra"
  const selectSecretario = document.getElementById('nomeSecretario');
  const opcaoExisteSecretario = Array.from(selectSecretario.options).some(o => o.value === voluntario.nomeSecretario);
  if (opcaoExisteSecretario) {
    selectSecretario.value = voluntario.nomeSecretario;
  } else if (voluntario.nomeSecretario) {
    selectSecretario.value = '__outra';
    document.getElementById('nomeSecretarioOutra').style.display = 'block';
    document.getElementById('nomeSecretarioOutra').value = voluntario.nomeSecretario;
  }

  // Local de prestação de serviço: pode ser uma opção da lista ou "outra"
  const selectLocal = document.getElementById('localPrestacao');
  const opcaoExisteLocal = Array.from(selectLocal.options).some(o => o.value === voluntario.localPrestacao);
  if (opcaoExisteLocal) {
    selectLocal.value = voluntario.localPrestacao;
  } else if (voluntario.localPrestacao) {
    selectLocal.value = '__outra';
    document.getElementById('localPrestacaoOutra').style.display = 'block';
    document.getElementById('localPrestacaoOutra').value = voluntario.localPrestacao;
  }

  if (voluntario.foto) {
    FOTO_BASE64 = voluntario.foto;
    document.getElementById('preview-foto').innerHTML = `<img src="${voluntario.foto}" alt="Prévia da foto">`;
  }

  atualizarIdade();
}

/* ------------------------------------------------------------------ */
/* Máscaras                                                             */
/* ------------------------------------------------------------------ */
function configurarMascaras() {
  const cpfInput = document.getElementById('cpf');
  cpfInput.addEventListener('input', () => {
    cpfInput.value = maskCPF(cpfInput.value);
  });

  const cepInput = document.getElementById('cep');
  cepInput.addEventListener('input', () => {
    cepInput.value = maskCEP(cepInput.value);
  });
}

/* ------------------------------------------------------------------ */
/* Cálculo automático da idade                                          */
/* ------------------------------------------------------------------ */
function configurarCalculoIdade() {
  document.getElementById('dataNascimento').addEventListener('change', atualizarIdade);
}

function atualizarIdade() {
  const dataNasc = document.getElementById('dataNascimento').value;
  document.getElementById('idade').value = dataNasc ? `${calcularIdade(dataNasc)} anos` : '';
}

/* ------------------------------------------------------------------ */
/* Upload e prévia da foto (conversão para Base64)                      */
/* ------------------------------------------------------------------ */
function configurarUploadFoto() {
  const input = document.getElementById('input-foto');
  input.addEventListener('change', () => {
    const arquivo = input.files[0];
    if (!arquivo) return;

    if (!arquivo.type.startsWith('image/')) {
      showToast('Selecione um arquivo de imagem válido.', 'erro');
      input.value = '';
      return;
    }

    const leitor = new FileReader();
    leitor.onload = (e) => {
      FOTO_BASE64 = e.target.result;
      document.getElementById('preview-foto').innerHTML = `<img src="${FOTO_BASE64}" alt="Prévia da foto">`;
      exibirErroCampo('foto', false);
    };
    leitor.readAsDataURL(arquivo);
  });
}

/* ------------------------------------------------------------------ */
/* Campo "Secretaria": exibe input livre quando "Outra" é selecionado  */
/* ------------------------------------------------------------------ */
function configurarSecretariaOutra() {
  const select = document.getElementById('secretaria');
  const outraInput = document.getElementById('secretariaOutra');
  select.addEventListener('change', () => {
    outraInput.style.display = select.value === '__outra' ? 'block' : 'none';
    if (select.value !== '__outra') outraInput.value = '';
  });
}

function obterSecretaria() {
  const select = document.getElementById('secretaria');
  if (select.value === '__outra') return document.getElementById('secretariaOutra').value.trim();
  return select.value;
}

/* ------------------------------------------------------------------ */
/* Campo "Nome do Secretário": exibe input livre quando "Outra" é selecionado */
/* ------------------------------------------------------------------ */
function configurarNomeSecretarioOutra() {
  const select = document.getElementById('nomeSecretario');
  const outraInput = document.getElementById('nomeSecretarioOutra');
  select.addEventListener('change', () => {
    outraInput.style.display = select.value === '__outra' ? 'block' : 'none';
    if (select.value !== '__outra') outraInput.value = '';
  });
}

function obterNomeSecretario() {
  const select = document.getElementById('nomeSecretario');
  if (select.value === '__outra') return document.getElementById('nomeSecretarioOutra').value.trim();
  return select.value;
}

/* ------------------------------------------------------------------ */
/* Campo "Local de prestação de serviço": exibe input livre quando "Outro" é selecionado */
/* ------------------------------------------------------------------ */
function configurarLocalPrestacaoOutra() {
  const select = document.getElementById('localPrestacao');
  const outraInput = document.getElementById('localPrestacaoOutra');
  select.addEventListener('change', () => {
    outraInput.style.display = select.value === '__outra' ? 'block' : 'none';
    if (select.value !== '__outra') outraInput.value = '';
  });
}

function obterLocalPrestacao() {
  const select = document.getElementById('localPrestacao');
  if (select.value === '__outra') return document.getElementById('localPrestacaoOutra').value.trim();
  return select.value;
}

function obterDiasSemanaSelecionados() {
  return Array.from(document.querySelectorAll('input[name="diaSemana"]:checked')).map(cb => cb.value);
}

/* ------------------------------------------------------------------ */
/* Validação e exibição de erros                                        */
/* ------------------------------------------------------------------ */
function exibirErroCampo(campo, temErro) {
  const inputEl = document.getElementById(campo);
  const erroEl = document.getElementById(`erro-${campo}`);
  if (inputEl) inputEl.classList.toggle('campo-invalido', !!temErro);
  if (erroEl) erroEl.classList.toggle('campo-erro--visivel', !!temErro);
}

function validarFormulario() {
  let valido = true;
  const marcar = (campo, condicaoInvalida) => {
    exibirErroCampo(campo, condicaoInvalida);
    if (condicaoInvalida) valido = false;
  };

  const nome = document.getElementById('nome').value.trim();
  const cpf = document.getElementById('cpf').value.trim();
  const rg = document.getElementById('rg').value.trim();
  const rgOrgaoExpedidor = document.getElementById('rgOrgaoExpedidor').value.trim();
  const rgDataExpedicao = document.getElementById('rgDataExpedicao').value;
  const dataNascimento = document.getElementById('dataNascimento').value;
  const sexo = document.getElementById('sexo').value;
  const escolaridade = document.getElementById('escolaridade').value;
  const estadoCivil = document.getElementById('estadoCivil').value;

  const cargaHoraria = document.getElementById('cargaHoraria').value;
  const cargo = document.getElementById('cargo').value.trim();
  const cep = document.getElementById('cep').value.trim();
  const logradouro = document.getElementById('logradouro').value.trim();
  const numero = document.getElementById('numero').value.trim();
  const bairro = document.getElementById('bairro').value.trim();
  const cidade = document.getElementById('cidade').value.trim();
  const estado = document.getElementById('estado').value;
  const localPrestacao = obterLocalPrestacao();
  const dataInicio = document.getElementById('dataInicio').value;
  const dataTermino = document.getElementById('dataTermino').value;
  const horarioInicio = document.getElementById('horarioInicio').value;
  const horarioTermino = document.getElementById('horarioTermino').value;
  const diasSemana = obterDiasSemanaSelecionados();
  const secretaria = obterSecretaria();
  const nomeSecretario = obterNomeSecretario();

  // Foto: obrigatória apenas em novos cadastros (na edição já existe uma foto salva)
  marcar('foto', !FOTO_BASE64);

  marcar('nome', nome.length === 0);
  marcar('cpf', !validarCPF(cpf));
  marcar('rg', rg.length === 0);
  marcar('rgOrgaoExpedidor', rgOrgaoExpedidor.length === 0);
  marcar('rgDataExpedicao', !rgDataExpedicao || (dataNascimento && rgDataExpedicao < dataNascimento));
  marcar('dataNascimento', !dataNascimento);
  marcar('sexo', !sexo);
  marcar('escolaridade', !escolaridade);
  marcar('estadoCivil', !estadoCivil);

  marcar('cargaHoraria', !cargaHoraria);
  marcar('cargo', cargo.length === 0);
  marcar('cep', cep.replace(/\D/g, '').length !== 8);
  marcar('logradouro', logradouro.length === 0);
  marcar('numero', numero.length === 0);
  marcar('bairro', bairro.length === 0);
  marcar('cidade', cidade.length === 0);
  marcar('estado', !estado);
  marcar('localPrestacao', localPrestacao.length === 0);
  marcar('dataInicio', !dataInicio);

  const datasValidas = dataInicio && dataTermino && dataTermino > dataInicio;
  const dentroDoPrazoMaximo = dataInicio && dataTermino && diferencaEmMeses(dataInicio, dataTermino) <= 6;
  marcar('dataTermino', !dataTermino || !datasValidas || !dentroDoPrazoMaximo);

  marcar('horarioInicio', !horarioInicio);
  const horariosValidos = horarioInicio && horarioTermino && horarioTermino > horarioInicio;
  marcar('horarioTermino', !horarioTermino || !horariosValidos);

  marcar('diasSemana', diasSemana.length === 0);

  marcar('secretaria', !secretaria);
  marcar('nomeSecretario', nomeSecretario.length === 0);

  return valido;
}

/** Diferença aproximada em meses entre duas datas "YYYY-MM-DD". */
function diferencaEmMeses(dataInicioISO, dataTerminoISO) {
  const inicio = new Date(dataInicioISO + 'T00:00:00');
  const termino = new Date(dataTerminoISO + 'T00:00:00');
  return (termino.getFullYear() - inicio.getFullYear()) * 12 + (termino.getMonth() - inicio.getMonth()) +
    ((termino.getDate() - inicio.getDate()) > 0 ? (termino.getDate() - inicio.getDate()) / 30 : 0);
}

/* ------------------------------------------------------------------ */
/* Envio do formulário                                                  */
/* ------------------------------------------------------------------ */
async function aoSubmeter(evento) {
  evento.preventDefault();

  if (!validarFormulario()) {
    showToast('Verifique os campos destacados em vermelho antes de salvar.', 'erro');
    const primeiroInvalido = document.querySelector('.campo-invalido');
    if (primeiroInvalido) primeiroInvalido.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return;
  }

  const dados = {
    foto: FOTO_BASE64,
    nome: document.getElementById('nome').value.trim(),
    cpf: document.getElementById('cpf').value.trim(),
    rg: document.getElementById('rg').value.trim(),
    rgOrgaoExpedidor: document.getElementById('rgOrgaoExpedidor').value.trim(),
    rgDataExpedicao: document.getElementById('rgDataExpedicao').value,
    dataNascimento: document.getElementById('dataNascimento').value,
    sexo: document.getElementById('sexo').value,
    escolaridade: document.getElementById('escolaridade').value,
    estadoCivil: document.getElementById('estadoCivil').value,

    cargaHoraria: document.getElementById('cargaHoraria').value,
    cargo: document.getElementById('cargo').value.trim(),
    cep: document.getElementById('cep').value.trim(),
    logradouro: document.getElementById('logradouro').value.trim(),
    numero: document.getElementById('numero').value.trim(),
    complemento: document.getElementById('complemento').value.trim(),
    bairro: document.getElementById('bairro').value.trim(),
    cidade: document.getElementById('cidade').value.trim(),
    estado: document.getElementById('estado').value,
    localPrestacao: obterLocalPrestacao(),
    dataInicio: document.getElementById('dataInicio').value,
    dataTermino: document.getElementById('dataTermino').value,
    horarioInicio: document.getElementById('horarioInicio').value,
    horarioTermino: document.getElementById('horarioTermino').value,
    diasSemana: obterDiasSemanaSelecionados(),
    secretaria: obterSecretaria(),
    nomeSecretario: obterNomeSecretario(),
  };

  const botaoSubmeter = document.querySelector('button[type="submit"]');
  const textoOriginalBotao = botaoSubmeter.innerHTML;
  botaoSubmeter.disabled = true;
  botaoSubmeter.innerHTML = `${icon('clock')} Salvando...`;

  try {
    if (ID_EDICAO) {
      await atualizarVoluntario(ID_EDICAO, dados);
      setFlashMessage('Voluntário atualizado com sucesso!', 'sucesso');
    } else {
      await criarVoluntario(dados);
      setFlashMessage('Voluntário cadastrado com sucesso!', 'sucesso');
    }
    location.href = 'voluntarios.html';
  } catch (erro) {
    const detalhes = (erro.erros && erro.erros.length) ? ' ' + erro.erros.join(' ') : '';
    showToast((erro.message || 'Não foi possível salvar o voluntário.') + detalhes, 'erro');
    botaoSubmeter.disabled = false;
    botaoSubmeter.innerHTML = textoOriginalBotao;
  }
}
