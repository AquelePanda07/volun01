/**
 * extrato-empenhos.js
 * Controle de Empenhos: utilitários de valores e o modal "Extrato do
 * voluntário" (empenhos com saldo, histórico de movimentos e os
 * formulários de novo empenho, pagamento e anulação). Usado por
 * empenhos.html e visualizar.html (api/empenhos.php).
 */

const MESES_CURTOS = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
const MESES_LONGOS = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
const SITUACAO_EMPENHO = {
  ANULADO: { texto: 'Já foi anulado', classe: 'badge--perigo' },
  SALDO_A_ANULAR: { texto: 'Saldo a anular', classe: 'badge--alerta' },
};

/* ------------------------------------------------------------------ */
/* Valores: a conta é sempre em centavos (inteiros)                     */
/* ------------------------------------------------------------------ */

/** "1102.50" (API) ou "1.102,50" (digitado) -> 110250. Inválido -> null. */
function centavosDe(valor) {
  if (typeof valor === 'number') return Math.round(valor * 100);
  let texto = String(valor ?? '').replace(/R\$|\s/g, '');
  if (texto === '' || texto === '-') return 0;
  if (texto.includes(',')) texto = texto.replace(/\./g, '').replace(',', '.');
  if (!/^-?\d+(\.\d+)?$/.test(texto)) return null;
  return Math.round(Number(texto) * 100);
}

/** 110250 -> "1102.50" (formato da API). */
function reaisDe(centavos) {
  const sinal = centavos < 0 ? '-' : '';
  const abs = Math.abs(centavos);
  return `${sinal}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}

const FORMATO_MOEDA = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
const FORMATO_NUMERO = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** "1102.50" -> "R$ 1.102,50" */
function moedaBR(valor) { return FORMATO_MOEDA.format((centavosDe(valor) || 0) / 100); }
/** "1102.50" -> "1.102,50" (para tabelas e campos) */
function numeroBR(valor) { return FORMATO_NUMERO.format((centavosDe(valor) || 0) / 100); }

/** '2026-07' -> 'Jul/2026' */
function competenciaBR(competencia) {
  const [ano, mes] = String(competencia || '').split('-');
  return mes ? `${MESES_CURTOS[Number(mes) - 1]}/${ano}` : '';
}
function competenciaAtual() {
  const hoje = new Date();
  return `${hoje.getFullYear()}-${String(hoje.getMonth() + 1).padStart(2, '0')}`;
}

/** Campo de competência como mês + ano (o <input type="month"> não funciona em todos os navegadores). */
function htmlCampoCompetencia(prefixo, competencia, rotulo = 'Competência') {
  const [ano, mes] = (competencia || competenciaAtual()).split('-');
  return `
    <div class="campo">
      <label for="${prefixo}-mes">${rotulo}</label>
      <div class="emp-competencia">
        <select id="${prefixo}-mes">${MESES_LONGOS.map((m, i) => `<option value="${String(i + 1).padStart(2, '0')}"${Number(mes) === i + 1 ? ' selected' : ''}>${m}</option>`).join('')}</select>
        <input type="number" id="${prefixo}-ano" min="2020" max="2100" value="${escapeHtml(ano)}" aria-label="Ano">
      </div>
    </div>`;
}
function lerCompetencia(raiz, prefixo) {
  const ano = raiz.querySelector(`#${prefixo}-ano`).value.trim();
  return `${ano}-${raiz.querySelector(`#${prefixo}-mes`).value}`;
}

/* ------------------------------------------------------------------ */
/* API                                                                  */
/* ------------------------------------------------------------------ */
const EMP_API = 'empenhos.php';
let PARAMETROS_EMPENHO = null;

async function parametrosEmpenho() {
  if (!PARAMETROS_EMPENHO) PARAMETROS_EMPENHO = (await apiFetch(`${EMP_API}?acao=parametros`)).dados;
  return PARAMETROS_EMPENHO;
}

async function extratoEmpenhos(idVoluntario) {
  return (await apiFetch(`${EMP_API}?acao=extrato&idVoluntario=${encodeURIComponent(idVoluntario)}`)).dados;
}

/** Pergunta "gravar mesmo assim?" com os avisos do servidor. Resolve true/false. */
function confirmarAvisos(avisos, { titulo = 'Confirmar lançamento', textoConfirmar = 'Lançar mesmo assim' } = {}) {
  return new Promise((resolve) => {
    let respondeu = false;
    const responder = (sim) => { if (!respondeu) { respondeu = true; resolve(sim); } };
    const { overlay, fechar } = abrirModal(`
      <div class="modal-box modal-box--medio" role="dialog" aria-modal="true">
        <h3>${icon('alert')} ${escapeHtml(titulo)}</h3>
        <ul class="emp-lista-avisos">${avisos.map(a => `<li>${escapeHtml(a)}</li>`).join('')}</ul>
        <div class="modal-acoes">
          <button type="button" class="btn btn--outline" data-fechar>Cancelar</button>
          <button type="button" class="btn" data-acao="sim">${escapeHtml(textoConfirmar)}</button>
        </div>
      </div>`);
    overlay.querySelector('[data-acao="sim"]').addEventListener('click', () => { responder(true); fechar(); });
    // Qualquer outra forma de fechar (botão, clique fora, Esc) = não.
    new MutationObserver((_, obs) => { if (!overlay.isConnected) { obs.disconnect(); responder(false); } })
      .observe(document.body, { childList: true });
  });
}

/**
 * Envia uma gravação; se o servidor responder 409 (aviso), pergunta e
 * reenvia com "confirmar": true. Devolve a resposta, ou null se o usuário desistiu.
 */
async function enviarEmpenho(acao, corpo, { metodo = 'POST', id = null } = {}) {
  const url = `${EMP_API}?acao=${acao}${id ? `&id=${id}` : ''}`;
  const enviar = (c) => apiFetch(url, { method: metodo, body: JSON.stringify(c) });
  try {
    return await enviar(corpo);
  } catch (erro) {
    if (erro.status !== 409 || !erro.avisos?.length) throw erro;
    if (!(await confirmarAvisos(erro.avisos))) return null;
    return enviar({ ...corpo, confirmar: true });
  }
}

/** Mostra o erro da API: toast para um erro, lista num modal quando forem vários. */
function mostrarErroEmpenho(erro) {
  const erros = erro.erros || [];
  if (erros.length <= 1) {
    showToast(erro.message || 'Não foi possível gravar.', 'erro');
    return;
  }
  abrirModal(`
    <div class="modal-box modal-box--medio" role="alertdialog" aria-modal="true">
      <h3>${icon('xCircle')} Corrija antes de gravar</h3>
      <ul class="emp-lista-avisos emp-lista-avisos--erro">${erros.map(e => `<li>${escapeHtml(e)}</li>`).join('')}</ul>
      <div class="modal-acoes"><button type="button" class="btn" data-fechar>Entendi</button></div>
    </div>`);
}

/** Bloqueia o botão enquanto a gravação acontece. */
async function comBotaoOcupado(botao, tarefa) {
  const html = botao.innerHTML;
  botao.disabled = true;
  botao.innerHTML = `${icon('refresh', 'girando')} Gravando...`;
  try { return await tarefa(); } finally { botao.disabled = false; botao.innerHTML = html; }
}

/* ------------------------------------------------------------------ */
/* Distribuição de um pagamento entre empenhos (mesma regra da API)     */
/* ------------------------------------------------------------------ */

/**
 * Do empenho mais antigo com saldo para o mais novo. "empenhos" já vem
 * ordenado pela API. "reservado" = centavos já usados por empenho (lote).
 */
function distribuirPagamento(empenhos, valorCentavos, reservado = {}) {
  let restante = valorCentavos;
  const partes = [];
  for (const e of empenhos) {
    if (restante <= 0) break;
    const saldo = centavosDe(e.saldo) - (reservado[e.id] || 0);
    if (saldo <= 0) continue;
    const parte = Math.min(saldo, restante);
    partes.push({ idEmpenho: e.id, numero: e.numero, valor: parte });
    restante -= parte;
  }
  return { partes, faltando: Math.max(0, restante) };
}

/* ------------------------------------------------------------------ */
/* Modal: extrato do voluntário                                         */
/* ------------------------------------------------------------------ */

/**
 * Abre o extrato. "aoAtualizar" é chamado depois de qualquer gravação
 * (para a tela de trás recarregar os totais).
 */
async function abrirExtratoEmpenhos(idVoluntario, { aoAtualizar = () => {} } = {}) {
  const { overlay } = abrirModal(`
    <div class="modal-box emp-extrato" role="dialog" aria-modal="true" aria-label="Extrato de empenhos">
      <div class="emp-extrato__corpo"><p class="texto-suave">${icon('refresh', 'girando')} Carregando extrato...</p></div>
    </div>`);
  const corpo = overlay.querySelector('.emp-extrato__corpo');

  const recarregar = async () => {
    try {
      const [extrato] = await Promise.all([extratoEmpenhos(idVoluntario), parametrosEmpenho()]);
      corpo.innerHTML = htmlExtrato(extrato);
      hidratarIcones(corpo);
      ligarExtrato(corpo, extrato, async () => { await recarregar(); aoAtualizar(); });
    } catch (erro) {
      corpo.innerHTML = `<p class="alerta">${escapeHtml(erro.message || 'Não foi possível carregar o extrato.')}</p>
        <div class="modal-acoes"><button type="button" class="btn btn--outline" data-fechar-extrato>Fechar</button></div>`;
      corpo.querySelector('[data-fechar-extrato]').addEventListener('click', () => overlay.click());
    }
  };
  await recarregar();
}

function htmlExtrato(x) {
  const v = x.voluntario;
  const situacao = SITUACAO_EMPENHO[x.situacao];
  const temSaldo = centavosDe(x.totais.saldo) > 0;
  const statusContrato = v.statusContrato ? badgeStatus(v.statusContrato) : '';

  const linhasEmpenhos = x.empenhos.length ? x.empenhos.map(e => `
    <tr>
      <td><strong>${escapeHtml(e.numero)}</strong><span class="emp-sub">${e.tipo === 'ORIGINAL' ? 'Original' : 'Reforço'} · ${e.ano}${e.dataEmpenho ? ` · ${formatarDataBR(e.dataEmpenho)}` : ''}</span></td>
      <td>${escapeHtml(e.lotacao)}${e.fonteRecurso ? `<span class="emp-sub">${escapeHtml(e.fonteRecurso)}</span>` : ''}</td>
      <td class="num">${numeroBR(e.valor)}</td>
      <td class="num">${numeroBR(e.pago)}</td>
      <td class="num ${centavosDe(e.anulado) ? 'emp-anulado' : ''}">${centavosDe(e.anulado) ? '-' + numeroBR(e.anulado) : '—'}</td>
      <td class="num"><strong>${numeroBR(e.saldo)}</strong></td>
      <td class="acoes-cel">
        <button type="button" class="btn btn--outline btn--sm btn--icon-only" data-editar-empenho="${e.id}" title="Editar empenho" aria-label="Editar empenho ${escapeHtml(e.numero)}">${icon('edit')}</button>
        ${centavosDe(e.saldo) > 0 ? `<button type="button" class="btn btn--danger-suave btn--sm btn--icon-only" data-anular-empenho="${e.id}" title="Anular saldo deste empenho" aria-label="Anular saldo do empenho ${escapeHtml(e.numero)}">${icon('xCircle')}</button>` : ''}
        ${centavosDe(e.pago) + centavosDe(e.anulado) === 0 ? `<button type="button" class="btn btn--danger-suave btn--sm btn--icon-only" data-excluir-empenho="${e.id}" title="Excluir empenho" aria-label="Excluir empenho ${escapeHtml(e.numero)}">${icon('trash')}</button>` : ''}
      </td>
    </tr>`).join('') : '<tr><td colspan="7" class="emp-vazio">Nenhum empenho cadastrado.</td></tr>';

  const linhasMov = x.movimentos.length ? x.movimentos.map(m => {
    const anulacao = m.tipo === 'ANULACAO';
    return `
    <tr>
      <td>${competenciaBR(m.competencia)}</td>
      <td>${anulacao ? '<span class="badge badge--perigo">Anulação</span>' : '<span class="badge badge--sucesso">Pagamento</span>'}</td>
      <td>${escapeHtml(m.numeroEmpenho)}</td>
      <td class="num ${anulacao ? 'emp-anulado' : ''}">${anulacao ? '-' : ''}${numeroBR(m.valor)}</td>
      <td class="num">${m.dias ? escapeHtml(String(m.dias).replace('.', ',')) : '—'}</td>
      <td>${escapeHtml(m.observacao || '')}<span class="emp-sub">${m.data ? formatarDataBR(m.data) : ''}</span></td>
      <td class="acoes-cel"><button type="button" class="btn btn--danger-suave btn--sm btn--icon-only" data-excluir-movimento="${m.id}" title="Excluir lançamento" aria-label="Excluir lançamento">${icon('trash')}</button></td>
    </tr>`;
  }).join('') : '<tr><td colspan="7" class="emp-vazio">Nenhum pagamento ou anulação lançado.</td></tr>';

  return `
    <div class="modal-cabecalho">
      <div>
        <h3>${escapeHtml(v.nome)}</h3>
        <p class="texto-suave emp-extrato__meta">
          ${v.cargaHoraria ? `${escapeHtml(v.cargaHoraria)}h semanais · ${moedaBR(v.valorDia)}/dia` : 'Sem carga horária no contrato'}
          ${v.lotacao ? ` · ${escapeHtml(v.lotacao)}` : ''}
          ${v.dataTermino ? ` · contrato até ${formatarDataBR(v.dataTermino)}` : ''}
        </p>
        <div class="emp-extrato__selos">${statusContrato}${v.naoAtivo ? '<span class="badge badge--inativo">Não ativo</span>' : ''}${situacao ? `<span class="badge ${situacao.classe}">${situacao.texto}</span>` : ''}</div>
      </div>
      <button type="button" class="btn btn--outline btn--sm btn--icon-only" data-fechar-extrato aria-label="Fechar">${icon('x')}</button>
    </div>

    ${x.situacao === 'SALDO_A_ANULAR' ? `<div class="alerta emp-alerta">${icon('alert')} Voluntário ${v.naoAtivo ? 'não ativo' : 'com contrato encerrado'} ainda tem ${moedaBR(x.totais.saldo)} de saldo. Anule o saldo restante.</div>` : ''}

    <div class="emp-resumo">
      <div><span>Empenhado</span><strong>${moedaBR(x.totais.empenhado)}</strong></div>
      <div><span>Pago</span><strong>${moedaBR(x.totais.pago)}</strong></div>
      <div><span>Anulado</span><strong class="${centavosDe(x.totais.anulado) ? 'emp-anulado' : ''}">${centavosDe(x.totais.anulado) ? '-' : ''}${moedaBR(x.totais.anulado)}</strong></div>
      <div class="emp-resumo__saldo"><span>Saldo</span><strong>${moedaBR(x.totais.saldo)}</strong></div>
    </div>

    <div class="emp-extrato__acoes">
      <button type="button" class="btn btn--sm" data-acao="novo-empenho">${icon('plus')} Novo empenho</button>
      <button type="button" class="btn btn--sm" data-acao="pagamento" ${temSaldo ? '' : 'disabled title="Sem saldo: cadastre um empenho"'}>${icon('checkCircle')} Lançar pagamento</button>
      <button type="button" class="btn btn--danger-suave btn--sm" data-acao="anular" ${temSaldo ? '' : 'disabled title="Sem saldo para anular"'}>${icon('xCircle')} Anular saldo</button>
    </div>

    <h4 class="emp-extrato__titulo">Empenhos</h4>
    <div class="tabela-wrapper emp-tabela-wrapper">
      <table class="emp-tabela emp-tabela--extrato">
        <thead><tr><th>Empenho</th><th>Lotação</th><th class="num">Valor</th><th class="num">Pago</th><th class="num">Anulado</th><th class="num">Saldo</th><th><span class="sr-only">Ações</span></th></tr></thead>
        <tbody>${linhasEmpenhos}</tbody>
      </table>
    </div>

    <h4 class="emp-extrato__titulo">Movimentos</h4>
    <div class="tabela-wrapper emp-tabela-wrapper">
      <table class="emp-tabela emp-tabela--extrato">
        <thead><tr><th>Competência</th><th>Tipo</th><th>Empenho</th><th class="num">Valor</th><th class="num">Dias</th><th>Observação</th><th><span class="sr-only">Ações</span></th></tr></thead>
        <tbody>${linhasMov}</tbody>
      </table>
    </div>`;
}

function ligarExtrato(corpo, x, aoGravar) {
  const overlay = corpo.closest('.modal-overlay');
  corpo.querySelector('[data-fechar-extrato]').addEventListener('click', () => overlay.click());
  corpo.querySelector('[data-acao="novo-empenho"]').addEventListener('click', () => abrirFormEmpenho(x, null, aoGravar));
  corpo.querySelector('[data-acao="pagamento"]').addEventListener('click', () => abrirFormPagamento(x, aoGravar));
  corpo.querySelector('[data-acao="anular"]').addEventListener('click', () => abrirFormAnulacao(x, null, aoGravar));

  const empenhoPorId = (id) => x.empenhos.find(e => e.id === Number(id));
  corpo.querySelectorAll('[data-editar-empenho]').forEach(b => b.addEventListener('click', () => abrirFormEmpenho(x, empenhoPorId(b.dataset.editarEmpenho), aoGravar)));
  corpo.querySelectorAll('[data-anular-empenho]').forEach(b => b.addEventListener('click', () => abrirFormAnulacao(x, empenhoPorId(b.dataset.anularEmpenho), aoGravar)));
  corpo.querySelectorAll('[data-excluir-empenho]').forEach(b => b.addEventListener('click', () => {
    const e = empenhoPorId(b.dataset.excluirEmpenho);
    showConfirm({
      titulo: 'Excluir empenho',
      mensagem: `Excluir o empenho nº ${e.numero} (${moedaBR(e.valor)})? Ele ainda não tem lançamentos.`,
      textoConfirmar: 'Excluir',
      onConfirm: async () => {
        try {
          await apiFetch(`${EMP_API}?acao=empenho&id=${e.id}`, { method: 'DELETE' });
          showToast('Empenho excluído.');
          aoGravar();
        } catch (erro) { mostrarErroEmpenho(erro); }
      },
    });
  }));
  corpo.querySelectorAll('[data-excluir-movimento]').forEach(b => b.addEventListener('click', () => {
    const m = x.movimentos.find(mm => mm.id === Number(b.dataset.excluirMovimento));
    showConfirm({
      titulo: 'Excluir lançamento',
      mensagem: `Excluir ${m.tipo === 'ANULACAO' ? 'a anulação' : 'o pagamento'} de ${competenciaBR(m.competencia)} (${moedaBR(m.valor)}, empenho ${m.numeroEmpenho})? O valor volta para o saldo do empenho.`,
      textoConfirmar: 'Excluir',
      onConfirm: async () => {
        try {
          await apiFetch(`${EMP_API}?acao=movimento&id=${m.id}`, { method: 'DELETE' });
          showToast('Lançamento excluído.');
          aoGravar();
        } catch (erro) { mostrarErroEmpenho(erro); }
      },
    });
  }));
}

/* ---------- Novo empenho / editar empenho ---------- */
async function abrirFormEmpenho(x, empenho, aoGravar) {
  const v = x.voluntario;
  const editando = Boolean(empenho);
  const ano = empenho?.ano || new Date().getFullYear();
  const primeiroDoAno = !x.empenhos.some(e => e.ano === ano);
  const valorSugerido = !editando && primeiroDoAno && v.valorOriginalSugerido ? v.valorOriginalSugerido : '';
  let locais = [];
  try { locais = (await apiFetch('locais.php?ativos=1')).dados.map(l => l.nome); } catch (e) { /* lista é só sugestão */ }

  const { overlay, fechar } = abrirModal(`
    <form class="modal-box modal-box--medio" role="dialog" aria-modal="true" novalidate>
      <h3>${editando ? `Editar empenho nº ${escapeHtml(empenho.numero)}` : 'Novo empenho'}</h3>
      <p class="texto-suave">${escapeHtml(v.nome)}</p>
      <div class="form-grid emp-form">
        <div class="campo"><label for="emp-numero">Nº do empenho<span class="obrigatorio">*</span></label>
          <input id="emp-numero" maxlength="30" required value="${escapeHtml(empenho?.numero || '')}"></div>
        <div class="campo"><label for="emp-ano">Ano<span class="obrigatorio">*</span></label>
          <input id="emp-ano" type="number" min="2020" max="2100" required value="${ano}"></div>
        <div class="campo"><label for="emp-valor">Valor (R$)<span class="obrigatorio">*</span></label>
          <input id="emp-valor" inputmode="decimal" required value="${escapeHtml(empenho ? numeroBR(empenho.valor) : (valorSugerido ? numeroBR(valorSugerido) : ''))}">
          ${valorSugerido ? `<small class="texto-suave">Sugerido: 6 meses de bolsa (${escapeHtml(v.cargaHoraria)}h).</small>` : ''}</div>
        <div class="campo"><label for="emp-tipo">Tipo</label>
          <select id="emp-tipo">
            <option value="ORIGINAL"${(empenho?.tipo || (primeiroDoAno ? 'ORIGINAL' : 'REFORCO')) === 'ORIGINAL' ? ' selected' : ''}>Original (1º semestre)</option>
            <option value="REFORCO"${(empenho?.tipo || (primeiroDoAno ? 'ORIGINAL' : 'REFORCO')) === 'REFORCO' ? ' selected' : ''}>Reforço / novo empenho</option>
          </select></div>
        <div class="campo"><label for="emp-data">Data do empenho</label>
          <input id="emp-data" type="date" value="${escapeHtml(empenho?.dataEmpenho || '')}"></div>
        <div class="campo"><label for="emp-lotacao">Lotação</label>
          <input id="emp-lotacao" list="emp-locais" value="${escapeHtml(empenho?.lotacao || v.lotacao || '')}">
          <datalist id="emp-locais">${locais.map(l => `<option value="${escapeHtml(l)}"></option>`).join('')}</datalist></div>
        <div class="campo campo--full"><label for="emp-fonte">Fonte de recurso</label>
          <input id="emp-fonte" maxlength="100" value="${escapeHtml(empenho?.fonteRecurso || '')}" placeholder="Ex.: FUNDEB, ficha 290"></div>
        <div class="campo campo--full"><label for="emp-obs">Observação</label>
          <input id="emp-obs" maxlength="250" value="${escapeHtml(empenho?.observacao || '')}"></div>
      </div>
      <div class="modal-acoes">
        <button type="button" class="btn btn--outline" data-fechar>Cancelar</button>
        <button type="submit" class="btn">${icon('save')} ${editando ? 'Salvar' : 'Cadastrar empenho'}</button>
      </div>
    </form>`);
  const form = overlay.querySelector('form');
  form.querySelector('#emp-numero').focus();
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const val = (id) => form.querySelector(id).value.trim();
    const corpo = {
      idVoluntario: v.id, numero: val('#emp-numero'), ano: val('#emp-ano'), valor: val('#emp-valor'),
      tipo: val('#emp-tipo'), dataEmpenho: val('#emp-data'), lotacao: val('#emp-lotacao'),
      fonteRecurso: val('#emp-fonte'), observacao: val('#emp-obs'),
    };
    await comBotaoOcupado(form.querySelector('[type="submit"]'), async () => {
      try {
        const r = editando
          ? await enviarEmpenho('empenho', corpo, { metodo: 'PUT', id: empenho.id })
          : await enviarEmpenho('empenho', corpo);
        if (!r) return;
        showToast(r.mensagem);
        fechar();
        aoGravar();
      } catch (erro) { mostrarErroEmpenho(erro); }
    });
  });
}

/* ---------- Lançar pagamento (individual) ---------- */
function abrirFormPagamento(x, aoGravar) {
  const v = x.voluntario;
  const comSaldo = x.empenhos.filter(e => centavosDe(e.saldo) > 0);
  const valorDia = centavosDe(v.valorDia) || 0;
  const passo = PARAMETROS_EMPENHO?.passoDias || 0.5;

  const { overlay, fechar } = abrirModal(`
    <form class="modal-box modal-box--medio" role="dialog" aria-modal="true" novalidate>
      <h3>Lançar pagamento</h3>
      <p class="texto-suave">${escapeHtml(v.nome)} · saldo ${moedaBR(x.totais.saldo)}</p>
      <div class="form-grid emp-form">
        ${htmlCampoCompetencia('pag-comp', competenciaAtual())}
        <div class="campo"><label for="pag-dias">Dias trabalhados</label>
          <input id="pag-dias" type="number" min="0" max="31" step="${passo}" inputmode="decimal" placeholder="Ex.: 20">
          <small class="texto-suave">${valorDia ? `${moedaBR(v.valorDia)} por dia` : 'Carga horária sem valor configurado'}</small></div>
        <div class="campo"><label for="pag-valor">Valor (R$)<span class="obrigatorio">*</span></label>
          <input id="pag-valor" inputmode="decimal" required></div>
        <div class="campo campo--full">
          <label>De qual empenho sai</label>
          <div class="emp-distribuicao" id="pag-dist">
            ${comSaldo.map(e => `
              <label class="emp-distribuicao__linha">
                <span><strong>${escapeHtml(e.numero)}</strong> <span class="texto-suave">saldo ${moedaBR(e.saldo)}</span></span>
                <input inputmode="decimal" data-empenho="${e.id}" aria-label="Valor do empenho ${escapeHtml(e.numero)}">
              </label>`).join('')}
          </div>
          <small class="emp-distribuicao__soma" id="pag-soma"></small>
        </div>
        <div class="campo campo--full"><label for="pag-obs">Observação</label><input id="pag-obs" maxlength="250"></div>
      </div>
      <div class="modal-acoes">
        <button type="button" class="btn btn--outline" data-fechar>Cancelar</button>
        <button type="submit" class="btn">${icon('save')} Lançar</button>
      </div>
    </form>`);

  const form = overlay.querySelector('form');
  const campoDias = form.querySelector('#pag-dias');
  const campoValor = form.querySelector('#pag-valor');
  const campos = [...form.querySelectorAll('[data-empenho]')];
  const soma = form.querySelector('#pag-soma');

  const atualizarSoma = () => {
    const total = centavosDe(campoValor.value) || 0;
    const distribuido = campos.reduce((s, c) => s + (centavosDe(c.value) || 0), 0);
    const ok = total > 0 && distribuido === total;
    soma.textContent = total ? `Distribuído ${moedaBR(reaisDe(distribuido))} de ${moedaBR(reaisDe(total))}${ok ? '' : ' — ajuste os valores'}` : '';
    soma.classList.toggle('emp-distribuicao__soma--erro', total > 0 && !ok);
  };
  const redistribuir = () => {
    const { partes, faltando } = distribuirPagamento(comSaldo, centavosDe(campoValor.value) || 0);
    campos.forEach(c => {
      const parte = partes.find(p => p.idEmpenho === Number(c.dataset.empenho));
      c.value = parte ? numeroBR(reaisDe(parte.valor)) : '';
    });
    atualizarSoma();
    if (faltando > 0) soma.textContent += ` · faltam ${moedaBR(reaisDe(faltando))} de saldo`;
  };
  campoDias.addEventListener('input', () => {
    const dias = Number(String(campoDias.value).replace(',', '.'));
    if (valorDia && dias >= 0) {
      campoValor.value = numeroBR(reaisDe(Math.round(valorDia * dias)));
      redistribuir();
    }
  });
  campoValor.addEventListener('input', redistribuir);
  campos.forEach(c => c.addEventListener('input', atualizarSoma));
  campoDias.focus();

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const corpo = {
      idVoluntario: v.id,
      competencia: lerCompetencia(form, 'pag-comp'),
      dias: campoDias.value,
      valor: campoValor.value,
      distribuicao: campos.filter(c => (centavosDe(c.value) || 0) > 0).map(c => ({ idEmpenho: Number(c.dataset.empenho), valor: c.value })),
      observacao: form.querySelector('#pag-obs').value,
    };
    await comBotaoOcupado(form.querySelector('[type="submit"]'), async () => {
      try {
        const r = await enviarEmpenho('pagamento', corpo);
        if (!r) return;
        showToast(r.mensagem);
        fechar();
        aoGravar();
      } catch (erro) { mostrarErroEmpenho(erro); }
    });
  });
}

/* ---------- Anular saldo (total ou parcial) ---------- */
function abrirFormAnulacao(x, empenho, aoGravar) {
  const v = x.voluntario;
  const comSaldo = x.empenhos.filter(e => centavosDe(e.saldo) > 0);
  const { overlay, fechar } = abrirModal(`
    <form class="modal-box modal-box--medio" role="dialog" aria-modal="true" novalidate>
      <h3>${icon('xCircle')} Anular saldo</h3>
      <p class="texto-suave">${escapeHtml(v.nome)} · saldo total ${moedaBR(x.totais.saldo)}</p>
      <div class="emp-modos" role="radiogroup" aria-label="Tipo de anulação">
        <label><input type="radio" name="anl-modo" value="total" ${empenho ? '' : 'checked'}> <span><strong>Total</strong><small>Anula todo o saldo restante (ex.: o voluntário saiu)</small></span></label>
        <label><input type="radio" name="anl-modo" value="parcial" ${empenho ? 'checked' : ''}> <span><strong>Parcial</strong><small>Anula parte de um empenho (ex.: adequação de fonte de recurso)</small></span></label>
      </div>
      <div class="form-grid emp-form">
        <div class="campo campo--full"><label for="anl-empenho">Empenho</label>
          <select id="anl-empenho">
            <option value="">Todos os empenhos com saldo</option>
            ${comSaldo.map(e => `<option value="${e.id}" data-saldo="${e.saldo}"${empenho?.id === e.id ? ' selected' : ''}>${escapeHtml(e.numero)} — saldo ${moedaBR(e.saldo)}</option>`).join('')}
          </select></div>
        <div class="campo" id="anl-campo-valor"><label for="anl-valor">Valor a anular (R$)<span class="obrigatorio">*</span></label>
          <input id="anl-valor" inputmode="decimal"></div>
        ${htmlCampoCompetencia('anl-comp', competenciaAtual(), 'Mês da anulação')}
        <div class="campo campo--full"><label for="anl-motivo">Motivo<span class="obrigatorio">*</span></label>
          <input id="anl-motivo" maxlength="250" required placeholder="Ex.: voluntário desligado; adequação de fonte de recurso"></div>
      </div>
      <p class="emp-anulacao__resumo" id="anl-resumo"></p>
      <div class="modal-acoes">
        <button type="button" class="btn btn--outline" data-fechar>Cancelar</button>
        <button type="submit" class="btn btn--danger">${icon('xCircle')} Anular</button>
      </div>
    </form>`);

  const form = overlay.querySelector('form');
  const seletor = form.querySelector('#anl-empenho');
  const campoValor = form.querySelector('#anl-valor');
  const resumo = form.querySelector('#anl-resumo');
  const modo = () => form.querySelector('[name="anl-modo"]:checked').value;
  const atualizar = () => {
    const parcial = modo() === 'parcial';
    form.querySelector('#anl-campo-valor').hidden = !parcial;
    seletor.options[0].disabled = parcial;
    if (parcial && !seletor.value && comSaldo[0]) seletor.value = String(comSaldo[0].id);
    const saldoEscolhido = seletor.value ? seletor.selectedOptions[0].dataset.saldo : x.totais.saldo;
    resumo.textContent = parcial
      ? `Saldo do empenho: ${moedaBR(saldoEscolhido)}.`
      : `Será anulado ${moedaBR(saldoEscolhido)} (todo o saldo ${seletor.value ? 'deste empenho' : 'do voluntário'}).`;
  };
  form.querySelectorAll('[name="anl-modo"]').forEach(r => r.addEventListener('change', atualizar));
  seletor.addEventListener('change', atualizar);
  atualizar();
  form.querySelector('#anl-motivo').focus();

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const corpo = {
      modo: modo(),
      idVoluntario: v.id,
      idEmpenho: seletor.value ? Number(seletor.value) : null,
      valor: campoValor.value,
      competencia: lerCompetencia(form, 'anl-comp'),
      motivo: form.querySelector('#anl-motivo').value.trim(),
    };
    await comBotaoOcupado(form.querySelector('[type="submit"]'), async () => {
      try {
        const r = await enviarEmpenho('anular', corpo);
        if (!r) return;
        showToast(r.mensagem);
        fechar();
        aoGravar();
      } catch (erro) { mostrarErroEmpenho(erro); }
    });
  });
}
