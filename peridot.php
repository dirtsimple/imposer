<?php
use Evenement\EventEmitterInterface;
use Peridot\Plugin\Watcher\WatcherPlugin;
use Peridot\Reporter\CodeCoverage\CodeCoverageReporter;
use Peridot\Reporter\CodeCoverageReporters;
use Peridot\Reporter\Dot\DotReporterPlugin;

use WP_CLI\Loggers;

function private_var($cls, $prop) {
	$p = new ReflectionProperty($cls, $prop);
	$p->setAccessible(true);
	return $p;
}

return function(EventEmitterInterface $emitter) {
    $watcher = new WatcherPlugin($emitter);
    $watcher->track(__DIR__ . '/src');

	$coverage = new CodeCoverageReporters($emitter);
	$coverage->register();

	$emitter->on('code-coverage.start', function (CodeCoverageReporter $reporter) {
		$reporter->addDirectoryToWhitelist(__DIR__ . '/src');
	});

	$dot = new DotReporterPlugin($emitter);

	# Suppress WP_CLI console output
	require_once "vendor/wp-cli/wp-cli/php/class-wp-cli.php";

	global $wp_cli_logger;
	$wp_cli_logger = new Loggers\Execution();
	$wp_cli_logger->ob_start();
	WP_CLI::set_logger($wp_cli_logger);

	# Make WP_CLI::error() throw an ExitException instead of exiting
	private_var('WP_CLI', 'capture_exit')->setValue(true);
};
