/**
 * documentos.js
 * Página "Documentos": situação do arquivo único de documentos pessoais de cada
 * voluntário (RG, CPF, comprovante de luz, histórico escolar e certidão
 * negativa juntos) e do que ele contém. "Ver / anexar" abre o arquivo e a lista
 * do voluntário num modal, com os mesmos controles da página de detalhes.
 *
 * Aceita ?id=5 na URL para abrir já posicionado no voluntário.
 */

let TODOS_VOLUNTARIOS = [];
/** id do voluntário => o arquivo de documentos dele (só quem tem um). */
const ANEXOS_POR_VOLUNTARIO = new Map();
let FILTRO_SITUACAO = '';
const ID_DESTACADO = new URLSearchParams(location.search).get('id');

const anexoDe = v => ANEXOS_POR_VOLUNTARIO.get(String(v.id)) || null;
const qtdEntregues = v => documentosEntregues(anexoDe(v)).length;

const SITUACOES = [
  { valor: '', texto: 'Todos', filtro: () => true },
  { valor: 'completo', texto: 'Completos', filtro: v => qtdEntregues(v) === DOCUMENTOS_ESPERADOS.length },
  { valor: 'incompleto', texto: 'Faltando algum', filtro: v => Boolean(anexoDe(v)) && qtdEntregues(v) < DOCUMENTOS_ESPERADOS.length },
  { valor: 'nenhum', texto: 'Sem arquivo', filtro: v => !anexoDe(v) },
];

document.addEventListener('DOMContentLoaded', async () => {
  renderizarCarregando();
  document.getElementById('filtro-falta').insertAdjacentHTML('beforeend',
    DOCUMENTOS_ESPERADOS.map(t => `<option value="${t}">${TIPOS_DOCUMENTO[t]}</option>`).join(''));

  try {
    const [voluntarios, anexos] = await Promise.all([listarVoluntarios(), listarAnexos()]);
    TODOS_VOLUNTARIOS = voluntarios;
    anexos.forEach(a => ANEXOS_POR_VOLUNTARIO.set(String(a.idVoluntario), a));
  } catch (erro) {
    showToast(erro.message || 'Não foi possível carregar os documentos.', 'erro');
  }

  renderizarTudo();
  document.getElementById('filtro-busca').addEventListener('input', renderizarTudo);
  document.getElementById('filtro-status').addEventListener('change', renderizarTudo);
  document.getElementById('filtro-falta').addEventListener('change', renderizarTudo);
  document.getElementById('filtro-situacao').addEventListener('click', (e) => {
    const chip = e.target.closest('[data-situacao]');
    if (!chip) return;
    FILTRO_SITUACAO = chip.dataset.situacao;
    renderizarTudo();
  });
  document.getElementById('docs-lista').addEventListener('click', (e) => {
    const botao = e.target.closest('[data-abrir-docs]');
    if (!botao) return;
    const v = TODOS_VOLUNTARIOS.find(x => String(x.id) === botao.dataset.abrirDocs);
    if (v) abrirModalDocumentos(v);
  });

  if (ID_DESTACADO) {
    const alvo = document.querySelector(`[data-voluntario="${CSS.escape(ID_DESTACADO)}"]`);
    if (alvo) setTimeout(() => alvo.scrollIntoView({ behavior: 'smooth', block: 'center' }), 300);
  }
});

function renderizarCarregando() {
  document.getElementById('docs-lista').innerHTML = Array.from({ length: 4 }, () => `
    <div class="doc-linha">
      <div class="termo-linha__pessoa">
        <span class="skeleton skeleton--circulo"></span>
        <div style="flex:1;"><span class="skeleton" style="width:70%;margin-bottom:.4rem;"></span><span class="skeleton" style="width:45%;"></span></div>
      </div>
      <span class="skeleton" style="height:1.6rem;"></span>
    </div>
  `).join('');
}

/** Busca, status do contrato e "falta o documento" (tudo menos a situação). */
function filtrarSemSituacao() {
  const termo = document.getElementById('filtro-busca').value.trim().toLowerCase();
  const status = document.getElementById('filtro-status').value;
  const falta = document.getElementById('filtro-falta').value;
  return TODOS_VOLUNTARIOS.filter(v => {
    if (status && calcularStatusContrato(v.dataInicio, v.dataTermino) !== status) return false;
    if (falta && documentosEntregues(anexoDe(v)).includes(falta)) return false;
    if (termo) {
      const alvo = [v.nome, v.cpf, v.localPrestacao, v.cargo].join(' ').toLowerCase();
      if (!alvo.includes(termo)) return false;
    }
    return true;
  });
}

function renderizarTudo() {
  renderizarResumo();
  const base = filtrarSemSituacao();
  const situacao = SITUACOES.find(s => s.valor === FILTRO_SITUACAO) || SITUACOES[0];
  const lista = base.filter(situacao.filtro);

  document.getElementById('filtro-situacao').innerHTML = SITUACOES.map(s => `
    <button type="button" class="chip${s.valor === FILTRO_SITUACAO ? ' chip--ativo' : ''}" data-situacao="${s.valor}" aria-pressed="${s.valor === FILTRO_SITUACAO}">
      ${s.texto} <span class="chip__contador">${base.filter(s.filtro).length}</span>
    </button>
  `).join('');
  document.getElementById('contador-resultados').innerHTML =
    `Mostrando <b>${lista.length}</b> de <b>${TODOS_VOLUNTARIOS.length}</b> voluntário(s)`;

  const container = document.getElementById('docs-lista');
  const estadoVazio = document.getElementById('estado-vazio');
  estadoVazio.hidden = lista.length > 0;
  container.hidden = lista.length === 0;
  if (lista.length === 0) {
    document.getElementById('estado-vazio-texto').textContent = TODOS_VOLUNTARIOS.length === 0
      ? 'Nenhum voluntário cadastrado ainda.'
      : 'Nenhum voluntário encontrado para os filtros aplicados.';
    container.innerHTML = '';
    return;
  }
  container.innerHTML = lista.map((v, i) => htmlLinha(v, i)).join('');
}

/** Cartões de totais no topo (sempre sobre todos os voluntários). */
function renderizarResumo() {
  const todos = TODOS_VOLUNTARIOS;
  const total = DOCUMENTOS_ESPERADOS.length;
  const cards = [
    { valor: todos.filter(v => qtdEntregues(v) === total).length, total: todos.length, label: 'Com todos os documentos', classe: 'card-stat--sucesso', icone: 'checkCircle' },
    { valor: todos.filter(v => anexoDe(v) && qtdEntregues(v) < total).length, label: 'Arquivo com documento faltando', classe: 'card-stat--alerta', icone: 'alert' },
    { valor: todos.filter(v => !anexoDe(v)).length, label: 'Sem arquivo anexado', classe: '', icone: 'file' },
    { valor: ANEXOS_POR_VOLUNTARIO.size, total: todos.length, label: 'Com arquivo anexado', classe: '', icone: 'paperclip' },
  ];
  document.getElementById('docs-resumo').innerHTML = cards.map(c => `
    <div class="termos-resumo__card ${c.classe}">
      <span class="termos-resumo__icone">${icon(c.icone)}</span>
      <div>
        <strong>${c.valor}${c.total !== undefined ? `<small> / ${c.total}</small>` : ''}</strong>
        <span>${c.label}</span>
      </div>
    </div>
  `).join('');
}

function htmlLinha(v, i) {
  const status = calcularStatusContrato(v.dataInicio, v.dataTermino);
  const destaque = String(v.id) === ID_DESTACADO ? ' doc-linha--destaque' : '';
  return `
    <article class="doc-linha animar-entrada${destaque}" style="--i:${Math.min(i, 20)}" data-voluntario="${v.id}">
      <div class="termo-linha__pessoa">
        <img class="avatar-mini" src="${fotoOuAvatar(v)}" alt="">
        <div class="termo-linha__info">
          <a href="visualizar.html?id=${encodeURIComponent(v.id)}#documentos"><strong>${escapeHtml(v.nome)}</strong></a>
          <span>${escapeHtml(v.cargo || '-')}</span>
          <div class="termo-linha__selos">${badgeStatus(status)}</div>
        </div>
      </div>
      ${htmlSelosDocumentos(anexoDe(v))}
      <button type="button" class="btn btn--outline btn--sm" data-abrir-docs="${v.id}">${icon('paperclip')} Ver / anexar</button>
    </article>
  `;
}

/** Modal com o arquivo de documentos do voluntário (anexar, abrir, baixar, substituir, remover) e a lista do que ele contém. */
function abrirModalDocumentos(v) {
  // Ao fechar: não deixa fechar no meio de um envio; marcações pendentes são gravadas de uma vez.
  const aoFechar = () => {
    if (overlay.querySelector('.docs-voluntario--ocupado')) return false;
    const anexo = anexoDe(v);
    if (anexo && anexo.pendente) salvarMarcacoesAoFechar(v, anexo);
    return true;
  };
  const { overlay } = abrirModal(`
    <div class="modal-box modal-box--medio" role="dialog" aria-modal="true" aria-labelledby="docs-titulo">
      <div class="modal-cabecalho">
        <h3 id="docs-titulo">${icon('paperclip')} ${escapeHtml(v.nome)}</h3>
        <button type="button" class="btn btn--outline btn--sm btn--icon-only" data-fechar aria-label="Fechar">${icon('x')}</button>
      </div>
      <div id="docs-modal-corpo"></div>
    </div>
  `, { podeFechar: aoFechar });
  const corpo = overlay.querySelector('#docs-modal-corpo');
  const desenhar = () => {
    corpo.innerHTML = htmlDocumentosVoluntario(anexoDe(v), { salvarAoFechar: true });
    ligarDocumentosVoluntario(corpo, v, anexoDe(v), (novo) => {
      if (novo) ANEXOS_POR_VOLUNTARIO.set(String(v.id), novo);
      else ANEXOS_POR_VOLUNTARIO.delete(String(v.id));
      desenhar();
      atualizarLinha(v);
    });
  };
  desenhar();
}

/** Grava em segundo plano as marcações feitas no modal (a linha já mostra o novo estado). */
async function salvarMarcacoesAoFechar(v, anexo) {
  try {
    ANEXOS_POR_VOLUNTARIO.set(String(v.id), await salvarMarcacoesDocumentos(v, anexo));
    showToast(`Documentos de ${v.nome} salvos.`, 'sucesso');
  } catch (erro) {
    // Volta a linha para o que está gravado, para a tela não mostrar algo que não foi salvo.
    ANEXOS_POR_VOLUNTARIO.set(String(v.id), { ...anexo, documentos: anexo.documentosSalvos || [], pendente: false });
    showToast(`Não foi possível salvar os documentos de ${v.nome}: ${erro.message}`, 'erro');
  }
  atualizarLinha(v);
}

/** Redesenha só a linha alterada (mantém a rolagem), os totais e os contadores. */
function atualizarLinha(v) {
  const linhaAtual = document.querySelector(`[data-voluntario="${CSS.escape(String(v.id))}"]`);
  if (linhaAtual) {
    const modelo = document.createElement('div');
    modelo.innerHTML = htmlLinha(v, 0).trim();
    const nova = modelo.firstElementChild;
    nova.classList.remove('animar-entrada');
    linhaAtual.replaceWith(nova);
  }
  renderizarResumo();
  const base = filtrarSemSituacao();
  document.querySelectorAll('#filtro-situacao [data-situacao]').forEach(chip => {
    const s = SITUACOES.find(x => x.valor === chip.dataset.situacao);
    chip.querySelector('.chip__contador').textContent = base.filter(s.filtro).length;
  });
}
