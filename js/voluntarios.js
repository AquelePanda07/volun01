/**
 * voluntarios.js
 * Lógica da página "Voluntários Cadastrados": listagem, pesquisa em tempo
 * real, filtros (status, secretaria, escola, carga horária), exclusão com
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
  document.getElementById('filtro-secretaria').addEventListener('change', () => {
    popularFiltroLocais(); // as escolas listadas dependem da secretaria escolhida
    renderizarLista();
  });
  document.getElementById('filtro-local').addEventListener('change', renderizarLista);
  document.getElementById('filtro-carga').addEventListener('change', renderizarLista);
  document.getElementById('filtro-termo').addEventListener('change', renderizarLista);
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
  configurarPrevia();
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
  popularFiltroLocais();
  renderizarLista();
  if (PREVIA_ID) renderizarPrevia(); // ex.: após renovar, mostra os dados novos
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

/** Escolas/locais de prestação, limitados à secretaria selecionada (se houver). */
function popularFiltroLocais() {
  const select = document.getElementById('filtro-local');
  const valorAtual = select.value;
  const secretaria = document.getElementById('filtro-secretaria').value;
  select.querySelectorAll('option:not([value=""])').forEach(o => o.remove());
  const locais = [...new Set(TODOS_VOLUNTARIOS
    .filter(v => !secretaria || v.secretaria === secretaria)
    .map(v => (v.localPrestacao || '').trim())
    .filter(Boolean))]
    .sort((a, b) => a.localeCompare(b, 'pt-BR', { sensitivity: 'base' }));
  locais.forEach(local => {
    const opt = document.createElement('option');
    opt.value = local;
    opt.textContent = local;
    select.appendChild(opt);
  });
  // Mantém a escolha se ela ainda existir na nova lista; senão volta para "Todos".
  select.value = locais.includes(valorAtual) ? valorAtual : '';
}

/** Aplica busca, secretaria, escola e carga horária (tudo menos o status). */
function filtrarSemStatus() {
  const termo = document.getElementById('filtro-busca').value.trim().toLowerCase();
  const secretaria = document.getElementById('filtro-secretaria').value;
  const local = document.getElementById('filtro-local').value;
  const carga = document.getElementById('filtro-carga').value;
  const filtroTermo = document.getElementById('filtro-termo').value;

  return TODOS_VOLUNTARIOS.filter(v => {
    if (termo) {
      const alvo = [v.nome, v.cpf, v.contato, v.localPrestacao, v.cargo].join(' ').toLowerCase();
      if (!alvo.includes(termo)) return false;
    }
    if (secretaria && v.secretaria !== secretaria) return false;
    if (local && (v.localPrestacao || '').trim() !== local) return false;
    if (carga && String(v.cargaHoraria) !== String(carga)) return false;
    if (filtroTermo && !atendeFiltroTermo(v, filtroTermo)) return false;
    return true;
  });
}

/** Filtro "Termo de adesão": assinado/pendente do contrato ou da renovação. */
function atendeFiltroTermo(v, filtro) {
  switch (filtro) {
    case 'termo-assinado': return Boolean(v.termoAssinado);
    case 'termo-pendente': return !v.termoAssinado;
    case 'renovacao-assinada': return Boolean(v.renovado && v.renovacaoAssinada);
    case 'renovacao-pendente': return Boolean(v.renovado && !v.renovacaoAssinada);
    default: return true;
  }
}

/** Chips de renovação, ao lado dos de status: quem pode renovar e quem já renovou. */
const FILTROS_RENOVACAO = {
  PARA_RENOVAR: { texto: 'Para renovar', filtro: podeRenovarContrato },
  RENOVADO: { texto: 'Renovados', filtro: v => Boolean(v.renovado) },
};

function atendeFiltroStatus(v, filtro) {
  if (!filtro) return true;
  if (FILTROS_RENOVACAO[filtro]) return FILTROS_RENOVACAO[filtro].filtro(v);
  return calcularStatusContrato(v.dataInicio, v.dataTermino) === filtro;
}

function filtrarVoluntarios() {
  return filtrarSemStatus()
    .filter(v => atendeFiltroStatus(v, FILTRO_STATUS))
    .sort(compararVoluntarios(document.getElementById('filtro-ordem').value));
}

/** Chips de status e de renovação com a quantidade de cada um (considerando os demais filtros). */
function renderizarChipsStatus() {
  const base = filtrarSemStatus();
  const contar = status => base.filter(v => atendeFiltroStatus(v, status)).length;
  const chip = (status, texto, classe = '') => `
    <button type="button" class="chip${classe}${status === FILTRO_STATUS ? ' chip--ativo' : ''}" data-status="${status}" aria-pressed="${status === FILTRO_STATUS}">
      ${texto} <span class="chip__contador">${status ? contar(status) : base.length}</span>
    </button>`;
  document.getElementById('filtro-status').innerHTML =
    chip('', 'Todos') +
    ['ATIVO', 'A_INICIAR', 'ENCERRADO'].map(s => chip(s, STATUS_LABEL[s])).join('') +
    '<span class="chips__divisor" aria-hidden="true"></span>' +
    Object.entries(FILTROS_RENOVACAO).map(([s, f]) => chip(s, `${icon('repeat')}${f.texto}`, ' chip--renovacao')).join('');
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
    tr.className = `animar-entrada linha-clicavel${String(v.id) === PREVIA_ID ? ' linha--selecionada' : ''}`;
    tr.dataset.previa = v.id;
    tr.setAttribute('style', `--i:${i};${destaquePrazo}`);
    tr.innerHTML = `
      <td class="col-selecao"><input type="checkbox" data-selecionar="${v.id}" ${marcado} aria-label="Selecionar ${escapeHtml(v.nome)}"></td>
      <td>
        <a class="pessoa-cel" href="visualizar.html?id=${id}">
          <img class="avatar-mini" src="${foto}" alt="">
          <div class="pessoa-cel__info">
            <strong>${escapeHtml(v.nome)}${seloTermos(v)}</strong>
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
          ${renovavel ? `<button class="btn btn--outline btn--renovar btn--sm btn--icon-only" data-renovar="${v.id}" data-dica="Renovar contrato" aria-label="Renovar contrato de ${escapeHtml(v.nome)}">${icon('repeat')}</button>` : ''}
          <a class="btn btn--outline btn--sm btn--icon-only" href="visualizar.html?id=${id}" data-dica="Visualizar" aria-label="Visualizar ${escapeHtml(v.nome)}">${icon('eye')}</a>
          <a class="btn btn--outline btn--sm btn--icon-only" href="cadastro.html?id=${id}" data-dica="Editar" aria-label="Editar ${escapeHtml(v.nome)}">${icon('edit')}</a>
          <a class="btn btn--outline btn--sm btn--icon-only" href="gerar-termo.html?id=${id}" data-dica="Gerar Termo de Adesão" aria-label="Gerar Termo de Adesão de ${escapeHtml(v.nome)}">${icon('file')}</a>
          <button class="btn btn--danger-suave btn--sm btn--icon-only" data-excluir="${v.id}" data-dica="Excluir" aria-label="Excluir ${escapeHtml(v.nome)}">${icon('trash')}</button>
        </div>
      </td>
    `;
    corpo.appendChild(tr);

    // Card (telas pequenas)
    const card = document.createElement('div');
    card.className = `voluntario-card animar-entrada linha-clicavel${String(v.id) === PREVIA_ID ? ' linha--selecionada' : ''}`;
    card.dataset.previa = v.id;
    card.setAttribute('style', `--i:${i};${destaquePrazo}`);
    card.innerHTML = `
      <div class="voluntario-card__topo">
        <input type="checkbox" data-selecionar="${v.id}" ${marcado} aria-label="Selecionar ${escapeHtml(v.nome)}">
        <img class="avatar-mini" src="${foto}" alt="">
        <div class="voluntario-card__info">
          <strong>${escapeHtml(v.nome)}${seloRenovado(v)}${seloTermos(v)}</strong>
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

/* ------------------------------------------------------------------ */
/* Prévia do voluntário (painel do lado direito, com tela cheia)        */
/* ------------------------------------------------------------------ */
let PREVIA_ID = null;

function configurarPrevia() {
  // Clique na linha/card (ou no nome) abre a prévia; botões, links de ação e
  // caixas de seleção continuam funcionando normalmente. Ctrl/⌘ + clique no
  // nome ainda abre a página completa em outra aba.
  document.querySelector('.main-area').addEventListener('click', (e) => {
    const alvo = e.target.closest('[data-previa]');
    if (!alvo) return;
    const linkNome = e.target.closest('.pessoa-cel');
    if (linkNome) {
      if (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
      e.preventDefault();
    } else if (e.target.closest('a, button, input, label')) {
      return;
    }
    abrirPrevia(alvo.dataset.previa);
  });

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape' || !PREVIA_ID || document.querySelector('.modal-overlay')) return;
    if (document.getElementById('previa').classList.contains('previa--cheia')) alternarTelaCheia(false);
    else fecharPrevia();
  });

  // Clique fora da prévia (e fora das linhas da lista) fecha a prévia.
  document.addEventListener('click', (e) => {
    if (!PREVIA_ID) return;
    if (e.target.closest('#previa, [data-previa], .modal-overlay, .toast-container')) return;
    if (!e.target.isConnected) return; // elemento redesenhado durante o clique
    fecharPrevia();
  });
}

function abrirPrevia(id) {
  PREVIA_ID = String(id);
  const painel = document.getElementById('previa');
  painel.hidden = false;
  document.body.classList.add('com-previa');
  requestAnimationFrame(() => painel.classList.add('previa--aberta'));
  renderizarPrevia();
  marcarLinhaSelecionada();
}

function fecharPrevia() {
  const painel = document.getElementById('previa');
  PREVIA_ID = null;
  painel.classList.remove('previa--aberta', 'previa--cheia');
  document.body.classList.remove('com-previa', 'previa-tela-cheia');
  marcarLinhaSelecionada();
  setTimeout(() => { if (!PREVIA_ID) painel.hidden = true; }, 250);
}

/** Expande a prévia para a tela inteira (ou volta ao painel lateral). */
function alternarTelaCheia(ativar) {
  const painel = document.getElementById('previa');
  const cheia = painel.classList.toggle('previa--cheia', ativar);
  document.body.classList.toggle('previa-tela-cheia', cheia);
  atualizarBotaoTelaCheia(painel.querySelector('[data-previa-acao="tela-cheia"]'), cheia);
}

function atualizarBotaoTelaCheia(botao, cheia) {
  botao.innerHTML = icon(cheia ? 'minimize' : 'maximize');
  botao.title = cheia ? 'Sair da tela cheia (Esc)' : 'Visualizar em tela cheia';
  botao.setAttribute('aria-label', botao.title);
  botao.setAttribute('aria-pressed', String(cheia));
}

function marcarLinhaSelecionada() {
  document.querySelectorAll('[data-previa]').forEach(el => {
    el.classList.toggle('linha--selecionada', el.dataset.previa === PREVIA_ID);
  });
}

function renderizarPrevia() {
  const painel = document.getElementById('previa');
  const v = TODOS_VOLUNTARIOS.find(x => String(x.id) === PREVIA_ID);
  if (!v) { fecharPrevia(); return; } // voluntário não existe mais (ex.: excluído)

  const status = calcularStatusContrato(v.dataInicio, v.dataTermino);
  const dias = calcularDiasRestantes(v.dataTermino);
  const progresso = calcularProgressoContrato(v.dataInicio, v.dataTermino);
  const idade = calcularIdade(v.dataNascimento);
  const id = encodeURIComponent(v.id);
  const item = (rotulo, valor, classe = '') => `<div class="detalhe-item ${classe}"><b>${rotulo}</b><span>${valor}</span></div>`;
  const endereco = [v.logradouro, v.numero ? `nº ${v.numero}` : '', v.complemento].filter(Boolean).join(', ')
    + (v.bairro ? ` - ${v.bairro}` : '') + (v.cidade ? `, ${v.cidade}` : '') + (v.estado ? `/${v.estado}` : '')
    + (v.cep ? ` — CEP ${v.cep}` : '');
  const descricaoPrazo = status === 'A_INICIAR'
    ? `Começa em ${formatarDataBR(v.dataInicio)}`
    : (status === 'ENCERRADO' ? 'Contrato encerrado' : `${formatarDiasRestantes(dias, status)} para o término`);
  const classeBarra = status === 'ENCERRADO' ? 'progresso__barra--perigo' : (dias !== null && dias <= 30 ? 'progresso__barra--alerta' : '');

  painel.innerHTML = `
    <div class="previa__barra">
      <span class="previa__titulo">${icon('eye')} Prévia do voluntário</span>
      <div class="previa__controles">
        <button type="button" class="btn btn--outline btn--sm btn--icon-only" data-previa-acao="tela-cheia"></button>
        <button type="button" class="btn btn--outline btn--sm btn--icon-only" data-previa-acao="fechar" title="Fechar (Esc)" aria-label="Fechar prévia">${icon('x')}</button>
      </div>
    </div>

    <div class="previa__corpo">
      <div class="previa__perfil">
        <img class="previa__foto" src="${fotoOuAvatar(v)}" alt="Foto de ${escapeHtml(v.nome)}">
        <div class="previa__perfil-info">
          <h2>${escapeHtml(v.nome)}</h2>
          <div class="previa__selos">${badgeStatus(status)}${badgeRenovado(v)}</div>
          <span class="previa__sub">${icon('briefcase')} ${escapeHtml(v.cargo || '-')}</span>
          <span class="previa__sub">${icon('mapPin')} ${escapeHtml(v.localPrestacao || '-')}</span>
        </div>
      </div>

      ${progresso === null ? '' : `
        <div class="progresso-contrato previa__progresso">
          <div class="progresso-contrato__topo">
            <span>${formatarPeriodoBR(v.dataInicio, v.dataTermino)}</span>
            <span><b>${progresso}%</b> · ${escapeHtml(descricaoPrazo)}</span>
          </div>
          <div class="progresso"><div class="progresso__barra ${classeBarra}" style="width:${progresso}%"></div></div>
        </div>`}

      <div class="previa__acoes">
        ${podeRenovarContrato(v) ? `<button type="button" class="btn btn--outline btn--renovar btn--sm" data-previa-acao="renovar">${icon('repeat')} Renovar</button>` : ''}
        <a class="btn btn--sm" href="visualizar.html?id=${id}">${icon('arrowRight')} Página completa</a>
        <a class="btn btn--outline btn--sm" href="cadastro.html?id=${id}">${icon('edit')} Editar</a>
        <a class="btn btn--outline btn--sm" href="gerar-termo.html?id=${id}">${icon('file')} Termo</a>
      </div>

      <section class="previa__secao">
        <h3>${icon('user')} Dados pessoais</h3>
        <div class="detalhe-grid">
          ${item('CPF', escapeHtml(v.cpf || '-'))}
          ${item('Contato', htmlContato(v.contato))}
          ${item('RG', escapeHtml([v.rg, v.rgOrgaoExpedidor].filter(Boolean).join(' — ') || '-'))}
          ${item('Nascimento', `${formatarDataBR(v.dataNascimento)}${idade === '' ? '' : ` (${idade} anos)`}`)}
          ${item('Sexo', escapeHtml(SEXO_LABEL[v.sexo] || v.sexo || '-'))}
          ${item('Estado civil', escapeHtml(ESTADO_CIVIL_LABEL[v.estadoCivil] || v.estadoCivil || '-'))}
          ${item('Escolaridade', escapeHtml(v.escolaridade || '-'), 'detalhe-item--full')}
          ${item('Endereço', escapeHtml(endereco || '-'), 'detalhe-item--full')}
        </div>
      </section>

      <section class="previa__secao">
        <h3>${icon('briefcase')} Prestação de serviço</h3>
        <div class="detalhe-grid">
          ${item('Carga horária', v.cargaHoraria ? `${escapeHtml(v.cargaHoraria)} horas` : '-')}
          ${item('Horário', escapeHtml(formatarHorario(v.horarioInicio, v.horarioTermino)))}
          ${item('Dias da semana', escapeHtml(formatarDiasSemanaExibicao(v.diasSemana)), 'detalhe-item--full')}
          ${item('Secretaria', escapeHtml(v.secretaria || '-'), 'detalhe-item--full')}
          ${item('Secretário(a)', escapeHtml(v.nomeSecretario || '-'), 'detalhe-item--full')}
          ${v.renovado ? item('Renovação', `Renovado em ${formatarDataBR(v.dataRenovacao)} · término anterior: ${formatarDataBR(v.terminoAnterior)}`, 'detalhe-item--full') : ''}
        </div>
      </section>

      <section class="previa__secao" id="previa-termos">
        <h3>${icon('fileCheck')} Termo de Adesão assinado</h3>
        ${htmlResumoTermos(v)}
      </section>
    </div>
  `;


  const botaoTelaCheia = painel.querySelector('[data-previa-acao="tela-cheia"]');
  atualizarBotaoTelaCheia(botaoTelaCheia, painel.classList.contains('previa--cheia'));
  botaoTelaCheia.addEventListener('click', () => alternarTelaCheia());
  painel.querySelector('[data-previa-acao="fechar"]').addEventListener('click', fecharPrevia);
  const botaoRenovar = painel.querySelector('[data-previa-acao="renovar"]');
  if (botaoRenovar) botaoRenovar.addEventListener('click', () => abrirModalRenovacao(v, recarregarVoluntarios));
}
