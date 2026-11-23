(function(){
'use strict';

/*<CAL>*/
const p2=n=>String(n).padStart(2,'0');
const DAY=864e5;
const isoMs=ms=>{const d=new Date(ms);return d.getUTCFullYear()+'-'+p2(d.getUTCMonth()+1)+'-'+p2(d.getUTCDate());};
const msIso=iso=>Date.UTC(+iso.slice(0,4),+iso.slice(5,7)-1,+iso.slice(8,10));
const addDias=(iso,n)=>isoMs(msIso(iso)+n*DAY);
const lastDay=ym=>new Date(Date.UTC(+ym.slice(0,4),+ym.slice(5,7),0)).getUTCDate();
const isoFromYMDay=(ym,d)=>ym+'-'+p2(Math.min(Math.max(1,d||1),lastDay(ym)));
function pascoa(y){
  const a=y%19,b=Math.floor(y/100),c=y%100,d=Math.floor(b/4),e=b%4,f=Math.floor((b+8)/25),g=Math.floor((b-f+1)/3);
  const h=(19*a+b-d-g+15)%30,i=Math.floor(c/4),k=c%4,l=(32+2*e+2*i-h-k)%7,m=Math.floor((a+11*h+22*l)/451);
  const mes=Math.floor((h+l-7*m+114)/31),dia=((h+l-7*m+114)%31)+1;
  return Date.UTC(y,mes-1,dia);
}
/* Dias sem expediente bancário: feriados nacionais, Carnaval (seg e ter) e Corpus Christi.
   Feriados estaduais e municipais vêm da tabela "feriados" do banco (tela Modelos). */
const EXTRA=new Set();
const _fer={};
function feriados(y){
  if(_fer[y])return _fer[y];
  const s=new Set();
  ['01-01','04-21','05-01','09-07','10-12','11-02','11-15','12-25'].forEach(x=>s.add(y+'-'+x));
  if(y>=2024)s.add(y+'-11-20');
  const p=pascoa(y);
  [-48,-47,-2,60].forEach(n=>s.add(isoMs(p+n*DAY)));
  return (_fer[y]=s);
}
const dow=iso=>new Date(msIso(iso)).getUTCDay();
const isUtil=iso=>{const w=dow(iso);return w!==0&&w!==6&&!feriados(+iso.slice(0,4)).has(iso)&&!EXTRA.has(iso);};
const proxUtil=iso=>{while(!isUtil(iso))iso=addDias(iso,1);return iso;};
const antUtil=iso=>{while(!isUtil(iso))iso=addDias(iso,-1);return iso;};
function nDiaUtil(ym,n){let d=ym+'-01',c=0;for(let i=0;i<45;i++){if(isUtil(d)){c++;if(c===n)return d;}d=addDias(d,1);}return d;}
const ultimoUtil=ym=>antUtil(ym+'-'+p2(lastDay(ym)));
/* Vencimento de um modelo dentro do mês ym (mês em que a obrigação vence). */
function vencimento(m,ym){
  const v=Number(m.vencValor)||1;
  if(m.vencTipo==='nutil')return nDiaUtil(ym,v);
  if(m.vencTipo==='ultimo')return ultimoUtil(ym);
  const iso=isoFromYMDay(ym,v);
  if(isUtil(iso))return iso;
  return m.ajuste==='antecipa'?antUtil(iso):proxUtil(iso);
}
/*</CAL>*/

/* ---------- utilidades ---------- */
const $=s=>document.querySelector(s);
const esc=s=>String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const hojeISO=()=>{const d=new Date();return d.getFullYear()+'-'+p2(d.getMonth()+1)+'-'+p2(d.getDate());};
const addMonthsYM=(ym,n)=>{const t=new Date(Date.UTC(+ym.slice(0,4),+ym.slice(5,7)-1+n,1));return t.getUTCFullYear()+'-'+p2(t.getUTCMonth()+1);};
const fmtData=iso=>iso?iso.slice(8,10)+'/'+iso.slice(5,7)+'/'+iso.slice(0,4):'';
const fmtDM=iso=>iso.slice(8,10)+'/'+iso.slice(5,7);
const fmtHora=s=>s?fmtData(s.slice(0,10))+' às '+s.slice(11,16):'';
const DOWS=['dom','seg','ter','qua','qui','sex','sáb'];
const MESES_C=['jan','fev','mar','abr','mai','jun','jul','ago','set','out','nov','dez'];
function fmtMes(ym){const s=new Date(Date.UTC(+ym.slice(0,4),+ym.slice(5,7)-1,1)).toLocaleDateString('pt-BR',{month:'long',year:'numeric',timeZone:'UTC'});return s.charAt(0).toUpperCase()+s.slice(1);}
const byNome=(a,b)=>String(a.nome||'').localeCompare(String(b.nome||''),'pt-BR');
const digits=s=>String(s||'').replace(/\D/g,'');

/* ---------- regras ---------- */
const REGIMES=['MEI','Simples Nacional','Lucro Presumido','Lucro Real'];
const PAPEIS={admin:'Administrador',contador:'Contador',colaborador:'Colaborador'};
const ST={pendente:'Pendente',andamento:'Em andamento',aguardando:'Aguardando cliente',concluida:'Concluída',na:'Não se aplica',atraso:'Em atraso'};
const SH={pendente:'Pendente',andamento:'Fazendo',aguardando:'Cliente',concluida:'✓ Feita',na:'N/A',atraso:'Atrasada'};
const ABERTA=['pendente','andamento','aguardando'];

/* CNPJ numérico ou alfanumérico (IN RFB 2.229/2024): módulo 11, valor = código ASCII − 48. */
const alnum=s=>String(s||'').toUpperCase().replace(/[^0-9A-Z]/g,'');
function cnpjDV(b){const n=b.length,w=n===12?[5,4,3,2,9,8,7,6,5,4,3,2]:[6,5,4,3,2,9,8,7,6,5,4,3,2];let s=0;for(let i=0;i<n;i++)s+=(b.charCodeAt(i)-48)*w[i];const r=s%11;return r<2?0:11-r;}
function cnpjValido(v){const c=alnum(v);if(!/^[0-9A-Z]{12}[0-9]{2}$/.test(c)||/^(.)\1{13}$/.test(c))return false;const d1=cnpjDV(c.slice(0,12)),d2=cnpjDV(c.slice(0,12)+d1);return c[12]===String(d1)&&c[13]===String(d2);}
function fmtCnpj(v){const c=alnum(v).slice(0,14);let o=c.slice(0,2);if(c.length>2)o+='.'+c.slice(2,5);if(c.length>5)o+='.'+c.slice(5,8);if(c.length>8)o+='/'+c.slice(8,12);if(c.length>12)o+='-'+c.slice(12,14);return o;}

/* Quais obrigações um cliente recebe em um mês (mês de vencimento, AAAA-MM). */
function aplica(m,c,mes){
  if(c.ativo===false||m.ativo===false)return false;
  if(c.inicio&&mes<c.inicio)return false;
  if(c.fim&&mes>c.fim)return false;
  if(m.desde&&mes<m.desde)return false;
  const ex=(c.excecoes||{})[m.id];
  if(ex==='excluir')return false;
  if(m.meses&&m.meses.length&&!m.meses.includes(+mes.slice(5,7)))return false;
  if(ex==='incluir')return true;
  if(!(m.regimes||[]).includes(c.regime))return false;
  if(m.caractId&&!(c.caract||[]).includes(m.caractId))return false;
  return true;
}
function descVenc(m){
  if(m.vencTipo==='nutil')return m.vencValor+'º dia útil';
  if(m.vencTipo==='ultimo')return 'Último dia útil';
  return 'Dia '+m.vencValor+' ('+(m.ajuste==='antecipa'?'antecipa':'prorroga')+')';
}
const descFreq=m=>m.meses&&m.meses.length?'Em '+m.meses.map(n=>MESES_C[n-1]).join(', '):'Todo mês';

/* ---------- estado ---------- */
const state={me:null,escritorio:null,escritorios:[],csrf:'',usuarios:[],departamentos:[],caracteristicas:[],clientes:[],modelos:[],tarefas:{},feriados:[],plat:null,pronto:false,erro:''};
const ui={tab:'painel',mes:hojeISO().slice(0,7),f:{resp:'',regime:'',depto:'',q:'',pend:false},cf:{q:'',regime:'',resp:''}};
const papel=()=>state.me?state.me.papel:'';
const isColab=()=>papel()==='colaborador';
const isAdmin=()=>papel()==='admin';
const isDono=()=>!!(state.me&&state.me.dono);
const podeEditarCli=()=>papel()==='admin'||papel()==='contador';
const nomeResp=id=>{const u=state.usuarios.find(x=>x.id===id);return u?u.nome:'Sem responsável';};
const usuariosAtivos=()=>state.usuarios.filter(u=>u.ativo!==false);
const nomeDepto=id=>{const d=state.departamentos.find(x=>x.id===id);return d?d.nome:'—';};
const nomeCar=id=>{const k=state.caracteristicas.find(x=>x.id===id);return k?k.nome:'';};
const deptoIdx=m=>{const i=state.departamentos.findIndex(x=>x.id===m.deptoId);return i<0?999:i;};
const efetivo=(t,hoje)=>ABERTA.includes(t.status)&&t.venc<hoje?'atraso':t.status;

function tarefasDoMes(mes){
  const out=[];
  state.clientes.forEach(c=>{
    if(c.ativo===false)return;
    state.modelos.forEach(m=>{
      if(!aplica(m,c,mes))return;
      const k=mes+'__'+c.id+'__'+m.id,t=state.tarefas[k]||{};
      out.push({k,mes,c,m,venc:vencimento(m,mes),status:t.status||'pendente',obs:t.obs||'',por:t.por||'',em:t.em||''});
    });
  });
  return out;
}

/* ---------- comunicação com o servidor ---------- */
async function api(rota,opt){
  opt=opt||{};
  const metodo=opt.dados!==undefined?'POST':'GET';
  const q=opt.query?'&'+new URLSearchParams(opt.query).toString():'';
  const init={method:metodo,credentials:'same-origin',headers:{'Accept':'application/json'}};
  if(metodo==='POST'){
    init.headers['Content-Type']='application/json';
    init.headers['X-CSRF-Token']=state.csrf;
    init.body=JSON.stringify(opt.dados);
  }
  let r,j={};
  try{r=await fetch('api.php?r='+encodeURIComponent(rota).replace(/%2F/g,'/')+q,init);}
  catch(e){throw new Error('Sem conexão com o servidor.');}
  try{j=await r.json();}catch(e){j={};}
  if(r.status===401&&rota!=='login'){
    state.me=null;mostrarLogin('Sua sessão expirou. Entre novamente.');
    throw new Error('Sessão expirada.');
  }
  if(!r.ok)throw new Error(j.erro||('Erro '+r.status));
  return j;
}
async function gravar(fn){try{return await fn();}catch(e){toast(e.message||'Não consegui salvar.',true);throw e;}}

function norm(d){
  state.usuarios=(d.usuarios||[]).map(u=>Object.assign({},u,{id:String(u.id)}));
  state.departamentos=(d.departamentos||[]).map(x=>({id:String(x.id),nome:x.nome}));
  state.caracteristicas=(d.caracteristicas||[]).map(x=>({id:String(x.id),nome:x.nome}));
  state.clientes=(d.clientes||[]).map(c=>Object.assign({},c,{id:String(c.id),respId:c.respId==null?'':String(c.respId),caract:(c.caract||[]).map(String),excecoes:(c.excecoes&&!Array.isArray(c.excecoes))?c.excecoes:{},inicio:c.inicio||'',fim:c.fim||''}));
  state.modelos=(d.modelos||[]).map(m=>{const o=Object.assign({},m,{id:String(m.id),deptoId:String(m.deptoId),caractId:m.caractId==null?'':String(m.caractId),desde:m.desde||''});o.depto=nomeDepto(o.deptoId);return o;});
  const t={};(d.tarefas||[]).forEach(x=>{t[d.mes+'__'+x.clienteId+'__'+x.modeloId]=x;});
  state.tarefas=t;
  state.feriados=d.feriados||[];
  EXTRA.clear();state.feriados.forEach(f=>EXTRA.add(f.data));
}
let carregando=false,refazer=false;
async function carregar(){
  if(!state.me)return;
  if(carregando){refazer=true;return;}
  carregando=true;
  const mes=ui.mes;
  try{
    const d=await api('dados',{query:{mes}});
    if(mes===ui.mes){norm(d);state.pronto=true;state.erro='';}
  }catch(e){state.erro=e.message;if(!state.pronto)state.pronto=true;}
  finally{carregando=false;render();if(refazer){refazer=false;carregar();}}
}

/* ---------- toast e janela ---------- */
function toast(msg,err){
  const el=document.createElement('div');el.className='toast'+(err?' err':'');el.textContent=msg;
  $('#toast').appendChild(el);setTimeout(()=>el.remove(),err?6000:3200);
}
let modalSubmit=null,lastFocus=null;
function openModal(title,body,opt){
  opt=opt||{};lastFocus=document.activeElement;
  $('#modal').innerHTML='<div class="scrim" data-close="1"></div><div class="sheet" role="dialog" aria-modal="true" aria-labelledby="mt"><form id="mform" novalidate><header><h2 id="mt">'+esc(title)+'</h2><button type="button" class="x" data-close="1" aria-label="Fechar">×</button></header><div class="mbody">'+body+'<p class="err" id="merr" hidden></p></div><footer>'+(opt.left||'')+'<button type="button" class="btn" data-close="1">Cancelar</button><button type="submit" class="btn primary">'+esc(opt.submit||'Salvar')+'</button></footer></form></div>';
  modalSubmit=opt.onSubmit||null;
  const f=$('#modal').querySelector('input:not([type=hidden]),select,textarea');if(f)f.focus();
}
function closeModal(){$('#modal').innerHTML='';modalSubmit=null;if(lastFocus&&lastFocus.focus)try{lastFocus.focus();}catch(e){}render();}
function modalErr(msg){const e=$('#merr');if(e){e.textContent=msg;e.hidden=!msg;}}
function confirmar(titulo,texto,rotulo,fn){openModal(titulo,'<p>'+esc(texto)+'</p>',{submit:rotulo,onSubmit:async()=>{await fn();closeModal();}});}
const opts=(arr,sel,vazio)=>(vazio!=null?'<option value="">'+esc(vazio)+'</option>':'')+arr.map(x=>{const v=typeof x==='string'?x:x.id,t=typeof x==='string'?x:x.nome;return '<option value="'+esc(v)+'"'+(String(v)===String(sel)?' selected':'')+'>'+esc(t)+'</option>';}).join('');

/* ---------- renderização ---------- */
let rafId=0;
function render(){if(rafId)return;rafId=requestAnimationFrame(()=>{rafId=0;draw();});}
const TAB_NOMES={painel:'Painel',obrigacoes:'Obrigações',clientes:'Clientes',equipe:'Equipe',modelos:'Modelos e ajustes',escritorios:'Escritórios',conta:'Minha conta'};
function tabsPermitidas(){
  const l=['painel','obrigacoes','clientes'];
  if(isAdmin())l.push('equipe','modelos');
  if(isDono())l.push('escritorios');
  l.push('conta');
  return l;
}
/* Login em 2 etapas: 1) CNPJ do escritório, 2) e-mail e senha. */
const LS_CNPJ='obrig_cnpj';
const lsGet=()=>{try{return localStorage.getItem(LS_CNPJ)||'';}catch(e){return '';}};
const lsSet=v=>{try{if(v)localStorage.setItem(LS_CNPJ,v);else localStorage.removeItem(LS_CNPJ);}catch(e){}};
let loginCnpj='';
function loginEtapa(n,msg){
  $('#cform').hidden=n!==1;$('#lform').hidden=n!==2;
  const e1=$('#c-erro'),e2=$('#l-erro');
  e1.textContent=n===1?(msg||''):'';e1.hidden=!(n===1&&msg);
  e2.textContent=n===2?(msg||''):'';e2.hidden=!(n===2&&msg);
  if(n===1){const i=$('#c-cnpj');if(!i.value)i.value=fmtCnpj(lsGet());setTimeout(()=>i.focus(),0);}
  else{$('#l-cnpj').textContent=fmtCnpj(loginCnpj);setTimeout(()=>$('#l-email').focus(),0);}
}
function mostrarLogin(msg){
  $('#shell').hidden=true;$('#login').hidden=false;
  $('#modal').innerHTML='';
  loginCnpj='';$('#l-senha').value='';
  loginEtapa(1,msg);
}
function draw(){
  if(!state.me)return;
  if($('#modal').innerHTML)return;
  $('#login').hidden=true;$('#shell').hidden=false;
  const a=document.activeElement;
  const id=a&&a.id,s=a&&a.selectionStart,e2=a&&a.selectionEnd;
  const perm=tabsPermitidas();
  if(!perm.includes(ui.tab))ui.tab='painel';
  $('#tabs').innerHTML=perm.map(t=>'<a href="#'+t+'"'+(t===ui.tab?' aria-current="page"':'')+'>'+TAB_NOMES[t]+'</a>').join('');
  const esc0=state.escritorio||{nome:'',cnpj:''};
  $('#eoff').textContent=esc0.nome+' · '+fmtCnpj(esc0.cnpj);
  const troca=state.escritorios.length>1?'<select id="sw" aria-label="Trocar de escritório">'+state.escritorios.map(x=>'<option value="'+esc(x.id)+'"'+(x.id===esc0.id?' selected':'')+'>'+esc(x.nome)+'</option>').join('')+'</select>':'';
  $('#who').innerHTML=troca+'<span>'+esc(state.me.nome)+' · '+esc(PAPEIS[state.me.papel]||'')+'</span><button class="btn sm" data-act="sair">Sair</button>';
  $('#monthbar').innerHTML=(ui.tab==='painel'||ui.tab==='obrigacoes')?monthbar():'';
  const view=$('#view');
  if(!state.pronto){view.innerHTML='<div class="empty">Carregando…</div>';return;}
  if(ui.tab==='escritorios'&&state.plat===null)carregarPlat();
  const fn={painel:viewPainel,obrigacoes:viewObrig,clientes:viewClientes,equipe:viewEquipe,modelos:viewModelos,escritorios:viewEscritorios,conta:viewConta}[ui.tab];
  view.innerHTML=(state.erro?'<div class="banner warn"><p>'+esc(state.erro)+' Os dados na tela podem estar desatualizados.</p></div>':'')+fn();
  if(id){const n=document.getElementById(id);if(n){n.focus();try{n.setSelectionRange(s,e2);}catch(x){}}}
}
function monthbar(){
  const hoje=hojeISO().slice(0,7);
  return '<div class="monthbar"><button class="btn icon" data-act="mes-prev" aria-label="Mês anterior">‹</button><span class="lbl">'+esc(fmtMes(ui.mes))+'</span><button class="btn icon" data-act="mes-next" aria-label="Próximo mês">›</button>'+(ui.mes!==hoje?'<button class="btn" data-act="mes-hoje">Mês atual</button>':'')+'</div><p class="mnote">Vencimentos que caem em '+esc(fmtMes(ui.mes).toLowerCase())+' (competência de referência: '+esc(fmtMes(addMonthsYM(ui.mes,-1)).toLowerCase())+').</p>';
}
function tile(label,val,sub,cls,extra){return '<div class="tile '+(cls||'')+'"><span class="tl">'+label+'</span><strong>'+val+'</strong>'+(sub?'<span class="ts">'+sub+'</span>':'')+(extra||'')+'</div>';}
function pillT(t,hoje){
  const e=efetivo(t,hoje);
  const cls={concluida:'good',andamento:'info',aguardando:'warn',atraso:'bad',pendente:'mute',na:'mute'}[e];
  let tx=ST[e];
  if(e==='atraso'){const d=Math.round((msIso(hoje)-msIso(t.venc))/DAY);tx='Em atraso · '+d+' d';}
  if(e==='pendente'&&t.venc===hoje)tx='Vence hoje';
  return '<span class="pill '+cls+'">'+esc(tx)+'</span>';
}
const dowTxt=iso=>DOWS[dow(iso)];
function semDados(){
  if(isColab())return '<div class="banner"><p><b>Nenhum cliente na sua carteira.</b> Peça ao administrador para atribuir clientes a você.</p></div>';
  if(isAdmin()||papel()==='contador')return '<div class="banner"><p><b>Comece por aqui.</b> Cadastre os clientes (um por CNPJ) e atribua um responsável a cada um. As obrigações do mês aparecem sozinhas, conforme o regime.</p><div class="row"><button class="btn primary" data-act="novo-cli">Cadastrar cliente</button></div></div>';
  return '';
}

/* --- Painel --- */
function viewPainel(){
  const hoje=hojeISO();
  if(!state.clientes.length)return semDados();
  const ts=tarefasDoMes(ui.mes);
  const vivas=ts.filter(t=>t.status!=='na');
  const conc=vivas.filter(t=>t.status==='concluida').length;
  const pc=vivas.length?Math.round(conc/vivas.length*100):0;
  const atras=vivas.filter(t=>efetivo(t,hoje)==='atraso');
  const lim=addDias(hoje,7);
  const prox=vivas.filter(t=>ABERTA.includes(t.status)&&t.venc>=hoje&&t.venc<=lim);
  const hojeN=vivas.filter(t=>ABERTA.includes(t.status)&&t.venc===hoje).length;
  const aguard=vivas.filter(t=>t.status==='aguardando').length;
  let h='<div class="tiles">'+
    tile('Concluído no mês',pc+'%',conc+' de '+vivas.length+' obrigações',pc>=100?'good':'','<div class="bar"><i style="width:'+pc+'%"></i></div>')+
    tile('Em atraso',atras.length,atras.length?'precisam de ação agora':'nada atrasado',atras.length?'bad':'good')+
    tile('Vencem em 7 dias',prox.length,hojeN?hojeN+' vence(m) hoje':'a partir de hoje')+
    tile('Aguardando cliente',aguard,'esperando documento ou retorno')+
  '</div>';
  if(!isColab()){
    const linhas=usuariosAtivos().map(e=>{
      const mine=vivas.filter(t=>t.c.respId===e.id);
      if(!mine.length)return null;
      const cc=mine.filter(t=>t.status==='concluida').length,at=mine.filter(t=>efetivo(t,hoje)==='atraso').length;
      const p=Math.round(cc/mine.length*100);
      const ncli=new Set(mine.map(t=>t.c.id)).size;
      return '<tr><td>'+esc(e.nome)+'<span class="sub">'+esc(PAPEIS[e.papel]||'')+'</span></td><td class="num">'+ncli+'</td><td class="num">'+mine.length+'</td><td class="num">'+cc+'</td><td class="num">'+(at?'<span class="pill bad">'+at+'</span>':'<span class="pill good">0</span>')+'</td><td style="min-width:120px"><div class="bar"><i style="width:'+p+'%"></i></div><span class="sub">'+p+'%</span></td></tr>';
    }).filter(Boolean);
    const semResp=vivas.filter(t=>!t.c.respId).length;
    h+='<div class="card"><h2>Carga por colaborador</h2>'+(linhas.length?'<div class="scroll"><table><thead><tr><th>Quem</th><th class="num">Clientes</th><th class="num">Obrigações</th><th class="num">Feitas</th><th class="num">Atrasos</th><th>Andamento</th></tr></thead><tbody>'+linhas.join('')+'</tbody></table></div>':'<p class="note">Nenhuma obrigação atribuída a alguém neste mês.</p>')+(semResp?'<p class="note">'+semResp+' obrigações de clientes sem responsável. Atribua um responsável na tela Clientes.</p>':'')+'</div>';
  }
  const rank=t=>efetivo(t,hoje)==='atraso'?0:1;
  const lista=vivas.filter(t=>ABERTA.includes(t.status)).sort((a,b)=>rank(a)-rank(b)||a.venc.localeCompare(b.venc)||a.c.nome.localeCompare(b.c.nome,'pt-BR'));
  const mostra=lista.slice(0,30);
  h+='<div class="card"><div class="hd"><h2>O que precisa de atenção</h2><span class="note">'+lista.length+' em aberto no mês</span></div>';
  if(!mostra.length)h+='<div class="empty">Tudo em dia neste mês.</div>';
  else h+='<div class="scroll"><table><thead><tr><th>Cliente</th><th>Obrigação</th><th>Vence</th><th>Responsável</th><th>Situação</th><th></th></tr></thead><tbody>'+mostra.map(t=>'<tr><td>'+esc(t.c.nome)+'<span class="sub">'+esc(t.c.regime)+'</span></td><td>'+esc(t.m.nome)+'<span class="sub">'+esc(t.m.depto)+'</span></td><td class="nowrap">'+fmtDM(t.venc)+'<span class="sub">'+dowTxt(t.venc)+'</span></td><td>'+esc(nomeResp(t.c.respId))+'</td><td>'+pillT(t,hoje)+'</td><td class="nowrap"><button class="btn sm" data-act="concluir" data-k="'+esc(t.k)+'">Concluir</button> <button class="btn sm" data-act="tarefa" data-k="'+esc(t.k)+'">Abrir</button></td></tr>').join('')+'</tbody></table></div>'+(lista.length>mostra.length?'<p class="note">Mostrando as '+mostra.length+' mais urgentes. Veja o restante em Obrigações.</p>':'');
  h+='</div>';
  return h;
}

/* --- Obrigações (matriz) --- */
function viewObrig(){
  const hoje=hojeISO(),f=ui.f;
  if(!state.clientes.length)return semDados();
  let ts=tarefasDoMes(ui.mes);
  if(f.resp)ts=ts.filter(t=>f.resp==='__none'?!t.c.respId:t.c.respId===f.resp);
  if(f.regime)ts=ts.filter(t=>t.c.regime===f.regime);
  if(f.depto)ts=ts.filter(t=>t.m.depto===f.depto);
  if(f.q){const q=f.q.toLowerCase(),qd=alnum(f.q);ts=ts.filter(t=>t.c.nome.toLowerCase().includes(q)||(qd.length>=4&&alnum(t.c.cnpj).includes(qd)));}
  if(f.pend){const abertos=new Set(ts.filter(t=>ABERTA.includes(t.status)).map(t=>t.c.id));ts=ts.filter(t=>abertos.has(t.c.id));}
  const cols=[],seen={},vencRef={};
  ts.forEach(t=>{vencRef[t.m.id]=t.venc;if(!seen[t.m.id]){seen[t.m.id]=1;cols.push(t.m);}});
  cols.sort((a,b)=>deptoIdx(a)-deptoIdx(b)||vencRef[a.id].localeCompare(vencRef[b.id])||a.nome.localeCompare(b.nome,'pt-BR'));
  const cli=[],cs={};
  ts.forEach(t=>{if(!cs[t.c.id]){cs[t.c.id]=1;cli.push(t.c);}});
  cli.sort(byNome);
  const map={};ts.forEach(t=>{map[t.c.id+'|'+t.m.id]=t;});
  const respOpts='<option value="">Todos</option>'+usuariosAtivos().map(e=>'<option value="'+esc(e.id)+'"'+(f.resp===e.id?' selected':'')+'>'+esc(e.nome)+'</option>').join('')+'<option value="__none"'+(f.resp==='__none'?' selected':'')+'>Sem responsável</option>';
  let h='<div class="filters">'+
    '<label>Buscar cliente ou CNPJ<input id="fq" data-f="f.q" type="search" value="'+esc(f.q)+'" placeholder="Nome ou CNPJ"></label>'+
    (isColab()?'':'<label>Responsável<select data-f="f.resp">'+respOpts+'</select></label>')+
    '<label>Regime<select data-f="f.regime">'+opts(REGIMES,f.regime,'Todos')+'</select></label>'+
    '<label>Departamento<select data-f="f.depto">'+opts(state.departamentos.map(d=>d.nome),f.depto,'Todos')+'</select></label>'+
    '<label class="chk"><input type="checkbox" data-f="f.pend"'+(f.pend?' checked':'')+'> Só clientes com pendência</label></div>';
  h+='<div class="legend"><span>Legenda:</span>'+['concluida','andamento','aguardando','pendente','atraso','na'].map(s=>'<span class="cel s-'+s+'" style="cursor:default;min-width:0">'+esc(SH[s])+'</span>').join('')+'<span>· clique em uma célula para mudar a situação. Cada cliente só vê as obrigações que se aplicam a ele; o traço indica que não se aplica.</span></div>';
  if(!cli.length)return h+'<div class="card"><div class="empty">Nenhuma obrigação com esses filtros neste mês.</div></div>';
  const grupos=[];cols.forEach(m=>{const g=grupos[grupos.length-1];if(g&&g.nome===m.depto)g.n++;else grupos.push({nome:m.depto,n:1});});
  h+='<div class="card" style="padding:0"><div class="scroll"><table class="mx"><thead><tr><th class="cli" rowspan="2">Cliente</th>'+grupos.map(g=>'<th class="grp" colspan="'+g.n+'">'+esc(g.nome)+'</th>').join('')+'<th class="col" rowspan="2">Andamento</th></tr><tr>'+cols.map(m=>'<th class="col" title="'+esc(m.obs||'')+'">'+esc(m.nome)+'</th>').join('')+'</tr></thead><tbody>';
  cli.forEach(c=>{
    let tot=0,ok=0;
    const cells=cols.map(m=>{
      const t=map[c.id+'|'+m.id];
      if(!t)return '<td><span class="dot">·</span></td>';
      const e=efetivo(t,hoje);
      if(e!=='na'){tot++;if(e==='concluida')ok++;}
      return '<td><button class="cel s-'+e+'" data-act="tarefa" data-k="'+esc(t.k)+'" title="'+esc(c.nome+' · '+m.nome+' · '+ST[e]+' · vence '+fmtData(t.venc))+'">'+esc(SH[e])+'<small>'+fmtDM(t.venc)+'</small></button></td>';
    }).join('');
    h+='<tr><td class="cli">'+esc(c.nome)+'<span class="sub">'+esc(c.regime)+' · '+esc(nomeResp(c.respId))+'</span></td>'+cells+'<td class="prog">'+ok+'/'+tot+'<div class="bar"><i style="width:'+(tot?Math.round(ok/tot*100):0)+'%"></i></div></td></tr>';
  });
  h+='</tbody></table></div></div>';
  return h;
}

/* --- Clientes --- */
function viewClientes(){
  const f=ui.cf,ro=!podeEditarCli();
  let l=state.clientes.slice();
  if(f.regime)l=l.filter(c=>c.regime===f.regime);
  if(f.resp)l=l.filter(c=>f.resp==='__none'?!c.respId:c.respId===f.resp);
  if(f.q){const q=f.q.toLowerCase(),qd=alnum(f.q);l=l.filter(c=>c.nome.toLowerCase().includes(q)||(qd.length>=4&&alnum(c.cnpj).includes(qd)));}
  l.sort(byNome);
  let h='<div class="card"><div class="hd"><h2>Clientes</h2>'+(ro?'':'<button class="btn primary" data-act="novo-cli">Novo cliente</button>')+'</div>';
  h+='<div class="filters"><label>Buscar<input id="cq" data-f="cf.q" type="search" value="'+esc(f.q)+'" placeholder="Nome ou CNPJ"></label><label>Regime<select data-f="cf.regime">'+opts(REGIMES,f.regime,'Todos')+'</select></label>'+(isColab()?'':'<label>Responsável<select data-f="cf.resp"><option value="">Todos</option>'+usuariosAtivos().map(e=>'<option value="'+esc(e.id)+'"'+(f.resp===e.id?' selected':'')+'>'+esc(e.nome)+'</option>').join('')+'<option value="__none"'+(f.resp==='__none'?' selected':'')+'>Sem responsável</option></select></label>')+'</div>';
  if(!l.length)return h+'<div class="empty">'+(state.clientes.length?'Nenhum cliente com esses filtros.':'Nenhum cliente ainda.')+'</div></div>'+(state.clientes.length?'':semDados());
  h+='<div class="scroll"><table><thead><tr><th>Cliente</th><th>Regime</th><th>Responsável</th><th>Características</th><th class="num">Obrigações no mês</th>'+(ro?'':'<th></th>')+'</tr></thead><tbody>'+l.map(c=>{
    const n=state.modelos.filter(m=>aplica(m,c,ui.mes)).length;
    const nomes=(c.caract||[]).map(nomeCar).filter(Boolean);
    const nex=Object.keys(c.excecoes||{}).length;
    const car=nomes.slice(0,3).map(x=>'<span class="pill info">'+esc(x)+'</span>').join(' ')+(nomes.length>3?' <span class="pill mute" title="'+esc(nomes.slice(3).join(', '))+'">+'+(nomes.length-3)+'</span>':'')+(nex?' <span class="pill warn" title="Obrigações incluídas ou retiradas à mão para este cliente">'+nex+' exceção(ões)</span>':'')+(!nomes.length&&!nex?'<span class="sub">—</span>':'');
    const per=(c.inicio||c.fim)?'<span class="sub">'+(c.inicio?'desde '+esc(c.inicio.slice(5)+'/'+c.inicio.slice(0,4)):'')+(c.fim?' até '+esc(c.fim.slice(5)+'/'+c.fim.slice(0,4)):'')+'</span>':'';
    return '<tr><td>'+esc(c.nome)+'<span class="sub">'+esc(c.cnpj?fmtCnpj(c.cnpj):'sem CNPJ')+'</span>'+per+'</td><td><span class="pill acc">'+esc(c.regime)+'</span></td><td>'+esc(nomeResp(c.respId))+'</td><td class="chipcell">'+car+'</td><td class="num">'+(c.ativo===false?'<span class="pill mute">Inativo</span>':n)+'</td>'+(ro?'':'<td class="nowrap"><button class="btn sm" data-act="edit-cli" data-id="'+esc(c.id)+'">Editar</button></td>')+'</tr>';
  }).join('')+'</tbody></table></div></div>';
  return h;
}
function modalCliente(id){
  const c=state.clientes.find(x=>x.id===id)||{nome:'',cnpj:'',regime:'Simples Nacional',respId:'',ativo:true,obs:'',inicio:'',fim:'',caract:[],excecoes:{}};
  const carHtml=state.caracteristicas.length?state.caracteristicas.map(k=>'<label class="chk"><input type="checkbox" name="car" value="'+esc(k.id)+'"'+((c.caract||[]).includes(k.id)?' checked':'')+'> '+esc(k.nome)+'</label>').join(''):'<span class="note">Nenhuma característica cadastrada. Crie em "Modelos e ajustes".</span>';
  const exHtml=state.departamentos.map(d=>{
    const ms=state.modelos.filter(m=>m.deptoId===d.id&&m.ativo!==false);
    if(!ms.length)return '';
    return '<div class="exg"><b>'+esc(d.nome)+'</b>'+ms.map(m=>{
      const auto=id?aplica(m,Object.assign({},c,{excecoes:{}}),ui.mes):null;
      const atual=(c.excecoes||{})[m.id]||'';
      const rot=auto===null?'Automático':'Automático ('+(auto?'recebe':'não recebe')+')';
      return '<label class="exr"><span>'+esc(m.nome)+'</span><select name="ex_'+esc(m.id)+'"><option value="">'+esc(rot)+'</option><option value="incluir"'+(atual==='incluir'?' selected':'')+'>Sempre incluir</option><option value="excluir"'+(atual==='excluir'?' selected':'')+'>Nunca incluir</option></select></label>';
    }).join('')+'</div>';
  }).join('');
  const body='<label>Nome do cliente<input name="nome" value="'+esc(c.nome)+'" required maxlength="150"></label>'+
    '<div class="grid2"><label>CNPJ (numérico ou alfanumérico)<input name="cnpj" data-mask="cnpj" value="'+esc(fmtCnpj(c.cnpj))+'" placeholder="00.000.000/0000-00" maxlength="18" autocomplete="off"></label><label>Regime tributário<select name="regime">'+opts(REGIMES,c.regime)+'</select></label></div>'+
    '<label>Responsável<select name="respId">'+opts(usuariosAtivos(),c.respId,'Sem responsável')+'</select></label>'+
    '<div><span class="note" style="font-weight:600">Características do cliente (definem quais obrigações ele recebe)</span><div class="chips" style="margin-top:6px">'+carHtml+'</div></div>'+
    '<div class="grid2"><label>Atende desde (opcional)<input name="inicio" value="'+esc(c.inicio||'')+'" placeholder="AAAA-MM" maxlength="7" pattern="\\d{4}-\\d{2}"></label><label>Até (opcional, ao encerrar)<input name="fim" value="'+esc(c.fim||'')+'" placeholder="AAAA-MM" maxlength="7" pattern="\\d{4}-\\d{2}"></label></div>'+
    '<label class="chk"><input type="checkbox" name="ativo"'+(c.ativo!==false?' checked':'')+'> Cliente ativo</label>'+
    '<details class="exc"><summary>Exceções: incluir ou retirar uma obrigação só para este cliente'+(Object.keys(c.excecoes||{}).length?' ('+Object.keys(c.excecoes).length+')':'')+'</summary><p class="note">"Automático" segue o regime e as características. Use "Sempre incluir" ou "Nunca incluir" para os casos especiais deste cliente.</p>'+exHtml+'</details>'+
    '<label>Observação<textarea name="obs" maxlength="500">'+esc(c.obs||'')+'</textarea></label>';
  openModal(id?'Editar cliente':'Novo cliente',body,{
    left:id?'<button type="button" class="btn danger left" data-act="del-cli" data-id="'+esc(id)+'">Excluir</button>':'',
    onSubmit:async fd=>{
      const nome=(fd.get('nome')||'').trim();if(!nome){modalErr('Informe o nome do cliente.');return;}
      const cnpj=(fd.get('cnpj')||'').trim();
      if(cnpj&&!cnpjValido(cnpj)){modalErr('CNPJ inválido. Confira os números e letras (os dois últimos são dígitos).');return;}
      const inicio=(fd.get('inicio')||'').trim(),fim=(fd.get('fim')||'').trim();
      const re=/^\d{4}-(0[1-9]|1[0-2])$/;
      if((inicio&&!re.test(inicio))||(fim&&!re.test(fim))){modalErr('Use o formato AAAA-MM nas datas (ex.: 2026-03).');return;}
      if(inicio&&fim&&fim<inicio){modalErr('O mês final não pode ser anterior ao inicial.');return;}
      const excecoes={};for(const [k,v] of fd.entries()){if(k.startsWith('ex_')&&v)excecoes[k.slice(3)]=v;}
      const dados={id:id?Number(id):0,nome,cnpj,regime:fd.get('regime'),respId:Number(fd.get('respId'))||0,caract:fd.getAll('car').map(Number),excecoes,inicio,fim,ativo:fd.get('ativo')==='on',obs:(fd.get('obs')||'').trim()};
      try{await api('cliente/salvar',{dados});}catch(e){modalErr(e.message);return;}
      closeModal();toast('Cliente salvo.');carregar();
    }});
}

/* --- Equipe (só administrador) --- */
function viewEquipe(){
  const l=state.usuarios;
  let h='<div class="card"><div class="hd"><h2>Equipe de '+esc(state.escritorio?state.escritorio.nome:'')+'</h2><button class="btn primary" data-act="novo-eq">Novo usuário</button></div>';
  h+='<p class="note" style="margin:0 0 10px"><b>Administrador:</b> vê e altera tudo deste escritório. <b>Contador:</b> vê todas as carteiras e cadastra clientes. <b>Colaborador:</b> vê e atualiza só os clientes em que é responsável. Quem atende mais de um escritório entra com o CNPJ de cada um e tem papel próprio em cada.</p>';
  if(!l.length)return h+'<div class="empty">Ninguém cadastrado.</div></div>';
  h+='<div class="scroll"><table><thead><tr><th>Nome</th><th>E-mail (login)</th><th>Papel aqui</th><th class="num">Clientes</th><th>Último acesso</th><th></th></tr></thead><tbody>'+l.map(e=>{
    const n=state.clientes.filter(c=>c.respId===e.id&&c.ativo!==false).length;
    return '<tr><td>'+esc(e.nome)+(e.ativo===false?' <span class="pill mute">Bloqueado</span>':'')+((e.escritorios>1||e.dono)?' <span class="pill info" title="Esta pessoa também entra em outro escritório">vários escritórios</span>':'')+'</td><td>'+esc(e.email||'')+'</td><td><span class="pill '+(e.papel==='colaborador'?'mute':'acc')+'">'+esc(PAPEIS[e.papel])+'</span></td><td class="num">'+n+'</td><td class="nowrap">'+(e.ultimoLogin?esc(fmtHora(e.ultimoLogin)):'<span class="sub">nunca entrou</span>')+'</td><td class="nowrap"><button class="btn sm" data-act="edit-eq" data-id="'+esc(e.id)+'">Editar</button></td></tr>';
  }).join('')+'</tbody></table></div></div>';
  return h;
}
function modalUsuario(id){
  const e=state.usuarios.find(x=>x.id===id)||{nome:'',email:'',papel:'colaborador',ativo:true};
  const eu=state.me&&String(state.me.id)===String(id);
  const comp=!!(id&&(e.escritorios>1||e.dono));
  const dis=comp?' disabled':'';
  const body='<label>Nome<input name="nome" value="'+esc(e.nome)+'" required maxlength="120"'+dis+'></label><label>E-mail (será o login)<input name="email" type="email" value="'+esc(e.email||'')+'" required maxlength="160" autocomplete="off"'+dis+'></label><div class="grid2"><label>Papel neste escritório<select name="papel"'+(eu?' disabled':'')+'>'+Object.keys(PAPEIS).map(k=>'<option value="'+k+'"'+(k===e.papel?' selected':'')+'>'+esc(PAPEIS[k])+'</option>').join('')+'</select></label>'+(comp?'':'<label>'+(id?'Nova senha (deixe em branco para manter)':'Senha inicial')+'<input name="senha" type="password" autocomplete="new-password" minlength="8"></label>')+'</div>'+(eu?'':'<label class="chk"><input type="checkbox" name="ativo"'+(e.ativo!==false?' checked':'')+'> Pode entrar neste escritório</label>')+(comp?'<p class="note">Esta pessoa atende mais de um escritório: nome, e-mail e senha só ela mesma altera (em Minha conta). Aqui você controla o papel e o acesso dela a este escritório.</p>':(id?'<p class="note">A senha precisa ter pelo menos 8 caracteres.</p>':'<p class="note">Se este e-mail já tiver cadastro em outro escritório, a pessoa é apenas vinculada a este e continua com a senha dela. Senha nova: mínimo de 8 caracteres.</p>'));
  openModal(id?'Editar usuário':'Novo usuário',body,{
    left:id&&!eu?'<button type="button" class="btn danger left" data-act="del-eq" data-id="'+esc(id)+'">Remover deste escritório</button>':'',
    onSubmit:async fd=>{
      const nome=comp?e.nome:(fd.get('nome')||'').trim(),email=comp?e.email:(fd.get('email')||'').trim();
      if(!nome||!email){modalErr('Informe nome e e-mail.');return;}
      const dados={id:id?Number(id):0,nome,email,papel:eu?'admin':fd.get('papel'),ativo:eu?true:fd.get('ativo')==='on',senha:comp?'':(fd.get('senha')||'')};
      let r;
      try{r=await api('usuario/salvar',{dados});}catch(x){modalErr(x.message);return;}
      closeModal();toast(r&&r.vinculado?'Pessoa já cadastrada: vinculada a este escritório.':'Usuário salvo.');carregar();
    }});
}

/* --- Modelos e ajustes (só administrador) --- */
function resumoRegimes(m){return (m.regimes||[]).map(r=>'<span class="pill acc">'+esc(r.replace('Simples Nacional','Simples').replace('Lucro ','L. '))+'</span>').join(' ');}
function viewModelos(){
  let h='<div class="banner warn"><p><b>Confira os vencimentos.</b> Os prazos abaixo são pontos de partida para você revisar. O cálculo considera dia útil nacional, Carnaval e Corpus Christi; feriados estaduais e municipais entram na lista ao final. O dia exato de ISS, ICMS e guias locais varia por município e estado.</p></div>';
  h+='<div class="card"><div class="hd"><h2>Obrigações por departamento</h2><button class="btn primary" data-act="novo-md">Nova obrigação</button></div><p class="note" style="margin:0 0 10px">Cada obrigação vale para os regimes marcados e, se tiver uma <b>característica</b> exigida, só chega aos clientes que a têm (ex.: ISS só para quem tem ISS). Alterações valem para todos os clientes deste escritório; use "Aplica a partir de" para não mexer em meses passados.</p>';
  if(!state.modelos.length)h+='<div class="empty">Nenhuma obrigação ainda.</div>';
  state.departamentos.forEach(d=>{
    const l=state.modelos.filter(m=>m.deptoId===d.id).sort((a,b)=>a.nome.localeCompare(b.nome,'pt-BR'));
    if(!l.length)return;
    h+='<h3 class="dh">'+esc(d.nome)+' <span class="pill mute">'+l.length+'</span></h3><div class="scroll"><table><thead><tr><th>Obrigação</th><th>Regimes</th><th>Quem recebe</th><th>Quando</th><th>Vencimento</th><th></th></tr></thead><tbody>'+l.map(m=>'<tr><td>'+esc(m.nome)+(m.ativo===false?' <span class="pill mute">Desligada</span>':'')+(m.obs?'<span class="sub">'+esc(m.obs)+'</span>':'')+(m.desde?'<span class="sub">a partir de '+esc(m.desde.slice(5)+'/'+m.desde.slice(0,4))+'</span>':'')+'</td><td>'+resumoRegimes(m)+'</td><td>'+(m.caractId?'<span class="pill info">'+esc(nomeCar(m.caractId))+'</span>':'<span class="sub">Todos do regime</span>')+'</td><td>'+esc(descFreq(m))+'</td><td class="nowrap">'+esc(descVenc(m))+'</td><td class="nowrap"><button class="btn sm" data-act="edit-md" data-id="'+esc(m.id)+'">Editar</button></td></tr>').join('')+'</tbody></table></div>';
  });
  h+='</div>';
  const nDep=id=>state.modelos.filter(m=>m.deptoId===id).length;
  h+='<div class="card"><div class="hd"><h2>Departamentos</h2><button class="btn primary" data-act="novo-dep">Novo departamento</button></div><div class="chips">'+(state.departamentos.map(d=>'<button class="tag" data-act="edit-dep" data-id="'+esc(d.id)+'">'+esc(d.nome)+' <small>'+nDep(d.id)+'</small></button>').join('')||'<span class="note">Nenhum.</span>')+'</div><p class="note">Os departamentos organizam as colunas da tela Obrigações (ex.: Fiscal, Contábil, Pessoal, Societário).</p></div>';
  const nCarCli=id=>state.clientes.filter(c=>(c.caract||[]).includes(id)).length;
  const nCarMod=id=>state.modelos.filter(m=>m.caractId===id).length;
  h+='<div class="card"><div class="hd"><h2>Características dos clientes</h2><button class="btn primary" data-act="novo-car">Nova característica</button></div><div class="chips">'+(state.caracteristicas.map(k=>'<button class="tag" data-act="edit-car" data-id="'+esc(k.id)+'" title="'+nCarCli(k.id)+' cliente(s) · exigida por '+nCarMod(k.id)+' obrigação(ões)">'+esc(k.nome)+' <small>'+nCarCli(k.id)+'</small></button>').join('')||'<span class="note">Nenhuma.</span>')+'</div><p class="note">O número é a quantidade de clientes com a característica. Marque as características de cada empresa em Clientes: quem não tem ISS não recebe a tarefa de ISS, e assim por diante.</p></div>';
  h+='<div class="card"><div class="hd"><h2>Feriados locais</h2><button class="btn primary" data-act="novo-fer">Novo feriado</button></div><p class="note" style="margin:0 0 10px">Feriados estaduais e municipais (e pontos facultativos em que o banco não abre) mudam o dia útil do vencimento. Cadastre os da sua cidade e dos seus clientes.</p>';
  h+=state.feriados.length?'<div class="scroll"><table><thead><tr><th>Data</th><th>Descrição</th><th></th></tr></thead><tbody>'+state.feriados.map(f=>'<tr><td class="nowrap">'+esc(fmtData(f.data))+'</td><td>'+esc(f.descricao)+'</td><td class="nowrap"><button class="btn sm danger" data-act="del-fer" data-d="'+esc(f.data)+'">Remover</button></td></tr>').join('')+'</tbody></table></div>':'<div class="empty">Nenhum feriado local cadastrado.</div>';
  return h+'</div>';
}
function modalModelo(id){
  const m=state.modelos.find(x=>x.id===id)||{nome:'',deptoId:(state.departamentos[0]||{}).id||'',regimes:['Simples Nacional'],caractId:'',meses:[],vencTipo:'dia',vencValor:20,ajuste:'prorroga',desde:'',obs:'',ativo:true};
  const body='<label>Nome da obrigação<input name="nome" value="'+esc(m.nome)+'" required maxlength="120"></label><div class="grid2"><label>Departamento<select name="deptoId">'+opts(state.departamentos,m.deptoId)+'</select></label><label>Só para clientes que têm<select name="caractId">'+opts(state.caracteristicas,m.caractId,'(todos os clientes do regime)')+'</select></label></div><div><span class="note" style="font-weight:600">Regimes</span><div class="chips" style="margin-top:6px">'+REGIMES.map(r=>'<label class="chk"><input type="checkbox" name="rg" value="'+esc(r)+'"'+((m.regimes||[]).includes(r)?' checked':'')+'> '+esc(r)+'</label>').join('')+'</div></div><label>Meses em que vence (números separados por vírgula; vazio = todo mês)<input name="meses" value="'+esc((m.meses||[]).join(', '))+'" placeholder="Ex.: 3  ou  1, 4, 7, 10"></label><div class="grid2"><label>Tipo de prazo<select name="vencTipo"><option value="dia"'+(m.vencTipo==='dia'?' selected':'')+'>Dia fixo do mês</option><option value="nutil"'+(m.vencTipo==='nutil'?' selected':'')+'>N-ésimo dia útil</option><option value="ultimo"'+(m.vencTipo==='ultimo'?' selected':'')+'>Último dia útil</option></select></label><label>Dia (ou nº do dia útil)<input name="vencValor" type="number" min="1" max="31" value="'+esc(m.vencValor||'')+'"></label></div><label>Se o dia fixo cair em dia sem expediente<select name="ajuste"><option value="prorroga"'+(m.ajuste!=='antecipa'?' selected':'')+'>Prorroga para o próximo dia útil</option><option value="antecipa"'+(m.ajuste==='antecipa'?' selected':'')+'>Antecipa para o dia útil anterior</option></select></label><div class="grid2"><label>Aplica a partir de (opcional)<input name="desde" value="'+esc(m.desde||'')+'" placeholder="AAAA-MM" maxlength="7" pattern="\\d{4}-\\d{2}"></label><label>Observação (aparece na tarefa)<input name="obs" value="'+esc(m.obs||'')+'" maxlength="255" placeholder="Ex.: o dia varia por município"></label></div><label class="chk"><input type="checkbox" name="ativo"'+(m.ativo!==false?' checked':'')+'> Obrigação ligada (gera tarefas)</label>';
  openModal(id?'Editar obrigação':'Nova obrigação',body,{
    left:id?'<button type="button" class="btn danger left" data-act="del-md" data-id="'+esc(id)+'">Excluir</button>':'',
    onSubmit:async fd=>{
      const nome=(fd.get('nome')||'').trim();if(!nome){modalErr('Informe o nome da obrigação.');return;}
      if(!fd.get('deptoId')){modalErr('Escolha um departamento.');return;}
      const regimes=fd.getAll('rg');if(!regimes.length){modalErr('Marque ao menos um regime.');return;}
      const meses=(fd.get('meses')||'').split(/[,\s;]+/).map(Number).filter(n=>n>=1&&n<=12);
      const tipo=fd.get('vencTipo');let val=Number(fd.get('vencValor'))||0;
      if(tipo==='dia'&&(val<1||val>31)){modalErr('Informe um dia entre 1 e 31.');return;}
      if(tipo==='nutil'&&(val<1||val>23)){modalErr('Informe o dia útil (1 a 23).');return;}
      const desde=(fd.get('desde')||'').trim();
      if(desde&&!/^\d{4}-(0[1-9]|1[0-2])$/.test(desde)){modalErr('Use o formato AAAA-MM em "Aplica a partir de" (ex.: 2026-12).');return;}
      const dados={id:id?Number(id):0,nome,deptoId:Number(fd.get('deptoId')),caractId:Number(fd.get('caractId'))||0,regimes,meses:[...new Set(meses)].sort((a,b)=>a-b),vencTipo:tipo,vencValor:tipo==='ultimo'?0:val,ajuste:fd.get('ajuste'),desde,obs:(fd.get('obs')||'').trim(),ativo:fd.get('ativo')==='on'};
      try{await api('modelo/salvar',{dados});}catch(x){modalErr(x.message);return;}
      closeModal();toast('Obrigação salva.');carregar();
    }});
}
/* departamento ou característica: só um nome */
function modalNome(tipo,id){
  const lista=tipo==='departamento'?state.departamentos:state.caracteristicas;
  const x=lista.find(i=>i.id===id)||{nome:''};
  const ex=tipo==='departamento'?'Ex.: Societário':'Ex.: Tem retenção de INSS';
  openModal((id?'Editar ':'Novo ')+(tipo==='departamento'?'departamento':'característica'),'<label>Nome<input name="nome" value="'+esc(x.nome)+'" required maxlength="80" placeholder="'+esc(ex)+'"></label>'+(id?'<p class="note">Só é possível excluir quando nenhuma obrigação usa este item.</p>':''),{
    left:id?'<button type="button" class="btn danger left" data-act="del-'+(tipo==='departamento'?'dep':'car')+'" data-id="'+esc(id)+'">Excluir</button>':'',
    onSubmit:async fd=>{
      const nome=(fd.get('nome')||'').trim();if(!nome){modalErr('Informe o nome.');return;}
      try{await api(tipo+'/salvar',{dados:{id:id?Number(id):0,nome}});}catch(e){modalErr(e.message);return;}
      closeModal();toast('Salvo.');carregar();
    }});
}
function modalFeriado(){
  openModal('Novo feriado local','<div class="grid2"><label>Data<input name="data" type="date" required></label><label>Descrição<input name="descricao" maxlength="80" placeholder="Ex.: Aniversário da cidade" required></label></div>',{
    onSubmit:async fd=>{
      const data=fd.get('data'),descricao=(fd.get('descricao')||'').trim();
      if(!data||!descricao){modalErr('Informe a data e a descrição.');return;}
      try{await api('feriado/salvar',{dados:{data,descricao}});}catch(x){modalErr(x.message);return;}
      closeModal();toast('Feriado salvo.');carregar();
    }});
}

/* --- Escritórios (só o dono da plataforma) --- */
let platCarregando=false;
async function carregarPlat(){
  if(platCarregando)return;platCarregando=true;
  try{const r=await api('plataforma/escritorios');state.plat=r.escritorios||[];}
  catch(e){state.plat=[];toast(e.message,true);}
  finally{platCarregando=false;render();}
}
function viewEscritorios(){
  if(state.plat===null)return '<div class="empty">Carregando…</div>';
  let h='<div class="card"><div class="hd"><h2>Escritórios na plataforma</h2><button class="btn primary" data-act="novo-esc">Novo escritório</button></div><p class="note" style="margin:0 0 10px">Cada escritório é uma "ilha": seus clientes, obrigações e equipe ficam separados dos demais. Aqui você vê só os dados cadastrais e as contagens — nunca os clientes de outro escritório. Todo escritório novo já nasce com os departamentos, características e obrigações-padrão.</p>';
  h+='<div class="scroll"><table><thead><tr><th>Escritório</th><th>CNPJ</th><th class="num">Pessoas</th><th class="num">Clientes ativos</th><th>Situação</th><th></th></tr></thead><tbody>'+state.plat.map(x=>'<tr><td>'+esc(x.nome)+(state.escritorio&&String(x.id)===String(state.escritorio.id)?' <span class="pill info">você está aqui</span>':'')+'<span class="sub">criado em '+esc(fmtData((x.criadoEm||'').slice(0,10)))+'</span></td><td class="nowrap">'+esc(fmtCnpj(x.cnpj))+'</td><td class="num">'+x.pessoas+'</td><td class="num">'+x.clientes+'</td><td>'+(x.ativo?'<span class="pill good">Ativo</span>':'<span class="pill mute">Desativado</span>')+'</td><td class="nowrap"><button class="btn sm" data-act="edit-esc" data-id="'+esc(x.id)+'">Editar</button></td></tr>').join('')+'</tbody></table></div></div>';
  return h;
}
function modalEscritorio(id){
  const x=(state.plat||[]).find(e=>String(e.id)===String(id))||{nome:'',cnpj:'',ativo:true};
  const novo='<div class="sep"></div><p class="note"><b>Primeiro administrador do escritório</b></p><div class="grid2"><label>Nome<input name="adminNome" maxlength="120" required></label><label>E-mail (login)<input name="adminEmail" type="email" maxlength="160" required autocomplete="off"></label></div><label>Senha inicial (mínimo 8 caracteres; deixe em branco se o e-mail já tiver cadastro em outro escritório)<input name="adminSenha" type="password" autocomplete="new-password" minlength="8"></label>';
  openModal(id?'Editar escritório':'Novo escritório','<label>Nome do escritório<input name="nome" value="'+esc(x.nome)+'" required maxlength="150"></label><label>CNPJ do escritório (é o que a equipe digita para entrar)<input name="cnpj" data-mask="cnpj" value="'+esc(fmtCnpj(x.cnpj))+'" maxlength="18" required placeholder="00.000.000/0000-00" autocomplete="off"></label><label class="chk"><input type="checkbox" name="ativo"'+(x.ativo!==false?' checked':'')+'> Escritório ativo (desativar bloqueia a entrada de toda a equipe)</label>'+(id?'':novo),{
    onSubmit:async fd=>{
      const nome=(fd.get('nome')||'').trim(),cnpj=(fd.get('cnpj')||'').trim();
      if(!nome){modalErr('Informe o nome.');return;}
      if(!cnpjValido(cnpj)){modalErr('CNPJ inválido. Confira os números e letras.');return;}
      const dados={id:id?Number(id):0,nome,cnpj,ativo:fd.get('ativo')==='on'};
      if(!id){dados.adminNome=(fd.get('adminNome')||'').trim();dados.adminEmail=(fd.get('adminEmail')||'').trim();dados.adminSenha=fd.get('adminSenha')||'';
        if(!dados.adminNome||!dados.adminEmail){modalErr('Informe o nome e o e-mail do primeiro administrador.');return;}}
      try{await api('plataforma/escritorio/salvar',{dados});}catch(e){modalErr(e.message);return;}
      closeModal();toast(id?'Escritório atualizado.':'Escritório criado com o cadastro-padrão.');
      state.plat=null;
      try{const r=await api('me');if(r.usuario){aplicarSessao(r);}}catch(e){}
      render();
    }});
}

/* --- Minha conta --- */
function viewConta(){
  const u=state.me,e=state.escritorio||{nome:'',cnpj:''};
  return '<div class="card"><h2>Minha conta</h2><p style="margin:0 0 4px"><b>'+esc(u.nome)+'</b></p><p class="note" style="margin:0 0 4px">'+esc(u.email)+'</p><p class="note" style="margin:0 0 12px">'+esc(e.nome)+' · '+esc(fmtCnpj(e.cnpj))+' · '+esc(PAPEIS[u.papel])+'</p><div class="row"><button class="btn" data-act="senha">Alterar minha senha</button><button class="btn" data-act="sair">Sair</button></div></div>';
}
function modalSenha(){
  openModal('Alterar senha','<label>Senha atual<input name="atual" type="password" autocomplete="current-password" required></label><label>Nova senha (mínimo 8 caracteres)<input name="nova" type="password" autocomplete="new-password" required minlength="8"></label><label>Repita a nova senha<input name="nova2" type="password" autocomplete="new-password" required></label>',{
    onSubmit:async fd=>{
      const atual=fd.get('atual')||'',nova=fd.get('nova')||'';
      if(nova!==fd.get('nova2')){modalErr('As senhas novas não conferem.');return;}
      try{await api('senha/alterar',{dados:{atual,nova}});}catch(x){modalErr(x.message);return;}
      closeModal();toast('Senha alterada.');
    }});
}

/* ---------- tarefa ---------- */
function modalTarefa(k){
  const [mes,cid,mid]=k.split('__');
  const c=state.clientes.find(x=>x.id===cid),m=state.modelos.find(x=>x.id===mid);
  if(!c||!m)return;
  const t=state.tarefas[k]||{},st=t.status||'pendente',venc=vencimento(m,mes),hoje=hojeISO();
  const body='<p><b>'+esc(c.nome)+'</b><span class="sub">'+esc(c.regime)+' · responsável: '+esc(nomeResp(c.respId))+'</span></p><p><b>'+esc(m.nome)+'</b> <span class="pill acc">'+esc(m.depto)+'</span><span class="sub">Vence em '+fmtData(venc)+' ('+dowTxt(venc)+') · '+esc(descVenc(m))+'</span></p>'+(m.obs?'<p class="note">'+esc(m.obs)+'</p>':'')+'<p>'+pillT({status:st,venc},hoje)+'</p><label>Situação<select name="status">'+Object.keys(ST).filter(s=>s!=='atraso').map(s=>'<option value="'+s+'"'+(s===st?' selected':'')+'>'+esc(ST[s])+'</option>').join('')+'</select></label><label>Observação<textarea name="obs" maxlength="1000" placeholder="Ex.: cliente vai mandar as notas na terça">'+esc(t.obs||'')+'</textarea></label><div><span class="note" style="font-weight:600">Histórico</span><ul class="hist" id="hist"><li>Carregando…</li></ul></div>';
  openModal('Obrigação do mês',body,{onSubmit:async fd=>{
    try{await salvarTarefa(k,fd.get('status'),(fd.get('obs')||'').trim());}catch(e){modalErr(e.message);return;}
    closeModal();
  }});
  api('tarefa/historico',{query:{mes,cliente_id:cid,modelo_id:mid}}).then(r=>{
    const ul=$('#hist');if(!ul)return;
    ul.innerHTML=r.historico.length?r.historico.map(h=>'<li><b>'+esc(h.por)+'</b> · '+esc(fmtHora(h.em))+'<br>'+esc(ST[h.antes]||h.antes)+' → '+esc(ST[h.depois]||h.depois)+(h.obs?'<br><i>'+esc(h.obs)+'</i>':'')+'</li>').join(''):'<li>Sem alterações ainda.</li>';
  }).catch(()=>{const ul=$('#hist');if(ul)ul.innerHTML='<li>Não consegui carregar o histórico.</li>';});
}
async function salvarTarefa(k,status,obs){
  const [mes,cid,mid]=k.split('__');
  await api('tarefa/salvar',{dados:{mes,clienteId:Number(cid),modeloId:Number(mid),status,obs}});
  await carregar();
}

/* ---------- eventos ---------- */
document.addEventListener('click',e=>{
  if(e.target.closest('[data-close]')){closeModal();return;}
  const el=e.target.closest('[data-act]');if(!el)return;
  const a=el.dataset.act,id=el.dataset.id,k=el.dataset.k;
  if(a==='mes-prev'){ui.mes=addMonthsYM(ui.mes,-1);state.pronto=false;carregar();render();}
  else if(a==='mes-next'){ui.mes=addMonthsYM(ui.mes,1);state.pronto=false;carregar();render();}
  else if(a==='mes-hoje'){ui.mes=hojeISO().slice(0,7);state.pronto=false;carregar();render();}
  else if(a==='tarefa')modalTarefa(k);
  else if(a==='concluir')salvarTarefa(k,'concluida',(state.tarefas[k]&&state.tarefas[k].obs)||'').then(()=>toast('Marcada como concluída.')).catch(x=>toast(x.message,true));
  else if(a==='novo-cli')modalCliente(null);
  else if(a==='edit-cli')modalCliente(id);
  else if(a==='del-cli')confirmar('Excluir cliente','Excluir este cliente? O histórico de tarefas dele também será apagado. Para só parar de gerar tarefas, desmarque "Cliente ativo".','Excluir',async()=>{await gravar(()=>api('cliente/excluir',{dados:{id:Number(id)}}));await carregar();});
  else if(a==='novo-eq')modalUsuario(null);
  else if(a==='edit-eq')modalUsuario(id);
  else if(a==='del-eq')confirmar('Excluir usuário','Excluir este usuário? Os clientes dele ficam sem responsável. Para só impedir o acesso, edite e desmarque "Pode entrar no sistema".','Excluir',async()=>{await gravar(()=>api('usuario/excluir',{dados:{id:Number(id)}}));await carregar();});
  else if(a==='novo-md')modalModelo(null);
  else if(a==='edit-md')modalModelo(id);
  else if(a==='del-md')confirmar('Excluir modelo','Excluir este modelo? Ele deixa de gerar tarefas e o histórico dele é apagado.','Excluir',async()=>{await gravar(()=>api('modelo/excluir',{dados:{id:Number(id)}}));await carregar();});
  else if(a==='novo-fer')modalFeriado();
  else if(a==='del-fer')gravar(()=>api('feriado/excluir',{dados:{data:el.dataset.d}})).then(carregar).catch(()=>{});
  else if(a==='novo-dep')modalNome('departamento',null);
  else if(a==='edit-dep')modalNome('departamento',id);
  else if(a==='del-dep')confirmar('Excluir departamento','Excluir este departamento? Só é possível se nenhuma obrigação estiver nele.','Excluir',async()=>{await gravar(()=>api('departamento/excluir',{dados:{id:Number(id)}}));await carregar();});
  else if(a==='novo-car')modalNome('caracteristica',null);
  else if(a==='edit-car')modalNome('caracteristica',id);
  else if(a==='del-car')confirmar('Excluir característica','Excluir esta característica? Ela some de todos os clientes. Só é possível se nenhuma obrigação a exigir.','Excluir',async()=>{await gravar(()=>api('caracteristica/excluir',{dados:{id:Number(id)}}));await carregar();});
  else if(a==='novo-esc')modalEscritorio(null);
  else if(a==='edit-esc')modalEscritorio(id);
  else if(a==='etapa1')loginEtapa(1,'');
  else if(a==='senha')modalSenha();
  else if(a==='sair')sair();
});
document.addEventListener('submit',async e=>{
  if(e.target.id==='cform'){e.preventDefault();return continuarLogin();}
  if(e.target.id==='lform'){e.preventDefault();return entrar();}
  if(e.target.id!=='mform')return;
  e.preventDefault();
  if(!modalSubmit)return;
  const btn=e.target.querySelector('button[type=submit]');if(btn)btn.disabled=true;
  try{modalErr('');await modalSubmit(new FormData(e.target));}catch(x){}
  if(btn)btn.disabled=false;
});
function onFilter(e){
  const t=e.target;
  if(!t.dataset||!t.dataset.f)return;
  const [g,key]=t.dataset.f.split('.');
  ui[g][key]=t.type==='checkbox'?t.checked:t.value;
  render();
}
document.addEventListener('input',e=>{
  const t=e.target;
  if(t&&t.dataset&&t.dataset.mask==='cnpj'){const v=fmtCnpj(t.value);if(v!==t.value)t.value=v;}
  onFilter(e);
});
document.addEventListener('change',e=>{if(e.target&&e.target.id==='sw')trocarEscritorio(e.target.value);});
document.addEventListener('change',onFilter);
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&$('#modal').innerHTML)closeModal();});
function rota(){const h=(location.hash||'').slice(1);if(TAB_NOMES[h]){if(h==='escritorios')state.plat=null;ui.tab=h;render();}}
window.addEventListener('hashchange',rota);

/* ---------- entrar, sair e início ---------- */
function aplicarSessao(r){
  state.me=r.usuario;state.csrf=r.csrf;state.escritorio=r.escritorio||null;state.escritorios=(r.escritorios||[]).map(x=>Object.assign({},x,{id:String(x.id)}));
  if(state.escritorio)state.escritorio.id=String(state.escritorio.id);
}
function zerarDados(){
  Object.assign(state,{usuarios:[],departamentos:[],caracteristicas:[],clientes:[],modelos:[],tarefas:{},feriados:[],plat:null,pronto:false,erro:''});
  ui.f={resp:'',regime:'',depto:'',q:'',pend:false};ui.cf={q:'',regime:'',resp:''};ui.tab='painel';
}
/* etapa 1: confere o CNPJ (só os dígitos verificadores; o servidor confere o resto junto com e-mail e senha) */
function continuarLogin(){
  const v=$('#c-cnpj').value;
  if(!cnpjValido(v)){loginEtapa(1,'CNPJ inválido. Confira os números e letras (os dois últimos são dígitos).');return;}
  loginCnpj=alnum(v);loginEtapa(2,'');
}
async function entrar(){
  const btn=$('#l-btn'),err=$('#l-erro');
  err.hidden=true;btn.disabled=true;
  try{
    const r=await api('login',{dados:{cnpj:loginCnpj,email:$('#l-email').value.trim(),senha:$('#l-senha').value}});
    lsSet(loginCnpj);
    zerarDados();aplicarSessao(r);$('#l-senha').value='';
    iniciarSessao();
  }catch(e){err.textContent=e.message;err.hidden=false;}
  btn.disabled=false;
}
async function sair(){
  try{await api('logout',{dados:{}});}catch(e){}
  state.me=null;state.csrf='';state.escritorio=null;state.escritorios=[];zerarDados();
  mostrarLogin('');
}
async function trocarEscritorio(id){
  try{
    const r=await api('escritorio/trocar',{dados:{id:Number(id)}});
    zerarDados();aplicarSessao(r);location.hash='#painel';carregar();render();
  }catch(e){toast(e.message,true);render();}
}
function iniciarSessao(){
  rota();
  $('#login').hidden=true;$('#shell').hidden=false;
  carregar();render();
}
/* Atualiza sozinho a cada minuto, para ver o que os colegas marcaram (não mexe se houver janela aberta). */
setInterval(()=>{if(state.me&&document.visibilityState==='visible'&&!$('#modal').innerHTML)carregar();},60000);
document.addEventListener('visibilitychange',()=>{if(state.me&&document.visibilityState==='visible'&&!$('#modal').innerHTML)carregar();});

(async function init(){
  try{
    const r=await api('me');
    if(r.usuario){aplicarSessao(r);iniciarSessao();}
    else mostrarLogin('');
  }catch(e){mostrarLogin(e.message);}
})();
})();
