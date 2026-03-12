---
name: code-review
description: Code review a pull request
argument-hint: <pr-number>
allowed-tools: Bash(gh issue view:*), Bash(gh search:*), Bash(gh issue list:*), Bash(gh pr diff:*), Bash(gh pr view:*), Bash(gh pr list:*), Bash(gh api:*), mcp__github__add_comment_to_pending_review, mcp__github__pull_request_review_write, mcp__github__pull_request_read, mcp__context7__resolve-library-id, mcp__context7__query-docs, Read, Glob, Grep
disable-model-invocation: false
---

Provide a code review for the given pull request.

Use the **Review Comment Format** section in CLAUDE.md for all comments (Conventional Comments labels, inline threads, review body structure).

To do this, follow these steps precisely:

1. Use a Haiku agent to check if the pull request (a) is closed, (b) is a draft, or (c) does not need a code review (eg. because it is an automated pull request, or is very simple and obviously ok).
   If so, do not proceed.
2. Use another Haiku agent to give you a list of file paths to (but not the contents of) any relevant CLAUDE.md files from the codebase: the root CLAUDE.md file (if one exists), as well as any
   CLAUDE.md files in the directories whose files the pull request modified
3. Use a Haiku agent to view the pull request, and ask the agent to return a summary of the change
4. Then, launch 5 parallel Sonnet agents to independently code review the change. The agents should do the following, then return a list of issues and the reason each issue was flagged (eg. CLAUDE.md
   adherence, bug, historical git context, etc.):
   a. Agent #1: Audit the changes to make sure they comply with the CLAUDE.md. Note that CLAUDE.md is guidance for Claude as it writes code, so not all instructions will be applicable during code
   review.
   b. Agent #2: Read the file changes in the pull request, then do a shallow scan for obvious bugs. Avoid reading extra context beyond the changes, focusing just on the changes themselves. Focus on
   large bugs, and avoid small issues and nitpicks. Do NOT flag nitpicks — only flag issues that impact correctness, security, performance, architecture, maintainability, test coverage, or debug leftovers. Ignore likely false positives. Before claiming any API does not exist or was renamed, the agent MUST verify using these methods in order of reliability:
   (1) Read the actual vendor source file (e.g. `api/vendor/symfony/console/Application.php`) — this is the ground truth.
   (2) Query context7 (`mcp__context7__resolve-library-id` then `mcp__context7__query-docs`) for current documentation.
   If neither verification method is possible, do NOT flag the issue.
   c. Agent #3: Read the git blame and history of the code modified, to identify any bugs in light of that historical context
   d. Agent #4: Read previous pull requests that touched these files, and check for any comments on those pull requests that may also apply to the current pull request.
   e. Agent #5: Read code comments in the modified files, and make sure the changes in the pull request comply with any guidance in the comments.
5. For each issue found in #4, launch a parallel Haiku agent that takes the PR, issue description, and list of CLAUDE.md files (from step 2), and returns a score to indicate the agent's level of
   confidence for whether the issue is real or false positive. To do that, the agent should score each issue on a scale from 0-100, indicating its level of confidence. For issues that were flagged due to
   CLAUDE.md instructions, the agent should double check that the CLAUDE.md actually calls out that issue specifically. The scale is (give this rubric to the agent verbatim):
   a. 0: Not confident at all. This is a false positive that doesn't stand up to light scrutiny, or is a pre-existing issue.
   b. 25: Somewhat confident. This might be a real issue, but may also be a false positive. The agent wasn't able to verify that it's a real issue. If the issue is stylistic, it is one that was not
   explicitly called out in the relevant CLAUDE.md.
   c. 50: Moderately confident. The agent was able to verify this is a real issue, but it is a nitpick or minor style issue that doesn't impact correctness, security, performance, architecture, maintainability, test coverage, or debug leftovers. These are always filtered out.
   d. 75: Highly confident. The agent double checked the issue, and verified that it is very likely it is a real issue that will be hit in practice. The existing approach in the PR is insufficient.
   The issue is very important and will directly impact the code's functionality, or it is an issue that is directly mentioned in the relevant CLAUDE.md.
   e. 100: Absolutely certain. The agent double checked the issue, and confirmed that it is definitely a real issue, that will happen frequently in practice. The evidence directly confirms this.
6. Filter out any issues with a score less than 80. If there are no issues that meet this criteria, do not proceed.
7. Use a Haiku agent to repeat the eligibility check from #1, to make sure that the pull request is still eligible for code review.
8. Before posting your review, dismiss and minimize any previous review comments from Claude on this PR:
   a. Minimize previous issue comments (old format):
      `gh api repos/{owner}/{repo}/issues/{pr_number}/comments --paginate --jq '.[] | select(.body | contains("### Code review") or contains("## Review")) | .node_id'`
      For each node_id: `gh api graphql -f query='mutation { minimizeComment(input: {subjectId: "<NODE_ID>", classifier: OUTDATED}) { minimizedComment { isMinimized } } }'`
   b. Fetch previous bot-authored reviews:
      `gh api repos/{owner}/{repo}/pulls/{pr_number}/reviews --paginate --jq '.[] | select(.user.login == "claude[bot]" or .user.login == "github-actions[bot]" or .user.type == "Bot") | {node_id: .node_id, state: .state}'`
   c. For each review with state "APPROVED" or "CHANGES_REQUESTED", dismiss it:
      `gh api graphql -f query='mutation { dismissPullRequestReview(input: {pullRequestReviewId: "<NODE_ID>", message: "Superseded by new review."}) { pullRequestReview { state } } }'`
   d. For each review node_id (all states), minimize it:
      `gh api graphql -f query='mutation { minimizeComment(input: {subjectId: "<NODE_ID>", classifier: OUTDATED}) { minimizedComment { isMinimized } } }'`
   e. If no matching comments or reviews are found, skip this step silently
9. Post the review using the pending review workflow:

   ### A. Check self-review

   Check `gh pr view <pr-number> --json author` — if you are the PR author, use `event: "COMMENT"` instead of `APPROVE` or `REQUEST_CHANGES`.

   ### B. Determine review event

   - **No critical or warning findings** → `APPROVE`
   - **Any critical or warning findings** → `REQUEST_CHANGES`
   - **Self-review** → `COMMENT` (always)

   ### C. Create pending review

   Use `mcp__github__pull_request_review_write` with `method: "create"`. Do NOT pass `event` (omitting it creates a pending review). Do NOT pass `body` (it will be set on submit).

   ### D. Post inline comments

   For each finding with confidence >= 80, post an inline comment using `mcp__github__add_comment_to_pending_review` in **Conventional Comments** format with `suggestion` blocks for code changes.

   Rules:
   - ALWAYS include a concrete fix using a GitHub `suggestion` block when the finding involves a code change
   - ALWAYS propose a solution — never just point out a problem
   - Keep suggestions minimal: only change what is necessary
   - Use `subjectType: "FILE"` as fallback if the exact diff line is not determinable
   - If there are more than 15 findings, post the 15 most important as inline comments and summarize the rest in the review body
   - When linking to code in the review body, use the full git SHA format: `https://github.com/{owner}/{repo}/blob/{full_sha}/{path}#L{start}-L{end}`

   ### E. Submit the review

   Use `mcp__github__pull_request_review_write` with:
   - `method`: "submit_pending"
   - `event`: the event determined in step B
   - `body`: structured review body containing:
     1. **Summary** — 1-3 sentences describing the overall quality
     2. **Inline comments** — "Posted N inline comment(s)." (or "No inline comments." if none)
     3. **Footer**:
        ```
        Generated with [Claude Code](https://claude.ai/code)

        If this code review was useful, please react with a thumbs up. Otherwise, react with a thumbs down.
        ```

   If `submit_pending` fails, retry once with `method: "create"`, `event: "COMMENT"` as fallback (this creates and submits in one call).

Examples of false positives, for steps 4 and 5:

- Pre-existing issues
- Something that looks like a bug but is not actually a bug
- Pedantic nitpicks that a senior engineer wouldn't call out
- Nitpicks: minor style preferences, naming bikeshedding, trivial formatting, missing documentation, or suggestions that don't impact correctness, security, performance, architecture, maintainability, test coverage, or debug leftovers. If a comment would use the `nitpick` label, do not post it.
- Issues that a linter, typechecker, or compiler would catch (eg. missing or incorrect imports, type errors, broken tests, formatting issues, pedantic style issues like newlines). No need to run these
  build steps yourself -- it is safe to assume that they will be run separately as part of CI.
- General code quality issues (eg. lack of test coverage, general security issues, poor documentation), unless explicitly required in CLAUDE.md
- Issues that are called out in CLAUDE.md, but explicitly silenced in the code (eg. due to a lint ignore comment)
- Changes in functionality that are likely intentional or are directly related to the broader change
- Real issues, but on lines that the user did not modify in their pull request
- API existence claims based on training data alone — this project uses cutting-edge frameworks (Symfony 8, Next.js 16, PHP 8.5) where APIs may have changed. Never claim an API "does not exist" without verifying against vendor source or context7 documentation

Notes:

- Do not check build signal or attempt to build or typecheck the app. These will run separately, and are not relevant to your code review.
- Use `gh` to interact with Github (eg. to fetch a pull request), rather than web fetch
- Make a todo list first
- You must cite and link each bug (eg. if referring to a CLAUDE.md, you must link it)
- When linking to code, follow the following format precisely, otherwise the Markdown preview won't render correctly:
  https://github.com/anthropics/claude-cli-internal/blob/c21d3c10bc8e898b7ac1a2d745bdc9bc4e423afe/package.json#L10-L15
    - Requires full git sha
    - You must provide the full sha. Commands like `https://github.com/owner/repo/blob/$(git rev-parse HEAD)/foo/bar` will not work, since your comment will be directly rendered in Markdown.
    - Repo name must match the repo you're code reviewing
    - # sign after the file name
    - Line range format is L[start]-L[end]
    - Provide at least 1 line of context before and after, centered on the line you are commenting about (eg. if you are commenting about lines 5-6, you should link to `L4-7`)
