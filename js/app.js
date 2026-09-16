/**
 * app.js
 * Núcleo compartilhado do Sistema de Gestão de Voluntários.
 * Contém: cliente da API PHP (fetch), cálculos (idade/status), máscaras,
 * validações, ícones SVG, toasts, modal de confirmação e navegação.
 * Este arquivo é carregado em TODAS as páginas.
 */

/* ------------------------------------------------------------------ */
/* Constantes                                                          */
/* ------------------------------------------------------------------ */
const API_BASE = 'api/';

/* ------------------------------------------------------------------ */
/* Ícones (SVG inline - estilo "feather", leves e sem dependências)    */
/* ------------------------------------------------------------------ */
const ICONS = {
  plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>',
  list: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>',
  eye: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>',
  edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>',
  trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path></svg>',
  search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>',
  back: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>',
  users: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>',
  checkCircle: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>',
  xCircle: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>',
  clock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>',
  menu: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>',
  camera: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>',
  alert: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>',
  user: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>',
  briefcase: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>'
};

function icon(name, extraClass) {
  return `<span class="icon ${extraClass || ''}">${ICONS[name] || ''}</span>`;
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
    throw new ErroApi(corpo.mensagem || 'Ocorreu um erro ao comunicar com o servidor.', corpo.erros);
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
  const resposta = await apiFetch('documentos.php', {
    method: 'POST',
    body: JSON.stringify({ idVoluntario, formato, dataEmissao }),
  });
  return resposta;
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
  const alpha = (0.12 + intensidade * 0.5).toFixed(2);
  return `background-color: rgba(201, 138, 31, ${alpha});`;
}

function zerarHora(data) {
  data.setHours(0, 0, 0, 0);
  return data;
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

/* ------------------------------------------------------------------ */
/* Formatação                                                           */
/* ------------------------------------------------------------------ */
function formatarDataBR(iso) {
  if (!iso) return '-';
  const [ano, mes, dia] = iso.split('-');
  if (!ano || !mes || !dia) return iso;
  return `${dia}/${mes}/${ano}`;
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
/* Modal de confirmação (usado antes de excluir)                       */
/* ------------------------------------------------------------------ */
function showConfirm({ titulo = 'Confirmar ação', mensagem, textoConfirmar = 'Confirmar', textoCancelar = 'Cancelar', onConfirm }) {
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.innerHTML = `
    <div class="modal-box modal-confirm" role="dialog" aria-modal="true">
      <div class="modal-confirm__icon">${ICONS.alert}</div>
      <h3>${escapeHtml(titulo)}</h3>
      <p>${escapeHtml(mensagem)}</p>
      <div class="modal-confirm__actions">
        <button type="button" class="btn btn--outline" data-acao="cancelar">${escapeHtml(textoCancelar)}</button>
        <button type="button" class="btn btn--danger" data-acao="confirmar">${escapeHtml(textoConfirmar)}</button>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
  requestAnimationFrame(() => overlay.classList.add('modal-overlay--visivel'));

  function fechar() {
    overlay.classList.remove('modal-overlay--visivel');
    setTimeout(() => overlay.remove(), 200);
  }

  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) fechar();
  });
  overlay.querySelector('[data-acao="cancelar"]').addEventListener('click', fechar);
  overlay.querySelector('[data-acao="confirmar"]').addEventListener('click', () => {
    fechar();
    if (typeof onConfirm === 'function') onConfirm();
  });
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
/* Navegação (sidebar/topbar compartilhada)                            */
/* ------------------------------------------------------------------ */
function initNavegacao() {
  const toggle = document.querySelector('[data-nav-toggle]');
  const sidebar = document.querySelector('.sidebar');
  const overlay = document.querySelector('.sidebar-overlay');

  if (toggle && sidebar) {
    toggle.addEventListener('click', () => {
      sidebar.classList.toggle('sidebar--aberta');
      if (overlay) overlay.classList.toggle('sidebar-overlay--visivel');
    });
  }
  if (overlay && sidebar) {
    overlay.addEventListener('click', () => {
      sidebar.classList.remove('sidebar--aberta');
      overlay.classList.remove('sidebar-overlay--visivel');
    });
  }

  // Marca o link ativo com base na página atual
  const paginaAtual = location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.sidebar__link').forEach(link => {
    const href = link.getAttribute('href');
    if (href === paginaAtual) link.classList.add('sidebar__link--ativo');
  });
}

document.addEventListener('DOMContentLoaded', () => {
  initNavegacao();
  consumirFlashMessage();
});
