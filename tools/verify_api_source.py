#!/usr/bin/env python3
"""Check source hashes and export fresh API contract fixtures."""
import argparse
import hashlib
import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('api_repository', type=Path)
args = parser.parse_args()
record = json.loads((ROOT/'tests/Fixtures/api-source-verification.json').read_text())
for name, digest in record['files'].items():
    path = args.api_repository/name
    if not path.is_file() or hashlib.sha256(path.read_bytes()).hexdigest() != digest:
        raise SystemExit('API source review required: ' + name)
for name, digest in record['fixtures'].items():
    if hashlib.sha256((ROOT/name).read_bytes()).hexdigest() != digest:
        raise SystemExit('API fixture review required: ' + name)
result = subprocess.run(['php', str(ROOT/'tools/export_api_fixtures.php'), str(args.api_repository/'laravel')], check=True, capture_output=True, text=True)
if json.loads(result.stdout) != json.loads((ROOT/'tests/Fixtures/api-source.json').read_text()):
    raise SystemExit('Fresh API fixtures differ. Review the API contract.')
print('API source hashes, fresh controller output, and request validation match.')
