'use strict';

let platCarregando = false;
async function carregarPlat() {
  if (platCarregando) return;
  platCarregando = true;
  try {
    const r = await api('escritorios');
    state.plat = r.escritorios || [];
  } catch (e) {
    state.plat = [];
    toast(e.message, true);
  } finally {
    platCarregando = false;
    render();
  }
}
function viewEscritorios() {
  if (state.plat === null) return '<div class="empty">Carregando…</div>';
  let h =
    '<div class="card"><div class="hd"><h2>Escritórios na plataforma</h2><button class="btn primary" data-act="novo-esc">Novo escritório</button></div><p class="note" style="margin:0 0 10px">Cada escritório é uma "ilha": seus clientes, obrigações e equipe ficam separados dos demais. Aqui você vê só os dados cadastrais e as contagens — nunca os clientes de outro escritório. Todo escritório novo já nasce com os departamentos, características e obrigações-padrão.</p>';
  h +=
    '<div class="scroll"><table><thead><tr><th>Escritório</th><th>CNPJ</th><th class="num">Pessoas</th><th class="num">Clientes ativos</th><th>Situação</th><th></th></tr></thead><tbody>' +
    state.plat
      .map(
        (x) =>
          '<tr><td>' +
          esc(x.nome) +
          (state.escritorio && String(x.id) === String(state.escritorio.id)
            ? ' <span class="pill info">você está aqui</span>'
            : '') +
          '<span class="sub">criado em ' +
          esc(fmtData((x.criadoEm || '').slice(0, 10))) +
          '</span></td><td class="nowrap">' +
          esc(fmtCnpj(x.cnpj)) +
          '</td><td class="num">' +
          x.pessoas +
          '</td><td class="num">' +
          x.clientes +
          '</td><td>' +
          (x.ativo ? '<span class="pill good">Ativo</span>' : '<span class="pill mute">Desativado</span>') +
          '</td><td class="nowrap"><button class="btn sm" data-act="edit-esc" data-id="' +
          esc(x.id) +
          '">Editar</button></td></tr>',
      )
      .join('') +
    '</tbody></table></div></div>';
  return h;
}
function modalEscritorio(id) {
  const x = (state.plat || []).find((e) => String(e.id) === String(id)) || {
    nome: '',
    cnpj: '',
    ativo: true,
  };
  const novo =
    '<div class="sep"></div><p class="note"><b>Primeiro administrador do escritório</b></p><div class="grid2"><label>Nome<input name="adminNome" maxlength="120" required></label><label>E-mail (login)<input name="adminEmail" type="email" maxlength="160" required autocomplete="off"></label></div><label>Senha inicial (mínimo 8 caracteres; deixe em branco se o e-mail já tiver cadastro em outro escritório)<input name="adminSenha" type="password" autocomplete="new-password" minlength="8"></label>';
  openModal(
    id ? 'Editar escritório' : 'Novo escritório',
    '<label>Nome do escritório<input name="nome" value="' +
      esc(x.nome) +
      '" required maxlength="150"></label><label>CNPJ do escritório (é o que a equipe digita para entrar)<input name="cnpj" data-mask="cnpj" value="' +
      esc(fmtCnpj(x.cnpj)) +
      '" maxlength="18" required placeholder="00.000.000/0000-00" autocomplete="off"></label><label class="chk"><input type="checkbox" name="ativo"' +
      (x.ativo !== false ? ' checked' : '') +
      '> Escritório ativo (desativar bloqueia a entrada de toda a equipe)</label>' +
      (id ? '' : novo),
    {
      onSubmit: async (fd) => {
        const nome = (fd.get('nome') || '').trim(),
          cnpj = (fd.get('cnpj') || '').trim();
        if (!nome) {
          modalErr('Informe o nome.');
          return;
        }
        if (!cnpjValido(cnpj)) {
          modalErr('CNPJ inválido. Confira os números e letras.');
          return;
        }
        const dados = { id: id ? Number(id) : 0, nome, cnpj, ativo: fd.get('ativo') === 'on' };
        if (!id) {
          dados.adminNome = (fd.get('adminNome') || '').trim();
          dados.adminEmail = (fd.get('adminEmail') || '').trim();
          dados.adminSenha = fd.get('adminSenha') || '';
          if (!dados.adminNome || !dados.adminEmail) {
            modalErr('Informe o nome e o e-mail do primeiro administrador.');
            return;
          }
        }
        try {
          await api('escritorios', { dados });
        } catch (e) {
          modalErr(e.message);
          return;
        }
        closeModal();
        toast(id ? 'Escritório atualizado.' : 'Escritório criado com o cadastro-padrão.');
        state.plat = null;
        try {
          const r = await api('auth/session');
          if (r.usuario) {
            aplicarSessao(r);
          }
        } catch (e) {}
        render();
      },
    },
  );
}

acoesPagina['novo-esc'] = (id, k, el) => modalEscritorio(null);
acoesPagina['edit-esc'] = (id, k, el) => modalEscritorio(id);

const renderPagina = viewEscritorios;
function prepararPagina() {
  if (state.plat === null) carregarPlat();
}
