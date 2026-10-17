<?php

namespace App\Neuron\Tools;

use App\Repository\ShopRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

final class SearchProductsTool extends Tool
{
    protected string $name = 'search_products';

    protected ?string $description = 'Search the product catalogue by keyword or category (Textiles, Decoration, Kitchen, Lighting). Returns price in euros and stock. Use an empty query to list products.';

    public function __construct(private readonly ShopRepository $repository)
    {
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'A keyword in English, e.g. "lamp", "candle" or a category like "Kitchen". Empty string lists products.',
                required: true,
            ),
            new ToolProperty(
                name: 'max_price_eur',
                type: PropertyType::NUMBER,
                description: 'Optional maximum price in euros.',
                required: false,
            ),
            new ToolProperty(
                name: 'in_stock_only',
                type: PropertyType::BOOLEAN,
                description: 'Optional. True to return only products that are in stock.',
                required: false,
            ),
        ];
    }

    public function __invoke(string $query, int|float|null $max_price_eur = null, ?bool $in_stock_only = null): ToolOutput
    {
        $products = $this->repository->searchProducts(
            $query,
            $max_price_eur !== null ? (float) $max_price_eur : null,
            (bool) $in_stock_only,
        );

        return ToolOutput::text(json_encode([
            'query' => $query,
            'count' => \count($products),
            'products' => $products,
        ], JSON_UNESCAPED_UNICODE));
    }
}
