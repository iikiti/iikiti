<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use iikiti\CMS\Kernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$uriPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
if ('/_health' === $uriPath) {
	http_response_code(200);
	header('Connection: close');
	header('Content-Type: text/plain');
	echo 'ok';

	return;
}

$lockFragmentId = getenv('IIKITI_TEST_DB_LOCK_FRAGMENT_ID') ?: '';
$signalFragmentId = getenv('IIKITI_TEST_SIGNAL_FRAGMENT_ID') ?: '';
$lockKey = (int) (getenv('IIKITI_TEST_DB_LOCK_KEY') ?: '0');
$lockWaitFile = getenv('IIKITI_TEST_LOCK_WAIT_FILE') ?: '';
$signalFile = getenv('IIKITI_TEST_SIGNAL_FILE') ?: '';
$timelineFile = getenv('IIKITI_TEST_TIMELINE_FILE') ?: '';
$fragmentId = isset($_GET['_iikiti_fragment_id']) && is_string($_GET['_iikiti_fragment_id']) ? $_GET['_iikiti_fragment_id'] : '';

$appendTimeline = static function (string $event) use ($timelineFile): void {
	if ('' !== $timelineFile) {
		file_put_contents($timelineFile, $event."\n", FILE_APPEND | LOCK_EX);
	}
};

$kernel = new Kernel('test', false);
$kernel->boot();

$request = Request::createFromGlobals();
$lockHeld = false;
/** @var Connection|null $connection */
$connection = null;

try {
	if ('' !== $lockFragmentId && $fragmentId === $lockFragmentId && 0 !== $lockKey) {
		/** @var Connection $connection */
		$connection = $kernel->getContainer()->get('doctrine.dbal.default_connection');
		$appendTimeline('lock_wait_start:'.$fragmentId);
		if ('' !== $lockWaitFile) {
			file_put_contents($lockWaitFile, "waiting\n");
		}
		$connection->executeStatement("SET lock_timeout = '3500ms'");
		$connection->executeQuery(
			'SELECT pg_advisory_lock(:key)',
			['key' => $lockKey],
			['key' => ParameterType::INTEGER],
		)->fetchOne();
		$lockHeld = true;
		$appendTimeline('lock_acquired:'.$fragmentId);
	}

	$response = $kernel->handle($request);
	if ('' !== $signalFragmentId && $fragmentId === $signalFragmentId && 200 === $response->getStatusCode()) {
		$appendTimeline('sibling_rendered:'.$fragmentId);
		if ('' !== $lockWaitFile) {
			$waitDeadline = microtime(true) + 1.5;
			while (!file_exists($lockWaitFile) && microtime(true) < $waitDeadline) {
				usleep(5_000);
			}
		}
		if ('' !== $signalFile) {
			file_put_contents($signalFile, "released\n");
		}
	}

	http_response_code($response->getStatusCode());
	header('Connection: close');
	foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
		foreach ($values as $value) {
			header($name.': '.$value, false);
		}
	}
	echo $response->getContent();
	$kernel->terminate($request, $response);
} finally {
	if (null !== $connection) {
		if ($lockHeld) {
			try {
				$connection->executeQuery(
					'SELECT pg_advisory_unlock(:key)',
					['key' => $lockKey],
					['key' => ParameterType::INTEGER],
				)->fetchOne();
			} catch (\Throwable) {
			}
		}
		try {
			$connection->executeStatement('SET lock_timeout = DEFAULT');
		} catch (\Throwable) {
		}
	}
}
