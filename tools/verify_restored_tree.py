"""Read-only verification of a restored tree against its private capture manifest.

Package: Nicode.joomla.webmonitor
This checks bytes and entry types, not application startup or database recovery.
Inputs must be trusted local artifacts with exclusive access during verification.
"""
import argparse
import hashlib
import json
from pathlib import Path


def verify(manifest: Path, target: Path) -> dict:
    if manifest.is_symlink() or manifest.stat().st_size > 16 * 1024 * 1024:
        raise ValueError('Invalid manifest file')
    data = json.loads(manifest.read_text(encoding='utf-8'))
    entries = data['entries']
    if data.get('phase') != 'complete' or not isinstance(entries, dict) or not 1 <= len(entries) <= 32768:
        raise ValueError('Incomplete or oversized manifest')
    if not isinstance(entries.get(''), dict) or entries[''].get('type') != 'directory':
        raise ValueError('Invalid root entry')
    if target.is_symlink() or (hasattr(target, 'is_junction') and target.is_junction()) or not target.is_dir():
        raise ValueError('Target must be a real directory')
    for key in entries:
        if key and any(p in ('', '.', '..') or ':' in p or '\\' in p for p in key.split('/')):
            raise ValueError('Invalid relative path')
    actual = set()
    pending = [target]
    while pending:
        for path in pending.pop().iterdir():
            if path.is_symlink() or (hasattr(path, 'is_junction') and path.is_junction()):
                raise ValueError('Linked target entry')
            actual.add(path.relative_to(target).as_posix())
            if len(actual) > 32768:
                raise ValueError('Target entry limit')
            if path.is_dir():
                pending.append(path)
    if actual != set(entries) - {''}:
        raise ValueError('Restored entry set differs')
    files = size = 0
    for key, entry in entries.items():
        path = target / key
        if entry['type'] == 'directory':
            if not path.is_dir():
                raise ValueError('Directory type differs')
            continue
        if entry['type'] != 'file' or not path.is_file() or path.stat().st_size != entry['bytes']:
            raise ValueError('File type or length differs')
        hashes = []
        with path.open('rb') as stream:
            while block := stream.read(1048576):
                hashes.append(hashlib.sha256(block).hexdigest())
        if hashes != entry['chunks']:
            raise ValueError('Restored file chunks differ')
        files += 1
        size += entry['bytes']
    return {'files': files, 'bytes': size, 'all_entries_match': True,
            'all_chunks_match': True, 'database_verified': False, 'application_verified': False}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('manifest', type=Path)
    parser.add_argument('target', type=Path)
    args = parser.parse_args()
    print(json.dumps(verify(args.manifest, args.target)))
