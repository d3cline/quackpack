#!/usr/bin/env python3
"""Recover a missing private runtime using the installed app's real configuration."""
import argparse,json,os,shutil,subprocess,tempfile
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('--php',default='php');a=p.parse_args()
source=Path(__file__).resolve().parents[1];checks=0

def check(value,name):
 global checks
 checks+=1
 if not value:raise AssertionError(name)
 print(f'ok {checks} - {name}',flush=True)

with tempfile.TemporaryDirectory(prefix='quackpack-updater-') as temp:
 home=Path(temp)/'account';home.mkdir();root=home/'apps/quackpack';stage=home/'private release/quackpack';stage.parent.mkdir()
 shutil.copytree(source,stage)
 private=home/'custom private storage'
 env=dict(os.environ,HOME=str(home),PUP_PASSWORD='fixture-password-123456')
 for key in ['PUP_CONFIG','PUP_DSN','PUP_DB_USER','PUP_DB_PASSWORD','PUP_PHP']:env.pop(key,None)
 def php(args,cwd=root,extra=None):return subprocess.run([a.php,*map(str,args)],cwd=cwd,env=dict(env,**(extra or {})),capture_output=True,text=True)
 r=php([stage/'bin/quackpack','install','--url=https://photos.example','--username=alice','--data-dir='+str(private),'--docroot='+str(root)],cwd=home)
 check(r.returncode==0,'install an existing site using a custom private path: '+r.stderr)
 config=private/'config.php';original=config.read_bytes()
 check(not (home/'quackpack/runtime').exists(),'the previously assumed HOME runtime path does not exist')
 check((root/'quackpack.php').is_file(),'deployment creates a stable CLI in the application directory')
 r=php([root/'quackpack.php','help']);check(r.returncode==0 and '0.5.3' in r.stdout,'stable CLI uses the configured runtime')
 seed='require '+repr(str(private/'runtime/app/bootstrap.php'))+';define("QUACKPACK_CONFIG_FILE",'+repr(str(config))+');$a=pup();$p=$a->post(["caption"=>"Keep me"],[["type"=>"Document","mediaType"=>"image/jpeg","url"=>"https://photos.example/media/existing.jpg"]]);echo Pup\\App::json(["actor"=>$a->actorId(),"post"=>$p["id"]]);'
 r=php(['-r',seed]);check(r.returncode==0,'seed existing posts with the installed identity: '+r.stderr);before=json.loads(r.stdout)
 (private/'media/existing.jpg').write_bytes(b'original-photo-bytes')
 (private/'runtime').rename(private/'preserved-old-runtime')
 check(not (private/'runtime/app/bootstrap.php').exists(),'reproduce the missing-bootstrap failure')
 r=php([stage/'update.php']);check(r.returncode==0,'updater recovers the runtime using the installed index pointer: '+r.stderr)
 result=json.loads(r.stdout.splitlines()[0])
 check(result['runtime']==str(private/'runtime') and result['cli']==str(root/'quackpack.php'),'recovery uses actual custom paths and returns the stable CLI')
 check(config.read_bytes()==original,'recovery preserves configuration bytes, keys and owner password')
 check((private/'media/existing.jpg').read_bytes()==b'original-photo-bytes','recovery preserves existing photo bytes')
 r=php([root/'quackpack.php','export']);check(r.returncode==0,'stable CLI runs after runtime reconstruction: '+r.stderr);export=json.loads(r.stdout)
 check(export['posts'][0]['id']==before['post'],'recovery preserves original post URLs and content')
 r=php([root/'quackpack.php','doctor']);check(json.loads(r.stdout)['actor']==before['actor'],'recovery preserves the original actor URL')
 r=php([stage/'update.php']);check(r.returncode==0 and config.read_bytes()==original,'updater can be run again without changing identity')
 # More than one configuration must not select an unrelated identity.
 empty=home/'empty';empty.mkdir();other=home/'other';other.mkdir();(other/'config.php').write_bytes(original)
 locator='require '+repr(str(stage/'app/bootstrap.php'))+';try{Pup\\Updater::locate('+repr(str(other))+','+repr(str(empty))+');echo "found";}catch(Throwable $e){echo $e->getMessage();}'
 r=php(['-r',locator]);check(r.returncode==0 and 'found' in r.stdout,'a complete unambiguous existing configuration can be found without a runtime')
 (empty/'config.php').write_bytes(original)
 r=php(['-r',locator]);check('Several existing Quackpack configurations' in r.stdout,'ambiguous configurations are not selected arbitrarily')
 (empty/'config.php').unlink()
 # No configuration is a failure, never a request to generate new keys.
 blank=home/'blank';blank.mkdir()
 r=php([stage/'update.php','--docroot='+str(blank)],extra={'PUP_CONFIG':str(blank/'missing.php')})
 check(r.returncode!=0 and 'existing Quackpack configuration' in r.stderr,'missing configuration fails clearly without creating an identity')
 check(not (blank/'config.php').exists() and config.read_bytes()==original,'failed recovery leaves the original installation unchanged')
 r=php([stage/'update.php','--docroot='+str(blank),'--config='+str(config)])
 check(r.returncode!=0 and 'configured application directory' in r.stderr,'an unrelated document root is not overwritten')
 database=private/'pup.sqlite';database.rename(private/'pup.saved.sqlite')
 r=php([stage/'update.php']);check(r.returncode!=0 and 'existing SQLite database is missing' in r.stderr,'a missing database is never silently replaced with an empty one')
 check(not database.exists(),'missing-database recovery creates no replacement database')
print(f'PASS: {checks} runtime-recovery assertions')
