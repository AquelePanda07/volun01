/**
 * cadastro.js
 * Lógica do formulário de cadastro/edição de voluntários.
 * Responsável por: preview de foto (Base64), máscaras de CPF/CEP,
 * cálculo automático de idade, validações, carregamento das listas
 * dinâmicas (Cargos/Secretarias/Locais/Secretarios) e persistência via
 * API PHP.
 */

// Selects que têm a opção "Outra (especificar)", que mostra um campo de texto livre.
const CAMPOS_COM_OUTRA = ['secretaria', 'nomeSecretario', 'localPrestacao'];

let FOTO_BASE64 = '';
let ID_EDICAO = null;

document.addEventListener('DOMContentLoaded', async () => {
  const params = new URLSearchParams(location.search);
  ID_EDICAO = params.get('id');

  configurarMascaras();
  configurarCalculoIdade();
  configurarUploadFoto();
  configurarPeriodoPadrao();
  CAMPOS_COM_OUTRA.forEach(configurarCampoOutra);

  await carregarListasDinamicas();

  if (ID_EDICAO) {
    carregarParaEdicao(ID_EDICAO);
  }

  document.getElementById('form-voluntario').addEventListener('submit', aoSubmeter);
});

/* ------------------------------------------------------------------ */
/* Listas dinâmicas (Cargos, Secretarias, Locais, Secretarios)          */
/* Carregadas via api/*.php e usadas para popular os campos de seleção. */
/* Em caso de falha, mantém as opções já existentes no HTML como        */
/* alternativa, para o formulário continuar utilizável.                 */
/* ------------------------------------------------------------------ */
async function carregarListasDinamicas() {
  await Promise.all([
    // Caminhos relativos a API_BASE ('api/'), que apiFetch() já acrescenta.
    popularSelect('cargo', 'cargos.php?ativos=1', item => ({ valor: item.nome, texto: item.nome })),
    popularSelectComOutra('secretaria', 'secretarias.php?ativos=1', item => ({ valor: item.nome, texto: item.nome })),
    popularSelectComOutra('nomeSecretario', 'secretarios.php?ativos=1', item => ({ valor: item.nome, texto: item.nome })),
    popularSelectComOutra('localPrestacao', 'locais.php?ativos=1', item => ({ valor: item.nome, texto: item.nome })),
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
    // Sem conexão com a API: mantém as opções fixas do HTML.
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
    // Sem conexão com a API: mantém as opções fixas do HTML.
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

  document.title = `Editar ${voluntario.nome} — Sistema de Gestão de Voluntários`;
  document.getElementById('topo-titulo').textContent = 'Editar Voluntário';
  // Editando, o item ativo do menu é "Voluntários" (e não "Adicionar").
  document.querySelectorAll('.sidebar__link').forEach(link => {
    link.classList.toggle('sidebar__link--ativo', link.getAttribute('href') === 'voluntarios.html');
  });
  document.getElementById('form-titulo').textContent = 'Editar Voluntário';
  document.querySelector('.page-header__subtitulo').textContent = 'Atualize os dados do voluntário e salve as alterações';
  document.querySelector('button[type="submit"]').innerHTML = `${icon('save')} Salvar Alterações`;

  const campos = ['nome', 'cpf', 'rg', 'rgOrgaoExpedidor', 'rgDataExpedicao', 'dataNascimento', 'sexo',
    'escolaridade', 'estadoCivil', 'contato', 'cargaHoraria', 'cargo', 'cep', 'logradouro', 'numero', 'complemento',
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

  // Secretaria, secretário e local: podem ser uma opção da lista ou "outra"
  CAMPOS_COM_OUTRA.forEach(campo => preencherCampoOutra(campo, voluntario[campo]));

  configurarRenovacao(voluntario);

  if (voluntario.foto) {
    FOTO_BASE64 = voluntario.foto;
    document.getElementById('preview-foto').innerHTML = `<img src="${voluntario.foto}" alt="Prévia da foto">`;
  }

  atualizarIdade();
}

/* ------------------------------------------------------------------ */
/* Renovação do contrato (só na edição)                                 */
/* Com o contrato renovado, "Data de término" é o novo término e o      */
/* original fica em "Término antes da renovação".                       */
/* ------------------------------------------------------------------ */
function configurarRenovacao(v) {
  const secao = document.getElementById('secao-renovacao');
  const caixa = document.getElementById('renovado');
  const anterior = document.getElementById('terminoAnterior');
  const dataRenovacao = document.getElementById('dataRenovacao');
  const termino = document.getElementById('dataTermino');
  const rotuloTermino = document.querySelector('label[for="dataTermino"]');
  const erroTermino = document.getElementById('erro-dataTermino');
  const erroTerminoOriginal = erroTermino.textContent;
  let terminoRenovado = v.renovado ? termino.value : ''; // para voltar a ele se desmarcar e marcar de novo

  secao.hidden = false;
  caixa.checked = Boolean(v.renovado);
  anterior.value = v.terminoAnterior || '';
  dataRenovacao.value = v.dataRenovacao || '';

  const atualizar = () => {
    const ligado = caixa.checked;
    secao.querySelectorAll('[data-campo-renovacao]').forEach(campo => { campo.hidden = !ligado; });
    rotuloTermino.innerHTML = `${ligado ? 'Novo término (após a renovação)' : 'Data de término'} <span class="obrigatorio">*</span>`;
    erroTermino.textContent = ligado
      ? 'O novo término deve ser depois do término anterior e no máximo 6 meses após ele (ou após a data da renovação, se for mais recente).'
      : erroTerminoOriginal;
    document.getElementById('renovacao-dica').textContent = ligado
      ? 'A "Data de término" em Período e Horário passa a ser o novo término.'
      : (v.renovado
        ? 'Ao salvar, a renovação será desfeita e o contrato volta ao término anterior.'
        : 'Marque se este contrato já foi renovado (por exemplo, numa renovação feita fora do sistema).');
  };

  caixa.addEventListener('change', () => {
    if (caixa.checked) {
      if (!anterior.value) anterior.value = termino.value;
      if (!dataRenovacao.value) dataRenovacao.value = dataParaISO(new Date());
      if (!termino.value || termino.value <= anterior.value) {
        termino.value = terminoRenovado && terminoRenovado > anterior.value
          ? terminoRenovado
          : somarMesesISO([anterior.value, dataRenovacao.value].sort().pop(), 6);
      }
    } else if (anterior.value) {
      terminoRenovado = termino.value;
      termino.value = anterior.value;
    }
    atualizar();
  });
  atualizar();
}

function renovacaoMarcada() {
  return Boolean(ID_EDICAO) && document.getElementById('renovado').checked;
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

  const contatoInput = document.getElementById('contato');
  contatoInput.addEventListener('input', () => {
    contatoInput.value = maskTelefone(contatoInput.value);
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
    };
    leitor.readAsDataURL(arquivo);
  });
}

/* ------------------------------------------------------------------ */
/* Botão "Hoje + 6 meses" (prazo máximo do contrato)                    */
/* ------------------------------------------------------------------ */
function configurarPeriodoPadrao() {
  document.getElementById('preencherPeriodo').addEventListener('click', () => {
    const hoje = new Date();
    const termino = new Date(hoje);
    termino.setMonth(termino.getMonth() + 6);
    const formato = data => `${data.getFullYear()}-${String(data.getMonth() + 1).padStart(2, '0')}-${String(data.getDate()).padStart(2, '0')}`;
    document.getElementById('dataInicio').value = formato(hoje);
    document.getElementById('dataTermino').value = formato(termino);
  });
}

/* ------------------------------------------------------------------ */
/* Selects com "Outra (especificar)": exibe um input livre             */
/* ------------------------------------------------------------------ */
function configurarCampoOutra(campo) {
  const select = document.getElementById(campo);
  const outraInput = document.getElementById(`${campo}Outra`);
  select.addEventListener('change', () => {
    outraInput.hidden = select.value !== '__outra';
    if (select.value !== '__outra') outraInput.value = '';
    else outraInput.focus();
  });
}

/** Seleciona o valor salvo; se não estiver na lista, usa "outra" com o texto livre. */
function preencherCampoOutra(campo, valor) {
  const select = document.getElementById(campo);
  if (Array.from(select.options).some(o => o.value === valor)) {
    select.value = valor;
  } else if (valor) {
    select.value = '__outra';
    const outraInput = document.getElementById(`${campo}Outra`);
    outraInput.hidden = false;
    outraInput.value = valor;
  }
}

function obterValorComOutra(campo) {
  const select = document.getElementById(campo);
  if (select.value === '__outra') return document.getElementById(`${campo}Outra`).value.trim();
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
  const rgDataExpedicao = document.getElementById('rgDataExpedicao').value;
  const dataNascimento = document.getElementById('dataNascimento').value;
  const sexo = document.getElementById('sexo').value;
  const escolaridade = document.getElementById('escolaridade').value;
  const estadoCivil = document.getElementById('estadoCivil').value;
  const contato = document.getElementById('contato').value.replace(/\D/g, '');

  const cargaHoraria = document.getElementById('cargaHoraria').value;
  const cargo = document.getElementById('cargo').value.trim();
  const cep = document.getElementById('cep').value.trim();
  const logradouro = document.getElementById('logradouro').value.trim();
  const numero = document.getElementById('numero').value.trim();
  const bairro = document.getElementById('bairro').value.trim();
  const cidade = document.getElementById('cidade').value.trim();
  const estado = document.getElementById('estado').value;
  const localPrestacao = obterValorComOutra('localPrestacao');
  const dataInicio = document.getElementById('dataInicio').value;
  const dataTermino = document.getElementById('dataTermino').value;
  const horarioInicio = document.getElementById('horarioInicio').value;
  const horarioTermino = document.getElementById('horarioTermino').value;
  const secretaria = obterValorComOutra('secretaria');
  const nomeSecretario = obterValorComOutra('nomeSecretario');

  // Na edição os campos não são obrigatórios (só o nome): o que for preenchido
  // ainda precisa estar num formato válido.
  const exigir = !ID_EDICAO;
  const faltando = (vazio) => exigir && Boolean(vazio);

  // Foto, RG, órgão expedidor e data de expedição são opcionais.
  marcar('nome', nome.length === 0);
  marcar('cpf', (exigir || cpf.length > 0) && !validarCPF(cpf));
  marcar('rgDataExpedicao', Boolean(rgDataExpedicao && dataNascimento && rgDataExpedicao < dataNascimento));
  marcar('dataNascimento', faltando(!dataNascimento));
  marcar('sexo', faltando(!sexo));
  marcar('escolaridade', faltando(!escolaridade));
  marcar('estadoCivil', faltando(!estadoCivil));
  marcar('contato', contato.length > 0 && contato.length !== 10 && contato.length !== 11);

  marcar('cargaHoraria', faltando(!cargaHoraria));
  marcar('cargo', faltando(cargo.length === 0));
  marcar('cep', (exigir || cep.length > 0) && cep.replace(/\D/g, '').length !== 8);
  marcar('logradouro', faltando(logradouro.length === 0));
  marcar('numero', faltando(numero.length === 0));
  marcar('bairro', faltando(bairro.length === 0));
  marcar('cidade', faltando(cidade.length === 0));
  marcar('estado', faltando(!estado));
  marcar('localPrestacao', faltando(localPrestacao.length === 0));
  marcar('dataInicio', faltando(!dataInicio));

  // Renovado: o novo término vai até 6 meses além do término anterior ou da data
  // da renovação. O período original é histórico (pode ter vindo da planilha
  // antiga com mais de 6 meses), então só se confere a ordem das datas.
  if (renovacaoMarcada()) {
    const terminoAnterior = document.getElementById('terminoAnterior').value;
    const dataRenovacao = document.getElementById('dataRenovacao').value;
    marcar('terminoAnterior', !terminoAnterior || Boolean(dataInicio && terminoAnterior <= dataInicio) ||
      Boolean(dataTermino && terminoAnterior >= dataTermino));
    marcar('dataRenovacao', !dataRenovacao);
    const limite = terminoAnterior && dataRenovacao ? somarMesesISO([terminoAnterior, dataRenovacao].sort().pop(), 6) : '';
    marcar('dataTermino', !dataTermino || !terminoAnterior || dataTermino <= terminoAnterior || Boolean(limite && dataTermino > limite));
  } else if (dataInicio && dataTermino) {
    const datasValidas = dataTermino > dataInicio;
    const dentroDoPrazoMaximo = diferencaEmMeses(dataInicio, dataTermino) <= 6;
    marcar('dataTermino', !datasValidas || !dentroDoPrazoMaximo);
  } else {
    marcar('dataTermino', faltando(true));
  }

  marcar('horarioInicio', faltando(!horarioInicio));
  if (horarioInicio && horarioTermino) {
    marcar('horarioTermino', horarioTermino <= horarioInicio);
  } else {
    marcar('horarioTermino', faltando(!horarioTermino));
  }

  marcar('secretaria', faltando(!secretaria));
  marcar('nomeSecretario', faltando(nomeSecretario.length === 0));

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
    contato: document.getElementById('contato').value.trim(),

    cargaHoraria: document.getElementById('cargaHoraria').value,
    cargo: document.getElementById('cargo').value.trim(),
    cep: document.getElementById('cep').value.trim(),
    logradouro: document.getElementById('logradouro').value.trim(),
    numero: document.getElementById('numero').value.trim(),
    complemento: document.getElementById('complemento').value.trim(),
    bairro: document.getElementById('bairro').value.trim(),
    cidade: document.getElementById('cidade').value.trim(),
    estado: document.getElementById('estado').value,
    localPrestacao: obterValorComOutra('localPrestacao'),
    dataInicio: document.getElementById('dataInicio').value,
    dataTermino: document.getElementById('dataTermino').value,
    horarioInicio: document.getElementById('horarioInicio').value,
    horarioTermino: document.getElementById('horarioTermino').value,
    diasSemana: obterDiasSemanaSelecionados(),
    secretaria: obterValorComOutra('secretaria'),
    nomeSecretario: obterValorComOutra('nomeSecretario'),
  };
  if (ID_EDICAO) {
    dados.renovado = renovacaoMarcada();
    dados.terminoAnterior = dados.renovado ? document.getElementById('terminoAnterior').value : '';
    dados.dataRenovacao = dados.renovado ? document.getElementById('dataRenovacao').value : '';
  }

  const botaoSubmeter = document.querySelector('button[type="submit"]');
  const textoOriginalBotao = botaoSubmeter.innerHTML;
  botaoSubmeter.disabled = true;
  botaoSubmeter.innerHTML = `${icon('refresh', 'girando')} Salvando...`;

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
