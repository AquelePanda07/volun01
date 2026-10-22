/**
 * voluntarios.js
 * Lógica da página "Voluntários Cadastrados": listagem, pesquisa em tempo
 * real, filtros (status, secretaria, carga horária), exclusão com
 * confirmação e geração de Termos de Adesão em lote.
 */

let TODOS_VOLUNTARIOS = [];
const SELECIONADOS = new Set();
// Status escolhido nos chips ('' = todos). Pode vir do painel: voluntarios.html?status=ATIVO
let FILTRO_STATUS = new URLSearchParams(location.search).get('status') || '';

document.addEventListener('DOMContentLoaded', async () => {
  renderizarCarregando();
  await recarregarVoluntarios();

  document.getElementById('filtro-busca').addEventListener('input', renderizarLista);
  document.getElementById('filtro-secretaria').addEventListener('change', renderizarLista);
  document.getElementById('filtro-carga').addEventListener('change', renderizarLista);
  document.getElementById('filtro-ordem').addEventListener('change', renderizarLista);
  document.getElementById('filtro-status').addEventListener('click', (e) => {
    const chip = e.target.closest('[data-status]');
    if (!chip) return;
    FILTRO_STATUS = chip.dataset.status;
    renderizarLista();
  });
  document.getElementById('selecionar-todos').addEventListener('change', (e) => {
    filtrarVoluntarios().forEach(v => e.target.checked ? SELECIONADOS.add(String(v.id)) : SELECIONADOS.delete(String(v.id)));
    renderizarLista();
  });
  document.getElementById('btn-termos-lote').addEventListener('click', abrirModalTermosLote);
});

function renderizarCarregando() {
  document.getElementById('tabela-corpo').innerHTML = Array.from({ length: 5 }, () => `
    <tr>
      <td></td>
      <td><div class="pessoa-cel"><span class="skeleton skeleton--circulo"></span><div style="flex:1;"><span class="skeleton" style="width:60%;margin-bottom:.4rem;"></span><span class="skeleton" style="width:40%;"></span></div></div></td>
      <td><span class="skeleton" style="width:70%;"></span></td>
      <td><span class="skeleton" style="width:60%;"></span></td>
      <td><span class="skeleton" style="width:50%;"></span></td>
      <td><span class="skeleton" style="width:70px;height:1.4rem;border-radius:999px;"></span></td>
      <td><span class="skeleton" style="width:120px;height:1.8rem;"></span></td>
    </tr>
  `).join('');
}

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

/** Aplica busca, secretaria e carga horária (tudo menos o status). */
function filtrarSemStatus() {
  const termo = document.getElementById('filtro-busca').value.trim().toLowerCase();
  const secretaria = document.getElementById('filtro-secretaria').value;
  const carga = document.getElementById('filtro-carga').value;

  return TODOS_VOLUNTARIOS.filter(v => {
    if (termo) {
      const alvo = [v.nome, v.cpf, v.contato, v.localPrestacao, v.cargo].join(' ').toLowerCase();
      if (!alvo.includes(termo)) return false;
    }
    if (secretaria && v.secretaria !== secretaria) return false;
    if (carga && String(v.cargaHoraria) !== String(carga)) return false;
    return true;
  });
}

function filtrarVoluntarios() {
  return filtrarSemStatus()
    .filter(v => !FILTRO_STATUS || calcularStatusContrato(v.dataInicio, v.dataTermino) === FILTRO_STATUS)
    .sort(compararVoluntarios(document.getElementById('filtro-ordem').value));
}

/** Chips de status com a quantidade de cada um (considerando os demais filtros). */
function renderizarChipsStatus() {
  const base = filtrarSemStatus();
  const contar = status => base.filter(v => calcularStatusContrato(v.dataInicio, v.dataTermino) === status).length;
  const chips = [
    { status: '', texto: 'Todos', qtd: base.length },
    ...['ATIVO', 'A_INICIAR', 'ENCERRADO'].map(s => ({ status: s, texto: STATUS_LABEL[s], qtd: contar(s) })),
  ];
  document.getElementById('filtro-status').innerHTML = chips.map(c => `
    <button type="button" class="chip${c.status === FILTRO_STATUS ? ' chip--ativo' : ''}" data-status="${c.status}" aria-pressed="${c.status === FILTRO_STATUS}">
      ${c.texto} <span class="chip__contador">${c.qtd}</span>
    </button>
  `).join('');
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

  renderizarChipsStatus();
  document.getElementById('contador-resultados').innerHTML =
    `Mostrando <b>${lista.length}</b> de <b>${TODOS_VOLUNTARIOS.length}</b> voluntário(s)`;

  corpo.innerHTML = '';
  cardsContainer.innerHTML = '';
  atualizarControlesSelecao(lista);

  const vazio = lista.length === 0;
  estadoVazio.hidden = !vazio;
  document.querySelector('.tabela-wrapper').hidden = vazio;
  if (vazio) {
    document.getElementById('estado-vazio-texto').textContent = TODOS_VOLUNTARIOS.length === 0
      ? 'Nenhum voluntário cadastrado ainda.'
      : 'Nenhum voluntário encontrado para os filtros aplicados.';
    return;
  }

  lista.forEach((v, i) => {
    const status = calcularStatusContrato(v.dataInicio, v.dataTermino);
    const foto = fotoOuAvatar(v);
    const diasRestantes = calcularDiasRestantes(v.dataTermino);
    const destaquePrazo = estiloDestaquePrazo(diasRestantes, status);
    const textoDiasRestantes = formatarDiasRestantes(diasRestantes, status);
    const marcado = SELECIONADOS.has(String(v.id)) ? 'checked' : '';
    const id = encodeURIComponent(v.id);
    const renovavel = podeRenovarContrato(v);

    // Linha da tabela (telas grandes)
    const tr = document.createElement('tr');
    tr.className = 'animar-entrada';
    tr.setAttribute('style', `--i:${i};${destaquePrazo}`);
    tr.innerHTML = `
      <td class="col-selecao"><input type="checkbox" data-selecionar="${v.id}" ${marcado} aria-label="Selecionar ${escapeHtml(v.nome)}"></td>
      <td>
        <a class="pessoa-cel" href="visualizar.html?id=${id}">
          <img class="avatar-mini" src="${foto}" alt="">
          <div class="pessoa-cel__info">
            <strong>${escapeHtml(v.nome)}</strong>
            <span>${escapeHtml(v.cargo || '-')}</span>
          </div>
        </a>
      </td>
      <td>${escapeHtml(v.localPrestacao || '-')}</td>
      <td>${formatarDataBR(v.dataTermino)}</td>
      <td>${escapeHtml(textoDiasRestantes)}</td>
      <td>${badgeStatus(status)}${badgeRenovado(v)}</td>
      <td>
        <div class="acoes-cel">
          ${renovavel ? `<button class="btn btn--outline btn--renovar btn--sm btn--icon-only" data-renovar="${v.id}" title="Marcar contrato como renovado">${icon('repeat')}</button>` : ''}
          <a class="btn btn--outline btn--sm btn--icon-only" href="visualizar.html?id=${id}" title="Visualizar">${icon('eye')}</a>
          <a class="btn btn--outline btn--sm btn--icon-only" href="cadastro.html?id=${id}" title="Editar">${icon('edit')}</a>
          <a class="btn btn--outline btn--sm btn--icon-only" href="gerar-termo.html?id=${id}" title="Gerar Termo de Adesão">${icon('file')}</a>
          <button class="btn btn--danger-suave btn--sm btn--icon-only" data-excluir="${v.id}" title="Excluir">${icon('trash')}</button>
        </div>
      </td>
    `;
    corpo.appendChild(tr);

    // Card (telas pequenas)
    const card = document.createElement('div');
    card.className = 'voluntario-card animar-entrada';
    card.setAttribute('style', `--i:${i};${destaquePrazo}`);
    card.innerHTML = `
      <div class="voluntario-card__topo">
        <input type="checkbox" data-selecionar="${v.id}" ${marcado} aria-label="Selecionar ${escapeHtml(v.nome)}">
        <img class="avatar-mini" src="${foto}" alt="">
        <div class="voluntario-card__info">
          <strong>${escapeHtml(v.nome)}${seloRenovado(v)}</strong>
          <span>${escapeHtml(v.cargo || '-')} · ${escapeHtml(v.localPrestacao || '-')}</span>
        </div>
        ${badgeStatus(status)}
      </div>
      <div class="voluntario-card__linha"><b>Término do contrato</b><span>${formatarDataBR(v.dataTermino)}</span></div>
      <div class="voluntario-card__linha"><b>Dias para o término</b><span>${escapeHtml(textoDiasRestantes)}</span></div>
      <div class="voluntario-card__acoes">
        ${renovavel ? `<button class="btn btn--outline btn--renovar btn--sm" data-renovar="${v.id}">${icon('repeat')} Renovar</button>` : ''}
        <a class="btn btn--outline btn--sm" href="visualizar.html?id=${id}">${icon('eye')} Ver</a>
        <a class="btn btn--outline btn--sm" href="cadastro.html?id=${id}">${icon('edit')} Editar</a>
        <a class="btn btn--outline btn--sm" href="gerar-termo.html?id=${id}">${icon('file')} Termo</a>
        <button class="btn btn--danger-suave btn--sm" data-excluir="${v.id}">${icon('trash')} Excluir</button>
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

  // Liga os botões de renovação (tabela + cards)
  document.querySelectorAll('[data-renovar]').forEach(btn => {
    btn.addEventListener('click', () => {
      const voluntario = TODOS_VOLUNTARIOS.find(v => String(v.id) === btn.getAttribute('data-renovar'));
      if (voluntario) abrirModalRenovacao(voluntario, recarregarVoluntarios);
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

  let gerando = false;
  const { overlay, fechar } = abrirModal(`
    <div class="modal-box modal-box--medio" role="dialog" aria-modal="true">
      <h3>Gerar Termos de Adesão em Lote</h3>
      <p>Serão gerados os Termos de Adesão de <b>${selecionados.length}</b> voluntário(s), entregues em um único arquivo ZIP. Os campos de assinatura permanecerão em branco.</p>
      <ul class="lote-lista">${selecionados.map(v => `<li>${escapeHtml(v.nome)}</li>`).join('')}</ul>
      <div class="modal-linha">
        <div class="campo">
          <label for="lote-data-emissao">Data de emissão</label>
          <input type="date" id="lote-data-emissao" value="${hojeISO()}">
        </div>
        <div class="campo">
          <label for="lote-formato">Formato</label>
          <select id="lote-formato">
            <option value="pdf">PDF</option>
            <option value="docx">DOCX</option>
          </select>
        </div>
      </div>
      <div class="modal-acoes">
        <button type="button" class="btn btn--outline" data-fechar>Cancelar</button>
        <button type="button" class="btn" data-acao="gerar">${icon('file')} Gerar termos</button>
      </div>
    </div>
  `, { podeFechar: () => !gerando });

  const botaoGerar = overlay.querySelector('[data-acao="gerar"]');
  botaoGerar.addEventListener('click', async () => {
    const dataEmissao = overlay.querySelector('#lote-data-emissao').value;
    const formato = overlay.querySelector('#lote-formato').value;

    gerando = true;
    botaoGerar.disabled = true;
    overlay.querySelector('[data-fechar]').disabled = true;
    botaoGerar.innerHTML = `${icon('refresh', 'girando')} Gerando ${selecionados.length} termo(s)...`;

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
  abrirModal(`
    <div class="modal-box modal-box--medio" role="dialog" aria-modal="true">
      <h3>${totalGerados > 0 ? 'Alguns termos não foram gerados' : 'Nenhum termo foi gerado'}</h3>
      <p>${totalGerados > 0 ? `${totalGerados} termo(s) foram gerados. ` : ''}Os voluntários abaixo não puderam ter o termo gerado:</p>
      ${falhas.map(f => `
        <div class="falha-item">
          <div class="falha-item__topo">
            <b>${escapeHtml(f.nome)}</b>
            <a class="btn btn--outline btn--sm" href="cadastro.html?id=${encodeURIComponent(f.idVoluntario)}">${icon('edit')} Editar</a>
          </div>
          <ul>${f.erros.map(e => `<li>${escapeHtml(e)}</li>`).join('')}</ul>
        </div>
      `).join('')}
      <div class="modal-acoes">
        <button type="button" class="btn" data-fechar>Fechar</button>
      </div>
    </div>
  `);
}
