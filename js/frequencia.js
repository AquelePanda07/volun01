/**
 * frequencia.js
 * Página "Quadro de Frequência": escolhe o mês, feriados/pontos facultativos,
 * descrição das atividades por cargo e quem certifica por lotação, seleciona
 * vários voluntários e gera UM documento Word com o relatório de cada um
 * (api/frequencia.php -> src/QuadroFrequenciaService.php).
 */

const MESES_NOME = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
const CODIGOS_DIA_JS = ['DOM', 'SEG', 'TER', 'QUA', 'QUI', 'SEX', 'SAB']; // índice = Date.getDay()
const CHAVE_DESCRICOES = 'sgv_freq_descricoes';
const chaveOcorrencias = (ano, mes) => `sgv_freq_ocorrencias_${ano}_${mes}`;

let VOLUNTARIOS = [];
let LOCAIS = [];
const SELECIONADOS = new Set();
let OCORRENCIAS = []; // [{ data: 'AAAA-MM-DD', texto: 'FERIADO' }]

/* ---------- armazenamento local (só conveniência; falhas são ignoradas) ---------- */
function lerLocal(chave, padrao) {
  try { const v = localStorage.getItem(chave); return v ? JSON.parse(v) : padrao; } catch (e) { return padrao; }
}
function gravarLocal(chave, valor) {
  try { localStorage.setItem(chave, JSON.stringify(valor)); } catch (e) { /* ignora */ }
}

document.addEventListener('DOMContentLoaded', async () => {
  const hoje = new Date();
  const selectMes = document.getElementById('freq-mes');
  selectMes.innerHTML = MESES_NOME.map((m, i) => `<option value="${i + 1}">${m}</option>`).join('');
  selectMes.value = String(hoje.getMonth() + 1);
  document.getElementById('freq-ano').value = hoje.getFullYear();

  document.getElementById('freq-lista').innerHTML = Array.from({ length: 6 }, () => `
    <li class="freq-item"><span class="skeleton skeleton--circulo"></span><div style="flex:1;"><span class="skeleton" style="width:60%;margin-bottom:.4rem;"></span><span class="skeleton" style="width:40%;"></span></div></li>
  `).join('');

  try {
    [VOLUNTARIOS, LOCAIS] = await Promise.all([
      listarVoluntarios(),
      apiFetch('locais.php').then(r => r.dados),
    ]);
  } catch (erro) {
    showToast(erro.message || 'Não foi possível carregar os dados.', 'erro');
  }

  popularFiltroLotacao();
  carregarOcorrenciasDoMes();
  renderizarLista();
  renderizarCamposDosSelecionados();

  const aoMudarPeriodo = () => { carregarOcorrenciasDoMes(); renderizarLista(); };
  selectMes.addEventListener('change', aoMudarPeriodo);
  document.getElementById('freq-ano').addEventListener('change', aoMudarPeriodo);
  document.getElementById('freq-ate-dia').addEventListener('change', renderizarLista);
  document.getElementById('freq-busca').addEventListener('input', renderizarLista);
  document.getElementById('freq-lotacao').addEventListener('change', renderizarLista);
  document.getElementById('freq-ordem').addEventListener('change', renderizarLista);
  document.getElementById('freq-selecionar-todos').addEventListener('change', (e) => {
    listaVisivel().filter(v => diasNoMes(v) > 0).forEach(v => {
      e.target.checked ? SELECIONADOS.add(String(v.id)) : SELECIONADOS.delete(String(v.id));
    });
    renderizarLista();
    renderizarCamposDosSelecionados();
  });
  document.getElementById('btn-add-ocorrencia').addEventListener('click', () => {
    const { ano, mes } = periodo();
    OCORRENCIAS.push({ data: `${ano}-${String(mes).padStart(2, '0')}-01`, texto: 'FERIADO' });
    renderizarOcorrencias(true);
  });
  document.getElementById('btn-feriados').addEventListener('click', adicionarFeriadosDoMes);
  document.getElementById('btn-gerar-frequencia').addEventListener('click', gerar);
});

function periodo() {
  return {
    ano: parseInt(document.getElementById('freq-ano').value, 10) || new Date().getFullYear(),
    mes: parseInt(document.getElementById('freq-mes').value, 10),
    ateDia: parseInt(document.getElementById('freq-ate-dia').value, 10) || null,
  };
}

/* ------------------------------------------------------------------ */
/* Dias do relatório (mesma regra de QuadroFrequenciaService::gerarAtividades) */
/* ------------------------------------------------------------------ */
function diasNoMes(v) {
  const { ano, mes, ateDia } = periodo();
  const ultimoDoMes = new Date(ano, mes, 0).getDate();
  const ultimo = ateDia ? Math.min(ateDia, ultimoDoMes) : ultimoDoMes;
  const codigos = (v.diasSemana || '').split(',').map(c => c.trim()).filter(c => CODIGOS_DIA_JS.includes(c));
  const permitidos = codigos.length ? codigos : ['SEG', 'TER', 'QUA', 'QUI', 'SEX'];
  let total = 0;
  for (let dia = 1; dia <= ultimo; dia++) {
    const iso = `${ano}-${String(mes).padStart(2, '0')}-${String(dia).padStart(2, '0')}`;
    if ((v.dataInicio && iso < v.dataInicio) || (v.dataTermino && iso > v.dataTermino)) continue;
    if (permitidos.includes(CODIGOS_DIA_JS[new Date(ano, mes - 1, dia).getDay()])) total++;
  }
  return total;
}

/* ------------------------------------------------------------------ */
/* Lista de voluntários                                                 */
/* ------------------------------------------------------------------ */
function popularFiltroLotacao() {
  const lotacoes = [...new Set(VOLUNTARIOS.map(v => (v.localPrestacao || '').trim()).filter(Boolean))]
    .sort((a, b) => a.localeCompare(b, 'pt-BR'));
  document.getElementById('freq-lotacao').insertAdjacentHTML('beforeend',
    lotacoes.map(l => `<option value="${escapeHtml(l)}">${escapeHtml(l)}</option>`).join(''));
}

function compararPorOrdem(a, b) {
  const texto = (x, y) => (x || '').localeCompare(y || '', 'pt-BR', { sensitivity: 'base' });
  if (document.getElementById('freq-ordem').value === 'lotacao') {
    return texto(a.localPrestacao, b.localPrestacao) || texto(a.nome, b.nome);
  }
  return texto(a.nome, b.nome);
}

function listaVisivel() {
  const termo = document.getElementById('freq-busca').value.trim().toLowerCase();
  const lotacao = document.getElementById('freq-lotacao').value;
  return VOLUNTARIOS
    .filter(v => !lotacao || (v.localPrestacao || '').trim() === lotacao)
    .filter(v => !termo || [v.nome, v.cpf, v.cargo].join(' ').toLowerCase().includes(termo))
    .sort(compararPorOrdem);
}

function renderizarLista() {
  // Voluntário sem nenhum dia no mês escolhido não pode ficar selecionado.
  VOLUNTARIOS.forEach(v => { if (SELECIONADOS.has(String(v.id)) && diasNoMes(v) === 0) SELECIONADOS.delete(String(v.id)); });

  const lista = listaVisivel();
  const ul = document.getElementById('freq-lista');
  if (lista.length === 0) {
    ul.innerHTML = `<li class="freq-vazio">${VOLUNTARIOS.length ? 'Nenhum voluntário encontrado.' : 'Nenhum voluntário cadastrado.'}</li>`;
  } else {
    ul.innerHTML = lista.map(v => {
      const dias = diasNoMes(v);
      const id = String(v.id);
      return `
        <li>
          <label class="freq-item${dias === 0 ? ' freq-item--indisponivel' : ''}${SELECIONADOS.has(id) ? ' freq-item--selecionado' : ''}">
            <input type="checkbox" data-id="${escapeHtml(id)}" ${SELECIONADOS.has(id) ? 'checked' : ''} ${dias === 0 ? 'disabled' : ''}>
            <img class="avatar-mini" src="${fotoOuAvatar(v)}" alt="">
            <span class="freq-item__info">
              <strong>${escapeHtml(v.nome)}</strong>
              <span>${escapeHtml(v.cargo || '-')} · ${escapeHtml(v.localPrestacao || 'sem lotação')}</span>
            </span>
            <span class="pill-dias ${dias === 0 ? 'pill-dias--urgente' : 'pill-dias--tranquilo'}">${dias === 0 ? 'Sem contrato no mês' : `${dias} dia${dias === 1 ? '' : 's'}`}</span>
          </label>
        </li>`;
    }).join('');
  }

  ul.querySelectorAll('input[data-id]').forEach(caixa => caixa.addEventListener('change', () => {
    caixa.checked ? SELECIONADOS.add(caixa.dataset.id) : SELECIONADOS.delete(caixa.dataset.id);
    caixa.closest('.freq-item').classList.toggle('freq-item--selecionado', caixa.checked);
    atualizarResumo();
    renderizarCamposDosSelecionados();
  }));

  const disponiveis = lista.filter(v => diasNoMes(v) > 0);
  const marcados = disponiveis.filter(v => SELECIONADOS.has(String(v.id))).length;
  const todos = document.getElementById('freq-selecionar-todos');
  todos.checked = disponiveis.length > 0 && marcados === disponiveis.length;
  todos.indeterminate = marcados > 0 && marcados < disponiveis.length;
  document.getElementById('freq-contador').textContent = `${lista.length} listado(s)`;
  atualizarResumo();
}

function selecionadosEmOrdem() {
  return VOLUNTARIOS.filter(v => SELECIONADOS.has(String(v.id))).sort(compararPorOrdem);
}

function atualizarResumo() {
  const total = SELECIONADOS.size;
  const { ano, mes } = periodo();
  document.getElementById('freq-resumo').innerHTML = total
    ? `<b>${total}</b> voluntário(s) · ${MESES_NOME[mes - 1]}/${ano} · um relatório por voluntário, no mesmo documento`
    : 'Nenhum voluntário selecionado';
  const botao = document.getElementById('btn-gerar-frequencia');
  botao.disabled = total === 0;
  botao.innerHTML = `${icon('download')} Gerar Word${total ? ` (${total})` : ''}`;
}

/* ------------------------------------------------------------------ */
/* Feriados / pontos facultativos                                       */
/* ------------------------------------------------------------------ */
function carregarOcorrenciasDoMes() {
  const { ano, mes } = periodo();
  OCORRENCIAS = lerLocal(chaveOcorrencias(ano, mes), []);
  renderizarOcorrencias();
}

function salvarOcorrencias() {
  const { ano, mes } = periodo();
  gravarLocal(chaveOcorrencias(ano, mes), OCORRENCIAS);
}

function renderizarOcorrencias(focarUltima = false) {
  const { ano, mes } = periodo();
  const min = `${ano}-${String(mes).padStart(2, '0')}-01`;
  const max = `${ano}-${String(mes).padStart(2, '0')}-${String(new Date(ano, mes, 0).getDate()).padStart(2, '0')}`;
  const area = document.getElementById('freq-ocorrencias');
  area.innerHTML = OCORRENCIAS.length === 0
    ? '<p class="freq-vazio">Nenhuma data marcada neste mês.</p>'
    : OCORRENCIAS.map((o, i) => `
      <div class="freq-ocorrencia" data-indice="${i}">
        <input type="date" value="${escapeHtml(o.data)}" min="${min}" max="${max}" aria-label="Data">
        <input type="text" value="${escapeHtml(o.texto)}" list="freq-rotulos" maxlength="40" aria-label="Texto da rubrica" placeholder="FERIADO">
        <button type="button" class="btn btn--danger-suave btn--sm btn--icon-only" title="Remover" aria-label="Remover data">${icon('trash')}</button>
      </div>`).join('');

  area.querySelectorAll('.freq-ocorrencia').forEach(linha => {
    const i = Number(linha.dataset.indice);
    const [data, texto] = linha.querySelectorAll('input');
    data.addEventListener('change', () => { OCORRENCIAS[i].data = data.value; salvarOcorrencias(); });
    texto.addEventListener('input', () => { OCORRENCIAS[i].texto = texto.value; salvarOcorrencias(); });
    linha.querySelector('button').addEventListener('click', () => {
      OCORRENCIAS.splice(i, 1);
      salvarOcorrencias();
      renderizarOcorrencias();
    });
  });
  if (focarUltima) {
    const ultimas = area.querySelectorAll('.freq-ocorrencia input[type="date"]');
    if (ultimas.length) ultimas[ultimas.length - 1].focus();
    salvarOcorrencias();
  }
}

/** Domingo de Páscoa (algoritmo de Meeus/Jones/Butcher). */
function pascoa(ano) {
  const a = ano % 19, b = Math.floor(ano / 100), c = ano % 100;
  const d = Math.floor(b / 4), e = b % 4, f = Math.floor((b + 8) / 25), g = Math.floor((b - f + 1) / 3);
  const h = (19 * a + b - d - g + 15) % 30, i = Math.floor(c / 4), k = c % 4;
  const l = (32 + 2 * e + 2 * i - h - k) % 7, m = Math.floor((a + 11 * h + 22 * l) / 451);
  const mes = Math.floor((h + l - 7 * m + 114) / 31), dia = ((h + l - 7 * m + 114) % 31) + 1;
  return new Date(ano, mes - 1, dia);
}

/** Feriados nacionais e de Rondônia (+ pontos facultativos usuais) do ano. */
function feriadosDoAno(ano) {
  const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const somaDias = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
  const p = pascoa(ano);
  const fixos = [
    ['01-01', 'FERIADO', 'Confraternização Universal'],
    ['01-04', 'FERIADO', 'Criação do Estado de Rondônia'],
    ['04-21', 'FERIADO', 'Tiradentes'],
    ['05-01', 'FERIADO', 'Dia do Trabalho'],
    ['06-18', 'FERIADO', 'Dia do Evangélico (RO)'],
    ['09-07', 'FERIADO', 'Independência do Brasil'],
    ['10-12', 'FERIADO', 'Nossa Senhora Aparecida'],
    ['10-15', 'PONTO FACULTATIVO', 'Dia do Professor'],
    ['11-02', 'FERIADO', 'Finados'],
    ['11-15', 'FERIADO', 'Proclamação da República'],
    ['11-20', 'FERIADO', 'Dia da Consciência Negra'],
    ['12-25', 'FERIADO', 'Natal'],
  ].map(([md, texto, nome]) => ({ data: `${ano}-${md}`, texto, nome }));
  const moveis = [
    { data: iso(somaDias(p, -48)), texto: 'PONTO FACULTATIVO', nome: 'Carnaval' },
    { data: iso(somaDias(p, -47)), texto: 'PONTO FACULTATIVO', nome: 'Carnaval' },
    { data: iso(somaDias(p, -2)), texto: 'FERIADO', nome: 'Sexta-feira Santa' },
    { data: iso(somaDias(p, 60)), texto: 'PONTO FACULTATIVO', nome: 'Corpus Christi' },
  ];
  return [...fixos, ...moveis];
}

function adicionarFeriadosDoMes() {
  const { ano, mes } = periodo();
  const prefixo = `${ano}-${String(mes).padStart(2, '0')}-`;
  const doMes = feriadosDoAno(ano).filter(f => f.data.startsWith(prefixo));
  const novos = doMes.filter(f => !OCORRENCIAS.some(o => o.data === f.data));
  novos.forEach(f => OCORRENCIAS.push({ data: f.data, texto: f.texto }));
  OCORRENCIAS.sort((a, b) => a.data.localeCompare(b.data));
  salvarOcorrencias();
  renderizarOcorrencias();
  showToast(novos.length
    ? `Adicionado(s): ${novos.map(f => `${formatarDataBR(f.data).slice(0, 5)} ${f.nome}`).join(', ')}. Confira e inclua os feriados municipais.`
    : (doMes.length ? 'Os feriados deste mês já estão na lista.' : 'Nenhum feriado nacional ou estadual neste mês.'), 'sucesso');
}

/* ------------------------------------------------------------------ */
/* Descrições por cargo e responsáveis por lotação (dos selecionados)  */
/* ------------------------------------------------------------------ */
function renderizarCamposDosSelecionados() {
  const selecionados = selecionadosEmOrdem();

  // Descrição das atividades por cargo (lembrada neste navegador).
  const descricoesSalvas = lerLocal(CHAVE_DESCRICOES, {});
  const cargos = [...new Set(selecionados.map(v => (v.cargo || '').trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'pt-BR'));
  document.getElementById('secao-descricoes').hidden = cargos.length === 0;
  document.getElementById('freq-descricoes').innerHTML = cargos.map(cargo => `
    <div class="campo">
      <label>${escapeHtml(cargo)}</label>
      <textarea rows="2" data-cargo="${escapeHtml(cargo)}" placeholder="${escapeHtml(cargo)}">${escapeHtml(descricoesSalvas[cargo] || '')}</textarea>
    </div>`).join('');
  document.querySelectorAll('#freq-descricoes textarea').forEach(campo => campo.addEventListener('input', () => {
    const atuais = lerLocal(CHAVE_DESCRICOES, {});
    atuais[campo.dataset.cargo] = campo.value;
    gravarLocal(CHAVE_DESCRICOES, atuais);
  }));

  // Responsável por lotação (gravado no cadastro do Local ao gerar).
  const lotacoes = [...new Set(selecionados.map(v => (v.localPrestacao || '').trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'pt-BR'));
  const area = document.getElementById('freq-responsaveis');
  const valoresDigitados = coletarResponsaveis(); // preserva o que já foi digitado e ainda não salvo
  document.getElementById('secao-responsaveis').hidden = lotacoes.length === 0;
  area.innerHTML = lotacoes.map(lotacao => {
    const local = buscarLocal(lotacao) || {};
    const r = valoresDigitados[lotacao] || {
      responsavel: local.responsavel || '', cargoResponsavel: local.cargoResponsavel || '',
      atoNomeacao: local.atoNomeacao || '', ocultarLotacao: ['TRUE', '1'].includes(String(local.ocultarLotacao || '').toUpperCase()),
    };
    return `
      <fieldset class="freq-responsavel" data-lotacao="${escapeHtml(lotacao)}">
        <legend>${icon('mapPin')} ${escapeHtml(lotacao)}</legend>
        <div class="freq-linha">
          <div class="campo"><label>Nome</label><input type="text" data-campo="responsavel" value="${escapeHtml(r.responsavel)}" placeholder="Ex.: THAIS MOURA C. DO NASCIMENTO"></div>
          <div class="campo"><label>Cargo</label><input type="text" data-campo="cargoResponsavel" value="${escapeHtml(r.cargoResponsavel)}" placeholder="Ex.: Diretora Escolar"></div>
        </div>
        <div class="campo"><label>Ato de nomeação</label><input type="text" data-campo="atoNomeacao" value="${escapeHtml(r.atoNomeacao)}" placeholder="Ex.: PORT. N° 10/GAB-PMIO/2024"></div>
        <label class="freq-check"><input type="checkbox" data-campo="ocultarLotacao" ${r.ocultarLotacao ? 'checked' : ''}> Não imprimir a lotação (assinatura da Secretaria)</label>
      </fieldset>`;
  }).join('');
}

function buscarLocal(nome) {
  const alvo = nome.trim().toLowerCase();
  return LOCAIS.find(l => (l.nome || '').trim().toLowerCase() === alvo) || null;
}

function coletarResponsaveis() {
  const resultado = {};
  document.querySelectorAll('.freq-responsavel').forEach(bloco => {
    const valor = campo => bloco.querySelector(`[data-campo="${campo}"]`);
    resultado[bloco.dataset.lotacao] = {
      responsavel: valor('responsavel').value.trim(),
      cargoResponsavel: valor('cargoResponsavel').value.trim(),
      atoNomeacao: valor('atoNomeacao').value.trim(),
      ocultarLotacao: valor('ocultarLotacao').checked,
    };
  });
  return resultado;
}

/** Grava no cadastro de Locais os responsáveis que mudaram (cria o local, se não existir). */
async function salvarResponsaveis() {
  const digitados = coletarResponsaveis();
  for (const [lotacao, r] of Object.entries(digitados)) {
    const local = buscarLocal(lotacao);
    const dados = { ...r, ocultarLotacao: r.ocultarLotacao ? 'TRUE' : 'FALSE' };
    const atual = local ? {
      responsavel: local.responsavel || '', cargoResponsavel: local.cargoResponsavel || '',
      atoNomeacao: local.atoNomeacao || '', ocultarLotacao: ['TRUE', '1'].includes(String(local.ocultarLotacao || '').toUpperCase()) ? 'TRUE' : 'FALSE',
    } : null;
    if (atual && JSON.stringify(atual) === JSON.stringify(dados)) continue;
    if (!local && !r.responsavel) continue;

    const resposta = local
      ? await apiFetch(`locais.php?id=${encodeURIComponent(local.id)}`, { method: 'PUT', body: JSON.stringify(dados) })
      : await apiFetch('locais.php', { method: 'POST', body: JSON.stringify({ nome: lotacao, endereco: '', ...dados }) });
    const salvo = resposta.dados;
    const indice = LOCAIS.findIndex(l => l.id === salvo.id);
    indice >= 0 ? (LOCAIS[indice] = salvo) : LOCAIS.push(salvo);
  }
}

/* ------------------------------------------------------------------ */
/* Geração                                                              */
/* ------------------------------------------------------------------ */
async function gerar() {
  const selecionados = selecionadosEmOrdem();
  if (selecionados.length === 0) return;
  const { ano, mes, ateDia } = periodo();

  const ocorrencias = {};
  OCORRENCIAS.filter(o => o.data && o.texto.trim()).forEach(o => { ocorrencias[o.data] = o.texto.trim().toUpperCase(); });
  const descricoes = {};
  document.querySelectorAll('#freq-descricoes textarea').forEach(c => { if (c.value.trim()) descricoes[c.dataset.cargo] = c.value.trim(); });

  const botao = document.getElementById('btn-gerar-frequencia');
  botao.disabled = true;
  botao.innerHTML = `${icon('refresh', 'girando')} Gerando ${selecionados.length} relatório(s)...`;

  try {
    await salvarResponsaveis();
    const resposta = await apiFetch('frequencia.php?acao=gerar', {
      method: 'POST',
      body: JSON.stringify({
        ano, mes, ateDia,
        idsVoluntarios: selecionados.map(v => v.id),
        ocorrencias,
        descricoes,
        valor: document.getElementById('freq-valor').value.trim(),
        dataVoluntario: document.getElementById('freq-data-voluntario').value,
        dataCertificacao: document.getElementById('freq-data-certificacao').value,
      }),
    });
    showToast(resposta.mensagem, 'sucesso');
    if (resposta.falhas && resposta.falhas.length) mostrarFalhas(resposta.falhas);
    window.location.href = resposta.urlDownload;
  } catch (erro) {
    showToast(erro.message || 'Não foi possível gerar o Quadro de Frequência.', 'erro');
    if (erro.falhas && erro.falhas.length) mostrarFalhas(erro.falhas);
  } finally {
    atualizarResumo();
  }
}

function mostrarFalhas(falhas) {
  abrirModal(`
    <div class="modal-box modal-box--medio" role="dialog" aria-modal="true">
      <h3>Alguns voluntários ficaram de fora</h3>
      ${falhas.map(f => `
        <div class="falha-item">
          <div class="falha-item__topo"><b>${escapeHtml(f.nome)}</b></div>
          <ul>${f.erros.map(e => `<li>${escapeHtml(e)}</li>`).join('')}</ul>
        </div>`).join('')}
      <div class="modal-acoes"><button type="button" class="btn" data-fechar>Fechar</button></div>
    </div>
  `);
}
