<?php

namespace App\Neuron\Tools;

use App\Repository\ShopRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

/**
 * DEMO ONLY: in a real shop, never list orders just because someone types an
 * email address. Use the logged-in customer's ID from the session instead.
 */
final class CustomerOrdersTool extends Tool
{
    protected string $name = 'list_customer_orders';

    protected ?string $description = 'List all orders of a customer, newest first, using the customer\'s email address.';

    public function __construct(private readonly ShopRepository $repository)
    {
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'email',
                type: PropertyType::STRING,
                description: 'The customer\'s email address.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $email): ToolOutput
    {
        $orders = $this->repository->findOrdersByCustomerEmail($email);

        return ToolOutput::text(json_encode([
            'email' => $email,
            'count' => \count($orders),
            'orders' => $orders,
        ], JSON_UNESCAPED_UNICODE));
    }
}
