# 05. Virtual Attribute Lifecycle

A Virtual Attribute (VA) in the Dynamic Report Generator allows users to assign a name (e.g., `total_revenue`) to a raw SQL aggregate fragment (e.g., `SUM(orders.amount)`). This is critical for filtering on aggregated data using `HAVING` clauses without writing complex manual ASTs.

This document traces the complete lifecycle of a Virtual Attribute from its creation to its execution in the SQL compiler.

---

## 1. Registration Phase

Before a VA can be used, it must be registered with the system. An administrator or backend process creates a record in the `VirtualAttribute` model.

**Example Request:**
```json
{
  "name": "total_revenue",
  "base_model": "Order",
  "type": "integer",
  "sql_fragment": "SUM(orders.amount)"
}
```

*Note: The `VirtualAttributeRegistry` loads these models so the compiler knows they exist.*

---

## 2. Discovery Phase

When a frontend application (like Vue or React) or an AI agent requests the available attributes for the `Order` model, the engine's Schema Discovery abstraction hooks into the `VirtualAttributeRegistry`.

The engine merges the physical database columns with the registered VAs. To distinguish them, the engine prefixes the VA name with `va:`.

**Engine Returns to Frontend:**
```json
[
  "id",
  "amount",
  "user_id",
  "va:total_revenue"
]
```

---

## 3. Query Construction Phase

A user building a report decides they want to filter groups where the `total_revenue > 10000`. The frontend constructs a Filter Leaf node. Because the frontend saw the `va:` prefix during discovery, it knows to flag this attribute with `"isVirtual": true` in the AST.

**The Resulting AST Node:**
```json
{
  "type": "leaf",
  "attribute": {
    "modelClass": "Order",
    "column": "total_revenue",
    "type": "integer",
    "isVirtual": true
  },
  "operator": ">",
  "value": 10000
}
```
*Note: The frontend strips the `va:` prefix when building the AST, relying entirely on the `isVirtual: true` flag.*

---

## 4. Compilation & Execution Phase

When the Laravel backend receives the AST payload, it passes it to the `ReportMaker` engine. The engine processes the `outerFilters` (HAVING clauses).

When `ReportMaker` encounters the node where `"isVirtual" === true`, it triggers the following logic:

1. **Lookup**: It queries the `VirtualAttributeRegistry` for a VA where `name = 'total_revenue'` and `base_model = 'Order'`.
2. **Replacement**: Instead of using the literal string `'total_revenue'` in the SQL compilation, the engine injects the raw `sql_fragment` stored in the registry (`SUM(orders.amount)`).
3. **Execution**: The query is executed safely using parameterized bindings for the value (`10000`).

**Resulting SQL Fragment:**
```sql
HAVING SUM(orders.amount) > 10000
```

This completes the lifecycle, allowing entirely dynamic, user-defined aggregates to safely interact with complex reporting logic.
