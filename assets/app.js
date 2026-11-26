document.addEventListener('DOMContentLoaded',()=>{document.querySelectorAll('form').forEach(f=>f.addEventListener('submit',()=>{const b=f.querySelector('button[type="submit"],button:not([type])');if(b){b.dataset.old=b.textContent;b.disabled=true;b.textContent='Traitement...';}}));});

/* ---------- Filtres de période : jour / mois / année / période libre / global + raccourcis ---------- */
document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('form[data-scope-form]').forEach(form=>{
    const sel=form.querySelector('select[data-scope]'); if(!sel)return;
    const pad=n=>String(n).padStart(2,'0'),ymd=d=>d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate());
    const show=()=>form.querySelectorAll('[data-scope-field]').forEach(el=>{el.style.display=el.dataset.scopeField===sel.value?'':'none';});
    sel.addEventListener('change',show);show();
    form.querySelectorAll('[data-quick]').forEach(b=>b.addEventListener('click',()=>{
      const now=new Date(),set=(n,v)=>{const e=form.querySelector('[name="'+n+'"]');if(e)e.value=v;};
      const k=b.dataset.quick;
      if(k==='today'){sel.value='day';set('jour',ymd(now));}
      else if(k==='month'){sel.value='month';set('mois',now.getFullYear()+'-'+pad(now.getMonth()+1));}
      else if(k==='year'){sel.value='year';set('annee',now.getFullYear());}
      else{sel.value='all';}
      show();form.submit();
    }));
  });
});

/* ---------- Aperçu avant impression avec zoom ----------
   Tout bouton portant data-preview="#selecteur" ouvre l'aperçu du bloc ciblé.
   Un élément portant data-autopreview ouvre l'aperçu dès le chargement de la page. */
(function(){
  const MM=96/25.4, PAPERS={A4:[210,297],A5:[148,210],Letter:[215.9,279.4]};
  let overlay=null,sheet=null,page=null,state={zoom:100,orient:'portrait',size:'A4'},oldTitle='',styleEl=null;
  try{const s=JSON.parse(localStorage.getItem('egd_print')||'{}');if(s.zoom)state.zoom=Math.max(30,Math.min(250,+s.zoom||100));if(s.orient)state.orient=s.orient==='landscape'?'landscape':'portrait';if(PAPERS[s.size])state.size=s.size;}catch(e){}
  const save=()=>{try{localStorage.setItem('egd_print',JSON.stringify(state));}catch(e){}};
  const hasZoom=window.CSS&&CSS.supports&&CSS.supports('zoom','1');

  function applyZoom(){
    const z=state.zoom/100;
    if(hasZoom){sheet.style.zoom=z;sheet.style.transform='';sheet.style.width='';}
    else{sheet.style.transform='scale('+z+')';sheet.style.transformOrigin='0 0';sheet.style.width=(100/z)+'%';}
    overlay.querySelector('[data-role=range]').value=state.zoom;
    overlay.querySelector('[data-role=out]').textContent=Math.round(state.zoom)+' %';
    save();
  }
  function applyPaper(){
    const p=PAPERS[state.size],w=state.orient==='landscape'?p[1]:p[0];
    page.style.width=w+'mm';
    if(!styleEl){styleEl=document.createElement('style');styleEl.id='ppPageStyle';document.head.appendChild(styleEl);}
    styleEl.textContent='@page{size:'+state.size+' '+state.orient+';margin:10mm}';
    save();
  }
  function fit(){
    // Ajuste le contenu à la largeur utile de la page (réduit seulement si le contenu déborde).
    const prev=state.zoom;state.zoom=100;applyZoom();
    const avail=page.clientWidth-2*10*MM,need=Math.max(sheet.scrollWidth,sheet.clientWidth);
    let z=need>avail?Math.floor(avail/need*100):100;
    state.zoom=Math.max(30,Math.min(100,z||prev));applyZoom();
  }
  function build(){
    overlay=document.createElement('div');overlay.id='ppOverlay';overlay.className='pp-overlay';
    overlay.innerHTML='<div class="pp-toolbar"><b>Aperçu avant impression</b>'
      +'<span class="pp-group">Zoom <button type="button" class="btn" data-act="minus" title="Réduire">−</button><input type="range" data-role="range" min="30" max="250" step="5"><button type="button" class="btn" data-act="plus" title="Agrandir">+</button><output data-role="out"></output></span>'
      +'<button type="button" class="btn" data-act="fit">Ajuster à la largeur</button><button type="button" class="btn" data-act="100">100 %</button>'
      +'<select data-role="orient" title="Orientation"><option value="portrait">Portrait</option><option value="landscape">Paysage</option></select>'
      +'<select data-role="size" title="Format du papier"><option>A4</option><option>A5</option><option>Letter</option></select>'
      +'<button type="button" class="btn primary" data-act="print">🖨 Imprimer / PDF</button><button type="button" class="btn" data-act="close">✕ Fermer</button></div>'
      +'<div class="pp-scroll"><div class="pp-page"><div class="pp-sheet"></div></div></div>';
    document.body.appendChild(overlay);
    page=overlay.querySelector('.pp-page');sheet=overlay.querySelector('.pp-sheet');
    const range=overlay.querySelector('[data-role=range]');
    range.addEventListener('input',()=>{state.zoom=+range.value;applyZoom();});
    overlay.querySelector('[data-role=orient]').addEventListener('change',e=>{state.orient=e.target.value;applyPaper();});
    overlay.querySelector('[data-role=size]').addEventListener('change',e=>{state.size=e.target.value;applyPaper();});
    overlay.addEventListener('click',e=>{
      const b=e.target.closest('[data-act]');if(!b)return;const a=b.dataset.act;
      if(a==='minus'){state.zoom=Math.max(30,state.zoom-10);applyZoom();}
      else if(a==='plus'){state.zoom=Math.min(250,state.zoom+10);applyZoom();}
      else if(a==='100'){state.zoom=100;applyZoom();}
      else if(a==='fit')fit();
      else if(a==='print')window.print();
      else if(a==='close')close();
    });
    overlay.addEventListener('wheel',e=>{if(e.ctrlKey){e.preventDefault();state.zoom=Math.max(30,Math.min(250,state.zoom+(e.deltaY<0?5:-5)));applyZoom();}},{passive:false});
    document.addEventListener('keydown',e=>{if(overlay&&e.key==='Escape')close();});
  }
  function open(selector){
    const src=document.querySelector(selector);if(!src)return;
    if(!overlay)build();
    const clone=src.cloneNode(true);
    clone.querySelectorAll('.no-print,script,[data-preview]').forEach(n=>n.remove());
    clone.removeAttribute('id');clone.removeAttribute('data-autopreview');
    sheet.innerHTML='';sheet.appendChild(clone);
    const foot=document.createElement('div');foot.className='pp-foot';foot.textContent='EGLISE GERME DE DAVID — document imprimé le '+new Date().toLocaleString('fr-FR');sheet.appendChild(foot);
    overlay.querySelector('[data-role=orient]').value=state.orient;overlay.querySelector('[data-role=size]').value=state.size;
    overlay.style.display='flex';document.body.classList.add('pp-open');
    oldTitle=document.title;const t=clone.querySelector('.report-head b,h1,h2');if(t)document.title=t.textContent.trim().slice(0,80);
    applyPaper();applyZoom();
  }
  function close(){if(!overlay)return;overlay.style.display='none';document.body.classList.remove('pp-open');document.title=oldTitle||document.title;if(styleEl){styleEl.remove();styleEl=null;}}
  document.addEventListener('click',e=>{const b=e.target.closest('[data-preview]');if(b){e.preventDefault();open(b.dataset.preview);}});
  window.addEventListener('afterprint',()=>{if(oldTitle)document.title=oldTitle;});
  document.addEventListener('DOMContentLoaded',()=>{const a=document.querySelector('[data-autopreview]');if(a)open(a.dataset.autopreview);});
})();
