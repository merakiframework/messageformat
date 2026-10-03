<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Tools;

/**
 * Creates this project's labels, milestones and issues on GitHub, from tracker-content.php.
 *
 * ## Why this is in the repository
 *
 * It has already run once and refuses to run again here, so it is not a tool anybody needs
 * day to day. It is kept for two reasons. It is the record of how the tracker was seeded —
 * reading it tells you what the milestones are and why, without clicking through twelve pages —
 * and `meraki/messageformat-resource` will want the same thing with different content, which is
 * why the content lives in its own file and the repository is an argument.
 *
 * It is deliberately **not** a composer script. `suite:update` is routine maintenance; this is a
 * one-off administrative action against a live repository, and burying it behind
 * `composer something` would make it look routine.
 *
 * ## Usage
 *
 *   php tools/seed-tracker.php --dry-run
 *   php tools/seed-tracker.php
 *   php tools/seed-tracker.php --repo=merakiframework/messageformat-resource --content=other.php
 *
 * Needs `gh` on PATH, authenticated with the `repo` scope. --dry-run prints the plan and
 * touches nothing, and is the only sensible first run.
 *
 * ## The refusal
 *
 * If the repository already has any milestone or issue, this stops. Seeding twice would
 * duplicate every object silently, and there is no safe way to guess which existing issue
 * corresponds to which entry in the content file. Deleting the guard is not the way to re-run
 * it; deleting the duplicates afterwards is much worse.
 */

const DEFAULT_REPO = 'merakiframework/messageformat';

$arguments = $_SERVER['argv'] ?? [];
$arguments = is_array($arguments) ? $arguments : [];

$repo = DEFAULT_REPO;
$content = __DIR__ . '/tracker-content.php';
$dryRun = false;

foreach ($arguments as $argument) {
	if (!is_string($argument)) {
		continue;
	}

	if ($argument === '--dry-run') {
		$dryRun = true;
	} elseif (str_starts_with($argument, '--repo=')) {
		$repo = substr($argument, 7);
	} elseif (str_starts_with($argument, '--content=')) {
		$content = substr($argument, 10);
	}
}

if (!is_file($content)) {
	fail('No content file at ' . $content);
}

/** @var array{labels: array<string, array{string, string}>, milestones: array<string, array{string, string}>, issues: array<string, array{title: string, body: string, labels: list<string>, milestone: string|null, closed?: bool}>} $plan */
$plan = require $content;

echo 'Repository: ', $repo, PHP_EOL;
echo 'Content:    ', $content, PHP_EOL;
echo 'Mode:       ', $dryRun ? 'dry run, nothing will be created' : 'creating', PHP_EOL, PHP_EOL;

if (!$dryRun) {
	refuseIfSeeded($repo);
}

// ------------------------------------------------------------------------------- labels

foreach ($plan['labels'] as $name => [$colour, $description]) {
	if ($dryRun) {
		printf('  label      %s (#%s)%s', $name, $colour, PHP_EOL);
		continue;
	}

	post($repo, 'labels', ['name' => $name, 'color' => $colour, 'description' => $description]);
	printf('  label      %s%s', $name, PHP_EOL);
}

// --------------------------------------------------------------------------- milestones

/** @var array<string, int> $milestoneNumbers keyed by the tag before the em dash, e.g. "M2" */
$milestoneNumbers = [];

foreach ($plan['milestones'] as $title => [$description, $state]) {
	$tag = tagOf($title);

	if ($dryRun) {
		printf('  milestone  %-4s %s (%s)%s', $tag, $title, $state, PHP_EOL);
		$milestoneNumbers[$tag] = 0;
		continue;
	}

	$created = post($repo, 'milestones', [
		'title' => $title,
		'description' => $description,
		'state' => $state,
	]);

	$number = $created['number'] ?? null;

	if (!is_int($number)) {
		fail('Milestone created without a number: ' . $title);
	}

	$milestoneNumbers[$tag] = $number;
	printf('  milestone  #%-3d %s (%s)%s', $number, $title, $state, PHP_EOL);
}

// -------------------------------------------------------------------------------- issues

// Two passes. An issue body may name another by key, and a number only exists once created, so
// everything is created first and the cross-references are patched in afterwards. The
// alternative -- ordering the content file so referents come first -- makes the content fragile
// in a way nobody would notice until a link pointed at the wrong issue.
$issueNumbers = [];

foreach ($plan['issues'] as $key => $issue) {
	$milestone = $issue['milestone'];

	if ($milestone !== null && !isset($milestoneNumbers[$milestone])) {
		fail(sprintf('Issue "%s" names milestone "%s", which the content file does not define.', $key, $milestone));
	}

	if ($dryRun) {
		printf('  issue      %-10s %s%s', $key, $issue['title'], PHP_EOL);
		$issueNumbers[$key] = 0;
		continue;
	}

	$payload = [
		'title' => $issue['title'],
		'body' => $issue['body'],
		'labels' => $issue['labels'],
	];

	if ($milestone !== null) {
		$payload['milestone'] = $milestoneNumbers[$milestone];
	}

	$created = post($repo, 'issues', $payload);
	$number = $created['number'] ?? null;

	if (!is_int($number)) {
		fail('Issue created without a number: ' . $issue['title']);
	}

	$issueNumbers[$key] = $number;
	printf('  issue      #%-3d %s%s', $number, $issue['title'], PHP_EOL);
}

echo PHP_EOL;

foreach ($plan['issues'] as $key => $issue) {
	$body = resolve($issue['body'], $issueNumbers);
	$closed = $issue['closed'] ?? false;

	if ($body === $issue['body'] && !$closed) {
		continue;
	}

	if ($dryRun) {
		printf('  patch      %-10s %s%s', $key, $closed ? '(close)' : '(links)', PHP_EOL);
		continue;
	}

	$payload = ['body' => $body];

	if ($closed) {
		$payload['state'] = 'closed';
		$payload['state_reason'] = 'completed';
	}

	patch($repo, 'issues/' . $issueNumbers[$key], $payload);
	printf('  patch      #%-3d %s%s', $issueNumbers[$key], $closed ? '(closed)' : '(links)', PHP_EOL);
}

// Every placeholder must have been consumed. One left over would ship a literal `{{numeric}}`
// into a public issue body, which is the sort of thing nobody notices for months.
foreach ($plan['issues'] as $key => $issue) {
	if (preg_match('/\{\{[a-z0-9-]+\}\}/', resolve($issue['body'], $issueNumbers), $leftover) === 1) {
		fail(sprintf('Issue "%s" still contains %s after resolution.', $key, $leftover[0]));
	}
}

echo PHP_EOL, $dryRun ? 'Dry run complete; nothing was created.' : 'Done.', PHP_EOL;

/**
 * `{{key}}` becomes `#number`, so content can cross-reference without knowing numbers.
 *
 * @param array<string, int> $numbers
 */
function resolve(string $body, array $numbers): string
{
	foreach ($numbers as $key => $number) {
		$body = str_replace('{{' . $key . '}}', '#' . $number, $body);
	}

	return $body;
}

/** The short tag a milestone title starts with, used to attach issues to it. */
function tagOf(string $title): string
{
	$parts = preg_split('/\s/', trim($title), 2);

	return $parts === false || $parts === [] ? $title : $parts[0];
}

function refuseIfSeeded(string $repo): void
{
	$milestones = get($repo, 'milestones?state=all&per_page=100');
	$issues = get($repo, 'issues?state=all&per_page=100');

	if ($milestones === [] && $issues === []) {
		return;
	}

	fail(sprintf(
		'%s already has %d milestone(s) and %d issue(s). Seeding again would duplicate every'
			. PHP_EOL . 'object, and nothing here could tell which existing issue matched which entry.'
			. PHP_EOL . 'Use --dry-run to see what this would have created.',
		$repo,
		count($milestones),
		count($issues),
	));
}

/**
 * @param array<string, mixed> $payload
 * @return array<array-key, mixed>
 */
function post(string $repo, string $path, array $payload): array
{
	return send($repo, $path, $payload, 'POST');
}

/**
 * @param array<string, mixed> $payload
 * @return array<array-key, mixed>
 */
function patch(string $repo, string $path, array $payload): array
{
	return send($repo, $path, $payload, 'PATCH');
}

/** @return list<mixed> */
function get(string $repo, string $path): array
{
	$command = sprintf('gh api %s 2>&1', escapeshellarg('repos/' . $repo . '/' . $path));
	$decoded = json_decode((string) shell_exec($command), true);

	return is_array($decoded) ? array_values($decoded) : [];
}

/**
 * @param array<string, mixed> $payload
 * @return array<array-key, mixed>
 */
function send(string $repo, string $path, array $payload, string $method): array
{
	$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

	if ($json === false) {
		fail('Could not encode the payload for ' . $path);
	}

	// Piped on stdin rather than passed as flags, so that no body needs shell escaping. An issue
	// body here contains braces, dollars, backticks and newlines.
	$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
	$command = sprintf(
		'gh api -X %s %s --input -',
		$method,
		escapeshellarg('repos/' . $repo . '/' . $path),
	);

	$process = proc_open($command, $descriptors, $pipes);

	if (!is_resource($process)) {
		fail('Could not run gh. Is it installed and on PATH?');
	}

	fwrite($pipes[0], $json);
	fclose($pipes[0]);

	$out = (string) stream_get_contents($pipes[1]);
	$error = (string) stream_get_contents($pipes[2]);

	fclose($pipes[1]);
	fclose($pipes[2]);

	if (proc_close($process) !== 0) {
		fail(sprintf('gh failed on %s %s:%s%s', $method, $path, PHP_EOL, trim($error)));
	}

	$decoded = json_decode($out, true);

	return is_array($decoded) ? $decoded : [];
}

/** @return never */
function fail(string $why): void
{
	fwrite(STDERR, $why . PHP_EOL);
	exit(1);
}
