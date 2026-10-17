<?php

namespace App\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use RuntimeException;

/**
 * Creates the shop tables, the table Neuron uses for chat history,
 * and fills the shop with demo data. Works on SQLite, MySQL and PostgreSQL.
 */
final class ShopDatabase
{
    /** Tables in drop order (children before parents, because of foreign keys). */
    private const TABLES = ['order_items', 'orders', 'products', 'customers', 'chat_messages'];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function isInstalled(): bool
    {
        return $this->connection->createSchemaManager()->tablesExist(['orders']);
    }

    public function drop(): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        foreach (self::TABLES as $table) {
            if ($schemaManager->tablesExist([$table])) {
                $schemaManager->dropTable($table);
            }
        }
    }

    public function create(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        foreach ($this->shopSchema()->toSql($platform) as $sql) {
            $this->connection->executeStatement($sql);
        }

        $this->connection->executeStatement($this->chatMessagesSql());
    }

    /**
     * @return array{customers: int, products: int, orders: int}
     */
    public function seed(): array
    {
        $now = new \DateTimeImmutable();

        $customers = [
            ['Lena Fischer', 'lena.fischer@example.com', 'Berlin'],
            ['Jonas Weber', 'jonas.weber@example.com', 'Hamburg'],
            ['Aylin Demir', 'aylin.demir@example.com', 'Köln'],
            ['Marco Rossi', 'marco.rossi@example.com', 'München'],
        ];

        $customerIds = [];
        foreach ($customers as [$name, $email, $city]) {
            $this->connection->insert('customers', ['name' => $name, 'email' => $email, 'city' => $city]);
            $customerIds[$email] = (int) $this->connection->lastInsertId();
        }

        // sku, name, category, price in cents, stock
        $products = [
            ['KL-1001', 'Linen Cushion Cover, sage', 'Textiles', 2499, 42],
            ['KL-1002', 'Wool Throw Blanket, grey', 'Textiles', 6999, 8],
            ['KL-1003', 'Ceramic Vase, sand', 'Decoration', 3499, 15],
            ['KL-1004', 'Scented Candle, fig & cedar', 'Decoration', 1899, 120],
            ['KL-1005', 'Oak Picture Frame 30x40', 'Decoration', 2999, 0],
            ['KL-1006', 'Stoneware Mug Set (4)', 'Kitchen', 3999, 25],
            ['KL-1007', 'Bamboo Serving Board', 'Kitchen', 2799, 31],
            ['KL-1008', 'Rattan Floor Lamp', 'Lighting', 14999, 4],
            ['KL-1009', 'Glass Pendant Light', 'Lighting', 8999, 0],
            ['KL-1010', 'Jute Rug 160x230', 'Textiles', 12999, 6],
        ];

        $productPrices = [];
        foreach ($products as [$sku, $name, $category, $price, $stock]) {
            $this->connection->insert('products', [
                'sku' => $sku,
                'name' => $name,
                'category' => $category,
                'price_cents' => $price,
                'stock' => $stock,
            ]);
            $productPrices[$sku] = [(int) $this->connection->lastInsertId(), $price];
        }

        // order number, customer email, status, days ago, tracking code, [sku => quantity]
        $orders = [
            ['SO-1001', 'lena.fischer@example.com', 'delivered', 21, 'DHL-00340012345', ['KL-1001' => 2, 'KL-1004' => 1]],
            ['SO-1002', 'jonas.weber@example.com', 'shipped', 3, 'DHL-00340067890', ['KL-1008' => 1]],
            ['SO-1003', 'lena.fischer@example.com', 'processing', 1, null, ['KL-1002' => 1, 'KL-1003' => 1]],
            ['SO-1004', 'aylin.demir@example.com', 'pending', 0, null, ['KL-1006' => 1, 'KL-1007' => 2]],
            ['SO-1005', 'marco.rossi@example.com', 'cancelled', 9, null, ['KL-1009' => 2]],
            ['SO-1006', 'aylin.demir@example.com', 'delivered', 40, 'HERMES-H1029384', ['KL-1010' => 1]],
            ['SO-1007', 'jonas.weber@example.com', 'processing', 2, null, ['KL-1004' => 3, 'KL-1001' => 1]],
        ];

        foreach ($orders as [$number, $email, $status, $daysAgo, $tracking, $items]) {
            $total = 0;
            foreach ($items as $sku => $quantity) {
                $total += $productPrices[$sku][1] * $quantity;
            }

            $this->connection->insert('orders', [
                'order_number' => $number,
                'customer_id' => $customerIds[$email],
                'status' => $status,
                'total_cents' => $total,
                'tracking_code' => $tracking,
                'created_at' => $now->modify("-{$daysAgo} days")->format('Y-m-d H:i:s'),
            ]);
            $orderId = (int) $this->connection->lastInsertId();

            foreach ($items as $sku => $quantity) {
                $this->connection->insert('order_items', [
                    'order_id' => $orderId,
                    'product_id' => $productPrices[$sku][0],
                    'quantity' => $quantity,
                    'unit_price_cents' => $productPrices[$sku][1],
                ]);
            }
        }

        return ['customers' => \count($customers), 'products' => \count($products), 'orders' => \count($orders)];
    }

    private function shopSchema(): Schema
    {
        $schema = new Schema();

        $customers = $schema->createTable('customers');
        $customers->addColumn('id', 'integer', ['autoincrement' => true]);
        $customers->addColumn('name', 'string', ['length' => 120]);
        $customers->addColumn('email', 'string', ['length' => 180]);
        $customers->addColumn('city', 'string', ['length' => 80]);
        $customers->setPrimaryKey(['id']);
        $customers->addUniqueIndex(['email'], 'uniq_customers_email');

        $products = $schema->createTable('products');
        $products->addColumn('id', 'integer', ['autoincrement' => true]);
        $products->addColumn('sku', 'string', ['length' => 20]);
        $products->addColumn('name', 'string', ['length' => 160]);
        $products->addColumn('category', 'string', ['length' => 60]);
        $products->addColumn('price_cents', 'integer');
        $products->addColumn('stock', 'integer');
        $products->setPrimaryKey(['id']);
        $products->addUniqueIndex(['sku'], 'uniq_products_sku');

        $orders = $schema->createTable('orders');
        $orders->addColumn('id', 'integer', ['autoincrement' => true]);
        $orders->addColumn('order_number', 'string', ['length' => 20]);
        $orders->addColumn('customer_id', 'integer');
        $orders->addColumn('status', 'string', ['length' => 20]);
        $orders->addColumn('total_cents', 'integer');
        $orders->addColumn('tracking_code', 'string', ['length' => 40, 'notnull' => false]);
        $orders->addColumn('created_at', 'datetime');
        $orders->setPrimaryKey(['id']);
        $orders->addUniqueIndex(['order_number'], 'uniq_orders_number');
        $orders->addIndex(['customer_id'], 'idx_orders_customer');
        $orders->addForeignKeyConstraint('customers', ['customer_id'], ['id'], [], 'fk_orders_customer');

        $items = $schema->createTable('order_items');
        $items->addColumn('id', 'integer', ['autoincrement' => true]);
        $items->addColumn('order_id', 'integer');
        $items->addColumn('product_id', 'integer');
        $items->addColumn('quantity', 'integer');
        $items->addColumn('unit_price_cents', 'integer');
        $items->setPrimaryKey(['id']);
        $items->addIndex(['order_id'], 'idx_items_order');
        $items->addIndex(['product_id'], 'idx_items_product');
        $items->addForeignKeyConstraint('orders', ['order_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_items_order');
        $items->addForeignKeyConstraint('products', ['product_id'], ['id'], [], 'fk_items_product');

        return $schema;
    }

    /**
     * The exact table layout Neuron's SQLMessageStore expects (see its class docblock).
     * On MySQL the IDs are VARBINARY so thread IDs compare case-sensitively.
     */
    private function chatMessagesSql(): string
    {
        $platform = $this->connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof AbstractMySQLPlatform => <<<'SQL'
                CREATE TABLE chat_messages (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    thread_id VARBINARY(255) NOT NULL,
                    message_id VARBINARY(64) NOT NULL,
                    role VARCHAR(32) NOT NULL,
                    content LONGTEXT NULL,
                    meta LONGTEXT NULL,
                    archived_at DATETIME NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE INDEX idx_thread_message (thread_id, message_id)
                )
                SQL,
            $platform instanceof PostgreSQLPlatform => <<<'SQL'
                CREATE TABLE chat_messages (
                    id BIGSERIAL PRIMARY KEY,
                    thread_id VARCHAR(255) NOT NULL,
                    message_id VARCHAR(64) NOT NULL,
                    role VARCHAR(32) NOT NULL,
                    content TEXT NULL,
                    meta TEXT NULL,
                    archived_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (thread_id, message_id)
                )
                SQL,
            $platform instanceof SQLitePlatform => <<<'SQL'
                CREATE TABLE chat_messages (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    thread_id TEXT NOT NULL,
                    message_id TEXT NOT NULL,
                    role TEXT NOT NULL,
                    content TEXT NULL,
                    meta TEXT NULL,
                    archived_at DATETIME NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (thread_id, message_id)
                )
                SQL,
            default => throw new RuntimeException(
                'Unsupported database platform: '.$platform::class.'. Use SQLite, MySQL or PostgreSQL.'
            ),
        };
    }
}
