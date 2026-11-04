"""Exercise artifact cleanup and README preservation without GitHub mutations."""
import importlib.util
import base64
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("artifacts", ROOT / "benchmarks/artifacts.py")
artifacts = importlib.util.module_from_spec(spec)
spec.loader.exec_module(artifacts)


def item(identifier, **overrides):
    return {"id": identifier, "name": f"benchmark-{identifier}-1",
            "created_at": f"2026-11-{identifier:02}T12:00:00Z", "expired": False,
            "workflow_run": {"head_branch": "main"}, **overrides}


class ArtifactRetentionTests(unittest.TestCase):
    def test_keep_newest_three_and_preserve_unrelated_artifacts(self):
        data = [item(i) for i in range(1, 6)]
        data += [item(20, name="test-results"), item(21, name="benchmark-manual"),
                 item(22, workflow_run={"head_branch": "feature"}),
                 item(23, name="benchmark-23-1-extra")]
        self.assertEqual([2, 1], [a["id"] for a in artifacts.deletion_candidates(data, 3)])
        self.assertEqual([], artifacts.deletion_candidates([item(1)], 3))

    def test_expired_and_rerun_artifacts(self):
        data = [item(1, created_at="2026-12-01T00:00:00Z", name="benchmark-1-2"),
                item(2), item(3), item(4, expired=True)]
        self.assertEqual([4, 2], [a["id"] for a in artifacts.deletion_candidates(data, 2)])
        with self.assertRaises(ValueError):
            artifacts.deletion_candidates(data, 4)

    def test_cleanup_collects_all_pages_and_enforces_limit(self):
        data = [item(i) for i in range(1, 6)]
        deleted = []

        def fake_api(endpoint, method="GET", **kwargs):
            if method == "DELETE":
                deleted.append(int(endpoint.rsplit("/", 1)[1]))
                return None
            available = [a for a in data if a["id"] not in deleted]
            return [{"artifacts": available[:2]}, {"artifacts": available[2:]}]

        with patch.object(artifacts, "api", side_effect=fake_api):
            artifacts.prune(2)
        self.assertEqual([3, 2, 1], deleted)

    def test_missing_or_changed_ci_identity_cannot_modify_artifacts(self):
        with patch.dict("os.environ", {"GITHUB_TRIGGERING_ACTOR": "someone-else",
                                     "GITHUB_REPOSITORY": artifacts.REPOSITORY,
                                     "GITHUB_REF": "refs/heads/main"}, clear=True):
            with self.assertRaises(RuntimeError):
                artifacts.require_authorized_context()

    def test_readme_updates_preserve_surrounding_content_and_are_idempotent(self):
        original = f"User introduction\n{artifacts.START}\nOld data\n{artifacts.END}\nUser footer\n"
        result = artifacts.replace_snapshot(original, "New measurements")
        self.assertEqual(f"User introduction\n{artifacts.START}\nNew measurements\n{artifacts.END}\nUser footer\n", result)
        self.assertEqual(result, artifacts.replace_snapshot(result, "New measurements"))
        for invalid in ["no markers", original + artifacts.START, artifacts.END + artifacts.START]:
            with self.assertRaises(ValueError):
                artifacts.replace_snapshot(invalid, "data")

    def test_publication_retries_concurrent_readme_edits(self):
        sha = "a" * 40
        environment = {"GITHUB_SHA": sha, "GITHUB_RUN_ID": "12", "GITHUB_RUN_ATTEMPT": "1"}
        writes = []
        reads = []

        def fake_api(endpoint, method="GET", payload=None, **kwargs):
            if "/actions/artifacts/" in endpoint:
                return {"name": "benchmark-12-1", "expired": False, "workflow_run": {"id": 12}}
            if method == "PUT":
                writes.append(payload)
                if len(writes) == 1:
                    raise RuntimeError("sha mismatch (HTTP 409)")
                return {}
            reads.append(endpoint)
            footer = "Original footer" if len(reads) == 1 else "Concurrent user edit"
            text = f"Introduction\n{artifacts.START}\nOld\n{artifacts.END}\n{footer}"
            return {"sha": str(len(reads)), "content": base64.b64encode(text.encode()).decode()}

        with tempfile.TemporaryDirectory() as name:
            directory = Path(name)
            (directory / "summary.json").write_text(json.dumps({
                "repository": artifacts.REPOSITORY, "commit": sha, "artifact_name": "benchmark-12-1"}))
            (directory / "readme.md").write_text("Verified measurements")
            with patch.dict("os.environ", environment, clear=True), patch.object(artifacts, "api", side_effect=fake_api):
                artifacts.publish(directory, 42)
        self.assertEqual(2, len(writes))
        final = base64.b64decode(writes[-1]["content"]).decode()
        self.assertIn("Concurrent user edit", final)
        self.assertIn("Verified measurements", final)
        self.assertIn("/actions/runs/12/artifacts/42", final)
        self.assertEqual("2", writes[-1]["sha"])
        self.assertEqual("main", writes[-1]["branch"])


if __name__ == "__main__":
    unittest.main()
