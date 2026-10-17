<?php

namespace App\Neuron\Tools;

use App\Repository\ShopRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

final class FindOrderTool extends Tool
{
    protected string $name = 'find_order';

    protected ?string $description = 'Look up one order by its order number (e.g. SO-1003). Returns status, date, total, tracking code and the ordered items.';

    public function __construct(private readonly ShopRepository $repository)
    {
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'order_number',
                type: PropertyType::STRING,
                description: 'The order number, for example SO-1003.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $order_number): ToolOutput
    {
        $order = $this->repository->findOrderByNumber($order_number);

        if ($order === null) {
            return ToolOutput::text(json_encode(['found' => false, 'order_number' => $order_number]));
        }

        return ToolOutput::text(json_encode(['found' => true] + $order, JSON_UNESCAPED_UNICODE));
    }
}
