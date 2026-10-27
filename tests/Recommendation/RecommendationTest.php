<?php

namespace App\Tests\Recommendation;

use App\Database\ShopDatabase;
use App\Neuron\AiProviderFactory;
use App\Neuron\ChatService;
use App\Neuron\ShopAgentFactory;
use App\Neuron\Tools\RecommendProductsTool;
use App\Recommendation\EmbeddingsProviderFactory;
use App\Recommendation\ProductEmbeddingIndex;
use App\Recommendation\RecommendationEngine;
use App\Repository\CatalogueRepository;
use App\Repository\ShopRepository;
use App\Tests\Support\WordCountEmbeddings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

/**
 * Recommendations with a real (in-memory SQLite) database and a deterministic
 * test embeddings model, so no AI service is needed.
 */
final class RecommendationTest extends TestCase
{
    private Connection $connection;
    private CatalogueRepository $catalogue;
    private ProductEmbeddingIndex $index;
    private EmbeddingsProviderFactory $embeddingsConfig;
    private RecommendationEngine $engine;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $database = new ShopDatabase($this->connection);
        $database->create();
        $database->seed();

        $this->catalogue = new CatalogueRepository($this->connection);
        $this->index = new ProductEmbeddingIndex($this->connection, $this->catalogue);
        $this->embeddingsConfig = new EmbeddingsProviderFactory('ollama', 'nomic-embed-text', '', 'http://localhost:11434/api');
        $this->engine = new RecommendationEngine($this->catalogue, $this->index, $this->embeddingsConfig);
    }

    public function testFallsBackToRulesWithoutEmbeddings(): void
    {
        $result = $this->engine->forCustomer($this->customerId('lena.fischer@example.com'));

        self::assertSame(RecommendationEngine::METHOD_RULES, $result['method']);
        self::assertCount(4, $result['items']);
        self::assertStringStartsWith('Same ', $result['items'][0]['reason']);
    }

    public function testUsesEmbeddingsWhenAllProductsHaveVectors(): void
    {
        $this->index->build(new WordCountEmbeddings(), $this->embeddingsConfig->modelId());

        // Emre bought a rattan pendant lamp: the rattan floor lamp must come first
        $result = $this->engine->forCustomer($this->customerId('emre.yilmaz@example.com'));

        self::assertSame(RecommendationEngine::METHOD_EMBEDDINGS, $result['method']);
        self::assertSame('KL-1008', $result['items'][0]['product']['sku']);
        self::assertStringContainsString('Rattan Pendant Lamp', $result['items'][0]['reason']);
        self::assertNotNull($result['items'][0]['match_percent']);
    }

    public function testNeverRecommendsBoughtOrSoldOutProducts(): void
    {
        $this->index->build(new WordCountEmbeddings(), $this->embeddingsConfig->modelId());

        foreach ($this->catalogue->customers() as $customer) {
            $bought = $this->catalogue->purchasedProductIds($customer['id']);
            $result = $this->engine->forCustomer($customer['id'], 6);

            foreach ($result['items'] as $item) {
                self::assertNotContains($item['product']['id'], $bought, $customer['name'].' got a product they already bought');
                self::assertTrue($item['product']['in_stock'], $customer['name'].' got a sold-out product');
            }
        }
    }

    public function testShowsAtMostTwoProductsPerCategory(): void
    {
        $this->index->build(new WordCountEmbeddings(), $this->embeddingsConfig->modelId());

        $result = $this->engine->forCustomer($this->customerId('lena.fischer@example.com'), 4);
        $perCategory = array_count_values(array_map(static fn (array $i): string => $i['product']['category'], $result['items']));

        self::assertLessThanOrEqual(2, max($perCategory));
    }

    public function testCustomerWithoutOrdersGetsBestsellers(): void
    {
        $this->connection->insert('customers', ['name' => 'New Customer', 'email' => 'new@example.com', 'city' => 'Berlin']);
        $result = $this->engine->forCustomer((int) $this->connection->lastInsertId());

        self::assertSame(RecommendationEngine::METHOD_BESTSELLERS, $result['method']);
        self::assertCount(4, $result['items']);
        self::assertSame('Popular in our shop', $result['items'][0]['reason']);
    }

    public function testSimilarProductsForOneProduct(): void
    {
        $this->index->build(new WordCountEmbeddings(), $this->embeddingsConfig->modelId());
        $lamp = $this->catalogue->findProductBySku('KL-1008');

        $result = $this->engine->similarTo($lamp['id']);

        self::assertSame('KL-1027', $result['items'][0]['product']['sku']); // rattan pendant lamp
        self::assertNotContains('KL-1008', array_map(static fn (array $i): string => $i['product']['sku'], $result['items']));
    }

    public function testIndexOnlyRecalculatesChangedProducts(): void
    {
        $embeddings = new WordCountEmbeddings();
        $model = $this->embeddingsConfig->modelId();

        self::assertSame(36, $this->index->build($embeddings, $model)['embedded']);
        self::assertSame(0, $this->index->build($embeddings, $model)['embedded']);

        $this->connection->update('products', ['description' => 'Now in a new colour.'], ['sku' => 'KL-1001']);
        self::assertSame(1, $this->index->build($embeddings, $model)['embedded']);

        // Another model: every vector must be recalculated (vectors of different models are not comparable)
        self::assertSame(36, $this->index->build($embeddings, 'openai/text-embedding-3-small')['embedded']);
        self::assertSame(36 + 1 + 36, $embeddings->calls);
    }

    public function testSignInNeedsMatchingEmailAndOrderNumber(): void
    {
        self::assertSame('Lena Fischer', $this->catalogue->findCustomerByEmailAndOrder('LENA.fischer@example.com', 'so-1001')['name']);
        self::assertNull($this->catalogue->findCustomerByEmailAndOrder('lena.fischer@example.com', 'SO-1002')); // Jonas' order
        self::assertNull($this->catalogue->findCustomerByEmailAndOrder('nobody@example.com', 'SO-1001'));
    }

    public function testToolAsksToSignInWhenNobodyIsSignedIn(): void
    {
        $tool = new RecommendProductsTool($this->engine, null);
        $output = json_decode((string) $tool()->getText(), true);

        self::assertFalse($output['signed_in']);
    }

    public function testAgentRecommendsForTheSignedInCustomerOnly(): void
    {
        $lena = $this->catalogue->findCustomer($this->customerId('lena.fischer@example.com'));
        $chat = new ChatService(new ShopAgentFactory(
            new AiProviderFactory('ollama', 'test', '', 'http://localhost'),
            new ShopRepository($this->connection),
            $this->engine,
            $this->connection,
        ));

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make('recommend_products', 'call_1', ['limit' => 3])]),
            new AssistantMessage('You might like the linen table runner.'),
        );

        $result = $chat->send('rec-thread-0001', 'What else would go with my order?', $provider, $lena);

        self::assertSame(['recommend_products'], $result['tools_used']);
        $recorded = $provider->getRecorded();

        // The model is told who is signed in ...
        $firstRequest = json_encode(array_map(static fn ($m) => $m->jsonSerialize(), $recorded[0]->messages));
        self::assertStringContainsString('Signed-in customer: Lena Fischer', $firstRequest);

        // ... and the tool result contains Lena's recommendations, not anyone else's
        $toolResult = json_encode(array_map(static fn ($m) => $m->jsonSerialize(), $recorded[1]->messages));
        self::assertStringContainsString('your Linen Cushion Cover', $toolResult);
    }

    private function customerId(string $email): int
    {
        foreach ($this->catalogue->customers() as $customer) {
            if ($customer['email'] === $email) {
                return $customer['id'];
            }
        }
        self::fail('Unknown customer '.$email);
    }
}
