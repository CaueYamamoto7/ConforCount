'use strict';

function toast(msg, err) {
  const el = document.createElement('div');
  el.className = 'toast' + (err ? ' err' : '');
  el.textContent = msg;
  $('#toast').appendChild(el);
  setTimeout(() => el.remove(), err ? 6000 : 3200);
}
let modalSubmit = null,
  lastFocus = null;
function openModal(title, body, opt) {
  opt = opt || {};
  lastFocus = document.activeElement;
  $('#modal').innerHTML =
    '<div class="scrim" data-close="1"></div><div class="sheet" role="dialog" aria-modal="true" aria-labelledby="mt"><form id="mform" novalidate><header><h2 id="mt">' +
    esc(title) +
    '</h2><button type="button" class="x" data-close="1" aria-label="Fechar">×</button></header><div class="mbody">' +
    body +
    '<p class="err" id="merr" hidden></p></div><footer>' +
    (opt.left || '') +
    '<button type="button" class="btn" data-close="1">Cancelar</button><button type="submit" class="btn primary">' +
    esc(opt.submit || 'Salvar') +
    '</button></footer></form></div>';
  modalSubmit = opt.onSubmit || null;
  const f = $('#modal').querySelector('input:not([type=hidden]),select,textarea');
  if (f) f.focus();
}
function closeModal() {
  $('#modal').innerHTML = '';
  modalSubmit = null;
  if (lastFocus && lastFocus.focus)
    try {
      lastFocus.focus();
    } catch (e) {}
  render();
}
function modalErr(msg) {
  const e = $('#merr');
  if (e) {
    e.textContent = msg;
    e.hidden = !msg;
  }
}
function confirmar(titulo, texto, rotulo, fn) {
  openModal(titulo, '<p>' + esc(texto) + '</p>', {
    submit: rotulo,
    onSubmit: async () => {
      await fn();
      closeModal();
    },
  });
}
const opts = (arr, sel, vazio) =>
  (vazio != null ? '<option value="">' + esc(vazio) + '</option>' : '') +
  arr
    .map((x) => {
      const v = typeof x === 'string' ? x : x.id,
        t = typeof x === 'string' ? x : x.nome;
      return (
        '<option value="' +
        esc(v) +
        '"' +
        (String(v) === String(sel) ? ' selected' : '') +
        '>' +
        esc(t) +
        '</option>'
      );
    })
    .join('');

let rafId = 0;
function render() {
  if (rafId) return;
  rafId = requestAnimationFrame(() => {
    rafId = 0;
    draw();
  });
}
const acoesPagina = {};
document.addEventListener('click', (e) => {
  if (e.target.closest('[data-close]')) {
    closeModal();
    return;
  }
  const el = e.target.closest('[data-act]');
  if (el) acoesPagina[el.dataset.act]?.(el.dataset.id, el.dataset.k, el);
});
document.addEventListener('submit', async (e) => {
  if (e.target.id !== 'mform') return;
  e.preventDefault();
  if (!modalSubmit) return;
  const btn = e.target.querySelector('button[type=submit]');
  if (btn) btn.disabled = true;
  try {
    modalErr('');
    await modalSubmit(new FormData(e.target));
  } catch (x) {}
  if (btn) btn.disabled = false;
});
function onFilter(e) {
  const t = e.target;
  if (!t.dataset || !t.dataset.f) return;
  const [g, key] = t.dataset.f.split('.');
  ui[g][key] = t.type === 'checkbox' ? t.checked : t.value;
  salvarFiltros();
  render();
}
document.addEventListener('input', (e) => {
  const t = e.target;
  if (t && t.dataset && t.dataset.mask === 'cnpj') {
    const v = fmtCnpj(t.value);
    if (v !== t.value) t.value = v;
  }
  onFilter(e);
});
document.addEventListener('change', (e) => {
  if (e.target && e.target.id === 'sw') trocarEscritorio(e.target.value);
});
document.addEventListener('change', onFilter);
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && $('#modal')?.innerHTML) closeModal();
});
