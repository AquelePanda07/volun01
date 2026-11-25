/**
 * empenhos.js
 * Página "Controle de Empenhos":
 *   - Painel anual (substitui a planilha): por lotação, um voluntário por
 *     linha com os empenhos, jan…dez, total empenhado/pago/anulado e saldo.
 *   - Lançamento mensal em lote: competência + lotação -> lista dos
 *     voluntários ativos com dias, valor sugerido e a divisão entre
 *     empenhos já calculada; o usuário revisa e confirma tudo de uma vez.
 * O extrato do voluntário (modal) fica em extrato-empenhos.js.
 */

const CHAVE_FILTROS = 'sgv_empenhos_filtros';
let RESUMO = null;
let LOCAIS_EMP = [];
let ITENS_LOTE = [];
let COMPETENCIA_LOTE = '';

function lerFiltrosSalvos() {
  try { return JSON.parse(localStorage.getItem(CHAVE_FILTROS)) || {}; } catch (e) { return {}; }
}
function salvarFiltros(f) {
  try { localStorage.setItem(CHAVE_FILTROS, JSON.stringify(f)); } catch (e) { /* só conveniência */ }
}

document.addEventListener('DOMContentLoaded', async () => {
  const salvos = lerFiltrosSalvos();
  let parametros = { anos: [new Date().getFullYear()] };
  try {
    [parametros, LOCAIS_EMP] = await Promise.all([
      parametrosEmpenho(),
      apiFetch('locais.php').then(r => r.dados.map(l => l.nome)),
    ]);
  } catch (erro) {
    showToast(erro.message || 'Não foi possível carregar os dados.', 'erro');
  }

  // Filtros do painel
  const selAno = document.getElementById('filtro-ano');
  selAno.innerHTML = parametros.anos.map(a => `<option value="${a}">${a}</option>`).join('');
  if (salvos.ano && parametros.anos.includes(Number(salvos.ano))) selAno.value = salvos.ano;
  preencherLotacoes(document.getElementById('filtro-lotacao'), LOCAIS_EMP, 'Todas as lotações');
  document.getElementById('filtro-lotacao').value = salvos.lotacao || '';

  selAno.addEventListener('change', carregarPainel);
  document.getElementById('filtro-lotacao').addEventListener('change', () => { salvarFiltrosAtuais(); renderizarPainel(); atualizarLinksExportacao(); });
  document.getElementById('filtro-busca').addEventListener('input', renderizarPainel);
  document.getElementById('filtro-pendencias').addEventListener('change', renderizarPainel);

  // Lote
  const hoje = new Date();
  document.getElementById('lote-mes').innerHTML = MESES_LONGOS.map((m, i) => `<option value="${String(i + 1).padStart(2, '0')}">${m}</option>`).join('');
  document.getElementById('lote-mes').value = String(hoje.getMonth() + 1).padStart(2, '0');
  document.getElementById('lote-ano').value = hoje.getFullYear();
  preencherLotacoes(document.getElementById('lote-lotacao'), LOCAIS_EMP, 'Todas as lotações');
  document.getElementById('lote-lotacao').value = salvos.lotacaoLote || '';
  document.getElementById('btn-carregar-lote').addEventListener('click', carregarLote);
  document.getElementById('btn-confirmar-lote').addEventListener('click', confirmarLote);

  // Abas
  document.querySelectorAll('[data-aba]').forEach(b => b.addEventListener('click', () => trocarAba(b.dataset.aba)));
  if (location.hash === '#lote' || location.hash === '#lista') trocarAba(location.hash.slice(1));

  await carregarPainel();

  // Atalho: empenhos.html?extrato=12 abre o extrato direto.
  const idExtrato = new URLSearchParams(location.search).get('extrato');
  if (idExtrato) abrirExtratoEmpenhos(idExtrato, { aoAtualizar: carregarPainel });
});

function preencherLotacoes(select, nomes, textoTodos) {
  const unicos = [...new Set(nomes.map(n => String(n || '').trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'pt-BR'));
  select.innerHTML = `<option value="">${textoTodos}</option>` + unicos.map(n => `<option value="${escapeHtml(n)}">${escapeHtml(n)}</option>`).join('');
}

function salvarFiltrosAtuais() {
  salvarFiltros({
    ano: document.getElementById('filtro-ano').value,
    lotacao: document.getElementById('filtro-lotacao').value,
    lotacaoLote: document.getElementById('lote-lotacao').value,
  });
}

function trocarAba(aba) {
  document.querySelectorAll('[data-aba]').forEach(b => {
    const ativa = b.dataset.aba === aba;
    b.classList.toggle('chip--ativo', ativa);
    b.setAttribute('aria-selected', String(ativa));
  });
  document.getElementById('aba-painel').hidden = aba !== 'painel';
  document.getElementById('aba-lote').hidden = aba !== 'lote';
  document.getElementById('aba-lista').hidden = aba !== 'lista'; // js/lista-empenhos.js
  document.getElementById('acoes-painel').hidden = aba !== 'painel';
  history.replaceState(null, '', aba === 'painel' ? location.pathname + location.search : `#${aba}`);
}

/* ================================================================== */
/* Painel anual                                                         */
/* ================================================================== */
async function carregarPainel() {
  salvarFiltrosAtuais();
  atualizarLinksExportacao();
  const ano = document.getElementById('filtro-ano').value || new Date().getFullYear();
  const destino = document.getElementById('emp-grupos');
  if (!RESUMO) {
    destino.innerHTML = `<div class="tabela-wrapper emp-grupo">${Array.from({ length: 5 }, () => '<p style="padding:.6rem 1.2rem;"><span class="skeleton" style="width:100%;"></span></p>').join('')}</div>`;
  }
  try {
    RESUMO = (await apiFetch(`${EMP_API}?acao=resumo&ano=${encodeURIComponent(ano)}`)).dados;
    // Lotações que só existem nos empenhos também entram no filtro.
    const filtro = document.getElementById('filtro-lotacao');
    const atual = filtro.value;
    preencherLotacoes(filtro, [...LOCAIS_EMP, ...RESUMO.grupos.map(g => g.lotacao)], 'Todas as lotações');
    filtro.value = atual;
  } catch (erro) {
    destino.innerHTML = `<div class="estado-vazio">${escapeHtml(erro.message || 'Não foi possível carregar o painel.')}</div>`;
    return;
  }
  renderizarPainel();
}

function atualizarLinksExportacao() {
  const ano = document.getElementById('filtro-ano').value || new Date().getFullYear();
  const lotacao = document.getElementById('filtro-lotacao').value;
  const base = `${API_BASE}${EMP_API}?acao=exportar&ano=${encodeURIComponent(ano)}&lotacao=${encodeURIComponent(lotacao)}`;
  document.getElementById('btn-exportar-xlsx').href = `${base}&formato=xlsx`;
  document.getElementById('btn-exportar-csv').href = `${base}&formato=csv`;
}

const chaveTexto = (t) => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

function renderizarPainel() {
  if (!RESUMO) return;
  const lotacao = chaveTexto(document.getElementById('filtro-lotacao').value);
  const busca = chaveTexto(document.getElementById('filtro-busca').value);
  const soPendencias = document.getElementById('filtro-pendencias').checked;

  const grupos = RESUMO.grupos
    .filter(g => !lotacao || chaveTexto(g.lotacao) === lotacao)
    .map(g => ({
      ...g,
      linhasVisiveis: g.linhas.filter(l =>
        (!soPendencias || l.situacao === 'SALDO_A_ANULAR')
        && (!busca || chaveTexto(l.nome).includes(busca) || l.empenhos.some(e => chaveTexto(e.numero).includes(busca)))),
    }))
    .filter(g => g.linhasVisiveis.length);

  // Totais do que está visível na lotação escolhida (o filtro de texto não muda os totais).
  const gruposDaLotacao = RESUMO.grupos.filter(g => !lotacao || chaveTexto(g.lotacao) === lotacao);
  const soma = (campo) => gruposDaLotacao.reduce((s, g) => s + centavosDe(g.total[campo]), 0);
  const pendentes = gruposDaLotacao.flatMap(g => g.linhas).filter(l => l.situacao === 'SALDO_A_ANULAR');
  const saldoPendente = pendentes.reduce((s, l) => s + centavosDe(l.saldo), 0);
  document.getElementById('emp-cards').innerHTML = [
    card('Total empenhado', moedaBR(reaisDe(soma('empenhado'))), 'file', ''),
    card('Total pago', moedaBR(reaisDe(soma('pago'))), 'checkCircle', 'card-stat--sucesso'),
    card('Anulado', moedaBR(reaisDe(soma('anulado'))), 'xCircle', 'card-stat--perigo'),
    card('Saldo', moedaBR(reaisDe(soma('saldo'))), 'grid', ''),
    card('Saldo a anular', moedaBR(reaisDe(saldoPendente)), 'alert', 'card-stat--alerta', `${pendentes.length} voluntário(s)`),
  ].join('');

  const destino = document.getElementById('emp-grupos');
  if (!grupos.length) {
    destino.innerHTML = `<div class="estado-vazio">${RESUMO.grupos.length
      ? 'Nenhum voluntário encontrado com esses filtros.'
      : `Nenhum empenho cadastrado em ${RESUMO.ano}. Abra o extrato de um voluntário (tela do voluntário → "Empenhos") para cadastrar o primeiro.`}</div>`;
    return;
  }

  destino.innerHTML = grupos.map(htmlGrupo).join('') + (lotacao ? '' : htmlTotalGeral());
  hidratarIcones(destino);
  destino.querySelectorAll('[data-extrato]').forEach(linha => {
    const abrir = () => abrirExtratoEmpenhos(linha.dataset.extrato, { aoAtualizar: carregarPainel });
    linha.addEventListener('click', abrir);
    linha.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); abrir(); } });
  });
}

function card(rotulo, valor, icone, classe, extra = '') {
  return `
    <div class="card-stat ${classe}">
      <div class="card-stat__icon">${icon(icone)}</div>
      <div>
        <div class="card-stat__valor emp-card-valor">${valor}</div>
        <div class="card-stat__label">${rotulo}</div>
        ${extra ? `<div class="card-stat__extra">${extra}</div>` : ''}
      </div>
    </div>`;
}

function celulaMes(mes) {
  const pago = centavosDe(mes.pago);
  const anulado = centavosDe(mes.anulado);
  if (!pago && !anulado) return '<td class="num emp-mes emp-mes--vazio">—</td>';
  const partes = [];
  if (pago) partes.push(`<span>${numeroBR(mes.pago)}</span>`);
  if (anulado) partes.push(`<span class="emp-anulado" title="Anulação">-${numeroBR(mes.anulado)}</span>`);
  return `<td class="num emp-mes">${partes.join('')}</td>`;
}

function htmlCabecalhoTabela() {
  return `<tr>
    <th class="emp-col-nome">Voluntário</th><th>Empenhos</th>
    ${MESES_CURTOS.map(m => `<th class="num">${m}</th>`).join('')}
    <th class="num">Empenhado</th><th class="num">Pago</th><th class="num">Anulado</th><th class="num">Saldo</th>
  </tr>`;
}

function htmlLinhaTotal(rotulo, t) {
  return `<tr>
    <td class="emp-col-nome">${escapeHtml(rotulo)}</td><td></td>
    ${t.meses ? Object.values(t.meses).map(celulaMes).join('') : ''}
    <td class="num">${numeroBR(t.empenhado)}</td><td class="num">${numeroBR(t.pago)}</td>
    <td class="num ${centavosDe(t.anulado) ? 'emp-anulado' : ''}">${centavosDe(t.anulado) ? '-' + numeroBR(t.anulado) : '—'}</td>
    <td class="num"><strong>${numeroBR(t.saldo)}</strong></td>
  </tr>`;
}

function htmlGrupo(g) {
  const linhas = g.linhasVisiveis.map(l => {
    const situacao = SITUACAO_EMPENHO[l.situacao];
    const classe = l.situacao === 'ANULADO' ? 'emp-linha--anulada' : (l.situacao === 'SALDO_A_ANULAR' ? 'emp-linha--a-anular' : '');
    return `
      <tr class="linha-clicavel ${classe}" data-extrato="${l.idVoluntario}" tabindex="0" title="Abrir extrato">
        <td class="emp-col-nome"><strong>${escapeHtml(l.nome)}</strong>${situacao ? `<span class="badge ${situacao.classe}">${situacao.texto}</span>` : ''}</td>
        <td class="emp-col-empenhos">${l.empenhos.map(e => `<span title="${e.tipo === 'ORIGINAL' ? 'Original' : 'Reforço'} · saldo ${moedaBR(e.saldo)}"><b>${escapeHtml(e.numero)}</b> ${numeroBR(e.valor)}</span>`).join('')}</td>
        ${Object.values(l.meses).map(celulaMes).join('')}
        <td class="num">${numeroBR(l.empenhado)}</td>
        <td class="num">${numeroBR(l.pago)}</td>
        <td class="num ${centavosDe(l.anulado) ? 'emp-anulado' : ''}">${centavosDe(l.anulado) ? '-' + numeroBR(l.anulado) : '—'}</td>
        <td class="num emp-saldo"><strong>${numeroBR(l.saldo)}</strong></td>
      </tr>`;
  }).join('');

  return `
    <section class="tabela-wrapper emp-grupo">
      <h2 class="emp-grupo__titulo">${icon('mapPin')} ${escapeHtml(g.lotacao)} <span class="contador-resultados"><b>${g.linhas.length}</b> voluntário(s)</span></h2>
      <div class="emp-rolagem">
        <table class="emp-tabela emp-tabela--painel">
          <thead>${htmlCabecalhoTabela()}</thead>
          <tbody>${linhas}</tbody>
          <tfoot>${htmlLinhaTotal(`Total da lotação (${g.linhas.length})`, g.total)}</tfoot>
        </table>
      </div>
    </section>`;
}

function htmlTotalGeral() {
  return `
    <section class="tabela-wrapper emp-grupo emp-grupo--geral">
      <h2 class="emp-grupo__titulo">${icon('grid')} Total geral ${RESUMO.ano}</h2>
      <div class="emp-rolagem">
        <table class="emp-tabela emp-tabela--painel">
          <thead>${htmlCabecalhoTabela()}</thead>
          <tfoot>${htmlLinhaTotal('Todas as lotações', RESUMO.total)}</tfoot>
        </table>
      </div>
    </section>`;
}

/* ================================================================== */
/* Lançamento mensal em lote                                            */
/* ================================================================== */
async function carregarLote() {
  salvarFiltrosAtuais();
  const ano = document.getElementById('lote-ano').value.trim();
  COMPETENCIA_LOTE = `${ano}-${document.getElementById('lote-mes').value}`;
  const lotacao = document.getElementById('lote-lotacao').value;
  const destino = document.getElementById('lote-conteudo');
  destino.innerHTML = `<p class="texto-suave">${icon('refresh', 'girando')} Carregando...</p>`;
  document.getElementById('lote-barra').hidden = true;
  try {
    ITENS_LOTE = (await apiFetch(`${EMP_API}?acao=lote&competencia=${encodeURIComponent(COMPETENCIA_LOTE)}&lotacao=${encodeURIComponent(lotacao)}`)).dados;
  } catch (erro) {
    destino.innerHTML = `<div class="estado-vazio">${escapeHtml(erro.message || 'Não foi possível carregar.')}</div>`;
    return;
  }
  if (!ITENS_LOTE.length) {
    destino.innerHTML = '<div class="estado-vazio">Nenhum voluntário ativo com contrato nessa competência e lotação.</div>';
    return;
  }
  // Marcados por padrão: quem ainda não recebeu nessa competência e tem saldo para o
  // valor sugerido. Os demais podem ser marcados depois de ajustar o valor.
  ITENS_LOTE.forEach(i => {
    i.selecionado = i.empenhos.length > 0 && centavosDe(i.jaLancado) === 0 && centavosDe(i.faltando) === 0;
    i.partes = Object.fromEntries(i.distribuicao.map(p => [p.idEmpenho, centavosDe(p.valor)]));
  });
  renderizarLote();
}

function renderizarLote() {
  const destino = document.getElementById('lote-conteudo');
  const passo = PARAMETROS_EMPENHO?.passoDias || 0.5;
  // Um bloco por lotação, como na "Lista da planilha" (js/lista-empenhos.js).
  destino.innerHTML = gruposLote().map(([nome, idxs]) => `
    <section class="tabela-wrapper emp-grupo" data-grupo-lote="${idxs.join(',')}">
      <h2 class="emp-grupo__titulo">${icon('mapPin')} ${escapeHtml(nome)}
        <span class="contador-resultados"><b>${idxs.length}</b> voluntário(s)</span>
      </h2>
      <div class="emp-rolagem">
        <table class="emp-tabela emp-tabela--lote">
          <thead><tr>
            <th class="col-selecao"><input type="checkbox" data-todos-grupo aria-label="Selecionar todos de ${escapeHtml(nome)}"></th>
            <th>Voluntário</th><th class="num">Dias</th><th class="num">Valor (R$)</th><th>Sai do empenho</th><th>Situação</th>
          </tr></thead>
          <tbody>${idxs.map(idx => htmlLinhaLote(ITENS_LOTE[idx], idx, passo)).join('')}</tbody>
          <tfoot><tr>
            <td></td><td colspan="2">Total da lotação</td>
            <td class="num" data-total-grupo></td><td colspan="2" data-resumo-grupo></td>
          </tr></tfoot>
        </table>
      </div>
    </section>`).join('');
  hidratarIcones(destino);

  destino.querySelectorAll('[data-grupo-lote]').forEach(secao => {
    const itens = secao.dataset.grupoLote.split(',').map(Number).map(idx => ITENS_LOTE[idx]);
    secao.querySelector('[data-todos-grupo]').addEventListener('change', (e) => {
      itens.forEach(i => { if (i.empenhos.length) i.selecionado = e.target.checked; });
      renderizarLote();
    });
  });

  destino.querySelectorAll('tr[data-idx]').forEach(tr => {
    const item = ITENS_LOTE[Number(tr.dataset.idx)];
    const sel = tr.querySelector('[data-campo="selecionado"]');
    const dias = tr.querySelector('[data-campo="dias"]');
    const valor = tr.querySelector('[data-campo="valor"]');
    sel?.addEventListener('change', () => { item.selecionado = sel.checked; atualizarLinhaLote(tr, item); atualizarResumoLote(); });
    dias.addEventListener('input', () => {
      const d = Number(String(dias.value).replace(',', '.'));
      item.dias = dias.value;
      if (centavosDe(item.valorDia) && d >= 0) {
        item.valor = reaisDe(Math.round(centavosDe(item.valorDia) * d));
        valor.value = numeroBR(item.valor);
        redistribuirItem(tr, item);
      }
    });
    valor.addEventListener('input', () => {
      item.valor = reaisDe(centavosDe(valor.value) || 0);
      redistribuirItem(tr, item);
    });
    tr.querySelectorAll('[data-parte]').forEach(c => c.addEventListener('input', () => {
      item.partes[c.dataset.parte] = centavosDe(c.value) || 0;
      atualizarLinhaLote(tr, item);
      atualizarResumoLote();
    }));
    tr.querySelector('[data-extrato]').addEventListener('click', () => abrirExtratoEmpenhos(item.idVoluntario, { aoAtualizar: () => { carregarLote(); carregarPainel(); } }));
    atualizarLinhaLote(tr, item);
  });
  document.getElementById('lote-barra').hidden = false;
  atualizarResumoLote();
}

/** [[lotação, [índices em ITENS_LOTE]], ...] em ordem alfabética de lotação. */
function gruposLote() {
  const grupos = new Map();
  ITENS_LOTE.forEach((item, idx) => {
    const nome = String(item.lotacao || '').trim() || 'Sem lotação';
    if (!grupos.has(nome)) grupos.set(nome, []);
    grupos.get(nome).push(idx);
  });
  return [...grupos.entries()].sort((a, b) => a[0].localeCompare(b[0], 'pt-BR'));
}

function htmlLinhaLote(i, idx, passo) {
  const semSaldo = !i.empenhos.length;
  return `
    <tr data-idx="${idx}" class="${semSaldo ? 'emp-linha--sem-saldo' : ''}">
      <td class="col-selecao">${semSaldo ? '' : `<input type="checkbox" data-campo="selecionado" ${i.selecionado ? 'checked' : ''} aria-label="Lançar para ${escapeHtml(i.nome)}">`}</td>
      <td>
        <button type="button" class="emp-link" data-extrato="${i.idVoluntario}">${escapeHtml(i.nome)}</button>
        <span class="emp-sub">${i.cargaHoraria ? `${escapeHtml(i.cargaHoraria)}h · ${moedaBR(i.valorDia)}/dia` : 'sem carga horária'} · saldo ${moedaBR(i.saldo)}</span>
      </td>
      <td class="num"><input class="emp-input emp-input--dias" type="number" min="0" max="31" step="${passo}" data-campo="dias" value="${escapeHtml(String(i.dias))}" aria-label="Dias trabalhados"></td>
      <td class="num"><input class="emp-input" inputmode="decimal" data-campo="valor" value="${numeroBR(i.valor)}" aria-label="Valor"></td>
      <td class="emp-col-dist">${semSaldo ? '<span class="texto-suave">Sem empenho com saldo</span>' : i.empenhos.map(e => `
        <label class="emp-parte"><span><b>${escapeHtml(e.numero)}</b> <small>saldo ${numeroBR(e.saldo)}</small></span>
          <input class="emp-input" inputmode="decimal" data-parte="${e.id}" value="${i.partes[e.id] ? numeroBR(reaisDe(i.partes[e.id])) : ''}" aria-label="Valor do empenho ${escapeHtml(e.numero)}"></label>`).join('')}
      </td>
      <td class="emp-col-situacao" data-situacao></td>
    </tr>`;
}

function redistribuirItem(tr, item) {
  const { partes } = distribuirPagamento(item.empenhos, centavosDe(item.valor) || 0);
  item.partes = Object.fromEntries(partes.map(p => [p.idEmpenho, p.valor]));
  tr.querySelectorAll('[data-parte]').forEach(c => {
    const v = item.partes[c.dataset.parte];
    c.value = v ? numeroBR(reaisDe(v)) : '';
  });
  atualizarLinhaLote(tr, item);
  atualizarResumoLote();
}

/** Problema da linha (ou '' se está ok). */
function problemaItem(item) {
  const total = centavosDe(item.valor) || 0;
  const distribuido = Object.values(item.partes).reduce((s, v) => s + v, 0);
  if (total <= 0) return 'Informe o valor';
  const saldo = centavosDe(item.saldo);
  if (total > saldo) return `Saldo insuficiente: faltam ${moedaBR(reaisDe(total - saldo))}`;
  for (const e of item.empenhos) {
    if ((item.partes[e.id] || 0) > centavosDe(e.saldo)) return `Empenho ${e.numero} acima do saldo`;
  }
  if (distribuido !== total) return `Distribuído ${moedaBR(reaisDe(distribuido))} de ${moedaBR(reaisDe(total))}`;
  return '';
}

function atualizarLinhaLote(tr, item) {
  const celula = tr.querySelector('[data-situacao]');
  const avisos = [];
  if (centavosDe(item.jaLancado) > 0) avisos.push(`<span class="badge badge--info">Já lançado ${moedaBR(item.jaLancado)}</span>`);
  if (item.empenhos.length && item.selecionado) {
    const problema = problemaItem(item);
    avisos.push(problema ? `<span class="badge badge--perigo">${escapeHtml(problema)}</span>` : '<span class="badge badge--sucesso">Pronto</span>');
  } else if (!item.empenhos.length) {
    avisos.push('<span class="badge badge--alerta">Cadastre um reforço</span>');
  }
  celula.innerHTML = avisos.join(' ');
  tr.classList.toggle('emp-linha--desmarcada', !item.selecionado);
}

function atualizarResumoLote() {
  const selecionados = ITENS_LOTE.filter(i => i.selecionado);
  const total = selecionados.reduce((s, i) => s + (centavosDe(i.valor) || 0), 0);
  const comProblema = selecionados.filter(i => problemaItem(i)).length;
  document.getElementById('lote-resumo').innerHTML = `${competenciaBR(COMPETENCIA_LOTE)} · ` + (selecionados.length
    ? `<b>${selecionados.length}</b> voluntário(s) · total <b>${moedaBR(reaisDe(total))}</b>${comProblema ? ` · <span class="emp-anulado">${comProblema} com pendência</span>` : ''}`
    : 'Nenhum voluntário selecionado');

  // Total e "selecionar todos" de cada lotação acompanham as marcações e os valores.
  document.querySelectorAll('#lote-conteudo [data-grupo-lote]').forEach(secao => {
    const itens = secao.dataset.grupoLote.split(',').map(Number).map(idx => ITENS_LOTE[idx]);
    const marcados = itens.filter(i => i.selecionado);
    const selecionaveis = itens.filter(i => i.empenhos.length);
    secao.querySelector('[data-total-grupo]').textContent = numeroBR(reaisDe(marcados.reduce((s, i) => s + (centavosDe(i.valor) || 0), 0)));
    secao.querySelector('[data-resumo-grupo]').textContent = `${marcados.length} de ${itens.length} selecionado(s)`;
    const todos = secao.querySelector('[data-todos-grupo]');
    todos.checked = selecionaveis.length > 0 && selecionaveis.every(i => i.selecionado);
    todos.indeterminate = !todos.checked && marcados.length > 0;
    todos.disabled = !selecionaveis.length;
  });
  document.getElementById('btn-confirmar-lote').disabled = !selecionados.length || comProblema > 0;
}

async function confirmarLote() {
  const selecionados = ITENS_LOTE.filter(i => i.selecionado);
  if (!selecionados.length) return;
  const corpo = {
    competencia: COMPETENCIA_LOTE,
    itens: selecionados.map(i => ({
      idVoluntario: i.idVoluntario,
      dias: String(i.dias ?? ''),
      valor: i.valor,
      distribuicao: Object.entries(i.partes).filter(([, v]) => v > 0).map(([idEmpenho, v]) => ({ idEmpenho: Number(idEmpenho), valor: reaisDe(v) })),
    })),
  };
  await comBotaoOcupado(document.getElementById('btn-confirmar-lote'), async () => {
    try {
      const r = await enviarEmpenho('lote', corpo);
      if (!r) return;
      showToast(r.mensagem);
      await Promise.all([carregarLote(), carregarPainel()]);
    } catch (erro) { mostrarErroEmpenho(erro); }
  });
}
