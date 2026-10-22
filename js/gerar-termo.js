/**
 * gerar-termo.js
 * Tela "Gerar Termo de Adesão": mostra o resumo do voluntário, permite
 * pré-visualizar o documento preenchido e gerar o arquivo final em
 * PDF ou DOCX, sempre com confirmação antes da geração definitiva.
 */

let VOLUNTARIO_TERMO = null;

document.addEventListener('DOMContentLoaded', async () => {
  const id = new URLSearchParams(location.search).get('id');

  if (id) document.getElementById('link-voltar').href = `visualizar.html?id=${encodeURIComponent(id)}`;

  const voluntario = id ? await buscarVoluntario(id) : null;
  if (!voluntario) {
    document.getElementById('area-termo').innerHTML = `
      <div class="estado-vazio">
        ${icon('alert')}
        <p>Voluntário não encontrado. Ele pode ter sido excluído.</p>
        <a class="btn" href="voluntarios.html">Voltar à lista</a>
      </div>
    `;
    return;
  }

  VOLUNTARIO_TERMO = voluntario;
  renderizarResumo(voluntario);
});

function renderizarResumo(v) {
  document.getElementById('area-termo').innerHTML = `
    <section class="form-secao">
      <h2 class="form-secao__titulo">${icon('user')} Dados do Voluntário</h2>
      <div class="perfil-topo perfil-topo--compacto">
        <div class="perfil-topo__corpo">
          <img class="perfil-topo__foto" src="${fotoOuAvatar(v)}" alt="Foto de ${escapeHtml(v.nome)}">
          <div class="perfil-topo__info">
            <h1>${escapeHtml(v.nome)}</h1>
            <div class="perfil-topo__meta">
              ${badgeStatus(calcularStatusContrato(v.dataInicio, v.dataTermino))}
              <span>${icon('briefcase')} ${escapeHtml(v.cargo || '-')}</span>
            </div>
          </div>
        </div>
      </div>
      <div class="detalhe-grid" style="margin-top:1.2rem;">
        <div class="detalhe-item"><b>CPF</b><span>${escapeHtml(v.cpf)}</span></div>
        <div class="detalhe-item"><b>Local de prestação</b><span>${escapeHtml(v.localPrestacao || '-')}</span></div>
        <div class="detalhe-item"><b>Data de início</b><span>${formatarDataBR(v.dataInicio)}</span></div>
        <div class="detalhe-item"><b>Data de término</b><span>${formatarDataBR(v.dataTermino)}</span></div>
        <div class="detalhe-item"><b>Secretaria</b><span>${escapeHtml(v.secretaria || '-')}</span></div>
      </div>
    </section>

    <section class="form-secao">
      <h2 class="form-secao__titulo">${icon('calendar')} Data de Emissão do Documento</h2>
      <div class="campo campo--estreito">
        <label for="dataEmissao">Data de emissão (Itapuã do Oeste, RO)</label>
        <input type="date" id="dataEmissao" value="${hojeISO()}">
      </div>
    </section>

    <div id="area-alerta-termo"></div>

    <div class="form-acoes form-acoes--inicio">
      <button type="button" class="btn btn--outline" id="btn-visualizar">${icon('eye')} Pré-visualizar</button>
      <button type="button" class="btn" id="btn-gerar-pdf">${icon('download')} Gerar PDF</button>
      <button type="button" class="btn" id="btn-gerar-docx">${icon('download')} Gerar DOCX</button>
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
    <div class="alerta">
      ${icon('alert')}
      <div>
        <b>Não é possível gerar o Termo de Adesão. Corrija os itens abaixo:</b>
        <ul>${erros.map(e => `<li>${escapeHtml(e)}</li>`).join('')}</ul>
        <a class="btn btn--outline btn--sm" href="cadastro.html?id=${encodeURIComponent(VOLUNTARIO_TERMO.id)}">${icon('edit')} Editar cadastro</a>
      </div>
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
  const { overlay } = abrirModal(`
    <div class="modal-box modal-box--largo" role="dialog" aria-modal="true">
      <div class="modal-cabecalho">
        <h3>Pré-visualização do Termo de Adesão</h3>
        <button type="button" class="btn btn--outline btn--sm" data-fechar>Fechar</button>
      </div>
      <iframe class="modal-iframe" title="Pré-visualização do Termo de Adesão"></iframe>
    </div>
  `);
  overlay.querySelector('iframe').srcdoc = html;
}

function confirmarGeracao(formato) {
  const dataEmissao = document.getElementById('dataEmissao').value;

  // Confirma antes de checar/gerar, para não surpreender o usuário; erros de
  // dados pendentes ainda impedem a geração definitiva (ver validarParaGeracao no back-end).
  showConfirm({
    titulo: `Gerar Termo de Adesão em ${formato.toUpperCase()}`,
    mensagem: `Confirma a geração do Termo de Adesão de "${VOLUNTARIO_TERMO.nome}" em ${formato.toUpperCase()}, com data de emissão ${formatarDataBR(dataEmissao)}? Os campos de assinatura permanecerão em branco.`,
    textoConfirmar: 'Gerar documento',
    perigo: false,
    onConfirm: () => gerarEBaixar(formato, dataEmissao),
  });
}

async function gerarEBaixar(formato, dataEmissao) {
  const botao = document.getElementById(formato === 'pdf' ? 'btn-gerar-pdf' : 'btn-gerar-docx');
  const textoOriginal = botao.innerHTML;
  botao.disabled = true;
  botao.innerHTML = `${icon('refresh', 'girando')} Gerando...`;

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
