<?php

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Products and customers for the product page, the sign-in and the recommendations.
 */
final class CatalogueRepository
{
    private const PRODUCT_COLUMNS = ['p.id', 'p.sku', 'p.name', 'p.category', 'p.color', 'p.material', 'p.description', 'p.price_cents', 'p.stock'];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allProducts(?string $category = null): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select(...self::PRODUCT_COLUMNS)
            ->from('products', 'p')
            ->orderBy('p.category')
            ->addOrderBy('p.name');

        if ($category !== null && $category !== '') {
            $qb->where('p.category = :category')->setParameter('category', $category);
        }

        return array_map($this->mapProduct(...), $qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * @return list<string>
     */
    public function categories(): array
    {
        return $this->connection->createQueryBuilder()
            ->select('DISTINCT category')
            ->from('products')
            ->orderBy('category')
            ->executeQuery()
            ->fetchFirstColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findProductBySku(string $sku): ?array
    {
        $row = $this->connection->createQueryBuilder()
            ->select(...self::PRODUCT_COLUMNS)
            ->from('products', 'p')
            ->where('UPPER(p.sku) = UPPER(:sku)')
            ->setParameter('sku', trim($sku))
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $this->mapProduct($row);
    }

    /**
     * @return list<array{id: int, name: string, email: string, city: string, orders: int}>
     */
    public function customers(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('c.id', 'c.name', 'c.email', 'c.city', 'COUNT(o.id) AS orders')
            ->from('customers', 'c')
            ->leftJoin('c', 'orders', 'o', 'o.customer_id = c.id')
            ->groupBy('c.id', 'c.name', 'c.email', 'c.city')
            ->orderBy('c.name')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'city' => $row['city'],
            'orders' => (int) $row['orders'],
        ], $rows);
    }

    /**
     * @return array{id: int, name: string, email: string, city: string}|null
     */
    public function findCustomer(int $id): ?array
    {
        $row = $this->connection->createQueryBuilder()
            ->select('id', 'name', 'email', 'city')
            ->from('customers')
            ->where('id = :id')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : ['id' => (int) $row['id'], 'name' => $row['name'], 'email' => $row['email'], 'city' => $row['city']];
    }

    /**
     * Sign-in check: the order must exist AND belong to this email address.
     * Returns null in both failure cases, so nobody can probe which emails exist.
     */
    public function findCustomerByEmailAndOrder(string $email, string $orderNumber): ?array
    {
        $id = $this->connection->createQueryBuilder()
            ->select('c.id')
            ->from('customers', 'c')
            ->innerJoin('c', 'orders', 'o', 'o.customer_id = c.id')
            ->where('LOWER(c.email) = LOWER(:email)')
            ->andWhere('UPPER(o.order_number) = UPPER(:number)')
            ->setParameter('email', trim($email))
            ->setParameter('number', trim($orderNumber))
            ->executeQuery()
            ->fetchOne();

        return $id === false ? null : $this->findCustomer((int) $id);
    }

    /**
     * Orders of one customer, newest first, with their items.
     *
     * @return list<array<string, mixed>>
     */
    public function ordersForCustomer(int $customerId): array
    {
        $orders = $this->connection->createQueryBuilder()
            ->select('id', 'order_number', 'status', 'total_cents', 'created_at')
            ->from('orders')
            ->where('customer_id = :id')
            ->setParameter('id', $customerId, ParameterType::INTEGER)
            ->orderBy('created_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        if ($orders === []) {
            return [];
        }

        // One query for all items (no N+1 query per order)
        $items = $this->connection->createQueryBuilder()
            ->select('i.order_id', 'p.sku', 'p.name', 'i.quantity')
            ->from('order_items', 'i')
            ->innerJoin('i', 'products', 'p', 'p.id = i.product_id')
            ->where('i.order_id IN (:ids)')
            ->setParameter('ids', array_map(static fn (array $o): int => (int) $o['id'], $orders), ArrayParameterType::INTEGER)
            ->orderBy('i.id')
            ->executeQuery()
            ->fetchAllAssociative();

        $itemsByOrder = [];
        foreach ($items as $item) {
            $itemsByOrder[(int) $item['order_id']][] = ['sku' => $item['sku'], 'name' => $item['name'], 'quantity' => (int) $item['quantity']];
        }

        return array_map(static fn (array $o): array => [
            'order_number' => $o['order_number'],
            'status' => $o['status'],
            'ordered_at' => substr((string) $o['created_at'], 0, 10),
            'total_eur' => round(((int) $o['total_cents']) / 100, 2),
            'items' => $itemsByOrder[(int) $o['id']] ?? [],
        ], $orders);
    }

    /**
     * Products the customer has ordered. Cancelled orders are included on purpose:
     * they still tell us what the customer likes.
     *
     * @return list<int>
     */
    public function purchasedProductIds(int $customerId): array
    {
        $ids = $this->connection->createQueryBuilder()
            ->select('DISTINCT i.product_id')
            ->from('order_items', 'i')
            ->innerJoin('i', 'orders', 'o', 'o.id = i.order_id')
            ->where('o.customer_id = :id')
            ->setParameter('id', $customerId, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map('intval', $ids);
    }

    /**
     * Product IDs ordered by how often they were bought (for customers without orders).
     *
     * @return list<int>
     */
    public function bestsellerIds(int $limit = 10): array
    {
        $ids = $this->connection->createQueryBuilder()
            ->select('i.product_id', 'SUM(i.quantity) AS sold')
            ->from('order_items', 'i')
            ->innerJoin('i', 'orders', 'o', 'o.id = i.order_id')
            ->where("o.status <> 'cancelled'")
            ->groupBy('i.product_id')
            ->orderBy('sold', 'DESC')
            ->addOrderBy('i.product_id')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map('intval', $ids);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapProduct(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'sku' => $row['sku'],
            'name' => $row['name'],
            'category' => $row['category'],
            'color' => $row['color'],
            'material' => $row['material'],
            'description' => $row['description'],
            'price_eur' => round(((int) $row['price_cents']) / 100, 2),
            'stock' => (int) $row['stock'],
            'in_stock' => (int) $row['stock'] > 0,
        ];
    }
}
