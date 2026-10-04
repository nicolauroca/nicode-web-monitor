"""Build a deterministic development plugin ZIP using Python's standard library."""
import hashlib
from pathlib import Path
import zipfile

root = Path(__file__).resolve().parents[1]
source = root / 'extensions/plg_console_nicodewebmonitor'
output = root / 'dist/plg_console_nicodewebmonitor-0.1.0-dev.zip'
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
