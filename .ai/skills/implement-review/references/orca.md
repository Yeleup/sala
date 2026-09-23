# Orca adapter

Roles: **implementer — Claude**, **reviewer — Codex**. Coordinator id: `orca:$ORCA_TERMINAL_HANDLE`.

You are an Orca orchestration coordinator. After a successful `claim`, load Orca's compact rules and follow them: `orca skills get orchestration` (about 200 lines — read all of it; never pipe it through `head`, never load `--full`). When a worker-start fails, a worker looks stuck or a release is uncertain, load `orca skills get orchestration --reference recovery-and-cleanup`. Every command below takes `--json`; read ids from the receipts, never guess them. Keep receipts and task texts in files under a scratch directory `S` of your own and pass texts with `--spec "$(cat "$S/<file>")"`.

## Run

```bash
orca status --json
orca orchestration run-create --objective "Issue #<N>: <title>" --json
```

## Implementer (SKILL step 3)

```bash
orca orchestration worker-start --spec "<implementer task>" --task-title "Implement #<N>" \
  --worktree new-top-level --name agent-issue-<N> --agent claude --setup run --timeout-ms 300000 --json > "$S/w1.json"; echo "worker-start-exit=$?"
jq -c '{ok, err: (.error | if . then {code, message: .message[0:400], next: .data.nextSteps} else null end)} + ((.result // {}) | {state, failedStage, lastError, recovery, runId, taskId, dispatchId, stage, turnStart, setup: .setup.state, W: (first(.effects[]? | select(.kind=="worktree") | .id) // null), H: (first(.effects[]? | select(.kind=="terminal" and .role=="agent") | .id) // null), residual: .residualResources})' "$S/w1.json"
```

Use this projection for every start (implementer, reviewer, follow-ups, retries). A start succeeded only when `worker-start-exit=0` and `state` is `ready`; `failed` and `outcome_unknown` also carry a `dispatchId`. `ok:false` means the call was rejected before any attempt: read `err.code` and follow `err.next`; fix the arguments — it is not a failed attempt. After `runtime_timeout` the start may have happened: repeat only with the `--retry-request` it names, never as a new start.

- Keep: task id, dispatch id `D1`, worktree id `W`, agent terminal handle `H1`. `W` is `<repo-id>::<absolute path>`: pass it whole as `--worktree id:<W>`; the path is `${W#*::}`. Orca may add a suffix to the name (`agent-issue-17-2`), so always take the path from the receipt. Other paths: worker-list `.result.workers[]` / `.result.counts`; terminal tail `.result.terminal.tail[]`.
- `new-top-level` creates a fresh worktree from `origin/master` and runs `orca.yaml` setup (`make worktree-setup`) before the agent starts. Never place a worker with `--worktree current`: that is the coordinator's own checkout.
- A failed start: read `failedStage` / `residual` and follow the recovery-and-cleanup reference. Take `W0` from the failed receipt and release its terminal (`worker-release --dispatch <D>`). If its setup succeeded, retry into it: `--task <task id> --retry-of <D> --worktree id:<W0> --agent claude` (existing worktrees never rerun setup, and creation flags are rejected there). If setup failed, `orca worktree rm --worktree id:<W0> --run-hooks --json` first, then retry with `new-top-level`. Every worktree a failed start left behind is removed in the clean-up. At most 3 attempts per task, then block.

## Waiting

Run `worker-start` and the wait in separate tool calls (or gate the wait with `d=$(jq -er 'select(.result.state == "ready") | .result.dispatchId' "$S/<receipt>.json") &&`). The standard wait — keepalive lines go to stderr, errors come as JSON on stdout:

```bash
orca orchestration check --wait --types "worker_done,escalation,question" --timeout-ms 540000 --json 2>/dev/null \
  | jq -c '{ok, err: .error, del: .result.deliveryId, timedOut: .result.timedOut, m: [.result.messages[]? | {id, type, from: .from_handle, subject, body, p: (.payload | fromjson? // .payload)}]}'
orca orchestration reply --id <message_id> --body "<answer>" --json   # questions you can answer from the issue/docs
orca orchestration check --ack <delivery_id> --json
```

- A timeout or empty result is a checkpoint, not a failure: wait again. Keep `--timeout-ms` ≤ 540000 (a shell call is limited to 10 minutes). Never use a bare `sleep`.
- A `worker_done` belongs to the dispatch you wait for when `p.dispatchId` equals it; `p.outcome` must be `succeeded`.
- A reviewer `worker_done` (`p.dispatchId` is `D2`) with a verdict (`approve`, `changes` or `head-mismatch`) is the reviewer's own report; without one it is the review only when it names the expected HEAD SHA and a `make test` summary (SKILL step 4). A report without a verdict and without both markers may come from a sub-agent of Codex: check the reviewer at once in two separate tool calls, `orca terminal read --terminal <H2> --screen --json | jq -r '.result.terminal.tail[]' | grep -qE 'esc to interrupt|Working \(' && echo busy || echo idle` (the default stream read shows only fragments of Codex's status line). Keep waiting while either read says `busy`; the reviewer's own report then arrives with the subject `Rejected worker_done: …` and `p._orcaLifecycleRejection.code == "dispatch_capability_invalid"`, and the text after `Original body:` is the review. If Codex is idle, send one follow-up on `H2` asking for the full report.
- A `Rejected worker_done` for a dispatch whose report you already accepted is a duplicate: ack it, act on nothing.
- To re-read a report you already acked: `orca orchestration check --all --types worker_done --json` (mailbox history; it marks nothing read). `worker-read` text blocks do not hold the findings — do not rely on them.
- A worker question you cannot answer from the issue or docs → block (SKILL step 9).
- After an implementer or reviewer finishes, keep its terminal for the next round: `orca orchestration worker-retain --dispatch <id> --json`.
- Never type into a worker terminal (it becomes `user_takeover` and leaves Orca's control).

## Reviewer (SKILL step 4)

```bash
orca orchestration worker-start --spec "<read-only review task>" --task-title "Review #<N> r<k>" \
  --worktree id:<W> --agent codex --json > "$S/r1.json"; echo "worker-start-exit=$?"
```

Keep dispatch `D2` and terminal `H2`. Codex runs with its sandbox disabled, so read-only is only a request: check `git -C <path> status --porcelain` and `HEAD` after every review.

### Codex reviewer that never started

- After a Codex worker-start, the first wait uses `--timeout-ms 180000`.
- On timeout: `orca terminal read --terminal <H2> --screen --json | jq -r '.result.terminal.tail[]' | tail -15`. `[Pasted Content …]` with `tab to queue message` and no `Working (` line proves the task was never submitted: `orca orchestration worker-stop --dispatch <D2> --json`, then `orca orchestration worker-start --task <review task id> --retry-of <D2> --worktree id:<W> --agent codex --json`. The spec is reused, the new terminal handle comes from the receipt, and this counts toward the 3 attempts. Otherwise wait again with the normal timeout.
- A worker-start that fails at `agent_readiness` with «agent-update-prompt» means Codex wants to update itself: block with «Codex ждёт обновления: обновите его (codex update) и снимите метку».
- Codex liveness normally reads `unverifiable: missing_status`: that is neither proof of work nor proof of a stall. Never use `terminal wait --for tui-idle` on Codex.

## Follow-ups to the same agent (fixes, rebase, re-review)

```bash
orca orchestration worker-start --spec "<findings / rebase instructions>" --task-title "Fix #<N> r<k>" \
  --terminal <H1> --worktree id:<W> --json
orca orchestration worker-start --spec "<re-review task>" --task-title "Review #<N> r<k+1>" \
  --terminal <H2> --worktree id:<W> --json
```

`--terminal` reuses the agent with its conversation; `--worktree` must name `W` (`current` would mean the coordinator's checkout). `--model` / `--effort` cannot be combined with `--terminal`.

## Clean up (SKILL step 8, and step 9 when blocking)

```bash
M="$(dirname "$(git rev-parse --path-format=absolute --git-common-dir)")"
orca orchestration worker-release --dispatch <latest implementer dispatch> --json | jq -c '{ok, r: .result}'
orca orchestration worker-release --dispatch <latest reviewer dispatch> --json | jq -c '{ok, r: .result}'
orca orchestration worker-list --run <run id> --terminal-state reclaimable --json | jq '.result.workers | length'   # must print 0
orca worktree rm --worktree id:<W> --run-hooks --json | jq -c '{ok, err: .error}'
make -C "$M" --no-print-directory worktree-prune 2>&1 | grep -E 'stale|not running' || echo 'no leftovers'
```

Remove every worktree a failed start left behind the same way (`orca worktree rm --worktree id:<W0> --run-hooks`), and a stale predecessor's by its path (`orca worktree rm --worktree path:<path> --run-hooks --json`, plus `--force` when SKILL step 1 found uncommitted files). Rows `retained` with `user_requested`, `external_terminal` or `user_takeover` are expected bookkeeping once the worktree is removed; do not act on them. `--run-hooks` runs the `orca.yaml` archive hook (containers, volumes, databases and database role of the worktree). The hook always exits 0, so the dry-run prune is the proof that everything is gone: report any `stale` line in the final comment with «make worktree-prune CONFIRM=yes».

## Scheduler

An Orca automation starts a fresh coordinator in the main checkout every 5 minutes when the queue has work:

```bash
orca automations create --name "sala: очередь агентов" --trigger "*/5 * * * *" --provider claude \
  --workspace path:<main checkout> --workspace-mode existing --fresh-session --enabled \
  --precheck "bash .ai/skills/implement-review/queue.sh has-work" \
  --prompt "Ты координатор очереди агентов. Выполни навык implement-review (адаптер Orca, файлы .ai/skills/implement-review/SKILL.md и references/orca.md) для следующего issue из очереди."
```

Inspect with `orca automations list --json` and `orca automations runs --id <id> --json`, disable with `orca automations edit <id> --disabled`. `orca automations run <id>` skips the precheck and starts a paid coordinator even on an empty queue; to start one now: `bash .ai/skills/implement-review/queue.sh has-work && orca automations run <id>`.
