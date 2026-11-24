'use strict';

const TAB_NOMES = {
  painel: 'Painel',
  obrigacoes: 'Obrigações',
  clientes: 'Clientes',
  equipe: 'Equipe',
  modelos: 'Modelos e ajustes',
  escritorios: 'Escritórios',
  conta: 'Minha conta',
};
function tabsPermitidas() {
  const l = ['painel', 'obrigacoes', 'clientes'];
  if (isAdmin()) l.push('equipe', 'modelos');
  if (isDono()) l.push('escritorios');
  l.push('conta');
  return l;
}
const URL_PAGINA = {
  painel: '/',
  obrigacoes: '/obrigacoes',
  clientes: '/clientes',
  equipe: '/equipe',
  modelos: '/modelos',
  escritorios: '/escritorios',
  conta: '/conta',
};

function draw() {
  if (!state.me) return;
  if ($('#modal').innerHTML) return;
  $('#shell').hidden = false;
  const a = document.activeElement;
  const id = a && a.id,
    s = a && a.selectionStart,
    e2 = a && a.selectionEnd;
  const perm = tabsPermitidas();
  if (!perm.includes(ui.tab)) {
    location.replace('/');
    return;
  }
  $('#tabs').innerHTML = perm
    .map(
      (t) =>
        '<a href="' +
        URL_PAGINA[t] +
        '"' +
        (t === ui.tab ? ' aria-current="page"' : '') +
        '>' +
        TAB_NOMES[t] +
        '</a>',
    )
    .join('');
  const esc0 = state.escritorio || { nome: '', cnpj: '' };
  $('#eoff').textContent = esc0.nome + ' · ' + fmtCnpj(esc0.cnpj);
  const troca =
    state.escritorios.length > 1
      ? '<select id="sw" aria-label="Trocar de escritório">' +
        state.escritorios
          .map(
            (x) =>
              '<option value="' +
              esc(x.id) +
              '"' +
              (x.id === esc0.id ? ' selected' : '') +
              '>' +
              esc(x.nome) +
              '</option>',
          )
          .join('') +
        '</select>'
      : '';
  $('#who').innerHTML =
    troca +
    '<span>' +
    esc(state.me.nome) +
    ' · ' +
    esc(PAPEIS[state.me.papel] || '') +
    '</span><button class="btn sm" data-act="sair">Sair</button>';
  $('#monthbar').innerHTML = ui.tab === 'painel' || ui.tab === 'obrigacoes' ? monthbar() : '';
  const view = $('#view');
  if (!state.pronto) {
    view.innerHTML = '<div class="empty">Carregando…</div>';
    return;
  }
  if (typeof prepararPagina === 'function') prepararPagina();
  view.innerHTML =
    (state.erro
      ? '<div class="banner warn"><p>' +
        esc(state.erro) +
        ' Os dados na tela podem estar desatualizados.</p></div>'
      : '') + renderPagina();
  if (id) {
    const n = document.getElementById(id);
    if (n) {
      n.focus();
      try {
        n.setSelectionRange(s, e2);
      } catch (x) {}
    }
  }
}

function monthbar() {
  const hoje = hojeISO().slice(0, 7);
  return (
    '<div class="monthbar"><button class="btn icon" data-act="mes-prev" aria-label="Mês anterior">‹</button><span class="lbl">' +
    esc(fmtMes(ui.mes)) +
    '</span><button class="btn icon" data-act="mes-next" aria-label="Próximo mês">›</button>' +
    (ui.mes !== hoje ? '<button class="btn" data-act="mes-hoje">Mês atual</button>' : '') +
    '</div><p class="mnote">Vencimentos que caem em ' +
    esc(fmtMes(ui.mes).toLowerCase()) +
    ' (competência de referência: ' +
    esc(fmtMes(addMonthsYM(ui.mes, -1)).toLowerCase()) +
    ').</p>'
  );
}
function tile(label, val, sub, cls, extra) {
  return (
    '<div class="tile ' +
    (cls || '') +
    '"><span class="tl">' +
    label +
    '</span><strong>' +
    val +
    '</strong>' +
    (sub ? '<span class="ts">' + sub + '</span>' : '') +
    (extra || '') +
    '</div>'
  );
}
function pillT(t, hoje) {
  const e = efetivo(t, hoje);
  const cls = {
    concluida: 'good',
    andamento: 'info',
    aguardando: 'warn',
    atraso: 'bad',
    pendente: 'mute',
    na: 'mute',
  }[e];
  let tx = ST[e];
  if (e === 'atraso') {
    const d = Math.round((msIso(hoje) - msIso(t.venc)) / DAY);
    tx = 'Em atraso · ' + d + ' d';
  }
  if (e === 'pendente' && t.venc === hoje) tx = 'Vence hoje';
  return '<span class="pill ' + cls + '">' + esc(tx) + '</span>';
}
const dowTxt = (iso) => DOWS[dow(iso)];
function semDados() {
  if (isColab())
    return '<div class="banner"><p><b>Nenhum cliente na sua carteira.</b> Peça ao administrador para atribuir clientes a você.</p></div>';
  if (isAdmin() || papel() === 'contador')
    return '<div class="banner"><p><b>Comece por aqui.</b> Cadastre os clientes (um por CNPJ) e atribua um responsável a cada um. As obrigações do mês aparecem sozinhas, conforme o regime.</p><div class="row"><button class="btn primary" data-act="novo-cli">Cadastrar cliente</button></div></div>';
  return '';
}
