/**
 * voluntarios.js
 * Lógica da página "Voluntários Cadastrados": listagem, pesquisa em tempo
 * real, filtros (status, secretaria, carga horária) e exclusão com
 * confirmação.
 */

let TODOS_VOLUNTARIOS = [];

document.addEventListener('DOMContentLoaded', async () => {
  await recarregarVoluntarios();

  document.getElementById('filtro-busca').addEventListener('input', renderizarLista);
  document.getElementById('filtro-status').addEventListener('change', renderizarLista);
  document.getElementById('filtro-secretaria').addEventListener('change', renderizarLista);
  document.getElementById('filtro-carga').addEventListener('change', renderizarLista);
});

async function recarregarVoluntarios() {
  try {
    TODOS_VOLUNTARIOS = await listarVoluntarios();
  } catch (erro) {
    TODOS_VOLUNTARIOS = [];
    showToast(erro.message || 'Não foi possível carregar os voluntários.', 'erro');
  }
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
  }).sort((a, b) => (a.nome || '').localeCompare(b.nome || ''));
}

function renderizarLista() {
  const lista = filtrarVoluntarios();
  const corpo = document.getElementById('tabela-corpo');
  const cardsContainer = document.getElementById('lista-cards');
  const estadoVazio = document.getElementById('estado-vazio');
  const estadoVazioTexto = document.getElementById('estado-vazio-texto');

  corpo.innerHTML = '';
  cardsContainer.innerHTML = '';

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
    tr.innerHTML = `
      <td>
        <div class="pessoa-cel">
          <img class="avatar-mini" src="${foto}" alt="Foto de ${escapeHtml(v.nome)}">
          <strong>${escapeHtml(v.nome)}</strong>
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
        <img class="avatar-mini" src="${foto}" alt="Foto de ${escapeHtml(v.nome)}">
        <div class="voluntario-card__info">
          <strong>${escapeHtml(v.nome)}</strong>
          <span>${escapeHtml(v.localPrestacao || '-')}</span>
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

function fotoPadraoSvg(nome) {
  const inicial = (nome || '?').trim().charAt(0).toUpperCase();
  return `data:image/svg+xml;utf8,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80"><rect width="80" height="80" fill="#c7d3d0"/><text x="50%" y="55%" font-family="Arial" font-size="32" fill="#2f5d50" text-anchor="middle" dominant-baseline="middle">${inicial}</text></svg>`)}`;
}
