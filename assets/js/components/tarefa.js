'use strict';

function modalTarefa(k) {
  const [mes, cid, mid] = k.split('__');
  const c = state.clientes.find((x) => x.id === cid),
    m = state.modelos.find((x) => x.id === mid);
  if (!c || !m) return;
  const t = state.tarefas[k] || {},
    st = t.status || 'pendente',
    venc = vencimento(m, mes),
    hoje = hojeISO();
  const body =
    '<p><b>' +
    esc(c.nome) +
    '</b><span class="sub">' +
    esc(c.regime) +
    ' · responsável: ' +
    esc(nomeResp(c.respId)) +
    '</span></p><p><b>' +
    esc(m.nome) +
    '</b> <span class="pill acc">' +
    esc(m.depto) +
    '</span><span class="sub">Vence em ' +
    fmtData(venc) +
    ' (' +
    dowTxt(venc) +
    ') · ' +
    esc(descVenc(m)) +
    '</span></p>' +
    (m.obs ? '<p class="note">' + esc(m.obs) + '</p>' : '') +
    '<p>' +
    pillT({ status: st, venc }, hoje) +
    '</p><label>Situação<select name="status">' +
    Object.keys(ST)
      .filter((s) => s !== 'atraso')
      .map(
        (s) => '<option value="' + s + '"' + (s === st ? ' selected' : '') + '>' + esc(ST[s]) + '</option>',
      )
      .join('') +
    '</select></label><label>Observação<textarea name="obs" maxlength="1000" placeholder="Ex.: cliente vai mandar as notas na terça">' +
    esc(t.obs || '') +
    '</textarea></label><div><span class="note" style="font-weight:600">Histórico</span><ul class="hist" id="hist"><li>Carregando…</li></ul></div>';
  openModal('Obrigação do mês', body, {
    onSubmit: async (fd) => {
      try {
        await salvarTarefa(k, fd.get('status'), (fd.get('obs') || '').trim());
      } catch (e) {
        modalErr(e.message);
        return;
      }
      closeModal();
    },
  });
  api('tarefas/historico', { query: { mes, cliente_id: cid, modelo_id: mid } })
    .then((r) => {
      const ul = $('#hist');
      if (!ul) return;
      ul.innerHTML = r.historico.length
        ? r.historico
            .map(
              (h) =>
                '<li><b>' +
                esc(h.por) +
                '</b> · ' +
                esc(fmtHora(h.em)) +
                '<br>' +
                esc(ST[h.antes] || h.antes) +
                ' → ' +
                esc(ST[h.depois] || h.depois) +
                (h.obs ? '<br><i>' + esc(h.obs) + '</i>' : '') +
                '</li>',
            )
            .join('')
        : '<li>Sem alterações ainda.</li>';
    })
    .catch(() => {
      const ul = $('#hist');
      if (ul) ul.innerHTML = '<li>Não consegui carregar o histórico.</li>';
    });
}
async function salvarTarefa(k, status, obs) {
  const [mes, cid, mid] = k.split('__');
  await api('tarefas', { dados: { mes, clienteId: Number(cid), modeloId: Number(mid), status, obs } });
  await carregar();
}

acoesPagina.tarefa = (_id, k) => modalTarefa(k);
acoesPagina['concluir'] = (id, k, el) =>
  salvarTarefa(k, 'concluida', (state.tarefas[k] && state.tarefas[k].obs) || '')
    .then(() => toast('Marcada como concluída.'))
    .catch((x) => toast(x.message, true));
