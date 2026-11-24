'use strict';

async function api(rota, opt) {
  opt = opt || {};
  const metodo = opt.dados !== undefined ? 'POST' : 'GET';
  const q = opt.query ? '?' + new URLSearchParams(opt.query).toString() : '';
  const init = { method: metodo, credentials: 'same-origin', headers: { Accept: 'application/json' } };
  if (metodo === 'POST') {
    init.headers['Content-Type'] = 'application/json';
    init.headers['X-CSRF-Token'] = state.csrf;
    init.body = JSON.stringify(opt.dados);
  }
  let r,
    j = {};
  try {
    r = await fetch('/api/' + rota + q, init);
  } catch (e) {
    throw new Error('Sem conexão com o servidor.');
  }
  try {
    j = await r.json();
  } catch (e) {
    j = {};
  }
  if (r.status === 401 && rota !== 'auth/login') {
    state.me = null;
    irParaLogin(true);
    throw new Error('Sessão expirada.');
  }
  if (!r.ok) throw new Error(j.erro || 'Erro ' + r.status);
  return j;
}
async function gravar(fn) {
  try {
    return await fn();
  } catch (e) {
    toast(e.message || 'Não consegui salvar.', true);
    throw e;
  }
}
