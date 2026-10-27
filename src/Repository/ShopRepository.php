<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Read-only queries the agent tools and the frontend use.
 * All values come back as plain arrays, with prices already converted to euros.
 */
final class ShopRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findOrderByNumber(string $orderNumber): ?array
    {
        $order = $this->connection->createQueryBuilder()
            ->select('o.id', 'o.order_number', 'o.status', 'o.total_cents', 'o.tracking_code', 'o.created_at', 'c.name AS customer_name', 'c.city AS customer_city')
            ->from('orders', 'o')
            ->innerJoin('o', 'customers', 'c', 'c.id = o.customer_id')
            ->where('UPPER(o.order_number) = UPPER(:number)')
            ->setParameter('number', trim($orderNumber))
            ->executeQuery()
            ->fetchAssociative();

        if ($order === false) {
            return null;
        }

        $items = $this->connection->createQueryBuilder()
            ->select('p.name', 'p.sku', 'i.quantity', 'i.unit_price_cents')
            ->from('order_items', 'i')
            ->innerJoin('i', 'products', 'p', 'p.id = i.product_id')
            ->where('i.order_id = :id')
            ->setParameter('id', $order['id'], ParameterType::INTEGER)
            ->orderBy('i.id')
            ->executeQuery()
            ->fetchAllAssociative();

        return [
            'order_number' => $order['order_number'],
            'status' => $order['status'],
            'ordered_at' => substr((string) $order['created_at'], 0, 10),
            'total_eur' => $this->euros($order['total_cents']),
            'tracking_code' => $order['tracking_code'],
            'customer' => ['name' => $order['customer_name'], 'city' => $order['customer_city']],
            'items' => array_map(fn (array $item): array => [
                'product' => $item['name'],
                'sku' => $item['sku'],
                'quantity' => (int) $item['quantity'],
                'unit_price_eur' => $this->euros($item['unit_price_cents']),
            ], $items),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findOrdersByCustomerEmail(string $email): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('o.order_number', 'o.status', 'o.total_cents', 'o.created_at')
            ->from('orders', 'o')
            ->innerJoin('o', 'customers', 'c', 'c.id = o.customer_id')
            ->where('LOWER(c.email) = LOWER(:email)')
            ->setParameter('email', trim($email))
            ->orderBy('o.created_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(fn (array $row): array => [
            'order_number' => $row['order_number'],
            'status' => $row['status'],
            'ordered_at' => substr((string) $row['created_at'], 0, 10),
            'total_eur' => $this->euros($row['total_cents']),
        ], $rows);
    }

    /**
     * Searches product name, category, color, material and description.
     * An empty query lists products.
     *
     * @return list<array<string, mixed>>
     */
    public function searchProducts(string $query = '', ?float $maxPriceEur = null, bool $inStockOnly = false, int $limit = 8): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('sku', 'name', 'category', 'color', 'material', 'price_cents', 'stock')
            ->from('products')
            ->orderBy('price_cents')
            ->setMaxResults(max(1, min($limit, 20)));

        $query = trim($query);
        if ($query !== '') {
            $qb->andWhere('LOWER(name) LIKE :q OR LOWER(category) LIKE :q OR LOWER(color) LIKE :q OR LOWER(material) LIKE :q OR LOWER(description) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower($query).'%');
        }

        if ($maxPriceEur !== null) {
            $qb->andWhere('price_cents <= :max')
                ->setParameter('max', (int) round($maxPriceEur * 100), ParameterType::INTEGER);
        }

        if ($inStockOnly) {
            $qb->andWhere('stock > 0');
        }

        return array_map(fn (array $row): array => [
            'sku' => $row['sku'],
            'name' => $row['name'],
            'category' => $row['category'],
            'color' => $row['color'],
            'material' => $row['material'],
            'price_eur' => $this->euros($row['price_cents']),
            'in_stock' => (int) $row['stock'] > 0,
            'stock' => (int) $row['stock'],
        ], $qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * Latest orders for the side panel of the frontend.
     *
     * @return list<array<string, mixed>>
     */
    public function recentOrders(int $limit = 10): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('o.order_number', 'o.status', 'o.total_cents', 'o.created_at', 'c.name AS customer_name', 'c.email AS customer_email')
            ->from('orders', 'o')
            ->innerJoin('o', 'customers', 'c', 'c.id = o.customer_id')
            ->orderBy('o.created_at', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(fn (array $row): array => [
            'order_number' => $row['order_number'],
            'status' => $row['status'],
            'ordered_at' => substr((string) $row['created_at'], 0, 10),
            'total_eur' => $this->euros($row['total_cents']),
            'customer_name' => $row['customer_name'],
            'customer_email' => $row['customer_email'],
        ], $rows);
    }

    private function euros(int|string $cents): float
    {
        return round(((int) $cents) / 100, 2);
    }
}
