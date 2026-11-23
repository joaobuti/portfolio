# ADR-0012: AI-assisted incident remediation through reviewed merge requests

- Status: accepted by the owner; implementation pending
- Date: 2026-11-23 (America/Sao_Paulo)
- Scope of this delivery: documentation of the approved architecture and boundaries

## Context

The owner requested an AI layer that monitors application errors, identifies
code defects, implements corrections and opens repository change requests.
Portfolio Billing already has application logs, correlation IDs, queues, tests
and GitLab CI. These are integration points, not an existing remediation agent.
Operational Prometheus gauges alone do not provide exception stack traces.

The repository is published to GitLab and GitHub. GitLab remains the primary
integration and CI platform; GitHub is the public portfolio copy. Local dual
push URLs do not synchronize changes merged through either hosting website.

## Accepted decisions

1. Build remediation as a separate service, outside the billing request path.
   Capture errors from the API and background workers, group duplicate
   incidents, and investigate using sanitized context tied to a code revision.
2. Classify suspected code defects separately from configuration problems,
   infrastructure/provider outages and expected business rejections. Classification
   is fallible; uncertainty must produce a diagnostic handoff rather than an
   invented patch.
3. Reproduce a suspected defect in isolation with a regression test, implement a
   correction and run the applicable checks before presenting it as validated.
4. Allow automatic branch creation and change-request publication. Require human
   review for merging and deployment; the agent cannot push directly to `main`,
   merge its own changes or deploy them.
5. Use GitLab **Merge Requests** as the canonical review surface. Do not create a
   competing GitHub PR for the same fix. Synchronize approved merged changes to
   GitHub through an explicit, verifiable publication step.
6. Use synthetic fixtures and disposable environments, with no production
   database access, payment credentials or real financial actions. Sanitize logs
   before model access and treat their contents as untrusted data.
7. Apply specific human review to financial rules, authentication and migrations.
   Passing tests is not authorization to change those policies autonomously.
8. Bound each incident's attempts, duration and cost; retain an audit trail and
   prevent repeated incidents or the agent's own failures from causing loops.
9. Deliver a controlled demonstration first: injected error, incident detection,
   failing test, correction, passing checks and automatic MR with evidence.
   Enable continuous monitoring only after that flow is evaluated.

## Consequences and limits

The agent can reduce the work required to investigate and prepare fixes. It does
not guarantee that every error is diagnosable, reproducible or repairable.
Provider failures and expected billing refusals do not automatically justify
source changes. Tests are evidence for review, not proof of correctness.

Separate execution and publication responsibilities: the environment running
generated code must not hold repository publication credentials. The publisher
receives a validated patch and bounded evidence, not arbitrary commands from
logs or model output. Credentials must be scoped to the required repository
operations; branch protections remain authoritative.

Because GitHub is public, repository artifacts, MR descriptions and synchronized
commits must contain no customer data, credentials or unsanitized incident logs.
Production monitoring requires an execution host and configured integrations;
this documentation does not activate them or authorize deployment.

## Implementation specification and open configuration

[AI remediation specification](../AI_REMEDIATION.md) records the workflow,
failure handling, validation, security boundaries and acceptance criteria.
Exact providers, model/version, runner hosting, numerical budgets, retention
periods and credentials remain implementation/activation configuration, not
values approved implicitly by this ADR.

Codex is a candidate coding engine, not an installed integration. Relevant
technical references are the [Codex SDK](https://github.com/openai/codex/blob/main/sdk/typescript/README.md)
and the [OpenAI CI autofix example](https://developers.openai.com/cookbook/examples/codex/autofix-github-actions).
Their GitHub examples do not replace the approved GitLab MR and CI workflow.
