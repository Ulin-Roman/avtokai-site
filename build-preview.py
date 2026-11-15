#!/usr/bin/env python3
"""Build an isolated GitHub Pages preview from Avtokai's public HTML.

The PHP production source is never executed or replaced. Static media is copied
from --source-root into the disposable build directory, so the repository needs
only one copy of its assets. Normal builds are offline and read preview-source.zip.
Use --capture explicitly to refresh that sanitized public-HTML snapshot archive.
"""
from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor
from html import escape
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import shutil
from urllib.parse import unquote, urljoin, urlsplit, urlunsplit
from urllib.request import Request, urlopen
import xml.etree.ElementTree as ET
from zipfile import ZipFile, ZIP_DEFLATED

ORIGIN = "https://avtokai.ru"
DEFAULT_BASE = "/avtokai-site/"
STATIC_SUFFIXES = {
    ".jpg", ".jpeg", ".png", ".webp", ".gif", ".svg", ".ico", ".avif",
    ".mp4", ".webm", ".ogg", ".mp3", ".wav", ".woff2", ".woff", ".ttf",
    ".otf", ".eot", ".css", ".js",
}
EXCLUDED_DIRS = {
    ".git", ".github", "node_modules", "vendor", "uploads", "logs", "cache",
    "tmp", "bin-tmp", "preview", "dist", "docs", "backups", "backup",
}
SENSITIVE_HIDDEN_FIELDS = {"csrf_token", "submission_id", "form_time"}
SCRIPT_RE = re.compile(r"<script\b(?P<attrs>[^>]*)>(?P<body>[\s\S]*?)</script\s*>", re.I)
CSS_URL_RE = re.compile(r"url\(\s*(?P<quote>['\"]?)(?P<url>[^)'\"]+)(?P=quote)\s*\)", re.I)
QUOTED_ROOT_URL_RE = re.compile(r"(?P<quote>['\"])(?P<url>/(?!/)[^'\"\r\n]*)(?P=quote)")


def read_url(url: str) -> str:
    request = Request(url, headers={"User-Agent": "AvtokaiPortfolioPreview/1.0"})
    with urlopen(request, timeout=45) as response:
        if response.status != 200:
            raise RuntimeError(f"Unexpected response {response.status}: {url}")
        content_type = response.headers.get_content_type()
        if content_type not in {"text/html", "application/xml", "text/xml"}:
            raise RuntimeError(f"Unexpected content type {content_type}: {url}")
        return response.read().decode("utf-8")


def site_routes(origin: str) -> list[str]:
    tree = ET.fromstring(read_url(origin + "/sitemap.xml"))
    routes = set()
    expected_host = urlsplit(origin).netloc
    for node in tree.findall("{*}url/{*}loc"):
        url = urlsplit(node.text or "")
        if url.netloc != expected_host or url.query or url.fragment:
            continue
        path = unquote(url.path)
        if not path.startswith("/") or ".." in Path(path).parts:
            raise RuntimeError(f"Unsafe sitemap path: {path}")
        if path != "/" and not path.endswith("/"):
            raise RuntimeError(f"Expected page directory in sitemap: {path}")
        routes.add(path)
    if "/" not in routes:
        raise RuntimeError("The source sitemap has no homepage")
    return sorted(routes, key=lambda path: (path != "/", path))


def serialize_tag(tag: str, attributes: list[tuple[str, str | None]], closed: bool = False) -> str:
    attrs = "".join(
        " " + name if value is None else f' {name}="{escape(value, quote=True)}"'
        for name, value in attributes
    )
    return "<" + tag + attrs + (" />" if closed else ">")


class SnapshotSanitizer(HTMLParser):
    """Remove per-session values before a public snapshot reaches disk."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=False)
        self.output: list[str] = []

    def tag(self, tag: str, attrs: list[tuple[str, str | None]], closed: bool) -> None:
        values = dict(attrs)
        if tag == "input" and values.get("type", "").lower() == "hidden":
            if values.get("name", "").lower() in SENSITIVE_HIDDEN_FIELDS:
                attrs = [(name, "" if name.lower() == "value" else value) for name, value in attrs]
                self.output.append(serialize_tag(tag, attrs, closed))
                return
        self.output.append(self.get_starttag_text())

    def handle_starttag(self, tag, attrs): self.tag(tag, attrs, False)
    def handle_startendtag(self, tag, attrs): self.tag(tag, attrs, True)
    def handle_endtag(self, tag): self.output.append(f"</{tag}>")
    def handle_data(self, data): self.output.append(data)
    def handle_entityref(self, name): self.output.append(f"&{name};")
    def handle_charref(self, name): self.output.append(f"&#{name};")
    def handle_comment(self, data): self.output.append(f"<!--{data}-->")
    def handle_decl(self, decl): self.output.append(f"<!{decl}>")
    def handle_pi(self, data): self.output.append(f"<?{data}>")


def sanitize_snapshot(html: str) -> str:
    sanitizer = SnapshotSanitizer()
    sanitizer.feed(html)
    sanitizer.close()
    return "".join(sanitizer.output)


class PreviewHTML(HTMLParser):
    def __init__(self, builder: "Builder", route: str) -> None:
        super().__init__(convert_charrefs=False)
        self.builder, self.route = builder, route
        self.output: list[str] = []

    def tag(self, tag: str, attrs: list[tuple[str, str | None]], closed: bool) -> None:
        values = dict(attrs)
        if tag == "meta" and values.get("name", "").lower() == "robots":
            return
        updated = []
        for name, value in attrs:
            if value is not None:
                if name in {"src", "poster"}:
                    value = self.builder.url(value, self.route, resource=True)
                elif name == "href" and tag == "a":
                    value = self.builder.url(value, self.route)
                elif name == "href" and tag == "link":
                    relation = values.get("rel", "").lower().split()
                    if "canonical" not in relation:
                        value = self.builder.url(value, self.route, resource=True)
                elif name == "srcset":
                    value = self.builder.srcset(value, self.route)
                elif name == "style":
                    value = self.builder.css(value, self.route)
                elif tag == "form" and name == "action":
                    value = "#"
            updated.append((name, value))
        if tag == "form":
            if "action" not in values:
                updated.append(("action", "#"))
            updated.append(("data-portfolio-demo", "true"))
        self.output.append(serialize_tag(tag, updated, closed))
        if tag == "head":
            self.output.append(self.builder.head_guard())
        elif tag == "body":
            self.output.append(self.builder.banner(self.route))
        elif tag == "form":
            self.output.append(self.builder.form_note(self.route))

    def handle_starttag(self, tag, attrs): self.tag(tag, attrs, False)
    def handle_startendtag(self, tag, attrs): self.tag(tag, attrs, True)
    def handle_endtag(self, tag): self.output.append(f"</{tag}>")
    def handle_data(self, data): self.output.append(data)
    def handle_entityref(self, name): self.output.append(f"&{name};")
    def handle_charref(self, name): self.output.append(f"&#{name};")
    def handle_comment(self, data): self.output.append(f"<!--{data}-->")
    def handle_decl(self, decl): self.output.append(f"<!{decl}>")
    def handle_pi(self, data): self.output.append(f"<?{data}>")


class Builder:
    def __init__(self, source_root: Path, output: Path, origin: str, base: str,
                 routes: list[str], allow_live_assets: bool = False) -> None:
        self.source_root, self.output = source_root, output
        self.origin, self.host = origin.rstrip("/"), urlsplit(origin).netloc
        self.base = "/" + base.strip("/") + "/" if base.strip("/") else "/"
        self.routes = set(routes)
        self.allow_live_assets = allow_live_assets
        self.missing_assets: set[str] = set()
        self.referenced_assets: set[str] = set()

    def url(self, raw: str, route: str, resource: bool = False) -> str:
        if not raw or raw.startswith(("#", "data:", "blob:", "mailto:", "tel:", "javascript:")):
            return raw
        url = urlsplit(urljoin(self.origin + route, raw))
        if url.scheme not in {"http", "https"} or url.netloc != self.host:
            return raw
        path = unquote(url.path)
        if ".." in Path(path).parts:
            raise RuntimeError(f"Unsafe source URL path: {path}")
        if path == "/index.php":
            path = "/"
        file_path = self.source_root / path.lstrip("/")
        is_asset = file_path.is_file() and (
            file_path.suffix.lower() in STATIC_SUFFIXES or file_path.name == "manifest.json"
        )
        if is_asset:
            self.referenced_assets.add(path.lstrip("/"))
        elif resource:
            self.missing_assets.add(path)
            return urlunsplit(("https", self.host, url.path, url.query, url.fragment))
        elif path not in self.routes:
            return urlunsplit(("https", self.host, url.path, url.query, url.fragment))
        rewritten = self.base + path.lstrip("/")
        return urlunsplit(("", "", rewritten, url.query, url.fragment))

    def srcset(self, value: str, route: str) -> str:
        if value.strip().startswith("data:"):
            return value
        items = []
        for item in value.split(","):
            bits = item.strip().split()
            if bits:
                bits[0] = self.url(bits[0], route, resource=True)
            items.append(" ".join(bits))
        return ", ".join(items)

    def css(self, text: str, route: str) -> str:
        return CSS_URL_RE.sub(
            lambda match: f'url("{self.url(match["url"].strip(), route, resource=True)}")', text
        )

    def javascript(self, text: str, route: str) -> str:
        def rewrite(match):
            raw = match["url"]
            path = unquote(urlsplit(raw).path)
            # Root-relative route constants drive the site's UTM-link handler.
            # Avoid rewriting unrelated JS strings such as regular expressions.
            if path not in self.routes and path != "/index.php":
                file = self.source_root / path.lstrip("/")
                if not file.is_file() or file.suffix.lower() not in STATIC_SUFFIXES:
                    return match[0]
            return match["quote"] + self.url(raw, route) + match["quote"]
        return QUOTED_ROOT_URL_RE.sub(rewrite, text)

    def head_guard(self) -> str:
        # This executes before production scripts add their submit listeners.
        # CSP also blocks native POST if JavaScript is unavailable.
        return '''
<meta name="robots" content="noindex, nofollow, noarchive">
<meta http-equiv="Content-Security-Policy" content="form-action 'none'; connect-src 'none'">
<script id="portfolio-demo-guard">
window.ym = function () {};
window.dataLayer = [];
document.addEventListener('submit', function (event) {
  event.preventDefault();
  event.stopImmediatePropagation();
  var note = event.target.querySelector('.portfolio-demo-form-note');
  if (note) { note.setAttribute('role', 'alert'); note.focus(); }
}, true);
</script>
<style id="portfolio-demo-style">
.portfolio-demo-note{position:fixed;z-index:2147483647;left:12px;bottom:12px;max-width:calc(100vw - 24px);padding:9px 13px;background:#171717;color:#fff;border:1px solid #777;border-radius:8px;box-shadow:0 4px 18px #0006;font:13px/1.4 system-ui,sans-serif}
.portfolio-demo-note a,.portfolio-demo-form-note a{color:#ff6b6b;text-decoration:underline}
.portfolio-demo-form-note{font:13px/1.5 system-ui,sans-serif;color:#bdbdbd;margin:0 0 12px}
@media(max-width:600px){.portfolio-demo-note{font-size:11px;padding:6px 9px;bottom:6px;left:6px;max-width:calc(100vw - 12px)}}
</style>
'''

    def banner(self, route: str) -> str:
        url = escape(self.origin + route, quote=True)
        return f'<aside class="portfolio-demo-note" aria-label="Демонстрационная версия">Демо проекта · <a href="{url}" target="_blank" rel="noopener noreferrer">Рабочий сайт avtokai.ru</a></aside>'

    def form_note(self, route: str) -> str:
        url = escape(self.origin + route, quote=True)
        return f'<p class="portfolio-demo-form-note" tabindex="-1">Демонстрационная форма: заявки здесь не отправляются. <a href="{url}" target="_blank" rel="noopener noreferrer">Оценить автомобиль на рабочем сайте</a>.</p>'

    def page(self, html: str, route: str) -> str:
        placeholders: dict[str, str] = {}

        def scripts(match):
            attrs, body = match["attrs"], match["body"]
            combined = (attrs + body).lower()
            if "callibri.ru" in combined or "mc.yandex.ru/metrika" in combined:
                return ""
            if "application/ld+json" not in attrs.lower():
                body = self.javascript(body, route)
            token = f"AVTOKAI_PREVIEW_SCRIPT_{len(placeholders)}_PLACEHOLDER"
            placeholders[token] = "<script" + attrs + ">" + body + "</script>"
            return token

        # Keep JavaScript raw while the HTML parser rewrites actual attributes.
        html = SCRIPT_RE.sub(scripts, sanitize_snapshot(html))
        html = re.sub(r"<noscript\b[^>]*>[\s\S]*?</noscript\s*>",
                      lambda m: "" if "mc.yandex.ru" in m[0].lower() else m[0], html, flags=re.I)
        # CSS blocks need the same base-path correction as style attributes.
        html = re.sub(r"(<style\b[^>]*>)([\s\S]*?)(</style\s*>)",
                      lambda m: m[1] + self.css(m[2], route) + m[3], html, flags=re.I)
        parser = PreviewHTML(self, route)
        parser.feed(html)
        parser.close()
        result = "".join(parser.output)
        for token, script in placeholders.items():
            result = result.replace(token, script)
        return result

    def copy_assets(self) -> int:
        count = 0
        for path in sorted(self.source_root.rglob("*")):
            if not path.is_file() or path.is_symlink():
                continue
            relative = path.relative_to(self.source_root)
            if any(part in EXCLUDED_DIRS or part.startswith(".") for part in relative.parts[:-1]):
                continue
            if path.suffix.lower() not in STATIC_SUFFIXES and path.name != "manifest.json":
                continue
            target = self.output / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            if path.suffix.lower() == ".css" and relative.as_posix() in self.referenced_assets:
                target.write_text(self.css(path.read_text(encoding="utf-8"), "/" + relative.as_posix()), encoding="utf-8")
            elif path.suffix.lower() == ".js":
                target.write_text(self.javascript(path.read_text(encoding="utf-8"), "/" + relative.as_posix()), encoding="utf-8")
            else:
                shutil.copyfile(path, target)
            count += 1
        return count


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-root", type=Path, default=Path.cwd())
    parser.add_argument("--output", type=Path, default=Path("dist-preview"))
    parser.add_argument("--snapshots", type=Path, default=Path("preview-source.zip"))
    parser.add_argument("--origin", default=ORIGIN)
    parser.add_argument("--base-path", default=DEFAULT_BASE)
    parser.add_argument("--capture", action="store_true", help="Capture sanitized public HTML into --snapshots and exit")
    parser.add_argument("--allow-live-assets", action="store_true", help="Allow missing media to use production URLs")
    args = parser.parse_args()
    source_root, output, snapshots = (path.resolve() for path in (args.source_root, args.output, args.snapshots))
    if not source_root.is_dir():
        parser.error("--source-root must exist")
    if output == source_root or output in source_root.parents or output == snapshots or output in snapshots.parents:
        parser.error("--output must be a disposable directory separate from source and snapshots")
    origin = args.origin.rstrip("/")
    if urlsplit(origin).scheme != "https" or urlsplit(origin).path:
        parser.error("--origin must be an HTTPS origin without a path")
    if args.capture:
        routes = site_routes(origin)
        snapshots.parent.mkdir(parents=True, exist_ok=True)

        def snapshot(route: str):
            html = sanitize_snapshot(read_url(origin + route))
            name = (Path(route.lstrip("/")) / "index.html").as_posix()
            return name, html

        with ThreadPoolExecutor(max_workers=4) as pool:
            captures = list(pool.map(snapshot, routes))
        with ZipFile(snapshots, "w", compression=ZIP_DEFLATED, compresslevel=9) as archive:
            archive.writestr("routes.json", json.dumps(routes, ensure_ascii=False, indent=2) + "\n")
            for name, html in captures:
                archive.writestr(name, html)
        print(json.dumps({"captured_pages": len(routes), "archive": str(snapshots),
                          "bytes": snapshots.stat().st_size}, ensure_ascii=False))
        return
    if not snapshots.is_file():
        parser.error("Snapshot archive is missing; run once with --capture")
    with ZipFile(snapshots) as archive:
        routes = json.loads(archive.read("routes.json"))
    if not isinstance(routes, list) or "/" not in routes or any(
        not isinstance(route, str) or not route.startswith("/") or ".." in Path(route).parts
        for route in routes
    ):
        raise RuntimeError("Invalid routes snapshot")
    if output.exists():
        shutil.rmtree(output)
    output.mkdir(parents=True)
    builder = Builder(source_root, output, origin, args.base_path, routes, args.allow_live_assets)
    with ZipFile(snapshots) as archive:
        for route in routes:
            name = (Path(route.lstrip("/")) / "index.html").as_posix()
            target = output / route.lstrip("/") / "index.html"
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(builder.page(archive.read(name).decode("utf-8"), route), encoding="utf-8")
    asset_count = builder.copy_assets()
    if builder.missing_assets and not builder.allow_live_assets:
        missing = "\n".join(sorted(builder.missing_assets))
        raise RuntimeError("Missing public assets in --source-root:\n" + missing)
    (output / ".nojekyll").write_text("", encoding="utf-8")
    (output / "robots.txt").write_text("User-agent: *\nDisallow: /\n", encoding="utf-8")
    manifest = {
        "source": origin, "base_path": builder.base, "pages": routes,
        "static_asset_files": asset_count,
        "referenced_assets": sorted(builder.referenced_assets),
        "live_asset_fallbacks": sorted(builder.missing_assets),
        "forms": "disabled", "analytics": "disabled", "indexing": "noindex",
    }
    (output / "preview-build.json").write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"pages": len(routes), "assets": asset_count, "output": str(output),
                      "live_asset_fallbacks": len(builder.missing_assets)}, ensure_ascii=False))


if __name__ == "__main__":
    main()
