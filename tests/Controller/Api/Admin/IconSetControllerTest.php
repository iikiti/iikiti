<?php

declare(strict_types=1);

namespace iikiti\CMS\Tests\Controller\Api\Admin;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Controller\Api\Admin\IconSetController;
use iikiti\CMS\Entity\IconSetEntity;
use iikiti\CMS\Repository\IconRepository;
use iikiti\CMS\Repository\IconSetRepository;
use iikiti\CMS\Security\PermissionChecker;
use iikiti\CMS\Web\Icon\IconSetManager;
use iikiti\CMS\Web\Icon\SvgSanitizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Admin icon API behaviour: permission gating and the status mapping for accepted and
 * rejected uploads.
 *
 * The real {@see IconSetManager} and {@see SvgSanitizer} are used. Persistence is
 * stubbed: the EntityManager fails the test if a rejected upload ever reaches it, which
 * is the property that matters (a hostile SVG is never stored).
 */
final class IconSetControllerTest extends TestCase
{
	/**
	 * Controller wired the way Symfony wires it: a token storage supplies the user to
	 * AppController::getUser(), so the production code path is exercised unchanged.
	 */
	private function controller(?UserInterface $user): IconSetController
	{
		$token = null === $user ? null : new UsernamePasswordToken($user, 'main', []);
		$tokenStorage = $this->createStub(TokenStorageInterface::class);
		$tokenStorage->method('getToken')->willReturn($token);

		$container = $this->createStub(ContainerInterface::class);
		$container->method('has')->willReturnCallback(static fn (string $id): bool => 'security.token_storage' === $id);
		$container->method('get')->willReturnCallback(static fn (string $id): mixed => 'security.token_storage' === $id ? $tokenStorage : null);

		$security = $this->createStub(Security::class);
		$security->method('getUser')->willReturn($user);

		return new IconSetController($security, $container);
	}

	private function managerWith(EntityManagerInterface $em, IconSetRepository $sets): IconSetManager
	{
		return new IconSetManager($em, $sets, $this->createStub(IconRepository::class), new SvgSanitizer());
	}

	private function persistenceThatMustNotRun(): EntityManagerInterface
	{
		$em = $this->createStub(EntityManagerInterface::class);
		$em->method('persist')->willReturnCallback(static function (): void {
			throw new \LogicException('A rejected SVG must never be persisted.');
		});
		$em->method('remove')->willReturnCallback(static function (): void {
			throw new \LogicException('A rejected SVG must never remove a stored icon.');
		});

		return $em;
	}

	private function setRepositoryReturning(?IconSetEntity $set): IconSetRepository
	{
		$sets = $this->createStub(IconSetRepository::class);
		$sets->method('findOneBySlug')->willReturn($set);

		return $sets;
	}

	private function allowed(): PermissionChecker
	{
		$checker = $this->createStub(PermissionChecker::class);
		$checker->method('canAccess')->willReturn(true);

		return $checker;
	}

	private function denied(): PermissionChecker
	{
		$checker = $this->createStub(PermissionChecker::class);
		$checker->method('canAccess')->willReturn(false);

		return $checker;
	}

	private function user(): UserInterface
	{
		return new \iikiti\CMS\Entity\Object\User();
	}

	public function testAnonymousWriteIsForbiddenAndNothingIsPersisted(): void
	{
		$manager = $this->managerWith($this->persistenceThatMustNotRun(), $this->setRepositoryReturning(null));

		$response = $this->controller(null)->create(
			Request::create('/api/admin/icon-sets', 'POST', [], [], [], [], (string) json_encode(['slug' => 'brand', 'name' => 'Brand'])),
			$manager,
			$this->denied(),
		);

		self::assertSame(403, $response->getStatusCode());
	}

	public function testUserWithoutTemplateWriteIsForbidden(): void
	{
		$manager = $this->managerWith($this->persistenceThatMustNotRun(), $this->setRepositoryReturning(null));

		$response = $this->controller($this->user())->create(
			Request::create('/api/admin/icon-sets', 'POST', [], [], [], [], (string) json_encode(['slug' => 'brand', 'name' => 'Brand'])),
			$manager,
			$this->denied(),
		);

		self::assertSame(403, $response->getStatusCode());
	}

	public function testRejectedSvgReturnsUnprocessableWithReasonAndNeverPersists(): void
	{
		$set = new IconSetEntity('brand', 'Brand');
		$manager = $this->managerWith($this->persistenceThatMustNotRun(), $this->setRepositoryReturning($set));

		$response = $this->controller($this->user())->putIcon(
			'brand',
			'evil',
			Request::create('/x', 'PUT', [], [], [], [], (string) json_encode(['svg' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'])),
			$manager,
			$this->allowed(),
		);

		self::assertSame(422, $response->getStatusCode());
		$body = json_decode((string) $response->getContent(), true);
		self::assertSame('SVG rejected', $body['error']);
		self::assertStringContainsString('onload', $body['reason']);
	}

	public function testUnknownSetReturnsNotFound(): void
	{
		$manager = $this->managerWith($this->persistenceThatMustNotRun(), $this->setRepositoryReturning(null));

		$response = $this->controller($this->user())->putIcon(
			'missing',
			'logo',
			Request::create('/x', 'PUT', [], [], [], [], (string) json_encode(['svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>'])),
			$manager,
			$this->allowed(),
		);

		self::assertSame(404, $response->getStatusCode());
	}

	public function testMalformedIconNameReturnsUnprocessable(): void
	{
		$set = new IconSetEntity('brand', 'Brand');
		$manager = $this->managerWith($this->persistenceThatMustNotRun(), $this->setRepositoryReturning($set));

		$response = $this->controller($this->user())->putIcon(
			'brand',
			'Bad Name!',
			Request::create('/x', 'PUT', [], [], [], [], (string) json_encode(['svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>'])),
			$manager,
			$this->allowed(),
		);

		self::assertSame(422, $response->getStatusCode());
	}
}
