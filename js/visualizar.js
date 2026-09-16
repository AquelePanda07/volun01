/**
 * visualizar.js
 * Renderiza a visualização completa de um voluntário (identificado pelo
 * parâmetro "id" na URL), organizada por categorias, com ações de
 * editar/excluir/voltar, além do histórico de Termos de Adesão gerados.
 */

const DIAS_SEMANA_NOMES = {
  SEG: 'Segunda-feira', TER: 'Terça-feira', QUA: 'Quarta-feira', QUI: 'Quinta-feira',
  SEX: 'Sexta-feira', SAB: 'Sábado', DOM: 'Domingo',
};

let VOLUNTARIO_ATUAL = null;

document.addEventListener('DOMContentLoaded', async () => {
  const params = new URLSearchParams(location.search);
  const id = params.get('id');
  const voluntario = id ? await buscarVoluntario(id) : null;

  if (!voluntario) {
    renderizarNaoEncontrado();
    return;
  }

  VOLUNTARIO_ATUAL = voluntario;
  document.getElementById('titulo-pagina').textContent = `${voluntario.nome} — Sistema de Gestão de Voluntários`;
  renderizarVoluntario(voluntario);
  carregarHistoricoDocumentos(voluntario.id);
});

function renderizarNaoEncontrado() {
  document.getElementById('area-dados').innerHTML = `
    <div class="estado-vazio">
      <span class="icon">${ICONS.alert}</span>
      <p>Voluntário não encontrado. Ele pode ter sido excluído.</p>
      <a class="btn" href="voluntarios.html">Voltar à lista</a>
    </div>
  `;
}

function formatarDiasSemanaExibicao(diasSemanaCsv) {
  if (!diasSemanaCsv) return '-';
  return diasSemanaCsv.split(',').map(c => DIAS_SEMANA_NOMES[c.trim()] || c.trim()).join(', ');
}

function renderizarVoluntario(v) {
  const status = calcularStatusContrato(v.dataInicio, v.dataTermino);
  const badgeClasse = status === 'ATIVO' ? 'badge--sucesso' : (status === 'ENCERRADO' ? 'badge--perigo' : 'badge--alerta');
  const foto = v.foto || `data:image/svg+xml;utf8,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120"><rect width="120" height="120" fill="#c7d3d0"/><text x="50%" y="55%" font-family="Arial" font-size="48" fill="#2f5d50" text-anchor="middle" dominant-baseline="middle">${(v.nome||'?').charAt(0).toUpperCase()}</text></svg>`)}`;

  const sexoLabel = { masculino: 'Masculino', feminino: 'Feminino', outro: 'Outro', nao_informar: 'Prefiro não informar' }[v.sexo] || v.sexo || '-';
  const estadoCivilLabel = { solteiro: 'Solteiro(a)', casado: 'Casado(a)', divorciado: 'Divorciado(a)', viuvo: 'Viúvo(a)', uniao_estavel: 'União estável' }[v.estadoCivil] || v.estadoCivil || '-';

  const endereco = [
    v.logradouro, v.numero ? `nº ${v.numero}` : '', v.complemento || '',
  ].filter(Boolean).join(', ') + (v.bairro ? ` - ${v.bairro}` : '') + (v.cidade ? `, ${v.cidade}` : '') + (v.estado ? `/${v.estado}` : '') + (v.cep ? ` — CEP ${v.cep}` : '');

  document.getElementById('area-dados').innerHTML = `
    <div class="perfil-topo">
      <img class="perfil-topo__foto" src="${foto}" alt="Foto de ${escapeHtml(v.nome)}">
      <div class="perfil-topo__info">
        <h1>${escapeHtml(v.nome)}</h1>
        <div class="perfil-topo__meta">
          <span class="badge ${badgeClasse}">${icon(STATUS_ICON[status])}${STATUS_LABEL[status]}</span>
          <span>${icon('briefcase')} ${escapeHtml(v.cargo || '-')}</span>
          <span>${icon('eye')} ${escapeHtml(v.localPrestacao || '-')}</span>
        </div>
      </div>
      <div class="perfil-topo__acoes">
        <a class="btn" href="gerar-termo.html?id=${encodeURIComponent(v.id)}">${icon('briefcase')} Gerar Termo de Adesão</a>
        <a class="btn btn--outline" href="cadastro.html?id=${encodeURIComponent(v.id)}">${icon('edit')} Editar</a>
        <button class="btn btn--danger" id="btn-excluir">${icon('trash')} Excluir</button>
      </div>
    </div>

    <section class="form-secao">
      <h2 class="form-secao__titulo">${icon('user')} Dados Pessoais</h2>
      <div class="detalhe-grid">
        <div class="detalhe-item"><b>Nome completo</b><span>${escapeHtml(v.nome)}</span></div>
        <div class="detalhe-item"><b>CPF</b><span>${escapeHtml(v.cpf)}</span></div>
        <div class="detalhe-item"><b>RG</b><span>${escapeHtml(v.rg || '-')}</span></div>
        <div class="detalhe-item"><b>Órgão expedidor</b><span>${escapeHtml(v.rgOrgaoExpedidor || '-')}</span></div>
        <div class="detalhe-item"><b>Data de expedição do RG</b><span>${formatarDataBR(v.rgDataExpedicao)}</span></div>
        <div class="detalhe-item"><b>Data de nascimento</b><span>${formatarDataBR(v.dataNascimento)}</span></div>
        <div class="detalhe-item"><b>Idade</b><span>${calcularIdade(v.dataNascimento)} anos</span></div>
        <div class="detalhe-item"><b>Sexo</b><span>${escapeHtml(sexoLabel)}</span></div>
        <div class="detalhe-item"><b>Grau de escolaridade</b><span>${escapeHtml(v.escolaridade || '-')}</span></div>
        <div class="detalhe-item"><b>Estado civil</b><span>${escapeHtml(estadoCivilLabel)}</span></div>
      </div>
    </section>

    <section class="form-secao">
      <h2 class="form-secao__titulo">${icon('briefcase')} Dados da Prestação de Serviço</h2>
      <div class="detalhe-grid">
        <div class="detalhe-item"><b>Carga horária</b><span>${escapeHtml(v.cargaHoraria)} horas</span></div>
        <div class="detalhe-item"><b>Cargo</b><span>${escapeHtml(v.cargo || '-')}</span></div>
        <div class="detalhe-item campo--full" style="grid-column:1/-1"><b>Endereço completo</b><span>${escapeHtml(endereco)}</span></div>
        <div class="detalhe-item"><b>Local de prestação de serviço</b><span>${escapeHtml(v.localPrestacao || '-')}</span></div>
        <div class="detalhe-item"><b>Data de início</b><span>${formatarDataBR(v.dataInicio)}</span></div>
        <div class="detalhe-item"><b>Data de término</b><span>${formatarDataBR(v.dataTermino)}</span></div>
        <div class="detalhe-item"><b>Horário</b><span>${escapeHtml((v.horarioInicio || '-').slice(0,5))} às ${escapeHtml((v.horarioTermino || '-').slice(0,5))}</span></div>
        <div class="detalhe-item"><b>Dias da semana</b><span>${escapeHtml(formatarDiasSemanaExibicao(v.diasSemana))}</span></div>
        <div class="detalhe-item"><b>Secretaria</b><span>${escapeHtml(v.secretaria || '-')}</span></div>
        <div class="detalhe-item"><b>Nome do Secretário</b><span>${escapeHtml(v.nomeSecretario || '-')}</span></div>
      </div>
    </section>

    <section class="form-secao">
      <h2 class="form-secao__titulo">${icon('list')} Documentos</h2>
      <div id="area-documentos">
        <p>Carregando histórico de documentos...</p>
      </div>
    </section>
  `;

  document.getElementById('btn-excluir').addEventListener('click', () => {
    showConfirm({
      titulo: 'Excluir voluntário',
      mensagem: `Tem certeza que deseja excluir o cadastro de "${v.nome}"? Esta ação não pode ser desfeita.`,
      textoConfirmar: 'Excluir',
      textoCancelar: 'Cancelar',
      onConfirm: async () => {
        try {
          await excluirVoluntario(v.id);
          setFlashMessage('Voluntário excluído com sucesso.', 'sucesso');
          location.href = 'voluntarios.html';
        } catch (erro) {
          showToast(erro.message || 'Não foi possível excluir o voluntário.', 'erro');
        }
      }
    });
  });
}

/* ------------------------------------------------------------------ */
/* Histórico de documentos gerados (Termo de Adesão)                    */
/* ------------------------------------------------------------------ */
async function carregarHistoricoDocumentos(idVoluntario) {
  const area = document.getElementById('area-documentos');
  try {
    const historico = await historicoDocumentos(idVoluntario);
    renderizarHistoricoDocumentos(historico);
  } catch (erro) {
    area.innerHTML = `<p>Não foi possível carregar o histórico de documentos.</p>`;
  }
}

function renderizarHistoricoDocumentos(historico) {
  const area = document.getElementById('area-documentos');

  if (!historico || historico.length === 0) {
    area.innerHTML = `
      <p>Nenhum documento gerado ainda.</p>
      <a class="btn btn--outline btn--sm" href="gerar-termo.html?id=${encodeURIComponent(VOLUNTARIO_ATUAL.id)}">${icon('briefcase')} Gerar Termo de Adesão</a>
    `;
    return;
  }

  const linhas = historico.map(doc => `
    <tr>
      <td>${escapeHtml(doc.tipoDocumento)} <span class="badge badge--alerta" style="margin-left:.4rem;">v${doc.versao}</span></td>
      <td>${formatarDataHoraBR(doc.dataGeracao)}</td>
      <td>
        <div class="acoes-cel">
          <button class="btn btn--outline btn--sm" data-visualizar-doc="${doc.id}">${icon('eye')} Visualizar</button>
          <button class="btn btn--outline btn--sm" data-baixar-doc="${doc.id}">${icon('checkCircle')} Baixar</button>
          <button class="btn btn--outline btn--sm" data-gerar-novamente="${doc.tipoDocumento.includes('PDF') ? 'pdf' : 'docx'}">${icon('plus')} Gerar novamente</button>
        </div>
      </td>
    </tr>
  `).join('');

  area.innerHTML = `
    <div class="tabela-wrapper">
      <table class="tabela-voluntarios">
        <thead><tr><th>Documento</th><th>Data</th><th>Ações</th></tr></thead>
        <tbody>${linhas}</tbody>
      </table>
    </div>
  `;

  area.querySelectorAll('[data-visualizar-doc]').forEach(btn => {
    btn.addEventListener('click', () => {
      window.open(`${API_BASE}documentos.php?acao=baixar&id=${btn.getAttribute('data-visualizar-doc')}`, '_blank');
    });
  });
  area.querySelectorAll('[data-baixar-doc]').forEach(btn => {
    btn.addEventListener('click', () => {
      window.location.href = `${API_BASE}documentos.php?acao=baixar&id=${btn.getAttribute('data-baixar-doc')}`;
    });
  });
  area.querySelectorAll('[data-gerar-novamente]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const formato = btn.getAttribute('data-gerar-novamente');
      btn.disabled = true;
      try {
        await gerarDocumento(VOLUNTARIO_ATUAL.id, formato);
        showToast('Termo de Adesão gerado novamente com sucesso!', 'sucesso');
        await carregarHistoricoDocumentos(VOLUNTARIO_ATUAL.id);
      } catch (erro) {
        const detalhes = (erro.erros && erro.erros.length) ? ' ' + erro.erros.join(' ') : '';
        showToast((erro.message || 'Não foi possível gerar o documento.') + detalhes, 'erro');
        btn.disabled = false;
      }
    });
  });
}

function formatarDataHoraBR(dataHoraSql) {
  if (!dataHoraSql) return '-';
  const [data, hora] = dataHoraSql.split(' ');
  return `${formatarDataBR(data)}${hora ? ' às ' + hora.slice(0, 5) : ''}`;
}
