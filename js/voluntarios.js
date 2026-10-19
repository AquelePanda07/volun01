/**
 * voluntarios.js
 * Lógica da página "Voluntários Cadastrados": listagem, pesquisa em tempo
 * real, filtros (status, secretaria, carga horária), exclusão com
 * confirmação e geração de Termos de Adesão em lote.
 */

let TODOS_VOLUNTARIOS = [];
const SELECIONADOS = new Set();

document.addEventListener('DOMContentLoaded', async () => {
  await recarregarVoluntarios();

  document.getElementById('filtro-busca').addEventListener('input', renderizarLista);
  document.getElementById('filtro-status').addEventListener('change', renderizarLista);
  document.getElementById('filtro-secretaria').addEventListener('change', renderizarLista);
  document.getElementById('filtro-carga').addEventListener('change', renderizarLista);
  document.getElementById('filtro-ordem').addEventListener('change', renderizarLista);
  document.getElementById('selecionar-todos').addEventListener('change', (e) => {
    filtrarVoluntarios().forEach(v => e.target.checked ? SELECIONADOS.add(String(v.id)) : SELECIONADOS.delete(String(v.id)));
    renderizarLista();
  });
  document.getElementById('btn-termos-lote').addEventListener('click', abrirModalTermosLote);
});

async function recarregarVoluntarios() {
  try {
    TODOS_VOLUNTARIOS = await listarVoluntarios();
  } catch (erro) {
    TODOS_VOLUNTARIOS = [];
    showToast(erro.message || 'Não foi possível carregar os voluntários.', 'erro');
  }
  // Descarta seleções de voluntários que não existem mais (ex.: excluídos).
  const idsExistentes = new Set(TODOS_VOLUNTARIOS.map(v => String(v.id)));
  [...SELECIONADOS].forEach(id => { if (!idsExistentes.has(id)) SELECIONADOS.delete(id); });
  popularFiltroSecretarias();
  renderizarLista();
}

function popularFiltroSecretarias() {
  const select = document.getElementById('filtro-secretaria');
  const valorAtual = select.value;
  select.querySelectorAll('option:not([value=""])').forEach(o => o.remove());
  const secretarias = [...new Set(TODOS_VOLUNTARIOS.map(v => v.secretaria).filter(Boolean))].sort();
  secretarias.forEach(sec => {
    const opt = document.createElement('option');
    opt.value = sec;
    opt.textContent = sec;
    select.appendChild(opt);
  });
  select.value = valorAtual;
}

function filtrarVoluntarios() {
  const termo = document.getElementById('filtro-busca').value.trim().toLowerCase();
  const status = document.getElementById('filtro-status').value;
  const secretaria = document.getElementById('filtro-secretaria').value;
  const carga = document.getElementById('filtro-carga').value;

  return TODOS_VOLUNTARIOS.filter(v => {
    const statusAtual = calcularStatusContrato(v.dataInicio, v.dataTermino);

    if (termo) {
      const alvo = [v.nome, v.cpf, v.localPrestacao, v.cargo].join(' ').toLowerCase();
      if (!alvo.includes(termo)) return false;
    }
    if (status && statusAtual !== status) return false;
    if (secretaria && v.secretaria !== secretaria) return false;
    if (carga && String(v.cargaHoraria) !== String(carga)) return false;

    return true;
  }).sort(compararVoluntarios(document.getElementById('filtro-ordem').value));
}

/**
 * Comparador conforme o "Ordenar por". Desempata sempre pelo nome.
 * Em "Dias para o término", contratos encerrados (ou sem data) ficam
 * sempre no fim da lista, em qualquer direção.
 */
function compararVoluntarios(ordem) {
  const texto = (a, b) => (a || '').localeCompare(b || '', 'pt-BR', { sensitivity: 'base' });
  const porNome = (a, b) => texto(a.nome, b.nome);
  const vazioPorUltimo = (a, b, campo) => (!a[campo] - !b[campo]) || texto(a[campo], b[campo]);

  switch (ordem) {
    case 'nome-desc':
      return (a, b) => porNome(b, a);
    case 'cargo':
      return (a, b) => vazioPorUltimo(a, b, 'cargo') || porNome(a, b);
    case 'local':
      return (a, b) => vazioPorUltimo(a, b, 'localPrestacao') || porNome(a, b);
    case 'dias':
    case 'dias-desc': {
      const sentido = ordem === 'dias' ? 1 : -1;
      const dias = v => calcularStatusContrato(v.dataInicio, v.dataTermino) === 'ENCERRADO'
        ? null
        : calcularDiasRestantes(v.dataTermino);
      return (a, b) => {
        const da = dias(a);
        const db = dias(b);
        if (da === null || db === null) return (da === null) - (db === null) || porNome(a, b);
        return (da - db) * sentido || porNome(a, b);
      };
    }
    default:
      return porNome;
  }
}

function renderizarLista() {
  const lista = filtrarVoluntarios();
  const corpo = document.getElementById('tabela-corpo');
  const cardsContainer = document.getElementById('lista-cards');
  const estadoVazio = document.getElementById('estado-vazio');
  const estadoVazioTexto = document.getElementById('estado-vazio-texto');

  corpo.innerHTML = '';
  cardsContainer.innerHTML = '';
  atualizarControlesSelecao(lista);

  if (lista.length === 0) {
    estadoVazio.style.display = 'block';
    estadoVazioTexto.textContent = TODOS_VOLUNTARIOS.length === 0
      ? 'Nenhum voluntário cadastrado ainda.'
      : 'Nenhum voluntário encontrado para os filtros aplicados.';
    return;
  }
  estadoVazio.style.display = 'none';

  lista.forEach(v => {
    const status = calcularStatusContrato(v.dataInicio, v.dataTermino);
    const badgeClasse = status === 'ATIVO' ? 'badge--sucesso' : (status === 'ENCERRADO' ? 'badge--perigo' : 'badge--alerta');
    const foto = v.foto || fotoPadraoSvg(v.nome);
    const diasRestantes = calcularDiasRestantes(v.dataTermino);
    const destaquePrazo = estiloDestaquePrazo(diasRestantes, status);
    const textoDiasRestantes = formatarDiasRestantes(diasRestantes, status);

    // Linha da tabela (telas grandes)
    const tr = document.createElement('tr');
    if (destaquePrazo) tr.setAttribute('style', destaquePrazo);
    const marcado = SELECIONADOS.has(String(v.id)) ? 'checked' : '';
    tr.innerHTML = `
      <td class="col-selecao"><input type="checkbox" data-selecionar="${v.id}" ${marcado} aria-label="Selecionar ${escapeHtml(v.nome)}"></td>
      <td>
        <div class="pessoa-cel">
          <img class="avatar-mini" src="${foto}" alt="Foto de ${escapeHtml(v.nome)}">
          <div class="pessoa-cel__info">
            <strong>${escapeHtml(v.nome)}</strong>
            <span>${escapeHtml(v.cargo || '-')}</span>
          </div>
        </div>
      </td>
      <td>${escapeHtml(v.localPrestacao || '-')}</td>
      <td>${formatarDataBR(v.dataTermino)}</td>
      <td>${escapeHtml(textoDiasRestantes)}</td>
      <td><span class="badge ${badgeClasse}">${icon(STATUS_ICON[status])}${STATUS_LABEL[status]}</span></td>
      <td>
        <div class="acoes-cel">
          <a class="btn btn--outline btn--sm btn--icon-only" href="visualizar.html?id=${encodeURIComponent(v.id)}" title="Visualizar">${icon('eye')}</a>
          <a class="btn btn--outline btn--sm btn--icon-only" href="cadastro.html?id=${encodeURIComponent(v.id)}" title="Editar">${icon('edit')}</a>
          <a class="btn btn--outline btn--sm btn--icon-only" href="gerar-termo.html?id=${encodeURIComponent(v.id)}" title="Gerar Termo de Adesão">${icon('briefcase')}</a>
          <button class="btn btn--danger btn--sm btn--icon-only" data-excluir="${v.id}" title="Excluir">${icon('trash')}</button>
        </div>
      </td>
    `;
    corpo.appendChild(tr);

    // Card (telas pequenas)
    const card = document.createElement('div');
    card.className = 'voluntario-card';
    if (destaquePrazo) card.setAttribute('style', destaquePrazo);
    card.innerHTML = `
      <div class="voluntario-card__topo">
        <input type="checkbox" data-selecionar="${v.id}" ${marcado} aria-label="Selecionar ${escapeHtml(v.nome)}">
        <img class="avatar-mini" src="${foto}" alt="Foto de ${escapeHtml(v.nome)}">
        <div class="voluntario-card__info">
          <strong>${escapeHtml(v.nome)}</strong>
          <span>${escapeHtml(v.cargo || '-')} · ${escapeHtml(v.localPrestacao || '-')}</span>
        </div>
        <span class="badge ${badgeClasse}">${icon(STATUS_ICON[status])}${STATUS_LABEL[status]}</span>
      </div>
      <div class="voluntario-card__linha"><b>Término do contrato</b><span>${formatarDataBR(v.dataTermino)}</span></div>
      <div class="voluntario-card__linha"><b>Dias para o término</b><span>${escapeHtml(textoDiasRestantes)}</span></div>
      <div class="voluntario-card__acoes">
        <a class="btn btn--outline btn--sm" href="visualizar.html?id=${encodeURIComponent(v.id)}">${icon('eye')} Ver</a>
        <a class="btn btn--outline btn--sm" href="cadastro.html?id=${encodeURIComponent(v.id)}">${icon('edit')} Editar</a>
        <a class="btn btn--outline btn--sm" href="gerar-termo.html?id=${encodeURIComponent(v.id)}">${icon('briefcase')} Termo</a>
        <button class="btn btn--danger btn--sm" data-excluir="${v.id}">${icon('trash')} Excluir</button>
      </div>
    `;
    cardsContainer.appendChild(card);
  });

  // Liga as caixas de seleção (tabela + cards)
  document.querySelectorAll('[data-selecionar]').forEach(caixa => {
    caixa.addEventListener('change', () => {
      const id = caixa.getAttribute('data-selecionar');
      caixa.checked ? SELECIONADOS.add(id) : SELECIONADOS.delete(id);
      // Mantém tabela e card do mesmo voluntário sincronizados.
      document.querySelectorAll(`[data-selecionar="${id}"]`).forEach(c => { c.checked = caixa.checked; });
      atualizarControlesSelecao(filtrarVoluntarios());
    });
  });

  // Liga os botões de exclusão (tabela + cards)
  document.querySelectorAll('[data-excluir]').forEach(btn => {
    btn.addEventListener('click', () => confirmarExclusao(btn.getAttribute('data-excluir')));
  });
}

function confirmarExclusao(id) {
  const voluntario = TODOS_VOLUNTARIOS.find(v => String(v.id) === String(id));
  if (!voluntario) return;

  showConfirm({
    titulo: 'Excluir voluntário',
    mensagem: `Tem certeza que deseja excluir o cadastro de "${voluntario.nome}"? Esta ação não pode ser desfeita.`,
    textoConfirmar: 'Excluir',
    textoCancelar: 'Cancelar',
    onConfirm: async () => {
      try {
        await excluirVoluntario(id);
        await recarregarVoluntarios();
        showToast('Voluntário excluído com sucesso.', 'sucesso');
      } catch (erro) {
        showToast(erro.message || 'Não foi possível excluir o voluntário.', 'erro');
      }
    }
  });
}

/* ------------------------------------------------------------------ */
/* Geração de Termos de Adesão em lote                                  */
/* ------------------------------------------------------------------ */

function atualizarControlesSelecao(listaVisivel) {
  const total = SELECIONADOS.size;
  document.getElementById('btn-termos-lote').disabled = total === 0;
  document.getElementById('btn-termos-lote-texto').textContent = total > 0
    ? `Gerar Termos em Lote (${total})`
    : 'Gerar Termos em Lote';

  const todos = document.getElementById('selecionar-todos');
  const marcadosVisiveis = listaVisivel.filter(v => SELECIONADOS.has(String(v.id))).length;
  todos.checked = listaVisivel.length > 0 && marcadosVisiveis === listaVisivel.length;
  todos.indeterminate = marcadosVisiveis > 0 && marcadosVisiveis < listaVisivel.length;
}

function abrirModalTermosLote() {
  const selecionados = TODOS_VOLUNTARIOS.filter(v => SELECIONADOS.has(String(v.id)));
  if (selecionados.length === 0) return;

  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.innerHTML = `
    <div class="modal-box" role="dialog" aria-modal="true" style="max-width:520px; width:95%;">
      <h3 style="margin-top:0;">Gerar Termos de Adesão em Lote</h3>
      <p>Serão gerados os Termos de Adesão de <b>${selecionados.length}</b> voluntário(s), entregues em um único arquivo ZIP. Os campos de assinatura permanecerão em branco.</p>
      <ul class="lote-lista">${selecionados.map(v => `<li>${escapeHtml(v.nome)}</li>`).join('')}</ul>
      <div style="display:flex; gap:1rem; flex-wrap:wrap; margin-top:1rem;">
        <div class="campo" style="flex:1; min-width:180px;">
          <label for="lote-data-emissao">Data de emissão</label>
          <input type="date" id="lote-data-emissao" value="${hojeISO()}">
        </div>
        <div class="campo" style="flex:1; min-width:140px;">
          <label for="lote-formato">Formato</label>
          <select id="lote-formato">
            <option value="pdf">PDF</option>
            <option value="docx">DOCX</option>
          </select>
        </div>
      </div>
      <div class="modal-confirm__actions" style="justify-content:flex-end;">
        <button type="button" class="btn btn--outline" data-acao="cancelar">Cancelar</button>
        <button type="button" class="btn" data-acao="gerar">${icon('checkCircle')} Gerar termos</button>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
  requestAnimationFrame(() => overlay.classList.add('modal-overlay--visivel'));

  let gerando = false;
  function fechar() {
    if (gerando) return;
    overlay.classList.remove('modal-overlay--visivel');
    setTimeout(() => overlay.remove(), 200);
  }
  overlay.addEventListener('click', (e) => { if (e.target === overlay) fechar(); });
  overlay.querySelector('[data-acao="cancelar"]').addEventListener('click', fechar);

  const botaoGerar = overlay.querySelector('[data-acao="gerar"]');
  botaoGerar.addEventListener('click', async () => {
    const dataEmissao = overlay.querySelector('#lote-data-emissao').value;
    const formato = overlay.querySelector('#lote-formato').value;

    gerando = true;
    botaoGerar.disabled = true;
    overlay.querySelector('[data-acao="cancelar"]').disabled = true;
    botaoGerar.innerHTML = `${icon('clock')} Gerando ${selecionados.length} termo(s)...`;

    try {
      const resposta = await gerarDocumentosEmLote(selecionados.map(v => v.id), formato, dataEmissao);
      gerando = false;
      fechar();
      showToast(resposta.mensagem, 'sucesso');
      if (resposta.falhas && resposta.falhas.length) {
        mostrarFalhasLote(resposta.falhas, resposta.totalGerados);
      }
      SELECIONADOS.clear();
      renderizarLista();
      window.location.href = resposta.urlDownload;
    } catch (erro) {
      gerando = false;
      fechar();
      showToast(erro.message || 'Não foi possível gerar os termos em lote.', 'erro');
      if (erro.falhas && erro.falhas.length) {
        mostrarFalhasLote(erro.falhas, 0);
      }
    }
  });
}

function mostrarFalhasLote(falhas, totalGerados) {
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.innerHTML = `
    <div class="modal-box" role="dialog" aria-modal="true" style="max-width:600px; width:95%; max-height:85vh; overflow:auto;">
      <h3 style="margin-top:0;">${totalGerados > 0 ? 'Alguns termos não foram gerados' : 'Nenhum termo foi gerado'}</h3>
      <p>${totalGerados > 0 ? `${totalGerados} termo(s) foram gerados. ` : ''}Os voluntários abaixo não puderam ter o termo gerado:</p>
      ${falhas.map(f => `
        <div style="margin-bottom:.8rem;">
          <b>${escapeHtml(f.nome)}</b>
          <a class="btn btn--outline btn--sm" style="margin-left:.5rem;" href="cadastro.html?id=${encodeURIComponent(f.idVoluntario)}">${icon('edit')} Editar</a>
          <ul style="margin:.3rem 0 0 1.2rem;">${f.erros.map(e => `<li>${escapeHtml(e)}</li>`).join('')}</ul>
        </div>
      `).join('')}
      <div class="modal-confirm__actions" style="justify-content:flex-end;">
        <button type="button" class="btn" data-acao="fechar">Fechar</button>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
  requestAnimationFrame(() => overlay.classList.add('modal-overlay--visivel'));

  function fechar() {
    overlay.classList.remove('modal-overlay--visivel');
    setTimeout(() => overlay.remove(), 200);
  }
  overlay.addEventListener('click', (e) => { if (e.target === overlay) fechar(); });
  overlay.querySelector('[data-acao="fechar"]').addEventListener('click', fechar);
}

function hojeISO() {
  const hoje = new Date();
  const mes = String(hoje.getMonth() + 1).padStart(2, '0');
  const dia = String(hoje.getDate()).padStart(2, '0');
  return `${hoje.getFullYear()}-${mes}-${dia}`;
}

function fotoPadraoSvg(nome) {
  const inicial = (nome || '?').trim().charAt(0).toUpperCase();
  return `data:image/svg+xml;utf8,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80"><rect width="80" height="80" fill="#cfe3f8"/><text x="50%" y="55%" font-family="Arial" font-size="32" fill="#1c64b0" text-anchor="middle" dominant-baseline="middle">${inicial}</text></svg>`)}`;
}
