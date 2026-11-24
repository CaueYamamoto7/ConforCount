'use strict';

function resumoRegimes(m) {
  return (m.regimes || [])
    .map(
      (r) =>
        '<span class="pill acc">' +
        esc(r.replace('Simples Nacional', 'Simples').replace('Lucro ', 'L. ')) +
        '</span>',
    )
    .join(' ');
}
function viewModelos() {
  let h =
    '<div class="banner warn"><p><b>Confira os vencimentos.</b> Os prazos abaixo são pontos de partida para você revisar. O cálculo considera dia útil nacional, Carnaval e Corpus Christi; feriados estaduais e municipais entram na lista ao final. O dia exato de ISS, ICMS e guias locais varia por município e estado.</p></div>';
  h +=
    '<div class="card"><div class="hd"><h2>Obrigações por departamento</h2><button class="btn primary" data-act="novo-md">Nova obrigação</button></div><p class="note" style="margin:0 0 10px">Cada obrigação vale para os regimes marcados e, se tiver uma <b>característica</b> exigida, só chega aos clientes que a têm (ex.: ISS só para quem tem ISS). Alterações valem para todos os clientes deste escritório; use "Aplica a partir de" para não mexer em meses passados.</p>';
  if (!state.modelos.length) h += '<div class="empty">Nenhuma obrigação ainda.</div>';
  state.departamentos.forEach((d) => {
    const l = state.modelos
      .filter((m) => m.deptoId === d.id)
      .sort((a, b) => a.nome.localeCompare(b.nome, 'pt-BR'));
    if (!l.length) return;
    h +=
      '<h3 class="dh">' +
      esc(d.nome) +
      ' <span class="pill mute">' +
      l.length +
      '</span></h3><div class="scroll"><table><thead><tr><th>Obrigação</th><th>Regimes</th><th>Quem recebe</th><th>Quando</th><th>Vencimento</th><th></th></tr></thead><tbody>' +
      l
        .map(
          (m) =>
            '<tr><td>' +
            esc(m.nome) +
            (m.ativo === false ? ' <span class="pill mute">Desligada</span>' : '') +
            (m.obs ? '<span class="sub">' + esc(m.obs) + '</span>' : '') +
            (m.desde
              ? '<span class="sub">a partir de ' +
                esc(m.desde.slice(5) + '/' + m.desde.slice(0, 4)) +
                '</span>'
              : '') +
            '</td><td>' +
            resumoRegimes(m) +
            '</td><td>' +
            (m.caractId
              ? '<span class="pill info">' + esc(nomeCar(m.caractId)) + '</span>'
              : '<span class="sub">Todos do regime</span>') +
            '</td><td>' +
            esc(descFreq(m)) +
            '</td><td class="nowrap">' +
            esc(descVenc(m)) +
            '</td><td class="nowrap"><button class="btn sm" data-act="edit-md" data-id="' +
            esc(m.id) +
            '">Editar</button></td></tr>',
        )
        .join('') +
      '</tbody></table></div>';
  });
  h += '</div>';
  const nDep = (id) => state.modelos.filter((m) => m.deptoId === id).length;
  h +=
    '<div class="card"><div class="hd"><h2>Departamentos</h2><button class="btn primary" data-act="novo-dep">Novo departamento</button></div><div class="chips">' +
    (state.departamentos
      .map(
        (d) =>
          '<button class="tag" data-act="edit-dep" data-id="' +
          esc(d.id) +
          '">' +
          esc(d.nome) +
          ' <small>' +
          nDep(d.id) +
          '</small></button>',
      )
      .join('') || '<span class="note">Nenhum.</span>') +
    '</div><p class="note">Os departamentos organizam as colunas da tela Obrigações (ex.: Fiscal, Contábil, Pessoal, Societário).</p></div>';
  const nCarCli = (id) => state.clientes.filter((c) => (c.caract || []).includes(id)).length;
  const nCarMod = (id) => state.modelos.filter((m) => m.caractId === id).length;
  h +=
    '<div class="card"><div class="hd"><h2>Características dos clientes</h2><button class="btn primary" data-act="novo-car">Nova característica</button></div><div class="chips">' +
    (state.caracteristicas
      .map(
        (k) =>
          '<button class="tag" data-act="edit-car" data-id="' +
          esc(k.id) +
          '" title="' +
          nCarCli(k.id) +
          ' cliente(s) · exigida por ' +
          nCarMod(k.id) +
          ' obrigação(ões)">' +
          esc(k.nome) +
          ' <small>' +
          nCarCli(k.id) +
          '</small></button>',
      )
      .join('') || '<span class="note">Nenhuma.</span>') +
    '</div><p class="note">O número é a quantidade de clientes com a característica. Marque as características de cada empresa em Clientes: quem não tem ISS não recebe a tarefa de ISS, e assim por diante.</p></div>';
  h +=
    '<div class="card"><div class="hd"><h2>Feriados locais</h2><button class="btn primary" data-act="novo-fer">Novo feriado</button></div><p class="note" style="margin:0 0 10px">Feriados estaduais e municipais (e pontos facultativos em que o banco não abre) mudam o dia útil do vencimento. Cadastre os da sua cidade e dos seus clientes.</p>';
  h += state.feriados.length
    ? '<div class="scroll"><table><thead><tr><th>Data</th><th>Descrição</th><th></th></tr></thead><tbody>' +
      state.feriados
        .map(
          (f) =>
            '<tr><td class="nowrap">' +
            esc(fmtData(f.data)) +
            '</td><td>' +
            esc(f.descricao) +
            '</td><td class="nowrap"><button class="btn sm danger" data-act="del-fer" data-d="' +
            esc(f.data) +
            '">Remover</button></td></tr>',
        )
        .join('') +
      '</tbody></table></div>'
    : '<div class="empty">Nenhum feriado local cadastrado.</div>';
  return h + '</div>';
}
function modalModelo(id) {
  const m = state.modelos.find((x) => x.id === id) || {
    nome: '',
    deptoId: (state.departamentos[0] || {}).id || '',
    regimes: ['Simples Nacional'],
    caractId: '',
    meses: [],
    vencTipo: 'dia',
    vencValor: 20,
    ajuste: 'prorroga',
    desde: '',
    obs: '',
    ativo: true,
  };
  const body =
    '<label>Nome da obrigação<input name="nome" value="' +
    esc(m.nome) +
    '" required maxlength="120"></label><div class="grid2"><label>Departamento<select name="deptoId">' +
    opts(state.departamentos, m.deptoId) +
    '</select></label><label>Só para clientes que têm<select name="caractId">' +
    opts(state.caracteristicas, m.caractId, '(todos os clientes do regime)') +
    '</select></label></div><div><span class="note" style="font-weight:600">Regimes</span><div class="chips" style="margin-top:6px">' +
    REGIMES.map(
      (r) =>
        '<label class="chk"><input type="checkbox" name="rg" value="' +
        esc(r) +
        '"' +
        ((m.regimes || []).includes(r) ? ' checked' : '') +
        '> ' +
        esc(r) +
        '</label>',
    ).join('') +
    '</div></div><label>Meses em que vence (números separados por vírgula; vazio = todo mês)<input name="meses" value="' +
    esc((m.meses || []).join(', ')) +
    '" placeholder="Ex.: 3  ou  1, 4, 7, 10"></label><div class="grid2"><label>Tipo de prazo<select name="vencTipo"><option value="dia"' +
    (m.vencTipo === 'dia' ? ' selected' : '') +
    '>Dia fixo do mês</option><option value="nutil"' +
    (m.vencTipo === 'nutil' ? ' selected' : '') +
    '>N-ésimo dia útil</option><option value="ultimo"' +
    (m.vencTipo === 'ultimo' ? ' selected' : '') +
    '>Último dia útil</option></select></label><label>Dia (ou nº do dia útil)<input name="vencValor" type="number" min="1" max="31" value="' +
    esc(m.vencValor || '') +
    '"></label></div><label>Se o dia fixo cair em dia sem expediente<select name="ajuste"><option value="prorroga"' +
    (m.ajuste !== 'antecipa' ? ' selected' : '') +
    '>Prorroga para o próximo dia útil</option><option value="antecipa"' +
    (m.ajuste === 'antecipa' ? ' selected' : '') +
    '>Antecipa para o dia útil anterior</option></select></label><div class="grid2"><label>Aplica a partir de (opcional)<input name="desde" value="' +
    esc(m.desde || '') +
    '" placeholder="AAAA-MM" maxlength="7" pattern="\\d{4}-\\d{2}"></label><label>Observação (aparece na tarefa)<input name="obs" value="' +
    esc(m.obs || '') +
    '" maxlength="255" placeholder="Ex.: o dia varia por município"></label></div><label class="chk"><input type="checkbox" name="ativo"' +
    (m.ativo !== false ? ' checked' : '') +
    '> Obrigação ligada (gera tarefas)</label>';
  openModal(id ? 'Editar obrigação' : 'Nova obrigação', body, {
    left: id
      ? '<button type="button" class="btn danger left" data-act="del-md" data-id="' +
        esc(id) +
        '">Excluir</button>'
      : '',
    onSubmit: async (fd) => {
      const nome = (fd.get('nome') || '').trim();
      if (!nome) {
        modalErr('Informe o nome da obrigação.');
        return;
      }
      if (!fd.get('deptoId')) {
        modalErr('Escolha um departamento.');
        return;
      }
      const regimes = fd.getAll('rg');
      if (!regimes.length) {
        modalErr('Marque ao menos um regime.');
        return;
      }
      const meses = (fd.get('meses') || '')
        .split(/[,\s;]+/)
        .map(Number)
        .filter((n) => n >= 1 && n <= 12);
      const tipo = fd.get('vencTipo');
      let val = Number(fd.get('vencValor')) || 0;
      if (tipo === 'dia' && (val < 1 || val > 31)) {
        modalErr('Informe um dia entre 1 e 31.');
        return;
      }
      if (tipo === 'nutil' && (val < 1 || val > 23)) {
        modalErr('Informe o dia útil (1 a 23).');
        return;
      }
      const desde = (fd.get('desde') || '').trim();
      if (desde && !/^\d{4}-(0[1-9]|1[0-2])$/.test(desde)) {
        modalErr('Use o formato AAAA-MM em "Aplica a partir de" (ex.: 2026-12).');
        return;
      }
      const dados = {
        id: id ? Number(id) : 0,
        nome,
        deptoId: Number(fd.get('deptoId')),
        caractId: Number(fd.get('caractId')) || 0,
        regimes,
        meses: [...new Set(meses)].sort((a, b) => a - b),
        vencTipo: tipo,
        vencValor: tipo === 'ultimo' ? 0 : val,
        ajuste: fd.get('ajuste'),
        desde,
        obs: (fd.get('obs') || '').trim(),
        ativo: fd.get('ativo') === 'on',
      };
      try {
        await api('modelos', { dados });
      } catch (x) {
        modalErr(x.message);
        return;
      }
      closeModal();
      toast('Obrigação salva.');
      carregar();
    },
  });
}
/* departamento ou característica: só um nome */
function modalNome(tipo, id) {
  const lista = tipo === 'departamento' ? state.departamentos : state.caracteristicas;
  const x = lista.find((i) => i.id === id) || { nome: '' };
  const ex = tipo === 'departamento' ? 'Ex.: Societário' : 'Ex.: Tem retenção de INSS';
  openModal(
    (id ? 'Editar ' : 'Novo ') + (tipo === 'departamento' ? 'departamento' : 'característica'),
    '<label>Nome<input name="nome" value="' +
      esc(x.nome) +
      '" required maxlength="80" placeholder="' +
      esc(ex) +
      '"></label>' +
      (id ? '<p class="note">Só é possível excluir quando nenhuma obrigação usa este item.</p>' : ''),
    {
      left: id
        ? '<button type="button" class="btn danger left" data-act="del-' +
          (tipo === 'departamento' ? 'dep' : 'car') +
          '" data-id="' +
          esc(id) +
          '">Excluir</button>'
        : '',
      onSubmit: async (fd) => {
        const nome = (fd.get('nome') || '').trim();
        if (!nome) {
          modalErr('Informe o nome.');
          return;
        }
        try {
          await api(tipo === 'departamento' ? 'departamentos' : 'caracteristicas', {
            dados: { id: id ? Number(id) : 0, nome },
          });
        } catch (e) {
          modalErr(e.message);
          return;
        }
        closeModal();
        toast('Salvo.');
        carregar();
      },
    },
  );
}
function modalFeriado() {
  openModal(
    'Novo feriado local',
    '<div class="grid2"><label>Data<input name="data" type="date" required></label><label>Descrição<input name="descricao" maxlength="80" placeholder="Ex.: Aniversário da cidade" required></label></div>',
    {
      onSubmit: async (fd) => {
        const data = fd.get('data'),
          descricao = (fd.get('descricao') || '').trim();
        if (!data || !descricao) {
          modalErr('Informe a data e a descrição.');
          return;
        }
        try {
          await api('feriados', { dados: { data, descricao } });
        } catch (x) {
          modalErr(x.message);
          return;
        }
        closeModal();
        toast('Feriado salvo.');
        carregar();
      },
    },
  );
}

acoesPagina['novo-md'] = (id, k, el) => modalModelo(null);
acoesPagina['edit-md'] = (id, k, el) => modalModelo(id);
acoesPagina['del-md'] = (id, k, el) =>
  confirmar(
    'Excluir modelo',
    'Excluir este modelo? Ele deixa de gerar tarefas e o histórico dele é apagado.',
    'Excluir',
    async () => {
      await gravar(() => api('modelos/excluir', { dados: { id: Number(id) } }));
      await carregar();
    },
  );
acoesPagina['novo-fer'] = (id, k, el) => modalFeriado();
acoesPagina['del-fer'] = (id, k, el) =>
  gravar(() => api('feriados/excluir', { dados: { data: el.dataset.d } }))
    .then(carregar)
    .catch(() => {});
acoesPagina['novo-dep'] = (id, k, el) => modalNome('departamento', null);
acoesPagina['edit-dep'] = (id, k, el) => modalNome('departamento', id);
acoesPagina['del-dep'] = (id, k, el) =>
  confirmar(
    'Excluir departamento',
    'Excluir este departamento? Só é possível se nenhuma obrigação estiver nele.',
    'Excluir',
    async () => {
      await gravar(() => api('departamentos/excluir', { dados: { id: Number(id) } }));
      await carregar();
    },
  );
acoesPagina['novo-car'] = (id, k, el) => modalNome('caracteristica', null);
acoesPagina['edit-car'] = (id, k, el) => modalNome('caracteristica', id);
acoesPagina['del-car'] = (id, k, el) =>
  confirmar(
    'Excluir característica',
    'Excluir esta característica? Ela some de todos os clientes. Só é possível se nenhuma obrigação a exigir.',
    'Excluir',
    async () => {
      await gravar(() => api('caracteristicas/excluir', { dados: { id: Number(id) } }));
      await carregar();
    },
  );

const renderPagina = viewModelos;
