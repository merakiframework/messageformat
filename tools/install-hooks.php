<?php
declare(strict_types=1);

namespace Meraki\MessageFormat\Tools;

/**
 * Points git at .githooks, and never fails the install if it cannot.
 *
 * This runs from `post-install-cmd`, which means it runs during `composer install` — so it is in
 * a position to break the one command everybody runs first. It must not.
 *
 * The reason this is a script rather than `git config core.hooksPath .githooks` written straight
 * into composer.json: that form fails the install wherever .git/config is not writable by
 * whoever is running Composer, and the first place that happened was CI. A GitHub runner checks
 * the repository out as uid 1001 and the container runs as uid 1000, so `git config` returns
 * "could not lock config file .git/config: Permission denied" and Composer propagates the exit
 * status. A hook is a local convenience; it has no business being a precondition for installing
 * dependencies.
 *
 * Nor can the shell take care of it: `|| true` would work under sh but not under the cmd.exe
 * Composer uses on Windows, where `true` is not a command.
 *
 * So: try, say what happened, exit 0 either way. CI does not need the hook, and a contributor
 * whose checkout cannot take it still gets a working vendor/ and a note explaining what they
 * did not get.
 */

$root = dirname(__DIR__);

// Installed from a tarball, or vendored into somebody else's project: there is no repository to
// configure and nothing to report.
if (!file_exists($root . '/.git')) {
	exit(0);
}

$output = [];
$status = 0;

exec('git config core.hooksPath .githooks 2>&1', $output, $status);

if ($status === 0) {
	printf('Hooks installed: git will run .githooks/pre-commit.%s', PHP_EOL);
	exit(0);
}

$why = trim(implode(' ', $output));

printf(
	'Hooks not installed, which is not fatal: %s%s'
		. 'Formatting is still checked by `composer test:style` and by CI. To install them by'
		. ' hand later: git config core.hooksPath .githooks%s',
	$why === '' ? 'git config failed' : $why,
	PHP_EOL,
	PHP_EOL,
);

exit(0);
