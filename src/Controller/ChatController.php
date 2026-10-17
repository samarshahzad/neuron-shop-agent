<?php

namespace App\Controller;

use App\Neuron\AiProviderFactory;
use App\Neuron\ChatService;
use App\Repository\ShopRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class ChatController extends AbstractController
{
    private const MAX_MESSAGE_LENGTH = 2000;

    public function __construct(
        private readonly ChatService $chat,
        private readonly ShopRepository $shop,
        private readonly AiProviderFactory $providerFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/', name: 'chat_page', methods: ['GET'])]
    public function page(): Response
    {
        return $this->render('chat/index.html.twig', [
            'model' => $this->providerFactory->describe(),
        ]);
    }

    #[Route('/api/chat', name: 'api_chat', methods: ['POST'])]
    public function send(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $threadId = \is_array($data) ? ($data['threadId'] ?? '') : '';
        $message = \is_array($data) ? trim((string) ($data['message'] ?? '')) : '';

        if (!$this->isValidThreadId($threadId)) {
            return $this->json(['error' => 'Invalid threadId.'], Response::HTTP_BAD_REQUEST);
        }
        if ($message === '' || mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return $this->json(['error' => 'Message must be between 1 and '.self::MAX_MESSAGE_LENGTH.' characters.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return $this->json($this->chat->send($threadId, $message));
        } catch (Throwable $e) {
            $this->logger->error('Agent failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->json([
                'error' => 'The assistant could not answer. Check your AI provider settings in .env.local.',
                // Show the real reason only in development
                'detail' => $this->getParameter('kernel.debug') ? $e->getMessage() : null,
            ], Response::HTTP_BAD_GATEWAY);
        }
    }

    #[Route('/api/history', name: 'api_history', methods: ['GET'])]
    public function history(Request $request): JsonResponse
    {
        $threadId = (string) $request->query->get('threadId', '');
        if (!$this->isValidThreadId($threadId)) {
            return $this->json(['error' => 'Invalid threadId.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['messages' => $this->chat->history($threadId)]);
    }

    #[Route('/api/history', name: 'api_history_clear', methods: ['DELETE'])]
    public function clear(Request $request): JsonResponse
    {
        $threadId = (string) $request->query->get('threadId', '');
        if (!$this->isValidThreadId($threadId)) {
            return $this->json(['error' => 'Invalid threadId.'], Response::HTTP_BAD_REQUEST);
        }

        $this->chat->clear($threadId);

        return $this->json(['cleared' => true]);
    }

    #[Route('/api/orders', name: 'api_orders', methods: ['GET'])]
    public function orders(): JsonResponse
    {
        return $this->json(['orders' => $this->shop->recentOrders()]);
    }

    private function isValidThreadId(mixed $threadId): bool
    {
        return \is_string($threadId) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $threadId) === 1;
    }
}
