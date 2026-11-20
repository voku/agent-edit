#!/usr/bin/env python3
"""The concurrent writer of the `stale-rename` case: someone else edits a file while the agent works on it.

    python3 tools/route-experiment/fixtures/stale_writer.py --repo RUN/repo --log RUN/stale-writer.jsonl [--delay 4]

Start it right before the agent. It watches every directory under <repo>/src with inotify. After the first open of
src/Checkout/Cart.php itself (directory listings and other files do not count), by the agent or by a tool it runs, it
waits --delay seconds. Then it inserts one new method into
src/Checkout/Cart.php, reading the file as it is at that moment so earlier edits are kept. The method calls the
family's old name. If nothing opens the file within --fallback seconds, it writes anyway and logs `fallback`; grade.py does not count such a run. Every step is logged with a
timestamp, and grade.py reads the log. It never touches anything else and exits after the one write.
"""
import argparse
import ctypes
import json
import os
import select
import struct
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from hierarchy_rename import STALE_PATH, stale_insert  # noqa: E402

IN_OPEN = 0x20
IN_ISDIR = 0x40000000


def log(log_path: Path, **event) -> None:
    with open(log_path, "a") as handle:
        handle.write(json.dumps({'at': time.time(), **event}) + '\n')


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--repo', required=True)
    parser.add_argument('--log', required=True)
    parser.add_argument('--delay', type=float, default=4.0)
    parser.add_argument('--fallback', type=float, default=120.0)
    args = parser.parse_args()
    repo = Path(args.repo).resolve()
    out = Path(args.log).resolve()

    libc = ctypes.CDLL('libc.so.6', use_errno=True)
    fd = libc.inotify_init()
    if fd < 0:
        sys.exit('inotify_init failed')
    for directory in [repo / 'src', *[p for p in (repo / 'src').rglob('*') if p.is_dir()]]:
        libc.inotify_add_watch(fd, str(directory).encode(), IN_OPEN)
    log(out, event='watching', repo=str(repo), delay=args.delay)

    # Fire only on an open of the target file itself: a directory listing or another file is not a read of the
    # content this writer is about to change, so it cannot make the agent's view stale.
    target_name = Path(STALE_PATH).name
    deadline = time.time() + args.fallback
    opened = False
    while not opened:
        remaining = deadline - time.time()
        ready, _, _ = select.select([fd], [], [], max(0.0, remaining))
        if not ready:
            break
        data = os.read(fd, 65536)
        offset = 0
        while offset + 16 <= len(data):
            _, mask, _, length = struct.unpack('iIII', data[offset:offset + 16])
            name = data[offset + 16:offset + 16 + length].rstrip(b'\0').decode(errors='replace')
            offset += 16 + length
            if mask & IN_ISDIR or name != target_name:
                continue
            opened = True
            log(out, event='first_open', name=name)
            break
    if opened:
        time.sleep(args.delay)
    else:
        log(out, event='fallback')
    target = repo / STALE_PATH
    before = target.read_text()
    target.write_text(stale_insert(before, 'amount'))
    log(out, event='wrote', path=STALE_PATH, bytes_before=len(before.encode()))


if __name__ == '__main__':
    main()
