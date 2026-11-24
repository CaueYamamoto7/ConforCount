'use strict';

function modalCliente(id) {
  const c = state.clientes.find((x) => x.id === id) || {
    nome: '',
    cnpj: '',
    regime: 'Simples Nacional',
    respId: '',
    ativo: true,
    obs: '',
    inicio: '',
    fim: '',
    caract: [],
    excecoes: {},
  };
  const carHtml = state.caracteristicas.length
    ? state.caracteristicas
        .map(
          (k) =>
            '<label class="chk"><input type="checkbox" name="car" value="' +
            esc(k.id) +
            '"' +
            ((c.caract || []).includes(k.id) ? ' checked' : '') +
            '> ' +
            esc(k.nome) +
            '</label>',
        )
        .join('')
    : '<span class="note">Nenhuma característica cadastrada. Crie em "Modelos e ajustes".</span>';
  const exHtml = state.departamentos
    .map((d) => {
      const ms = state.modelos.filter((m) => m.deptoId === d.id && m.ativo !== false);
      if (!ms.length) return '';
      return (
        '<div class="exg"><b>' +
        esc(d.nome) +
        '</b>' +
        ms
          .map((m) => {
            const auto = id ? aplica(m, Object.assign({}, c, { excecoes: {} }), ui.mes) : null;
            const atual = (c.excecoes || {})[m.id] || '';
            const rot =
              auto === null ? 'Automático' : 'Automático (' + (auto ? 'recebe' : 'não recebe') + ')';
            return (
              '<label class="exr"><span>' +
              esc(m.nome) +
              '</span><select name="ex_' +
              esc(m.id) +
              '"><option value="">' +
              esc(rot) +
              '</option><option value="incluir"' +
              (atual === 'incluir' ? ' selected' : '') +
              '>Sempre incluir</option><option value="excluir"' +
              (atual === 'excluir' ? ' selected' : '') +
              '>Nunca incluir</option></select></label>'
            );
          })
          .join('') +
        '</div>'
      );
    })
    .join('');
  const body =
    '<label>Nome do cliente<input name="nome" value="' +
    esc(c.nome) +
    '" required maxlength="150"></label>' +
    '<div class="grid2"><label>CNPJ (numérico ou alfanumérico)<input name="cnpj" data-mask="cnpj" value="' +
    esc(fmtCnpj(c.cnpj)) +
    '" placeholder="00.000.000/0000-00" maxlength="18" autocomplete="off"></label><label>Regime tributário<select name="regime">' +
    opts(REGIMES, c.regime) +
    '</select></label></div>' +
    '<label>Responsável<select name="respId">' +
    opts(usuariosAtivos(), c.respId, 'Sem responsável') +
    '</select></label>' +
    '<div><span class="note" style="font-weight:600">Características do cliente (definem quais obrigações ele recebe)</span><div class="chips" style="margin-top:6px">' +
    carHtml +
    '</div></div>' +
    '<div class="grid2"><label>Atende desde (opcional)<input name="inicio" value="' +
    esc(c.inicio || '') +
    '" placeholder="AAAA-MM" maxlength="7" pattern="\\d{4}-\\d{2}"></label><label>Até (opcional, ao encerrar)<input name="fim" value="' +
    esc(c.fim || '') +
    '" placeholder="AAAA-MM" maxlength="7" pattern="\\d{4}-\\d{2}"></label></div>' +
    '<label class="chk"><input type="checkbox" name="ativo"' +
    (c.ativo !== false ? ' checked' : '') +
    '> Cliente ativo</label>' +
    '<details class="exc"><summary>Exceções: incluir ou retirar uma obrigação só para este cliente' +
    (Object.keys(c.excecoes || {}).length ? ' (' + Object.keys(c.excecoes).length + ')' : '') +
    '</summary><p class="note">"Automático" segue o regime e as características. Use "Sempre incluir" ou "Nunca incluir" para os casos especiais deste cliente.</p>' +
    exHtml +
    '</details>' +
    '<label>Observação<textarea name="obs" maxlength="500">' +
    esc(c.obs || '') +
    '</textarea></label>';
  openModal(id ? 'Editar cliente' : 'Novo cliente', body, {
    left: id
      ? '<button type="button" class="btn danger left" data-act="del-cli" data-id="' +
        esc(id) +
        '">Excluir</button>'
      : '',
    onSubmit: async (fd) => {
      const nome = (fd.get('nome') || '').trim();
      if (!nome) {
        modalErr('Informe o nome do cliente.');
        return;
      }
      const cnpj = (fd.get('cnpj') || '').trim();
      if (cnpj && !cnpjValido(cnpj)) {
        modalErr('CNPJ inválido. Confira os números e letras (os dois últimos são dígitos).');
        return;
      }
      const inicio = (fd.get('inicio') || '').trim(),
        fim = (fd.get('fim') || '').trim();
      const re = /^\d{4}-(0[1-9]|1[0-2])$/;
      if ((inicio && !re.test(inicio)) || (fim && !re.test(fim))) {
        modalErr('Use o formato AAAA-MM nas datas (ex.: 2026-03).');
        return;
      }
      if (inicio && fim && fim < inicio) {
        modalErr('O mês final não pode ser anterior ao inicial.');
        return;
      }
      const excecoes = {};
      for (const [k, v] of fd.entries()) {
        if (k.startsWith('ex_') && v) excecoes[k.slice(3)] = v;
      }
      const dados = {
        id: id ? Number(id) : 0,
        nome,
        cnpj,
        regime: fd.get('regime'),
        respId: Number(fd.get('respId')) || 0,
        caract: fd.getAll('car').map(Number),
        excecoes,
        inicio,
        fim,
        ativo: fd.get('ativo') === 'on',
        obs: (fd.get('obs') || '').trim(),
      };
      try {
        await api('clientes', { dados });
      } catch (e) {
        modalErr(e.message);
        return;
      }
      closeModal();
      toast('Cliente salvo.');
      carregar();
    },
  });
}

acoesPagina['novo-cli'] = () => modalCliente(null);
