<?php

namespace App\Tests\Neuron;

use App\Database\ShopDatabase;
use App\Neuron\AiProviderFactory;
use App\Neuron\ChatService;
use App\Neuron\ShopAgentFactory;
use App\Repository\ShopRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

/**
 * Tests the agent end to end with a real (in-memory SQLite) database and
 * Neuron's FakeAIProvider, so no API key or network is needed.
 */
final class ShopAgentTest extends TestCase
{
    private Connection $connection;
    private ShopRepository $repository;
    private ChatService $chat;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $database = new ShopDatabase($this->connection);
        $database->create();
        $database->seed();

        $this->repository = new ShopRepository($this->connection);
        $factory = new ShopAgentFactory(
            new AiProviderFactory('ollama', 'test-model', '', 'http://localhost:11434/api'),
            $this->repository,
            $this->connection,
        );
        $this->chat = new ChatService($factory);
    }

    public function testRepositoryFindsOrderWithItems(): void
    {
        $order = $this->repository->findOrderByNumber('so-1003');

        self::assertNotNull($order);
        self::assertSame('processing', $order['status']);
        self::assertSame(104.98, $order['total_eur']);
        self::assertCount(2, $order['items']);
        self::assertNull($this->repository->findOrderByNumber('SO-9999'));
    }

    public function testProductSearchFilters(): void
    {
        self::assertCount(2, $this->repository->searchProducts('', 25.0));
        $inStockLighting = $this->repository->searchProducts('lighting', null, true);
        self::assertCount(1, $inStockLighting);
        self::assertSame('KL-1008', $inStockLighting[0]['sku']);
    }

    public function testAgentCallsToolAndSendsRealDataToTheModel(): void
    {
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make('find_order', 'call_1', ['order_number' => 'SO-1003'])]),
            new AssistantMessage('Your order SO-1003 is being packed.'),
        );

        $result = $this->chat->send('test-thread-0001', 'Where is my order SO-1003?', $provider);

        self::assertSame('Your order SO-1003 is being packed.', $result['reply']);
        self::assertSame(['find_order'], $result['tools_used']);

        $recorded = $provider->getRecorded();
        self::assertCount(2, $recorded);
        $secondRequest = json_encode(array_map(static fn ($m) => $m->jsonSerialize(), $recorded[1]->messages));
        self::assertStringContainsString('Ceramic Vase', $secondRequest);
        self::assertStringContainsString('Kiez Living', (string) $recorded[0]->systemPrompt?->getContent());
    }

    public function testConversationIsStoredInTheDatabase(): void
    {
        $this->chat->send('test-thread-0002', 'Hi', new FakeAIProvider(new AssistantMessage('Hello!')));

        // A new agent instance (like a new HTTP request) sees the earlier turn
        $provider = new FakeAIProvider(new AssistantMessage('Gern geschehen!'));
        $this->chat->send('test-thread-0002', 'Danke!', $provider);
        self::assertGreaterThanOrEqual(3, \count($provider->getRecorded()[0]->messages));

        $history = $this->chat->history('test-thread-0002');
        self::assertSame(['user', 'assistant', 'user', 'assistant'], array_column($history, 'role'));
        self::assertSame([], $this->chat->history('other-thread-0001'));

        $this->chat->clear('test-thread-0002');
        self::assertSame([], $this->chat->history('test-thread-0002'));
    }

    public function testMissingApiKeyGivesHelpfulError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AI_API_KEY is empty');

        (new AiProviderFactory('anthropic', 'claude-sonnet-4-5', '', ''))->create();
    }
}
