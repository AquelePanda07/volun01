/**
 * app.js
 * Núcleo compartilhado do Sistema de Gestão de Voluntários.
 * Contém: layout (sidebar/topbar), cliente da API PHP (fetch), cálculos
 * (idade/status), máscaras, validações, ícones SVG, toasts, modais e tema.
 * Este arquivo é carregado em TODAS as páginas, antes do script da página.
 */

/* ------------------------------------------------------------------ */
/* Constantes                                                          */
/* ------------------------------------------------------------------ */
const API_BASE = 'api/';

/* ------------------------------------------------------------------ */
/* Ícones (SVG inline - estilo "feather", leves e sem dependências)    */
/* ------------------------------------------------------------------ */
const ICONS = {
  home: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>',
  plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>',
  list: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>',
  grid: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="3" y1="15" x2="21" y2="15"></line><line x1="9" y1="3" x2="9" y2="21"></line></svg>',
  eye: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>',
  edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>',
  trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path></svg>',
  search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>',
  back: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>',
  arrowRight: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>',
  users: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>',
  user: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>',
  checkCircle: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>',
  xCircle: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>',
  clock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>',
  calendar: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>',
  mapPin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>',
  alert: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>',
  briefcase: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>',
  file: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>',
  download: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>',
  refresh: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>',
  repeat: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path></svg>',
  fileCheck: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><polyline points="9 15 11 17 15 13"></polyline></svg>',
  upload: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>',
  paperclip: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>',
  maximize: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"></path></svg>',
  minimize: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v3a2 2 0 0 1-2 2H3m18 0h-3a2 2 0 0 1-2-2V3m0 18v-3a2 2 0 0 1 2-2h3M3 16h3a2 2 0 0 1 2 2v3"></path></svg>',
  x: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>',
  save: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>',
  menu: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>',
  camera: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>',
  sun: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>',
  moon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>'
};

function icon(name, extraClass) {
  return `<span class="icon ${extraClass || ''}">${ICONS[name] || ''}</span>`;
}

/** Preenche os <span data-icon="nome"> escritos direto no HTML. */
function hidratarIcones(raiz = document) {
  raiz.querySelectorAll('[data-icon]').forEach(el => {
    el.classList.add('icon');
    el.innerHTML = ICONS[el.dataset.icon] || '';
    el.removeAttribute('data-icon');
  });
}

/* ------------------------------------------------------------------ */
/* Cliente da API (back-end PHP + MySQL)                                */
/* ------------------------------------------------------------------ */

/** Erro de API: mantém a mensagem principal e a lista detalhada de erros de validação. */
class ErroApi extends Error {
  constructor(mensagem, erros) {
    super(mensagem);
    this.erros = erros || [];
  }
}

async function apiFetch(caminho, opcoes = {}) {
  const resposta = await fetch(API_BASE + caminho, {
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
    ...opcoes,
  });

  let corpo = null;
  try {
    corpo = await resposta.json();
  } catch (e) {
    throw new ErroApi('Resposta inesperada do servidor.');
  }

  if (!resposta.ok || !corpo.sucesso) {
    const erro = new ErroApi(corpo.mensagem || 'Ocorreu um erro ao comunicar com o servidor.', corpo.erros);
    erro.falhas = corpo.falhas || [];
    throw erro;
  }
  return corpo;
}

async function listarVoluntarios() {
  const resposta = await apiFetch('voluntarios.php');
  return resposta.dados;
}

async function buscarVoluntario(id) {
  try {
    const resposta = await apiFetch(`voluntarios.php?id=${encodeURIComponent(id)}`);
    return resposta.dados;
  } catch (e) {
    return null;
  }
}

async function criarVoluntario(dados) {
  const resposta = await apiFetch('voluntarios.php', { method: 'POST', body: JSON.stringify(dados) });
  return resposta.dados;
}

async function atualizarVoluntario(id, dados) {
  const resposta = await apiFetch(`voluntarios.php?id=${encodeURIComponent(id)}`, {
    method: 'PUT',
    body: JSON.stringify(dados),
  });
  return resposta.dados;
}

async function excluirVoluntario(id) {
  await apiFetch(`voluntarios.php?id=${encodeURIComponent(id)}`, { method: 'DELETE' });
}

/** Pré-visualiza o Termo de Adesão (HTML pronto para exibir em um iframe). */
async function previsualizarTermo(idVoluntario, dataEmissao) {
  const query = new URLSearchParams({ acao: 'previsualizar', id_voluntario: idVoluntario });
  if (dataEmissao) query.set('dataEmissao', dataEmissao);
  const resposta = await fetch(`${API_BASE}documentos.php?${query.toString()}`);
  return resposta.json();
}

/** Gera o Termo de Adesão (PDF ou DOCX) e registra no histórico. */
async function gerarDocumento(idVoluntario, formato, dataEmissao) {
  return apiFetch('documentos.php', {
    method: 'POST',
    body: JSON.stringify({ idVoluntario, formato, dataEmissao }),
  });
}

/** Gera o Termo de Adesão de vários voluntários de uma vez (devolve link de um ZIP). */
async function gerarDocumentosEmLote(idsVoluntarios, formato, dataEmissao) {
  return apiFetch('documentos.php?acao=lote', {
    method: 'POST',
    body: JSON.stringify({ idsVoluntarios, formato, dataEmissao }),
  });
}

/** Marca o contrato vigente do voluntário como renovado, com a nova data de término. */
async function renovarContrato(idVoluntario, novaDataTermino) {
  return apiFetch('contratos.php?acao=renovar', {
    method: 'POST',
    body: JSON.stringify({ idVoluntario, novaDataTermino }),
  });
}

async function historicoDocumentos(idVoluntario) {
  const resposta = await apiFetch(`documentos.php?acao=historico&id_voluntario=${encodeURIComponent(idVoluntario)}`);
  return resposta.dados;
}

/* ------------------------------------------------------------------ */
/* Cálculos                                                             */
/* ------------------------------------------------------------------ */
function calcularIdade(dataNascISO) {
  if (!dataNascISO) return '';
  const hoje = new Date();
  const nasc = new Date(dataNascISO + 'T00:00:00');
  if (isNaN(nasc.getTime())) return '';
  let idade = hoje.getFullYear() - nasc.getFullYear();
  const m = hoje.getMonth() - nasc.getMonth();
  if (m < 0 || (m === 0 && hoje.getDate() < nasc.getDate())) {
    idade--;
  }
  return idade >= 0 ? idade : '';
}

/**
 * Calcula o status do contrato com base nas datas de início/término.
 * Retorna: 'ATIVO' | 'A_INICIAR' | 'ENCERRADO'
 */
function calcularStatusContrato(dataInicioISO, dataTerminoISO) {
  const hoje = zerarHora(new Date());
  const inicio = dataInicioISO ? zerarHora(new Date(dataInicioISO + 'T00:00:00')) : null;
  const termino = dataTerminoISO ? zerarHora(new Date(dataTerminoISO + 'T00:00:00')) : null;

  if (inicio && hoje < inicio) return 'A_INICIAR';
  if (termino && hoje > termino) return 'ENCERRADO';
  return 'ATIVO';
}

/** Quantidade de dias entre hoje e a data de término (pode ser negativa, se já passou). */
function calcularDiasRestantes(dataTerminoISO) {
  if (!dataTerminoISO) return null;
  const hoje = zerarHora(new Date());
  const termino = zerarHora(new Date(dataTerminoISO + 'T00:00:00'));
  if (isNaN(termino.getTime())) return null;
  return Math.round((termino - hoje) / 86400000);
}

/** Percentual (0–100) do contrato já cumprido até hoje. */
function calcularProgressoContrato(dataInicioISO, dataTerminoISO) {
  if (!dataInicioISO || !dataTerminoISO) return null;
  const inicio = new Date(dataInicioISO + 'T00:00:00').getTime();
  const termino = new Date(dataTerminoISO + 'T00:00:00').getTime();
  if (isNaN(inicio) || isNaN(termino) || termino <= inicio) return null;
  const decorrido = (zerarHora(new Date()).getTime() - inicio) / (termino - inicio);
  return Math.round(Math.min(1, Math.max(0, decorrido)) * 100);
}

/** Texto amigável para a coluna "Dias para o Término". */
function formatarDiasRestantes(diasRestantes, status) {
  if (status === 'ENCERRADO' || diasRestantes === null) return 'Encerrado';
  if (diasRestantes === 0) return 'Termina hoje';
  if (diasRestantes === 1) return '1 dia';
  return `${diasRestantes} dias`;
}

/**
 * Quanto mais perto do fim do contrato (apenas para contratos ATIVOS), mais
 * amarelo/âmbar fica o destaque. Retorna um estilo inline (ou string vazia).
 */
function estiloDestaquePrazo(diasRestantes, status) {
  const JANELA_DIAS = 60; // a partir de 60 dias antes do término, o destaque começa a aparecer
  if (status !== 'ATIVO' || diasRestantes === null || diasRestantes < 0 || diasRestantes > JANELA_DIAS) {
    return '';
  }
  const intensidade = 1 - (diasRestantes / JANELA_DIAS);
  const alpha = (0.08 + intensidade * 0.32).toFixed(2);
  return `--destaque-prazo: rgba(217, 150, 36, ${alpha});`;
}

function zerarHora(data) {
  data.setHours(0, 0, 0, 0);
  return data;
}

function hojeISO() {
  const hoje = new Date();
  const mes = String(hoje.getMonth() + 1).padStart(2, '0');
  const dia = String(hoje.getDate()).padStart(2, '0');
  return `${hoje.getFullYear()}-${mes}-${dia}`;
}

const STATUS_LABEL = {
  ATIVO: 'Ativo',
  A_INICIAR: 'A Iniciar',
  ENCERRADO: 'Encerrado'
};

const STATUS_ICON = {
  ATIVO: 'checkCircle',
  A_INICIAR: 'clock',
  ENCERRADO: 'xCircle'
};

const STATUS_BADGE = {
  ATIVO: 'badge--sucesso',
  A_INICIAR: 'badge--alerta',
  ENCERRADO: 'badge--perigo'
};

/** Selo colorido com o status do contrato. */
function badgeStatus(status) {
  return `<span class="badge ${STATUS_BADGE[status]}">${icon(STATUS_ICON[status])}${STATUS_LABEL[status]}</span>`;
}

/* ------------------------------------------------------------------ */
/* Renovação de contrato                                                */
/* ------------------------------------------------------------------ */
const JANELA_RENOVACAO_DIAS = 60; // mesma janela de ContratoService::JANELA_RENOVACAO_DIAS

/** Renovação disponível: contrato ainda não renovado, nos últimos 60 dias ou já encerrado. */
function podeRenovarContrato(v) {
  if (v.renovado || !v.dataTermino) return false;
  const status = calcularStatusContrato(v.dataInicio, v.dataTermino);
  const dias = calcularDiasRestantes(v.dataTermino);
  return status !== 'A_INICIAR' && dias !== null && dias <= JANELA_RENOVACAO_DIAS;
}

/** Selo "Renovado" (com o término anterior no tooltip). */
function badgeRenovado(v) {
  if (!v.renovado) return '';
  const dica = v.terminoAnterior ? `Contrato renovado — término anterior: ${formatarDataBR(v.terminoAnterior)}` : 'Contrato renovado';
  return `<span class="badge badge--renovado" title="${escapeHtml(dica)}">${icon('repeat')}Renovado</span>`;
}

/** Ícone compacto de renovado, para ficar ao lado do nome em listas. */
function seloRenovado(v) {
  if (!v.renovado) return '';
  return `<span class="selo-renovado" title="Contrato renovado" aria-label="Contrato renovado">${icon('repeat')}</span>`;
}

function dataParaISO(data) {
  return `${data.getFullYear()}-${String(data.getMonth() + 1).padStart(2, '0')}-${String(data.getDate()).padStart(2, '0')}`;
}

function somarMesesISO(iso, meses) {
  const data = new Date(iso + 'T00:00:00');
  const dia = data.getDate();
  data.setMonth(data.getMonth() + meses);
  if (data.getDate() !== dia) data.setDate(0); // 31/08 + 6 meses -> 28/02, e não 03/03
  return dataParaISO(data);
}

function somarDiasISO(iso, dias) {
  const data = new Date(iso + 'T00:00:00');
  data.setDate(data.getDate() + dias);
  return dataParaISO(data);
}

/**
 * Modal "Marcar como renovado": pede a nova data de término, contando os
 * 6 meses a partir do término atual ou a partir de hoje (à escolha), e chama
 * aoRenovar(voluntarioAtualizado) no sucesso.
 */
function abrirModalRenovacao(v, aoRenovar) {
  const hoje = hojeISO();
  const opcoes = {
    termino: { minimo: somarDiasISO(v.dataTermino, 1), maximo: somarMesesISO(v.dataTermino, 6) },
    hoje: { minimo: [somarDiasISO(v.dataTermino, 1), hoje].sort().pop(), maximo: somarMesesISO(hoje, 6) },
  };
  // Contrato já encerrado: por padrão, conta a partir de hoje.
  const padrao = v.dataTermino < hoje ? 'hoje' : 'termino';
  let { minimo, maximo } = opcoes[padrao];
  let salvando = false;

  const dias = calcularDiasRestantes(v.dataTermino);
  const situacao = dias < 0
    ? `<span class="renovacao-atual__situacao renovacao-atual__situacao--vencido">${icon('alert')}Encerrado há ${-dias} ${-dias === 1 ? 'dia' : 'dias'}</span>`
    : `<span class="renovacao-atual__situacao">${icon('clock')}${dias === 0 ? 'Termina hoje' : `Faltam ${dias} ${dias === 1 ? 'dia' : 'dias'}`}</span>`;

  const opcao = (chave, titulo, base) => `
    <label class="opcao-renovacao">
      <input type="radio" name="renovacao-base" value="${chave}" ${chave === padrao ? 'checked' : ''}>
      <span class="opcao-renovacao__titulo">${titulo}</span>
      <span class="opcao-renovacao__data">${formatarDataBR(opcoes[chave].maximo)}</span>
      <span class="opcao-renovacao__conta">${formatarDataBR(base)} + 6 meses</span>
      ${opcoes[chave].maximo < hoje ? `<span class="opcao-renovacao__aviso">${icon('alert')}Já vencida — continua encerrado</span>` : ''}
    </label>`;

  const { overlay, fechar } = abrirModal(`
    <div class="modal-box modal-box--medio" role="dialog" aria-modal="true" aria-labelledby="renovacao-titulo">
      <h3 id="renovacao-titulo">${icon('repeat')} Renovar contrato</h3>
      <div class="renovacao-atual">
        <div>
          <span class="renovacao-atual__nome">${escapeHtml(v.nome)}</span>
          <span class="texto-suave">Término atual: <b>${formatarDataBR(v.dataTermino)}</b></span>
        </div>
        ${situacao}
      </div>
      <p class="renovacao-pergunta">Contar os 6 meses a partir de:</p>
      <div class="opcoes-renovacao" role="radiogroup" aria-label="Contar a renovação a partir de">
        ${opcao('termino', 'Do término do contrato', v.dataTermino)}
        ${opcao('hoje', 'De hoje', hoje)}
      </div>
      <div class="campo">
        <label for="renovacao-termino">Nova data de término</label>
        <input type="date" id="renovacao-termino" value="${maximo}" min="${minimo}" max="${maximo}" required>
        <small class="texto-suave" id="renovacao-dica"></small>
      </div>
      <div class="renovacao-resumo">
        <span>${formatarDataBR(v.dataTermino)}</span>
        ${icon('arrowRight')}
        <b id="renovacao-resumo-nova"></b>
      </div>
      <div class="modal-acoes">
        <button type="button" class="btn btn--outline" data-fechar>Cancelar</button>
        <button type="button" class="btn" data-acao="renovar">${icon('repeat')} Marcar como renovado</button>
      </div>
    </div>
  `, { podeFechar: () => !salvando });

  const campo = overlay.querySelector('#renovacao-termino');
  const botao = overlay.querySelector('[data-acao="renovar"]');
  const atualizarResumo = () => {
    overlay.querySelector('#renovacao-dica').textContent = `Pode ser ajustada entre ${formatarDataBR(minimo)} e ${formatarDataBR(maximo)}.`;
    overlay.querySelector('#renovacao-resumo-nova').textContent = campo.value ? formatarDataBR(campo.value) : '—';
  };
  overlay.querySelectorAll('[name="renovacao-base"]').forEach(radio => {
    radio.addEventListener('change', () => {
      ({ minimo, maximo } = opcoes[radio.value]);
      campo.min = minimo;
      campo.max = maximo;
      campo.value = maximo;
      atualizarResumo();
    });
  });
  campo.addEventListener('input', atualizarResumo);
  atualizarResumo();
  campo.focus();
  botao.addEventListener('click', async () => {
    const novaData = campo.value;
    if (!novaData || novaData < minimo || novaData > maximo) {
      showToast(`Escolha uma data entre ${formatarDataBR(minimo)} e ${formatarDataBR(maximo)}.`, 'erro');
      return;
    }
    salvando = true;
    botao.disabled = true;
    botao.innerHTML = `${icon('refresh', 'girando')} Salvando...`;
    try {
      const resposta = await renovarContrato(v.id, novaData);
      salvando = false;
      fechar();
      showToast(resposta.mensagem, 'sucesso');
      if (typeof aoRenovar === 'function') aoRenovar(resposta.dados);
    } catch (erro) {
      salvando = false;
      botao.disabled = false;
      botao.innerHTML = `${icon('repeat')} Marcar como renovado`;
      showToast(erro.message || 'Não foi possível renovar o contrato.', 'erro');
    }
  });
}

/* ------------------------------------------------------------------ */
/* Termo de Adesão assinado (anexo + marcação de assinado)             */
/* Só é possível marcar como assinado depois de anexar o termo.        */
/* ------------------------------------------------------------------ */
const TERMOS_ASSINADOS = {
  termo: { titulo: 'Termo do contrato', anexo: 'anexoTermo', assinado: 'termoAssinado', data: 'dataAssinaturaTermo' },
  renovacao: { titulo: 'Termo da renovação', anexo: 'anexoRenovacao', assinado: 'renovacaoAssinada', data: 'dataAssinaturaRenovacao' },
};
const TAMANHO_MAXIMO_ANEXO_MB = 10;

async function acaoTermoAssinado(acao, corpo) {
  return apiFetch(`contratos.php?acao=${acao}`, { method: 'POST', body: JSON.stringify(corpo) });
}

function urlAnexoTermo(idVoluntario, tipo, baixar = false) {
  const query = new URLSearchParams({ acao: 'anexo', id_voluntario: idVoluntario, tipo });
  if (baixar) query.set('baixar', '1');
  return `${API_BASE}contratos.php?${query.toString()}`;
}

/** Ícones compactos de termo assinado (contrato e/ou renovação), para listas. */
function seloTermos(v) {
  let html = '';
  if (v.termoAssinado) {
    html += `<span class="selo-termo" title="Termo assinado (marcado em ${formatarDataBR(v.dataAssinaturaTermo)})" aria-label="Termo assinado">${icon('fileCheck')}</span>`;
  }
  if (v.renovado && v.renovacaoAssinada) {
    html += `<span class="selo-termo selo-termo--renovacao" title="Renovação assinada (marcada em ${formatarDataBR(v.dataAssinaturaRenovacao)})" aria-label="Renovação assinada">${icon('fileCheck')}</span>`;
  }
  return html;
}

/** Cartões "Termo do contrato" e (se renovado) "Termo da renovação". */
function htmlTermosAssinados(v) {
  const tipos = v.renovado ? ['termo', 'renovacao'] : ['termo'];
  return `<div class="termos-assinados">${tipos.map(tipo => {
    const cfg = TERMOS_ASSINADOS[tipo];
    const anexo = v[cfg.anexo];
    const assinado = Boolean(v[cfg.assinado]);
    const estado = assinado ? 'assinado' : (anexo ? 'anexado' : 'pendente');
    const textoEstado = {
      assinado: `Assinado · marcado em ${formatarDataBR(v[cfg.data])}`,
      anexado: 'Anexado · falta marcar como assinado',
      pendente: 'Pendente · nenhum termo assinado anexado',
    }[estado];
    const idCheck = `termo-assinado-${tipo}-${v.id}`;
    return `
      <div class="termo-card termo-card--${estado}" data-tipo-termo="${tipo}">
        <div class="termo-card__topo">
          <span class="termo-card__icone">${icon(assinado ? 'fileCheck' : 'file')}</span>
          <div class="termo-card__titulo">
            <strong>${cfg.titulo}</strong>
            <span>${escapeHtml(textoEstado)}</span>
          </div>
        </div>
        ${anexo ? `
          <div class="termo-card__arquivo">
            ${icon('paperclip')}
            <a href="${urlAnexoTermo(v.id, tipo)}" target="_blank" rel="noopener">Ver anexo</a>
            <span aria-hidden="true">·</span>
            <a href="${urlAnexoTermo(v.id, tipo, true)}" download>Baixar</a>
          </div>` : ''}
        <div class="termo-card__acoes">
          <label class="btn btn--outline btn--sm">
            ${icon('upload')} ${anexo ? 'Substituir anexo' : 'Anexar termo assinado'}
            <input type="file" accept="application/pdf,image/jpeg,image/png,image/webp" data-termo-arquivo hidden>
          </label>
          ${anexo ? `<button type="button" class="btn btn--danger-suave btn--sm" data-termo-remover>${icon('trash')} Remover</button>` : ''}
        </div>
        <label class="termo-card__check${anexo ? '' : ' termo-card__check--bloqueado'}" for="${idCheck}">
          <input type="checkbox" id="${idCheck}" data-termo-assinado ${assinado ? 'checked' : ''} ${anexo ? '' : 'disabled'}>
          <span>${tipo === 'termo' ? 'Termo assinado' : 'Renovação assinada'}</span>
        </label>
        ${anexo ? '' : '<small class="termo-card__dica">Anexe o termo assinado (PDF ou foto) para poder marcar.</small>'}
      </div>`;
  }).join('')}</div>`;
}

/** Resumo somente leitura da situação dos termos, com link para a página "Termos de Adesão". */
function htmlResumoTermos(v) {
  const tipos = v.renovado ? ['termo', 'renovacao'] : ['termo'];
  const itens = tipos.map(tipo => {
    const cfg = TERMOS_ASSINADOS[tipo];
    const anexo = v[cfg.anexo];
    if (v[cfg.assinado]) {
      return `<span class="badge badge--sucesso">${icon('fileCheck')}${cfg.titulo}: assinado em ${formatarDataBR(v[cfg.data])}</span>`;
    }
    return anexo
      ? `<span class="badge badge--info">${icon('paperclip')}${cfg.titulo}: anexado, falta marcar</span>`
      : `<span class="badge badge--alerta">${icon('file')}${cfg.titulo}: pendente</span>`;
  }).join('');
  return `
    <div class="termos-resumo-perfil">
      <div class="termos-resumo-perfil__selos">${itens}</div>
      <a class="btn btn--outline btn--sm" href="termos.html?id=${encodeURIComponent(v.id)}">${icon('fileCheck')} Anexar / marcar em Termos de Adesão</a>
    </div>`;
}

/** Liga os eventos dos cartões de htmlTermosAssinados(). aoAtualizar recebe o voluntário atualizado. */
function ligarTermosAssinados(raiz, v, aoAtualizar) {
  const executar = async (cartao, acao, corpo) => {
    cartao.classList.add('termo-card--ocupado');
    cartao.querySelectorAll('input, button').forEach(el => { el.disabled = true; });
    try {
      const resposta = await acaoTermoAssinado(acao, { idVoluntario: v.id, tipo: cartao.dataset.tipoTermo, ...corpo });
      showToast(resposta.mensagem, 'sucesso');
      aoAtualizar(resposta.dados);
    } catch (erro) {
      showToast(erro.message || 'Não foi possível atualizar o termo.', 'erro');
      aoAtualizar(v); // redesenha no estado anterior
    }
  };

  raiz.querySelectorAll('.termo-card').forEach(cartao => {
    cartao.querySelector('[data-termo-arquivo]').addEventListener('change', (e) => {
      const arquivo = e.target.files[0];
      if (!arquivo) return;
      if (arquivo.size > TAMANHO_MAXIMO_ANEXO_MB * 1024 * 1024) {
        showToast(`O arquivo não pode passar de ${TAMANHO_MAXIMO_ANEXO_MB} MB.`, 'erro');
        e.target.value = '';
        return;
      }
      const leitor = new FileReader();
      leitor.onload = () => executar(cartao, 'anexar', { arquivo: leitor.result });
      leitor.onerror = () => showToast('Não foi possível ler o arquivo.', 'erro');
      leitor.readAsDataURL(arquivo);
    });

    cartao.querySelector('[data-termo-assinado]').addEventListener('change', (e) => {
      executar(cartao, 'assinado', { assinado: e.target.checked });
    });

    const remover = cartao.querySelector('[data-termo-remover]');
    if (remover) {
      remover.addEventListener('click', () => showConfirm({
        titulo: 'Remover anexo',
        mensagem: 'O arquivo anexado será apagado e o termo deixará de constar como assinado. Deseja continuar?',
        textoConfirmar: 'Remover',
        onConfirm: () => executar(cartao, 'remover_anexo', {}),
      }));
    }
  });
}

/* ------------------------------------------------------------------ */
/* Documentos pessoais: um arquivo único por voluntário (ex.: um PDF   */
/* com todos juntos) + a lista do que ele contém (AnexoService.php).   */
/* ------------------------------------------------------------------ */
const TIPOS_DOCUMENTO = {
  rg: 'RG',
  cpf: 'CPF',
  comprovante_luz: 'Comprovante de Luz',
  historico_escolar: 'Histórico Escolar',
  certidao_negativa: 'Certidão Negativa',
};
const DOCUMENTOS_ESPERADOS = Object.keys(TIPOS_DOCUMENTO);
const TAMANHO_MAXIMO_DOCUMENTOS_MB = 20;

/** Sem id: o arquivo de todos os voluntários que têm um. Com id: o do voluntário (ou null). */
async function listarAnexos(idVoluntario) {
  const query = idVoluntario ? `?id_voluntario=${encodeURIComponent(idVoluntario)}` : '';
  return (await apiFetch(`anexos.php${query}`)).dados;
}

async function acaoAnexo(acao, corpo) {
  return apiFetch(`anexos.php?acao=${acao}`, { method: 'POST', body: JSON.stringify(corpo) });
}

function urlArquivoAnexo(idVoluntario, baixar = false) {
  return `${API_BASE}anexos.php?acao=arquivo&id_voluntario=${encodeURIComponent(idVoluntario)}${baixar ? '&baixar=1' : ''}`;
}

function formatarTamanhoArquivo(bytes) {
  if (!bytes) return '';
  return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} MB`;
}

/** Documentos marcados como presentes no arquivo do voluntário. */
function documentosEntregues(anexo) {
  return anexo ? DOCUMENTOS_ESPERADOS.filter(t => anexo.documentos.includes(t)) : [];
}

/** Selos compactos (✓/pendente) de cada documento, para listas. */
function htmlSelosDocumentos(anexo) {
  const entregues = new Set(documentosEntregues(anexo));
  return `<div class="docs-selos">${DOCUMENTOS_ESPERADOS.map(t => `
    <span class="docs-selo${entregues.has(t) ? ' docs-selo--ok' : ''}" title="${TIPOS_DOCUMENTO[t]}: ${entregues.has(t) ? 'no arquivo' : 'pendente'}">
      ${icon(entregues.has(t) ? 'checkCircle' : 'file')}${TIPOS_DOCUMENTO[t]}
    </span>`).join('')}</div>`;
}

/**
 * O arquivo do voluntário (ou a área para anexar) e a lista do que ele contém.
 * As marcações não são salvas a cada clique: ficam pendentes (anexo.pendente)
 * até o botão "Salvar" ou, com salvarAoFechar, até fechar o modal.
 */
function htmlDocumentosVoluntario(anexo, { salvarAoFechar = false } = {}) {
  const entregues = documentosEntregues(anexo);
  const completo = entregues.length === DOCUMENTOS_ESPERADOS.length;
  const entrada = `<input type="file" accept="application/pdf,image/jpeg,image/png,image/webp" data-docs-arquivo hidden>`;

  const arquivo = anexo ? `
    <div class="docs-arquivo-unico">
      <span class="docs-arquivo-unico__icone">${icon('file')}</span>
      <div class="docs-arquivo-unico__info">
        <a href="${urlArquivoAnexo(anexo.idVoluntario)}" target="_blank" rel="noopener">${escapeHtml(anexo.nomeOriginal)}</a>
        <small>Enviado em ${formatarDataBR(anexo.dataEnvio.slice(0, 10))}${anexo.tamanho ? ` · ${formatarTamanhoArquivo(anexo.tamanho)}` : ''}</small>
      </div>
      <div class="docs-arquivo-unico__acoes">
        <a class="btn btn--outline btn--sm" href="${urlArquivoAnexo(anexo.idVoluntario)}" target="_blank" rel="noopener">${icon('eye')} Abrir</a>
        <a class="btn btn--outline btn--sm" href="${urlArquivoAnexo(anexo.idVoluntario, true)}" download>${icon('download')} Baixar</a>
        <label class="btn btn--outline btn--sm">${icon('upload')} Substituir${entrada}</label>
        <button type="button" class="btn btn--danger-suave btn--sm btn--icon-only" data-docs-remover aria-label="Remover arquivo" data-dica="Remover">${icon('trash')}</button>
      </div>
    </div>` : `
    <label class="anexo-soltar" data-docs-soltar>
      ${icon('upload')}
      <span><b>Clique para escolher</b> ou arraste o arquivo aqui</span>
      <small>Um único arquivo com todos os documentos · PDF ou foto (JPG, PNG, WEBP) · até ${TAMANHO_MAXIMO_DOCUMENTOS_MB} MB</small>
      ${entrada}
    </label>`;

  return `
    <div class="docs-voluntario${anexo ? '' : ' docs-voluntario--sem-arquivo'}">
      ${arquivo}
      <div class="docs-checklist">
        <div class="docs-checklist__topo">
          <strong>O arquivo contém:</strong>
          <span class="docs-voluntario__progresso${completo ? ' docs-voluntario__progresso--completo' : ''}">
            ${icon(completo ? 'checkCircle' : 'file')}<b>${entregues.length} de ${DOCUMENTOS_ESPERADOS.length}</b> documentos
          </span>
        </div>
        ${anexo && anexo.pendente ? `
          <div class="docs-pendente">
            ${icon('alert')}
            ${salvarAoFechar
              ? '<span>Alterações não salvas — serão salvas ao fechar esta janela.</span>'
              : `<span>Alterações não salvas.</span><button type="button" class="btn btn--sm" data-docs-salvar>${icon('save')} Salvar</button>`}
          </div>` : ''}
        <div class="docs-checklist__itens">
          ${DOCUMENTOS_ESPERADOS.map(t => `
            <label class="docs-check${anexo ? '' : ' docs-check--bloqueado'}">
              <input type="checkbox" value="${t}" data-docs-item ${entregues.includes(t) ? 'checked' : ''} ${anexo ? '' : 'disabled'}>
              <span>${TIPOS_DOCUMENTO[t]}</span>
            </label>`).join('')}
        </div>
        ${anexo
          ? (salvarAoFechar && !anexo.pendente ? '<small class="termo-card__dica">Marque à vontade: as alterações são salvas ao fechar esta janela.</small>' : '')
          : '<small class="termo-card__dica">Anexe o arquivo para poder marcar os documentos que ele contém.</small>'}
      </div>
    </div>`;
}

/** Grava as marcações pendentes do anexo. Devolve o anexo salvo. */
async function salvarMarcacoesDocumentos(v, anexo) {
  const resposta = await acaoAnexo('documentos', { idVoluntario: v.id, documentos: anexo.documentos });
  return resposta.dados;
}

/**
 * Liga os controles de htmlDocumentosVoluntario(). aoAtualizar recebe o
 * novo estado do arquivo do voluntário (null depois de remover) — inclusive
 * marcações ainda não salvas, com pendente: true.
 */
function ligarDocumentosVoluntario(raiz, v, anexo, aoAtualizar) {
  const executar = async (acao, corpo, mensagemErro) => {
    raiz.querySelectorAll('input, button').forEach(el => { el.disabled = true; });
    // Vai no bloco (que é redesenhado por aoAtualizar), não na raiz — senão a raiz ficaria bloqueada para sempre.
    raiz.querySelector('.docs-voluntario').classList.add('docs-voluntario--ocupado');
    try {
      const resposta = await acaoAnexo(acao, { idVoluntario: v.id, ...corpo });
      let dados = resposta.dados;
      // Trocar o arquivo com marcações pendentes: grava as marcações junto, para não perdê-las.
      if (acao === 'anexar' && anexo && anexo.pendente) dados = await salvarMarcacoesDocumentos(v, anexo);
      showToast(resposta.mensagem, 'sucesso');
      aoAtualizar(dados);
    } catch (erro) {
      showToast(erro.message || mensagemErro, 'erro');
      aoAtualizar(anexo); // redesenha no estado anterior
    }
  };

  const enviar = (arquivo) => {
    if (!arquivo) return;
    if (arquivo.size > TAMANHO_MAXIMO_DOCUMENTOS_MB * 1024 * 1024) {
      showToast(`O arquivo não pode passar de ${TAMANHO_MAXIMO_DOCUMENTOS_MB} MB.`, 'erro');
      return;
    }
    const leitor = new FileReader();
    leitor.onload = () => executar('anexar', { nomeOriginal: arquivo.name, arquivo: leitor.result }, 'Não foi possível anexar o arquivo.');
    leitor.onerror = () => showToast('Não foi possível ler o arquivo.', 'erro');
    leitor.readAsDataURL(arquivo);
  };

  raiz.querySelector('[data-docs-arquivo]').addEventListener('change', (e) => enviar(e.target.files[0]));

  const soltar = raiz.querySelector('[data-docs-soltar]');
  if (soltar) {
    soltar.addEventListener('dragover', (e) => { e.preventDefault(); soltar.classList.add('anexo-soltar--ativo'); });
    soltar.addEventListener('dragleave', () => soltar.classList.remove('anexo-soltar--ativo'));
    soltar.addEventListener('drop', (e) => {
      e.preventDefault();
      soltar.classList.remove('anexo-soltar--ativo');
      if (e.dataTransfer.files.length > 1) showToast('Envie um único arquivo com todos os documentos.', 'erro');
      else enviar(e.dataTransfer.files[0]);
    });
  }

  // Marcar/desmarcar só muda a tela; a gravação é feita depois, de uma vez.
  raiz.querySelectorAll('[data-docs-item]').forEach(caixa => {
    caixa.addEventListener('change', () => {
      const marcados = [...raiz.querySelectorAll('[data-docs-item]:checked')].map(c => c.value);
      const salvos = anexo.documentosSalvos || anexo.documentos;
      const iguais = marcados.length === salvos.length && marcados.every(t => salvos.includes(t));
      aoAtualizar({ ...anexo, documentos: marcados, documentosSalvos: salvos, pendente: !iguais });
    });
  });

  const salvar = raiz.querySelector('[data-docs-salvar]');
  if (salvar) {
    salvar.addEventListener('click', () => executar('documentos', { documentos: anexo.documentos }, 'Não foi possível salvar a lista de documentos.'));
  }

  const remover = raiz.querySelector('[data-docs-remover]');
  if (remover) {
    remover.addEventListener('click', () => showConfirm({
      titulo: 'Remover arquivo',
      mensagem: 'O arquivo com os documentos será apagado e a lista do que ele contém será desmarcada. Deseja continuar?',
      textoConfirmar: 'Remover',
      onConfirm: () => executar('remover', {}, 'Não foi possível remover o arquivo.'),
    }));
  }
}

/* ------------------------------------------------------------------ */
/* Formatação                                                           */
/* ------------------------------------------------------------------ */
const DIAS_SEMANA_NOMES = {
  SEG: 'Segunda-feira', TER: 'Terça-feira', QUA: 'Quarta-feira', QUI: 'Quinta-feira',
  SEX: 'Sexta-feira', SAB: 'Sábado', DOM: 'Domingo',
};

const SEXO_LABEL = { masculino: 'Masculino', feminino: 'Feminino', outro: 'Outro', nao_informar: 'Prefiro não informar' };
const ESTADO_CIVIL_LABEL = { solteiro: 'Solteiro(a)', casado: 'Casado(a)', divorciado: 'Divorciado(a)', viuvo: 'Viúvo(a)', uniao_estavel: 'União estável' };

function formatarDiasSemanaExibicao(diasSemanaCsv) {
  if (!diasSemanaCsv) return '-';
  return diasSemanaCsv.split(',').map(c => DIAS_SEMANA_NOMES[c.trim()] || c.trim()).join(', ');
}

/** Telefone com link para ligar e para abrir no WhatsApp (DDI 55). */
function htmlContato(contato) {
  const digitos = (contato || '').replace(/\D/g, '');
  if (!digitos) return '-';
  return `<a href="tel:+55${digitos}">${escapeHtml(contato)}</a> · <a href="https://wa.me/55${digitos}" target="_blank" rel="noopener">WhatsApp</a>`;
}

function formatarDataBR(iso) {
  if (!iso) return '-';
  const [ano, mes, dia] = iso.split('-');
  if (!ano || !mes || !dia) return iso;
  return `${dia}/${mes}/${ano}`;
}

/** "01/10/2026 → 01/04/2027", deixando claro quando uma das datas falta. */
function formatarPeriodoBR(inicio, termino) {
  if (!inicio && !termino) return 'Período não informado';
  return `${inicio ? formatarDataBR(inicio) : 'Início não informado'} → ${termino ? formatarDataBR(termino) : 'término não informado'}`;
}

/** "07:00 às 11:00", ou "Não informado" quando não há horário cadastrado. */
function formatarHorario(inicio, termino) {
  if (!inicio && !termino) return 'Não informado';
  return `${(inicio || '?').slice(0, 5)} às ${(termino || '?').slice(0, 5)}`;
}

function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

/** Foto do voluntário ou, sem foto, um avatar com a inicial do nome. */
function fotoOuAvatar(v) {
  if (v.foto) return v.foto;
  const inicial = escapeHtml((v.nome || '?').trim().charAt(0).toUpperCase());
  return `data:image/svg+xml;utf8,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#dcecfd"/><stop offset="1" stop-color="#b9d8f7"/></linearGradient></defs><rect width="120" height="120" fill="url(#g)"/><text x="50%" y="55%" font-family="Segoe UI, Arial" font-weight="600" font-size="50" fill="#1c64b0" text-anchor="middle" dominant-baseline="middle">${inicial}</text></svg>`)}`;
}

/* ------------------------------------------------------------------ */
/* Máscaras                                                             */
/* ------------------------------------------------------------------ */
function maskCPF(value) {
  return value
    .replace(/\D/g, '')
    .slice(0, 11)
    .replace(/(\d{3})(\d)/, '$1.$2')
    .replace(/(\d{3})(\d)/, '$1.$2')
    .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
}

function maskTelefone(value) {
  const digitos = value.replace(/\D/g, '').slice(0, 11);
  if (digitos.length <= 10) {
    return digitos.replace(/(\d{2})(\d)/, '($1) $2').replace(/(\d{4})(\d)/, '$1-$2');
  }
  return digitos.replace(/(\d{2})(\d)/, '($1) $2').replace(/(\d{5})(\d)/, '$1-$2');
}

function maskCEP(value) {
  return value
    .replace(/\D/g, '')
    .slice(0, 8)
    .replace(/(\d{5})(\d)/, '$1-$2');
}

/* ------------------------------------------------------------------ */
/* Validações                                                           */
/* ------------------------------------------------------------------ */
function validarCPF(cpfFormatado) {
  const cpf = (cpfFormatado || '').replace(/\D/g, '');
  if (cpf.length !== 11) return false;
  if (/^(\d)\1{10}$/.test(cpf)) return false; // todos os dígitos iguais

  let soma = 0;
  for (let i = 0; i < 9; i++) soma += parseInt(cpf.charAt(i), 10) * (10 - i);
  let resto = (soma * 10) % 11;
  if (resto === 10 || resto === 11) resto = 0;
  if (resto !== parseInt(cpf.charAt(9), 10)) return false;

  soma = 0;
  for (let i = 0; i < 10; i++) soma += parseInt(cpf.charAt(i), 10) * (11 - i);
  resto = (soma * 10) % 11;
  if (resto === 10 || resto === 11) resto = 0;
  if (resto !== parseInt(cpf.charAt(10), 10)) return false;

  return true;
}

/* ------------------------------------------------------------------ */
/* Toasts (mensagens de sucesso/erro sem alert())                      */
/* ------------------------------------------------------------------ */
function garantirContainerToast() {
  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    container.className = 'toast-container';
    document.body.appendChild(container);
  }
  return container;
}

function showToast(mensagem, tipo = 'sucesso') {
  const container = garantirContainerToast();
  const toast = document.createElement('div');
  toast.className = `toast toast--${tipo}`;
  const iconeNome = tipo === 'sucesso' ? 'checkCircle' : (tipo === 'erro' ? 'xCircle' : 'alert');
  toast.innerHTML = `${icon(iconeNome)}<span>${escapeHtml(mensagem)}</span>`;
  container.appendChild(toast);

  requestAnimationFrame(() => toast.classList.add('toast--visivel'));

  setTimeout(() => {
    toast.classList.remove('toast--visivel');
    setTimeout(() => toast.remove(), 300);
  }, 4000);
}

/* ------------------------------------------------------------------ */
/* Modais                                                               */
/* ------------------------------------------------------------------ */

/**
 * Abre um modal com o HTML informado. Fecha ao clicar fora, com Esc ou em
 * qualquer elemento [data-fechar]. "podeFechar" permite travar o fechamento
 * (ex.: enquanto um documento está sendo gerado).
 */
function abrirModal(conteudoHtml, { classe = '', podeFechar = () => true } = {}) {
  const overlay = document.createElement('div');
  overlay.className = `modal-overlay ${classe}`;
  overlay.innerHTML = conteudoHtml;
  document.body.appendChild(overlay);
  requestAnimationFrame(() => overlay.classList.add('modal-overlay--visivel'));

  function fechar() {
    if (!podeFechar()) return;
    document.removeEventListener('keydown', aoTeclar);
    overlay.classList.remove('modal-overlay--visivel');
    setTimeout(() => overlay.remove(), 200);
  }
  function aoTeclar(e) {
    // Com modais empilhados (ex.: "Anexar documento" sobre a lista), Esc fecha só o de cima.
    if (e.key === 'Escape' && overlay === [...document.querySelectorAll('.modal-overlay')].pop()) fechar();
  }

  overlay.addEventListener('click', (e) => { if (e.target === overlay) fechar(); });
  overlay.querySelectorAll('[data-fechar]').forEach(el => el.addEventListener('click', fechar));
  document.addEventListener('keydown', aoTeclar);
  return { overlay, fechar };
}

/** Modal de confirmação (usado antes de excluir ou gerar documentos). */
function showConfirm({ titulo = 'Confirmar ação', mensagem, textoConfirmar = 'Confirmar', textoCancelar = 'Cancelar', perigo = true, onConfirm }) {
  const { overlay, fechar } = abrirModal(`
    <div class="modal-box modal-confirm" role="dialog" aria-modal="true">
      <div class="modal-confirm__icon ${perigo ? '' : 'modal-confirm__icon--info'}">${ICONS[perigo ? 'alert' : 'file']}</div>
      <h3>${escapeHtml(titulo)}</h3>
      <p>${escapeHtml(mensagem)}</p>
      <div class="modal-acoes modal-acoes--centro">
        <button type="button" class="btn btn--outline" data-fechar>${escapeHtml(textoCancelar)}</button>
        <button type="button" class="btn ${perigo ? 'btn--danger' : ''}" data-acao="confirmar">${escapeHtml(textoConfirmar)}</button>
      </div>
    </div>
  `);
  const botaoConfirmar = overlay.querySelector('[data-acao="confirmar"]');
  botaoConfirmar.focus();
  botaoConfirmar.addEventListener('click', () => {
    fechar();
    if (typeof onConfirm === 'function') onConfirm();
  });
}

/** Visualizador de foto em tamanho completo (sem recorte). */
function mostrarFotoCompleta(src, alt = 'Foto') {
  const { overlay } = abrirModal(`
    <div class="modal-foto__conteudo" role="dialog" aria-modal="true" aria-label="${escapeHtml(alt)}">
      <button type="button" class="modal-foto__fechar" aria-label="Fechar" data-fechar>&times;</button>
      <img class="modal-foto__img" src="${src}" alt="${escapeHtml(alt)}">
    </div>
  `, { classe: 'modal-foto' });
  overlay.querySelector('.modal-foto__fechar').focus();
}

/* ------------------------------------------------------------------ */
/* Mensagem "flash" (exibida em toast após redirecionar de página)     */
/* ------------------------------------------------------------------ */
const FLASH_KEY = 'sgv_flash';

function setFlashMessage(mensagem, tipo = 'sucesso') {
  sessionStorage.setItem(FLASH_KEY, JSON.stringify({ mensagem, tipo }));
}

function consumirFlashMessage() {
  const bruto = sessionStorage.getItem(FLASH_KEY);
  if (!bruto) return;
  sessionStorage.removeItem(FLASH_KEY);
  try {
    const { mensagem, tipo } = JSON.parse(bruto);
    showToast(mensagem, tipo);
  } catch (e) { /* ignora */ }
}

/* ------------------------------------------------------------------ */
/* Layout compartilhado (sidebar + topbar)                             */
/* Cada página só escreve o seu <main class="page-content">; o restante */
/* é montado aqui, para não repetir o menu em todos os HTML.           */
/* ------------------------------------------------------------------ */
const NAV_LINKS = [
  { href: 'index.html', icone: 'home', texto: 'Início' },
  { href: 'voluntarios.html', icone: 'users', texto: 'Voluntários' },
  { href: 'termos.html', icone: 'fileCheck', texto: 'Termos de Adesão' },
  { href: 'documentos.html', icone: 'paperclip', texto: 'Documentos' },
  { href: 'frequencia.html', icone: 'calendar', texto: 'Quadro de Frequência' },
  { href: 'por-cargo.html', icone: 'briefcase', texto: 'Por Cargo' },
  { href: 'cadastro.html', icone: 'plus', texto: 'Adicionar Voluntário' },
];

function montarLayout() {
  const main = document.querySelector('main.page-content');
  if (!main) return;

  // Páginas internas (ex.: visualizar) podem destacar outro item com data-nav no <body>.
  const paginaAtual = document.body.dataset.nav || location.pathname.split('/').pop() || 'index.html';
  const links = NAV_LINKS.map(l => `
    <a class="sidebar__link${l.href === paginaAtual ? ' sidebar__link--ativo' : ''}" href="${l.href}">
      ${icon(l.icone)}<span>${l.texto}</span>
    </a>
  `).join('');

  const layout = document.createElement('div');
  layout.className = 'app-layout';
  layout.innerHTML = `
    <div class="sidebar-overlay"></div>
    <aside class="sidebar">
      <a class="sidebar__brand" href="index.html">
        <img class="sidebar__logo sidebar__logo--clara" src="img/Logos/Logo preta.png" alt="SEMED Itapuã do Oeste/RO">
        <img class="sidebar__logo sidebar__logo--escura" src="img/Logos/Logo Branca.png" alt="SEMED Itapuã do Oeste/RO">
        <div>
          <strong>Gestão de Voluntários</strong>
          <span>Painel administrativo</span>
        </div>
      </a>
      <nav class="sidebar__nav">${links}</nav>
      <div class="sidebar__rodape">Prefeitura de Itapuã do Oeste/RO</div>
    </aside>
    <div class="main-area">
      <header class="topbar">
        <button class="topbar__toggle" type="button" aria-label="Abrir menu">${icon('menu')}</button>
        <span class="topbar__titulo" id="topo-titulo">${escapeHtml(document.body.dataset.titulo || '')}</span>
      </header>
    </div>
  `;
  main.before(layout);
  layout.querySelector('.main-area').appendChild(main);

  const sidebar = layout.querySelector('.sidebar');
  const overlay = layout.querySelector('.sidebar-overlay');
  const alternarMenu = (abrir) => {
    sidebar.classList.toggle('sidebar--aberta', abrir);
    overlay.classList.toggle('sidebar-overlay--visivel', abrir);
  };
  layout.querySelector('.topbar__toggle').addEventListener('click', () => alternarMenu(!sidebar.classList.contains('sidebar--aberta')));
  overlay.addEventListener('click', () => alternarMenu(false));
}

/* ------------------------------------------------------------------ */
/* Tema claro (azul claro) / escuro (azul escuro)                      */
/* ------------------------------------------------------------------ */
const TEMA_KEY = 'sgv_tema';

function temaAtual() {
  return document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';
}

function aplicarTema(tema) {
  if (tema === 'dark') document.documentElement.dataset.theme = 'dark';
  else delete document.documentElement.dataset.theme;
  try { localStorage.setItem(TEMA_KEY, tema); } catch (e) { /* ignora */ }
}

function initTema() {
  const topbar = document.querySelector('.topbar');
  if (!topbar) return;

  const botao = document.createElement('button');
  botao.type = 'button';
  botao.className = 'btn-tema';

  function atualizarBotao() {
    // O botão mostra o tema para o qual vai mudar
    const escuro = temaAtual() === 'dark';
    botao.innerHTML = `${icon(escuro ? 'sun' : 'moon')}<span class="btn-tema__texto">${escuro ? 'Tema claro' : 'Tema escuro'}</span>`;
    botao.title = escuro ? 'Mudar para o tema claro' : 'Mudar para o tema escuro';
    botao.setAttribute('aria-label', botao.title);
  }

  botao.addEventListener('click', () => {
    aplicarTema(temaAtual() === 'dark' ? 'light' : 'dark');
    atualizarBotao();
  });

  atualizarBotao();
  topbar.appendChild(botao);
}

/* ------------------------------------------------------------------ */
/* Inicialização                                                        */
/* app.js é carregado no fim do <body>, então o HTML da página já existe: */
/* o layout é montado na hora, antes dos scripts de cada página rodarem. */
/* ------------------------------------------------------------------ */
montarLayout();
hidratarIcones();
initTema();
document.addEventListener('DOMContentLoaded', consumirFlashMessage);
