<!--
Delete whatever does not apply. The checklist is short on purpose: each line is something CI
cannot tell you, or something that has actually gone wrong in this repository before.
-->

## What this changes

<!-- What is true after this that was not true before. Not a list of files. -->

## Why

<!-- If it fixes an issue, link it. If it implements a milestone, say which. -->

---

## Checks

- [ ] `docker compose run --rm dev composer ci` passes.

  That runs the environment check, `composer validate`, `composer audit`, PHPStan at level max,
  the style check and the suite with coverage — in that order, with the environment check first
  because every later step trusts the interpreter. Run it in the container rather than on a local
  PHP: ICU version changes answers, and the container is where it is pinned.

- [ ] `tests/fixtures/conformance/` is untouched.

  It is a verbatim copy of one upstream commit, recorded in `UPSTREAM`. To update it, run
  `composer suite:update` as its own commit so the diff shows which expectations moved.

- [ ] If the number of asserted conformance cases changed, `ASSERTED_FLOOR` moved with it.

  It is a ratchet in `tests/Conformance/SuiteTest.php`. Raising it is the normal outcome of
  implementing anything. Lowering it needs saying why in the PR, because a suite that quietly
  asserts less is the failure mode it exists to catch.

- [ ] If `Syntax\CodePoints` changed, `AbnfAgreementTest` still passes.

  It re-derives every character class from the vendored grammar. If it disagrees with you, it is
  usually right.

- [ ] A behaviour that knowingly differs from the specification is recorded, not just left.

  In the docblock where it happens, in a test that pins current behaviour and says what it will
  become, and — if it is more than a detail — in a `spec-divergence` issue. A divergence that
  exists only in somebody's head reads as a bug to the next person.

- [ ] New behaviour arrived test-first.

  Not a process ritual: a test written afterwards passes immediately, which proves it runs but
  not that it can fail. Several defects in this repository were found only because a test was
  watched failing first, and at least one guard was trusted only after being deliberately broken
  to check it could go red.
