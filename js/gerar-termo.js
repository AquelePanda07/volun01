/**
 * gerar-termo.js
 * Tela "Gerar Termo de Adesão": mostra o resumo do voluntário, permite
 * pré-visualizar o documento preenchido e gerar o arquivo final em
 * PDF ou DOCX, sempre com confirmação antes da geração definitiva.
 */

let VOLUNTARIO_TERMO = null;

document.addEventListener('DOMContentLoaded', async () => {
  const params = new URLSearchParams(location.search);
  const id = params.get('id');

  document.getElementById('link-voltar').href = id ? `visualizar.html?id=${encodeURIComponent(id)}` : 'voluntarios.html';

  const voluntario = id ? await buscarVoluntario(id) : null;
  if (!voluntario) {
    document.getElementById('area-termo').innerHTML = `
      <div class="estado-vazio">
        <span class="icon">${ICONS.alert}</span>
        <p>Voluntário não encontrado. Ele pode ter sido excluído.</p>
        <a class="btn" href="voluntarios.html">Voltar à lista</a>
      </div>
    `;
    return;
  }

  VOLUNTARIO_TERMO = voluntario;
  renderizarResumo(voluntario);
});

function hojeISO() {
  const hoje = new Date();
  const mes = String(hoje.getMonth() + 1).padStart(2, '0');
  const dia = String(hoje.getDate()).padStart(2, '0');
  return `${hoje.getFullYear()}-${mes}-${dia}`;
}

function renderizarResumo(v) {
  const foto = v.foto || `data:image/svg+xml;utf8,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96"><rect width="96" height="96" fill="#c7d3d0"/><text x="50%" y="55%" font-family="Arial" font-size="40" fill="#2f5d50" text-anchor="middle" dominant-baseline="middle">${(v.nome||'?').charAt(0).toUpperCase()}</text></svg>`)}`;

  document.getElementById('area-termo').innerHTML = `
    <section class="form-secao">
      <h2 class="form-secao__titulo">${icon('user')} Dados do Voluntário</h2>
      <div class="perfil-topo" style="margin-bottom:0;">
        <img class="perfil-topo__foto" src="${foto}" alt="Foto de ${escapeHtml(v.nome)}" style="width:96px;height:96px;">
        <div class="perfil-topo__info">
          <h1 style="font-size:1.3rem;">${escapeHtml(v.nome)}</h1>
          <div class="perfil-topo__meta">
            <span>${icon('briefcase')} ${escapeHtml(v.cargo || '-')}</span>
          </div>
        </div>
      </div>
      <div class="detalhe-grid" style="margin-top:1.2rem;">
        <div class="detalhe-item"><b>CPF</b><span>${escapeHtml(v.cpf)}</span></div>
        <div class="detalhe-item"><b>Cargo</b><span>${escapeHtml(v.cargo || '-')}</span></div>
        <div class="detalhe-item"><b>Local de prestação</b><span>${escapeHtml(v.localPrestacao || '-')}</span></div>
        <div class="detalhe-item"><b>Data de início</b><span>${formatarDataBR(v.dataInicio)}</span></div>
        <div class="detalhe-item"><b>Data de término</b><span>${formatarDataBR(v.dataTermino)}</span></div>
        <div class="detalhe-item"><b>Secretaria</b><span>${escapeHtml(v.secretaria || '-')}</span></div>
      </div>
    </section>

    <section class="form-secao">
      <h2 class="form-secao__titulo">${icon('list')} Data de Emissão do Documento</h2>
      <div class="campo" style="max-width:260px;">
        <label for="dataEmissao">Data de emissão (Itapuã do Oeste, RO)</label>
        <input type="date" id="dataEmissao" value="${hojeISO()}">
      </div>
    </section>

    <div id="area-alerta-termo"></div>

    <div class="form-acoes" style="justify-content:flex-start; flex-wrap:wrap;">
      <button type="button" class="btn btn--outline" id="btn-visualizar">${icon('eye')} Visualizar Documento</button>
      <button type="button" class="btn" id="btn-gerar-pdf">${icon('checkCircle')} Gerar PDF</button>
      <button type="button" class="btn" id="btn-gerar-docx">${icon('checkCircle')} Gerar DOCX</button>
      <a class="btn btn--outline" href="visualizar.html?id=${encodeURIComponent(v.id)}">${icon('back')} Voltar</a>
    </div>
  `;

  document.getElementById('btn-visualizar').addEventListener('click', abrirPreVisualizacao);
  document.getElementById('btn-gerar-pdf').addEventListener('click', () => confirmarGeracao('pdf'));
  document.getElementById('btn-gerar-docx').addEventListener('click', () => confirmarGeracao('docx'));
}

function exibirErrosGeracao(erros) {
  const area = document.getElementById('area-alerta-termo');
  if (!erros || erros.length === 0) {
    area.innerHTML = '';
    return;
  }
  area.innerHTML = `
    <div class="estado-vazio" style="text-align:left; border-color:var(--cor-perigo);">
      <span class="icon">${ICONS.alert}</span>
      <p><b>Não é possível gerar o Termo de Adesão. Corrija os itens abaixo:</b></p>
      <ul style="margin:.4rem 0 0 1.2rem;">${erros.map(e => `<li>${escapeHtml(e)}</li>`).join('')}</ul>
      <a class="btn btn--outline" style="margin-top:.8rem;" href="cadastro.html?id=${encodeURIComponent(VOLUNTARIO_TERMO.id)}">Editar cadastro</a>
    </div>
  `;
}

async function abrirPreVisualizacao() {
  const dataEmissao = document.getElementById('dataEmissao').value;
  const botao = document.getElementById('btn-visualizar');
  botao.disabled = true;
  try {
    const resposta = await previsualizarTermo(VOLUNTARIO_TERMO.id, dataEmissao);
    if (!resposta.sucesso) {
      exibirErrosGeracao(resposta.erros);
      showToast('Há dados obrigatórios pendentes para gerar o Termo de Adesão.', 'erro');
      return;
    }
    exibirErrosGeracao([]);
    abrirModalPreVisualizacao(resposta.html);
  } catch (erro) {
    showToast(erro.message || 'Não foi possível pré-visualizar o documento.', 'erro');
  } finally {
    botao.disabled = false;
  }
}

function abrirModalPreVisualizacao(html) {
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.innerHTML = `
    <div class="modal-box" style="max-width:900px; width:95%; height:85vh; display:flex; flex-direction:column;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:.8rem;">
        <h3 style="margin:0;">Pré-visualização do Termo de Adesão</h3>
        <button type="button" class="btn btn--outline btn--sm" data-fechar-preview>Fechar</button>
      </div>
      <iframe id="iframe-preview-termo" style="flex:1; width:100%; border:1px solid #d8dedc; border-radius:.5rem; background:#fff;"></iframe>
    </div>
  `;
  document.body.appendChild(overlay);
  requestAnimationFrame(() => overlay.classList.add('modal-overlay--visivel'));

  const iframe = overlay.querySelector('#iframe-preview-termo');
  iframe.srcdoc = html;

  function fechar() {
    overlay.classList.remove('modal-overlay--visivel');
    setTimeout(() => overlay.remove(), 200);
  }
  overlay.addEventListener('click', (e) => { if (e.target === overlay) fechar(); });
  overlay.querySelector('[data-fechar-preview]').addEventListener('click', fechar);
}

async function confirmarGeracao(formato) {
  const dataEmissao = document.getElementById('dataEmissao').value;

  // Confirma antes de checar/gerar, para não surpreender o usuário; erros de
  // dados pendentes ainda impedem a geração definitiva (ver validarParaGeracao no back-end).
  showConfirm({
    titulo: `Gerar Termo de Adesão em ${formato.toUpperCase()}`,
    mensagem: `Confirma a geração do Termo de Adesão de "${VOLUNTARIO_TERMO.nome}" em ${formato.toUpperCase()}, com data de emissão ${formatarDataBR(dataEmissao)}? Os campos de assinatura permanecerão em branco.`,
    textoConfirmar: 'Gerar documento',
    textoCancelar: 'Cancelar',
    onConfirm: () => gerarEDaixar(formato, dataEmissao),
  });
}

async function gerarEDaixar(formato, dataEmissao) {
  const botao = formato === 'pdf' ? document.getElementById('btn-gerar-pdf') : document.getElementById('btn-gerar-docx');
  const textoOriginal = botao.innerHTML;
  botao.disabled = true;
  botao.innerHTML = `${icon('clock')} Gerando...`;

  try {
    const resposta = await gerarDocumento(VOLUNTARIO_TERMO.id, formato, dataEmissao);
    exibirErrosGeracao([]);
    showToast('Termo de Adesão gerado com sucesso!', 'sucesso');
    window.location.href = resposta.urlDownload;
  } catch (erro) {
    if (erro.erros && erro.erros.length) {
      exibirErrosGeracao(erro.erros);
    }
    showToast(erro.message || 'Não foi possível gerar o documento.', 'erro');
  } finally {
    botao.disabled = false;
    botao.innerHTML = textoOriginal;
  }
}
