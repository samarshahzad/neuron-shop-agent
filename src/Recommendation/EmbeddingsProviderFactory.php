<?php

namespace App\Recommendation;

use InvalidArgumentException;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\GeminiEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;

/**
 * Builds the Neuron embeddings provider from .env / .env.local.
 * EMBEDDINGS_PROVIDER=none switches AI similarity off (rules only).
 */
final class EmbeddingsProviderFactory
{
    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly string $apiKey,
        private readonly string $ollamaUrl,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->providerName() !== 'none' && $this->providerName() !== '';
    }

    /**
     * Identifies which model made a vector. Vectors from different models
     * cannot be compared, so they are stored together with this ID.
     */
    public function modelId(): string
    {
        return $this->providerName().'/'.trim($this->model);
    }

    public function create(): EmbeddingsProviderInterface
    {
        $provider = $this->providerName();

        if (\in_array($provider, ['openai', 'gemini'], true) && trim($this->apiKey) === '') {
            throw new InvalidArgumentException(sprintf('AI_API_KEY is empty. Set it in .env.local to use "%s" embeddings, or use EMBEDDINGS_PROVIDER=ollama.', $provider));
        }

        return match ($provider) {
            'ollama' => new OllamaEmbeddingsProvider(model: $this->model, url: $this->ollamaUrl),
            'openai' => new OpenAIEmbeddingsProvider(key: $this->apiKey, model: $this->model),
            'gemini' => new GeminiEmbeddingsProvider(key: $this->apiKey, model: $this->model),
            default => throw new InvalidArgumentException(sprintf(
                'Unknown EMBEDDINGS_PROVIDER "%s". Use ollama, openai, gemini or none.',
                $this->provider
            )),
        };
    }

    private function providerName(): string
    {
        return strtolower(trim($this->provider));
    }
}
