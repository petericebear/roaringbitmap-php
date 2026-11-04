#!/usr/bin/env python3
"""Maintain this workflow's benchmark artifacts and README snapshot using gh."""

import argparse
import base64
import json
import os
from pathlib import Path
import re
import subprocess

REPOSITORY = "petericebear/roaringbitmap-php"
START = "<!-- benchmark:latest:start -->"
END = "<!-- benchmark:latest:end -->"
ARTIFACT_NAME = re.compile(r"^benchmark-[0-9]+-[0-9]+$")


def artifact_candidates(artifacts):
    return [a for a in artifacts
            if ARTIFACT_NAME.fullmatch(a.get("name", ""))
            and isinstance(a.get("id"), int)
            and isinstance(a.get("created_at"), str)
            and (a.get("workflow_run") or {}).get("head_branch") == "main"]


def deletion_candidates(artifacts, keep):
    if not 0 <= keep <= 3:
        raise ValueError("Keep must be between zero and three.")
    owned = artifact_candidates(artifacts)
    live = sorted((a for a in owned if not a.get("expired")),
                  key=lambda a: (a["created_at"], a["id"]), reverse=True)
    return [a for a in owned if a.get("expired")] + live[keep:]


def replace_snapshot(readme, snapshot):
    if readme.count(START) != 1 or readme.count(END) != 1 or readme.index(START) >= readme.index(END):
        raise ValueError("README benchmark markers must appear exactly once, in order.")
    before, rest = readme.split(START)
    _, after = rest.split(END)
    return before + START + "\n" + snapshot.strip() + "\n" + END + after


def require_authorized_context():
    if (os.environ.get("GITHUB_TRIGGERING_ACTOR") != "petericebear"
            or os.environ.get("GITHUB_REPOSITORY") != REPOSITORY
            or os.environ.get("GITHUB_REF") != "refs/heads/main"):
        raise RuntimeError("Artifact maintenance is restricted to petericebear's main-branch CI runs.")


def api(endpoint, method="GET", payload=None, paginate=False):
    command = ["gh", "api", "--method", method, endpoint]
    if paginate:
        command += ["--paginate", "--slurp"]
    if payload is not None:
        command += ["--input", "-"]
    result = subprocess.run(command, input=json.dumps(payload) if payload is not None else None,
                            text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError(result.stderr.strip())
    return json.loads(result.stdout) if result.stdout.strip() else None


def prune(keep):
    # Collect every page before deletion so changing page offsets cannot skip artifacts.
    pages = api(f"repos/{REPOSITORY}/actions/artifacts?per_page=100", paginate=True)
    artifacts = [artifact for page in pages for artifact in page["artifacts"]]
    for artifact in deletion_candidates(artifacts, keep):
        try:
            api(f"repos/{REPOSITORY}/actions/artifacts/{artifact['id']}", method="DELETE")
        except RuntimeError as error:
            if "HTTP 404" not in str(error):
                raise
        print(f"Removed old benchmark artifact {artifact['name']} ({artifact['id']}).")
    # Re-read to catch an unexpected concurrent uploader or a failed API deletion.
    pages = api(f"repos/{REPOSITORY}/actions/artifacts?per_page=100", paginate=True)
    remaining = artifact_candidates([a for page in pages for a in page["artifacts"]])
    if any(a.get("expired") for a in remaining) or len(remaining) > keep:
        raise RuntimeError("Benchmark artifact retention limit was not reached.")
    print(f"Benchmark artifacts retained: {len(remaining)} (limit {keep}).")


def publish(directory, artifact_id):
    summary = json.loads((directory / "summary.json").read_text())
    expected_name = f"benchmark-{os.environ['GITHUB_RUN_ID']}-{os.environ['GITHUB_RUN_ATTEMPT']}"
    if (summary["repository"] != REPOSITORY or summary["commit"] != os.environ["GITHUB_SHA"]
            or summary["artifact_name"] != expected_name):
        raise ValueError("Benchmark provenance does not match this workflow run.")
    artifact = api(f"repos/{REPOSITORY}/actions/artifacts/{artifact_id}")
    if (artifact["name"] != expected_name or artifact.get("expired")
            or str(artifact["workflow_run"]["id"]) != os.environ["GITHUB_RUN_ID"]):
        raise ValueError("Uploaded artifact does not match this benchmark run.")
    run_url = f"https://github.com/{REPOSITORY}/actions/runs/{os.environ['GITHUB_RUN_ID']}"
    artifact_url = f"{run_url}/artifacts/{artifact_id}"
    snapshot = (directory / "readme.md").read_text().rstrip() + "\n\n" + (
        f"[Download full benchmark reports and raw samples]({artifact_url}) (GitHub sign-in required) · "
        f"[CI run]({run_url}). Only the three newest benchmark bundles are retained; "
        "artifacts also expire after 90 days. The measured snapshot remains readable here."
    )
    for attempt in range(4):
        current = api(f"repos/{REPOSITORY}/contents/README.md?ref=main")
        original = base64.b64decode(current["content"]).decode()
        updated = replace_snapshot(original, snapshot)
        if original == updated:
            break
        try:
            api(f"repos/{REPOSITORY}/contents/README.md", method="PUT", payload={
                "message": f"docs: refresh CI benchmark results ({expected_name}) [skip ci]",
                "content": base64.b64encode(updated.encode()).decode(),
                "sha": current["sha"], "branch": "main",
            })
            break
        except RuntimeError as error:
            if "HTTP 409" not in str(error) or attempt == 3:
                raise
            # Preserve intervening README edits using the newest file SHA.
    if os.environ.get("GITHUB_STEP_SUMMARY"):
        with open(os.environ["GITHUB_STEP_SUMMARY"], "a") as file:
            file.write("## Latest CI benchmark\n\n" + snapshot + "\n")
    print(f"README performance snapshot published from {expected_name}.")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    cleanup = commands.add_parser("prune")
    cleanup.add_argument("--keep", type=int, choices=range(4), required=True)
    publication = commands.add_parser("publish")
    publication.add_argument("--directory", type=Path, required=True)
    publication.add_argument("--artifact-id", type=int, required=True)
    args = parser.parse_args()
    require_authorized_context()
    if args.command == "prune":
        prune(args.keep)
    else:
        publish(args.directory, args.artifact_id)


if __name__ == "__main__":
    main()
