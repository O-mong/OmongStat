"""Create the distribution archive from an already prepared staging directory."""

from pathlib import Path
import sys
from zipfile import ZIP_DEFLATED, ZipFile


def create_archive(staging: Path, output: Path) -> None:
    with ZipFile(output, 'w', ZIP_DEFLATED) as archive:
        for file in sorted(staging.rglob('*')):
            if file.is_file():
                archive.write(file, file.relative_to(staging))


if __name__ == '__main__':
    create_archive(Path(sys.argv[1]), Path(sys.argv[2]))
