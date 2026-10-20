#!/usr/bin/env python3
"""Regression checks for PHP dispatch, missing dotfiles and interrupted 0.3.1 setup."""
import argparse
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import zipfile

p=argparse.ArgumentParser()
p.add_argument('--php',default='php')
p.add_argument('--previous-release')
p.add_argument('--running-release')
a=p.parse_args()
source=Path(__file__).resolve().parents[1]
checks=0

def check(ok,label):
    global checks
    checks+=1
    if not ok:raise AssertionError(label)
    print(f'ok {checks} - {label}',flush=True)

with tempfile.TemporaryDirectory(prefix='quackpack-php-') as directory:
    base=Path(directory)
    env=dict(os.environ)
    for name in ('PUP_CONFIG','PUP_PASSWORD','PUP_DSN','PUP_DB_USER','PUP_DB_PASSWORD','PUP_PHP'):env.pop(name,None)
    def run(args,account,extra=None,cwd=None):
        return subprocess.run(args,env=dict(env,HOME=str(account),**(extra or {})),cwd=cwd,capture_output=True,text=True)
    def release(root):
        shutil.copytree(source,root,dirs_exist_ok=True,ignore=shutil.ignore_patterns('quackpack-config.php','__pycache__','.user.ini','.gitignore','.gitkeep'))
    fresh=base/'fresh'
    root=fresh/'apps/quackpack'
    release(root)
    check(not (root/'.user.ini').exists(),'release copied without dotfiles')
    r=run([a.php,str(root/'bin/quackpack'),'install','--url=https://photos.example','--username=alice'],fresh,{'PUP_PASSWORD':'test-install-password-123'})
    check(r.returncode==0,'missing .user.ini does not prevent installation: '+r.stderr)
    installed=json.loads(r.stdout)
    check((root/'.user.ini').is_file() and 'memory_limit = 256M' in (root/'.user.ini').read_text(),'default PHP settings generated in docroot')
    check((fresh/'quackpack/runtime/.user.ini').is_file(),'private runtime retains generated settings')
    check(installed['php_binary'] in installed['cron'] and installed['php_binary']!='php','cron pins the selected absolute PHP binary')
    check((Path(installed['cli']).stat().st_mode & 0o700)==0o700,'private CLI remains executable by owner')

    # Model the old-runtime branch with the actual supported interpreter.
    # PHP 7.x itself is not available in this build environment.
    dispatch=base/'dispatch with spaces'
    (dispatch/'bin').mkdir(parents=True)
    launcher=(source/'bin/quackpack').read_text()
    (dispatch/'bin/quackpack').write_text(launcher.replace('PHP_VERSION_ID < 80200','true /* simulated old default PHP */'))
    (dispatch/'bin/pup').write_text('<?php echo json_encode(array("args"=>array_slice($argv,1),"binary"=>PHP_BINARY));')
    binaries=base/'available-php'
    binaries.mkdir()
    (binaries/'php85').symlink_to(a.php)
    arguments=['install','--name=John Augustino Spounias','--literal=$(touch SHOULD_NOT_EXIST)','--quote=one\'two']
    r=run([a.php,str(dispatch/'bin/quackpack'),*arguments],base,{'PATH':str(binaries)},cwd=base)
    check(r.returncode==0,'old-runtime guard finds a supported versioned CLI: '+r.stderr)
    check(json.loads(r.stdout)['args']==arguments and not (base/'SHOULD_NOT_EXIST').exists(),'dispatch forwards spaces and shell syntax as literal arguments')
    selected=base/'selected PHP'
    selected.symlink_to(a.php)
    r=run([a.php,str(dispatch/'bin/quackpack'),'help'],base,{'PUP_PHP':str(selected)})
    check(r.returncode==0 and json.loads(r.stdout)['args']==['help'],'PUP_PHP accepts an explicit binary path with spaces')
    r=run([a.php,str(dispatch/'bin/quackpack'),'help'],base,{'PUP_PHP':str(base/'not-php')})
    check(r.returncode!=0 and 'PUP_PHP must name' in r.stderr,'invalid explicit runtime fails with a useful error')
    (binaries/'php85').unlink()
    r=run([a.php,str(dispatch/'bin/quackpack'),'help'],base,{'PATH':str(binaries)})
    check(r.returncode!=0 and 'No supported CLI PHP found' in r.stderr,'no supported PHP fails before loading application code')
    (binaries/'php84').symlink_to(a.php)
    r=run([a.php,str(dispatch/'bin/quackpack'),'doctor'],base,{'PATH':str(binaries)})
    check(r.returncode==0 and json.loads(r.stdout)['args']==['doctor'],'runtime selection also supports other installed version aliases')

    account=base/'recovery'
    root=account/'apps/quackpack'
    if a.previous_release:
        with zipfile.ZipFile(a.previous_release) as z:
            stage=base/'old'
            z.extractall(stage)
        shutil.copytree(stage/'quackpack',root)
        (root/'.user.ini').unlink(missing_ok=True)
        r=run([a.php,str(root/'bin/quackpack'),'install','--url=https://quackpack.d3cline.net','--username=ayylmao','--name=John Augustino Spounias'],account,{'PUP_PASSWORD':'test-recovery-password-123'})
        check(r.returncode!=0 and '.user.ini' in r.stderr and 'Cannot copy deployment file' in r.stderr,'reproduce the exact 0.3.1 missing-dotfile failure')
    else:
        release(root)
        # Deterministic interruption after configuration is written and before deployment.
        installer=root/'app/Installer.php'
        installer.write_text(installer.read_text().replace("$stage=$data.'/.runtime-'", "throw new \\RuntimeException('Simulated interrupted deployment'); $stage=$data.'/.runtime-'",1))
        r=run([a.php,str(root/'bin/quackpack'),'install','--url=https://quackpack.d3cline.net','--username=ayylmao','--name=John Augustino Spounias'],account,{'PUP_PASSWORD':'test-recovery-password-123'})
        check(r.returncode!=0 and 'Simulated interrupted deployment' in r.stderr,'create an interrupted install after identity generation')
    private=account/'quackpack'
    original=(private/'config.php').read_bytes()
    check(b'John Augustino Spounias' in original and b'ayylmao' in original,'interrupted install retains original owner configuration')
    seed='require "app/bootstrap.php";$a=pup();$a->store->put("meta","recovery-fixture",["text"=>"preserve my existing data"]);'
    r=run([a.php,'-r',seed],account,cwd=root)
    check(r.returncode==0,'existing database populated before repair')
    (private/'media'/'recovery-fixture.jpg').write_bytes(b'existing-photo-bytes')
    release(root)
    r=run([a.php,str(root/'bin/quackpack'),'repair'],account,{'PUP_PHP':a.php})
    check(r.returncode==0,'repair completes existing install without password input: '+r.stderr)
    repaired=json.loads(r.stdout)
    check(repaired['repaired'] and 'ayylmao' in repaired['actor'],'repair retains the original actor identity')
    check((private/'config.php').read_bytes()==original,'repair preserves exact config, federation keys and owner password hash')
    runtime=private/'runtime'
    r=run([a.php,'-r','require "app/bootstrap.php";echo Pup\\App::json(pup()->store->get("meta","recovery-fixture"));'],account,cwd=runtime)
    check(r.returncode==0 and json.loads(r.stdout)['text']=='preserve my existing data','repair preserves existing database content')
    check((private/'media/recovery-fixture.jpg').read_bytes()==b'existing-photo-bytes','repair preserves existing photos')
    check((root/'.user.ini').exists() and not (root/'app').exists(),'repair generates missing settings and completes private-source relocation')
    if a.previous_release:
        check(len(list(private.glob('runtime-backup-*')))==1,'incomplete 0.3.1 runtime retained as a private backup')
    check(repaired['php_binary'] in repaired['cron'],'recovery cron uses the actual selected PHP binary')
    r=run([a.php,str(runtime/'bin/quackpack'),'work','--seconds=1'],account)
    check(r.returncode==0,'recovered private worker starts')
    r=run([a.php,str(runtime/'bin/quackpack'),'doctor'],account)
    check(r.returncode==0 and json.loads(r.stdout)['ok'],'recovered installation passes doctor')
    repair_source=account/'release-source'
    release(repair_source)
    r=run(['bash',str(repair_source/'deploy/opalstack.sh'),'repair'],account,{'PUP_PHP':a.php})
    check(r.returncode==0 and '"repaired":true' in r.stdout and '"ok":true' in r.stdout,'scripted repair uses selected PHP without fresh-install variables: '+r.stderr)
    check((private/'config.php').read_bytes()==original,'scripted repair also leaves configuration unchanged')
    if a.running_release:
        running_account=base/'running-upgrade'
        old_stage=base/'running-old-release'
        with zipfile.ZipFile(a.running_release) as z:z.extractall(old_stage)
        app_root=running_account/'apps/quackpack'
        shutil.copytree(old_stage/'quackpack',app_root)
        r=run([a.php,str(app_root/'bin/quackpack'),'install','--url=https://existing.example','--username=owner'],running_account,{'PUP_PASSWORD':'running-install-password-123'})
        check(r.returncode==0,'existing 0.3.2 site installs for upgrade test: '+r.stderr)
        old_install=json.loads(r.stdout)
        old_config=(running_account/'quackpack/config.php').read_bytes()
        old_runtime=running_account/'quackpack/runtime'
        seed_code='require "app/bootstrap.php";$a=pup();$a->saveLinks([["label"=>"Keep my link","url"=>"https://music.example/","enabled"=>true]]);$p=$a->post(["caption"=>"Keep my existing photo"],[["type"=>"Document","mediaType"=>"image/jpeg","url"=>"https://existing.example/photo.jpg"]]);echo Pup\\App::json(["id"=>$p["id"],"actor"=>$a->actorId()]);'
        r=run([a.php,'-r',seed_code],running_account,cwd=old_runtime)
        check(r.returncode==0,'running site has an existing public photo and managed links')
        old_post=json.loads(r.stdout)
        new_stage=running_account/'private-update'
        release(new_stage)
        r=run([a.php,str(new_stage/'bin/quackpack'),'repair'],running_account)
        check(r.returncode==0 and (running_account/'quackpack/config.php').read_bytes()==old_config,'private staging upgrades a running site while preserving exact configuration')
        read_code='require "app/bootstrap.php";$a=pup();echo Pup\\App::json(["post"=>$a->store->page("posts")[0],"links"=>$a->links(),"actor"=>$a->actorId()]);'
        r=run([a.php,'-r',read_code],running_account,cwd=old_runtime)
        kept=json.loads(r.stdout)
        check(kept['post']['id']==old_post['id'] and kept['actor']==old_install['actor'] and kept['links'][0]['label']=='Keep my link','upgrade preserves post URLs, actor identity and profile links')
        r=run([a.php,str(old_runtime/'bin/quackpack'),'discover','--action=seed','--value=known.example'],running_account)
        check(r.returncode==0 and json.loads(r.stdout)['queue']>=5,'schema upgrade enables persistent discovery queue on the old database')
print(f'PASS: {checks} installer and PHP-selection regression assertions')
