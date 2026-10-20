import {readFile} from 'node:fs/promises';
import {pathToFileURL} from 'node:url';
const {Window}=await import(process.argv[2]?pathToFileURL(process.argv[2]).href:'happy-dom');
const script=await readFile(new URL('../assets/theme.js',import.meta.url),'utf8');
const css=await readFile(new URL('../assets/app.css',import.meta.url),'utf8');
let count=0;
function check(ok,label){if(!ok)throw new Error(label);console.log(`ok ${++count} - ${label}`);}
function fixture({saved=null,dark=false,blocked=false}={}){
  const w=new Window({url:'https://photos.example',settings:{disableCSSFileLoading:true,disableJavaScriptFileLoading:true}});
  const changes=[];w.matchMedia=()=>({matches:dark,addEventListener:(_,fn)=>changes.push(fn)});
  w.document.head.innerHTML='<meta name="theme-color" content="#f8f7f3">';
  if(saved!==null)w.localStorage.setItem('quackpack:theme',saved);
  if(blocked)Object.defineProperty(w,'localStorage',{get(){throw new Error('Storage blocked');}});
  w.eval(script);
  const initial=w.document.documentElement.dataset.theme;
  w.document.body.innerHTML='<select id="theme-choice" aria-label="Color theme"><option value="system">Auto</option><option value="light">Light</option><option value="dark">Dark</option></select>';
  w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
  return {w,d:w.document,initial,changes};
}
let f=fixture({dark:true});
check(f.initial==='dark','system dark preference applies before the controls are parsed');
check(f.d.querySelector('meta[name="theme-color"]').content==='#171b18','browser chrome follows the active dark palette');
check(f.d.querySelector('#theme-choice').value==='system','Auto remains the default preference');
const picker=f.d.querySelector('#theme-choice');picker.value='light';picker.dispatchEvent(new f.w.Event('change'));
check(f.d.documentElement.dataset.theme==='light'&&f.w.localStorage.getItem('quackpack:theme')==='light','switching to Light persists beyond this page');
check(f.d.querySelector('meta[name="theme-color"]').content==='#f8f7f3','switching to Light updates browser chrome');
f.changes[0]();check(f.d.documentElement.dataset.theme==='light','system changes preserve an explicit Light preference');
picker.value='dark';picker.dispatchEvent(new f.w.Event('change'));
check(f.w.localStorage.getItem('quackpack:theme')==='dark','Dark selection is saved');
f.w.dispatchEvent(new f.w.StorageEvent('storage',{key:'quackpack:theme',newValue:'light'}));
check(f.d.documentElement.dataset.theme==='light'&&picker.value==='light','another tab can update the active theme and selector');
f.w.happyDOM.abort();
f=fixture({saved:'dark'});check(f.initial==='dark'&&f.d.querySelector('#theme-choice').value==='dark','a saved Dark selection beats a light operating system on reload');f.w.happyDOM.abort();
f=fixture({saved:'invalid',dark:true});check(f.initial==='dark','invalid stored values fall back to the system theme');f.w.happyDOM.abort();
f=fixture({blocked:true,dark:true});check(f.initial==='dark','theme selection still works when browser storage is blocked');
f.d.querySelector('#theme-choice').value='light';f.d.querySelector('#theme-choice').dispatchEvent(new f.w.Event('change'));
check(f.d.documentElement.dataset.theme==='light','a blocked storage write does not prevent switching themes');f.w.happyDOM.abort();

function palette(selector){const rule=css.slice(css.indexOf(selector)).match(/^[^{]*\{([^}]+)\}/)[1];return Object.fromEntries([...rule.matchAll(/(--[\w-]+):([^;]+)/g)].map(m=>[m[1],m[2]]));}
function luminance(hex){const rgb=(hex.length===4?[...hex.slice(1)].map(x=>x+x).join(''):hex.slice(1));return rgb.match(/../g).slice(0,3).map(x=>parseInt(x,16)/255).map(x=>x<=.04045?x/12.92:((x+.055)/1.055)**2.4).reduce((sum,x,i)=>sum+x*[.2126,.7152,.0722][i],0);}
function contrast(a,b){const x=luminance(a),y=luminance(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);}
for(const [name,selector] of [['Light',':root{'],['Dark',':root[data-theme="dark"]']]){
  const p=palette(selector);
  for(const [fg,bg] of [['--ink','--paper'],['--text','--paper'],['--muted','--paper'],['--text','--white'],['--sage','--white'],['--on-primary','--primary']]){
    check(contrast(p[fg],p[bg])>=4.5,`${name} ${fg}/${bg} text meets 4.5:1 contrast`);
  }
}
console.log(`PASS: ${count} theme preference and contrast assertions`);
