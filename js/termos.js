/**
 * termos.js
 * Página "Termos de Adesão": para cada voluntário, anexo do termo assinado
 * do contrato (e da renovação, quando houver) e a marcação de assinado —
 * que só é liberada depois de anexar o arquivo (regra também validada no
 * servidor, em ContratoService).
 *
 * Aceita ?id=5 na URL para abrir já posicionado no voluntário.
 */

let TODOS_VOLUNTARIOS = [];
let FILTRO_SITUACAO = '';
const ID_DESTACADO = new URLSearchParams(location.search).get('id');

/** Situações dos chips. Cada uma diz se o voluntário entra no filtro. */
const SITUACOES = [
  { valor: '', texto: 'Todos', filtro: () => true },
  { valor: 'pendente', texto: 'Sem anexo', filtro: v => !v.anexoTermo },
  { valor: 'anexado', texto: 'Anexado, falta marcar', filtro: v => Boolean(v.anexoTermo && !v.termoAssinado) },
  { valor: 'assinado', texto: 'Termo assinado', filtro: v => Boolean(v.termoAssinado) },
  { valor: 'renovacao-pendente', texto: 'Renovação pendente', filtro: v => Boolean(v.renovado && !v.renovacaoAssinada) },
  { valor: 'renovacao-assinada', texto: 'Renovação assinada', filtro: v => Boolean(v.renovado && v.renovacaoAssinada) },
];

document.addEventListener('DOMContentLoaded', async () => {
  renderizarCarregando();

  try {
    TODOS_VOLUNTARIOS = await listarVoluntarios();
  } catch (erro) {
    showToast(erro.message || 'Não foi possível carregar os voluntários.', 'erro');
  }

  renderizarTudo();
  document.getElementById('filtro-busca').addEventListener('input', renderizarTudo);
  document.getElementById('filtro-status').addEventListener('change', renderizarTudo);
  document.getElementById('filtro-situacao').addEventListener('click', (e) => {
    const chip = e.target.closest('[data-situacao]');
    if (!chip) return;
    FILTRO_SITUACAO = chip.dataset.situacao;
    renderizarTudo();
  });

  if (ID_DESTACADO) {
    const alvo = document.querySelector(`[data-voluntario="${CSS.escape(ID_DESTACADO)}"]`);
    if (alvo) {
      alvo.classList.add('termo-linha--destaque');
      setTimeout(() => alvo.scrollIntoView({ behavior: 'smooth', block: 'center' }), 300);
    }
  }
});

function renderizarCarregando() {
  document.getElementById('termos-lista').innerHTML = Array.from({ length: 3 }, () => `
    <div class="termo-linha">
      <div class="termo-linha__pessoa">
        <span class="skeleton skeleton--circulo"></span>
        <div style="flex:1;"><span class="skeleton" style="width:70%;margin-bottom:.4rem;"></span><span class="skeleton" style="width:45%;"></span></div>
      </div>
      <span class="skeleton" style="height:7rem;border-radius:var(--raio);"></span>
    </div>
  `).join('');
}

/** Busca e status do contrato (tudo menos a situação do termo). */
function filtrarSemSituacao() {
  const termo = document.getElementById('filtro-busca').value.trim().toLowerCase();
  const status = document.getElementById('filtro-status').value;
  return TODOS_VOLUNTARIOS.filter(v => {
    if (status && calcularStatusContrato(v.dataInicio, v.dataTermino) !== status) return false;
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

  const container = document.getElementById('termos-lista');
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
  lista.forEach(v => ligarLinha(v));
}

/** Cartões de totais no topo (sempre sobre todos os voluntários). */
function renderizarResumo() {
  const todos = TODOS_VOLUNTARIOS;
  const renovados = todos.filter(v => v.renovado);
  const cards = [
    { valor: todos.filter(v => v.termoAssinado).length, total: todos.length, label: 'Termos assinados', classe: 'card-stat--sucesso', icone: 'fileCheck' },
    { valor: todos.filter(v => v.anexoTermo && !v.termoAssinado).length, label: 'Anexados, falta marcar', classe: '', icone: 'paperclip' },
    { valor: todos.filter(v => !v.anexoTermo).length, label: 'Sem termo anexado', classe: 'card-stat--alerta', icone: 'file' },
    { valor: renovados.filter(v => v.renovacaoAssinada).length, total: renovados.length, label: 'Renovações assinadas', classe: 'card-stat--renovado', icone: 'repeat' },
  ];
  document.getElementById('termos-resumo').innerHTML = cards.map(c => `
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
  const destaque = String(v.id) === ID_DESTACADO ? ' termo-linha--destaque' : '';
  return `
    <article class="termo-linha animar-entrada${destaque}" style="--i:${i}" data-voluntario="${v.id}">
      <div class="termo-linha__pessoa">
        <img class="avatar-mini" src="${fotoOuAvatar(v)}" alt="">
        <div class="termo-linha__info">
          <a href="visualizar.html?id=${encodeURIComponent(v.id)}"><strong>${escapeHtml(v.nome)}</strong></a>
          <span>${escapeHtml(v.cargo || '-')}</span>
          <span>${formatarDataBR(v.dataInicio)} → ${formatarDataBR(v.dataTermino)}</span>
          <div class="termo-linha__selos">${badgeStatus(status)}${badgeRenovado(v)}</div>
        </div>
      </div>
      <div class="termo-linha__termos">${htmlTermosAssinados(v)}</div>
    </article>
  `;
}

function ligarLinha(v) {
  const linha = document.querySelector(`[data-voluntario="${CSS.escape(String(v.id))}"]`);
  ligarTermosAssinados(linha, v, atualizarVoluntario);
}

/** Redesenha só a linha alterada (mantém a rolagem) e os totais. */
function atualizarVoluntario(atualizado) {
  const indice = TODOS_VOLUNTARIOS.findIndex(x => String(x.id) === String(atualizado.id));
  if (indice >= 0) TODOS_VOLUNTARIOS[indice] = atualizado;

  const linhaAtual = document.querySelector(`[data-voluntario="${CSS.escape(String(atualizado.id))}"]`);
  if (!linhaAtual) { renderizarTudo(); return; }
  const modelo = document.createElement('div');
  modelo.innerHTML = htmlLinha(atualizado, 0).trim();
  const nova = modelo.firstElementChild;
  nova.classList.remove('animar-entrada');
  linhaAtual.replaceWith(nova);
  ligarLinha(atualizado);

  // Atualiza totais e contadores dos chips sem recriar a lista inteira.
  renderizarResumo();
  const base = filtrarSemSituacao();
  document.querySelectorAll('#filtro-situacao [data-situacao]').forEach(chip => {
    const s = SITUACOES.find(x => x.valor === chip.dataset.situacao);
    chip.querySelector('.chip__contador').textContent = base.filter(s.filtro).length;
  });
}
