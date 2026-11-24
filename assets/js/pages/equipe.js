'use strict';

function viewEquipe() {
  const l = state.usuarios;
  let h =
    '<div class="card"><div class="hd"><h2>Equipe de ' +
    esc(state.escritorio ? state.escritorio.nome : '') +
    '</h2><button class="btn primary" data-act="novo-eq">Novo usuário</button></div>';
  h +=
    '<p class="note" style="margin:0 0 10px"><b>Administrador:</b> vê e altera tudo deste escritório. <b>Contador:</b> vê todas as carteiras e cadastra clientes. <b>Colaborador:</b> vê e atualiza só os clientes em que é responsável. Quem atende mais de um escritório entra com o CNPJ de cada um e tem papel próprio em cada.</p>';
  if (!l.length) return h + '<div class="empty">Ninguém cadastrado.</div></div>';
  h +=
    '<div class="scroll"><table><thead><tr><th>Nome</th><th>E-mail (login)</th><th>Papel aqui</th><th class="num">Clientes</th><th>Último acesso</th><th></th></tr></thead><tbody>' +
    l
      .map((e) => {
        const n = state.clientes.filter((c) => c.respId === e.id && c.ativo !== false).length;
        return (
          '<tr><td>' +
          esc(e.nome) +
          (e.ativo === false ? ' <span class="pill mute">Bloqueado</span>' : '') +
          (e.escritorios > 1 || e.dono
            ? ' <span class="pill info" title="Esta pessoa também entra em outro escritório">vários escritórios</span>'
            : '') +
          '</td><td>' +
          esc(e.email || '') +
          '</td><td><span class="pill ' +
          (e.papel === 'colaborador' ? 'mute' : 'acc') +
          '">' +
          esc(PAPEIS[e.papel]) +
          '</span></td><td class="num">' +
          n +
          '</td><td class="nowrap">' +
          (e.ultimoLogin ? esc(fmtHora(e.ultimoLogin)) : '<span class="sub">nunca entrou</span>') +
          '</td><td class="nowrap"><button class="btn sm" data-act="edit-eq" data-id="' +
          esc(e.id) +
          '">Editar</button></td></tr>'
        );
      })
      .join('') +
    '</tbody></table></div></div>';
  return h;
}
function modalUsuario(id) {
  const e = state.usuarios.find((x) => x.id === id) || {
    nome: '',
    email: '',
    papel: 'colaborador',
    ativo: true,
  };
  const eu = state.me && String(state.me.id) === String(id);
  const comp = !!(id && (e.escritorios > 1 || e.dono));
  const dis = comp ? ' disabled' : '';
  const body =
    '<label>Nome<input name="nome" value="' +
    esc(e.nome) +
    '" required maxlength="120"' +
    dis +
    '></label><label>E-mail (será o login)<input name="email" type="email" value="' +
    esc(e.email || '') +
    '" required maxlength="160" autocomplete="off"' +
    dis +
    '></label><div class="grid2"><label>Papel neste escritório<select name="papel"' +
    (eu ? ' disabled' : '') +
    '>' +
    Object.keys(PAPEIS)
      .map(
        (k) =>
          '<option value="' +
          k +
          '"' +
          (k === e.papel ? ' selected' : '') +
          '>' +
          esc(PAPEIS[k]) +
          '</option>',
      )
      .join('') +
    '</select></label>' +
    (comp
      ? ''
      : '<label>' +
        (id ? 'Nova senha (deixe em branco para manter)' : 'Senha inicial') +
        '<input name="senha" type="password" autocomplete="new-password" minlength="8"></label>') +
    '</div>' +
    (eu
      ? ''
      : '<label class="chk"><input type="checkbox" name="ativo"' +
        (e.ativo !== false ? ' checked' : '') +
        '> Pode entrar neste escritório</label>') +
    (comp
      ? '<p class="note">Esta pessoa atende mais de um escritório: nome, e-mail e senha só ela mesma altera (em Minha conta). Aqui você controla o papel e o acesso dela a este escritório.</p>'
      : id
        ? '<p class="note">A senha precisa ter pelo menos 8 caracteres.</p>'
        : '<p class="note">Se este e-mail já tiver cadastro em outro escritório, a pessoa é apenas vinculada a este e continua com a senha dela. Senha nova: mínimo de 8 caracteres.</p>');
  openModal(id ? 'Editar usuário' : 'Novo usuário', body, {
    left:
      id && !eu
        ? '<button type="button" class="btn danger left" data-act="del-eq" data-id="' +
          esc(id) +
          '">Remover deste escritório</button>'
        : '',
    onSubmit: async (fd) => {
      const nome = comp ? e.nome : (fd.get('nome') || '').trim(),
        email = comp ? e.email : (fd.get('email') || '').trim();
      if (!nome || !email) {
        modalErr('Informe nome e e-mail.');
        return;
      }
      const dados = {
        id: id ? Number(id) : 0,
        nome,
        email,
        papel: eu ? 'admin' : fd.get('papel'),
        ativo: eu ? true : fd.get('ativo') === 'on',
        senha: comp ? '' : fd.get('senha') || '',
      };
      let r;
      try {
        r = await api('equipe', { dados });
      } catch (x) {
        modalErr(x.message);
        return;
      }
      closeModal();
      toast(r && r.vinculado ? 'Pessoa já cadastrada: vinculada a este escritório.' : 'Usuário salvo.');
      carregar();
    },
  });
}

acoesPagina['novo-eq'] = (id, k, el) => modalUsuario(null);
acoesPagina['edit-eq'] = (id, k, el) => modalUsuario(id);
acoesPagina['del-eq'] = (id, k, el) =>
  confirmar(
    'Excluir usuário',
    'Excluir este usuário? Os clientes dele ficam sem responsável. Para só impedir o acesso, edite e desmarque "Pode entrar no sistema".',
    'Excluir',
    async () => {
      await gravar(() => api('equipe/excluir', { dados: { id: Number(id) } }));
      await carregar();
    },
  );

const renderPagina = viewEquipe;
