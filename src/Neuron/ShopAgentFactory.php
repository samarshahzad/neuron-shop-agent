<?php

namespace App\Neuron;

use App\Neuron\Tools\CustomerOrdersTool;
use App\Neuron\Tools\FindOrderTool;
use App\Neuron\Tools\SearchProductsTool;
use App\Repository\ShopRepository;
use Doctrine\DBAL\Connection;
use NeuronAI\Chat\History\SQLMessageStore;
use NeuronAI\Providers\AIProviderInterface;
use PDO;
use RuntimeException;

/**
 * Creates a ready-to-use agent for one conversation (thread):
 * AI provider from .env, database tools, and chat history stored in the database,
 * so a conversation survives page reloads and server restarts.
 */
final class ShopAgentFactory
{
    public function __construct(
        private readonly AiProviderFactory $providerFactory,
        private readonly ShopRepository $repository,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param AIProviderInterface|null $provider Override the provider (used in tests with a fake provider)
     */
    public function create(string $threadId, ?AIProviderInterface $provider = null): ShopAssistantAgent
    {
        return ShopAssistantAgent::make()
            ->setAiProvider($provider ?? $this->providerFactory->create())
            ->setMessageStore($this->messageStore())
            ->addTool([
                new FindOrderTool($this->repository),
                new CustomerOrdersTool($this->repository),
                new SearchProductsTool($this->repository),
            ])
            ->setThreadId($threadId);
    }

    public function messageStore(): SQLMessageStore
    {
        $pdo = $this->connection->getNativeConnection();

        if (!$pdo instanceof PDO) {
            throw new RuntimeException('Neuron\'s SQL chat history needs a PDO connection. Use a pdo_* driver (pdo_sqlite, pdo_mysql, pdo_pgsql).');
        }

        return new SQLMessageStore($pdo, 'chat_messages');
    }
}
