<?php

namespace App\Neuron;

use App\Neuron\Tools\CustomerOrdersTool;
use App\Neuron\Tools\FindOrderTool;
use App\Neuron\Tools\RecommendProductsTool;
use App\Neuron\Tools\SearchProductsTool;
use App\Recommendation\RecommendationEngine;
use App\Repository\ShopRepository;
use Doctrine\DBAL\Connection;
use NeuronAI\Chat\History\SQLMessageStore;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
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
        private readonly RecommendationEngine $recommendations,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param AIProviderInterface|null $provider Override the provider (used in tests with a fake provider)
     * @param array{id: int, name: string, email: string}|null $customer The signed-in customer, from the session
     */
    public function create(string $threadId, ?AIProviderInterface $provider = null, ?array $customer = null): ShopAssistantAgent
    {
        $agent = ShopAssistantAgent::make()
            ->setAiProvider($provider ?? $this->providerFactory->create())
            ->setMessageStore($this->messageStore())
            ->addTool([
                new FindOrderTool($this->repository),
                new CustomerOrdersTool($this->repository),
                new SearchProductsTool($this->repository),
                new RecommendProductsTool($this->recommendations, $customer['id'] ?? null),
            ])
            ->setThreadId($threadId);

        // Sent with every question, but never stored in the chat history
        $agent->setContext(customer: new TextContent($customer === null
            ? 'Signed-in customer: none.'
            : sprintf('Signed-in customer: %s (%s).', $customer['name'], $customer['email'])));

        return $agent;
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
