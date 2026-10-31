"""Build a deterministic development plugin ZIP using Python's standard library."""
import hashlib
from pathlib import Path
import zipfile

TEXT_SUFFIXES = {'.php', '.xml', '.sql', '.ini', '.txt', '.css', '.js', '.json', '.md'}

def source_bytes(path):
    data = path.read_bytes()
    # Git archives use LF while Windows checkouts may use CRLF. Normalize only
    # known text sources; binary assets must remain byte-for-byte intact.
    if path.suffix.lower() in TEXT_SUFFIXES or path.name == 'LICENSE':
        data = data.replace(b'\r\n', b'\n')
    return data

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
            archive.writestr(info, source_bytes(file))
    license_info = zipfile.ZipInfo('LICENSE.txt', (2026, 1, 1, 0, 0, 0))
    license_info.compress_type = zipfile.ZIP_DEFLATED
    license_info.external_attr = 0o100644 << 16
    archive.writestr(license_info, source_bytes(root / 'LICENSE'))
print(output.name, hashlib.sha256(output.read_bytes()).hexdigest())

component = root / 'extensions/com_nicodewebmonitor'
output = root / 'dist/com_nicodewebmonitor-0.8.0-dev.zip'
with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    files = {p.relative_to(component).as_posix(): source_bytes(p) for p in component.rglob('*') if p.is_file()}
    files['LICENSE.txt'] = source_bytes(root / 'LICENSE')
    for name in ['PackageReceipt', 'PackageState']:
        files['administrator/src/Service/' + name + '.php'] = source_bytes(root / 'tools' / (name + '.php')).replace(
            b'Nicode\\Maintenance', b'Nicode\\Component\\NicodeWebMonitor\\Administrator\\Service')
    for name, content in sorted(files.items()):
        info = zipfile.ZipInfo(name, (2026, 1, 1, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        archive.writestr(info, content)
print(output.name, hashlib.sha256(output.read_bytes()).hexdigest())

# Bundle the same collector into the independent HTTP plugin, with its own namespace.
# Generated copies live only in the ZIP: one reviewed source of inventory behavior.
connector = root / 'extensions/plg_system_nicodewebmonitor'
output = root / 'dist/plg_system_nicodewebmonitor-0.5.0-dev.zip'
files = {p.relative_to(connector).as_posix(): source_bytes(p) for p in connector.rglob('*') if p.is_file()}
for file in (source / 'src/Inventory').glob('*.php'):
    files['src/Inventory/' + file.name] = source_bytes(file).replace(
        b'Nicode\\Plugin\\Console\\NicodeWebMonitor', b'Nicode\\Plugin\\System\\NicodeWebMonitor')
files['LICENSE.txt'] = source_bytes(root / 'LICENSE')
for name in ['BackupOperations', 'FileSnapshot', 'DatabaseSnapshot', 'SiteSnapshot', 'TreeOperations', 'TreeManifest', 'ResumableFile', 'ResumableTree', 'ResumableDatabase', 'ResumableSite', 'ExtensionUpdate', 'MaintenanceGate', 'CoreDispatch', 'CoreOperation', 'PackageReceipt', 'PackageState', 'PackagePreflight', 'PackageOperation', 'NativePackageUpdate']:
    files['src/Maintenance/' + name + '.php'] = source_bytes(root / 'tools' / (name + '.php')).replace(
        b'Nicode\\Maintenance', b'Nicode\\Plugin\\System\\NicodeWebMonitor\\Maintenance')
with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for name, content in sorted(files.items()):
        info = zipfile.ZipInfo(name, (2026, 1, 1, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        archive.writestr(info, content)
print(output.name, hashlib.sha256(output.read_bytes()).hexdigest())

# Native Joomla scheduler plugin; no tasks are created during installation.
source = root / 'extensions/plg_task_nicodewebmonitor'
output = root / 'dist/plg_task_nicodewebmonitor-0.1.0-dev.zip'
with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    files = {p.relative_to(source).as_posix(): source_bytes(p) for p in source.rglob('*') if p.is_file()}
    files['LICENSE.txt'] = source_bytes(root / 'LICENSE')
    for name, content in sorted(files.items()):
        info = zipfile.ZipInfo(name, (2026, 1, 1, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        archive.writestr(info, content)
print(output.name, hashlib.sha256(output.read_bytes()).hexdigest())
