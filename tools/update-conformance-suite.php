<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Tools;

/**
 * Vendors the Unicode working group's conformance suite, pinned to one commit.
 *
 * A copy rather than a submodule, for two reasons. A submodule makes `composer install` and a
 * plain `git clone` insufficient to run the tests, which turns "the suite is green" into a
 * question about someone's checkout. And a submodule bump is invisible in a diff — it is one
 * line of hex — whereas a copy shows exactly which expectations moved, which is the thing you
 * want to read in review.
 *
 * The cost of a copy is that it can silently fall behind. That is what --check is for: it runs
 * in CI on a schedule, not on every push, so an upstream change arrives as a review prompt
 * rather than as a red build on an unrelated commit.
 *
 * Usage:
 *   php tools/update-conformance-suite.php           download and overwrite
 *   php tools/update-conformance-suite.php --check   exit 1 if the pin is behind upstream
 */

const REPO = 'unicode-org/message-format-wg';
const UPSTREAM_DIR = 'test';

/** Pinned alongside the fixtures because AbnfAgreementTest derives its ranges from it. */
const EXTRA_FILES = ['spec/message.abnf'];

const LOCAL_DIR = __DIR__ . '/../tests/fixtures/conformance';
const PIN_FILE = LOCAL_DIR . '/UPSTREAM';

$arguments = $_SERVER['argv'] ?? [];
$check = is_array($arguments) && in_array('--check', $arguments, true);

$pinned = readPin();
$latest = latestCommit();

if ($check) {
	if ($pinned === null) {
		fail('No pin recorded. Run without --check first.');
	}

	if ($pinned['sha'] === $latest['sha']) {
		echo 'Up to date with ', REPO, '@', substr($latest['sha'], 0, 12), PHP_EOL;
		exit(0);
	}

	fwrite(STDERR, sprintf(
		'Conformance suite is behind upstream.%s  pinned: %s (%s)%s  latest: %s (%s)%s'
			. 'Run `composer suite:update` and review the diff.%s',
		PHP_EOL,
		substr($pinned['sha'], 0, 12),
		$pinned['date'],
		PHP_EOL,
		substr($latest['sha'], 0, 12),
		$latest['date'],
		PHP_EOL,
		PHP_EOL,
	));
	exit(1);
}

$files = treeFor($latest['sha']);

if ($files === []) {
	fail('Found no files under ' . UPSTREAM_DIR . '/. Refusing to wipe the suite.');
}

$total = count($files) + count(EXTRA_FILES);
$short = substr($latest['sha'], 0, 12);

printf('Vendoring %d files from %s@%s%s', $total, REPO, $short, PHP_EOL);

foreach ($files as $path) {
	$relative = substr($path, strlen(UPSTREAM_DIR) + 1);

	write($relative, download($latest['sha'], $path));
}

foreach (EXTRA_FILES as $path) {
	write(basename($path), download($latest['sha'], $path));
}

file_put_contents(PIN_FILE, sprintf(
	'# The conformance suite in this directory is a verbatim copy. Do not edit it by hand.%s'
		. '# Refresh with `composer suite:update`; `composer suite:check` reports when it is stale.%s'
		. 'repository = %s%s'
		. 'directory = %s%s'
		. 'commit = %s%s'
		. 'date = %s%s'
		. 'files = %d%s',
	PHP_EOL,
	PHP_EOL,
	REPO,
	PHP_EOL,
	UPSTREAM_DIR,
	PHP_EOL,
	$latest['sha'],
	PHP_EOL,
	$latest['date'],
	PHP_EOL,
	count($files) + count(EXTRA_FILES),
	PHP_EOL,
));

echo 'Pinned to ', substr($latest['sha'], 0, 12), ' (', $latest['date'], ')', PHP_EOL;

function download(string $sha, string $path): string
{
	return fetch(sprintf('https://raw.githubusercontent.com/%s/%s/%s', REPO, $sha, $path));
}

function write(string $relative, string $body): void
{
	$target = LOCAL_DIR . '/' . $relative;
	$directory = dirname($target);

	if (!is_dir($directory) && !mkdir($directory, 0o777, true)) {
		fail('Could not create ' . $directory);
	}

	// Written byte-for-byte. .gitattributes marks this tree -text so git does not rewrite it.
	file_put_contents($target, $body);
	printf('  %-48s %7d%s', $relative, strlen($body), PHP_EOL);
}

/** @return array{sha: string, date: string} */
function latestCommit(): array
{
	$url = sprintf('https://api.github.com/repos/%s/commits?path=%s&per_page=1', REPO, UPSTREAM_DIR);
	$commits = fetchJson($url);
	$first = $commits[0] ?? null;

	if (!is_array($first)) {
		fail('Could not read the latest commit for ' . UPSTREAM_DIR . '/.');
	}

	$sha = $first['sha'] ?? null;

	if (!is_string($sha) || $sha === '') {
		fail('The latest commit for ' . UPSTREAM_DIR . '/ has no sha.');
	}

	return ['sha' => $sha, 'date' => committerDate($first)];
}

/** @param array<array-key, mixed> $commit */
function committerDate(array $commit): string
{
	$detail = $commit['commit'] ?? null;

	if (!is_array($detail)) {
		return 'unknown';
	}

	$committer = $detail['committer'] ?? null;

	if (!is_array($committer)) {
		return 'unknown';
	}

	$date = $committer['date'] ?? null;

	return is_string($date) ? $date : 'unknown';
}

/** @return list<string> */
function treeFor(string $sha): array
{
	$url = sprintf('https://api.github.com/repos/%s/git/trees/%s?recursive=1', REPO, $sha);
	$entries = fetchJson($url)['tree'] ?? null;

	if (!is_array($entries)) {
		fail('The tree for ' . $sha . ' has no entries.');
	}

	$paths = [];

	foreach ($entries as $entry) {
		if (!is_array($entry)) {
			continue;
		}

		$path = $entry['path'] ?? null;

		if (($entry['type'] ?? null) !== 'blob' || !is_string($path)) {
			continue;
		}

		if (str_starts_with($path, UPSTREAM_DIR . '/')) {
			$paths[] = $path;
		}
	}

	sort($paths);

	return $paths;
}

/** @return array{sha: string, date: string}|null */
function readPin(): ?array
{
	if (!is_file(PIN_FILE)) {
		return null;
	}

	$text = file_get_contents(PIN_FILE);

	if ($text === false) {
		return null;
	}

	$sha = null;
	$date = 'unknown';

	foreach (explode("\n", $text) as $line) {
		$line = trim($line);

		if ($line === '' || str_starts_with($line, '#')) {
			continue;
		}

		[$key, $value] = array_pad(explode('=', $line, 2), 2, '');

		if (trim($key) === 'commit') {
			$sha = trim($value);
		} elseif (trim($key) === 'date') {
			$date = trim($value);
		}
	}

	return $sha === null || $sha === '' ? null : ['sha' => $sha, 'date' => $date];
}

/** @return array<array-key, mixed> */
function fetchJson(string $url): array
{
	$decoded = json_decode(fetch($url), true);

	if (!is_array($decoded)) {
		fail('Expected JSON from ' . $url);
	}

	return $decoded;
}

function fetch(string $url): string
{
	$context = stream_context_create(['http' => [
		'header' => [
			'User-Agent: meraki-messageformat-suite-sync',
			'Accept: application/vnd.github+json',
		],
		'timeout' => 30,
	]]);

	$body = @file_get_contents($url, false, $context);

	if ($body === false) {
		fail('Could not fetch ' . $url);
	}

	return $body;
}

/** @return never */
function fail(string $why): void
{
	fwrite(STDERR, $why . PHP_EOL);
	exit(1);
}
