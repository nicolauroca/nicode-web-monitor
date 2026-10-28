"""Package: Nicode.joomla.webmonitor. Packaging equivalence across LF and CRLF checkouts."""
import argparse
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import zipfile

p=argparse.ArgumentParser();p.add_argument('--output',type=Path,required=True);a=p.parse_args()
repo=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='nwm-build-') as tmp:
    roots=[Path(tmp)/'lf',Path(tmp)/'crlf']
    binary=b'\x89PNG\r\n\x1a\n\x00\xff\r\n'
    for root in roots:
        root.mkdir()
        for directory in ['extensions','tools']:
            shutil.copytree(repo/directory,root/directory)
        shutil.copy2(repo/'LICENSE',root/'LICENSE')
        for file in root.rglob('*'):
            if file.is_file() and (file.suffix in {'.php','.xml','.sql','.ini','.txt','.css','.js','.json','.md'} or file.name=='LICENSE'):
                data=file.read_bytes().replace(b'\r\n',b'\n')
                file.write_bytes(data if root.name=='lf' else data.replace(b'\n',b'\r\n'))
        (root/'extensions/com_nicodewebmonitor/binary-fixture.png').write_bytes(binary)
        subprocess.run([sys.executable,'-B',str(root/'tools/build.py')],check=True,capture_output=True)
    hashes={}
    for archive in (roots[0]/'dist').glob('*.zip'):
        data=archive.read_bytes();assert data==(roots[1]/'dist'/archive.name).read_bytes(),archive.name
        hashes[archive.name]=hashlib.sha256(data).hexdigest()
        with zipfile.ZipFile(archive) as z:
            assert len(z.namelist())==len(set(z.namelist()))
            for name in z.namelist():
                if name.endswith('.png'):assert z.read(name)==binary
                else:assert b'\r\n' not in z.read(name),name
    assert len(hashes)==4
a.output.write_text(json.dumps({'equivalent_packages':4,'binary_preserved':True,'fixture_hashes':hashes,
    'note':'Component fixture contains a synthetic binary; these are not release artifact hashes.'},indent=2))
print('4 packages identical across LF/CRLF; binary bytes preserved.')
