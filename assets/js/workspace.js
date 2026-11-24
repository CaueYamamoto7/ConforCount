'use strict';

function selecionarMes(mes) {
  ui.mes = mes;
  salvarFiltros();
  state.pronto = false;
  carregar();
  render();
}
acoesPagina['mes-prev'] = () => selecionarMes(addMonthsYM(ui.mes, -1));
acoesPagina['mes-next'] = () => selecionarMes(addMonthsYM(ui.mes, 1));
acoesPagina['mes-hoje'] = () => selecionarMes(hojeISO().slice(0, 7));

/* Atualiza sozinho a cada minuto, para ver o que os colegas marcaram (não mexe se houver janela aberta). */
setInterval(() => {
  if (state.me && document.visibilityState === 'visible' && !$('#modal').innerHTML) carregar();
}, 60000);
document.addEventListener('visibilitychange', () => {
  if (state.me && document.visibilityState === 'visible' && !$('#modal').innerHTML) carregar();
});
(async function iniciarWorkspace() {
  const antiga = location.hash.slice(1);
  if (location.pathname === '/' && Object.hasOwn(URL_PAGINA, antiga) && antiga !== 'painel') {
    location.replace(URL_PAGINA[antiga]);
    return;
  }
  try {
    const sessao = await api('auth/session');
    if (!sessao.usuario) {
      irParaLogin();
      return;
    }
    aplicarSessao(sessao);
    render();
    await carregar();
  } catch (erro) {
    $('#shell').hidden = false;
    $('#view').textContent = erro.message;
  }
})();
