#!/usr/bin/env python3
"""Import only the public assets pinned in asset-manifest.json.

This is a one-time source import helper, not a production deployment script.
It never reads the site's uploads, application settings, logs, or requests.
"""

from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor, as_completed
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import sys
import tempfile
from urllib.error import HTTPError, URLError
from urllib.parse import quote, urlparse
from urllib.request import HTTPRedirectHandler, Request, build_opener


SOURCE_ORIGIN = 'https://avtokai.ru'
PUBLIC_EXTENSIONS = {'.png', '.jpg', '.jpeg', '.webp', '.ico', '.mp4', '.woff2', '.svg', '.html'}


class SameOriginRedirects(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        parsed = urlparse(newurl)
        if parsed.scheme != 'https' or parsed.netloc != 'avtokai.ru':
            raise ValueError('Redirect outside the source origin is not allowed')
        return super().redirect_request(request, fp, code, msg, headers, newurl)


def validate_path(value: object) -> str:
    if not isinstance(value, str) or not value or '\\' in value or any(ord(char) < 32 or ord(char) == 127 for char in value):
        raise ValueError('Invalid asset path')
    path = PurePosixPath(value)
    if path.is_absolute() or '..' in path.parts or value != path.as_posix():
        raise ValueError('Asset paths must be normalized and relative')
    if any(part.startswith('.') for part in path.parts):
        raise ValueError('Hidden paths cannot be imported')
    if path.parts[0].lower() == 'uploads':
        raise ValueError('Customer uploads cannot be imported')
    if path.suffix.lower() not in PUBLIC_EXTENSIONS and value != 'ico/manifest.json':
        raise ValueError('The path is not an allowed public asset')
    return value


def load_manifest(path: Path) -> list[dict]:
    manifest = json.loads(path.read_text(encoding='utf-8'))
    if manifest.get('schema_version') != 1 or manifest.get('source_origin') != SOURCE_ORIGIN:
        raise ValueError('Unsupported manifest or source origin')
    assets = manifest.get('assets')
    if not isinstance(assets, list) or not assets:
        raise ValueError('Manifest must contain assets')
    seen = set()
    validated = []
    for asset in assets:
        if not isinstance(asset, dict):
            raise ValueError('Invalid asset record')
        relative = validate_path(asset.get('path'))
        if relative in seen:
            raise ValueError('Duplicate asset path')
        seen.add(relative)
        size, checksum = asset.get('size'), asset.get('sha256')
        if type(size) is not int or size < 0 or size > 25 * 1024 * 1024:
            raise ValueError('Invalid or unexpectedly large asset size')
        if not isinstance(checksum, str) or not re.fullmatch(r'[0-9a-f]{64}', checksum):
            raise ValueError('Invalid SHA-256 checksum')
        validated.append({'path': relative, 'size': size, 'sha256': checksum})
    return validated


def destination_for(root: Path, relative: str) -> Path:
    destination = root / relative
    current = destination
    while current != root:
        if current.is_symlink():
            raise ValueError('Symlinks cannot be import destinations')
        current = current.parent
    if not destination.resolve().is_relative_to(root):
        raise ValueError('Asset destination escapes the repository')
    return destination


def matches(path: Path, asset: dict) -> bool:
    if not path.is_file() or path.stat().st_size != asset['size']:
        return False
    digest = hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            digest.update(block)
    return digest.hexdigest() == asset['sha256']


def import_one(root: Path, asset: dict, verify_only: bool, retries: int) -> str:
    destination = destination_for(root, asset['path'])
    if matches(destination, asset):
        return 'verified' if verify_only else 'existing'
    if verify_only:
        raise ValueError('Missing asset or checksum mismatch')

    destination.parent.mkdir(parents=True, exist_ok=True)
    last_error = None
    for _ in range(retries + 1):
        temporary = None
        try:
            request = Request(
                SOURCE_ORIGIN + '/' + quote(asset['path'], safe='/'),
                headers={'User-Agent': 'AvtoKai-source-import/1.0'},
            )
            with build_opener(SameOriginRedirects()).open(request, timeout=30) as response:
                parsed = urlparse(response.geturl())
                if parsed.scheme != 'https' or parsed.netloc != 'avtokai.ru' or response.status != 200:
                    raise ValueError('Unexpected source response')
                data = response.read(asset['size'] + 1)
            if len(data) != asset['size'] or hashlib.sha256(data).hexdigest() != asset['sha256']:
                raise ValueError('Downloaded size or SHA-256 checksum does not match the manifest')
            with tempfile.NamedTemporaryFile(dir=destination.parent, prefix='.' + destination.name + '.', delete=False) as stream:
                temporary = Path(stream.name)
                stream.write(data)
            # Verify the destination again before the atomic replacement.
            destination_for(root, asset['path'])
            temporary.replace(destination)
            return 'downloaded'
        except HTTPError as exc:
            last_error = f'HTTP {exc.code}'
        except (URLError, TimeoutError, OSError, ValueError) as exc:
            last_error = type(exc).__name__
        finally:
            if temporary is not None and temporary.exists():
                temporary.unlink()
    raise RuntimeError(last_error or 'Asset download failed')


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repo-root', type=Path, default=Path(__file__).resolve().parent)
    parser.add_argument('--manifest', type=Path, help='Defaults to asset-manifest.json in the repository root')
    parser.add_argument('--verify-only', action='store_true', help='Verify local files without making any network requests')
    parser.add_argument('--workers', type=int, default=8, choices=range(1, 9), metavar='1..8')
    parser.add_argument('--retries', type=int, default=2, choices=range(3), metavar='0..2')
    parser.add_argument('--pathspec-file', type=Path, help='Write a NUL-delimited list of validated asset paths after success')
    args = parser.parse_args()
    root = args.repo_root.resolve()
    manifest_path = args.manifest or root / 'asset-manifest.json'
    try:
        assets = load_manifest(manifest_path)
        # Check all destinations before starting any writes.
        destinations = {destination_for(root, item['path']) for item in assets}
        if args.pathspec_file and args.pathspec_file.resolve() in destinations:
            raise ValueError('Pathspec output cannot overwrite an asset')
    except (OSError, ValueError, TypeError, AttributeError) as exc:
        print(f'Invalid manifest or destination: {type(exc).__name__}', file=sys.stderr)
        return 1

    errors = []
    statuses = {}
    with ThreadPoolExecutor(max_workers=args.workers) as pool:
        futures = {pool.submit(import_one, root, asset, args.verify_only, args.retries): asset['path'] for asset in assets}
        for future in as_completed(futures):
            relative = futures[future]
            try:
                status = future.result()
                statuses[status] = statuses.get(status, 0) + 1
            except Exception as exc:
                errors.append(relative)
                print(f'ERROR {relative}: {type(exc).__name__}', file=sys.stderr)
    if errors:
        print(f'Failed: {len(errors)} of {len(assets)} public assets', file=sys.stderr)
        return 1
    if args.pathspec_file:
        args.pathspec_file.parent.mkdir(parents=True, exist_ok=True)
        args.pathspec_file.write_bytes(b''.join(item['path'].encode('utf-8') + b'\0' for item in assets))
    print(json.dumps({'assets': len(assets), 'bytes': sum(item['size'] for item in assets), **statuses}, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
