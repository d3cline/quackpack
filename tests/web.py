#!/usr/bin/env python3
"""Exercise CLI install and the real PHP request router without running a web server."""
import argparse
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

parser = argparse.ArgumentParser()
parser.add_argument('--php', default='php')
args = parser.parse_args()
source = Path(__file__).resolve().parents[1]
count = 0

def check(ok, name):
    global count
    count += 1
    if not ok:
        raise AssertionError(name)
    print(f'ok {count} - {name}')

with tempfile.TemporaryDirectory(prefix='pup-web-') as directory:
    root=Path(directory)/'app'
    shutil.copytree(source,root,ignore=shutil.ignore_patterns('quackpack-config.php','__pycache__'))
    env = dict(os.environ, PUP_CONFIG=directory+'/config.php', PUP_PASSWORD='test-owner-password-123')
    # Do not inherit a production DSN into the temporary integration test.
    for key in ('PUP_DSN','PUP_DB_USER','PUP_DB_PASSWORD'):
        env.pop(key, None)

    def php(argv, data=None):
        return subprocess.run([args.php, *argv], input=data, text=True, capture_output=True, cwd=root, env=env)

    install_args = ['bin/pup','install','--url=https://photos.example','--username=alice','--name=Alice','--data-dir='+directory+'/data','--docroot='+directory+'/www']
    r = php(install_args)
    check(r.returncode == 0, 'headless install succeeds: '+r.stderr.strip())
    config = Path(env['PUP_CONFIG'])
    check(config.stat().st_mode & 0o777 == 0o600, 'config is private')
    installed = json.loads(r.stdout)
    check(installed['address']=='@alice@photos.example' and 'password' not in r.stdout, 'installer returns identity without secrets')
    original = config.read_bytes()
    check(php(install_args).returncode!=0 and original==config.read_bytes(), 'reinstall refuses to replace identity')
    check(php(['bin/pup','work','--seconds=1']).returncode==0, 'cron command runs headlessly')
    doctor = php(['bin/pup','doctor'])
    check(doctor.returncode==0 and json.loads(doctor.stdout)['ok'], 'doctor accepts healthy configuration')

    def request(path='/', method='GET', **kw):
        r = php(['tests/request.php'], json.dumps(dict(path=path,method=method,**kw)))
        match=re.search(r'STATUS:(\d+)',r.stderr)
        if not match: raise RuntimeError(r.stderr+'\n'+r.stdout)
        if 'Warning:' in r.stdout or 'Fatal error:' in r.stdout: raise RuntimeError(r.stdout+r.stderr)
        return int(match.group(1)), r.stdout

    status, html = request()
    check(status==200 and 'Alice' in html, 'public journal renders')
    check('name="password"' not in html and 'Create account' not in html, 'public journal has no registration flow')
    status, body = request('/.well-known/webfinger',get={'resource':'acct:alice@photos.example'})
    check(status==200 and json.loads(body)['links'][0]['href']==installed['actor'], 'WebFinger resolves sole actor')
    check(request('/.well-known/webfinger',get={'resource':'acct:someone@photos.example'})[0]==404, 'other usernames are absent')
    check(json.loads(request('/users/alice')[1])['publicKey']['owner']==installed['actor'], 'actor exposes bound public key')
    check(json.loads(request('/nodeinfo/2.0')[1])['openRegistrations'] is False, 'registrations explicitly closed')
    check(request('/api/settings')[0]==401, 'settings API requires owner')
    check(request('/api/discovery')[0]==401 and request('/api/master-feed')[0]==401, 'crawler status and master feed are owner-only')
    check(request('/feed')[0]==302, 'master feed page redirects visitors to owner login')
    check(request('/api/discovery','POST',post={'action':'start'})[0]==401, 'visitors cannot start background discovery')
    check(request('/api/browse',get={'q':'remote.example'})[0]==401, 'remote fetch requires owner')
    check(request('/api/feed',get={'kind':'saved'})[0]==401, 'saved collection is private')
    check(request('/api/feed',get={'kind':'following'})[0]==401, 'following collection is private')
    check(request('/api/feed',get={'kind':'journal'})[0]==200, 'public journal can paginate without login')
    check(request('/api/delete','POST',post={'id':'anything'})[0]==401, 'unauthenticated mutations rejected')
    check(request('/api/links','POST',post={'links':[]})[0]==401, 'public visitors cannot manage links')
    check(request('/login','POST',post={'password':'wrong'})[0]==403, 'login requires CSRF')

    # Create a session through the same PHP session handler, then pass its browser cookie.
    session_code = '''require "app/bootstrap.php"; $a=pup(); session_save_path($a->config['data_dir'].'/sessions'); session_name('pup_owner'); session_id('pup-integration-session-123'); session_start(); $_SESSION=['owner'=>hash('sha256',$a->config['password_hash']),'csrf'=>'test-csrf-token']; session_write_close();'''
    check(php(['-r',session_code]).returncode==0, 'owner session fixture created')
    owner={'cookies':{'pup_owner':'pup-integration-session-123'}}
    status, body = request('/api/settings',**owner)
    check(status==200 and 'jobs' in json.loads(body), 'owner can read settings')
    check(request('/api/profile','POST',post={'name':'Alice'},**owner)[0]==403, 'owner mutation also requires CSRF')
    check(request('/api/links','POST',post={'links':[]},**owner)[0]==403, 'link changes require CSRF')
    check(request('/api/discovery','POST',post={'action':'start'},**owner)[0]==403, 'crawler controls require CSRF')
    status,feed_html=request('/feed',**owner)
    check(status==200 and 'id="discovery-panel"' in feed_html and 'The whole flock' in feed_html, 'owner master feed renders real background-status surface')
    check('This server’s people' not in request('/explore',**owner)[1], 'single-owner browser removes ambiguous server toggle')
    check(request('/api/discovery',**owner)[0]==200 and request('/api/master-feed',**owner)[0]==200, 'owner can read cached feed and crawler state without remote fetches')
    check(request('/api/discovery','POST',post={'action':'pause'},csrf='test-csrf-token',**owner)[0]==200, 'owner can pause crawler with CSRF')
    csrf={'csrf':'test-csrf-token',**owner}
    links=[{'label':'My music <b>live</b>','url':'https://music.example/?a=1&b=2','enabled':True},
           {'label':'Hidden draft','url':'https://hidden.example/private-draft','enabled':False},
           {'label':'My shop','url':'https://shop.example/','enabled':True}]
    status,body=request('/api/links','POST',post={'links':links},**csrf)
    check(status==200 and json.loads(body)['links']==links, 'owner can save ordered links and visibility')
    public=request()[1]
    check('class="profile-links"' in public and public.index('class="profile-links"')<public.index('class="feed-toolbar"')<public.index('id="feed"'), 'public links appear above photo feed')
    check('My music &lt;b&gt;live&lt;/b&gt;' in public and 'href="https://music.example/?a=1&amp;b=2"' in public, 'public link titles and addresses are escaped')
    check('Hidden draft' not in public and 'hidden.example' not in public, 'hidden links absent from all public HTML and page data')
    check(public.index('class="profile-link"')<public.index('My shop'), 'link display preserves saved order')
    check('id="links-form"' in request('/settings',**owner)[1] and 'Hidden draft' in request('/settings',**owner)[1], 'owner editor includes hidden links')
    reordered=[links[2],dict(links[0],label='Music, updated')]
    status,_=request('/api/links','POST',post={'links':reordered},**csrf)
    public=request()[1]
    check(status==200 and public.index('My shop')<public.index('Music, updated') and 'Hidden draft' not in public, 'edit, reorder and removal persist together')
    for bad in ['javascript:alert(1)','data:text/html,bad','file:///etc/passwd','https://user:password@shop.example/','https://shop.example/\\bad']:
        status,_=request('/api/links','POST',post={'links':[{'label':'Unsafe','url':bad}]},**csrf)
        check(status==400, 'unsafe link address rejected: '+bad)
    check('Music, updated' in request()[1], 'invalid link save preserves previous list')
    check(request('/api/links','POST',post={'links':[{'label':'','url':'https://shop.example/'}]},**csrf)[0]==400, 'blank link label rejected')
    check(request('/api/links','POST',post={'links':[{'label':'x'*81,'url':'https://shop.example/'}]},**csrf)[0]==400, 'link label length enforced')
    check(request('/api/links','POST',post={'links':[links[0]]*31},**csrf)[0]==400, 'link list size enforced')
    check(request('/api/links','POST',post={'links':[{'label':'Visible','url':'https://shop.example/','enabled':'false'}]},**csrf)[0]==400, 'visibility requires an actual boolean')
    contacts=[{'label':'Email','url':'mailto:hello@example.com','enabled':True},{'label':'Call','url':'tel:+16195550100','enabled':True}]
    check(request('/api/links','POST',post={'links':contacts},**csrf)[0]==200, 'email and telephone links accepted')
    content_export=php(['bin/pup','export'])
    check(json.loads(content_export.stdout)['links']==contacts, 'links included in content export')
    check(request('/api/links','POST',post={'links':[]},**csrf)[0]==200 and 'class="profile-links"' not in request()[1], 'removing all links removes public section')
    status, body = request('/api/profile','POST',post={'name':'New <script>name</script>','bio':'A private place.'},csrf='test-csrf-token',**owner)
    check(status==200 and json.loads(body)['ok'], 'owner profile mutation with CSRF succeeds')
    check('<h1>New <script>name</script>' not in request()[1], 'profile HTML is escaped')
    check(request('/api/server','POST',post={'domain':'bad','token':'secret'},csrf='test-csrf-token',**owner)[0]==400, 'malformed server rejected')
    status,_=request('/api/server','POST',post={'domain':'remote.example','token':'never-show-this-token'},csrf='test-csrf-token',**owner)
    check(status==200 and 'never-show-this-token' not in request('/explore',**owner)[1], 'server token never reaches page state')
    public_html=request()[1]
    public_state=json.loads(re.search(r'id="pup-state">(.*?)</script>',public_html,re.S).group(1))
    check(public_state['owner'] is False and public_state['servers']==[] and 'remote.example' not in public_html, 'public journal omits saved browsing destinations')
    check('never-show-this-token' not in public_html, 'public journal omits saved API tokens')
    owner_html=request('/explore',**owner)[1]
    owner_state=json.loads(re.search(r'id="pup-state">(.*?)</script>',owner_html,re.S).group(1))
    check(owner_state['owner'] and any(s['domain']=='remote.example' for s in owner_state['servers']), 'owner retains saved browsing destinations')
    check('never-show-this-token' not in owner_html, 'owner page also omits saved API tokens')
    check(request('/var/config.php')[0]==404 and request('/app/bootstrap.php')[0]==404, 'front controller cannot serve private files')
    check(request('/api/post','POST',post={'caption':'no photos'},csrf='test-csrf-token',**owner)[0]==400, 'publish validates required media')
    status,body=request('/api/post','POST',post={'caption':'Text reply','reply_to':'https://remote.example/p/123'},**csrf)
    check(status==400 and 'photo' in json.loads(body)['error'], 'reply API requires a photo before resolving a remote target')
    # A password change invalidates the existing owner session.
    env['PUP_PASSWORD']='different-test-password-123'
    check(php(['bin/pup','password']).returncode==0, 'headless password reset succeeds')
    check(request('/api/settings',**owner)[0]==401, 'old owner session revoked after password change')
    export=php(['bin/pup','export'])
    check(export.returncode==0 and 'private_key' not in export.stdout and 'never-show-this-token' not in export.stdout, 'content export excludes secrets')

print(f'PASS: {count} CLI and web contract assertions')
