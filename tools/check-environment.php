<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Tools;

/**
 * Asserts the interpreter can actually answer the questions this library asks of it.
 *
 * Every check here is something that fails *silently* rather than loudly. A missing extension
 * raises on first use and is easy to diagnose; minimal ICU locale data does not — intl loads,
 * every call succeeds, and non-English locales quietly answer as if they were English. The
 * plural tests would then be wrong rather than red, and the obvious conclusion would be that
 * the plural code is broken.
 *
 * Run as part of `composer ci`, and by CI before the suite, so an environment fault is reported
 * as an environment fault.
 */

$failures = [];
$notes = [];

if (!extension_loaded('intl')) {
	$failures[] = 'ext-intl is not loaded. Use the dev container (see .devcontainer/) rather than '
		. 'enabling it globally; without a container, ICU version is unpinned and the suite can '
		. 'disagree between machines.';
} else {
	// INTL_ICU_DATA_VERSION is ICU's own data version, not the CLDR release — it tracks ICU, so
	// ICU 76 reports 76.1 here while carrying CLDR 46. Reported as what it is rather than
	// relabelled, because the CLDR number is only derivable by a convention (CLDR = ICU - 30 for
	// every release since ICU 72) that nothing guarantees will hold.
	$notes[] = sprintf('ICU %s, ICU data %s', INTL_ICU_VERSION, INTL_ICU_DATA_VERSION);

	// The real check. These four locales need four different plural rule sets, and a root
	// fallback collapses all of them to one.
	$expected = [
		'cs' => ['1' => 'one', '3' => 'few', '8' => 'other'],
		'ar' => ['0' => 'zero', '2' => 'two', '11' => 'many'],
		'cy' => ['3' => 'few', '6' => 'many'],
		'pl' => ['5' => 'many'],
	];
	$pattern = '{0,plural,zero{zero}one{one}two{two}few{few}many{many}other{other}}';

	foreach ($expected as $locale => $cases) {
		// The keys above are written quoted for readability; PHP has already coerced them to
		// int, which is what formatMessage needs anyway.
		foreach ($cases as $count => $category) {
			$actual = \MessageFormatter::formatMessage($locale, $pattern, [$count]);

			if ($actual === $category) {
				continue;
			}

			$failures[] = sprintf(
				'CLDR plural rules for "%s" are not loaded: %s of %d gave "%s", expected "%s". '
					. 'On Alpine this means icu-data-full is missing and icu-data-en was '
					. 'installed in its place.',
				$locale,
				$locale,
				$count,
				$actual === false ? 'false' : $actual,
				$category,
			);
		}
	}

	// IntlListFormatter is new in PHP 8.5 and needs ICU 67+. The :meraki:list function is built
	// on it, so its absence is a missing feature rather than a missing nicety.
	if (!class_exists(\IntlListFormatter::class)) {
		$failures[] = 'IntlListFormatter is missing. It needs PHP 8.5 and ICU 67 or later.';
	} else {
		$french = new \IntlListFormatter('fr-FR', \IntlListFormatter::TYPE_AND);
		$joined = $french->format(['a', 'b', 'c']);

		if ($joined !== 'a, b et c') {
			$failures[] = sprintf(
				'IntlListFormatter has no French data: got "%s", expected "a, b et c".',
				$joined === false ? 'false' : $joined,
			);
		}
	}
}

if (!extension_loaded('mbstring')) {
	$failures[] = 'ext-mbstring is not loaded. Syntax\Utf8 needs it to validate and convert input.';
}

// There is deliberately no PHP version check here. composer.json requires php ^8.5 and Composer
// refuses to install below it, so a check would be unreachable — and PHPStan says so, because it
// analyses against that same constraint.

// A machine-local timezone would make any date assertion pass here and fail elsewhere.
if (date_default_timezone_get() !== 'UTC') {
	$notes[] = sprintf(
		'timezone is "%s", not UTC — date formatting may differ from CI',
		date_default_timezone_get(),
	);
}

printf('PHP %s%s', PHP_VERSION, PHP_EOL);

foreach ($notes as $note) {
	printf('  %s%s', $note, PHP_EOL);
}

if ($failures === []) {
	printf('Environment is fit for the suite.%s', PHP_EOL);
	exit(0);
}

fwrite(STDERR, PHP_EOL . 'Environment is not fit for the suite:' . PHP_EOL);

foreach ($failures as $failure) {
	fwrite(STDERR, '  - ' . $failure . PHP_EOL);
}

exit(1);
