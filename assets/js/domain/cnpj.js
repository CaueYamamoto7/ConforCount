'use strict';

/* CNPJ numérico ou alfanumérico (IN RFB 2.229/2024): módulo 11, valor = código ASCII − 48. */
const alnum = (s) =>
  String(s || '')
    .toUpperCase()
    .replace(/[^0-9A-Z]/g, '');
function cnpjDV(b) {
  const n = b.length,
    w = n === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
  let s = 0;
  for (let i = 0; i < n; i++) s += (b.charCodeAt(i) - 48) * w[i];
  const r = s % 11;
  return r < 2 ? 0 : 11 - r;
}
function cnpjValido(v) {
  const c = alnum(v);
  if (!/^[0-9A-Z]{12}[0-9]{2}$/.test(c) || /^(.)\1{13}$/.test(c)) return false;
  const d1 = cnpjDV(c.slice(0, 12)),
    d2 = cnpjDV(c.slice(0, 12) + d1);
  return c[12] === String(d1) && c[13] === String(d2);
}
function fmtCnpj(v) {
  const c = alnum(v).slice(0, 14);
  let o = c.slice(0, 2);
  if (c.length > 2) o += '.' + c.slice(2, 5);
  if (c.length > 5) o += '.' + c.slice(5, 8);
  if (c.length > 8) o += '/' + c.slice(8, 12);
  if (c.length > 12) o += '-' + c.slice(12, 14);
  return o;
}
