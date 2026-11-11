"""Launcher negatives and the owning consumer's isolated vendor/rig contract."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("agelto_launcher", ROOT / "scripts/agelto.py")
launcher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(launcher)


class AgeltoLauncherTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.git("init", "--template=")
        self.git("remote", "add", "origin", "https://github.com/axiomasoft/agelto.git")
        (self.root / "allowed.txt").write_text("baseline")
        self.git("add", "--", "allowed.txt")
        self.git("-c", "user.name=Fixture", "-c", "user.email=fixture@example.invalid", "commit", "-qm", "fixture")
        self.head = self.git("rev-parse", "HEAD")
        artifact = self.root / "integration/agelto/vendor-changes.json"
        artifact.parent.mkdir(parents=True)
        artifact.write_text(json.dumps({"external_changed_paths": ["allowed.txt", "integration/agelto/vendor-changes.json"]}))

    def git(self, *args):
        return subprocess.check_output(["git", "-C", str(self.root), *args], stderr=subprocess.PIPE, text=True).strip()

    def test_explicit_root_and_unique_maind_binding_without_directory_guessing(self):
        with patch.dict(os.environ, {"PROXYMA_AGELTO_ROOT": str(self.root)}):
            self.assertEqual(launcher.resolve_root(), self.root)
        with patch.dict(os.environ, {"PROXYMA_AGELTO_ROOT": "relative"}):
            with self.assertRaises(ValueError):
                launcher.resolve_root()
        for rows in ([], [{"repo": "axiomasoft/agelto", "local_path": str(self.root)}] * 2):
            with patch.dict(os.environ, {"PROXYMA_AGELTO_ROOT": ""}), patch.object(launcher.subprocess, "run",
                    return_value=subprocess.CompletedProcess([], 0, json.dumps(rows), "")):
                with self.assertRaises(ValueError):
                    launcher.resolve_root()
        with patch.dict(os.environ, {"PROXYMA_AGELTO_ROOT": ""}), patch.object(launcher.subprocess, "run",
                return_value=subprocess.CompletedProcess([], 0, json.dumps([
                    {"repo": "axiomasoft/agelto", "local_path": str(self.root)}]), "")):
            self.assertEqual(launcher.resolve_root(), self.root)

    def test_expected_head_origin_and_declared_diff_are_checked_without_reset(self):
        with patch.object(launcher, "ROOT", self.root):
            launcher.verify(self.root, self.head)
            (self.root / "allowed.txt").write_text("authorized change")
            launcher.verify(self.root, self.head)
            (self.root / "foreign.txt").write_text("unrelated work")
            with self.assertRaisesRegex(ValueError, "outside"):
                launcher.verify(self.root, self.head)
            self.assertEqual((self.root / "foreign.txt").read_text(), "unrelated work")
            (self.root / "foreign.txt").unlink()
            with self.assertRaisesRegex(ValueError, "baseline"):
                launcher.verify(self.root, "a" * 40)
            self.git("remote", "set-url", "origin", "https://token:secret@github.com/axiomasoft/agelto.git")
            with self.assertRaisesRegex(ValueError, "origin") as error:
                launcher.verify(self.root, self.head)
            self.assertNotIn("token:secret", str(error.exception))

    def test_imported_maind_binding_resolves_by_declared_id_and_still_checks_origin(self):
        binding = {"id": "agelto", "repo": None, "local_path": str(self.root), "enabled": True, "status": "active"}
        completed = subprocess.CompletedProcess([], 0, json.dumps([binding]), "")
        with patch.dict(os.environ, {"PROXYMA_AGELTO_ROOT": ""}), \
                patch.object(launcher.subprocess, "run", return_value=completed):
            self.assertEqual(launcher.resolve_root(), self.root)
        with patch.object(launcher, "ROOT", self.root):
            launcher.verify(self.root, self.head)
            self.git("remote", "set-url", "origin", "https://github.com/other/agelto.git")
            with self.assertRaisesRegex(ValueError, "origin"):
                launcher.verify(self.root, self.head)

    def test_imported_maind_binding_rejects_conflict_disabled_inactive_and_duplicates(self):
        binding = {"id": "agelto", "repo": None, "local_path": str(self.root)}
        for rows in ([{**binding, "repo": "other/agelto"}], [{**binding, "enabled": False}],
                     [{**binding, "status": "inactive"}], [binding, dict(binding)],
                     [{**binding, "id": "unrelated"}]):
            with self.subTest(rows=rows), patch.dict(os.environ, {"PROXYMA_AGELTO_ROOT": ""}), \
                    patch.object(launcher.subprocess, "run", return_value=subprocess.CompletedProcess([], 0, json.dumps(rows), "")):
                with self.assertRaises(ValueError):
                    launcher.resolve_root()

    def test_cli_delegates_exact_argv_cwd_and_exit_after_verification(self):
        command = [sys.executable, str(ROOT / "scripts/agelto.py"), "run", "--expected-head", self.head, "--",
                   sys.executable, "-c", "import os,sys; print(os.getcwd()); sys.exit(7)"]
        # Point the launcher artifact at a real allowed integration tree using a local copy.
        script = self.root / "scripts/agelto.py"
        script.parent.mkdir()
        script.write_bytes((ROOT / "scripts/agelto.py").read_bytes())
        artifact = self.root / "integration/agelto/vendor-changes.json"
        value = json.loads(artifact.read_text())
        value["external_changed_paths"].append("scripts/agelto.py")
        artifact.write_text(json.dumps(value))
        command[1] = str(script)
        result = subprocess.run(command, capture_output=True, text=True,
                                env={**os.environ, "PROXYMA_AGELTO_ROOT": str(self.root)})
        self.assertEqual(result.returncode, 7, result.stderr)
        self.assertIn("Agelto cwd: " + str(self.root), result.stdout)
        self.assertIn("Agelto source: " + self.head, result.stdout)

    def test_rename_with_secondary_modified_status_is_rejected(self):
        head, origin, status = self.head, "https://github.com/axiomasoft/agelto.git", "RM allowed.txt\0old.txt\0"
        with patch.object(launcher, "ROOT", self.root), patch.object(launcher, "git",
                side_effect=[str(self.root), origin, head, status]):
            with self.assertRaisesRegex(ValueError, "outside"):
                launcher.verify(self.root, self.head)

    def test_adapter_paths_require_matching_checkout_and_revision(self):
        path = self.root / "integration/agelto/adapter-changes.json"
        value = {"schema": "proxyma-agelto-adapter-changes/v1", "agelto_head": self.head,
                 "external_root": str(self.root), "external_changed_paths":
                 ["adapter.txt", "integration/agelto/adapter-changes.json"]}
        path.write_text(json.dumps(value))
        (self.root / "adapter.txt").write_text("adapter")
        with patch.object(launcher, "ROOT", self.root):
            launcher.verify(self.root, self.head)
            for field, invalid in (("agelto_head", "a" * 40), ("external_root", str(self.root / "other"))):
                path.write_text(json.dumps({**value, field: invalid}))
                with self.assertRaisesRegex(ValueError, "same checkout"):
                    launcher.verify(self.root, self.head)
            path.write_text(json.dumps(value))
            (self.root / "foreign.txt").write_text("outside authority")
            with self.assertRaisesRegex(ValueError, "outside"):
                launcher.verify(self.root, self.head)

    def test_consumer_vendor_and_snapshot_contract(self):
        consumer = launcher.resolve_root()
        command = [sys.executable, "-B", "-m", "unittest", "discover", "-s", "docker/tests/process-core", "-p", "test_rig.py", "-v"]
        result = subprocess.run(command, cwd=consumer, capture_output=True, text=True)
        log = ROOT / "integration/agelto/p91-rig-tests.log"
        log.write_text("cwd: " + str(consumer) + "\ncommand: " + " ".join(command) + "\nexit: " + str(result.returncode)
                       + "\n" + result.stdout + result.stderr)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_joint_provider_paths_require_matching_checkout_and_revision(self):
        path = self.root / "integration/agelto/llm-changes.json"
        value = {"schema": "proxyma-agelto-llm-changes/v1", "agelto_head": self.head,
                 "external_root": str(self.root), "external_changed_paths":
                 ["provider.txt", "integration/agelto/llm-changes.json"]}
        path.write_text(json.dumps(value))
        (self.root / "provider.txt").write_text("provider")
        with patch.object(launcher, "ROOT", self.root):
            launcher.verify(self.root, self.head)
            for field, invalid in (("agelto_head", "a" * 40), ("external_root", str(self.root / "other")),
                                   ("schema", "proxyma-agelto-adapter-changes/v1")):
                path.write_text(json.dumps({**value, field: invalid}))
                with self.assertRaisesRegex(ValueError, "same checkout"):
                    launcher.verify(self.root, self.head)

    def test_migration_paths_require_matching_checkout_revision_and_schema(self):
        path = self.root / "integration/agelto/migration-map.json"
        value = {"schema": "proxyma-agelto-migration-map/v1", "agelto_head": self.head,
                 "external_root": str(self.root), "external_changed_paths": ["transfer.php", "integration/agelto/migration-map.json"]}
        path.write_text(json.dumps(value))
        (self.root / "transfer.php").write_text("transfer")
        with patch.object(launcher, "ROOT", self.root):
            launcher.verify(self.root, self.head)
            for field, invalid in (("agelto_head", "a" * 40), ("external_root", str(self.root / "other")),
                                   ("schema", "proxyma-agelto-caller-map/v1")):
                path.write_text(json.dumps({**value, field: invalid}))
                with self.assertRaisesRegex(ValueError, "same checkout"):
                    launcher.verify(self.root, self.head)

    def test_cutover_paths_require_matching_checkout_revision_and_schema(self):
        path = self.root / "integration/agelto/cutover-evidence.json"
        value = {"schema": "proxyma-agelto-cutover-evidence/v1", "agelto_head": self.head,
                 "external_root": str(self.root), "external_changed_paths": ["cutover.php", "integration/agelto/cutover-evidence.json"]}
        path.write_text(json.dumps(value))
        (self.root / "cutover.php").write_text("cutover")
        with patch.object(launcher, "ROOT", self.root):
            launcher.verify(self.root, self.head)
            for field, invalid in (("agelto_head", "a" * 40), ("external_root", str(self.root / "other")),
                                   ("schema", "proxyma-agelto-migration-map/v1")):
                path.write_text(json.dumps({**value, field: invalid}))
                with self.assertRaisesRegex(ValueError, "same checkout"):
                    launcher.verify(self.root, self.head)

    def test_caller_map_paths_require_matching_checkout_revision_and_schema(self):
        path = self.root / "integration/agelto/caller-map.json"
        value = {"schema": "proxyma-agelto-caller-map/v1", "agelto_head": self.head,
                 "external_root": str(self.root), "external_changed_paths":
                 ["caller.php", "integration/agelto/caller-map.json"]}
        path.write_text(json.dumps(value))
        (self.root / "caller.php").write_text("caller fixture")
        with patch.object(launcher, "ROOT", self.root):
            launcher.verify(self.root, self.head)
            for field, invalid in (("agelto_head", "a" * 40), ("external_root", str(self.root / "other")),
                                   ("schema", "proxyma-agelto-llm-changes/v1")):
                path.write_text(json.dumps({**value, field: invalid}))
                with self.assertRaisesRegex(ValueError, "same checkout"):
                    launcher.verify(self.root, self.head)

    def test_ui_paths_require_matching_checkout_revision_and_schema(self):
        path = self.root / "integration/agelto/ui-and-provenance.json"
        value = {"schema": "proxyma-agelto-ui-and-provenance/v1", "agelto_head": self.head,
                 "external_root": str(self.root), "external_changed_paths":
                 ["ui.php", "integration/agelto/ui-and-provenance.json"]}
        path.write_text(json.dumps(value))
        (self.root / "ui.php").write_text("isolated UI fixture")
        with patch.object(launcher, "ROOT", self.root):
            launcher.verify(self.root, self.head)
            for field, invalid in (("agelto_head", "a" * 40), ("external_root", str(self.root / "other")),
                                   ("schema", "proxyma-agelto-caller-map/v1")):
                path.write_text(json.dumps({**value, field: invalid}))
                with self.assertRaisesRegex(ValueError, "same checkout"):
                    launcher.verify(self.root, self.head)


if __name__ == "__main__":
    unittest.main(verbosity=2)
