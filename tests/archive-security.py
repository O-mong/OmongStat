import importlib.util
from pathlib import Path
import tempfile
import unittest
from zipfile import ZipFile

spec = importlib.util.spec_from_file_location('archive', Path(__file__).resolve().parents[1] / 'scripts/archive.py')
archive = importlib.util.module_from_spec(spec)
spec.loader.exec_module(archive)


class ArchiveSecurity(unittest.TestCase):
    def test_regular_files(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            staging = root / 'staging'
            staging.mkdir()
            (staging / 'regular.txt').write_text('safe')
            output = root / 'test.zip'
            archive.create_archive(staging, output)
            with ZipFile(output) as package:
                self.assertEqual(package.read('regular.txt'), b'safe')

    def test_external_symlink_is_rejected_before_output(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            staging = root / 'staging'
            staging.mkdir()
            outside = root / 'outside.txt'
            outside.write_text('synthetic-secret')
            (staging / 'link.txt').symlink_to(outside)
            output = root / 'test.zip'
            with self.assertRaises(ValueError):
                archive.create_archive(staging, output)
            self.assertFalse(output.exists())


if __name__ == '__main__':
    unittest.main()
