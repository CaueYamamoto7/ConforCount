import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const contexto = vm.createContext({});
for (const nome of ['calendario', 'obrigacoes']) {
  vm.runInContext(
    readFileSync(new URL('../assets/js/domain/' + nome + '.js', import.meta.url), 'utf8'),
    contexto,
  );
}
const executar = (codigo) => vm.runInContext(codigo, contexto);

test('antecipa ou prorroga vencimentos no fim de semana e nos feriados locais', () => {
  assert.equal(
    executar("vencimento({vencTipo:'dia',vencValor:17,ajuste:'antecipa'}, '2026-05')"),
    '2026-05-15',
  );
  assert.equal(
    executar(
      "EXTRA.add('2026-05-18'); vencimento({vencTipo:'dia',vencValor:17,ajuste:'prorroga'}, '2026-05')",
    ),
    '2026-05-19',
  );
});

test('cliente só recebe obrigação vigente, com exceção explícita para o regime', () => {
  const modelo = "{id:'1',ativo:true,regimes:['MEI'],meses:[5],desde:'2026-05'}";
  assert.equal(
    executar('aplica(' + modelo + ", {regime:'Lucro Real',excecoes:{'1':'incluir'}}, '2026-05')"),
    true,
  );
  assert.equal(executar('aplica(' + modelo + ", {regime:'MEI',excecoes:{}}, '2026-04')"), false);
  assert.equal(executar('aplica(' + modelo + ", {regime:'MEI',excecoes:{'1':'excluir'}}, '2026-05')"), false);
});
