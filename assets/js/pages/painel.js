'use strict';

function viewPainel() {
  const hoje = hojeISO();
  if (!state.clientes.length) return semDados();
  const ts = tarefasDoMes(ui.mes);
  const vivas = ts.filter((t) => t.status !== 'na');
  const conc = vivas.filter((t) => t.status === 'concluida').length;
  const pc = vivas.length ? Math.round((conc / vivas.length) * 100) : 0;
  const atras = vivas.filter((t) => efetivo(t, hoje) === 'atraso');
  const lim = addDias(hoje, 7);
  const prox = vivas.filter((t) => ABERTA.includes(t.status) && t.venc >= hoje && t.venc <= lim);
  const hojeN = vivas.filter((t) => ABERTA.includes(t.status) && t.venc === hoje).length;
  const aguard = vivas.filter((t) => t.status === 'aguardando').length;
  let h =
    '<div class="tiles">' +
    tile(
      'Concluído no mês',
      pc + '%',
      conc + ' de ' + vivas.length + ' obrigações',
      pc >= 100 ? 'good' : '',
      '<div class="bar"><i style="width:' + pc + '%"></i></div>',
    ) +
    tile(
      'Em atraso',
      atras.length,
      atras.length ? 'precisam de ação agora' : 'nada atrasado',
      atras.length ? 'bad' : 'good',
    ) +
    tile('Vencem em 7 dias', prox.length, hojeN ? hojeN + ' vence(m) hoje' : 'a partir de hoje') +
    tile('Aguardando cliente', aguard, 'esperando documento ou retorno') +
    '</div>';
  if (!isColab()) {
    const linhas = usuariosAtivos()
      .map((e) => {
        const mine = vivas.filter((t) => t.c.respId === e.id);
        if (!mine.length) return null;
        const cc = mine.filter((t) => t.status === 'concluida').length,
          at = mine.filter((t) => efetivo(t, hoje) === 'atraso').length;
        const p = Math.round((cc / mine.length) * 100);
        const ncli = new Set(mine.map((t) => t.c.id)).size;
        return (
          '<tr><td>' +
          esc(e.nome) +
          '<span class="sub">' +
          esc(PAPEIS[e.papel] || '') +
          '</span></td><td class="num">' +
          ncli +
          '</td><td class="num">' +
          mine.length +
          '</td><td class="num">' +
          cc +
          '</td><td class="num">' +
          (at ? '<span class="pill bad">' + at + '</span>' : '<span class="pill good">0</span>') +
          '</td><td style="min-width:120px"><div class="bar"><i style="width:' +
          p +
          '%"></i></div><span class="sub">' +
          p +
          '%</span></td></tr>'
        );
      })
      .filter(Boolean);
    const semResp = vivas.filter((t) => !t.c.respId).length;
    h +=
      '<div class="card"><h2>Carga por colaborador</h2>' +
      (linhas.length
        ? '<div class="scroll"><table><thead><tr><th>Quem</th><th class="num">Clientes</th><th class="num">Obrigações</th><th class="num">Feitas</th><th class="num">Atrasos</th><th>Andamento</th></tr></thead><tbody>' +
          linhas.join('') +
          '</tbody></table></div>'
        : '<p class="note">Nenhuma obrigação atribuída a alguém neste mês.</p>') +
      (semResp
        ? '<p class="note">' +
          semResp +
          ' obrigações de clientes sem responsável. Atribua um responsável na tela Clientes.</p>'
        : '') +
      '</div>';
  }
  const rank = (t) => (efetivo(t, hoje) === 'atraso' ? 0 : 1);
  const lista = vivas
    .filter((t) => ABERTA.includes(t.status))
    .sort(
      (a, b) =>
        rank(a) - rank(b) || a.venc.localeCompare(b.venc) || a.c.nome.localeCompare(b.c.nome, 'pt-BR'),
    );
  const mostra = lista.slice(0, 30);
  h +=
    '<div class="card"><div class="hd"><h2>O que precisa de atenção</h2><span class="note">' +
    lista.length +
    ' em aberto no mês</span></div>';
  if (!mostra.length) h += '<div class="empty">Tudo em dia neste mês.</div>';
  else
    h +=
      '<div class="scroll"><table><thead><tr><th>Cliente</th><th>Obrigação</th><th>Vence</th><th>Responsável</th><th>Situação</th><th></th></tr></thead><tbody>' +
      mostra
        .map(
          (t) =>
            '<tr><td>' +
            esc(t.c.nome) +
            '<span class="sub">' +
            esc(t.c.regime) +
            '</span></td><td>' +
            esc(t.m.nome) +
            '<span class="sub">' +
            esc(t.m.depto) +
            '</span></td><td class="nowrap">' +
            fmtDM(t.venc) +
            '<span class="sub">' +
            dowTxt(t.venc) +
            '</span></td><td>' +
            esc(nomeResp(t.c.respId)) +
            '</td><td>' +
            pillT(t, hoje) +
            '</td><td class="nowrap"><button class="btn sm" data-act="concluir" data-k="' +
            esc(t.k) +
            '">Concluir</button> <button class="btn sm" data-act="tarefa" data-k="' +
            esc(t.k) +
            '">Abrir</button></td></tr>',
        )
        .join('') +
      '</tbody></table></div>' +
      (lista.length > mostra.length
        ? '<p class="note">Mostrando as ' +
          mostra.length +
          ' mais urgentes. Veja o restante em Obrigações.</p>'
        : '');
  h += '</div>';
  return h;
}

const renderPagina = viewPainel;
