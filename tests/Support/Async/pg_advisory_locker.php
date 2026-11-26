<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use iikiti\CMS\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$argvList = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
if (\PHP_SAPI !== 'cli' || count($argvList) < 3) {
	fwrite(STDERR, "Usage: php pg_advisory_locker.php <lock_key> <signal_file>\n");
	exit(1);
}

$lockKey = (int) $argvList[1];
$signalFile = (string) $argvList[2];

$kernel = new Kernel('test', false);
$kernel->boot();
/** @var Connection $connection */
$connection = $kernel->getContainer()->get('doctrine.dbal.default_connection');

$connection->executeQuery(
	'SELECT pg_advisory_lock(:key)',
	['key' => $lockKey],
	['key' => ParameterType::INTEGER],
)->fetchOne();

fwrite(STDOUT, "LOCKED\n");
fflush(STDOUT);

$deadline = microtime(true) + 5.0;
while (microtime(true) < $deadline && !file_exists($signalFile)) {
	usleep(5_000);
}

$connection->executeQuery(
	'SELECT pg_advisory_unlock(:key)',
	['key' => $lockKey],
	['key' => ParameterType::INTEGER],
)->fetchOne();
$connection->close();
$kernel->shutdown();
