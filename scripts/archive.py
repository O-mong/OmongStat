"""Archive only regular files, rejecting links before creating the ZIP."""

import os
from pathlib import Path
import stat
import sys
from zipfile import ZIP_DEFLATED, ZipFile


def create_archive(staging: Path, output: Path) -> None:
    if staging.is_symlink():
        raise ValueError('Archive root must not be a symlink.')
    staging = staging.resolve(strict=True)
    files = []
    for file in sorted(staging.rglob('*')):
        mode = file.lstat().st_mode
        if stat.S_ISLNK(mode):
            raise ValueError('Symlinks are not allowed in the archive.')
        if stat.S_ISDIR(mode):
            continue
        if not stat.S_ISREG(mode):
            raise ValueError('Only regular files are allowed in the archive.')
        file.resolve(strict=True).relative_to(staging)
        files.append(file)

    with ZipFile(output, 'w', ZIP_DEFLATED) as archive:
        for file in files:
            descriptor = os.open(file, os.O_RDONLY | os.O_NOFOLLOW)
            with os.fdopen(descriptor, 'rb') as source:
                if not stat.S_ISREG(os.fstat(source.fileno()).st_mode):
                    raise ValueError('Archive file changed during packaging.')
                with archive.open(file.relative_to(staging).as_posix(), 'w') as target:
                    while block := source.read(65536):
                        target.write(block)


if __name__ == '__main__':
    create_archive(Path(sys.argv[1]), Path(sys.argv[2]))
