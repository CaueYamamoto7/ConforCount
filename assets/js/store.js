'use strict';

const state = {
  me: null,
  escritorio: null,
  escritorios: [],
  csrf: '',
  usuarios: [],
  departamentos: [],
  caracteristicas: [],
  clientes: [],
  modelos: [],
  tarefas: {},
  feriados: [],
  plat: null,
  pronto: false,
  erro: '',
};
const CHAVE_FILTROS = 'conforcont.filtros';
const ui = {
  tab: document.body.dataset.pagina,
  mes: hojeISO().slice(0, 7),
  f: { resp: '', regime: '', depto: '', q: '', pend: false },
  cf: { q: '', regime: '', resp: '' },
};

try {
  const opcoes = JSON.parse(sessionStorage.getItem(CHAVE_FILTROS) || '{}');
  if (/^\d{4}-(0[1-9]|1[0-2])$/.test(opcoes.mes)) ui.mes = opcoes.mes;
  Object.assign(ui.f, opcoes.f);
  Object.assign(ui.cf, opcoes.cf);
} catch {}

function salvarFiltros() {
  try {
    sessionStorage.setItem(CHAVE_FILTROS, JSON.stringify({ mes: ui.mes, f: ui.f, cf: ui.cf }));
  } catch {}
}
const papel = () => (state.me ? state.me.papel : '');
const isColab = () => papel() === 'colaborador';
const isAdmin = () => papel() === 'admin';
const isDono = () => !!(state.me && state.me.dono);
const podeEditarCli = () => papel() === 'admin' || papel() === 'contador';
const nomeResp = (id) => {
  const u = state.usuarios.find((x) => x.id === id);
  return u ? u.nome : 'Sem responsável';
};
const usuariosAtivos = () => state.usuarios.filter((u) => u.ativo !== false);
const nomeDepto = (id) => {
  const d = state.departamentos.find((x) => x.id === id);
  return d ? d.nome : '—';
};
const nomeCar = (id) => {
  const k = state.caracteristicas.find((x) => x.id === id);
  return k ? k.nome : '';
};
const deptoIdx = (m) => {
  const i = state.departamentos.findIndex((x) => x.id === m.deptoId);
  return i < 0 ? 999 : i;
};
const efetivo = (t, hoje) => (ABERTA.includes(t.status) && t.venc < hoje ? 'atraso' : t.status);

/* Aberta e vencendo hoje ou nos próximos 5 dias (ainda não atrasada). */
const PRAZO_AVISO = 5;
const vencendo = (t, hoje) =>
  ABERTA.includes(t.status) && t.venc >= hoje && t.venc <= addDias(hoje, PRAZO_AVISO);

function tarefasDoMes(mes) {
  const out = [];
  state.clientes.forEach((c) => {
    if (c.ativo === false) return;
    state.modelos.forEach((m) => {
      if (!aplica(m, c, mes)) return;
      const k = mes + '__' + c.id + '__' + m.id,
        t = state.tarefas[k] || {};
      out.push({
        k,
        mes,
        c,
        m,
        venc: vencimento(m, mes),
        status: t.status || 'pendente',
        obs: t.obs || '',
        por: t.por || '',
        em: t.em || '',
      });
    });
  });
  return out;
}

function norm(d) {
  state.usuarios = (d.usuarios || []).map((u) => Object.assign({}, u, { id: String(u.id) }));
  state.departamentos = (d.departamentos || []).map((x) => ({ id: String(x.id), nome: x.nome }));
  state.caracteristicas = (d.caracteristicas || []).map((x) => ({ id: String(x.id), nome: x.nome }));
  state.clientes = (d.clientes || []).map((c) =>
    Object.assign({}, c, {
      id: String(c.id),
      respId: c.respId == null ? '' : String(c.respId),
      caract: (c.caract || []).map(String),
      excecoes: c.excecoes && !Array.isArray(c.excecoes) ? c.excecoes : {},
      inicio: c.inicio || '',
      fim: c.fim || '',
    }),
  );
  state.modelos = (d.modelos || []).map((m) => {
    const o = Object.assign({}, m, {
      id: String(m.id),
      deptoId: String(m.deptoId),
      caractId: m.caractId == null ? '' : String(m.caractId),
      desde: m.desde || '',
    });
    o.depto = nomeDepto(o.deptoId);
    return o;
  });
  const t = {};
  (d.tarefas || []).forEach((x) => {
    t[d.mes + '__' + x.clienteId + '__' + x.modeloId] = x;
  });
  state.tarefas = t;
  state.feriados = d.feriados || [];
  EXTRA.clear();
  state.feriados.forEach((f) => EXTRA.add(f.data));
}
let carregando = false,
  refazer = false;
async function carregar() {
  if (!state.me) return;
  if (carregando) {
    refazer = true;
    return;
  }
  carregando = true;
  const mes = ui.mes;
  try {
    const d = await api('painel', { query: { mes } });
    if (mes === ui.mes) {
      norm(d);
      state.pronto = true;
      state.erro = '';
    }
  } catch (e) {
    state.erro = e.message;
    if (!state.pronto) state.pronto = true;
  } finally {
    carregando = false;
    render();
    if (refazer) {
      refazer = false;
      carregar();
    }
  }
}

function zerarDados() {
  Object.assign(state, {
    usuarios: [],
    departamentos: [],
    caracteristicas: [],
    clientes: [],
    modelos: [],
    tarefas: {},
    feriados: [],
    plat: null,
    pronto: false,
    erro: '',
  });
  ui.f = { resp: '', regime: '', depto: '', q: '', pend: false };
  ui.cf = { q: '', regime: '', resp: '' };
  salvarFiltros();
}
