'use strict';

const p2 = (n) => String(n).padStart(2, '0');
const DAY = 864e5;
const isoMs = (ms) => {
  const d = new Date(ms);
  return d.getUTCFullYear() + '-' + p2(d.getUTCMonth() + 1) + '-' + p2(d.getUTCDate());
};
const msIso = (iso) => Date.UTC(+iso.slice(0, 4), +iso.slice(5, 7) - 1, +iso.slice(8, 10));
const addDias = (iso, n) => isoMs(msIso(iso) + n * DAY);
const lastDay = (ym) => new Date(Date.UTC(+ym.slice(0, 4), +ym.slice(5, 7), 0)).getUTCDate();
const isoFromYMDay = (ym, d) => ym + '-' + p2(Math.min(Math.max(1, d || 1), lastDay(ym)));
function pascoa(y) {
  const a = y % 19,
    b = Math.floor(y / 100),
    c = y % 100,
    d = Math.floor(b / 4),
    e = b % 4,
    f = Math.floor((b + 8) / 25),
    g = Math.floor((b - f + 1) / 3);
  const h = (19 * a + b - d - g + 15) % 30,
    i = Math.floor(c / 4),
    k = c % 4,
    l = (32 + 2 * e + 2 * i - h - k) % 7,
    m = Math.floor((a + 11 * h + 22 * l) / 451);
  const mes = Math.floor((h + l - 7 * m + 114) / 31),
    dia = ((h + l - 7 * m + 114) % 31) + 1;
  return Date.UTC(y, mes - 1, dia);
}
/* Dias sem expediente bancário: feriados nacionais, Carnaval (seg e ter) e Corpus Christi.
   Feriados estaduais e municipais vêm da tabela "feriados" do banco (tela Modelos). */
const EXTRA = new Set();
const _fer = {};
function feriados(y) {
  if (_fer[y]) return _fer[y];
  const s = new Set();
  ['01-01', '04-21', '05-01', '09-07', '10-12', '11-02', '11-15', '12-25'].forEach((x) => s.add(y + '-' + x));
  if (y >= 2024) s.add(y + '-11-20');
  const p = pascoa(y);
  [-48, -47, -2, 60].forEach((n) => s.add(isoMs(p + n * DAY)));
  return (_fer[y] = s);
}
const dow = (iso) => new Date(msIso(iso)).getUTCDay();
const isUtil = (iso) => {
  const w = dow(iso);
  return w !== 0 && w !== 6 && !feriados(+iso.slice(0, 4)).has(iso) && !EXTRA.has(iso);
};
const proxUtil = (iso) => {
  while (!isUtil(iso)) iso = addDias(iso, 1);
  return iso;
};
const antUtil = (iso) => {
  while (!isUtil(iso)) iso = addDias(iso, -1);
  return iso;
};
function nDiaUtil(ym, n) {
  let d = ym + '-01',
    c = 0;
  for (let i = 0; i < 45; i++) {
    if (isUtil(d)) {
      c++;
      if (c === n) return d;
    }
    d = addDias(d, 1);
  }
  return d;
}
const ultimoUtil = (ym) => antUtil(ym + '-' + p2(lastDay(ym)));
/* Vencimento de um modelo dentro do mês ym (mês em que a obrigação vence). */
function vencimento(m, ym) {
  const v = Number(m.vencValor) || 1;
  if (m.vencTipo === 'nutil') return nDiaUtil(ym, v);
  if (m.vencTipo === 'ultimo') return ultimoUtil(ym);
  const iso = isoFromYMDay(ym, v);
  if (isUtil(iso)) return iso;
  return m.ajuste === 'antecipa' ? antUtil(iso) : proxUtil(iso);
}
