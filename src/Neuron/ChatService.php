<?php

namespace App\Neuron;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;

/**
 * The bridge between HTTP and the agent: send a message, read and clear history.
 * Keeps the controller thin and makes the logic testable without HTTP.
 */
final class ChatService
{
    public function __construct(private readonly ShopAgentFactory $agentFactory)
    {
    }

    /**
     * @return array{reply: string, tools_used: list<string>}
     */
    public function send(string $threadId, string $text, ?AIProviderInterface $provider = null): array
    {
        $agent = $this->agentFactory->create($threadId, $provider);

        $knownIds = array_map(
            static fn (Message $message): string => $message->getId(),
            $agent->getChatHistory()->getMessages(),
        );

        $state = $agent->chat(new UserMessage($text));

        // Find which tools the agent called during this turn, for the frontend
        $toolsUsed = [];
        foreach ($agent->getChatHistory()->getMessages() as $message) {
            if ($message instanceof ToolCallMessage && !\in_array($message->getId(), $knownIds, true)) {
                foreach ($message->getToolCalls() as $call) {
                    $toolsUsed[] = $call->getName();
                }
            }
        }

        return [
            'reply' => trim((string) $state->getMessage()?->getContent()),
            'tools_used' => array_values(array_unique($toolsUsed)),
        ];
    }

    /**
     * The visible conversation: user questions and assistant answers, without tool traffic.
     *
     * @return list<array{role: string, text: string}>
     */
    public function history(string $threadId): array
    {
        $history = [];

        foreach ($this->agentFactory->messageStore()->loadAll($threadId) as $message) {
            if ($message instanceof ToolCallMessage || $message instanceof ToolResultMessage) {
                continue;
            }

            $text = trim((string) $message->getContent());
            if ($text === '' || !\in_array($message->getRole(), ['user', 'assistant'], true)) {
                continue;
            }

            $history[] = ['role' => $message->getRole(), 'text' => $text];
        }

        return $history;
    }

    public function clear(string $threadId): void
    {
        $this->agentFactory->messageStore()->clear($threadId);
    }
}
