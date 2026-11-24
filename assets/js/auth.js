'use strict';

const PAGINAS_INTERNAS = ['/', '/obrigacoes', '/clientes', '/equipe', '/modelos', '/escritorios', '/conta'];
function destinoLogin() {
  const destino = new URLSearchParams(location.search).get('next');
  return PAGINAS_INTERNAS.includes(destino) ? destino : '/';
}
function irParaLogin(expirada = false) {
  if (location.pathname === '/login') return;
  const query = new URLSearchParams({ next: location.pathname });
  if (expirada) query.set('expirada', '1');
  location.replace('/login?' + query);
}

function aplicarSessao(r) {
  state.me = r.usuario;
  state.csrf = r.csrf;
  state.escritorio = r.escritorio || null;
  state.escritorios = (r.escritorios || []).map((x) => Object.assign({}, x, { id: String(x.id) }));
  if (state.escritorio) state.escritorio.id = String(state.escritorio.id);
}
async function sair() {
  try {
    await api('auth/logout', { dados: {} });
  } catch (e) {}
  state.me = null;
  state.csrf = '';
  state.escritorio = null;
  state.escritorios = [];
  zerarDados();
  location.replace('/login');
}
async function trocarEscritorio(id) {
  try {
    const r = await api('auth/escritorio', { dados: { id: Number(id) } });
    zerarDados();
    aplicarSessao(r);
    location.replace('/');
  } catch (e) {
    toast(e.message, true);
    render();
  }
}
acoesPagina.sair = () => sair();
