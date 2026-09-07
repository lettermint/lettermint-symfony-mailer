#!/usr/bin/env python3
"""Check the release tag before a package is published."""
import os
import re
from pathlib import Path
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
tag = os.environ['RELEASE_TAG']
version = tag.removeprefix('v')
if not re.fullmatch(r'(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?', version):
    raise SystemExit('Use a semantic version tag, such as v0.1.0 or v0.2.0-rc.1.')
package_version = version  # Composer derives the package version from the Git tag.
if package_version != version:
    raise SystemExit('The release tag must match the package version in the tagged source.')
print('Release version:', version)
