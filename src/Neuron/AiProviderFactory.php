<?php

namespace App\Neuron;

use InvalidArgumentException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Providers\OpenAI\OpenAI;

/**
 * Builds the Neuron AI provider from the values in .env / .env.local,
 * so switching from Ollama to Claude or OpenAI needs no code change.
 */
final class AiProviderFactory
{
    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly string $apiKey,
        private readonly string $ollamaUrl,
    ) {
    }

    public function create(): AIProviderInterface
    {
        $provider = strtolower(trim($this->provider));

        if ($provider !== 'ollama' && trim($this->apiKey) === '') {
            throw new InvalidArgumentException(sprintf(
                'AI_API_KEY is empty. Set it in .env.local to use the "%s" provider, or switch to AI_PROVIDER=ollama.',
                $provider
            ));
        }

        return match ($provider) {
            'anthropic' => new Anthropic(key: $this->apiKey, model: $this->model),
            'openai' => new OpenAI(key: $this->apiKey, model: $this->model),
            'gemini' => new Gemini(key: $this->apiKey, model: $this->model),
            'ollama' => new Ollama(url: $this->ollamaUrl, model: $this->model),
            default => throw new InvalidArgumentException(sprintf(
                'Unknown AI_PROVIDER "%s". Use anthropic, openai, gemini or ollama.',
                $this->provider
            )),
        };
    }

    public function describe(): string
    {
        return sprintf('%s / %s', strtolower(trim($this->provider)), $this->model);
    }
}
