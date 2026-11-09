"""Create and verify a complete Nasbia source handover; never deploy or run code.

Python 3.10+ standard library only. Dependencies and frontend assets are built
from the committed lock files by the recipient, never copied from Windows.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import shutil
import stat
import subprocess
import zipfile
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo

ROOT_FILES = {
    ".editorconfig", ".env.example", ".env.testing.example", ".gitattributes",
    ".gitignore", ".npmrc", "artisan", "composer.json", "composer.lock",
    "package.json", "package-lock.json", "phpunit.xml", "README.md", "vite.config.js",
    "start-local-router.php", "start-local.ps1", "start-local.cmd",
    "run-tests.ps1", "run-tests.cmd", "audit-pwa.ps1", "audit-pwa.cmd", "index.html",
}
SOURCE_DIRS = {"app", "bootstrap", "config", "database", "public", "resources", "routes", "tests", "scripts"}
REFERENCE_DOCS = {"docs/administrative-expansion.md", "docs/library-file-recovery.md", "docs/smart-tutor-gemini.md", "docs/student-level-analysis.md", "docs/parent-pwa-qa.md"}
EXCLUSIONS = [
    ".git, .vs, IDE metadata and previous output/ archives",
    "nasr-al-daem-school/** (separate application; no runtime dependency found)",
    ".env and real credentials, account JSON, private keys and auth.json",
    "vendor/** and node_modules/**; install exact lock files on the target OS",
    "public/build/** and public/hot; frontend build is deliberately not shipped",
    "public/storage/**; storage/** except empty-directory .gitignore scaffolding",
    "database files/dumps, tmp/**, uploads, sessions, logs and generated caches",
    "nds-*.sql, deploy/digitalocean/*nds*, tatus, tash list (other-school/manual operational artifacts)",
    "original QA reports and unrelated historical documents outside docs/qa and docs/deployment",
    "old delta-only CHANGESET_SHA256.txt and REVIEW_PACKAGE.md; current full-package manifest replaces their delivery role",
]
DOC_COPIES = {
    "START_HERE_AR.md": "docs/deployment/START_HERE_AR.md",
    "DIGITALOCEAN_HANDOFF_AR.md": "docs/deployment/DIGITALOCEAN_HANDOFF_AR.md",
    "ENV_CHANGES.example": "docs/deployment/ENV_CHANGES.example",
    "QA_CLOSEOUT_AR.md": "docs/qa/QA_EXECUTION_STATUS.md",
    "TEST_EVIDENCE.md": "docs/qa/TEST_EVIDENCE.md",
    "DEVICE_STAGING_ACCEPTANCE_AR.md": "docs/qa/DEVICE_STAGING_ACCEPTANCE_AR.md",
    "RELEASE_READINESS.md": "docs/qa/RELEASE_READINESS.md",
    "MESSAGE_TO_HOSTING_AR.txt": "docs/deployment/MESSAGE_TO_HOSTING_AR.txt",
}


def sha(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def safe_relative_name(name: str, *, directory: bool = False) -> str:
    """Use one portable path spelling; reject Windows aliases and ADS as well."""
    if not isinstance(name, str) or not name or "\\" in name or "\x00" in name:
        raise ValueError("Invalid package path")
    if directory and name.endswith("/"):
        name = name[:-1]
    parts = name.split("/")
    reserved = {"CON", "PRN", "AUX", "NUL"} | {f"{prefix}{number}" for prefix in ("COM", "LPT") for number in range(1, 10)}
    if any(part in {"", ".", ".."} or part.endswith((".", " "))
           or re.search(r'[<>:"|?*\x00-\x1f]', part)
           or part.split(".", 1)[0].upper() in reserved for part in parts):
        raise ValueError(f"Unsafe package path: {name}")
    return name


def contained_file(stage: Path, name: str) -> Path:
    name = safe_relative_name(name)
    path = stage / name
    if not path.resolve().is_relative_to(stage.resolve()) or path.is_symlink():
        raise ValueError(f"Path escapes package: {name}")
    return path


def collect(root: Path) -> list[Path]:
    selected = [root / name for name in ROOT_FILES if (root / name).is_file()]
    for directory in sorted(SOURCE_DIRS | {"docs", "storage", "deploy"}):
        base = root / directory
        if not base.exists():
            continue
        # Explicit traversal: never descend into a linked upload directory.
        pending = [base]
        while pending:
            folder = pending.pop()
            for path in folder.iterdir():
                rel = path.relative_to(root).as_posix()
                if rel in {"public/build", "public/storage", "public/hot"}:
                    continue
                if path.is_symlink():
                    raise ValueError(f"Linked source path needs explicit review: {rel}")
                if directory == "docs" and not (rel in REFERENCE_DOCS or rel in {"docs/qa", "docs/deployment"} or rel.startswith(("docs/qa/", "docs/deployment/"))):
                    continue
                if rel in {"docs/deployment/CHANGESET_SHA256.txt", "docs/deployment/REVIEW_PACKAGE.md"}:
                    continue
                if directory == "deploy" and ("nds" in path.name.lower() or path.suffix.lower() == ".sql"):
                    continue
                if path.is_dir():
                    if path.name in {"__pycache__", ".pytest_cache", ".phpunit.cache"}:
                        continue
                    pending.append(path)
                    continue
                if directory == "storage" and path.name != ".gitignore":
                    continue
                if rel.startswith("bootstrap/cache/") and path.name != ".gitignore":
                    continue
                if rel == "public/fonts-manifest.dev.json":
                    continue
                if directory == "database" and path.suffix.lower() in {".sqlite", ".sqlite3", ".db"}:
                    continue
                if path.suffix.lower() in {".sqlite", ".sqlite3", ".db", ".log", ".key", ".pem", ".p12", ".pfx"} or path.name in {".env", "auth.json"}:
                    raise ValueError(f"Sensitive/generated file in selected source: {rel}")
                selected.append(path)
    selected = sorted(set(selected), key=lambda path: path.relative_to(root).as_posix())
    names = [p.relative_to(root).as_posix() for p in selected]
    if len({name.casefold() for name in names}) != len(names):
        raise ValueError("Case-insensitive source path collision")
    for required in ("artisan", "composer.lock", "package-lock.json", "public/index.php", "bootstrap/app.php"):
        if required not in names:
            raise ValueError(f"Required source file missing: {required}")
    return selected


def identity(root: Path, files: list[Path]) -> str:
    # Evidence documents contain this digest; exclude docs to avoid self-reference.
    records = [f"{p.relative_to(root).as_posix()}\0{sha(p)}\n" for p in files if not p.relative_to(root).as_posix().startswith("docs/")]
    return hashlib.sha256("".join(records).encode()).hexdigest()


def scan(files: list[Path], root: Path) -> dict:
    findings = []
    scanned = 0
    patterns = {
        "private_key_material": re.compile(r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----"),
        "google_api_key_shape": re.compile(r"AIza[0-9A-Za-z_-]{35}"),
        "github_token_shape": re.compile(r"gh[pousr]_[A-Za-z0-9]{30,}"),
        "aws_access_key_shape": re.compile(r"AKIA[0-9A-Z]{16}"),
    }
    for path in files:
        try:
            content = path.read_text(encoding="utf-8-sig")
        except (UnicodeError, OSError):
            continue
        scanned += 1
        for name, pattern in patterns.items():
            if pattern.search(content):
                findings.append({"path": path.relative_to(root).as_posix(), "rule": name})
        if path.suffix == ".json":
            try:
                data = json.loads(content)
            except json.JSONDecodeError:
                continue
            if isinstance(data, dict) and data.get("type") == "service_account" and data.get("private_key"):
                findings.append({"path": path.relative_to(root).as_posix(), "rule": "service_account_credentials"})
    if findings:
        raise ValueError("Secret-shaped material requires review (values not printed): " + json.dumps(findings))
    return {"text_files_scanned": scanned, "findings": [], "limits": "Pattern/file-policy scan, not proof that arbitrary personal data can be recognized. Source is allowlisted; evidence is synthetic."}


def build(root: Path, destination: Path) -> dict:
    files = collect(root)
    source_id = identity(root, files)
    for required in DOC_COPIES.values():
        if not (root / required).is_file():
            raise ValueError(f"Handover document missing: {required}")
    source_scan = scan(files, root)
    created = datetime.now(ZoneInfo("Africa/Tripoli"))
    stage = destination / f"{created:%Y%m%d-%H%M%S}-nasbia-{source_id[:12]}"
    stage.mkdir(parents=True, exist_ok=False)
    for path in files:
        target = stage / "source" / path.relative_to(root)
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(path, target)
    for name, original in DOC_COPIES.items():
        if name in {"QA_CLOSEOUT_AR.md", "TEST_EVIDENCE.md", "RELEASE_READINESS.md", "DEVICE_STAGING_ACCEPTANCE_AR.md"}:
            text = (root / original).read_text(encoding="utf-8-sig")
            def relocate_link(match):
                label, target = match.groups()
                if ":" in target or target.startswith(("#", "/")):
                    return match.group(0)
                path, separator, anchor = target.partition("#")
                resolved = (root / original).parent.joinpath(path).resolve()
                if not resolved.is_relative_to(root):
                    return match.group(0)
                return f"[{label}](source/{resolved.relative_to(root).as_posix()}{separator}{anchor})"
            text = re.sub(r"\[([^\]]+)\]\(([^)]+)\)", relocate_link, text)
            (stage / name).write_text(text, encoding="utf-8")
        else:
            shutil.copy2(root / original, stage / name)
    entries = []
    for path in sorted(stage.rglob("*")):
        if path.is_file():
            entries.append({"path": path.relative_to(stage).as_posix(), "bytes": path.stat().st_size, "sha256": sha(path)})
    head = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=root, text=True).strip()
    branch = subprocess.check_output(["git", "branch", "--show-current"], cwd=root, text=True).strip()
    manifest = {
        "schema_version": 1, "school": "مدرسة النسبية", "scope": "repository root only",
        "created_at": created.isoformat(), "base_head": head, "branch": branch,
        "uncommitted_changes_included": True, "source_id": source_id,
        "source_id_definition": "SHA256 of sorted relative-path NUL file-sha256 LF for source files except docs/**; documentation evidence is separately covered by file manifest",
        "frontend_strategy": "Source only; build on recipient OS from package-lock.json with approved VITE values. public/build is excluded.",
        "exclusions": EXCLUSIONS,
        "self_reference_exclusions": ["RELEASE_MANIFEST.json", "SHA256SUMS.txt", "ZIP archive and its external .sha256"],
        "source_scan": source_scan, "files": entries,
    }
    manifest_path = stage / "RELEASE_MANIFEST.json"
    manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    sums = [f"{item['sha256']}  {item['path']}" for item in entries]
    sums.append(f"{sha(manifest_path)}  RELEASE_MANIFEST.json")
    (stage / "SHA256SUMS.txt").write_text("\n".join(sums) + "\n", encoding="utf-8")
    archive = stage.with_suffix(".zip")
    with zipfile.ZipFile(archive, "x", zipfile.ZIP_DEFLATED, compresslevel=7) as output:
        for path in sorted(stage.rglob("*")):
            if path.is_file():
                output.write(path, path.relative_to(stage).as_posix())
    archive_hash = sha(archive)
    archive.with_suffix(".zip.sha256").write_text(f"{archive_hash}  {archive.name}\n", encoding="utf-8")
    verify(stage)
    return {"stage": str(stage), "archive": str(archive), "archive_bytes": archive.stat().st_size, "archive_sha256": archive_hash, "source_id": source_id, "files": len(entries)}


def verify(stage: Path) -> dict:
    manifest = json.loads((stage / "RELEASE_MANIFEST.json").read_text(encoding="utf-8"))
    names = [safe_relative_name(item["path"]) for item in manifest["files"]]
    if len(names) != len({name.casefold() for name in names}):
        raise ValueError("Duplicate or case-colliding manifest paths")
    listed = set(names)
    if listed & {"RELEASE_MANIFEST.json", "SHA256SUMS.txt"}:
        raise ValueError("Manifest includes its own metadata")
    if any(path.is_symlink() for path in stage.rglob("*")):
        raise ValueError("Linked package entries are not permitted")
    actual = {p.relative_to(stage).as_posix() for p in stage.rglob("*") if p.is_file()}
    if len(actual) != len({name.casefold() for name in actual}):
        raise ValueError("Case-colliding extracted paths")
    if actual != listed | {"RELEASE_MANIFEST.json", "SHA256SUMS.txt"}:
        raise ValueError("Package file list differs from its manifest")
    for item in manifest["files"]:
        path = contained_file(stage, item["path"])
        if path.stat().st_size != item["bytes"] or sha(path) != item["sha256"]:
            raise ValueError(f"Manifest mismatch: {item['path']}")
    checksums = {}
    seen = set()
    for line in (stage / "SHA256SUMS.txt").read_text(encoding="utf-8").splitlines():
        expected, name = line.split("  ", 1)
        path = contained_file(stage, name)
        if name.casefold() in seen or not re.fullmatch(r"[0-9a-f]{64}", expected):
            raise ValueError("Duplicate checksum path or invalid digest")
        seen.add(name.casefold())
        checksums[name] = expected
        if sha(path) != expected:
            raise ValueError(f"Checksum mismatch: {name}")
    if set(checksums) != listed | {"RELEASE_MANIFEST.json"}:
        raise ValueError("Checksum coverage differs from package contents")
    source_root = stage / "source"
    source_files = sorted((stage / name for name in listed if name.startswith("source/")),
                          key=lambda path: path.relative_to(source_root).as_posix())
    if not source_files or identity(source_root, source_files) != manifest["source_id"]:
        raise ValueError("Source identity does not match packaged files")
    scan([stage / item["path"] for item in manifest["files"]], stage)
    return {"verified_files": len(listed), "source_id": manifest["source_id"]}


def extract(archive: Path, destination: Path) -> dict:
    if destination.exists():
        raise ValueError("Extraction destination must be new")
    with zipfile.ZipFile(archive) as source:
        names = []
        for info in source.infolist():
            if stat.S_ISLNK(info.external_attr >> 16):
                raise ValueError("ZIP symbolic links are not permitted")
            names.append(safe_relative_name(info.filename, directory=info.is_dir()))
        if len(names) != len({name.casefold() for name in names}):
            raise ValueError("Duplicate or case-colliding ZIP entries")
        for name in names:
            if not (destination / name).resolve().is_relative_to(destination.resolve()):
                raise ValueError("Unsafe ZIP path")
        destination.mkdir(parents=True)
        source.extractall(destination)
    return verify(destination)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument("--output", type=Path)
    parser.add_argument("--verify", type=Path)
    parser.add_argument("--extract", type=Path)
    parser.add_argument("--destination", type=Path)
    parser.add_argument("--inventory", action="store_true")
    args = parser.parse_args()
    if args.verify:
        result = verify(args.verify.resolve())
    elif args.extract:
        if not args.destination:
            parser.error("--extract requires --destination")
        result = extract(args.extract.resolve(), args.destination.resolve())
    elif args.inventory:
        selected = collect(args.root.resolve())
        result = {"files": len(selected), "source_id": identity(args.root.resolve(), selected), "bytes": sum(p.stat().st_size for p in selected), "scan": scan(selected, args.root.resolve())}
    else:
        result = build(args.root.resolve(), (args.output or args.root / "output/final-handover").resolve())
    print(json.dumps(result, ensure_ascii=True))
