<?php

declare(strict_types=1);

namespace iikiti\CMS\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\ApiResource\FirewallTrustedAutomationResource;
use iikiti\CMS\Audit\AuditRecorder;
use iikiti\CMS\Entity\Object\FirewallTrustedAutomation;
use iikiti\CMS\Repository\Object\FirewallTrustedAutomationRepository;
use iikiti\CMS\Security\Firewall\Admin\FirewallSiteScope;
use iikiti\CMS\Security\Firewall\Bot\FirewallAutomationSecretCipher;
use iikiti\CMS\State\Mapper\FirewallTrustedAutomationMapper;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Creates and updates trusted automations. The write-only `secret` is converted to protected
 * material before storage: HMAC secrets are encrypted (recoverable for signature checks), header
 * secrets are hashed (comparison only). The plaintext is never persisted or returned.
 *
 * @implements ProcessorInterface<FirewallTrustedAutomationResource,FirewallTrustedAutomationResource>
 */
#[AutoconfigureTag('api_platform.state_processor')]
final readonly class FirewallTrustedAutomationProcessor implements ProcessorInterface
{
	private const GLOBAL_SITE_ID = 0;
	private const MAX_CIDRS = 100;
	private const MAX_PATHS = 50;

	public function __construct(
		private FirewallTrustedAutomationRepository $automations,
		private FirewallAutomationSecretCipher $cipher,
		private FirewallTrustedAutomationMapper $mapper,
		private EntityManagerInterface $entityManager,
		private AuditRecorder $audit,
	) {
	}

	public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FirewallTrustedAutomationResource
	{
		$siteId = array_key_exists('siteId', $uriVariables)
			? FirewallSiteScope::assertCurrent($uriVariables)->getId()
			: self::GLOBAL_SITE_ID;

		$isUpdate = isset($uriVariables['id']);
		$automation = $isUpdate ? $this->findScoped($uriVariables['id'], $siteId) : new FirewallTrustedAutomation();
		$before = $isUpdate ? $this->snapshot($automation) : null;

		try {
			$this->apply($automation, $data);
		} catch (\InvalidArgumentException $exception) {
			throw new BadRequestHttpException($exception->getMessage(), $exception);
		}

		if (!$isUpdate) {
			$automation->setSiteId($siteId);
			$automation->setCreatedDate(new \DateTimeImmutable());
			$automation->setCreatorId(0);
		}
		$this->entityManager->persist($automation);
		$this->entityManager->flush();

		$this->audit->record(
			sprintf('%s trusted automation %s', $isUpdate ? 'Updated' : 'Created', $automation->getHandle()),
			$isUpdate ? 'firewall_automation_updated' : 'firewall_automation_created',
			FirewallTrustedAutomation::class,
			$automation->getId(),
			$before,
			$this->snapshot($automation),
		);

		return $this->mapper->toResource($automation);
	}

	/**
	 * Applies only the provided fields so an update does not reset omitted settings.
	 */
	private function apply(FirewallTrustedAutomation $automation, FirewallTrustedAutomationResource $data): void
	{
		$automation->setName($data->name);
		$automation->setHandle($data->handle);
		$automation->setCategory($data->category);
		$automation->setAuthMode($data->authMode);
		if (count($data->cidrs) > self::MAX_CIDRS || count($data->allowedPaths) > self::MAX_PATHS) {
			throw new \InvalidArgumentException('Too many CIDRs or allowed paths for one automation.');
		}
		$automation->setCidrs($data->cidrs);
		$automation->setAllowedPaths($data->allowedPaths);
		$automation->setHeaderName($data->headerName ?? '');
		$automation->setPublicKey($data->publicKey);
		$automation->setBypassWaf($data->bypassWaf);
		$automation->setRateLimitOverride($data->rateLimitOverride);
		$automation->setEnabled($data->enabled);
		$automation->setPriority($data->priority);

		if ('' === $data->secret) {
			throw new \InvalidArgumentException('A provided secret must not be empty.');
		}
		if (null !== $data->secret) {
			$automation->setSecretMaterial($this->protect($data->authMode, $data->secret));
		} elseif (in_array($data->authMode, ['hmac', 'header_secret'], true) && null === $automation->getSecretMaterial()) {
			throw new \InvalidArgumentException('A secret is required for hmac and header_secret auth modes.');
		} elseif (!in_array($data->authMode, ['hmac', 'header_secret'], true)) {
			$automation->setSecretMaterial(null);
		}
	}

	/** Converts a plaintext secret into the at-rest form for the chosen auth mode. */
	private function protect(string $authMode, string $secret): string
	{
		return match ($authMode) {
			'hmac' => $this->cipher->encryptHmacSecret($secret),
			'header_secret' => $this->cipher->hashHeaderSecret($secret),
			default => throw new \InvalidArgumentException('Secrets apply only to hmac or header_secret auth modes.'),
		};
	}

	private function findScoped(mixed $id, int|string $siteId): FirewallTrustedAutomation
	{
		$automation = $this->automations->find($id ?? '');
		if (!$automation instanceof FirewallTrustedAutomation || (string) $automation->getSiteId() !== (string) $siteId) {
			throw new NotFoundHttpException('Trusted automation not found.');
		}

		return $automation;
	}

	/**
	 * Audit snapshot: identifying fields only. Never includes secret material or public keys.
	 *
	 * @return array<string,mixed>
	 */
	private function snapshot(FirewallTrustedAutomation $automation): array
	{
		return [
			'handle' => $automation->getHandle(),
			'category' => $automation->getCategory(),
			'authMode' => $automation->getAuthMode(),
			'enabled' => $automation->isEnabled(),
			'bypassWaf' => $automation->isBypassWaf(),
		];
	}
}
