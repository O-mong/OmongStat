"""Exercise the real deployment shell script with a filesystem-backed Docker double."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
FAKE_DOCKER = r'''#!/usr/bin/env python3
import json,os,pathlib,subprocess,sys,tempfile
root=os.environ['FAKE_PLUGINS']
backups=os.environ['FAKE_BACKUPS']
a=sys.argv[1:]
if a[0]=='inspect': print('true'); sys.exit(0)
if a[0]!='exec': sys.exit(2)
a=a[1:]
if a[0]=='-i': a=a[1:]
a=a[1:]
a=[x.replace('/var/www/html/wp-content/plugins',root).replace('/var/backups/omongstat',backups) for x in a]
if a[0]=='mktemp': print(tempfile.mkdtemp(prefix='.omongstat.stage.',dir=root)); sys.exit(0)
if a[0]=='php':
 p=pathlib.Path(a[-1]); m=p/'assets/admin/.vite/manifest.json'
 if os.environ.get('FAIL_VALIDATION')=='1': sys.exit(1)
 e=json.loads(m.read_text())['src/main.tsx']
 sys.exit(0 if all((p/f).is_file() for f in ['omongstat.php','assets/js/collector.js','assets/admin/'+e['file'],*['assets/admin/'+f for f in e.get('css',[])]]) else 1)
if a[0]=='sh': a=[x.replace('chown -R root:www-data', 'true') for x in a]
sys.exit(subprocess.call(a))
'''
FAKE_MV = r'''#!/usr/bin/env python3
import os,subprocess,sys
args=sys.argv[1:]
if os.environ.get('FAIL_REPLACE')=='1' and '.stage.' in args[0] and not args[0].endswith('.previous') and args[1].endswith('/omongstat'): sys.exit(1)
sys.exit(subprocess.call(['/bin/mv',*args]))
'''
FAKE_NPM = r'''#!/usr/bin/env python3
import json,os,pathlib,sys
marker=pathlib.Path(os.environ['FAKE_PLUGINS']).parent/'build-calls.jsonl'
with marker.open('a') as stream: stream.write(json.dumps(sys.argv[1:])+'\n')
if os.environ.get('FAIL_BUILD')=='1':
 print('Simulated TypeScript compilation failure',file=sys.stderr)
 sys.exit(23)
'''
class Deployment(unittest.TestCase):
    def exercise(self, flag=None, arguments=()):
        with tempfile.TemporaryDirectory(prefix='omongstat-deploy-test-') as tmp:
            root=Path(tmp); tools=root/'tools'; tools.mkdir(); plugins=root/'plugins'; plugins.mkdir()
            target=plugins/'omongstat'; target.mkdir(); (target/'original').write_text('preserve me')
            for name, content in [('docker',FAKE_DOCKER),('mv',FAKE_MV),('npm',FAKE_NPM)]:
                file=tools/name; file.write_text(content); file.chmod(0o755)
            env=dict(os.environ, PATH=str(tools)+os.pathsep+os.environ['PATH'], FAKE_PLUGINS=str(plugins), FAKE_BACKUPS=str(root/'backups'))
            if flag: env[flag]='1'
            result=subprocess.run(['bash',str(ROOT/'wordpress_plugins/omongstat/deploy-plugin.sh'),*arguments],cwd=root,env=env,capture_output=True,text=True)
            calls = (root/'build-calls.jsonl').read_text().splitlines()
            self.assertEqual(len(calls), 1)
            self.assertEqual(json.loads(calls[0]), ['--prefix', str(ROOT), 'run', 'build'])
            if flag == 'FAIL_BUILD':
                self.assertEqual(result.returncode, 23)
                self.assertIn('React build failed', result.stderr)
                self.assertEqual(list(plugins.iterdir()), [target])
            if flag:
                self.assertNotEqual(result.returncode,0,result.stdout+result.stderr)
                self.assertEqual((target/'original').read_text(),'preserve me')
            else:
                self.assertEqual(result.returncode,0,result.stdout+result.stderr)
                self.assertTrue((target/'omongstat.php').is_file())
                self.assertTrue(any(p.name.endswith('.previous') and (p/'original').is_file() for p in (root/'backups').iterdir()))
            self.assertFalse((plugins/'.omongstat.deploy.lock').exists())
    def test_success_keeps_backup(self): self.exercise()
    def test_build_failure_skips_upload(self): self.exercise('FAIL_BUILD')
    def test_legacy_build_flag_builds_once(self): self.exercise(arguments=('--build',))
    def test_validation_failure_keeps_original(self): self.exercise('FAIL_VALIDATION')
    def test_replace_failure_restores_original(self): self.exercise('FAIL_REPLACE')
if __name__=='__main__': unittest.main()
