"""Restoration verification must reject loss, corruption and extra content.
Package: Nicode.joomla.webmonitor
"""
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('verifier', Path(__file__).resolve().parents[1] / 'tools/verify_restored_tree.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class RestorationVerification(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.target = self.root / 'target'
        self.target.mkdir()
        (self.target / 'file').write_bytes(b'original')
        (self.target / 'empty').mkdir()
        self.manifest = self.root / 'manifest.json'
        self.data = {'phase': 'complete', 'entries': {'': {'type': 'directory', 'children': ['empty', 'file']},
            'empty': {'type': 'directory'}, 'file': {'type': 'file', 'bytes': 8,
            'chunks': [hashlib.sha256(b'original').hexdigest()]}}}
        self.save()

    def save(self):
        self.manifest.write_text(json.dumps(self.data))

    def verify(self):
        return module.verify(self.manifest, self.target)

    def test_exact_tree(self):
        result = self.verify()
        self.assertEqual((result['files'], result['bytes']), (1, 8))
        self.assertFalse(result['application_verified'])

    def test_same_size_corruption(self):
        (self.target / 'file').write_bytes(b'changed!')
        with self.assertRaisesRegex(ValueError, 'chunks'): self.verify()

    def test_missing_file(self):
        (self.target / 'file').unlink()
        with self.assertRaisesRegex(ValueError, 'entry set'): self.verify()

    def test_extra_file(self):
        (self.target / 'extra').touch()
        with self.assertRaisesRegex(ValueError, 'entry set'): self.verify()

    def test_directory_replaced(self):
        (self.target / 'empty').rmdir()
        (self.target / 'empty').touch()
        with self.assertRaisesRegex(ValueError, 'Directory'): self.verify()

    def test_incomplete_manifest(self):
        self.data['phase'] = 'discover'; self.save()
        with self.assertRaisesRegex(ValueError, 'Incomplete'): self.verify()

    def test_path_escape(self):
        self.data['entries']['../escape'] = {'type': 'directory'}; self.save()
        with self.assertRaisesRegex(ValueError, 'relative'): self.verify()

    def test_multiblock_and_empty_files(self):
        content = b'x' * 1048576 + b'last block'
        (self.target / 'file').write_bytes(content)
        (self.target / 'zero').touch()
        self.data['entries']['file'] = {'type': 'file', 'bytes': len(content),
            'chunks': [hashlib.sha256(content[:1048576]).hexdigest(), hashlib.sha256(content[1048576:]).hexdigest()]}
        self.data['entries']['zero'] = {'type': 'file', 'bytes': 0, 'chunks': []}
        self.save()
        self.assertEqual(self.verify()['files'], 2)
        with (self.target / 'file').open('ab') as stream: stream.write(b'x')
        with self.assertRaisesRegex(ValueError, 'length'): self.verify()


if __name__ == '__main__': unittest.main()
