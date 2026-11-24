import assert from 'node:assert/strict';

// Execute somente com servidores que apontem para uma base de teste recém-inicializada.
const primeiraOrigem = process.env.TEST_API_URL;
const segundaOrigem = process.env.TEST_API_URL_SECONDARY ?? primeiraOrigem;
const cnpj = process.env.SETUP_CNPJ;
const email = process.env.SETUP_EMAIL;
const senha = process.env.SETUP_SENHA;
assert.ok(primeiraOrigem && cnpj && email && senha, 'Configure TEST_API_URL e os dados SETUP de teste.');

const mes = '2026-05';
const sessao = () => ({ cookie: '', csrf: '' });

async function api(s, rota, corpo, { origem = primeiraOrigem, status = 200, csrf = s.csrf } = {}) {
  const [nome, busca] = rota.split('&', 2);
  const consulta = rota.slice(nome.length + 1);
  const url = new URL('/api/' + nome + (busca ? '?' + consulta : ''), origem);
  const resposta = await fetch(url, {
    method: corpo === undefined ? 'GET' : 'POST',
    headers: { Cookie: s.cookie, 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' },
    body: corpo === undefined ? undefined : JSON.stringify(corpo),
    signal: AbortSignal.timeout(10000),
  });
  const bruto = await resposta.text();
  assert.equal(resposta.status, status, `${rota}: ${bruto}`);
  assert.match(resposta.headers.get('cache-control') ?? '', /\bno-store\b/);
  const cookie = resposta.headers.get('set-cookie');
  if (cookie) s.cookie = cookie.split(';', 1)[0];
  const dados = JSON.parse(bruto);
  if (dados.csrf) s.csrf = dados.csrf;
  return dados;
}

const admin = sessao();
assert.equal((await api(admin, 'auth/session')).usuario, null);
const cookieAnterior = admin.cookie;
const login = await api(admin, 'auth/login', { cnpj, email, senha });
assert.notEqual(admin.cookie, cookieAnterior);
assert.equal(login.usuario.papel, 'admin');
const eid = login.escritorio.id;
assert.equal((await api(admin, 'auth/session', undefined, { origem: segundaOrigem })).escritorio.id, eid);
assert.equal((await api({ ...sessao(), cookie: cookieAnterior }, 'auth/session')).usuario, null);
await api(admin, 'feriados', {}, { status: 403, csrf: '' });

const dados = await api(admin, 'painel&mes=' + mes);
assert.ok(dados.modelos.length > 0);
assert.ok(dados.caracteristicas.length > 0);
assert.equal(dados.clientes.length, 0);
const modeloId = dados.modelos[0].id;
const caract = dados.caracteristicas[0].id;

const { id: deptoId } = await api(admin, 'departamentos', { nome: 'Revisão fiscal' });
await api(admin, 'departamentos', { nome: 'REVISAO FISCAL' }, { status: 422 });
const { id: caractId } = await api(admin, 'caracteristicas', { nome: 'Teste de enquadramento' });
const modelo = {
  nome: 'Obrigação de teste',
  deptoId,
  caractId,
  regimes: ['MEI'],
  meses: [5],
  vencTipo: 'dia',
  vencValor: 15,
  ajuste: 'antecipa',
  desde: mes,
  ativo: true,
};
const { id: modeloNovo } = await api(admin, 'modelos', modelo);
await api(admin, 'modelos', { ...modelo, id: modeloNovo, vencValor: 18 });
const modeloAtual = (await api(admin, 'painel&mes=' + mes)).modelos.find((m) => m.id === modeloNovo);
assert.equal(modeloAtual.vencValor, 18);
assert.deepEqual(modeloAtual.meses, [5]);
assert.equal(modeloAtual.desde, mes);
await api(admin, 'modelos/excluir', { id: modeloNovo });
await api(admin, 'departamentos/excluir', { id: deptoId });
await api(admin, 'caracteristicas/excluir', { id: caractId });

const feriado = { data: '2026-05-25', descricao: 'Feriado local' };
await api(admin, 'feriados', feriado);
await api(admin, 'feriados', { ...feriado, descricao: 'Feriado atualizado' });
assert.deepEqual((await api(admin, 'painel&mes=' + mes)).feriados, [
  { ...feriado, descricao: 'Feriado atualizado' },
]);
await api(admin, 'feriados/excluir', { data: feriado.data });

const colaborador = sessao();
const membro = {
  nome: 'Colaborador Teste',
  email: 'colaborador@example.test',
  senha: 'senha-ficticia-5678',
  papel: 'colaborador',
  ativo: true,
};
const { id: responsavel } = await api(admin, 'equipe', membro);
const cliente = {
  nome: 'Cliente Teste',
  regime: 'MEI',
  respId: responsavel,
  ativo: true,
  caract: [caract],
  excecoes: { [modeloId]: 'incluir' },
};
const { id: clienteId } = await api(admin, 'clientes', cliente);
const { id: outroCliente } = await api(admin, 'clientes', {
  ...cliente,
  nome: 'Outro cliente',
  respId: null,
});
await api(colaborador, 'auth/login', { cnpj, email: membro.email, senha: membro.senha });
const carteira = await api(colaborador, 'painel&mes=' + mes);
assert.deepEqual(
  carteira.clientes.map((c) => c.id),
  [clienteId],
);
assert.deepEqual(carteira.clientes[0].caract, [caract]);
assert.equal(carteira.clientes[0].excecoes[modeloId], 'incluir');
await api(colaborador, 'clientes', cliente, { status: 403 });
await api(colaborador, 'escritorios', undefined, { status: 403 });

const tarefa = { mes, clienteId, modeloId };
await api(
  colaborador,
  'tarefas',
  { ...tarefa, clienteId: outroCliente, status: 'concluida' },
  { status: 403 },
);
await Promise.all([
  api(admin, 'tarefas', { ...tarefa, status: 'andamento' }),
  api(colaborador, 'tarefas', { ...tarefa, status: 'concluida' }, { origem: segundaOrigem }),
]);
const urlHistorico = `tarefas/historico&mes=${mes}&cliente_id=${clienteId}&modelo_id=${modeloId}`;
const { historico } = await api(admin, urlHistorico);
assert.equal(historico.length, 2);
assert.equal(historico[1].antes, 'pendente');
assert.equal(historico[0].antes, historico[1].depois);
assert.equal((await api(admin, 'painel&mes=' + mes)).tarefas[0].status, historico[0].depois);

const outroCnpj = '11444777000161';
const outro = await api(admin, 'escritorios', {
  nome: 'Outro escritório',
  cnpj: outroCnpj,
  adminNome: login.usuario.nome,
  adminEmail: email,
  ativo: true,
});
assert.equal(outro.adminJaExistia, true);
await api(admin, 'auth/escritorio', { id: outro.id });
assert.deepEqual((await api(admin, 'painel&mes=' + mes)).clientes, []);
await api(admin, urlHistorico, undefined, { status: 404 });
await api(admin, 'auth/escritorio', { id: eid });

const contador = sessao();
const contaContador = {
  ...membro,
  nome: 'Contador Teste',
  email: 'contador@example.test',
  papel: 'contador',
};
await api(admin, 'equipe', contaContador);
await api(contador, 'auth/login', { cnpj, email: contaContador.email, senha: contaContador.senha });
assert.equal((await api(contador, 'painel&mes=' + mes)).clientes.length, 2);
await api(contador, 'feriados', feriado, { status: 403 });
await api(contador, 'clientes/excluir', { id: outroCliente });

await api(admin, 'equipe', { ...membro, id: responsavel, ativo: false });
assert.equal((await api(colaborador, 'auth/session')).usuario, null);
const senhaNova = senha + '-nova';
const cookieAntesDaSenha = admin.cookie;
await api(admin, 'auth/password', { atual: senha, nova: senhaNova });
assert.notEqual(admin.cookie, cookieAntesDaSenha);
await api(sessao(), 'auth/login', { cnpj, email, senha }, { status: 401 });
await api(admin, 'auth/login', { cnpj, email, senha: senhaNova });
const cookieAtivo = admin.cookie;
await api(admin, 'auth/logout', {});
assert.equal(
  (await api({ ...sessao(), cookie: cookieAtivo }, 'auth/session', undefined, { origem: segundaOrigem }))
    .usuario,
  null,
);
console.log('API, sessões, permissões, isolamento e histórico validados com PostgreSQL.');
