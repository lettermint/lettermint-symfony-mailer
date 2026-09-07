#!/usr/bin/env python3
"""Request an update for an existing Packagist listing."""
import json
import os
import urllib.error
import urllib.request

username = os.environ['PACKAGIST_USERNAME']
token = os.environ['PACKAGIST_API_TOKEN']
if not username or not token:
    raise SystemExit('Set the Packagist username and API token.')
repository = os.environ['GITHUB_SERVER_URL'] + '/' + os.environ['GITHUB_REPOSITORY']
request = urllib.request.Request(
    'https://packagist.org/api/update-package',
    data=json.dumps({'repository': repository}).encode(),
    headers={'Content-Type': 'application/json', 'Authorization': 'Bearer ' + username + ':' + token},
    method='POST',
)
try:
    with urllib.request.urlopen(request, timeout=30) as response:
        result = json.load(response)
except (urllib.error.URLError, ValueError):
    raise SystemExit('Packagist update failed. Check the listing and registry credentials.') from None
if result.get('status') != 'success':
    raise SystemExit('Packagist did not accept the update request.')
print('Packagist accepted the update request.')
