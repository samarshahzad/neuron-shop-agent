<?php

namespace App\Recommendation;

use App\Repository\CatalogueRepository;
use NeuronAI\RAG\VectorSimilarity;

/**
 * Recommends products that are similar to what a customer has bought.
 *
 * Hybrid scoring:
 *  - "embeddings": cosine similarity of the AI vectors (meaning of the product text),
 *    plus a small bonus for the same category or color.
 *  - "rules": used automatically when vectors are missing (for example the embedding
 *    model is not installed): same category, color and material.
 *  - "bestsellers": for customers without orders.
 *
 * Always removes products the customer already bought and products out of stock,
 * and shows at most 2 products per category so the list stays varied.
 */
final class RecommendationEngine
{
    public const METHOD_EMBEDDINGS = 'embeddings';
    public const METHOD_RULES = 'rules';
    public const METHOD_BESTSELLERS = 'bestsellers';

    private const MAX_PER_CATEGORY = 2;
    private const CATEGORY_BONUS = 0.04;
    private const COLOR_BONUS = 0.03;

    public function __construct(
        private readonly CatalogueRepository $catalogue,
        private readonly ProductEmbeddingIndex $index,
        private readonly EmbeddingsProviderFactory $embeddings,
    ) {
    }

    /**
     * "Recommended for you": based on everything the customer ordered.
     *
     * @return array{method: string, items: list<array<string, mixed>>}
     */
    public function forCustomer(int $customerId, int $limit = 4): array
    {
        $products = $this->productsById();
        $purchased = array_values(array_filter(
            $this->catalogue->purchasedProductIds($customerId),
            static fn (int $id): bool => isset($products[$id]),
        ));

        if ($purchased === []) {
            return ['method' => self::METHOD_BESTSELLERS, 'items' => $this->bestsellers($products, [], $limit)];
        }

        return $this->recommend($products, $purchased, $limit, 'your');
    }

    /**
     * "Similar products" for one product page (no customer needed).
     *
     * @return array{method: string, items: list<array<string, mixed>>}
     */
    public function similarTo(int $productId, int $limit = 4): array
    {
        $products = $this->productsById();

        if (!isset($products[$productId])) {
            return ['method' => self::METHOD_RULES, 'items' => []];
        }

        return $this->recommend($products, [$productId], $limit, 'this');
    }

    /**
     * Which scoring method would be used right now.
     */
    public function activeMethod(): string
    {
        return $this->vectorsForAll($this->productsById()) !== null ? self::METHOD_EMBEDDINGS : self::METHOD_RULES;
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @param list<int> $sources products the recommendations are based on
     * @return array{method: string, items: list<array<string, mixed>>}
     */
    private function recommend(array $products, array $sources, int $limit, string $owner): array
    {
        $vectors = $this->vectorsForAll($products);
        $method = $vectors !== null ? self::METHOD_EMBEDDINGS : self::METHOD_RULES;

        $scored = [];
        foreach ($products as $id => $candidate) {
            if (\in_array($id, $sources, true) || !$candidate['in_stock']) {
                continue;
            }

            // Compare with every source product and keep the closest one: that becomes the reason
            $best = null;
            foreach ($sources as $sourceId) {
                $source = $products[$sourceId];
                $score = $vectors !== null
                    ? $this->embeddingScore($vectors[$id], $vectors[$sourceId], $candidate, $source)
                    : $this->ruleScore($candidate, $source);

                if ($best === null || $score > $best['score']) {
                    $best = ['score' => $score, 'source' => $source];
                }
            }

            if ($best !== null && $best['score'] > 0) {
                $scored[] = $this->item($candidate, $best['score'], $this->reason($method, $candidate, $best['source'], $owner), $best['source']['sku']);
            }
        }

        usort($scored, static fn (array $a, array $b): int => [$b['score'], $a['product']['id']] <=> [$a['score'], $b['product']['id']]);

        $items = $this->diversify($scored, $limit);

        // Not enough similar products in stock: fill up with bestsellers
        if (\count($items) < $limit) {
            $taken = array_merge($sources, array_map(static fn (array $i): int => $i['product']['id'], $items));
            $items = array_merge($items, $this->bestsellers($products, $taken, $limit - \count($items)));
        }

        return ['method' => $method, 'items' => $items];
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $source
     */
    private function embeddingScore(array $a, array $b, array $candidate, array $source): float
    {
        $score = VectorSimilarity::cosineSimilarity($a, $b);
        $score += $candidate['category'] === $source['category'] ? self::CATEGORY_BONUS : 0.0;
        $score += $candidate['color'] === $source['color'] ? self::COLOR_BONUS : 0.0;

        return round(min(1.0, $score), 4);
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $source
     */
    private function ruleScore(array $candidate, array $source): float
    {
        return round(
            ($candidate['category'] === $source['category'] ? 0.45 : 0.0)
            + ($candidate['color'] === $source['color'] ? 0.30 : 0.0)
            + ($candidate['material'] === $source['material'] ? 0.25 : 0.0),
            4,
        );
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $source
     */
    private function reason(string $method, array $candidate, array $source, string $owner): string
    {
        $target = $owner === 'your' ? 'your '.$source['name'] : 'this product';

        $matches = array_keys(array_filter([
            'category' => $candidate['category'] === $source['category'],
            'color' => $candidate['color'] === $source['color'],
            'material' => $candidate['material'] === $source['material'],
        ]));

        if ($method === self::METHOD_EMBEDDINGS) {
            // The AI decides the match; shared attributes only help explain it
            $shared = array_values(array_diff($matches, ['category']));

            if ($owner !== 'your') {
                // Product page: the dialog already says which product it is about
                return $shared === [] ? 'Similar style and use' : 'Same '.$this->joinWords($shared);
            }

            return 'Similar to '.$target.($shared === [] ? '' : ' (same '.$this->joinWords($shared).')');
        }

        if ($owner !== 'your') {
            return 'Same '.$this->joinWords($matches);
        }

        return sprintf('Same %s as %s', $this->joinWords($matches), $target);
    }

    /**
     * At most MAX_PER_CATEGORY per category, so the list is not 4 cushions.
     *
     * @param list<array<string, mixed>> $scored sorted best first
     * @return list<array<string, mixed>>
     */
    private function diversify(array $scored, int $limit): array
    {
        $result = [];
        $perCategory = [];
        $skipped = [];

        foreach ($scored as $item) {
            $category = $item['product']['category'];
            if (($perCategory[$category] ?? 0) >= self::MAX_PER_CATEGORY) {
                $skipped[] = $item;
                continue;
            }
            $perCategory[$category] = ($perCategory[$category] ?? 0) + 1;
            $result[] = $item;
            if (\count($result) === $limit) {
                return $result;
            }
        }

        // Still room: allow more of the same category rather than showing nothing
        foreach ($skipped as $item) {
            if (\count($result) === $limit) {
                break;
            }
            $result[] = $item;
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @param list<int> $exclude
     * @return list<array<string, mixed>>
     */
    private function bestsellers(array $products, array $exclude, int $limit): array
    {
        $items = [];
        $ids = array_merge($this->catalogue->bestsellerIds(20), array_keys($products));

        foreach (array_unique($ids) as $id) {
            if (\count($items) >= $limit) {
                break;
            }
            if (!isset($products[$id]) || \in_array($id, $exclude, true) || !$products[$id]['in_stock']) {
                continue;
            }
            $items[] = $this->item($products[$id], null, 'Popular in our shop', null);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function item(array $product, ?float $score, string $reason, ?string $basedOnSku): array
    {
        unset($product['description']);

        return [
            'product' => $product,
            'score' => $score,
            'match_percent' => $score === null ? null : (int) round($score * 100),
            'reason' => $reason,
            'based_on_sku' => $basedOnSku,
        ];
    }

    /**
     * Vectors for every product from the current model, or null if any is missing
     * (then we use rules, because mixing vector and rule scores is not comparable).
     *
     * @param array<int, array<string, mixed>> $products
     * @return array<int, list<float>>|null
     */
    private function vectorsForAll(array $products): ?array
    {
        if (!$this->embeddings->isEnabled() || $products === []) {
            return null;
        }

        $vectors = $this->index->vectors($this->embeddings->modelId());

        foreach (array_keys($products) as $id) {
            if (!isset($vectors[$id])) {
                return null;
            }
        }

        return $vectors;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function productsById(): array
    {
        $products = [];
        foreach ($this->catalogue->allProducts() as $product) {
            $products[$product['id']] = $product;
        }

        return $products;
    }

    /**
     * @param list<string> $words
     */
    private function joinWords(array $words): string
    {
        if (\count($words) <= 1) {
            return implode('', $words);
        }

        return implode(', ', \array_slice($words, 0, -1)).' and '.end($words);
    }
}
