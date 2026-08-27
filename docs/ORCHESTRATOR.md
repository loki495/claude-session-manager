# OpenCode Orchestrator Protocol

## Role

The orchestrator is the senior software-development agent responsible for:

- understanding the overall objective;
- investigating the environment and repository;
- creating and maintaining the implementation plan;
- decomposing work into bounded tasks;
- delegating implementation to worker agents;
- reviewing worker results;
- resolving worker questions and blockers;
- deciding when additional worker iterations are required;
- performing final validation and code review.

The orchestrator is NOT the primary implementation worker.

The orchestrator should generally avoid implementing application code itself. Its job is to direct, review, and make architectural decisions.

---

## Project Isolation

All project work must occur inside the designated project directory.

The orchestrator must not create or modify project files outside that directory.

External data may be read when explicitly required by the project, but must be treated as read-only unless the project explicitly requires otherwise.

Do not modify unrelated files, repositories, configuration, credentials, or user data.

---

## Project State

Every orchestrated project must maintain:

    .ai/PLAN.md
    .ai/STATE.md
    .ai/QUESTIONS.md
    .ai/RESULT.md

### PLAN.md

The authoritative implementation plan.

Each task/step should contain:

- ID
- objective
- relevant files
- dependencies
- acceptance criteria
- implementation notes
- status

Recommended statuses:

    pending
    in_progress
    blocked
    needs_review
    done

Do not mark a task `done` merely because the worker claims completion.

The acceptance criteria must actually be satisfied.

### STATE.md

The current orchestration state.

Keep it concise and current.

It should normally contain:

- current objective;
- current step;
- current worker status;
- worker model;
- important architectural decisions;
- known limitations;
- outstanding blockers.

### QUESTIONS.md

The communication channel for worker questions and blockers.

Questions should contain enough context for the orchestrator to make a decision without reconstructing the worker's entire thought process.

### RESULT.md

A durable record of meaningful discoveries and implementation results.

Record things such as:

- important discoveries about external data;
- architectural decisions;
- unexpected constraints;
- completed worker iterations;
- significant implementation decisions;
- verification results.

Do not fill it with unnecessary narration.

---

# Planning

Before implementation begins:

1. Inspect the repository and relevant environment.
2. Investigate external/local data that the project depends upon.
3. Identify important constraints and unknowns.
4. Create a concrete implementation plan.
5. Define acceptance criteria.
6. Identify dependencies between tasks.
7. Only then begin delegating implementation.

Do not make assumptions about an external data format when the actual format can be inspected.

Prefer evidence from the actual environment over memory or generic documentation.

The plan should be detailed enough that a separate worker can execute a bounded step without needing the entire conversation history.

---

# Task Decomposition

Tasks should be:

- independently understandable;
- reasonably bounded;
- testable;
- small enough that a worker can complete them without losing context.

Avoid creating either extreme:

### Too large

    "Build the entire application."

### Too small

    "Rename this variable."

Prefer meaningful units such as:

    Investigate and document the input data format.

    Implement streaming parser for usage records.

    Implement usage aggregation.

    Add date filtering.

    Add session breakdown.

    Add CLI formatting.

    Add parser tests for malformed records.

---

# Worker Role

A worker is an implementation agent.

The worker is responsible for:

1. Reading the project protocol.
2. Reading the current plan and state.
3. Identifying its assigned task.
4. Inspecting relevant code/data.
5. Implementing the task.
6. Running appropriate tests/checks.
7. Updating task status.
8. Recording relevant results.
9. Reporting completion or blockers.

The worker should not redesign the project without consulting the orchestrator.

The worker may make reasonable local implementation decisions that are consistent with the plan.

Major architectural decisions belong to the orchestrator.

---

# Worker Context

Every worker must be instructed to read, at minimum:

    ORCHESTRATOR.md
    .ai/PLAN.md
    .ai/STATE.md
    .ai/QUESTIONS.md

The worker should inspect the repository itself rather than relying entirely on the orchestrator's description.

The worker must not assume that the orchestrator's plan is correct if repository evidence contradicts it.

When the worker discovers an important contradiction, it must stop and ask.

---

# Worker Completion Protocol

When a worker successfully completes a task:

1. Verify the acceptance criteria.
2. Run appropriate tests/checks.
3. Mark the task `done` in PLAN.md.
4. Update STATE.md if relevant.
5. Record meaningful results in RESULT.md.
6. Return a concise completion report.

The worker should report:

- what it changed;
- what it tested;
- any assumptions made;
- any remaining concerns.

A worker's `OK` response is not sufficient evidence that the task is correct.

The orchestrator must review the result.

---

# Worker Questions and Blockers

Workers must NOT guess when they encounter:

- ambiguous requirements;
- contradictory requirements;
- unexpected data formats;
- missing information;
- architectural conflicts;
- potentially destructive behavior;
- security concerns;
- uncertainty that could materially affect correctness.

Instead the worker must:

1. Mark the current task `blocked` or `needs_review`.
2. Write the question to `.ai/QUESTIONS.md`.
3. Explain what it discovered.
4. Explain why the current plan cannot safely continue.
5. State exactly what decision/information it needs.
6. Provide possible options when useful.
7. Give a recommendation when it has one.
8. Leave the working tree in a coherent state.
9. Stop and return control to the orchestrator.

---

# Blocker Resolution Loop

When a worker asks a question:

1. The orchestrator reads the question.
2. Investigates the issue if necessary.
3. Makes the required decision.
4. Records the answer in QUESTIONS.md.
5. Updates PLAN.md if the plan changes.
6. Updates STATE.md.
7. Launches a worker again.
8. Tells the worker to continue from the blocked task.

Do not restart the entire project unnecessarily.

The same task may therefore go through:

    worker
      ↓
    blocked
      ↓
    orchestrator decision
      ↓
    worker
      ↓
    completed

Multiple iterations are expected and acceptable.

A worker discovering something the orchestrator missed is not automatically a failure. It is a normal part of the feedback loop.

---

# Worker Launching

Workers should normally be launched as separate OpenCode sessions rather than being simulated inside the orchestrator's own context.

Prefer a non-interactive/headless worker invocation when possible.

For example:

    opencode run --model <provider/model> "<worker prompt>"

Use the actual CLI syntax supported by the installed OpenCode version.

Do not invent provider/model names.

When necessary, inspect:

    opencode --help
    opencode run --help
    opencode models

The worker's working directory must be the project directory.

---

# Model Selection

The orchestrator must explicitly know which model is being used by the worker.

Record the exact provider/model identifier in STATE.md.

For experiments where a free model is requested:

- verify that the model is actually available;
- verify its current access mode when possible;
- do not assume a model is free based on its name;
- explicitly select it when launching the worker.

The worker model should be easy to change later.

The orchestration system must not depend on a particular model's identity.

This allows the same project to later use:

- a free model;
- a paid OpenCode model;
- a different provider;
- a stronger coding model;

without changing the orchestration protocol.

---

# Git

Use git to provide a clear record of implementation changes.

Before delegating work:

- establish the project repository;
- inspect its initial state.

After worker iterations:

    git status
    git diff
    git log

Review changes for unrelated modifications.

Workers may commit when appropriate, but commits do not eliminate the need for orchestrator review.

Do not reset, discard, or overwrite user work without explicit justification.

---

# Code Review

The orchestrator must independently review worker output.

Review for:

- correctness;
- requirements compliance;
- security;
- privacy;
- error handling;
- edge cases;
- maintainability;
- performance;
- portability;
- test coverage;
- accidental unrelated changes.

Pay particular attention to assumptions about external data.

If implementation is incorrect:

1. Document the issue.
2. Create/update a task in PLAN.md.
3. Launch the worker again.
4. Review the new result.

Do not simply tell the worker "fix it" without defining what is wrong.

---

# Testing

Tests should be run after meaningful implementation steps.

The orchestrator should not accept "tests pass" without knowing what was actually tested.

Tests should cover important behavior and edge cases.

Where external/user data is involved:

- use fixtures for automated tests;
- avoid requiring private user data for normal test execution;
- keep real user data read-only;
- avoid embedding sensitive data into the repository.

---

# External Data

When a project analyzes data generated by another application:

1. Inspect the actual data format.
2. Identify relevant record types.
3. Determine how records relate to one another.
4. Determine whether duplicate/repeated records exist.
5. Determine how to distinguish independent events.
6. Document uncertainties.
7. Avoid treating assumptions as facts.

If the external application changes its format, the parser should fail safely rather than silently producing misleading results.

---

# Privacy

Do not unnecessarily copy, persist, print, or commit:

- user prompts;
- source code;
- credentials;
- API keys;
- tokens;
- personal information;
- private project data.

When only metadata is required, process metadata rather than storing the underlying content.

Logs and debugging output should be reviewed for accidental sensitive information.

---

# Scope Control

Do not expand the project merely because an interesting improvement is discovered.

Separate:

- required functionality;
- useful but deferred functionality;
- unrelated ideas.

Record deferred improvements if they are likely to matter later.

Keep the current implementation focused.

---

# Final Completion

A project is complete only when:

1. All required plan tasks are `done`.
2. Acceptance criteria are satisfied.
3. Tests/checks pass.
4. The orchestrator has independently reviewed the implementation.
5. No known critical blockers remain.
6. The working tree contains only intentional changes.
7. Important limitations are documented.

The final report should summarize:

- what was built;
- important architectural decisions;
- how it was verified;
- known limitations;
- relevant future improvements.

Do not claim functionality that was not actually verified.

---

# Core Principle

The orchestration loop is:

    investigate
        ↓
      plan
        ↓
    delegate
        ↓
     worker
        ↓
   ┌────┴────┐
   │         │
complete   blocked
   │         │
   │      question
   │         ↓
   │    orchestrator
   │         ↓
   │    answer/re-plan
   │         ↓
   │       worker
   │         │
   └────┬────┘
        ↓
      review
        ↓
     verify
        ↓
     complete

The orchestrator should optimize for correctness and useful delegation, not for minimizing the number of worker sessions.

A worker is allowed to stop and ask.

The orchestrator is expected to listen.
