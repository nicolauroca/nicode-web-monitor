"""Build deterministic Joomla packages; users install the two pkg_ ZIPs."""
import hashlib
from pathlib import Path
import zipfile

TEXT_SUFFIXES = {'.php', '.xml', '.sql', '.ini', '.txt', '.css', '.js', '.json', '.md', '.svg'}

def source_bytes(path):
    data = path.read_bytes()
    # Git archives use LF while Windows checkouts may use CRLF. Normalize only
    # known text sources; binary assets must remain byte-for-byte intact.
    if path.suffix.lower() in TEXT_SUFFIXES or path.name == 'LICENSE':
        data = data.replace(b'\r\n', b'\n')
    return data

root = Path(__file__).resolve().parents[1]

def brand_files():
    # Both components own an independent installed copy of the same brand source.
    assets={'brand.css':'brand.css', 'Sora-variable.woff2':'fuentes/Sora-variable.woff2',
            'OFL-Sora.txt':'fuentes/OFL-Sora.txt', 'isotipo-dark.svg':'logos/isotipo-dark.svg'}
    return {'administrator/tmpl/brand/' + name: source_bytes(root / 'tools/brand' / path)
            for name,path in assets.items()}

def connection_files(namespace):
    return {'administrator/src/Connection/' + p.name: source_bytes(p).replace(
        b'Nicode\\Connection', namespace + b'\\Administrator\\Connection')
        for p in (root / 'tools/connection').glob('*.php')}

def write_zip(output, files):
    with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for name, content in sorted(files.items()):
            info = zipfile.ZipInfo(name, (2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, content)
    print(output.name, hashlib.sha256(output.read_bytes()).hexdigest())
source = root / 'extensions/plg_console_nicodewebmonitor'
output = root / 'dist/plg_console_nicodewebmonitor-1.1.1-dev.10.zip'
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
output = root / 'dist/com_nicodewebmonitor-1.1.1-dev.10.zip'
with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    files = {p.relative_to(component).as_posix(): source_bytes(p) for p in component.rglob('*') if p.is_file()}
    files['LICENSE.txt'] = source_bytes(root / 'LICENSE')
    files.update(connection_files(b'Nicode\\Component\\NicodeWebMonitor'))
    files.update(brand_files())
    files['administrator/src/Service/InventorySchema.php'] = source_bytes(root / 'extensions/plg_console_nicodewebmonitor/src/Inventory/InventorySchema.php').replace(b'Nicode\\Plugin\\Console\\NicodeWebMonitor\\Inventory', b'Nicode\\Component\\NicodeWebMonitor\\Administrator\\Service')
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
output = root / 'dist/plg_system_nicodewebmonitor-1.1.1-dev.10.zip'
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
output = root / 'dist/plg_task_nicodewebmonitor-1.1.1-dev.10.zip'
with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    files = {p.relative_to(source).as_posix(): source_bytes(p) for p in source.rglob('*') if p.is_file()}
    files['LICENSE.txt'] = source_bytes(root / 'LICENSE')
    for name, content in sorted(files.items()):
        info = zipfile.ZipInfo(name, (2026, 1, 1, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        archive.writestr(info, content)
print(output.name, hashlib.sha256(output.read_bytes()).hexdigest())

# User-facing connector component and the two independent package owners.
source = root / 'extensions/com_nicodeconnector'
files = {p.relative_to(source).as_posix(): source_bytes(p) for p in source.rglob('*') if p.is_file()}
files['LICENSE.txt'] = source_bytes(root / 'LICENSE')
files.update(connection_files(b'Nicode\\Component\\NicodeConnector'))
files.update(brand_files())
files['administrator/src/Service/InventorySchema.php'] = source_bytes(root / 'extensions/plg_console_nicodewebmonitor/src/Inventory/InventorySchema.php').replace(b'Nicode\\Plugin\\Console\\NicodeWebMonitor\\Inventory', b'Nicode\\Component\\NicodeConnector\\Administrator\\Service')
files['administrator/src/Service/CredentialVault.php'] = source_bytes(
    root / 'extensions/com_nicodewebmonitor/administrator/src/Service/CredentialVault.php').replace(
        b'Nicode\\Component\\NicodeWebMonitor', b'Nicode\\Component\\NicodeConnector')
write_zip(root / 'dist/com_nicodeconnector-1.1.1-dev.10.zip', files)

for package, children in {
    'nicodemonitor': ['com_nicodewebmonitor', 'plg_task_nicodewebmonitor'],
    'nicodeconnector': ['plg_system_nicodewebmonitor', 'com_nicodeconnector'],
}.items():
    manifest = 'pkg_' + package + '.xml'
    files = {manifest: source_bytes(root / 'extensions' / ('pkg_' + package) / manifest),
        'script.php': source_bytes(root / 'extensions' / ('pkg_' + package) / 'script.php')}
    files['LICENSE.txt'] = source_bytes(root / 'LICENSE')
    for child in children:
        files['packages/' + child + '.zip'] = (root / 'dist' / (child + '-1.1.1-dev.10.zip')).read_bytes()
    write_zip(root / 'dist' / ('pkg_' + package + '-1.1.1-dev.10.zip'), files)
