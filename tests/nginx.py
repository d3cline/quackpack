#!/usr/bin/env python3
"""Real plain Nginx + PHP FastCGI test: no rewrites or application deny rules."""
import argparse
import base64
import grp
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

p=argparse.ArgumentParser()
p.add_argument('--nginx',default='nginx')
p.add_argument('--php',default='php')
p.add_argument('--php-cgi',default='php-cgi')
p.add_argument('--fastcgi-params',default='/etc/nginx/fastcgi_params')
a=p.parse_args()
source=Path(__file__).resolve().parents[1]
checks=0

def check(ok,name):
    global checks
    checks+=1
    if not ok:raise AssertionError(name)
    print(f'ok {checks} - {name}',flush=True)

def port():
    with socket.socket() as s:
        s.bind(('127.0.0.1',0))
        return s.getsockname()[1]

def local_path(url):
    u=urllib.parse.urlsplit(url)
    return u.path+('?' + u.query if u.query else '')

def route(path,**query):
    return '/index.php?'+urllib.parse.urlencode({'route':path,**query})

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs):return None

with tempfile.TemporaryDirectory(prefix='quackpack-managed-') as directory:
    base=Path(directory)
    account=base/'home'/'alice'
    root=account/'apps'/'quackpack'
    shutil.copytree(source,root,ignore=shutil.ignore_patterns('quackpack-config.php','__pycache__'))
    env=dict(os.environ)
    for k in ('PUP_CONFIG','PUP_DSN','PUP_DB_USER','PUP_DB_PASSWORD','PUP_PASSWORD'):env.pop(k,None)
    # A disposable login environment models the owning shell account's real HOME.
    install_env=dict(env,HOME=str(account),PUP_PASSWORD='integration-test-password')
    install=subprocess.run([a.php,str(root/'bin/quackpack'),'install','--url=https://photos.example','--username=alice'],env=install_env,capture_output=True,text=True)
    check(install.returncode==0,'default headless install: '+install.stderr.strip())
    result=json.loads(install.stdout)
    private=account/'quackpack'
    runtime=private/'runtime'
    check(result['document_root']==str(root) and result['private_directory']==str(private),'docroot is app directory; private files default to HOME/quackpack')
    check((private/'config.php').is_file() and (private/'pup.sqlite').is_file(),'configuration and SQLite are outside apps')
    check((private.stat().st_mode & 0o777)==0o700 and ((private/'config.php').stat().st_mode & 0o777)==0o600,'private directory and config permissions')
    check({f.name for f in root.iterdir()}=={'index.php','assets','.well-known','.user.ini'},'only public entrypoints, assets and PHP settings remain in docroot')
    check((runtime/'app/bootstrap.php').is_file() and (runtime/'bin/quackpack').is_file(),'runtime and worker relocated under HOME')
    entry=(root/'index.php').read_text()
    check(str(runtime/'app/bootstrap.php') in entry and 'PRIVATE KEY' not in entry and 'password_hash' not in entry,'public bootstrap contains paths without credentials')
    check(not list(root.rglob('.htaccess')) and not list(runtime.rglob('.htaccess')),'no .htaccess files')
    # Neither PHP-FPM nor cron needs to inherit HOME or any PUP_* variable.
    clean_env=dict(env)
    clean_env.pop('HOME',None)
    def cli(arguments,code=None):
        cmd=[a.php,'-r',code] if code is not None else [a.php,str(runtime/'bin/quackpack'),*arguments]
        return subprocess.run(cmd,env=clean_env,cwd=runtime,capture_output=True,text=True)
    check(cli(['work','--seconds=1']).returncode==0,'private cron command works without HOME or PUP_CONFIG')
    doctor=cli(['doctor'])
    check(doctor.returncode==0 and json.loads(doctor.stdout)['ok'],'private doctor uses persisted config location')
    original=(private/'config.php').read_bytes()
    check(cli(['install','--url=https://photos.example','--username=alice']).returncode!=0 and (private/'config.php').read_bytes()==original,'reinstall preserves identity')

    invalid_root=base/'invalid-app'
    shutil.copytree(source,invalid_root,ignore=shutil.ignore_patterns('quackpack-config.php','__pycache__'))
    bad_env=dict(install_env,HOME=str(base/'invalid-home'))
    bad_command=[a.php,str(invalid_root/'bin/quackpack'),'install','--url=https://photos.example','--username=alice']
    rejected=subprocess.run(bad_command+['--data-dir='+str(invalid_root/'var')],env=bad_env,capture_output=True,text=True)
    check(rejected.returncode!=0 and 'outside' in rejected.stderr,'reject data inside docroot')
    rejected=subprocess.run(bad_command,env=dict(bad_env,PUP_CONFIG=str(invalid_root/'secret.php')),capture_output=True,text=True)
    check(rejected.returncode!=0 and 'outside' in rejected.stderr,'reject config inside docroot')
    rejected=subprocess.run(bad_command,env=dict(bad_env,PUP_DSN='sqlite:'+str(invalid_root/'secret.sqlite')),capture_output=True,text=True)
    check(rejected.returncode!=0 and 'outside' in rejected.stderr,'reject SQLite inside docroot')
    (base/'misleading-private').symlink_to(invalid_root/'var',target_is_directory=True)
    rejected=subprocess.run(bad_command+['--data-dir='+str(base/'misleading-private')],env=bad_env,capture_output=True,text=True)
    check(rejected.returncode!=0 and 'outside' in rejected.stderr,'resolve private-directory symlinks before validating')

    wrapper_account=base/'wrapper-home'
    wrapper_root=wrapper_account/'apps'/'quackpack'
    shutil.copytree(source,wrapper_root,ignore=shutil.ignore_patterns('quackpack-config.php','__pycache__'))
    wrapped=subprocess.run(['bash',str(wrapper_root/'deploy/opalstack.sh')],env=dict(install_env,HOME=str(wrapper_account),PUP_URL='https://photos.example',PUP_USERNAME='alice',PUP_PHP=a.php),capture_output=True,text=True)
    check(wrapped.returncode==0,'Opalstack script completes after relocating its own source: '+wrapped.stderr.strip())
    check('"ok":true' in wrapped.stdout and not (wrapper_root/'bin').exists(),'script runs worker and doctor from private runtime')

    http_port,cgi_port=port(),port()
    conf=base/'nginx.conf'
    user=pwd.getpwuid(os.getuid()).pw_name
    group=grp.getgrgid(os.getgid()).gr_name
    # A generic PHP handler only. No front-controller fallback, rewrite or deny rule.
    conf.write_text(f'''user {user} {group};
worker_processes 1;
master_process off;
daemon off;
pid {base}/nginx.pid;
error_log {base}/error.log;
events {{ worker_connections 128; }}
http {{
 include {Path(a.fastcgi_params).with_name('mime.types')};
 access_log off;
 proxy_temp_path {base}/proxy;
 uwsgi_temp_path {base}/uwsgi;
 scgi_temp_path {base}/scgi;
 client_body_temp_path {base}/body;
 fastcgi_temp_path {base}/fastcgi;
 server {{
  listen 127.0.0.1:{http_port};
  root {root};
  index index.php index.html;
  location ~ \\.php$ {{
   try_files $uri =404;
   include {a.fastcgi_params};
   fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
   fastcgi_param REDIRECT_STATUS 200;
   fastcgi_pass 127.0.0.1:{cgi_port};
  }}
 }}
}}
''')
    lint=subprocess.run([a.nginx,'-p',str(base)+'/', '-c',str(conf),'-t'],env=env,capture_output=True,text=True)
    check(lint.returncode==0,'plain Nginx/PHP fixture passes nginx -t: '+lint.stderr.strip())
    cgi_log=(base/'cgi.log').open('w')
    nginx_log=(base/'nginx.log').open('w')
    cgi=subprocess.Popen([a.php_cgi,'-b',f'127.0.0.1:{cgi_port}'],env=dict(clean_env,PHP_FCGI_MAX_REQUESTS='1000'),stdout=cgi_log,stderr=cgi_log)
    nginx=None
    def request(path,method='GET',data=None,headers=None,follow=True):
        req=urllib.request.Request(f'http://127.0.0.1:{http_port}'+path,data=data,method=method,headers=headers or {})
        opener=urllib.request.build_opener() if follow else urllib.request.build_opener(NoRedirect())
        try:
            with opener.open(req,timeout=5) as response:return response.status,response.read(),response.headers
        except urllib.error.HTTPError as error:return error.code,error.read(),error.headers
    try:
        nginx=subprocess.Popen([a.nginx,'-p',str(base)+'/', '-c',str(conf)],env=clean_env,stdout=nginx_log,stderr=nginx_log)
        for _ in range(100):
            try:
                status,body,_=request(route('/health'))
                if status==200:break
            except (urllib.error.URLError,ConnectionError):pass
            if nginx.poll() is not None or cgi.poll() is not None:raise RuntimeError((base/'error.log').read_text()+(base/'cgi.log').read_text())
            time.sleep(.05)
        else:raise RuntimeError('Services did not become ready: '+(base/'error.log').read_text()+(base/'cgi.log').read_text())
        check(json.loads(body)['app']=='quackpack','PHP query routing works without rewrites')
        status,body,_=request('/')
        check(status==200 and b'quackpack' in body,'public landing page loads at domain root')
        check(b'href="/index.php?route=%2Flogin"' in body,'owner navigation uses an executable PHP URL')
        for path in ['/assets/app.css','/assets/app.js','/assets/quackpack-logo.png']:
            status,body,_=request(path)
            check(status==200 and body==(root/path.lstrip('/')).read_bytes(),'direct public asset: '+path)
        wf='/.well-known/webfinger?resource=acct%3Aalice%40photos.example'
        status,_,headers=request(wf,follow=False)
        check(status==301 and '/.well-known/webfinger/?resource=acct%3Aalice%40photos.example' in headers['Location'],'ordinary directory redirect preserves WebFinger query')
        status,body,headers=request(wf)
        check(status==200 and json.loads(body)['subject']=='acct:alice@photos.example' and headers['Content-Type'].startswith('application/jrd+json'),'WebFinger resolves after directory redirect with JRD content type')
        check(headers['Access-Control-Allow-Origin']=='*','WebFinger is available to cross-origin clients')
        check(request('/.well-known/webfinger?resource=acct%3Amissing%40photos.example')[0]==404,'WebFinger rejects other accounts')
        check(request('/.well-known/webfinger/?route=%2Fhealth&resource=acct%3Aalice%40photos.example')[0]==200,'discovery entrypoint has a fixed route')
        status,body,_=request('/.well-known/nodeinfo')
        info=json.loads(body)
        check(status==200 and request(local_path(info['links'][0]['href']))[0]==200,'NodeInfo discovery and document work without rewrites')
        status,body,_=request(local_path(result['actor']))
        actor=json.loads(body)
        check(status==200 and actor['type']=='Person' and actor['publicKey']['owner']==result['actor'],'published actor URL and key resolve')
        status,root_actor,root_headers=request('/',headers={'Accept':'application/activity+json'})
        check(status==200 and json.loads(root_actor)['id']==actor['id'] and 'Accept' in root_headers.get('Vary',''),'homepage negotiates the stable actor for remote profile discovery')
        check('Set-Cookie' not in root_headers and actor['id'] in root_headers.get('Link',''),'federation homepage is stateless and advertises its actor')
        html=request('/')[1]
        check(b'rel="alternate" type="application/activity+json"' in html and b'id="theme-choice"' in html,'public HTML advertises ActivityPub and includes the theme selector')
        check(request('/assets/theme.js')[0]==200,'early theme script is published in the managed docroot')
        status,body,_=request(local_path(actor['outbox']))
        outbox=json.loads(body)
        check(status==200 and request(local_path(outbox['first']))[0]==200,'outbox page query retains its route parameter')
        check(request(route('/api/settings'))[0]==401,'owner API requires authentication')
        status,_,headers=request(route('/settings'),follow=False)
        check(status==302 and headers['Location']==route('/login'),'owner pages redirect to executable login URL')
        check(request(local_path(actor['inbox']),'POST',b'{}')[0]==400,'inbox POST reaches signature validation without redirect')
        check(request(local_path(actor['inbox']),'POST',b'x'*1048577)[0]==413,'large inbox body rejected')
        for path in ['/app/bootstrap.php','/views/shell.php','/bin/quackpack','/tests/run.php','/deploy/nginx.conf','/docs/BRAND.md','/var/pup.sqlite','/quackpack-config.php','/config.php','/runtime/app/bootstrap.php','/composer.json','/README.md','/.env','/public/index.php']:
            check(request(path)[0]==404,'private path absent from public filesystem: '+path)
        check(request('/missing-photo.jpg')[0]==404,'missing static path is a real 404')
        check(request(route('/missing'))[0]==404,'unknown application route is a real 404')

        # Sign a real HTTP request to the published query-based inbox.
        fixture=cli([],code='require "app/bootstrap.php"; $a=pup(); $actor=$a->actor(); $actor["_fetched"]=time(); $a->store->put("actors",$actor["id"],$actor); $body=Pup\\App::json($a->fed->activity("Like","https://photos.example/fixture")); echo Pup\\App::json(["body"=>$body,"headers"=>$a->http->signatureHeaders("POST",$a->url("/inbox"),$body)]);')
        check(fixture.returncode==0,'signed inbox fixture created privately: '+fixture.stderr.strip())
        signed=json.loads(fixture.stdout)
        check(request(local_path(actor['inbox']),'POST',signed['body'].encode(),signed['headers'])[0]==202,'HTTP signature verifies actual encoded query request target')
        check(request('/index.php?route=/inbox','POST',signed['body'].encode(),signed['headers'])[0]==400,'changing query encoding invalidates the signed request target')

        # Exercise login, links and a real multipart photo through FastCGI.
        status,body,headers=request(route('/login'))
        state=json.loads(re.search(rb'<script type="application/json" id="pup-state">(.*?)</script>',body,re.S).group(1))
        cookie=headers['Set-Cookie'].split(';',1)[0]
        auth_headers={'Cookie':cookie,'Content-Type':'application/json','X-CSRF-Token':state['csrf']}
        status,body,headers=request(route('/login'),'POST',json.dumps({'password':'integration-test-password'}).encode(),auth_headers)
        check(status==200 and json.loads(body)['ok'],'owner login works over ordinary PHP route')
        auth_headers['Cookie']=headers['Set-Cookie'].split(';',1)[0]
        check(request(route('/api/discovery'))[0]==401 and request(route('/api/master-feed'))[0]==401,'discovery and mixed-feed APIs remain private through FastCGI')
        check(request(route('/api/federation-check'),'POST',b'{}')[0]==401 and request(route('/api/resend'),'POST',b'{}')[0]==401,'federation checks and resends require the owner')
        check(request(route('/api/resend'),'POST',b'{}',{'Cookie':auth_headers['Cookie'],'Content-Type':'application/json'})[0]==403,'journal resend requires a valid CSRF token')
        status,feed,_=request(route('/feed'),headers=auth_headers)
        check(status==200 and b'id="discovery-panel"' in feed,'master feed and status panel render through plain PHP routing')
        upload_state=json.loads(re.search(rb'<script type="application/json" id="pup-state">(.*?)</script>',feed,re.S).group(1))
        check(upload_state['uploads']['file_bytes']>0 and upload_state['uploads']['post_bytes']>0,'owner receives effective PHP upload limits for photo preparation')
        check(request('/assets/photos.js')[0]==200 and feed.index(b'/assets/photos.js?')<feed.index(b'/assets/app.js?'),'photo preparation is deployed and loaded before the app script')
        check(request(route('/api/discovery'),'POST',json.dumps({'action':'pause'}).encode(),auth_headers)[0]==200,'owner can pause the background crawler over real HTTP')
        links=[{'label':'My music','url':'https://music.example/','enabled':True}]
        check(request(route('/api/links'),'POST',json.dumps({'links':links}).encode(),auth_headers)[0]==200,'owner can manage link stack through query API')
        html=request('/')[1]
        check(b'My music' in html and html.index(b'class="profile-links"')<html.index(b'id="feed"'),'managed links appear above public feed')
        fixture=cli([],code='$im=imagecreatetruecolor(8,8);ob_start();imagepng($im);echo base64_encode(ob_get_clean());')
        image=base64.b64decode(fixture.stdout)
        boundary='quackpack-integration-boundary'
        upload=(f'--{boundary}\r\nContent-Disposition: form-data; name="caption"\r\n\r\nA managed-host photo\r\n--{boundary}\r\nContent-Disposition: form-data; name="photos[]"; filename="test.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+image+f'\r\n--{boundary}--\r\n'.encode())
        photo_headers=dict(auth_headers)
        photo_headers['Content-Type']='multipart/form-data; boundary='+boundary
        status,body,_=request(route('/api/post'),'POST',upload,photo_headers)
        check(status==200,'multipart photo publishing works: '+body.decode()[:200])
        post=json.loads(body)['post']
        status,photo,_=request(local_path(post['photos'][0]['url']))
        check(status==200 and photo.startswith(b'\xff\xd8'),'photo served through PHP from private HOME storage')
        status,body,_=request(local_path(post['id']),headers={'Accept':'application/activity+json'})
        check(status==200 and json.loads(body)['id']==post['id'],'published post permalink serves its ActivityPub object')
        status,body,_=request(local_path(outbox['first']))
        create=json.loads(body)['orderedItems'][0]
        check(create['object']['id']==post['id'] and request(local_path(create['id']))[0]==200,'outbox includes retrievable Create activity')
        check(request(route('/api/delete'),'POST',json.dumps({'id':post['id']}).encode(),auth_headers)[0]==200,'owner can delete a published photo')
        check(request(local_path(post['id']))[0]==410 and request(local_path(post['photos'][0]['url']))[0]==404,'delete produces tombstone and removes private image')
    finally:
        for process in (nginx,cgi):
            if process:
                process.terminate()
                try:process.wait(timeout=5)
                except subprocess.TimeoutExpired:process.kill();process.wait()
        cgi_log.close()
        nginx_log.close()
print(f'PASS: {checks} plain Nginx/FastCGI hosting assertions')
