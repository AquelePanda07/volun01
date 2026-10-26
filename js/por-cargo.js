/**
 * por-cargo.js
 * Página "Voluntários por Cargo": agrupa os voluntários pelo cargo/função,
 * com contagem por status em cada grupo, pesquisa e filtro de status.
 */

const SEM_CARGO = 'Sem cargo informado';
const STATUS_SINGULAR = { ATIVO: 'ativo', A_INICIAR: 'a iniciar', ENCERRADO: 'encerrado' };
const STATUS_PLURAL = { ATIVO: 'ativos', A_INICIAR: 'a iniciar', ENCERRADO: 'encerrados' };

let TODOS_VOLUNTARIOS = [];
// Grupos recolhidos pelo usuário (mantidos ao filtrar/pesquisar).
const GRUPOS_FECHADOS = new Set();

document.addEventListener('DOMContentLoaded', async () => {
  renderizarCarregando();

  try {
    TODOS_VOLUNTARIOS = (await listarVoluntarios()).map(v => ({
      ...v,
      status: calcularStatusContrato(v.dataInicio, v.dataTermino),
      diasRestantes: calcularDiasRestantes(v.dataTermino),
    }));
  } catch (erro) {
    showToast(erro.message || 'Não foi possível carregar os voluntários.', 'erro');
  }

  popularFiltroLotacao();
  renderizarGrupos();
  document.getElementById('filtro-busca').addEventListener('input', renderizarGrupos);
  document.getElementById('filtro-lotacao').addEventListener('change', renderizarGrupos);
  document.getElementById('filtro-status').addEventListener('change', renderizarGrupos);
  document.getElementById('btn-alternar-grupos').addEventListener('click', alternarTodosGrupos);
});

function renderizarCarregando() {
  document.getElementById('cargo-grupos').innerHTML = Array.from({ length: 2 }, () => `
    <div class="cargo-grupo"><div class="cargo-grupo__cabecalho">
      <span class="skeleton" style="width:44px;height:44px;border-radius:12px;"></span>
      <div style="flex:1;"><span class="skeleton" style="width:45%;margin-bottom:.4rem;"></span><span class="skeleton" style="width:25%;"></span></div>
    </div></div>
  `).join('');
}

const SEM_LOTACAO = '__sem_lotacao__';

/** Lotações (locais de prestação) existentes, em ordem alfabética, com a quantidade de voluntários. */
function popularFiltroLotacao() {
  const contagem = new Map();
  let semLotacao = 0;
  TODOS_VOLUNTARIOS.forEach(v => {
    const local = (v.localPrestacao || '').trim();
    if (local) contagem.set(local, (contagem.get(local) || 0) + 1);
    else semLotacao++;
  });
  const select = document.getElementById('filtro-lotacao');
  const opcoes = [...contagem.entries()]
    .sort((a, b) => a[0].localeCompare(b[0], 'pt-BR', { sensitivity: 'base' }))
    .map(([local, qtd]) => `<option value="${escapeHtml(local)}">${escapeHtml(local)} (${qtd})</option>`);
  if (semLotacao) opcoes.push(`<option value="${SEM_LOTACAO}">Sem lotação informada (${semLotacao})</option>`);
  select.insertAdjacentHTML('beforeend', opcoes.join(''));
}

function filtrar() {
  const termo = document.getElementById('filtro-busca').value.trim().toLowerCase();
  const status = document.getElementById('filtro-status').value;
  const lotacao = document.getElementById('filtro-lotacao').value;
  return TODOS_VOLUNTARIOS.filter(v => {
    if (status && v.status !== status) return false;
    if (lotacao) {
      const local = (v.localPrestacao || '').trim();
      if (lotacao === SEM_LOTACAO ? local !== '' : local !== lotacao) return false;
    }
    if (termo) {
      const alvo = [v.nome, v.cpf, v.localPrestacao, v.cargo].join(' ').toLowerCase();
      if (!alvo.includes(termo)) return false;
    }
    return true;
  });
}

/** Agrupa por cargo, do cargo com mais voluntários para o com menos. */
function agruparPorCargo(lista) {
  const grupos = new Map();
  lista.forEach(v => {
    const cargo = (v.cargo || '').trim() || SEM_CARGO;
    if (!grupos.has(cargo)) grupos.set(cargo, []);
    grupos.get(cargo).push(v);
  });
  const porNome = (a, b) => (a || '').localeCompare(b || '', 'pt-BR', { sensitivity: 'base' });
  grupos.forEach(membros => membros.sort((a, b) => porNome(a.nome, b.nome)));
  return [...grupos.entries()].sort((a, b) => b[1].length - a[1].length || porNome(a[0], b[0]));
}

function renderizarGrupos() {
  const lista = filtrar();
  const grupos = agruparPorCargo(lista);
  const container = document.getElementById('cargo-grupos');
  const estadoVazio = document.getElementById('estado-vazio');

  document.getElementById('cargo-resumo').innerHTML = TODOS_VOLUNTARIOS.length
    ? `<b>${grupos.length}</b> cargo(s) · <b>${lista.length}</b> de <b>${TODOS_VOLUNTARIOS.length}</b> voluntário(s)`
    : '';

  estadoVazio.hidden = grupos.length > 0;
  if (grupos.length === 0) {
    container.innerHTML = '';
    document.getElementById('estado-vazio-texto').textContent = TODOS_VOLUNTARIOS.length === 0
      ? 'Nenhum voluntário cadastrado ainda.'
      : 'Nenhum voluntário encontrado para os filtros aplicados.';
    atualizarBotaoAlternar();
    return;
  }

  container.innerHTML = grupos.map(([cargo, membros], i) => {
    const contar = s => membros.filter(v => v.status === s).length;
    const resumoStatus = ['ATIVO', 'A_INICIAR', 'ENCERRADO']
      .filter(s => contar(s) > 0)
      .map(s => `<span class="badge ${STATUS_BADGE[s]}">${contar(s)} ${(contar(s) === 1 ? STATUS_SINGULAR : STATUS_PLURAL)[s]}</span>`)
      .join('');

    return `
      <details class="cargo-grupo animar-entrada" style="--i:${i}" data-cargo="${escapeHtml(cargo)}" ${GRUPOS_FECHADOS.has(cargo) ? '' : 'open'}>
        <summary class="cargo-grupo__cabecalho">
          <span class="cargo-grupo__icone">${icon('briefcase')}</span>
          <div class="cargo-grupo__titulo">
            <h2>${escapeHtml(cargo)}</h2>
            <div class="cargo-grupo__status">${resumoStatus}</div>
          </div>
          <span class="cargo-grupo__total">${membros.length}</span>
          <span class="cargo-grupo__seta">${icon('arrowRight')}</span>
        </summary>
        <ul class="cargo-grupo__lista">
          ${membros.map(v => `
            <li>
              <a class="cargo-membro" href="visualizar.html?id=${encodeURIComponent(v.id)}">
                <img class="avatar-mini" src="${fotoOuAvatar(v)}" alt="">
                <div class="cargo-membro__info">
                  <strong>${escapeHtml(v.nome)}${seloRenovado(v)}${seloTermos(v)}</strong>
                  <span>${icon('mapPin')} ${escapeHtml(v.localPrestacao || '-')}</span>
                </div>
                <span class="cargo-membro__prazo">${v.dataTermino ? `${v.status === 'ENCERRADO' ? 'Encerrou em' : 'Termina em'} ${formatarDataBR(v.dataTermino)}` : ''}</span>
                ${badgeStatus(v.status)}
              </a>
            </li>
          `).join('')}
        </ul>
      </details>
    `;
  }).join('');

  container.querySelectorAll('details').forEach(d => {
    d.addEventListener('toggle', () => {
      d.open ? GRUPOS_FECHADOS.delete(d.dataset.cargo) : GRUPOS_FECHADOS.add(d.dataset.cargo);
      atualizarBotaoAlternar();
    });
  });
  atualizarBotaoAlternar();
}

/** "Recolher todos" quando algum grupo está aberto; senão "Expandir todos". */
function alternarTodosGrupos() {
  const grupos = [...document.querySelectorAll('.cargo-grupo[data-cargo]')];
  const abrir = grupos.every(d => !d.open);
  grupos.forEach(d => { d.open = abrir; });
}

function atualizarBotaoAlternar() {
  const grupos = [...document.querySelectorAll('.cargo-grupo[data-cargo]')];
  const botao = document.getElementById('btn-alternar-grupos');
  botao.disabled = grupos.length === 0;
  document.getElementById('btn-alternar-grupos-texto').textContent =
    grupos.length && grupos.every(d => !d.open) ? 'Expandir todos' : 'Recolher todos';
}
