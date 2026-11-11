#!/usr/bin/env python3
"""Run an explicit command in the verified consumer checkout; never locate by dirname."""
import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys

BASELINE = "f8828dc8d43fb14b8389daf10e1f81fd2e5ca62f"
ROOT = Path(__file__).resolve().parents[1]


def require(condition, message):
    if not condition:
        raise ValueError(message)


def resolve_root():
    explicit = os.environ.get("PROXYMA_AGELTO_ROOT")
    if explicit:
        path = Path(explicit).expanduser()
        require(path.is_absolute(), "PROXYMA_AGELTO_ROOT must be absolute")
        return path.resolve(strict=True)
    result = subprocess.run(["maind", "projects", "list", "--json"], capture_output=True, text=True)
    require(result.returncode == 0, "cannot read maind binding; set PROXYMA_AGELTO_ROOT")
    # maind's project importer records local_path/id and leaves the GitHub slug null.
    # The selected checkout still must pass verify(), including its actual origin.
    matches = [p for p in json.loads(result.stdout)
               if (p.get("repo") == "axiomasoft/agelto"
                   or (p.get("id") == "agelto" and p.get("repo") is None))
               and p.get("enabled", True) and p.get("status", "active") == "active"]
    require(len(matches) == 1, "need one maind Agelto binding or PROXYMA_AGELTO_ROOT")
    path = Path(matches[0]["local_path"]).expanduser()
    require(path.is_absolute(), "maind binding must be absolute")
    return path.resolve(strict=True)


def git(root, *args):
    result = subprocess.run(["git", "-C", str(root), *args], capture_output=True, text=True)
    require(result.returncode == 0, "consumer Git verification failed")
    return result.stdout.rstrip("\n")


def verify(root, baseline):
    require(re.fullmatch(r"[0-9a-f]{40}", baseline) is not None and baseline != "0" * 40, "expected baseline must be full SHA")
    require(Path(git(root, "rev-parse", "--show-toplevel")).resolve() == root, "consumer path must be checkout root")
    origins = {f"{prefix}axiomasoft/agelto{suffix}" for prefix in
               ("https://github.com/", "git@github.com:", "ssh://git@github.com/") for suffix in ("", ".git")}
    require(git(root, "remote", "get-url", "origin") in origins, "consumer origin must be axiomasoft/agelto")
    require(git(root, "rev-parse", "HEAD") == baseline, "consumer HEAD differs from expected baseline")
    artifact = json.loads((ROOT / "integration/agelto/vendor-changes.json").read_text())
    allowed = set(artifact["external_changed_paths"])
    adapter_path = ROOT / "integration/agelto/adapter-changes.json"
    if adapter_path.is_file():
        adapters = json.loads(adapter_path.read_text())
        require(adapters.get("schema") == "proxyma-agelto-adapter-changes/v1"
                and adapters.get("agelto_head") == baseline
                and Path(adapters.get("external_root", "")).resolve() == root,
                "adapter changes require the same checkout and expected baseline")
        allowed.update(adapters["external_changed_paths"])
    dirty = git(root, "status", "--porcelain=v1", "--untracked-files=all", "-z")
    # Rename records include two names; rejecting them prevents an unchecked source path.
    for record in dirty.split("\0"):
        if not record:
            continue
        require(len(record) >= 4 and not {"R", "C"}.intersection(record[:2])
                and record[3:] in allowed, "consumer has changes outside the declared integration paths")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    run_parser = parser.add_subparsers(dest="action", required=True).add_parser("run")
    run_parser.add_argument("--expected-head", default=os.environ.get("PROXYMA_AGELTO_BASELINE", BASELINE))
    run_parser.add_argument("command", nargs=argparse.REMAINDER)
    args = parser.parse_args()
    command = args.command
    if command and command[0] == "--":
        command = command[1:]
    require(bool(command), "supply a command after --")
    root = resolve_root()
    verify(root, args.expected_head)
    print(f"Agelto cwd: {root}\nAgelto source: {args.expected_head}", flush=True)
    return subprocess.run(command, cwd=root).returncode


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (ValueError, OSError, KeyError) as error:
        print(f"Agelto launcher refused: {error}", file=sys.stderr)
        sys.exit(1)
