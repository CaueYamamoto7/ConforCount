'use strict';

function viewConta() {
  const u = state.me,
    e = state.escritorio || { nome: '', cnpj: '' };
  return (
    '<div class="card"><h2>Minha conta</h2><p style="margin:0 0 4px"><b>' +
    esc(u.nome) +
    '</b></p><p class="note" style="margin:0 0 4px">' +
    esc(u.email) +
    '</p><p class="note" style="margin:0 0 12px">' +
    esc(e.nome) +
    ' · ' +
    esc(fmtCnpj(e.cnpj)) +
    ' · ' +
    esc(PAPEIS[u.papel]) +
    '</p><div class="row"><button class="btn" data-act="senha">Alterar minha senha</button><button class="btn" data-act="sair">Sair</button></div></div>'
  );
}
function modalSenha() {
  openModal(
    'Alterar senha',
    '<label>Senha atual<input name="atual" type="password" autocomplete="current-password" required></label><label>Nova senha (mínimo 8 caracteres)<input name="nova" type="password" autocomplete="new-password" required minlength="8"></label><label>Repita a nova senha<input name="nova2" type="password" autocomplete="new-password" required></label>',
    {
      onSubmit: async (fd) => {
        const atual = fd.get('atual') || '',
          nova = fd.get('nova') || '';
        if (nova !== fd.get('nova2')) {
          modalErr('As senhas novas não conferem.');
          return;
        }
        try {
          await api('auth/password', { dados: { atual, nova } });
        } catch (x) {
          modalErr(x.message);
          return;
        }
        closeModal();
        toast('Senha alterada.');
      },
    },
  );
}

acoesPagina['senha'] = (id, k, el) => modalSenha();

const renderPagina = viewConta;
