# Agent Task Queue

Code changes are done through a queue of GitHub issues (label `agent`) worked by coordinators that follow the `implement-review` skill: one agent implements, another reviews, the PR is merged after a clean review.

The queue rules below apply only to **entry sessions**: a session in the main checkout. A worker in a task worktree or an Orca coordinator ignores them and does the task it was given; a reviewer follows «Reviewing a queue task».

When the user asks an entry session for a code change (feature, bug fix, refactoring, migration, UI change):

- Put it in the queue: write the user's request, with the context from the conversation, to a file and run `bash "$(dirname "$(git rev-parse --path-format=absolute --git-common-dir)")/.ai/skills/implement-review/queue.sh" enqueue "<short Russian title>" <file>` (the main checkout's copy of the script). Reply with the issue link; a coordinator picks it up on its own.
- Settle every open interpretation with the user (AskUserQuestion) before enqueueing: a coordinator claims a new issue within minutes and reads the thread once, so «напишите — поправлю задачу» comes too late.
- In the issue body keep «Запрос пользователя» (quoted), «Решения пользователя» and your own proposals apart; ask about any proposal that changes existing behaviour, or leave it out.
- The repository is public: the issue never contains data from the local database or real conversations (phone numbers, names, WhatsApp message texts, ids of real contacts or listings, tokens or keys). Describe the case in general terms.
- Run `queue.sh list` first. If the change needs code from an open queue issue, add a line `Зависит от #N` (the queue holds the issue until #N is closed); if it only touches the same module, add «Связано с #N». After enqueueing, `queue.sh list` shows `waits:#N` for a registered dependency.
- Enqueueing replaces implementation in any effort or workflow mode.
- If the user says «сразу» (now): enqueue it. Only in an Orca terminal (`ORCA_TERMINAL_HANDLE` is set), claim that issue yourself (`queue.sh claim "orca:$ORCA_TERMINAL_HANDLE" <number>`) and coordinate it per the `implement-review` skill; anywhere else say that the Orca automation picks it up within about 5 minutes.
- If the user says «сделай сам» or «без очереди»: do the change yourself in this session, without the queue.

Questions, analysis, code review, SQL for production data fixes and other work that does not change repository files are answered directly, without the queue.

## Reviewing a queue task

If you review a pull request or branch named `agent/issue-<N>`, whatever tool started you:

- do not modify, commit or push anything, and do not spawn sub-agents;
- in the task worktree (`HEAD` is that branch): review `git diff origin/master...HEAD` against issue #<N> (`gh issue view <N>`; only the issue author's comments count), the relevant `docs/` and these project rules, and run the full `make test`;
- anywhere else (for example the main checkout): review `gh pr diff <pr>` the same way; `make test` here would test other code, so report «make test не запускался» unless you ran it in a worktree checked out at that branch;
- report every finding with severity, `file:line`, the failure scenario and a suggested fix, then the reviewed SHA and the `make test` summary, and end with the line `VERDICT: approve` or `VERDICT: changes`.
