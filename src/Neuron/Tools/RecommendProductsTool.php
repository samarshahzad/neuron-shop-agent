<?php

namespace App\Neuron\Tools;

use App\Recommendation\RecommendationEngine;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

/**
 * Recommends products for the SIGNED-IN customer.
 *
 * The customer ID is bound on the server when the agent is created, from the session.
 * The model cannot pass a customer ID or email, so a chat message like
 * "show recommendations for lena@..." can never read another customer's purchases.
 */
final class RecommendProductsTool extends Tool
{
    protected string $name = 'recommend_products';

    protected ?string $description = 'Recommend products for the signed-in customer, based on what they ordered before. Returns products in stock that they have not bought yet, with a reason for each.';

    public function __construct(
        private readonly RecommendationEngine $engine,
        private readonly ?int $customerId,
    ) {
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'How many products to recommend (1 to 6). Default 4.',
                required: false,
            ),
        ];
    }

    public function __invoke(?int $limit = null): ToolOutput
    {
        if ($this->customerId === null) {
            return ToolOutput::text(json_encode([
                'signed_in' => false,
                'message' => 'No customer is signed in. Ask the customer to sign in (button at the top right) to get personal recommendations.',
            ]));
        }

        $result = $this->engine->forCustomer($this->customerId, max(1, min($limit ?? 4, 6)));

        return ToolOutput::text(json_encode([
            'signed_in' => true,
            'method' => $result['method'],
            'recommendations' => array_map(static fn (array $item): array => [
                'sku' => $item['product']['sku'],
                'name' => $item['product']['name'],
                'category' => $item['product']['category'],
                'price_eur' => $item['product']['price_eur'],
                'reason' => $item['reason'],
            ], $result['items']),
        ], JSON_UNESCAPED_UNICODE));
    }
}
