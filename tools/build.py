"""Build a deterministic development plugin ZIP using Python's standard library."""
import hashlib
from pathlib import Path
import zipfile

root = Path(__file__).resolve().parents[1]
source = root / 'extensions/plg_console_nicodewebmonitor'
output = root / 'dist/plg_console_nicodewebmonitor-0.1.1-dev.zip'
output.parent.mkdir(exist_ok=True)
with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for file in sorted(source.rglob('*')):
        if file.is_file():
            info = zipfile.ZipInfo(file.relative_to(source).as_posix(), (2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, file.read_bytes())
    license_info = zipfile.ZipInfo('LICENSE.txt', (2026, 1, 1, 0, 0, 0))
    license_info.compress_type = zipfile.ZIP_DEFLATED
    license_info.external_attr = 0o100644 << 16
    archive.writestr(license_info, (root / 'LICENSE').read_bytes())
print(output.name, hashlib.sha256(output.read_bytes()).hexdigest())

component = root / 'extensions/com_nicodewebmonitor'
output = root / 'dist/com_nicodewebmonitor-0.3.0-dev.zip'
with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    files = {p.relative_to(component).as_posix(): p.read_bytes() for p in component.rglob('*') if p.is_file()}
    files['LICENSE.txt'] = (root / 'LICENSE').read_bytes()
    for name, content in sorted(files.items()):
        info = zipfile.ZipInfo(name, (2026, 1, 1, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        archive.writestr(info, content)
print(output.name, hashlib.sha256(output.read_bytes()).hexdigest())

# Bundle the same collector into the independent HTTP plugin, with its own namespace.
# Generated copies live only in the ZIP: one reviewed source of inventory behavior.
connector = root / 'extensions/plg_system_nicodewebmonitor'
output = root / 'dist/plg_system_nicodewebmonitor-0.2.0-dev.zip'
files = {p.relative_to(connector).as_posix(): p.read_bytes() for p in connector.rglob('*') if p.is_file()}
for file in (source / 'src/Inventory').glob('*.php'):
    files['src/Inventory/' + file.name] = file.read_bytes().replace(
        b'Nicode\\Plugin\\Console\\NicodeWebMonitor', b'Nicode\\Plugin\\System\\NicodeWebMonitor')
files['LICENSE.txt'] = (root / 'LICENSE').read_bytes()
with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for name, content in sorted(files.items()):
        info = zipfile.ZipInfo(name, (2026, 1, 1, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        archive.writestr(info, content)
print(output.name, hashlib.sha256(output.read_bytes()).hexdigest())
