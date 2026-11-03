#!/usr/bin/env python3
"""Codex <-> Claude review loop driver. Runs from a trusted checkout of main.

Commands:
  precheck  find the PR, wait for its CI checks, decide whether Claude should review
  act       read Claude's verdict, then ask Codex for fixes, or merge and start the next task
  start     post the "implement task" request for a task id (manual dispatch)
"""
from __future__ import annotations

import json
import os
import re
import subprocess
import sys
import time
from pathlib import Path

REPO = os.environ["REPO"]
OWNER = os.environ.get("OWNER_LOGIN", "")
MAX_ROUNDS = int(os.environ.get("MAX_ROUNDS", "3"))
OWN_CHECKS = {"review", "start-task"}  # job names of this workflow; never wait on ourselves
TRUSTED = Path(os.environ.get("TRUSTED_DIR", "."))
TITLE_RE = re.compile(r"^(T(\d+)\.(\d+))\b")


def gh(*args: str, input_data: str | None = None) -> str:
    result = subprocess.run(["gh", *args], input=input_data, capture_output=True, text=True)
    if result.returncode != 0:
        raise RuntimeError(f"gh {' '.join(args)} failed: {result.stderr.strip()}")
    return result.stdout


def api(path: str, method: str = "GET", body: dict | None = None):
    args = ["api", "-X", method, f"repos/{REPO}/{path}"]
    out = gh(*args, "--input", "-", input_data=json.dumps(body)) if body is not None else gh(*args)
    return json.loads(out) if out.strip() else None


def api_all(path: str) -> list:
    sep = "&" if "?" in path else "?"
    items, page = [], 1
    while True:
        chunk = api(f"{path}{sep}per_page=100&page={page}")
        items += chunk
        if len(chunk) < 100:
            return items
        page += 1


def output(**values: str) -> None:
    with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as fh:
        for key, value in values.items():
            fh.write(f"{key}={value}\n")


def comment(number: int, body: str) -> None:
    api(f"issues/{number}/comments", "POST", {"body": body})


def label(number: int, name: str) -> None:
    api(f"issues/{number}/labels", "POST", {"labels": [name]})


def loop_markers(pr: int) -> list[dict]:
    markers = []
    for c in api_all(f"issues/{pr}/comments"):
        m = re.search(r"<!-- rm-loop sha=(\w+) verdict=(\w+) -->", c.get("body") or "")
        if m:
            markers.append({"sha": m.group(1), "verdict": m.group(2)})
    return markers


# ---------------------------------------------------------------- plan helpers
def plan_tasks() -> list[str]:
    text = (TRUSTED / "docs/PLAN.md").read_text(encoding="utf-8")
    return re.findall(r"^\*\*(T\d+\.\d+)", text, re.M)


def phase_of(task: str) -> int:
    return int(task[1:].split(".")[0])


def phase_issue(phase: int) -> dict | None:
    for issue in api_all("issues?state=all&labels=phase"):
        if "pull_request" not in issue and re.match(rf"^Phase {phase}\b", issue["title"]):
            return issue
    return None


def unchecked_human_steps(body: str) -> list[str]:
    section = re.search(r"### Human steps \(Ahmed\)\n(.*?)(\n### |\Z)", body, re.S)
    return re.findall(r"^- \[ \] (.+)$", section.group(1), re.M) if section else []


def start_task(task: str) -> None:
    issue = phase_issue(phase_of(task))
    if issue is None:
        raise RuntimeError(f"No phase issue for {task}")
    comment(issue["number"], (
        f"<!-- rm-start task={task} -->\n"
        f"@codex Read AGENTS.md, docs/DOMAIN.md and docs/PLAN.md. Implement task **{task}** only, "
        f"following AGENTS.md (section \"Automated review loop\").\n"
        f"Start from the latest main on a new branch named `codex/{task.lower()}-<short-slug>`. "
        f"Open a pull request against main titled `{task}: <summary>` with `Refs #{issue['number']}` in the body. "
        f"One commit named `{task}: ...`. Do not start any other task. "
        f"Do not perform [AHMED] steps; list them in the PR body.\n"
        f"In the PR body report: what changed, files, checks run and results, suites you could not run, "
        f"and questions added to docs/QUESTIONS.md."
    ))
    print(f"Requested {task} on issue #{issue['number']}")


# ---------------------------------------------------------------- commands
def precheck() -> None:
    pr_number = int(os.environ.get("PR_NUMBER") or 0)
    pr = api(f"pulls/{pr_number}")
    sha, title = pr["head"]["sha"], pr["title"]
    output(pr=str(pr_number), sha=sha, action="skip")
    if pr["state"] != "open" or not TITLE_RE.match(title) or not pr["head"]["ref"].startswith("codex/"):
        print("Not a Codex task PR; skipping.")
        return
    markers = loop_markers(pr_number)
    if any(m["sha"] == sha for m in markers):
        print(f"{sha} already handled; skipping.")
        return
    rounds = sum(1 for m in markers if m["verdict"] in ("CHANGES", "CI_FAIL"))
    if rounds >= MAX_ROUNDS:
        label(pr_number, "needs-human")
        comment(pr_number, f"<!-- rm-loop sha={sha} verdict=STOPPED -->\n@{OWNER} The review loop stopped "
                f"after {rounds} fix rounds without a clean review. Please take a look.")
        return

    # Wait for every check run on this commit except our own jobs.
    deadline, first_seen = time.time() + 45 * 60, time.time()
    while True:
        runs = [r for r in api(f"commits/{sha}/check-runs?per_page=100")["check_runs"]
                if r["name"] not in OWN_CHECKS]
        pending = [r["name"] for r in runs if r["status"] != "completed"]
        if runs and not pending:
            break
        if not runs and time.time() - first_seen > 5 * 60:
            print("No CI checks appeared within 5 minutes; reviewing without CI.")
            break
        if time.time() > deadline:
            comment(pr_number, f"@{OWNER} CI did not finish within 45 minutes on `{sha[:7]}`; review skipped.")
            return
        time.sleep(30)

    failed = [r["name"] for r in runs if r["conclusion"] not in ("success", "skipped", "neutral")]
    if failed:
        comment(pr_number, (
            f"<!-- rm-loop sha={sha} verdict=CI_FAIL -->\n"
            f"@codex CI failed on `{sha[:7]}` ({', '.join(failed)}). Read the failing job logs, fix the cause "
            f"without weakening tests, and push to this same branch. Fix round {rounds + 1}/{MAX_ROUNDS}."
        ))
        return
    output(action="review", round=str(rounds + 1))


def act() -> None:
    pr_number, sha = int(os.environ["PR_NUMBER"]), os.environ["SHA"]
    pr = api(f"pulls/{pr_number}")
    if pr["head"]["sha"] != sha:
        print("PR moved on during review; the next run will handle it.")
        return
    verdict_file = Path(os.environ["VERDICT_FILE"])
    try:
        verdict = json.loads(verdict_file.read_text(encoding="utf-8"))["verdict"]
    except (OSError, ValueError, KeyError):
        verdict = None
    if verdict not in ("APPROVE", "CHANGES"):
        label(pr_number, "needs-human")
        comment(pr_number, f"<!-- rm-loop sha={sha} verdict=ERROR -->\n@{OWNER} Claude's review did not "
                f"produce a verdict for `{sha[:7]}`. Check the workflow run.")
        return

    rounds = sum(1 for m in loop_markers(pr_number) if m["verdict"] in ("CHANGES", "CI_FAIL"))
    if verdict == "CHANGES":
        comment(pr_number, (
            f"<!-- rm-loop sha={sha} verdict=CHANGES -->\n"
            f"@codex Fix every blocker and major finding in the latest Claude review on this PR "
            f"(and minors marked \"fix now\"). Do not change unrelated code; add a regression test for each "
            f"blocker. Push to this same branch with one commit named `{TITLE_RE.match(pr['title']).group(1)}: "
            f"address review findings`. Fix round {rounds + 1}/{MAX_ROUNDS}."
        ))
        return

    task = TITLE_RE.match(pr["title"]).group(1)
    comment(pr_number, f"<!-- rm-loop sha={sha} verdict=APPROVE -->\nClean review and green CI. Merging {task}.")
    if pr.get("draft"):
        gh("pr", "ready", str(pr_number), "--repo", REPO)
    gh("pr", "merge", str(pr_number), "--repo", REPO, "--merge", "--delete-branch")

    issue = phase_issue(phase_of(task))
    if issue:
        body = re.sub(rf"^- \[ \] \*\*{re.escape(task)}\*\*", f"- [x] **{task}**", issue["body"], flags=re.M)
        api(f"issues/{issue['number']}", "PATCH", {"body": body})
        issue["body"] = body

    tasks = plan_tasks()
    nxt = tasks[tasks.index(task) + 1] if task in tasks and tasks.index(task) + 1 < len(tasks) else None
    if nxt and phase_of(nxt) == phase_of(task):
        start_task(nxt)
        return

    # Phase finished.
    if issue:
        steps = unchecked_human_steps(issue["body"])
        if steps:
            label(issue["number"], "needs-human")
            comment(issue["number"], (
                f"@{OWNER} All tasks of this phase are merged. Before the next phase, complete and tick the "
                f"human steps above:\n" + "\n".join(f"- {s}" for s in steps) +
                (f"\n\nThen run the **Claude review loop** workflow manually with task `{nxt}`." if nxt else "")
            ))
            return
        api(f"issues/{issue['number']}", "PATCH", {"state": "closed"})
    if nxt:
        start_task(nxt)
    else:
        print("Plan finished.")


if __name__ == "__main__":
    command = sys.argv[1] if len(sys.argv) > 1 else ""
    if command == "precheck":
        precheck()
    elif command == "act":
        act()
    elif command == "start":
        start_task(sys.argv[2])
    else:
        sys.exit("usage: loop.py precheck|act|start <task>")
