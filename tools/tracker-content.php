<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Tools;

/**
 * What {@see seed-tracker.php} creates: this project's labels, milestones and issues.
 *
 * Data, not code, so that `meraki/messageformat-resource` can have its own copy of this file and
 * reuse the runner unchanged.
 *
 * Bodies are nowdocs (`<<<'MD'`), which are literal: braces, dollars and backslashes in a
 * message example need no escaping, which matters when half the prose is MessageFormat syntax.
 *
 * `{{key}}` in a body becomes `#<issue number>` once everything exists, so issues can reference
 * each other without anybody tracking numbers by hand. The runner fails if one is left over.
 *
 * This is a record of what was seeded as much as an instruction. If an issue is reworded on
 * GitHub, this file is not the source of truth any more — and that is fine, because it cannot be
 * run against this repository a second time anyway.
 */

return [
	'labels' => [
		'conformance' => ['0e8a16', 'Concerns the Unicode conformance suite'],
		'spec-divergence' => ['d93f0b', 'A known, recorded difference from the specification'],
		'needs-icu' => ['1d76db', 'Blocked on what ext-intl exposes, or on an ICU version'],
		'milestone' => ['5319e7', 'Tracks one milestone of the build'],
	],

	'milestones' => [
		'M1 — simple-message, and the suite that checks it' => [
			<<<'TEXT'
			The scanner, the grammar's character classes, the data model, a recursive-descent parser for the whole of `simple-message`, a formatter, and the conformance runner. Complete: 171 of 462 fixture cases asserted, the rest skipped by named reason.
			TEXT,
			'closed',
		],
		'M2 — complex messages and validity' => [
			<<<'TEXT'
			`complex-message`, `.input` and `.local` declarations, quoted patterns, `.match` and its variants, and the validator for all six Data Model Errors. The largest single gain in asserted conformance cases, and it needs no ICU.
			TEXT,
			'open',
		],
		'M3 — the function registry, resolved values and selection' => [
			<<<'TEXT'
			The handler interface, resolved values, option resolution, the pattern-selection algorithm, and `:string`. After this an unknown function is the exception rather than the rule.
			TEXT,
			'open',
		],
		"M4 — the specification's test functions" => [
			<<<'TEXT'
			`:test:function`, `:test:select` and `:test:format`. Deliberately before `:number`: they exercise the selection algorithm in locale `und` with no ICU involved, so a later plural bug cannot be mistaken for a selection bug.
			TEXT,
			'open',
		],
		'M5 — exact numerics and number formatting' => [
			<<<'TEXT'
			A lossless decimal representation, the number option set, the option-to-ICU-skeleton mapping, and `:number` and `:integer` formatting.
			TEXT,
			'open',
		],
		'M6 — plural selection' => [
			<<<'TEXT'
			Plural operands, plural categories, and `select=plural|ordinal|exact`.
			TEXT,
			'open',
		],
		'M7 — the error model' => [
			<<<'TEXT'
			The error collector, strict and fallback modes as configuration rather than branches, the fallback string table, and message-level fallback for input that cannot be parsed. After this the suite's expected errors can be compared, not just its output.
			TEXT,
			'open',
		],
		'M8 — formatted parts and markup' => [
			<<<'TEXT'
			`formatToParts()`, markup resolution, and the parts JSON shape the suite's `expParts` uses. Markup is unreachable from string output, so this is the first point at which it does anything.
			TEXT,
			'open',
		],
		'M9 — directionality' => [
			<<<'TEXT'
			Base direction, the `u:id` and `u:dir` options, and the default bidi isolation strategy.
			TEXT,
			'open',
		],
		'M10 — the remaining functions (1.0)' => [
			<<<'TEXT'
			`:offset`, `:percent`, `:currency`, `:unit`, `:date`, `:time`, `:datetime`, and a namespaced list function. The whole conformance suite green; this is 1.0.
			TEXT,
			'open',
		],
		'M11 — CLDR plural rules from data (1.1)' => [
			<<<'TEXT'
			Replaces the probe-based plural implementation with generated CLDR tables, closing a correctness gap the conformance suite cannot see.
			TEXT,
			'open',
		],
		'M12 — hardening' => [
			<<<'TEXT'
			Deserialisation and round-trip property tests, fuzzing, a compiled-message cache for the catalogue package, and documentation.
			TEXT,
			'open',
		],
	],

	// Created in this order. The divergences come first only because the milestone trackers
	// reference them; the runner patches cross-references afterwards either way.
	'issues' => [
		'numeric' => [
			'title' => 'An unannotated numeric operand is not formatted for its locale',
			'labels' => ['spec-divergence', 'needs-icu', 'conformance'],
			'milestone' => 'M5',
			'body' => <<<'MD'
				## What happens

				```php
				MessageFormatter::for('fr')->format('{$one} et {$two}', ['one' => 1.3, 'two' => 4.2]);
				// => "1.3 et 4.2"
				```

				## What should happen

				`"1,3 et 4,2"`. A placeholder carrying no function still formats a numeric operand according to the locale.

				## Evidence

				`tests/fixtures/conformance/tests/syntax.json`, case 90: locale `fr`, parameters `one=1.3` and `two=4.2`, expected output `1,3 et 4,2`.

				## Why it is open

				Locale-aware number formatting needs ICU, and only `Meraki\MessageFormat\Icu` is permitted to touch `ext-intl`. Adding a second, slightly different number formatter outside that boundary to serve this one case would be the wrong trade, so it waits for the one `:number` is built on.

				## It is recorded, not hidden

				- `MessageFormatter::asText()` documents the divergence in place
				- `FormattingTest::a_numeric_argument_is_not_yet_formatted_for_its_locale()` pins current behaviour and says what it will become
				- the conformance runner skips the two affected cases with the reason "locale formatting of a non-string argument"

				This was found by the conformance suite. An earlier test asserted plain conversion as though it were correct, with a comment arguing that locale formatting was `:number`'s business. The comment was wrong.
				MD,
		],

		'plural' => [
			'title' => 'Plural categories are derived by probing MessageFormat 1, which cannot see the `v` operand',
			'labels' => ['spec-divergence', 'needs-icu'],
			'milestone' => 'M11',
			'body' => <<<'MD'
				## The problem

				PHP has no `PluralRules` class — `ext-intl` exposes none at any version. The usual workaround asks ICU for a category by abusing MessageFormat **1**:

				```php
				MessageFormatter::formatMessage($locale,
				    '{0,plural,zero{zero}one{one}two{two}few{few}many{many}other{other}}', [$n]);
				```

				That works for most inputs. It cannot be made correct.

				## Why

				CLDR plural rules are functions of six operands, one of which is `v`: the count of visible fraction digits, **including trailing zeros**. The specification requires selection to run on the operand *as modified by function options*.

				The trick accepts only a `double`, and **a double cannot carry `v`**. ICU formats the argument with the locale's default decimal format, whose minimum fraction digits is 0 in every CLDR locale, so no cleverer choice of argument recovers it.

				## Measured

				| message | locale | specification | this approach |
				| --- | --- | --- | --- |
				| `{$n :number minimumFractionDigits=1}`, n=1 | `en` | `other` | `one` |
				| `{$n :number minimumFractionDigits=1}`, n=1 | `cs` | `many` | `one` |
				| `{$n :number maximumFractionDigits=0}`, n=1.4 | `en` | `one` | `other` |

				`v` is load-bearing in roughly forty locales, including `en de nl sv da it pt es ru pl cs sk uk be lt lv hr sr bs sl`.

				## Partial mitigation

				Pre-rounding the operand before probing recovers the whole `maximumFractionDigits` family — the third row above. The first two cannot be recovered this way.

				## Closing it

				Generate CLDR plural rule tables from `plurals.xml` and `ordinals.xml` and evaluate them directly, with a test cross-checking the generated tables against the installed ICU so drift is visible.

				## Note on severity

				**The conformance suite never exercises this**, so a green suite is not evidence against it. That is the reason this issue exists rather than a failing test.
				MD,
		],

		'icu77' => [
			'title' => 'No distribution packages ICU 77, the release MessageFormat 2 became Stable in',
			'labels' => ['needs-icu'],
			'milestone' => null,
			'body' => <<<'MD'
				## The situation

				MessageFormat 2 became Stable in CLDR 47, which ships in ICU 77. No Linux distribution packages that version:

				| base | ICU | CLDR |
				| --- | --- | --- |
				| Debian 12 | 72 | 42 |
				| Debian 13 | 76 | 46 |
				| Alpine 3.24 | 78 | 48 |
				| Debian unstable | 78 | 48 |

				ICU 77 is reachable only by building it from source.

				## The decision

				Accepted rather than worked around. The conformance fixtures are generated by the working group's JavaScript implementation, not by ICU, so pinning ICU 77 would not make them more authoritative — it would only change what `:number` and `:date` print.

				Development runs on ICU 76 and CI additionally runs ICU 72 and ICU 78, spanning CLDR 42 to 48. Those two are marked `continue-on-error`: a difference between them is CLDR data moving rather than this library breaking, and such an assertion belongs in the `icu` PHPUnit group.

				## Reopen this if

				A conformance expectation turns out to depend on CLDR 47 specifically, in which case the base image gains a source build of ICU 77 and `PHP_BASE` stops being the only knob. See `.devcontainer/Dockerfile`.
				MD,
		],

		'parts' => [
			'title' => 'Formatted parts cannot include number sub-parts',
			'labels' => ['needs-icu', 'conformance'],
			'milestone' => 'M8',
			'body' => <<<'MD'
				## The limit

				`ext-intl` has no equivalent of `NumberFormatter::formatToParts`. ICU4C has one; the PHP extension does not expose it. So a formatted `number` part cannot be broken into `{"type":"integer","value":"42"}` and friends, because the information is not obtainable.

				## Why this is allowed

				The specification leaves the form of a formatted part implementation-defined, so coarser parts are conformant. The conformance suite's `expParts` is the reference implementation's output rather than a normative requirement.

				## Consequence

				`expParts` comparison needs a subset comparator rather than exact equality, with a recorded reason per affected case and a check that no recorded exception has gone stale. Exact equality elsewhere.

				## Closing it

				Needs an upstream change to `ext-intl`, or a hand-rolled decomposition of ICU's output, which would be guessing at ICU's internals. Not planned.
				MD,
		],

		'm1' => [
			'title' => 'M1 — simple-message, and the suite that checks it',
			'labels' => ['milestone'],
			'milestone' => 'M1',
			'closed' => true,
			'body' => <<<'MD'
				Scanner, grammar character classes, data model, parser for the whole of `simple-message`, a formatter, and the conformance runner.

				**Done when**

				- [x] `Hello {$name}!` formats
				- [x] `{$missing}` falls back to `{$missing}`
				- [x] `{|a\|b|}` yields `a|b`
				- [x] a lone `}` is a Syntax Error
				- [x] every conformance case runs; unimplemented ones skip with a named reason

				Delivered with 171 of 462 cases asserted. The parser covers all of `simple-message` including functions, options, attributes and markup — wider than first planned, so that the milestone boundary is simply simple-message versus complex-message and no curated fixture subset is needed.
				MD,
		],

		'm2' => [
			'title' => 'M2 — complex messages and validity',
			'labels' => ['milestone'],
			'milestone' => 'M2',
			'body' => <<<'MD'
				`complex-message` and everything that only appears inside one.

				**Scope**

				- `.input` and `.local` declarations
				- quoted patterns `{{…}}`
				- `.match` with selectors and variants, including the `*` catch-all
				- a validator for all six Data Model Errors: variant key mismatch, missing fallback variant, missing selector annotation, duplicate declaration, duplicate option name, duplicate variant

				Note that leading and trailing whitespace is **not** significant in a complex message, the opposite of a simple one.

				**Done when**

				`syntax.json`, `syntax-errors.json` and `data-model-errors.json` pass in full, and `Error\Unsupported` has no remaining uses. Selection cannot run yet — `missing-selector-annotation` is precisely why — so the matcher is parsed and validated here and executed in M3.

				Expected to move roughly 179 cases from skipped to asserted, the largest single gain in the build, with no ICU involved.
				MD,
		],

		'm3' => [
			'title' => 'M3 — the function registry, resolved values and selection',
			'labels' => ['milestone'],
			'milestone' => 'M3',
			'body' => <<<'MD'
				The seam every function hangs off, and the algorithm that uses them to choose a pattern.

				**Scope**

				- the function handler interface and its context
				- resolved values, including the fallback value
				- option resolution: literal-sourced options distinguished from variable-sourced ones
				- the pattern-selection algorithm, with variant keys compared after NFC normalisation
				- `:string`

				**Done when** `functions/string.json` passes.
				MD,
		],

		'm4' => [
			'title' => "M4 — the specification's test functions",
			'labels' => ['milestone'],
			'milestone' => 'M4',
			'body' => <<<'MD'
				`:test:function`, `:test:select` and `:test:format`, which exist so that implementations can check the selection algorithm without involving locale data.

				**Why before `:number`**

				Pattern selection is the hardest algorithm in the specification, and these exercise it in locale `und` with no ICU involved at all. Proving it before plural rules exist means a later plural bug cannot be mistaken for a selection bug.

				**Done when** `pattern-selection.json` and `fallback.json` pass.
				MD,
		],

		'm5' => [
			'title' => 'M5 — exact numerics and number formatting',
			'labels' => ['milestone'],
			'milestone' => 'M5',
			'body' => <<<'MD'
				**Scope**

				- an exact decimal representation: sign, integer digits, fraction digits and exponent held as strings, so selection and exact-key matching never lose precision
				- the number option set, validated
				- a pure mapping from those options to an ICU number skeleton
				- `:number` and `:integer`, formatting only

				Options are mapped to a skeleton rather than to `NumberFormatter`'s attribute API, which cannot express `signDisplay`, `trailingZeroDisplay`, `roundingPriority` or `useGrouping=min2`.

				**Done when** every formatting and Bad Operand case in `functions/number.json` and `functions/integer.json` passes.

				Closes {{numeric}}.
				MD,
		],

		'm6' => [
			'title' => 'M6 — plural selection',
			'labels' => ['milestone'],
			'milestone' => 'M6',
			'body' => <<<'MD'
				Plural operands computed from an exact decimal, plural categories, and `select=plural|ordinal|exact` including the rule that an exact key wins over a category key.

				**Done when** the remaining selection cases in `functions/number.json` and `functions/integer.json` pass.

				See {{plural}} for a correctness gap this milestone introduces and M11 closes.
				MD,
		],

		'm7' => [
			'title' => 'M7 — the error model',
			'labels' => ['milestone'],
			'milestone' => 'M7',
			'body' => <<<'MD'
				**Scope**

				- an error collector, with strict and fallback modes as configuration rather than branches scattered through the formatter
				- the fallback string table, including re-labelling
				- message-level fallback for input that cannot be parsed at all

				**Done when** every `expErrors` multiset in the suite matches. Until then the conformance runner compares output and the two error categories that have no fallback, Syntax and Data Model, but not runtime errors.

				Worth knowing: `expErrors` is not normative. It is the reference implementation's output, while `errors.md` requires only that an implementation report at least one error. Exact matching is the right default, with documented exceptions.
				MD,
		],

		'm8' => [
			'title' => 'M8 — formatted parts and markup',
			'labels' => ['milestone'],
			'milestone' => 'M8',
			'body' => <<<'MD'
				`formatToParts()`, markup resolution, and the parts JSON shape the suite's `expParts` uses.

				Markup currently does nothing: the specification defines no vocabulary and no string rendering for it, so it reaches a caller only through the parts API. `u:id` is likewise meaningless until then.

				**Done when** every `expParts` assertion passes.

				See {{parts}} for a limit on how granular these can be.
				MD,
		],

		'm9' => [
			'title' => 'M9 — directionality',
			'labels' => ['milestone'],
			'milestone' => 'M9',
			'body' => <<<'MD'
				Base direction, the `u:id` and `u:dir` options, and the default bidi isolation strategy.

				`ext-intl` exposes no `isRightToLeft()`, so the locale-to-direction mapping needs a hand-maintained table of RTL languages and scripts — around forty entries, which change approximately never.

				**Done when** `bidi.json` and `u-options.json` pass. Five cases are currently skipped for isolation alone.
				MD,
		],

		'm10' => [
			'title' => 'M10 — the remaining functions (1.0)',
			'labels' => ['milestone'],
			'milestone' => 'M10',
			'body' => <<<'MD'
				**Scope**

				- `:offset`, `:percent`, `:currency`, `:unit`
				- `:date`, `:time`, `:datetime`
				- a list function, namespaced

				The date and time functions use CLDR semantic skeletons, which `ext-intl` does not expose, so they map onto classic CLDR skeletons instead. They and `:unit` are Draft in the specification and ship marked experimental, outside the semver promise.

				A list function is **not** in the specification's default registry, and the specification requires non-standard functions to be namespaced. A bare `:list` would therefore break on every other implementation, so it ships as `:meraki:list`. There is no `functions/unit.json` upstream, so `:unit` has no conformance coverage at all.

				**Done when** the entire `functions/` directory passes and the whole suite is green. This is 1.0.
				MD,
		],

		'm11' => [
			'title' => 'M11 — CLDR plural rules from data (1.1)',
			'labels' => ['milestone'],
			'milestone' => 'M11',
			'body' => <<<'MD'
				Replace the probe-based plural implementation with generated CLDR tables and an evaluator, plus a test that cross-checks them against the installed ICU.

				Not required by the conformance suite, which cannot see the gap. Required for correctness in roughly forty locales. See {{plural}}.

				**Pull this into 1.0** if any message in use puts a fraction-digit option on a selector in a non-English locale.
				MD,
		],

		'm12' => [
			'title' => 'M12 — hardening',
			'labels' => ['milestone'],
			'milestone' => 'M12',
			'body' => <<<'MD'
				**Scope**

				- deserialisation of the data model, and round-trip property tests against the serialiser and a source stringifier
				- fuzzing the parser
				- a compiled-message cache, for the catalogue package to use instead of re-parsing
				- benchmarks, and documentation

				**Done when** the round-trip properties hold over the whole conformance corpus.
				MD,
		],
	],
];
