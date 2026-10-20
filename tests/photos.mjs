// Deterministic upload preparation tests. Native iOS decoding still requires a device check.
import {readFile} from 'node:fs/promises';
import {File} from 'node:buffer';
import vm from 'node:vm';
const script=await readFile(new URL('../assets/photos.js',import.meta.url),'utf8');
let count=0;
function check(ok,label){if(!ok)throw new Error(label);console.log(`ok ${++count} - ${label}`);}
function fixture(options={}){
 const draws=[],revoked=[],sizes=[],canvas={width:0,height:0,getContext(){return options.noContext?null:{fillRect(){},drawImage(image,x,y,w,h){draws.push([w,h]);}};},toBlob(callback,type,quality){sizes.push(quality);callback(options.noBlob?null:new Blob([new Uint8Array(options.bytes?.(quality,this.width)??1000)],{type}));}};
 class Image {naturalWidth=options.width??8064;naturalHeight=options.height??6048;set src(value){if(value)queueMicrotask(()=>options.decodeError?this.onerror?.():this.onload?.());}}
 const context=vm.createContext({window:{},Image,File,document:{createElement(){return canvas;}},URL:{createObjectURL(){return 'blob:test';},revokeObjectURL(url){revoked.push(url);}},setTimeout,clearTimeout});
 vm.runInContext(script,context);return {photos:context.window.QuackpackPhotos,draws,revoked,canvas,sizes};
}
const phone=new File([new Uint8Array(20*1024*1024)],'IMG_1001.HEIC',{type:'image/heic',lastModified:123});
let f=fixture(),out=await f.photos.prepare(phone);
check(out.type==='image/jpeg'&&out.name==='IMG_1001.jpg','iPhone HEIC is exported as a JPEG upload');
check(f.draws[0][0]===2560&&f.draws[0][1]===1920,'48 MP original becomes a 2560-pixel photo before upload');
check(out.lastModified===123,'file modification time survives preparation');
check(f.revoked.length===1&&f.canvas.width===1&&f.canvas.height===1,'object URL and canvas backing store released after success');
f=fixture({width:600,height:800});await f.photos.prepare(new File(['png'],'portrait.png',{type:'image/png'}));
check(f.draws[0][0]===600&&f.draws[0][1]===800,'small portrait keeps dimensions without upscaling');
check(f.photos.budget({file_bytes:2*1024*1024,post_bytes:8*1024*1024},10)<820000,'ten-photo batch fits the total PHP request limit');
check(f.photos.budget({file_bytes:0,post_bytes:0})===1800000,'unlimited PHP settings retain a bounded upload size');
check(f.photos.budget({file_bytes:1024*1024})<1024*1024,'individual PHP upload limit respected');
f=fixture({bytes:(quality,width)=>width>1920?5000:1000});await f.photos.prepare(phone,2000);
check(f.draws.some(([w])=>w===1920),'incompressible photos shrink again to fit their byte budget');
async function rejects(file,options,pattern,label){const x=fixture(options);let error;try{await x.photos.prepare(file);}catch(e){error=e;}check(pattern.test(error?.message||''),label);return x;}
f=await rejects(phone,{decodeError:true},/HEIC.*Safari 17/,'unsupported HEIC decoder gives an actionable message');
check(f.revoked.length===1,'object URL released after decoding failure');
await rejects(new File(['bad'],'photo.jpg',{type:'image/jpeg'}),{decodeError:true},/could not be opened/,'corrupt JPEG is rejected');
await rejects(new File(['<svg>'],'image.svg',{type:'image/svg+xml'}),{},/Choose a JPEG/,'SVG is not accepted by the upload preparation path');
await rejects(new File([],'empty.jpg',{type:'image/jpeg'}),{},/empty/,'empty file rejected');
await rejects(phone,{noContext:true},/could not prepare/,'canvas allocation failure is reported');
await rejects(phone,{noBlob:true},/could not convert/,'JPEG encoding failure is reported');
await rejects(phone,{width:0},/dimensions/,'invalid dimensions rejected');
let error;try{f.photos.budget({post_bytes:10000});}catch(e){error=e;}
check(/limit is too small/.test(error?.message||''),'unusable server limits fail before uploading');
console.log(`PASS: ${count} photo preparation assertions (browser decoding simulated)`);
