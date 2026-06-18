<?php

namespace Nisalatp\DynamicReportGenerator\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nisalatp\DynamicReportGenerator\Providers\DynamicReportGeneratorServiceProvider;
use Nisalatp\DynamicReportGenerator\ReportMaker;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\Category;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\Order;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\OrderItem;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\Product;
use Nisalatp\DynamicReportGenerator\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base test case for all integration tests.
 *
 * Boots a minimal Laravel application via Orchestra Testbench, registers the
 * package service provider, runs the package migrations against an in-memory
 * SQLite database, and seeds a deterministic fixture dataset that mirrors the
 * domain model used throughout the FYP report:
 *
 *   User --hasMany--> Order --hasMany--> OrderItem --belongsTo--> Product
 *
 * Seeded data:
 *   Users:    1 Alice (alice@example.com), 2 Bob (email NULL)
 *   Products: 1 Widget (10.00), 2 Gadget (20.00)
 *   Orders:   1 Alice paid 100, 2 Alice pending 50, 3 Bob paid 200
 *   Items:    order1->Widget, order2->Widget, order3->Gadget
 *
 * Derived expectations used by the tests:
 *   - Total spent (SUM orders.amount): Alice = 150, Bob = 200
 *   - SUM amount by status: paid = 300, pending = 50
 *   - Category has no relationships, so it is unreachable from User (noPath).
 */
abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [DynamicReportGeneratorServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // Disabled so fixture inserts and the report_logs "set null" path
            // are not blocked by SQLite FK enforcement during tests.
            'foreign_key_constraints' => false,
        ]);

        $app['config']->set('dynamicreportgenerator.reportable_models', [
            User::class,
            Order::class,
            OrderItem::class,
            Product::class,
            Category::class,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createFixtureSchema();
        // Runs the package's own migrations (the dynamic_* tables, including the ALS tables).
        $this->artisan('migrate')->run();
        $this->seedFixtureData();
    }

    /** Resolve the engine from the IoC container (bound as a singleton). */
    protected function maker(): ReportMaker
    {
        return $this->app->make(ReportMaker::class);
    }

    private function createFixtureSchema(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
            $t->timestamps();
        });

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->decimal('price', 10, 2)->default(0);
            $t->string('category')->nullable();
            $t->timestamps();
        });

        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->decimal('amount', 10, 2)->default(0);
            $t->string('status')->default('pending');
            $t->timestamps();
        });

        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('product_id');
            $t->integer('quantity')->default(1);
            $t->timestamps();
        });

        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
    }

    private function seedFixtureData(): void
    {
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
            ['id' => 2, 'name' => 'Bob', 'email' => null],
        ]);

        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Widget', 'price' => 10.00, 'category' => 'A'],
            ['id' => 2, 'name' => 'Gadget', 'price' => 20.00, 'category' => 'B'],
        ]);

        DB::table('orders')->insert([
            ['id' => 1, 'user_id' => 1, 'amount' => 100.00, 'status' => 'paid'],
            ['id' => 2, 'user_id' => 1, 'amount' => 50.00, 'status' => 'pending'],
            ['id' => 3, 'user_id' => 2, 'amount' => 200.00, 'status' => 'paid'],
        ]);

        DB::table('order_items')->insert([
            ['id' => 1, 'order_id' => 1, 'product_id' => 1, 'quantity' => 2],
            ['id' => 2, 'order_id' => 2, 'product_id' => 1, 'quantity' => 1],
            ['id' => 3, 'order_id' => 3, 'product_id' => 2, 'quantity' => 1],
        ]);

        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Uncategorised'],
        ]);
    }
}
