'use strict';

const REGIMES = ['MEI', 'Simples Nacional', 'Lucro Presumido', 'Lucro Real'];
const PAPEIS = { admin: 'Administrador', contador: 'Contador', colaborador: 'Colaborador' };
const ST = {
  pendente: 'Pendente',
  andamento: 'Em andamento',
  aguardando: 'Aguardando cliente',
  concluida: 'Concluída',
  na: 'Não se aplica',
  atraso: 'Em atraso',
};
const SH = {
  pendente: 'Pendente',
  andamento: 'Fazendo',
  aguardando: 'Cliente',
  concluida: '✓ Feita',
  na: 'N/A',
  atraso: 'Atrasada',
};
const ABERTA = ['pendente', 'andamento', 'aguardando'];

/* Quais obrigações um cliente recebe em um mês (mês de vencimento, AAAA-MM). */
function aplica(m, c, mes) {
  if (c.ativo === false || m.ativo === false) return false;
  if (c.inicio && mes < c.inicio) return false;
  if (c.fim && mes > c.fim) return false;
  if (m.desde && mes < m.desde) return false;
  const ex = (c.excecoes || {})[m.id];
  if (ex === 'excluir') return false;
  if (m.meses && m.meses.length && !m.meses.includes(+mes.slice(5, 7))) return false;
  if (ex === 'incluir') return true;
  if (!(m.regimes || []).includes(c.regime)) return false;
  if (m.caractId && !(c.caract || []).includes(m.caractId)) return false;
  return true;
}
function descVenc(m) {
  if (m.vencTipo === 'nutil') return m.vencValor + 'º dia útil';
  if (m.vencTipo === 'ultimo') return 'Último dia útil';
  return 'Dia ' + m.vencValor + ' (' + (m.ajuste === 'antecipa' ? 'antecipa' : 'prorroga') + ')';
}
const descFreq = (m) =>
  m.meses && m.meses.length ? 'Em ' + m.meses.map((n) => MESES_C[n - 1]).join(', ') : 'Todo mês';
