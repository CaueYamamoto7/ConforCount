'use strict';

function viewObrig() {
  const hoje = hojeISO(),
    f = ui.f;
  if (!state.clientes.length) return semDados();
  let ts = tarefasDoMes(ui.mes);
  if (f.resp) ts = ts.filter((t) => (f.resp === '__none' ? !t.c.respId : t.c.respId === f.resp));
  if (f.regime) ts = ts.filter((t) => t.c.regime === f.regime);
  if (f.depto) ts = ts.filter((t) => t.m.depto === f.depto);
  if (f.q) {
    const q = f.q.toLowerCase(),
      qd = alnum(f.q);
    ts = ts.filter(
      (t) => t.c.nome.toLowerCase().includes(q) || (qd.length >= 4 && alnum(t.c.cnpj).includes(qd)),
    );
  }
  if (f.pend) {
    const abertos = new Set(ts.filter((t) => ABERTA.includes(t.status)).map((t) => t.c.id));
    ts = ts.filter((t) => abertos.has(t.c.id));
  }
  const cols = [],
    seen = {},
    vencRef = {};
  ts.forEach((t) => {
    vencRef[t.m.id] = t.venc;
    if (!seen[t.m.id]) {
      seen[t.m.id] = 1;
      cols.push(t.m);
    }
  });
  cols.sort(
    (a, b) =>
      deptoIdx(a) - deptoIdx(b) ||
      vencRef[a.id].localeCompare(vencRef[b.id]) ||
      a.nome.localeCompare(b.nome, 'pt-BR'),
  );
  const cli = [],
    cs = {};
  ts.forEach((t) => {
    if (!cs[t.c.id]) {
      cs[t.c.id] = 1;
      cli.push(t.c);
    }
  });
  cli.sort(byNome);
  const map = {};
  ts.forEach((t) => {
    map[t.c.id + '|' + t.m.id] = t;
  });
  const respOpts =
    '<option value="">Todos</option>' +
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
    '>Sem responsável</option>';
  let h =
    (isColab()
      ? ''
      : '<p class="note" style="margin:0 0 10px">Precisa de um tipo de obrigação que não está na lista? <a href="/modelos.html">Criar nova obrigação</a> (vale para qualquer regime).</p>') +
    '<div class="filters">' +
    '<label>Buscar cliente ou CNPJ<input id="fq" data-f="f.q" type="search" value="' +
    esc(f.q) +
    '" placeholder="Nome ou CNPJ"></label>' +
    (isColab() ? '' : '<label>Responsável<select data-f="f.resp">' + respOpts + '</select></label>') +
    '<label>Regime<select data-f="f.regime">' +
    opts(REGIMES, f.regime, 'Todos') +
    '</select></label>' +
    '<label>Departamento<select data-f="f.depto">' +
    opts(
      state.departamentos.map((d) => d.nome),
      f.depto,
      'Todos',
    ) +
    '</select></label>' +
    '<label class="chk"><input type="checkbox" data-f="f.pend"' +
    (f.pend ? ' checked' : '') +
    '> Só clientes com pendência</label></div>';
  h +=
    '<div class="legend"><span>Legenda:</span>' +
    ['concluida', 'andamento', 'aguardando', 'pendente', 'atraso', 'na']
      .map(
        (s) => '<span class="cel s-' + s + '" style="cursor:default;min-width:0">' + esc(SH[s]) + '</span>',
      )
      .join('') +
    '<span class="cel prazo" style="cursor:default;min-width:0">Vence em até 5 dias</span>' +
    '<span>· clique em uma célula para mudar a situação. Cada cliente só vê as obrigações que se aplicam a ele; o traço indica que não se aplica.</span></div>';
  if (!cli.length)
    return (
      h + '<div class="card"><div class="empty">Nenhuma obrigação com esses filtros neste mês.</div></div>'
    );
  const grupos = [];
  cols.forEach((m) => {
    const g = grupos[grupos.length - 1];
    if (g && g.nome === m.depto) g.n++;
    else grupos.push({ nome: m.depto, n: 1 });
  });
  h +=
    '<div class="card" style="padding:0"><div class="scroll"><table class="mx"><thead><tr><th class="cli" rowspan="2">Cliente</th>' +
    grupos.map((g) => '<th class="grp" colspan="' + g.n + '">' + esc(g.nome) + '</th>').join('') +
    '<th class="col" rowspan="2">Andamento</th></tr><tr>' +
    cols.map((m) => '<th class="col" title="' + esc(m.obs || '') + '">' + esc(m.nome) + '</th>').join('') +
    '</tr></thead><tbody>';
  cli.forEach((c) => {
    let tot = 0,
      ok = 0;
    const cells = cols
      .map((m) => {
        const t = map[c.id + '|' + m.id];
        if (!t) return '<td><span class="dot">·</span></td>';
        const e = efetivo(t, hoje);
        if (e !== 'na') {
          tot++;
          if (e === 'concluida') ok++;
        }
        return (
          '<td><button class="cel s-' +
          e +
          (vencendo(t, hoje) ? ' prazo' : '') +
          '" data-act="tarefa" data-k="' +
          esc(t.k) +
          '" title="' +
          esc(
            c.nome +
              ' · ' +
              m.nome +
              ' · ' +
              ST[e] +
              ' · vence ' +
              fmtData(t.venc) +
              (vencendo(t, hoje) ? ' (vence em breve)' : ''),
          ) +
          '">' +
          esc(SH[e]) +
          '<small>' +
          fmtDM(t.venc) +
          '</small></button></td>'
        );
      })
      .join('');
    h +=
      '<tr><td class="cli">' +
      esc(c.nome) +
      '<span class="sub">' +
      esc(c.regime) +
      ' · ' +
      esc(nomeResp(c.respId)) +
      '</span></td>' +
      cells +
      '<td class="prog">' +
      ok +
      '/' +
      tot +
      '<div class="bar"><i style="width:' +
      (tot ? Math.round((ok / tot) * 100) : 0) +
      '%"></i></div></td></tr>';
  });
  h += '</tbody></table></div></div>';
  return h;
}

const renderPagina = viewObrig;
