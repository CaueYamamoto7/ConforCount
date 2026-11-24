'use strict';

const LS_CNPJ = 'obrig_cnpj';
const lsGet = () => {
  try {
    return localStorage.getItem(LS_CNPJ) || '';
  } catch (e) {
    return '';
  }
};
const lsSet = (v) => {
  try {
    if (v) localStorage.setItem(LS_CNPJ, v);
    else localStorage.removeItem(LS_CNPJ);
  } catch (e) {}
};
let loginCnpj = '';
function loginEtapa(n, msg) {
  $('#cform').hidden = n !== 1;
  $('#lform').hidden = n !== 2;
  const e1 = $('#c-erro'),
    e2 = $('#l-erro');
  e1.textContent = n === 1 ? msg || '' : '';
  e1.hidden = !(n === 1 && msg);
  e2.textContent = n === 2 ? msg || '' : '';
  e2.hidden = !(n === 2 && msg);
  if (n === 1) {
    const i = $('#c-cnpj');
    if (!i.value) i.value = fmtCnpj(lsGet());
    setTimeout(() => i.focus(), 0);
  } else {
    $('#l-cnpj').textContent = fmtCnpj(loginCnpj);
    setTimeout(() => $('#l-email').focus(), 0);
  }
}

/* etapa 1: confere o CNPJ (só os dígitos verificadores; o servidor confere o resto junto com e-mail e senha) */
function continuarLogin() {
  const v = $('#c-cnpj').value;
  if (!cnpjValido(v)) {
    loginEtapa(1, 'CNPJ inválido. Confira os números e letras (os dois últimos são dígitos).');
    return;
  }
  loginCnpj = alnum(v);
  loginEtapa(2, '');
}
async function entrar() {
  const btn = $('#l-btn'),
    err = $('#l-erro');
  err.hidden = true;
  btn.disabled = true;
  try {
    await api('auth/login', {
      dados: { cnpj: loginCnpj, email: $('#l-email').value.trim(), senha: $('#l-senha').value },
    });
    lsSet(loginCnpj);
    $('#l-senha').value = '';
    location.replace(destinoLogin());
  } catch (e) {
    err.textContent = e.message;
    err.hidden = false;
  }
  btn.disabled = false;
}
document.addEventListener('submit', (event) => {
  if (event.target.id === 'cform') {
    event.preventDefault();
    continuarLogin();
  }
  if (event.target.id === 'lform') {
    event.preventDefault();
    entrar();
  }
});
acoesPagina.etapa1 = () => loginEtapa(1, '');
$('#login').hidden = false;
const expirada = new URLSearchParams(location.search).has('expirada');
loginEtapa(1, expirada ? 'Sua sessão expirou. Entre novamente.' : '');
api('auth/session')
  .then((sessao) => {
    if (sessao.usuario) location.replace(destinoLogin());
  })
  .catch((erro) => loginEtapa(1, erro.message));
