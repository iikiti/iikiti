<?php

declare(strict_types=1);

namespace iikiti\CMS\Security\Firewall;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class FirewallRulePackManager
{
	private const MAX_ENVELOPE_BYTES = 12582912;
	private const MAX_PAYLOAD_BYTES = 8388608;

	public function __construct(
		private HttpClientInterface $httpClient,
		private FirewallRulePackSourceRegistry $sources,
		private FirewallRulePackConverter $converter,
		private FirewallRulePackStore $store,
	) {
	}

	public function refresh(string $sourceKey): void
	{
		$source = $this->sources->get($sourceKey);
		try {
			$response = $this->httpClient->request('GET', $source->getUrl(), [
				'timeout' => 10,
				'max_duration' => 30,
				'max_redirects' => 0,
				'headers' => ['Accept' => 'application/json'],
			]);
			$status = $response->getStatusCode();
			if ($status < 200 || $status >= 300) {
				if ($status < 500) {
					throw new FirewallRulePackRejected(sprintf('Firewall rule-pack source returned non-success HTTP %d.', $status));
				}
				throw new \RuntimeException(sprintf('Firewall rule-pack source returned HTTP %d.', $status));
			}
			$headers = $response->getHeaders(false);
			$contentLength = $headers['content-length'][0] ?? null;
			if (is_string($contentLength) && ctype_digit($contentLength) && (int) $contentLength > self::MAX_ENVELOPE_BYTES) {
				throw new FirewallRulePackRejected('Firewall rule-pack envelope exceeds the maximum size.');
			}
			$contents = '';
			foreach ($this->httpClient->stream($response, 0.5) as $chunk) {
				if ($chunk->isTimeout()) {
					continue;
				}
				$contents .= $chunk->getContent();
				if (strlen($contents) > self::MAX_ENVELOPE_BYTES) {
					throw new FirewallRulePackRejected('Firewall rule-pack envelope exceeds the maximum size.');
				}
			}
			$this->verifyAndActivate($sourceKey, $source->getPublicKey(), $contents);
		} catch (FirewallRulePackRejected $exception) {
			throw $exception;
		} catch (\Throwable $exception) {
			throw new \RuntimeException(sprintf('Unable to refresh firewall rule pack "%s".', $sourceKey), previous: $exception);
		}
	}

	private function verifyAndActivate(string $sourceKey, string $encodedPublicKey, string $contents): void
	{
		try {
			$envelope = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
			$expectedKeys = ['source', 'version', 'format', 'sha256', 'signature', 'payload'];
			if (!is_array($envelope) || [] !== array_diff(array_keys($envelope), $expectedKeys) ||
				[] !== array_diff($expectedKeys, array_keys($envelope)) ||
				($envelope['source'] ?? null) !== $sourceKey ||
				!is_string($version = $envelope['version'] ?? null) ||
				!is_string($format = $envelope['format'] ?? null) ||
				!is_string($checksum = $envelope['sha256'] ?? null) ||
				!is_string($signatureText = $envelope['signature'] ?? null) ||
				!is_string($payloadText = $envelope['payload'] ?? null) ||
				!FirewallRulePackVersion::isValid($version) ||
				!preg_match('/^[a-f0-9]{64}$/D', $checksum)
			) {
				throw new \InvalidArgumentException('Firewall rule-pack envelope is malformed.');
			}
			$payload = base64_decode($payloadText, true);
			$signature = base64_decode($signatureText, true);
			$publicKey = base64_decode($encodedPublicKey, true);
			if (false === $payload || strlen($payload) > self::MAX_PAYLOAD_BYTES || false === $signature ||
				SODIUM_CRYPTO_SIGN_BYTES !== strlen($signature) || false === $publicKey ||
				SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen($publicKey)
			) {
				throw new \InvalidArgumentException('Firewall rule-pack signature or payload is malformed.');
			}
			$actualChecksum = hash('sha256', $payload);
			if (!hash_equals($checksum, $actualChecksum)) {
				throw new \InvalidArgumentException('Firewall rule-pack checksum does not match its payload.');
			}
			$signedMessage = implode("\n", ['iikiti-firewall-pack-v1', $sourceKey, $version, $format, $checksum])."\n".$payload;
			if (!sodium_crypto_sign_verify_detached($signature, $signedMessage, $publicKey)) {
				throw new \InvalidArgumentException('Firewall rule-pack signature verification failed.');
			}
			$currentVersion = $this->store->getActiveVersion($sourceKey);
			if (null !== $currentVersion && FirewallRulePackVersion::isOlderThan($version, $currentVersion)) {
				throw new FirewallRulePackRejected('Scheduled firewall rule-pack refresh cannot downgrade the active version.');
			}
			$rules = $this->converter->convert($format, $payload);
			foreach ($rules as &$rule) {
				$ruleId = $sourceKey.':'.$rule['id'];
				if (strlen($ruleId) > 128) {
					throw new \InvalidArgumentException('Namespaced firewall rule ID exceeds the maximum length.');
				}
				$rule['id'] = $ruleId;
			}
			unset($rule);
			$this->store->activate($sourceKey, $version, $checksum, $rules);
		} catch (FirewallRulePackRejected $exception) {
			throw $exception;
		} catch (\Throwable $exception) {
			throw new FirewallRulePackRejected(sprintf('Firewall rule pack "%s" was rejected.', $sourceKey), previous: $exception);
		}
	}
}
