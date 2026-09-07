"""Check local release guards without publishing packages."""
import os
import re
import subprocess
import sys
import unittest
from pathlib import Path
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]

class ReleaseTests(unittest.TestCase):
    def run_guard(self, tag):
        return subprocess.run([sys.executable, str(ROOT/'tools/check_release.py')], env={**os.environ, 'RELEASE_TAG': tag}, capture_output=True, text=True)

    def version(self):
        project = ROOT/'src/Lettermint/Lettermint.csproj'
        if project.exists():
            return ET.parse(project).findtext('.//Version')
        if (ROOT/'mix.exs').exists():
            return re.search(r'version:\s*"([^"]+)"', (ROOT/'mix.exs').read_text()).group(1)
        return '0.1.0'

    def test_matching_version(self):
        for prefix in ['', 'v']:
            self.assertEqual(self.run_guard(prefix+self.version()).returncode, 0)

    def test_invalid_tags(self):
        for tag in ['main', 'v1.2', 'v01.2.3', 'v1.2.3;echo bad', 'v1.2.3\nextra']:
            self.assertNotEqual(self.run_guard(tag).returncode, 0)

    def test_mismatched_version_or_composer_tag(self):
        result = self.run_guard('v9999.0.0-rc.1')
        if (ROOT/'composer.json').exists():
            self.assertEqual(result.returncode, 0)
        else:
            self.assertNotEqual(result.returncode, 0)

if __name__ == '__main__':
    unittest.main()
