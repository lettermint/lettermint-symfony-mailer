"""Check local release guards without publishing packages."""
import contextlib
import io
import json
import os
import re
import runpy
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
import urllib.error
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

if (ROOT/'tools/check_audit.py').exists():
    class AuditTests(unittest.TestCase):
        def check(self, report):
            with tempfile.TemporaryDirectory() as directory:
                path = Path(directory)/'audit.json'
                path.write_text(json.dumps(report))
                return subprocess.run([sys.executable, str(ROOT/'tools/check_audit.py'), str(path)], capture_output=True).returncode

        def test_clean_report(self):
            self.assertEqual(self.check({'projects': [{'frameworks': [{'framework': 'net8.0'}]}]}), 0)

        def test_transitive_advisory(self):
            self.assertNotEqual(self.check({'projects': [{'frameworks': [{'transitivePackages': [{'id': 'test', 'vulnerabilities': [{'severity': 'High'}]}]}]}]}), 0)

        def test_failed_audit(self):
            self.assertNotEqual(self.check({'problems': [{'message': 'Source unavailable'}]}), 0)

if (ROOT/'tools/update_packagist.py').exists():
    class PackagistTests(unittest.TestCase):
        def execute(self, response=None, error=None):
            env = {'PACKAGIST_USERNAME': 'test-user', 'PACKAGIST_API_TOKEN': 'test-secret', 'GITHUB_SERVER_URL': 'https://github.com', 'GITHUB_REPOSITORY': 'example/bridge'}
            with patch.dict(os.environ, env), patch('urllib.request.urlopen', return_value=io.BytesIO(json.dumps(response).encode()), side_effect=error) as mocked, contextlib.redirect_stdout(io.StringIO()):
                runpy.run_path(str(ROOT/'tools/update_packagist.py'), run_name='__main__')
            return mocked.call_args.args[0]

        def test_update_request(self):
            request = self.execute({'status': 'success'})
            self.assertEqual(request.get_method(), 'POST')
            self.assertEqual(request.full_url, 'https://packagist.org/api/update-package')
            self.assertEqual(request.get_header('Authorization'), 'Bearer test-user:test-secret')
            self.assertEqual(json.loads(request.data), {'repository': 'https://github.com/example/bridge'})

        def test_unsuccessful_update(self):
            with self.assertRaises(SystemExit):
                self.execute({'status': 'error'})

        def test_network_error_does_not_print_credentials(self):
            with self.assertRaises(SystemExit) as error:
                self.execute(error=urllib.error.URLError('test-secret'))
            self.assertNotIn('test-secret', str(error.exception))

if __name__ == '__main__':
    unittest.main()
