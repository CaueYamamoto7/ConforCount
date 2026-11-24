'use strict';

const $ = (s) => document.querySelector(s);
const esc = (s) =>
  String(s == null ? '' : s).replace(
    /[&<>"']/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c],
  );
const hojeISO = () => {
  const d = new Date();
  return d.getFullYear() + '-' + p2(d.getMonth() + 1) + '-' + p2(d.getDate());
};
const addMonthsYM = (ym, n) => {
  const t = new Date(Date.UTC(+ym.slice(0, 4), +ym.slice(5, 7) - 1 + n, 1));
  return t.getUTCFullYear() + '-' + p2(t.getUTCMonth() + 1);
};
const fmtData = (iso) => (iso ? iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4) : '');
const fmtDM = (iso) => iso.slice(8, 10) + '/' + iso.slice(5, 7);
const fmtHora = (s) => (s ? fmtData(s.slice(0, 10)) + ' às ' + s.slice(11, 16) : '');
const DOWS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
const MESES_C = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
function fmtMes(ym) {
  const s = new Date(Date.UTC(+ym.slice(0, 4), +ym.slice(5, 7) - 1, 1)).toLocaleDateString('pt-BR', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
  });
  return s.charAt(0).toUpperCase() + s.slice(1);
}
const byNome = (a, b) => String(a.nome || '').localeCompare(String(b.nome || ''), 'pt-BR');
