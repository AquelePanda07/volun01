/**
 * painel.js
 * Página inicial (index.html): saudação, contadores de contratos,
 * contratos que terminam nos próximos dias e distribuição por status/local.
 */

// Até quantos dias antes do término o prazo aparece destacado em âmbar/vermelho.
const DIAS_ALERTA_PRAZO = 30;
const DIAS_URGENTE = 7;

document.addEventListener('DOMContentLoaded', async () => {
  renderizarSaudacao();
  renderizarCarregando();

  let voluntarios = [];
  try {
    voluntarios = await listarVoluntarios();
  } catch (erro) {
    showToast(erro.message || 'Não foi possível carregar os dados do painel.', 'erro');
  }

  const comStatus = voluntarios.map(v => ({
    ...v,
    status: calcularStatusContrato(v.dataInicio, v.dataTermino),
    diasRestantes: calcularDiasRestantes(v.dataTermino),
  }));

  renderizarCards(comStatus);
  renderizarPrazos(comStatus);
  renderizarDistribuicao(comStatus);
});

function renderizarSaudacao() {
  const agora = new Date();
  const hora = agora.getHours();
  const saudacao = hora < 12 ? 'Bom dia' : (hora < 18 ? 'Boa tarde' : 'Boa noite');
  document.getElementById('hero-saudacao').textContent = `${saudacao}!`;
  const data = agora.toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  document.getElementById('hero-data').textContent = data.charAt(0).toUpperCase() + data.slice(1);
}

function renderizarCarregando() {
  document.getElementById('cards-estatisticas').innerHTML = Array.from({ length: 4 }, () => `
    <div class="card-stat">
      <span class="skeleton skeleton--circulo" style="width:50px;height:50px;border-radius:14px;"></span>
      <div style="flex:1;"><span class="skeleton" style="width:40%;height:1.6rem;margin-bottom:.4rem;"></span><span class="skeleton" style="width:75%;"></span></div>
    </div>
  `).join('');
  document.getElementById('lista-prazos').innerHTML = Array.from({ length: 3 }, () => `
    <li class="prazo-item"><span class="skeleton skeleton--circulo"></span><div style="flex:1;"><span class="skeleton" style="width:55%;margin-bottom:.4rem;"></span><span class="skeleton" style="width:35%;"></span></div></li>
  `).join('');
}

function renderizarCards(voluntarios) {
  const total = voluntarios.length;
  const contar = status => voluntarios.filter(v => v.status === status).length;
  const percentual = n => total ? `${Math.round((n / total) * 100)}% do total` : '';

  const cards = [
    { valor: total, label: 'Voluntários cadastrados', icone: 'users', classe: '', filtro: '' },
    { valor: contar('ATIVO'), label: 'Contratos ativos', icone: 'checkCircle', classe: 'card-stat--sucesso', filtro: 'ATIVO' },
    { valor: contar('A_INICIAR'), label: 'A iniciar', icone: 'clock', classe: 'card-stat--alerta', filtro: 'A_INICIAR' },
    { valor: contar('ENCERRADO'), label: 'Contratos encerrados', icone: 'xCircle', classe: 'card-stat--perigo', filtro: 'ENCERRADO' },
  ];

  const container = document.getElementById('cards-estatisticas');
  container.innerHTML = cards.map((c, i) => `
    <a class="card-stat ${c.classe} animar-entrada" style="--i:${i}" href="voluntarios.html${c.filtro ? `?status=${c.filtro}` : ''}">
      <div class="card-stat__icon">${icon(c.icone)}</div>
      <div>
        <div class="card-stat__valor" data-contar="${c.valor}">0</div>
        <div class="card-stat__label">${c.label}</div>
        ${c.filtro ? `<div class="card-stat__extra">${percentual(c.valor)}</div>` : ''}
      </div>
    </a>
  `).join('');

  container.querySelectorAll('[data-contar]').forEach(el => animarContador(el, Number(el.dataset.contar)));
}

/** Conta de 0 até o valor final em ~0,8s. */
function animarContador(el, valorFinal) {
  if (valorFinal === 0 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    el.textContent = valorFinal;
    return;
  }
  const duracao = 800;
  const inicio = performance.now();
  function passo(agora) {
    const t = Math.min(1, (agora - inicio) / duracao);
    const suavizado = 1 - Math.pow(1 - t, 3);
    el.textContent = Math.round(valorFinal * suavizado);
    if (t < 1) requestAnimationFrame(passo);
  }
  requestAnimationFrame(passo);
}

function renderizarPrazos(voluntarios) {
  const proximos = voluntarios
    .filter(v => v.status === 'ATIVO' && v.diasRestantes !== null)
    .sort((a, b) => a.diasRestantes - b.diasRestantes)
    .slice(0, 6);

  const lista = document.getElementById('lista-prazos');
  if (proximos.length === 0) {
    lista.innerHTML = '<li class="painel__vazio">Nenhum contrato ativo no momento.</li>';
    return;
  }

  lista.innerHTML = proximos.map((v, i) => `
    <li class="animar-entrada" style="--i:${i}">
      <a class="prazo-item" href="visualizar.html?id=${encodeURIComponent(v.id)}">
        <img class="avatar-mini" src="${fotoOuAvatar(v)}" alt="">
        <div class="prazo-item__info">
          <strong>${escapeHtml(v.nome)}${seloRenovado(v)}${seloTermos(v)}</strong>
          <span>${escapeHtml(v.localPrestacao || '-')} · termina em ${formatarDataBR(v.dataTermino)}</span>
        </div>
        <span class="pill-dias ${classePrazo(v.diasRestantes)}">${escapeHtml(formatarDiasRestantes(v.diasRestantes, v.status))}</span>
      </a>
    </li>
  `).join('');
}

function classePrazo(dias) {
  if (dias <= DIAS_URGENTE) return 'pill-dias--urgente';
  return dias <= DIAS_ALERTA_PRAZO ? '' : 'pill-dias--tranquilo';
}

function renderizarDistribuicao(voluntarios) {
  const total = voluntarios.length;
  const partes = [
    { status: 'ATIVO', classe: 'sucesso', cor: 'var(--cor-sucesso)' },
    { status: 'A_INICIAR', classe: 'alerta', cor: 'var(--cor-alerta)' },
    { status: 'ENCERRADO', classe: 'perigo', cor: 'var(--cor-perigo)' },
  ].map(p => ({ ...p, qtd: voluntarios.filter(v => v.status === p.status).length }));

  const barra = document.getElementById('barra-status');
  barra.innerHTML = partes.map(p => `<div class="barra-status__parte barra-status__parte--${p.classe}" data-largura="${total ? (p.qtd / total) * 100 : 0}" title="${STATUS_LABEL[p.status]}: ${p.qtd}"></div>`).join('');

  document.getElementById('legenda-status').innerHTML = partes.map(p =>
    `<span><i style="background:${p.cor}"></i>${STATUS_LABEL[p.status]} (${p.qtd})</span>`
  ).join('');

  // Locais com mais voluntários em contrato ativo
  const porLocal = {};
  voluntarios.filter(v => v.status === 'ATIVO').forEach(v => {
    const local = v.localPrestacao || 'Não informado';
    porLocal[local] = (porLocal[local] || 0) + 1;
  });
  const ranking = Object.entries(porLocal).sort((a, b) => b[1] - a[1]).slice(0, 5);
  const maior = ranking.length ? ranking[0][1] : 0;

  document.getElementById('ranking-locais').innerHTML = `
    <p class="ranking__titulo">Ativos por local de prestação</p>
    ${ranking.length === 0 ? '<p class="painel__vazio">Nenhum contrato ativo no momento.</p>' : ranking.map(([local, qtd]) => `
      <div class="ranking__linha">
        <span class="ranking__nome" title="${escapeHtml(local)}">${escapeHtml(local)}</span>
        <span class="ranking__valor">${qtd}</span>
        <div class="ranking__barra"><i data-largura="${(qtd / maior) * 100}"></i></div>
      </div>
    `).join('')}
  `;

  // Anima as barras crescendo a partir de 0
  requestAnimationFrame(() => requestAnimationFrame(() => {
    document.querySelectorAll('[data-largura]').forEach(el => { el.style.width = `${el.dataset.largura}%`; });
  }));
}
