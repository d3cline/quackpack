'use strict';
(() => {
  const state = JSON.parse(document.getElementById('pup-state').textContent);
  const route = path => {
    if(state.routing !== 'query')return path;
    const u = new URL(path, location.origin);
    if(u.pathname === '/')return path;
    return '/index.php?route='+encodeURIComponent(u.pathname)+(u.search?'&'+u.search.slice(1):'')+u.hash;
  };
  const $ = (s, root = document) => root.querySelector(s);
  const $$ = (s, root = document) => [...root.querySelectorAll(s)];
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const safeURL = s => { try {const u = new URL(s, location.origin); return ['https:', 'http:'].includes(u.protocol) && (u.protocol === 'https:' || u.origin === location.origin) ? u.href : '';} catch {return '';}};
  const storage = {get(k,fallback=null){try{return JSON.parse(sessionStorage.getItem('pup:'+k))??fallback;}catch{return fallback;}},set(k,v){try{sessionStorage.setItem('pup:'+k,JSON.stringify(v));}catch{}}};
  let posts = state.posts, layout = storage.get('layout','grid'), cursor = state.view==='feed'?(state.feed_cursor||''):'', busy = false, browse = null, browseVersion = 0, more = state.view==='feed'?!!state.feed_cursor:(!state.single && posts.length === 24);
  let files = [], objectURLs = [], viewerPost, viewerIndex = 0, toastTimer, pendingReload = false;
  const api = async (path, data, options = {}) => {
    const init = {credentials:'same-origin',headers:{},...options};
    if (data !== undefined) {init.method='POST';init.headers['X-CSRF-Token']=state.csrf;
      if(data instanceof FormData)init.body=data;
      else {init.headers['Content-Type']='application/json';init.body=JSON.stringify(data);}
    }
    const r = await fetch(route(path),init); let value;
    try {value=await r.json();} catch {throw new Error('The server did not return a readable response.');}
    if(!r.ok){const error=new Error(value.error||'That request could not be completed.');error.accessRequired=!!value.access_required;throw error;} return value;
  };
  const toast = (text, error=false) => {const el=$('#toast');el.textContent=text;el.classList.toggle('error',error);el.hidden=false;clearTimeout(toastTimer);toastTimer=setTimeout(()=>el.hidden=true,5500);};
  const run = async (fn,button) => {if(button)button.disabled=true;try{return await fn();}catch(e){toast(e.message,true);}finally{if(button)button.disabled=false;}};
  const dateLabel = d => {try {const date=new Date(d);return Number.isNaN(date.getTime())?'':date.toLocaleDateString(undefined,{month:'short',day:'numeric'});}catch{return '';}};
  function persist() {if(state.view==='explore' && browse)storage.set('browse-v2',{browse,posts,cursor,more,scroll:window.scrollY,source:$('#source-info')?.dataset.source||''});}
  function postHTML(p,i) {
    const image = p.photos?.[0], hidden = p.sensitive && !p.revealed;
    let media;
    if(hidden)media=`<div class="sensitive-cover"><strong>${esc(p.summary||'Sensitive content')}</strong><span>This moment has a content warning.</span><button class="button secondary reveal" data-index="${i}">Show moment</button></div>`;
    else if(!image&&p.preview&&safeURL(p.preview))media=`<a class="remote-preview" href="${esc(safeURL(p.url))}" target="_blank" rel="noopener noreferrer"><img src="${esc(safeURL(p.preview))}" alt="${esc(p.kind||'Public post')} preview" loading="lazy" referrerpolicy="no-referrer"><span>${p.kind==='Video'?'▶':p.kind==='Audio'?'♫':'↗'} ${esc(p.kind||'Post')} · Open original ↗</span></a>`;
    else if(image)media=`<button class="photo-button" data-index="${i}" aria-label="Open photo: ${esc(image.alt||p.name)}"><img src="${esc(safeURL(image.url))}" alt="${esc(image.alt)}" loading="lazy" decoding="async" referrerpolicy="no-referrer"></button>${p.photos.length>1?`<span class="photo-count">▧ ${p.photos.length}</span>`:''}`;
    else media=`<div class="text-card">${esc(p.content.slice(0,900))}</div>`;
    return `<article class="post" data-index="${i}"><div class="photo-wrap">${media}</div><div class="post-meta"><button class="post-person" data-index="${i}" title="${esc(p.handle)}">${esc(p.name)}</button><time class="post-date" datetime="${esc(p.published)}">${esc(dateLabel(p.published))}</time></div><p class="post-caption">${esc(p.content)}</p><div class="post-actions">${state.owner?`${!p.local?`<button class="post-action like ${p.liked?'selected':''}" data-index="${i}" aria-label="${p.liked?'Unlike':'Like'}" aria-pressed="${!!p.liked}">${p.liked?'♥':'♡'}</button>`:''}<button class="post-action save ${p.saved?'selected':''}" data-index="${i}" aria-label="${p.saved?'Unsave':'Save'}" aria-pressed="${!!p.saved}">▱</button><button class="post-action reply" data-index="${i}" aria-label="Write a public reply">↩</button>${p.local?`<button class="post-action comments" data-index="${i}" aria-label="Read replies">◌</button>`:''}`:''}<a class="post-action" href="${esc(safeURL(p.url)||'#')}" target="_blank" rel="noopener noreferrer" aria-label="Open original post">↗</a>${state.owner&&p.local?`<button class="post-action post-menu edit" data-index="${i}" aria-label="Edit post">Edit</button><button class="post-action delete" data-index="${i}" aria-label="Delete post">×</button>`:''}</div></article>`;
  }
  function render() {
    const feed=$('#feed');if(!feed)return;
    feed.className=layout==='grid'?'feed-grid':'feed-reading';feed.innerHTML=posts.map(postHTML).join('');
    $$('.view-btn').forEach(b=>{b.classList.toggle('selected',b.dataset.layout===layout);b.setAttribute('aria-pressed',String(b.dataset.layout===layout));});
    $('#load-more').hidden=!more;
    empty();
    $$('img',feed).forEach(img=>img.addEventListener('error',()=>{img.classList.add('failed');const message=document.createElement('span');message.className='image-error';message.textContent='Photo unavailable · Open original ↗';img.parentNode.append(message);},{once:true}));
    persist();
  }
  function empty(title,description,action,fn) {
    const el=$('#empty-state');if(!el)return;el.hidden=posts.length>0||busy;
    if(posts.length)return;
    const defaults={feed:['The flock is on its way.','Your background crawler is finding public photos. Its progress is above; new moments will appear here as they arrive.','Check for moments',()=>refreshMaster()],journal:['Every good journal starts somewhere.','A photo, a few words, a moment that feels like you.',state.owner?'Publish your first moment':'',()=>openComposer()],explore:['The world is a little bigger here.','Open a server or a person above. Save the places you love, and make your own way through the Fediverse.','Try pixelfed.social',()=>startBrowse('pixelfed.social')],following:['Good company starts with a hello.','Find someone in Explore and follow them. Their new public posts will arrive right here.','Find your people',()=>location.href=route('/explore')],saved:['Some moments deserve a bookmark.','Use the save button beneath a post. Your collection is private, and stays here.','Go exploring',()=>location.href=route('/explore')]}[state.view];
    const [t,d,a,f]=title?[title,description,action,fn]:defaults||['Nothing here yet.','','',()=>{}];
    $('h2',el).textContent=t;$('p',el).textContent=d;const button=$('#empty-action');button.textContent=a;button.hidden=!a;button.onclick=f;
  }
  $$('.view-btn').forEach(b=>b.addEventListener('click',()=>{layout=b.dataset.layout;storage.set('layout',layout);render();}));
  function renderPlaces() {
    const el=$('#server-list');if(!el)return;
    el.innerHTML=state.servers.map((s,i)=>`<div class="place-item"><a href="${esc(route(`/explore?q=${encodeURIComponent(s.domain)}`))}">${esc(s.domain)}</a><button class="place-edit" data-server="${i}" aria-label="Edit ${esc(s.domain)}">···</button></div>`).join('');
    $$('.place-edit',el).forEach(b=>b.onclick=()=>openPlace(state.servers[Number(b.dataset.server)]));
  }
  function sourceInfo(result) {
    const el=$('#source-info');if(!el)return;el.hidden=false;
    const actor=result.actor;
    el.innerHTML=`<div><strong>${esc(result.source||browse.q)}</strong><p>${esc(actor?actor.handle: (browse.tag?'#'+browse.tag+' · ':'')+(result.listing||(browse.local?'Local public timeline':'Federated public timeline')))}</p>${actor?.bio?`<p>${esc(actor.bio.slice(0,250))}</p>`:''}${result.notice?`<p class="source-notice">${esc(result.notice)}</p>`:''}</div>${actor?`<button class="button secondary" id="follow-person">${actor.following==='accepted'?'Following':actor.following==='pending'?'Requested':'Follow ↗'}</button>`:`<button class="text-link" id="save-place">Save place ＋</button>`}`;
    let people=$('#source-people');
    if(!people){people=document.createElement('section');people.id='source-people';people.className='source-people';people.setAttribute('aria-label','Public people and communities');el.after(people);}
    const entries=(result.people||[]).slice(0,12);people.hidden=!entries.length;
    people.innerHTML=entries.length?`<div class="links-heading"><h2>People & places to open.</h2></div><div class="people-grid">${entries.map((p,i)=>`<button type="button" class="person-card" data-person="${i}">${safeURL(p.avatar)?`<img src="${esc(safeURL(p.avatar))}" alt="" loading="lazy" referrerpolicy="no-referrer">`:`<span class="person-initial" aria-hidden="true">${esc((p.name||'?').slice(0,1))}</span>`}<span><strong>${esc(p.name)}</strong><small>${esc(p.handle)}</small></span><span aria-hidden="true">↗</span></button>`).join('')}</div>`:'';
    $$('[data-person]',people).forEach(button=>button.onclick=()=>{const p=entries[Number(button.dataset.person)];if(p.mode==='person')startBrowse(p.url,false,'person');else startBrowse(browse.q,false,'account',p.id,p.family||'mastodon');});
    el.dataset.source=JSON.stringify({source:result.source,actor:result.actor,notice:result.notice,access_required:result.access_required,listing:result.listing,people:result.people,needs_profile:result.needs_profile});
    if(actor){$('#follow-person').onclick=e=>run(async()=>{if(actor.following){await api('/api/unfollow',{id:actor.id});actor.following=null;toast('Unfollowed.');}else{const r=await api('/api/follow',{q:actor.id});actor.following=r.state;toast(r.state==='accepted'?'You’re following.':'Follow request sent.');}sourceInfo(result);persist();},e.currentTarget);}
    else $('#save-place').onclick=()=>openPlace({domain:browse.q});
  }
  function infer(q) {if(q.startsWith('@')||(!q.includes('://')&&q.includes('@')))return 'person';if(q.startsWith('https://')){try {const path=new URL(q).pathname;if((path!=='/'&&path!=='')||new URL(q).searchParams.has('route'))return 'person';}catch{}}return 'server';}
  async function startBrowse(q,append=false,forceMode=null,accountId='',family='') {
    if(busy&&append)return;
    const version=++browseVersion;busy=true;
    if(!append){const prior=browse;const account=accountId||(forceMode==='account'?prior?.account:'')||'';const sourceFamily=family||(forceMode==='account'?prior?.family:'')||'';browse={account,family:sourceFamily,q:q.trim(),mode:forceMode||infer(q.trim()),tag:$('#browse-tag').value.trim().replace(/^#/,''),photos:$('#photos-only').checked,local:$('#local-only')?.checked??true};cursor='';posts=[];more=false;$('#browse-q').value=browse.q;history.replaceState({},'',route(`/explore?q=${encodeURIComponent(browse.q)}${browse.account?'&account='+encodeURIComponent(browse.account)+'&family='+encodeURIComponent(browse.family):''}`));}
    const mode=browse.mode;
    $('#empty-state').hidden=true;$('#feed-status').textContent=append?'A little further…':'Opening a window…';$('#load-more').disabled=true;
    if(!append)$('#feed').innerHTML='<div class="feed-skeleton"></div>'.repeat(6);
    const params=new URLSearchParams({...browse,photos:browse.photos?'1':'0',local:browse.local?'1':'0',cursor});
    try {
      let r;
      try {r=await api('/api/browse?'+params);}catch(e){
        // A pasted URL can be a profile or a post. Resolve either without forcing the user to know.
        if(!append && mode==='person' && browse.q.startsWith('https://')){params.set('mode','post');r=await api('/api/browse?'+params);browse.mode='post';}else throw e;
      }
      if(version!==browseVersion)return;
      const seen=new Set(posts.map(p=>p.id));posts=posts.concat(r.posts.filter(p=>!seen.has(p.id)));
      $('#photos-only').disabled=r.photos_supported===false;
      if(r.photos_applied===false){browse.photos=false;$('#photos-only').checked=false;}
      const next=r.cursor||'';more=!!next&&next!==cursor;cursor=next;sourceInfo(r);
      busy=false;render();$('#feed-status').textContent='';
      if(!posts.length&&r.people?.length)empty('Start with someone interesting.','Open a profile above to browse their public posts.','',()=>{});
      else if(!posts.length&&r.needs_profile)empty('Open a window into this server.','Paste a public profile, community, channel, collection or post URL in the browser above.','Enter a public URL',()=>{$('#browse-q').focus();$('#browse-q').select();});
      else if(!posts.length)empty('A quieter corner.','No matching public posts came back. Try switching off Photos only, changing the hashtag, or opening another page.',more?'Try the next page':'Try all posts',()=>{if(more)loadMore();else{$('#photos-only').checked=false;startBrowse(browse.q,false,browse.mode,browse.account||'',browse.family||'');}});
    } catch(e){if(version!==browseVersion)return;busy=false;render();$('#feed-status').textContent='';empty('This window is closed for now.',e.message,e.accessRequired?'Add read token':'Try again',()=>e.accessRequired?openPlace({domain:browse.q}):startBrowse(browse.q,false,browse.mode,browse.account||'',browse.family||''));toast(e.message,true);}
    finally{if(version===browseVersion){busy=false;$('#load-more').disabled=false;}}
  }
  async function loadMore() {
    if(busy)return;if(state.view==='feed')return refreshMaster(true);if(state.view==='explore'){return startBrowse(browse.q,true);}
    busy=true;$('#load-more').disabled=true;
    try{const r=await api(`/api/feed?kind=${state.view}&offset=${posts.length}`);posts.push(...r.posts);more=r.posts.length===24;render();}catch(e){toast(e.message,true);}finally{busy=false;$('#load-more').disabled=false;}
  }
  $('#load-more')?.addEventListener('click',loadMore);
  $('#browse-form')?.addEventListener('submit',e=>{e.preventDefault();startBrowse($('#browse-q').value);});
  ['#photos-only','#local-only'].forEach(s=>$(s)?.addEventListener('change',()=>{if(browse)startBrowse(browse.q,false,browse.mode);}));
  $('#browse-tag')?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();if(browse)startBrowse(browse.q,false,browse.mode);}});
  function releasePreviews(){objectURLs.forEach(URL.revokeObjectURL);objectURLs=[];}
  function previews() {
    const alts=$$('#photo-previews input').map(i=>i.value);releasePreviews();
    $('#photo-previews').innerHTML=files.map((f,i)=>{const url=URL.createObjectURL(f);objectURLs.push(url);return `<div class="photo-preview"><img src="${url}" alt="Photo ${i+1} preview"><input name="alt[]" aria-label="Description for photo ${i+1}" placeholder="Describe this photo…" maxlength="1000" value="${esc(alts[i]||'')}"><button type="button" data-remove-photo="${i}">Remove</button></div>`;}).join('');
    $$('[data-remove-photo]').forEach(b=>b.onclick=()=>{const i=Number(b.dataset.removePhoto);files.splice(i,1);$$('#photo-previews input')[i]?.closest('.photo-preview').remove();previews();});
  }
  function openComposer(p=null,reply=null) {
    if(!state.owner){location.href=route('/login');return;}
    const form=$('#compose-form');form.reset();files=[];previews();form.elements.edit.value=p?.id||'';form.elements.reply_to.value=reply?.id||'';
    $('#composer h2').textContent=p?'Edit this moment.':reply?'A little conversation.':'A new moment.';
    $('#upload-zone').hidden=!!p||!!reply;$('#caption').value=p?.caption||'';
    form.elements.sensitive.checked=!!p?.sensitive;form.elements.summary.value=p?.summary||'';
    form.querySelector('[type=submit]').textContent=p?'Save changes ↗':reply?'Publish reply ↗':'Publish moment ↗';$('#composer').showModal();
    if(p||reply)$('#caption').focus();
  }
  $$('.compose-open').forEach(b=>b.onclick=()=>openComposer());
  $('#photo-input').addEventListener('change',e=>{
    const incoming=[...e.target.files];if(files.length+incoming.length>10){toast('Choose up to 10 photos.',true);return;}
    if(incoming.some(f=>f.size>15*1024*1024)){toast('Each photo must be under 15 MB.',true);return;}
    files.push(...incoming);previews();e.target.value='';
  });
  $$('.close-dialog').forEach(b=>b.onclick=()=>b.closest('dialog').close());
  $('#composer').addEventListener('close',()=>releasePreviews());
  $('#compose-form').addEventListener('submit',e=>{e.preventDefault();run(async()=>{
    if(!e.target.elements.edit.value&&!e.target.elements.reply_to.value&&!files.length)throw new Error('Choose a photo first.');
    const body=new FormData(e.target);body.delete('photos[]');files.forEach(f=>body.append('photos[]',f));
    await api('/api/post',body);$('#composer').close();toast('Your moment is published.');location.href=route('/');
  },e.submitter);});
  function openViewer(p,i=0){viewerPost=p;viewerIndex=i;updateViewer();$('#viewer').showModal();}
  function updateViewer(){const photo=viewerPost.photos[viewerIndex];$('#viewer-image').src=safeURL(photo.url);$('#viewer-image').alt=photo.alt||'';$('#viewer-caption').textContent=`${viewerIndex+1} / ${viewerPost.photos.length} · ${photo.alt||viewerPost.content}`;$('.photo-prev').hidden=$('.photo-next').hidden=viewerPost.photos.length<2;}
  $('.viewer-close').onclick=()=>$('#viewer').close();$('.photo-prev').onclick=()=>{viewerIndex=(viewerIndex-1+viewerPost.photos.length)%viewerPost.photos.length;updateViewer();};$('.photo-next').onclick=()=>{viewerIndex=(viewerIndex+1)%viewerPost.photos.length;updateViewer();};
  document.addEventListener('keydown',e=>{if($('#viewer').open){if(e.key==='ArrowRight')$('.photo-next').click();if(e.key==='ArrowLeft')$('.photo-prev').click();}});
  const confirmDelete = p => new Promise(resolve=>{const d=$('#confirm-dialog');$('#confirm-title').textContent='Let this moment go?';$('#confirm-body').textContent='This removes the post from your journal and asks followers’ servers to remove their copies.';d.addEventListener('close',()=>resolve(d.returnValue==='confirm'),{once:true});d.showModal();});
  $('#feed')?.addEventListener('click',e=>{
    const b=e.target.closest('button');if(!b)return;const p=posts[Number(b.dataset.index)];if(!p)return;
    if(b.matches('.photo-button'))openViewer(p);
    if(b.matches('.reveal')){p.revealed=true;render();}
    if(b.matches('.post-person'))location.href=route('/explore?q='+encodeURIComponent(p.actor));
    if(b.matches('.edit'))openComposer(p);
    if(b.matches('.reply'))openComposer(null,p);
    if(b.matches('.save,.like'))run(async()=>{const action=b.matches('.save')?'save':'like';const r=await api('/api/'+action,{id:p.id});if(action==='save')p.saved=r.saved;else p.liked=r.liked;toast(action==='save'?(p.saved?'Saved for another day.':'Removed from saved.'):(p.liked?'A little appreciation, delivered.':'Like removed.'));if(state.view==='saved'&&!p.saved)posts=posts.filter(x=>x.id!==p.id);render();},b);
    if(b.matches('.delete'))run(async()=>{if(await confirmDelete(p)){await api('/api/delete',{id:p.id});posts=posts.filter(x=>x.id!==p.id);render();toast('Moment removed.');}},b);
    if(b.matches('.comments'))run(async()=>{const article=b.closest('article'),old=$('.comments-panel',article);if(old){old.remove();return;}const r=await api('/api/comments?id='+encodeURIComponent(p.id));const panel=document.createElement('section');panel.className='comments-panel';panel.innerHTML=r.comments.length?r.comments.map(c=>`<div class="comment"><strong>${esc(c.name)}</strong><p>${esc(c.content)}</p></div>`).join(''):'<p class="muted">No public replies yet.</p>';article.append(panel);},b);
  });
  $$('.handle-copy').forEach(b=>b.onclick=()=>run(async()=>{await navigator.clipboard.writeText(state.handle);toast('Your federation address is copied.');},b));
  function openPlace(server={}) {const f=$('#place-form');f.reset();f.elements.domain.value=server.domain||'';$('#remove-place').hidden=!state.servers.some(s=>s.domain===server.domain);$('#place-dialog').showModal();}
  $('#add-place')?.addEventListener('click',()=>openPlace());
  $('#place-form').addEventListener('submit',e=>{e.preventDefault();run(async()=>{const body=Object.fromEntries(new FormData(e.target));await api('/api/server',body);$('#place-dialog').close();toast('Your place is saved.');location.reload();},e.submitter);});
  $('#remove-place').onclick=()=>run(async()=>{await api('/api/server',{domain:$('#place-form').elements.domain.value,remove:true});location.reload();},$('#remove-place'));
  $('#login-form')?.addEventListener('submit',e=>{e.preventDefault();run(async()=>{await api('/login',{password:e.target.elements.password.value});location.href=route('/feed');},e.submitter);});
  $('#logout')?.addEventListener('click',()=>run(async()=>{await api('/logout',{});sessionStorage.clear();location.href=route('/');}));
  $('#profile-form')?.addEventListener('submit',e=>{e.preventDefault();run(async()=>{await api('/api/profile',new FormData(e.target));toast('Your profile is updated.');location.reload();},e.submitter);});
  let links = (state.links||[]).map(item=>({...item}));
  function collectLinks() {
    links=$$('.link-editor-row').map(row=>({label:$('[data-link-label]',row).value,url:$('[data-link-url]',row).value,enabled:$('[data-link-enabled]',row).checked}));
    return links;
  }
  function linksChanged() {$('#links-save-status').textContent='Unsaved changes';}
  function renderLinks() {
    const editor=$('#links-editor');if(!editor)return;
    editor.innerHTML=links.length?links.map((item,i)=>`<div class="link-editor-row" data-link-index="${i}"><span class="link-position" aria-hidden="true">${String(i+1).padStart(2,'0')}</span><div class="link-fields"><label>Title<input data-link-label maxlength="80" value="${esc(item.label)}" placeholder="My music, shop, or next event" required></label><label>Address<input data-link-url type="text" inputmode="url" maxlength="2048" value="${esc(item.url)}" placeholder="https://…" autocomplete="off" spellcheck="false" required></label></div><div class="link-row-tools"><label class="link-visibility"><input data-link-enabled type="checkbox" ${item.enabled?'checked':''}> Visible</label><div class="link-order"><button class="icon-button" type="button" data-link-action="up" aria-label="Move link ${i+1} up" ${i===0?'disabled':''}>↑</button><button class="icon-button" type="button" data-link-action="down" aria-label="Move link ${i+1} down" ${i===links.length-1?'disabled':''}>↓</button><button class="text-link" type="button" data-link-action="remove" aria-label="Remove link ${i+1}">Remove</button></div></div></div>`).join(''):'<p class="links-editor-empty muted">Your links will live here. Start with something you want people to find.</p>';
    $('#add-link').disabled=links.length>=30;
  }
  $('#links-editor')?.addEventListener('input',linksChanged);
  $('#links-editor')?.addEventListener('change',linksChanged);
  $('#links-editor')?.addEventListener('click',e=>{
    const button=e.target.closest('[data-link-action]');if(!button)return;
    const i=Number(button.closest('[data-link-index]').dataset.linkIndex),action=button.dataset.linkAction;
    collectLinks();let next=i;
    if(action==='remove')links.splice(i,1);
    else {next=i+(action==='up'?-1:1);if(next<0||next>=links.length)return;[links[i],links[next]]=[links[next],links[i]];}
    renderLinks();linksChanged();
    if(action==='remove'){const row=$$('.link-editor-row')[Math.min(i,links.length-1)];(row?$('[data-link-label]',row):$('#add-link')).focus();}
    else $$('.link-editor-row')[next]?.querySelector(`[data-link-action="${action}"]`)?.focus();
  });
  $('#add-link')?.addEventListener('click',()=>{collectLinks();if(links.length>=30)return;links.push({label:'',url:'',enabled:true});renderLinks();linksChanged();$$('[data-link-label]').at(-1).focus();});
  $('#links-form')?.addEventListener('submit',e=>{e.preventDefault();run(async()=>{const r=await api('/api/links',{links:collectLinks()});links=r.links;renderLinks();$('#links-save-status').textContent='Saved';toast('Your links are live above the feed.');},e.submitter);});
  renderLinks();
  let masterMode='mix', masterPhotos=true, discoverySnapshot=state.discovery||null, discoveryTimer, masterPending=false;
  async function refreshMaster(append=false) {
    if(busy){if(!append)masterPending=true;return;}busy=true;
    const button=$('#master-refresh');if(button)button.disabled=true;
    try {
      const params=new URLSearchParams({mode:masterMode,photos:masterPhotos?'1':'0',cursor:append?cursor:''});
      const r=await api('/api/master-feed?'+params);
      if(append){const seen=new Set(posts.map(p=>p.id));posts.push(...r.posts.filter(p=>!seen.has(p.id)));}else posts=r.posts;
      cursor=r.cursor||'';more=!!cursor;render();
      if(button)button.textContent='Fresh mix ↻';
    }catch(e){toast(e.message,true);}finally{busy=false;if(button)button.disabled=false;empty();if(masterPending){masterPending=false;refreshMaster();}}
  }
  function renderDiscovery(r) {
    if(!$('#discovery-panel'))return;
    const stale=r.enabled&&(!r.worker?.last_run||Date.now()/1000-r.worker.last_run>180),active=r.enabled&&!stale&&r.worker?.active;
    $('#discovery-status').textContent=!r.enabled?'Crawler paused':active?'Visiting '+r.worker.active:stale?'Waiting for your cron worker':'The flock is roaming';
    $('#discovery-signal').classList.toggle('stale',stale);
    $('#discovery-detail').textContent=!r.enabled?'Your collected feed is still here. Resume whenever you like.':r.worker?.last_run?'Last check '+new Date(r.worker.last_run*1000).toLocaleTimeString()+' · '+r.due+' tasks ready':'Your minute cron will pick up these sources. No browser tab needs to stay open.';
    $('#discovery-toggle').textContent=r.enabled?'Pause crawler':'Resume crawler';
    $('#discovery-counts').innerHTML=[[r.servers,'servers found'],[r.posts,'collected moments'],[r.watching,'profiles watched'],[(r.states?.online||0)+(r.states?.['outbox-online']||0),'live feeds'],[r.due,'tasks ready']].map(([n,label])=>`<div><b>${Number(n).toLocaleString()}</b><small>${label}</small></div>`).join('');
    $('#crawl-servers').innerHTML=r.hosts.length?r.hosts.slice(0,12).map(h=>`<div class="crawl-row"><strong>${esc(h.domain)}</strong><small>${esc(h.health||'queued')}${h.software?' · '+esc(h.software):''} · ${(h.roster||[]).length} sampled profiles${h.last_poll?' · last feed '+esc(new Date(h.last_poll*1000).toLocaleTimeString()):''}</small>${h.last_error?`<small>${esc(h.last_error)}</small>`:''}</div>`).join(''):'<p class="muted">Sources will appear here as discovery begins.</p>';
    $('#crawl-restrictions').innerHTML=r.restrictions.length?r.restrictions.map(h=>`<div class="crawl-row"><strong>${esc(h.domain)}</strong><small>${esc(h.state)}${h.severity?' · '+esc(h.severity):''} · checked ${esc(new Date(h.checked*1000).toLocaleString())}</small>${h.confirmed?`<small>Last confirmed ${esc(new Date(h.confirmed*1000).toLocaleString())}</small>`:''}${h.comment?`<small>${esc(h.comment)}</small>`:''}${h.error?`<small>${esc(h.error)}</small>`:''}<a class="text-link" target="_blank" rel="noopener noreferrer" href="${esc(safeURL(h.url)||'#')}">Published evidence ↗</a> <button type="button" class="text-link crawl-recheck" data-domain="${esc(h.domain)}">Recheck</button></div>`).join(''):'<p class="muted">No confirmed published restrictions recorded. Unavailable and private lists remain unknown.</p>';
    $$('.crawl-recheck').forEach(b=>b.onclick=()=>run(async()=>renderDiscovery(await api('/api/discovery',{action:'recheck',value:b.dataset.domain})),b));
    $('#crawl-events').innerHTML=r.events.slice(0,20).map(e=>`<li><time>${esc(new Date(e.at*1000).toLocaleTimeString())}</time>${esc(e.message)}</li>`).join('');
    const points=r.history||[],svg=$('#crawl-chart');
    if(points.length){const min=Math.min(...points.map(p=>p.posts)),max=Math.max(min+1,...points.map(p=>p.posts));const chart=points.map((p,i)=>`${8+i/Math.max(1,points.length-1)*624},${80-(p.posts-min)/(max-min)*66}`).join(' ');svg.innerHTML=`<line x1="8" y1="80" x2="632" y2="80"/><polyline points="${chart}"/>`;$('#crawl-chart-caption').textContent=`${points.length} recorded samples · ${points.at(-1).posts.toLocaleString()} moments currently cached`;}else{$('#crawl-chart-caption').textContent='The chart starts recording when your background worker checks in.';svg.innerHTML='';}
    if(discoverySnapshot&&r.posts>discoverySnapshot.posts){if(!posts.length)refreshMaster();else $('#master-refresh').textContent='New finds · refresh ↻';}
    discoverySnapshot=r;
  }
  async function discoveryPoll(){try{if(!document.hidden)renderDiscovery(await api('/api/discovery'));}catch(e){$('#discovery-detail').textContent=e.message;}finally{discoveryTimer=setTimeout(discoveryPoll,5000);}}
  $('#master-refresh')?.addEventListener('click',()=>refreshMaster());
  $$('.master-order').forEach(b=>b.onclick=()=>{masterMode=b.dataset.mode;$$('.master-order').forEach(x=>x.classList.toggle('active',x===b));refreshMaster();});
  $('#master-photos')?.addEventListener('change',e=>{masterPhotos=e.target.checked;refreshMaster();});
  $('#discovery-toggle')?.addEventListener('click',e=>run(async()=>renderDiscovery(await api('/api/discovery',{action:discoverySnapshot?.enabled?'pause':'start'})),e.currentTarget));
  $('#discovery-seed')?.addEventListener('submit',e=>{e.preventDefault();run(async()=>{const value=$('#discovery-source').value.trim();let action='seed';try{const u=new URL(value);if(u.pathname!=='/'&&u.pathname!=='')action='watch';}catch{}renderDiscovery(await api('/api/discovery',{action,value}));e.target.reset();toast('Source added to the background queue.');},e.submitter);});
  if(state.view==='feed'&&$('#discovery-panel')){renderDiscovery(state.discovery);discoveryTimer=setTimeout(discoveryPoll,5000);window.addEventListener('pagehide',()=>clearTimeout(discoveryTimer),{once:true});}
  $('#retry-jobs')?.addEventListener('click',e=>run(async()=>{await api('/api/retry',{});toast('Deliveries queued for the next worker run.');loadSettings();},e.currentTarget));
  function renderFederation(result) {
    const el=$('#federation-check-results');if(!el)return;
    el.innerHTML=result?`<p class="muted">Public endpoints checked ${esc(new Date(result.checked*1000).toLocaleString())}. ${result.ok?'All checks passed from your host.':'Some checks need attention.'}</p>${result.checks.map(c=>`<div class="federation-check"><strong>${c.ok?'✓':'!'} ${esc(c.name)}</strong><small>${esc(c.detail)}</small></div>`).join('')}`:'';
  }
  $('#check-federation')?.addEventListener('click',e=>run(async()=>{const el=$('#federation-check-results');el.textContent='Checking the public URLs other servers use…';renderFederation(await api('/api/federation-check',{}));},e.currentTarget));
  $('#resend-posts')?.addEventListener('click',e=>run(async()=>{const r=await api('/api/resend',{});toast(`${r.queued} recent posts queued for your followers.`);loadSettings();},e.currentTarget));
  $('#block-form')?.addEventListener('submit',e=>{e.preventDefault();run(async()=>{await api('/api/block',{domain:e.target.elements.domain.value});e.target.reset();toast('Server blocked.');loadSettings();},e.submitter);});
  async function loadSettings(){try{
    const r=await api('/api/settings');const last=r.worker?.last_run;const stale=!last||Date.now()/1000-last>180;
    $('#delivery-status').innerHTML=`<div class="status-line"><b>${stale?'◷':'✓'}</b> ${stale?'Waiting for your cron worker':'Delivery worker is checking in'}</div><div class="status-line">${r.jobs.pending||0} queued · ${r.jobs.failed||0} need attention</div>${r.followers?`<div class="status-line">${Number(r.followers)} ${Number(r.followers)===1?'follower':'followers'} receiving your posts</div>`:`<p class="muted">No followers yet. Search ${esc(state.handle)} in Mastodon and follow it to receive new photos. You can send your recent posts after following.</p>`}${r.last_delivery?`<p class="muted">Last inbox accepted a delivery: ${esc(r.last_delivery.server)} · ${esc(new Date(r.last_delivery.at*1000).toLocaleString())}</p>`:''}${r.failed.map(j=>`<p class="job-error">${esc(j.error)} · ${j.attempts} attempts</p>`).join('')}`;
    renderFederation(r.federation_check);
    if($('#resend-posts'))$('#resend-posts').disabled=!r.followers;
    $('#follow-list').innerHTML=r.follows.length?r.follows.map((f,i)=>`<div class="list-row"><div>${esc(f.name)}<small>${esc(f.state)}</small></div><button class="text-link unfollow" data-i="${i}">Unfollow</button></div>`).join(''):'<p class="muted">A good place to start: someone who makes you stop scrolling.</p>';
    $$('.unfollow').forEach(b=>b.onclick=()=>run(async()=>{await api('/api/unfollow',{id:r.follows[Number(b.dataset.i)].id});loadSettings();},b));
    $('#block-list').innerHTML=r.blocks.map((b,i)=>`<div class="list-row"><span>${esc(b.domain)}</span><button class="text-link unblock" data-i="${i}">Unblock</button></div>`).join('');
    $$('.unblock').forEach(b=>b.onclick=()=>run(async()=>{await api('/api/block',{domain:r.blocks[Number(b.dataset.i)].domain,remove:true});loadSettings();},b));
  }catch(e){toast(e.message,true);}}
  if(state.profile.avatar&&safeURL(state.profile.avatar))$('#rail-avatar').innerHTML=`<img src="${esc(safeURL(state.profile.avatar))}" alt="">`;
  renderPlaces();render();
  if(state.single){layout='reading';render();}
  if(state.view==='settings')loadSettings();
  if(state.view==='explore'){
    const params=new URLSearchParams(location.search),q=params.get('q'),account=params.get('account')||'',family=params.get('family')||'',saved=storage.get('browse-v2');
    if(saved&&(!q||q===saved.browse.q)&&account===(saved.browse.account||'')){browse=saved.browse;posts=saved.posts;cursor=saved.cursor;more=saved.more;$('#browse-q').value=browse.q;$('#browse-tag').value=browse.tag;$('#photos-only').checked=browse.photos;if($('#local-only'))$('#local-only').checked=browse.local;if(saved.source){try{sourceInfo(JSON.parse(saved.source));}catch{}}render();requestAnimationFrame(()=>window.scrollTo(0,saved.scroll||0));}
    else if(q)startBrowse(q,false,account?'account':null,account,family);
  }
  window.addEventListener('pagehide',persist);window.addEventListener('scroll',()=>{if(pendingReload)return;pendingReload=true;setTimeout(()=>{persist();pendingReload=false;},350);},{passive:true});
})();
