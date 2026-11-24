'use strict';

function viewClientes() {
  const f = ui.cf,
    ro = !podeEditarCli();
  let l = state.clientes.slice();
  if (f.regime) l = l.filter((c) => c.regime === f.regime);
  if (f.resp) l = l.filter((c) => (f.resp === '__none' ? !c.respId : c.respId === f.resp));
  if (f.q) {
    const q = f.q.toLowerCase(),
      qd = alnum(f.q);
    l = l.filter((c) => c.nome.toLowerCase().includes(q) || (qd.length >= 4 && alnum(c.cnpj).includes(qd)));
  }
  l.sort(byNome);
  let h =
    '<div class="card"><div class="hd"><h2>Clientes</h2>' +
    (ro ? '' : '<button class="btn primary" data-act="novo-cli">Novo cliente</button>') +
    '</div>';
  h +=
    '<div class="filters"><label>Buscar<input id="cq" data-f="cf.q" type="search" value="' +
    esc(f.q) +
    '" placeholder="Nome ou CNPJ"></label><label>Regime<select data-f="cf.regime">' +
    opts(REGIMES, f.regime, 'Todos') +
    '</select></label>' +
    (isColab()
      ? ''
      : '<label>Responsável<select data-f="cf.resp"><option value="">Todos</option>' +
        usuariosAtivos()
          .map(
            (e) =>
              '<option value="' +
              esc(e.id) +
              '"' +
              (f.resp === e.id ? ' selected' : '') +
              '>' +
              esc(e.nome) +
              '</option>',
          )
          .join('') +
        '<option value="__none"' +
        (f.resp === '__none' ? ' selected' : '') +
        '>Sem responsável</option></select></label>') +
    '</div>';
  if (!l.length)
    return (
      h +
      '<div class="empty">' +
      (state.clientes.length ? 'Nenhum cliente com esses filtros.' : 'Nenhum cliente ainda.') +
      '</div></div>' +
      (state.clientes.length ? '' : semDados())
    );
  h +=
    '<div class="scroll"><table><thead><tr><th>Cliente</th><th>Regime</th><th>Responsável</th><th>Características</th><th class="num">Obrigações no mês</th>' +
    (ro ? '' : '<th></th>') +
    '</tr></thead><tbody>' +
    l
      .map((c) => {
        const n = state.modelos.filter((m) => aplica(m, c, ui.mes)).length;
        const nomes = (c.caract || []).map(nomeCar).filter(Boolean);
        const nex = Object.keys(c.excecoes || {}).length;
        const car =
          nomes
            .slice(0, 3)
            .map((x) => '<span class="pill info">' + esc(x) + '</span>')
            .join(' ') +
          (nomes.length > 3
            ? ' <span class="pill mute" title="' +
              esc(nomes.slice(3).join(', ')) +
              '">+' +
              (nomes.length - 3) +
              '</span>'
            : '') +
          (nex
            ? ' <span class="pill warn" title="Obrigações incluídas ou retiradas à mão para este cliente">' +
              nex +
              ' exceção(ões)</span>'
            : '') +
          (!nomes.length && !nex ? '<span class="sub">—</span>' : '');
        const per =
          c.inicio || c.fim
            ? '<span class="sub">' +
              (c.inicio ? 'desde ' + esc(c.inicio.slice(5) + '/' + c.inicio.slice(0, 4)) : '') +
              (c.fim ? ' até ' + esc(c.fim.slice(5) + '/' + c.fim.slice(0, 4)) : '') +
              '</span>'
            : '';
        return (
          '<tr><td>' +
          esc(c.nome) +
          '<span class="sub">' +
          esc(c.cnpj ? fmtCnpj(c.cnpj) : 'sem CNPJ') +
          '</span>' +
          per +
          '</td><td><span class="pill acc">' +
          esc(c.regime) +
          '</span></td><td>' +
          esc(nomeResp(c.respId)) +
          '</td><td class="chipcell">' +
          car +
          '</td><td class="num">' +
          (c.ativo === false ? '<span class="pill mute">Inativo</span>' : n) +
          '</td>' +
          (ro
            ? ''
            : '<td class="nowrap"><button class="btn sm" data-act="edit-cli" data-id="' +
              esc(c.id) +
              '">Editar</button></td>') +
          '</tr>'
        );
      })
      .join('') +
    '</tbody></table></div></div>';
  return h;
}

acoesPagina['edit-cli'] = (id, k, el) => modalCliente(id);
acoesPagina['del-cli'] = (id, k, el) =>
  confirmar(
    'Excluir cliente',
    'Excluir este cliente? O histórico de tarefas dele também será apagado. Para só parar de gerar tarefas, desmarque "Cliente ativo".',
    'Excluir',
    async () => {
      await gravar(() => api('clientes/excluir', { dados: { id: Number(id) } }));
      await carregar();
    },
  );

const renderPagina = viewClientes;
