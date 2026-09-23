<?php

declare(strict_types=1);

namespace iikiti\CMS\Controller\Api\Editor;

use iikiti\CMS\Controller\AppController;
use iikiti\CMS\Repository\Object\ApiTokenRepository;
use iikiti\CMS\Web\BlockEditor\Collaboration\PresenceStore;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admits an editor into a collaboration room over the WebSocket relay.
 *
 * The realtime relay itself is a `y-websocket` Node sidecar (not PHP); this
 * endpoint is the authoritative admission gate: it validates the editor's
 * ephemeral API token and returns the room channel + current presence so the
 * client can join the Yjs-aware relay.
 */
#[AsController]
class EditorRoomController extends AppController
{
	#[Route('/api/editor/room/{contextType}/{contextId}', name: 'api_editor_room', methods: ['GET'])]
	public function enter(Request $request, string $contextType, int $contextId, ApiTokenRepository $tokenRepository, PresenceStore $presence): JsonResponse
	{
		$token = $request->query->get('token');
		$apiToken = is_string($token) ? $tokenRepository->findOneBy(['token' => $token]) : null;

		if (null === $apiToken || $apiToken->isExpired()) {
			return $this->json(['error' => 'Invalid token'], Response::HTTP_UNAUTHORIZED);
		}

		$room = sprintf('room/%s:%d', $contextType, $contextId);

		return $this->json([
			'room' => $room,
			'transport' => 'websocket',
			'presence' => $presence->getPresence($room),
		]);
	}
}
