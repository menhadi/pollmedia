"""One repeatable official-source refresh. Schedule only when deployed."""
import subprocess,sys,json
from acquire import ROOT
steps=[['acquire.py','census-pilibhit-2011','sir-pilibhit-uncollected'],['extract.py'],['store.py']]
for step in steps:
    subprocess.run([sys.executable,str(ROOT/step[0]),*step[1:]],check=True)
    if step[0]=='acquire.py':
        manifest=json.loads((ROOT/'acquisition.json').read_text())
        failures=[s['id'] for s in manifest if s['id'] in step[1:] and s.get('last_check_error')]
        if failures:raise SystemExit('Refresh failed; accepted data preserved: '+', '.join(failures))
