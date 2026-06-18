# Dynamic Report Generator for Laravel

![Laravel](https://img.shields.io/badge/Laravel-10.x_|_11.x-FF2D20?style=for-the-badge&logo=laravel)
![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php)
![License](https://img.shields.io/badge/License-MIT-blue?style=for-the-badge)

Hi there! 👋 Welcome to the **Dynamic Report Generator**.

If you're a Laravel developer, you've probably been here before: you build a beautiful dashboard, hand it over to the client, and immediately get hit with a flood of requests. *"Can we add a column for the user's last order date? What about filtering by customers who spent over $500 but haven't logged in recently?"*

Before you know it, you're writing endless `LEFT JOIN` statements and creating custom controllers for every single report request. It's a massive bottleneck.

I built this package to solve that exact problem. It's a reporting engine that lets your **non-technical users build their own complex reports** on the fly, safely, without you having to write a single query for them.

---

## ✨ Why You'll Love It

- **No More Manual Joins**: Ever tried to dynamically join 5 different tables based on user input? It's a nightmare. This package uses a Graph-Theory Breadth-First Search (BFS) to look at your Eloquent relationships and automatically find the shortest, most efficient path between models.
- **Virtual Attributes (The Secret Sauce)**: You can pre-write complex, heavy SQL subqueries (like calculating a user's total lifetime value) and register them as a "Virtual Attribute". To your end-users, it just looks like another simple column they can select.
- **Insanely Fast & Memory Efficient**: We don't pull thousands of Eloquent models into PHP memory just to count them. The engine compiles the user's report request and pushes all the heavy lifting down to the database, where it belongs.
- **100% UI Agnostic**: It doesn't care if you use Vue, React, Livewire, or AlpineJS. You just send it a JSON payload, and it spits back optimized data.

## 📦 Installation

Pull it in via Composer:

```bash
composer require nisalatp/dynamicreportgenerator
```

*(Note: If you're running this on a fresh install, you might need to allow minimum stability in your `composer.json` during development).*

## 🚀 Quick Start

Using the package is super straightforward. Your frontend sends a JSON payload of what the user wants to see, and you pass it to the `DynamicReport` facade.

### 1. Generating a Report

```php
use DynamicReport;
use Nisalatp\DynamicReportGenerator\Types\ReportRequest;

// 1. Catch the JSON payload from your frontend UI
$ast = new ReportRequest(
    baseModel: 'User',
    targetModels: ['Order', 'Address'], // The engine figures out the joins for you!
    selectedAttributes: [ /* columns the user wants */ ],
    innerFilters: /* filters the user applied */
);

// 2. Generate the optimized Laravel Query
$query = DynamicReport::generate($ast);

// 3. Return the data
return response()->json($query->paginate(50));
```

### 2. Setting Up Virtual Attributes

Want to give your users the ability to query "Total Spend" without exposing your complex database structure? Register a Virtual Attribute. You can do this in a Service Provider or an Admin Controller.

```php
use Nisalatp\DynamicReportGenerator\Builders\VirtualAttributeBuilder;

VirtualAttributeBuilder::create('Total Spend')
    ->forBaseModel('User')
    ->withSqlFragment('(SELECT SUM(amount) FROM orders WHERE orders.user_id = users.id)')
    ->register();
```

Now, when a user clicks the "Total Spend" checkbox on your frontend, the engine injects that raw SQL safely into the query. It's like magic.

## 🤝 Want to Contribute?

This project is totally free and open-source. If you want to help make it better—whether that's adding NoSQL support, optimizing the graph traversal, or just fixing a typo—I'd love your help! Just fork the repo, make your changes, and open a pull request.

## 📄 License

This package is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT). Enjoy building awesome things!
