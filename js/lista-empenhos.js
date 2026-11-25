/**
 * lista-empenhos.js
 * Aba "Lista da planilha" de empenhos.html: voluntários e valores de empenho do ano,
 * agrupados por lotação (na ordem do arquivo importado), com o total de
 * cada lotação e o total geral. Os totais acompanham os filtros.
 * Dados: api/lista-empenhos.php (importados por scripts/importar_empenhos.php).
 */

const LEMP_API = 'lista-empenhos.php';
let LEMP_LINHAS = [];

/* Valores chegam como "8400.00"; as contas são feitas em centavos para não perder precisão. */
const lempCentavos = (valor) => Math.round(Number(String(valor || '0').replace(',', '.')) * 100) || 0;
const LEMP_FORMATO = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const lempValor = (centavos) => LEMP_FORMATO.format(centavos / 100);
const lempChave = (t) => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();

document.addEventListener('DOMContentLoaded', async () => {
  const destino = document.getElementById('lemp-grupos');
  destino.innerHTML = `<div class="tabela-wrapper emp-grupo">${Array.from({ length: 5 }, () => '<p style="padding:.6rem 1.2rem;"><span class="skeleton" style="width:100%;"></span></p>').join('')}</div>`;

  try {
    const todas = (await apiFetch(LEMP_API)).dados;
    // Mostra o ano mais recente importado.
    const ano = todas.reduce((max, l) => (String(l.Ano) > max ? String(l.Ano) : max), '');
    LEMP_LINHAS = todas.filter(l => String(l.Ano) === ano).map(l => ({
      ...l,
      empenhado: lempCentavos(l['Total Empenhado']),
      pago: lempCentavos(l.Pago),
      anulado: lempCentavos(l.Anulado),
      saldo: lempCentavos(l.Saldo),
    }));
    document.getElementById('lemp-ano').textContent = ano;
  } catch (erro) {
    destino.innerHTML = `<div class="estado-vazio">${escapeHtml(erro.message || 'Não foi possível carregar a lista.')}</div>`;
    return;
  }

  const lotacoes = [...new Set(LEMP_LINHAS.map(l => l.Lotacao))];
  document.getElementById('lemp-lotacao').innerHTML = '<option value="">Todas as lotações</option>'
    + lotacoes.map(n => `<option value="${escapeHtml(n)}">${escapeHtml(n)}</option>`).join('');

  document.getElementById('lemp-busca').addEventListener('input', renderizarLista);
  document.getElementById('lemp-lotacao').addEventListener('change', renderizarLista);
  document.getElementById('lemp-situacao').addEventListener('change', renderizarLista);
  renderizarLista();
});

function somarLinhas(linhas) {
  return linhas.reduce((t, l) => ({
    empenhado: t.empenhado + l.empenhado,
    pago: t.pago + l.pago,
    anulado: t.anulado + l.anulado,
    saldo: t.saldo + l.saldo,
  }), { empenhado: 0, pago: 0, anulado: 0, saldo: 0 });
}

function renderizarLista() {
  const busca = lempChave(document.getElementById('lemp-busca').value);
  const lotacao = document.getElementById('lemp-lotacao').value;
  const situacao = document.getElementById('lemp-situacao').value;

  const visiveis = LEMP_LINHAS.filter(l =>
    (!lotacao || l.Lotacao === lotacao)
    && (!situacao || l.Situacao === situacao)
    && (!busca || lempChave(l.Nome).includes(busca)));

  // Agrupa mantendo a ordem do arquivo importado.
  const grupos = new Map();
  visiveis.forEach(l => {
    if (!grupos.has(l.Lotacao)) grupos.set(l.Lotacao, []);
    grupos.get(l.Lotacao).push(l);
  });

  const geral = somarLinhas(visiveis);
  document.getElementById('lemp-cards').innerHTML = [
    cardLista('Voluntários', String(visiveis.length), 'users', ''),
    cardLista('Total empenhado', `R$ ${lempValor(geral.empenhado)}`, 'file', ''),
    cardLista('Pago', `R$ ${lempValor(geral.pago)}`, 'checkCircle', 'card-stat--sucesso'),
    cardLista('Anulado', `R$ ${lempValor(geral.anulado)}`, 'xCircle', 'card-stat--perigo'),
    cardLista('Saldo', `R$ ${lempValor(geral.saldo)}`, 'grid', 'card-stat--alerta'),
  ].join('');

  const destino = document.getElementById('lemp-grupos');
  if (!LEMP_LINHAS.length) {
    destino.innerHTML = '<div class="estado-vazio">Nenhum empenho importado ainda. Rode <code>php scripts/importar_empenhos.php --executar</code>.</div>';
    return;
  }
  if (!visiveis.length) {
    destino.innerHTML = '<div class="estado-vazio">Nenhum voluntário encontrado com esses filtros.</div>';
    return;
  }

  destino.innerHTML = [...grupos.entries()].map(([nome, linhas]) => htmlGrupoLista(nome, linhas)).join('')
    + (grupos.size > 1 ? htmlTotalGeralLista(visiveis.length, geral) : '');
  hidratarIcones(destino);
}

function cardLista(rotulo, valor, icone, classe) {
  return `
    <div class="card-stat ${classe}">
      <div class="card-stat__icon">${icon(icone)}</div>
      <div>
        <div class="card-stat__valor emp-card-valor">${valor}</div>
        <div class="card-stat__label">${rotulo}</div>
      </div>
    </div>`;
}

function htmlLinhaLista(l, i) {
  const anulado = l.Situacao === 'Anulado';
  const nome = l['ID Voluntario']
    ? `<a class="emp-link" href="visualizar.html?id=${encodeURIComponent(l['ID Voluntario'])}">${escapeHtml(l.Nome)}</a>`
    : `<strong>${escapeHtml(l.Nome)}</strong>`;
  const empenhos = (l.Empenhos || [])
    .map(e => `<span><b>${escapeHtml(e.numero)}:</b> ${lempValor(lempCentavos(e.valor))}</span>`).join('');
  return `
    <tr class="${anulado ? 'emp-linha--anulada' : ''}">
      <td class="num">${i + 1}</td>
      <td class="emp-col-nome">${nome}</td>
      <td class="emp-col-empenhos">${empenhos || '—'}</td>
      <td class="num">${lempValor(l.empenhado)}</td>
      <td class="num">${lempValor(l.pago)}</td>
      <td class="num${l.anulado ? ' emp-anulado' : ''}">${lempValor(l.anulado)}</td>
      <td class="num emp-saldo"><strong>${lempValor(l.saldo)}</strong></td>
      <td><span class="badge ${anulado ? 'badge--perigo' : 'badge--sucesso'}">${escapeHtml(l.Situacao)}</span></td>
    </tr>`;
}

function htmlGrupoLista(nome, linhas) {
  const t = somarLinhas(linhas);
  return `
    <section class="tabela-wrapper emp-grupo">
      <h2 class="emp-grupo__titulo">${icon('mapPin')} ${escapeHtml(nome)}
        <span class="contador-resultados"><b>${linhas.length}</b> voluntário(s)</span>
      </h2>
      <div class="emp-rolagem">
        <table class="emp-tabela lemp-tabela">
          <thead><tr>
            <th class="num">Nº</th><th>Nome</th><th>Empenhos</th>
            <th class="num">Total empenhado</th><th class="num">Pago</th><th class="num">Anulado</th>
            <th class="num">Saldo</th><th>Situação</th>
          </tr></thead>
          <tbody>${linhas.map(htmlLinhaLista).join('')}</tbody>
          <tfoot><tr>
            <td></td><td colspan="2">Total da lotação (${linhas.length})</td>
            <td class="num">${lempValor(t.empenhado)}</td><td class="num">${lempValor(t.pago)}</td>
            <td class="num">${lempValor(t.anulado)}</td><td class="num">${lempValor(t.saldo)}</td><td></td>
          </tr></tfoot>
        </table>
      </div>
    </section>`;
}

function htmlTotalGeralLista(quantidade, t) {
  return `
    <section class="tabela-wrapper emp-grupo emp-grupo--geral">
      <h2 class="emp-grupo__titulo">${icon('grid')} Total geral
        <span class="contador-resultados"><b>${quantidade}</b> voluntário(s)</span>
      </h2>
      <div class="emp-rolagem">
        <table class="emp-tabela lemp-tabela">
          <thead><tr>
            <th class="num">Total empenhado</th><th class="num">Pago</th><th class="num">Anulado</th><th class="num">Saldo</th>
          </tr></thead>
          <tbody><tr>
            <td class="num"><strong>${lempValor(t.empenhado)}</strong></td><td class="num">${lempValor(t.pago)}</td>
            <td class="num">${lempValor(t.anulado)}</td><td class="num emp-saldo"><strong>${lempValor(t.saldo)}</strong></td>
          </tr></tbody>
        </table>
      </div>
    </section>`;
}
