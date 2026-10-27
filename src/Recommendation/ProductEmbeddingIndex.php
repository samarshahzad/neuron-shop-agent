<?php

namespace App\Recommendation;

use App\Repository\CatalogueRepository;
use Doctrine\DBAL\Connection;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use RuntimeException;

/**
 * Stores one embedding vector per product in the product_embeddings table.
 *
 * A vector is only recalculated when the product text or the model changes
 * (content_hash), so running the indexer again is cheap and safe.
 */
final class ProductEmbeddingIndex
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CatalogueRepository $catalogue,
    ) {
    }

    /**
     * The text the embeddings model reads for a product.
     *
     * @param array<string, mixed> $product
     */
    public static function productText(array $product): string
    {
        return sprintf(
            '%s. Category: %s. Color: %s. Material: %s. %s',
            $product['name'],
            $product['category'],
            $product['color'],
            $product['material'],
            $product['description'],
        );
    }

    /**
     * @param callable(string $sku, string $status): void|null $progress
     * @return array{embedded: int, unchanged: int, total: int}
     */
    public function build(EmbeddingsProviderInterface $provider, string $modelId, bool $force = false, ?callable $progress = null): array
    {
        $existing = [];
        foreach ($this->connection->fetchAllAssociative('SELECT product_id, model, content_hash FROM product_embeddings') as $row) {
            $existing[(int) $row['product_id']] = $row['model'].'|'.$row['content_hash'];
        }

        $embedded = 0;
        $unchanged = 0;
        $products = $this->catalogue->allProducts();

        foreach ($products as $product) {
            $text = self::productText($product);
            $hash = hash('sha256', $text);

            if (!$force && ($existing[$product['id']] ?? null) === $modelId.'|'.$hash) {
                $unchanged++;
                $progress && $progress($product['sku'], 'unchanged');
                continue;
            }

            $vector = $provider->embedText($text);
            if ($vector === []) {
                throw new RuntimeException(sprintf('The embeddings model returned an empty vector for %s.', $product['sku']));
            }

            $this->connection->transactional(function (Connection $connection) use ($product, $modelId, $hash, $vector): void {
                $connection->delete('product_embeddings', ['product_id' => $product['id']]);
                $connection->insert('product_embeddings', [
                    'product_id' => $product['id'],
                    'model' => $modelId,
                    'content_hash' => $hash,
                    'dimensions' => \count($vector),
                    'vector' => json_encode(array_map('floatval', $vector)),
                    'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);
            });

            $embedded++;
            $progress && $progress($product['sku'], 'embedded');
        }

        return ['embedded' => $embedded, 'unchanged' => $unchanged, 'total' => \count($products)];
    }

    /**
     * All vectors made by this model, keyed by product ID.
     *
     * @return array<int, list<float>>
     */
    public function vectors(string $modelId): array
    {
        $vectors = [];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT product_id, vector FROM product_embeddings WHERE model = ?',
            [$modelId],
        );

        foreach ($rows as $row) {
            $vectors[(int) $row['product_id']] = json_decode((string) $row['vector'], true, flags: JSON_THROW_ON_ERROR);
        }

        return $vectors;
    }
}
