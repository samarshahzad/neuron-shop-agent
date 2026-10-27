<?php

namespace App\Tests\Support;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

/**
 * Test embeddings without a real AI model: a vector of word counts over a fixed
 * vocabulary. Products that share words (linen, sage, rattan, ...) get similar
 * vectors, so recommendation tests are deterministic and meaningful.
 */
final class WordCountEmbeddings implements EmbeddingsProviderInterface
{
    public int $calls = 0;

    /** @var list<string> */
    private array $vocabulary = [
        'linen', 'wool', 'cotton', 'velvet', 'jute', 'rattan', 'seagrass', 'oak', 'bamboo', 'acacia',
        'ceramic', 'stoneware', 'glass', 'brass', 'enamel', 'soy', 'sage', 'grey', 'cream', 'sand',
        'natural', 'white', 'terracotta', 'olive', 'gold', 'cushion', 'throw', 'vase', 'candle', 'lamp',
        'light', 'basket', 'plates', 'mug', 'napkins', 'table', 'kitchen', 'textiles', 'lighting', 'storage',
        'decoration', 'cosy', 'boho', 'woven',
    ];

    public function embedText(string $text): array
    {
        $this->calls++;
        $words = preg_split('/[^a-z]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $counts = array_count_values($words);

        return array_map(static fn (string $word): float => (float) ($counts[$word] ?? 0), $this->vocabulary);
    }

    public function embedDocument(Document $document): Document
    {
        $document->embedding = $this->embedText($document->content);

        return $document;
    }

    public function embedDocuments(array $documents): array
    {
        return array_map($this->embedDocument(...), $documents);
    }
}
