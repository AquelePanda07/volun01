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

const SEXO_LABEL = { masculino: 'Masculino', feminino: 'Feminino', outro: 'Outro', nao_informar: 'Prefiro não informar' };
const ESTADO_CIVIL_LABEL = { solteiro: 'Solteiro(a)', casado: 'Casado(a)', divorciado: 'Divorciado(a)', viuvo: 'Viúvo(a)', uniao_estavel: 'União estável' };

let VOLUNTARIO_ATUAL = null;

document.addEventListener('DOMContentLoaded', async () => {
  const id = new URLSearchParams(location.search).get('id');
  const voluntario = id ? await buscarVoluntario(id) : null;

  if (!voluntario) {
    renderizarNaoEncontrado();
    return;
  }

  VOLUNTARIO_ATUAL = voluntario;
  document.title = `${voluntario.nome} — Sistema de Gestão de Voluntários`;
  renderizarVoluntario(voluntario);
  carregarHistoricoDocumentos(voluntario.id);
});

function renderizarNaoEncontrado() {
  document.getElementById('area-dados').innerHTML = `
    <div class="estado-vazio">
      ${icon('alert')}
      <p>Voluntário não encontrado. Ele pode ter sido excluído.</p>
      <a class="btn" href="voluntarios.html">Voltar à lista</a>
    </div>
  `;
}

function formatarDiasSemanaExibicao(diasSemanaCsv) {
  if (!diasSemanaCsv) return '-';
  return diasSemanaCsv.split(',').map(c => DIAS_SEMANA_NOMES[c.trim()] || c.trim()).join(', ');
}

function item(rotulo, valor, classe = '') {
  return `<div class="detalhe-item ${classe}"><b>${rotulo}</b><span>${valor}</span></div>`;
}

/** Telefone com link para ligar e para abrir no WhatsApp (DDI 55). */
function htmlContato(contato) {
  const digitos = (contato || '').replace(/\D/g, '');
  if (!digitos) return '-';
  return `<a href="tel:+55${digitos}">${escapeHtml(contato)}</a> · <a href="https://wa.me/55${digitos}" target="_blank" rel="noopener">WhatsApp</a>`;
}

/** Barra com o quanto do contrato já foi cumprido. */
function htmlProgressoContrato(v, status) {
  const progresso = calcularProgressoContrato(v.dataInicio, v.dataTermino);
  if (progresso === null) return '';
  const dias = calcularDiasRestantes(v.dataTermino);
  const descricao = status === 'A_INICIAR'
    ? `Começa em ${formatarDataBR(v.dataInicio)}`
    : (status === 'ENCERRADO' ? 'Contrato encerrado' : `${formatarDiasRestantes(dias, status)} para o término`);
  const classeBarra = status === 'ENCERRADO' ? 'progresso__barra--perigo' : (dias !== null && dias <= 30 ? 'progresso__barra--alerta' : '');

  return `
    <div class="progresso-contrato">
      <div class="progresso-contrato__topo">
        <span>${formatarDataBR(v.dataInicio)} → ${formatarDataBR(v.dataTermino)}</span>
        <span><b>${progresso}%</b> concluído · ${escapeHtml(descricao)}</span>
      </div>
      <div class="progresso" role="progressbar" aria-valuenow="${progresso}" aria-valuemin="0" aria-valuemax="100">
        <div class="progresso__barra ${classeBarra}" data-largura="${progresso}"></div>
      </div>
    </div>
  `;
}

function renderizarVoluntario(v) {
  const status = calcularStatusContrato(v.dataInicio, v.dataTermino);
  const id = encodeURIComponent(v.id);

  const endereco = [
    v.logradouro, v.numero ? `nº ${v.numero}` : '', v.complemento || '',
  ].filter(Boolean).join(', ') + (v.bairro ? ` - ${v.bairro}` : '') + (v.cidade ? `, ${v.cidade}` : '') + (v.estado ? `/${v.estado}` : '') + (v.cep ? ` — CEP ${v.cep}` : '');

  const idade = calcularIdade(v.dataNascimento);

  document.getElementById('area-dados').innerHTML = `
    <div class="perfil-topo">
      <div class="perfil-topo__capa"></div>
      <div class="perfil-topo__corpo">
        <img class="perfil-topo__foto${v.foto ? ' perfil-topo__foto--clicavel' : ''}" id="foto-perfil" src="${fotoOuAvatar(v)}" alt="Foto de ${escapeHtml(v.nome)}"${v.foto ? ' title="Clique para ver a foto completa"' : ''}>
        <div class="perfil-topo__info">
          <h1>${escapeHtml(v.nome)}</h1>
          <div class="perfil-topo__meta">
            ${badgeStatus(status)}${badgeRenovado(v)}
            <span>${icon('briefcase')} ${escapeHtml(v.cargo || '-')}</span>
            <span>${icon('mapPin')} ${escapeHtml(v.localPrestacao || '-')}</span>
          </div>
        </div>
        <div class="perfil-topo__acoes">
          ${podeRenovarContrato(v) ? `<button class="btn btn--outline btn--renovar" id="btn-renovar">${icon('repeat')} Marcar como renovado</button>` : ''}
          <a class="btn" href="gerar-termo.html?id=${id}">${icon('file')} Gerar Termo</a>
          <a class="btn btn--outline" href="cadastro.html?id=${id}">${icon('edit')} Editar</a>
          <button class="btn btn--danger-suave" id="btn-excluir">${icon('trash')} Excluir</button>
        </div>
      </div>
      ${htmlProgressoContrato(v, status)}
    </div>

    <section class="form-secao">
      <h2 class="form-secao__titulo">${icon('user')} Dados Pessoais</h2>
      <div class="detalhe-grid">
        ${item('Nome completo', escapeHtml(v.nome))}
        ${item('CPF', escapeHtml(v.cpf))}
        ${item('RG', escapeHtml(v.rg || '-'))}
        ${item('Órgão expedidor', escapeHtml(v.rgOrgaoExpedidor || '-'))}
        ${item('Data de expedição do RG', formatarDataBR(v.rgDataExpedicao))}
        ${item('Data de nascimento', formatarDataBR(v.dataNascimento))}
        ${item('Idade', idade === '' ? '-' : `${idade} anos`)}
        ${item('Sexo', escapeHtml(SEXO_LABEL[v.sexo] || v.sexo || '-'))}
        ${item('Grau de escolaridade', escapeHtml(v.escolaridade || '-'))}
        ${item('Estado civil', escapeHtml(ESTADO_CIVIL_LABEL[v.estadoCivil] || v.estadoCivil || '-'))}
        ${item('Contato', htmlContato(v.contato))}
      </div>
    </section>

    <section class="form-secao">
      <h2 class="form-secao__titulo">${icon('briefcase')} Dados da Prestação de Serviço</h2>
      <div class="detalhe-grid">
        ${item('Carga horária', `${escapeHtml(v.cargaHoraria)} horas`)}
        ${item('Cargo', escapeHtml(v.cargo || '-'))}
        ${item('Endereço completo', escapeHtml(endereco), 'detalhe-item--full')}
        ${item('Local de prestação de serviço', escapeHtml(v.localPrestacao || '-'))}
        ${item('Data de início', formatarDataBR(v.dataInicio))}
        ${item('Data de término', formatarDataBR(v.dataTermino))}
        ${v.renovado ? item('Renovação', `Renovado em ${formatarDataBR(v.dataRenovacao)} · término anterior: ${formatarDataBR(v.terminoAnterior)}`) : ''}
        ${item('Horário', `${escapeHtml((v.horarioInicio || '-').slice(0, 5))} às ${escapeHtml((v.horarioTermino || '-').slice(0, 5))}`)}
        ${item('Dias da semana', escapeHtml(formatarDiasSemanaExibicao(v.diasSemana)))}
        ${item('Secretaria', escapeHtml(v.secretaria || '-'))}
        ${item('Nome do Secretário', escapeHtml(v.nomeSecretario || '-'))}
      </div>
    </section>

    <section class="form-secao">
      <h2 class="form-secao__titulo">${icon('file')} Documentos</h2>
      <div id="area-documentos">
        <span class="skeleton" style="width:50%;margin-bottom:.6rem;"></span>
        <span class="skeleton" style="width:35%;"></span>
      </div>
    </section>
  `;

  requestAnimationFrame(() => requestAnimationFrame(() => {
    document.querySelectorAll('[data-largura]').forEach(el => { el.style.width = `${el.dataset.largura}%`; });
  }));

  if (v.foto) {
    document.getElementById('foto-perfil').addEventListener('click', () => {
      mostrarFotoCompleta(v.foto, `Foto de ${v.nome}`);
    });
  }

  const botaoRenovar = document.getElementById('btn-renovar');
  if (botaoRenovar) {
    botaoRenovar.addEventListener('click', () => abrirModalRenovacao(v, (atualizado) => {
      VOLUNTARIO_ATUAL = atualizado;
      renderizarVoluntario(atualizado);
      carregarHistoricoDocumentos(atualizado.id);
    }));
  }

  document.getElementById('btn-excluir').addEventListener('click', () => {
    showConfirm({
      titulo: 'Excluir voluntário',
      mensagem: `Tem certeza que deseja excluir o cadastro de "${v.nome}"? Esta ação não pode ser desfeita.`,
      textoConfirmar: 'Excluir',
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
    area.innerHTML = `<p class="texto-suave">Não foi possível carregar o histórico de documentos.</p>`;
  }
}

function renderizarHistoricoDocumentos(historico) {
  const area = document.getElementById('area-documentos');

  if (!historico || historico.length === 0) {
    area.innerHTML = `
      <p class="texto-suave">Nenhum documento gerado ainda.</p>
      <a class="btn btn--outline btn--sm" href="gerar-termo.html?id=${encodeURIComponent(VOLUNTARIO_ATUAL.id)}">${icon('file')} Gerar Termo de Adesão</a>
    `;
    return;
  }

  const linhas = historico.map((doc, i) => `
    <tr class="animar-entrada" style="--i:${i}">
      <td>${escapeHtml(doc.tipoDocumento)} <span class="badge badge--info">v${doc.versao}</span></td>
      <td>${formatarDataHoraBR(doc.dataGeracao)}</td>
      <td>
        <div class="acoes-cel">
          <a class="btn btn--outline btn--sm" href="${API_BASE}documentos.php?acao=baixar&id=${encodeURIComponent(doc.id)}" target="_blank" rel="noopener">${icon('eye')} Visualizar</a>
          <a class="btn btn--outline btn--sm" href="${API_BASE}documentos.php?acao=baixar&id=${encodeURIComponent(doc.id)}" download>${icon('download')} Baixar</a>
          <button class="btn btn--outline btn--sm" data-gerar-novamente="${doc.tipoDocumento.includes('PDF') ? 'pdf' : 'docx'}">${icon('refresh')} Gerar novamente</button>
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

  area.querySelectorAll('[data-gerar-novamente]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const formato = btn.getAttribute('data-gerar-novamente');
      btn.disabled = true;
      btn.querySelector('.icon').classList.add('girando');
      try {
        await gerarDocumento(VOLUNTARIO_ATUAL.id, formato);
        showToast('Termo de Adesão gerado novamente com sucesso!', 'sucesso');
        await carregarHistoricoDocumentos(VOLUNTARIO_ATUAL.id);
      } catch (erro) {
        const detalhes = (erro.erros && erro.erros.length) ? ' ' + erro.erros.join(' ') : '';
        showToast((erro.message || 'Não foi possível gerar o documento.') + detalhes, 'erro');
        btn.disabled = false;
        btn.querySelector('.icon').classList.remove('girando');
      }
    });
  });
}

function formatarDataHoraBR(dataHoraSql) {
  if (!dataHoraSql) return '-';
  const [data, hora] = dataHoraSql.split(' ');
  return `${formatarDataBR(data)}${hora ? ' às ' + hora.slice(0, 5) : ''}`;
}
