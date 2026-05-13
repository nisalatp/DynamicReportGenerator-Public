# Abstract Syntax Tree (AST) Reference Guide

This document is the definitive guide to the strict JSON Abstract Syntax Tree (AST) payload required by the Dynamic Report Generator engine. 

Because the engine completely abstracts away database joins and relations, the frontend **must not** send SQL strings. Instead, it must send this strictly typed data structure. Any deviation from this format will result in a validation exception.

---

## 1. The Root Object: `ReportRequest`

Every report generation request must submit a JSON object matching the `ReportRequest` schema.

```json
{
  "baseModel": "User",
  "targetModels": ["Order", "Product"],
  "selectedAttributes": [ /* Array of Attribute objects */ ],
  "innerFilters": { /* FilterGroup or FilterLeaf */ },
  "groupBys": [ /* Array of GroupBy objects */ ],
  "aggregates": [ /* Array of Aggregate objects */ ],
  "outerFilters": { /* FilterGroup or FilterLeaf */ },
  "sorts": [ /* Array of Sort objects */ ]
}
```

### Parameters
| Field | Type | Description | Required? |
|-------|------|-------------|-----------|
| `baseModel` | `string` | The root Eloquent model class name (e.g., `"User"`, `"Product"`). | **Yes** |
| `targetModels` | `array<string>` | An array of models that are explicitly joined (e.g., `["Order", "Product"]`). Note: Virtual Attribute dependencies are auto-injected. | No |
| `selectedAttributes` | `array<Attribute>` | The exact raw columns the user wants to see in their report. | **Yes** |
| `innerFilters` | `FilterNode` \| `null` | The `WHERE` clause logic. A root `FilterGroup` or `FilterLeaf` object. | No |
| `groupBys` | `array<GroupBy>` | Defines the `GROUP BY` logic for aggregations. | No |
| `aggregates` | `array<Aggregate>` | Defines `COUNT`, `SUM`, etc., calculated over the `groupBys`. | No |
| `outerFilters` | `FilterNode` \| `null` | The `HAVING` clause logic (used for filtering after aggregations). | No |
| `sorts` | `array<Sort>` | Defines `ORDER BY` logic. | No |

---

## 2. The `Attribute` Object

The `Attribute` object is heavily used across `selectedAttributes`, `groupBys`, and `filters`.

```json
{
  "modelClass": "User",
  "column": "email",
  "type": "string",
  "isVirtual": false,
  "jsonPath": null,
  "alias": null
}
```

### Parameters
| Field | Type | Description | Required? |
|-------|------|-------------|-----------|
| `modelClass` | `string` | The exact Model class name (e.g., `"User"`). | **Yes** |
| `column` | `string` | The exact database column name or Virtual Attribute name. | **Yes** |
| `type` | `string` | The data type (e.g., `"string"`, `"integer"`, `"date"`). Used for validation. | **Yes** |
| `isVirtual` | `boolean` | Set to `true` if this is a Virtual Attribute. | No (Default: `false`) |
| `jsonPath` | `string` \| `null` | Used if extracting data from a JSON column. | No |
| `alias` | `string` \| `null` | SQL `AS` alias. | No |

---

## 3. `GroupBy` and `Aggregate` Objects

When grouping data, you specify an array of `GroupBy` objects and an array of `Aggregate` objects. You can provide **multiple** group bys and multiple aggregates simultaneously.

### `GroupBy` Example (Multiple Groupings)
```json
"groupBys": [
  { "attribute": { "modelClass": "User", "column": "country", "type": "string" } },
  { "attribute": { "modelClass": "Product", "column": "category", "type": "string" } }
]
```

### `Aggregate` Example (Multiple Aggregations)
```json
"aggregates": [
  {
    "attribute": { "modelClass": "Order", "column": "amount", "type": "integer" },
    "function": "SUM",
    "alias": "total_revenue"
  },
  {
    "attribute": { "modelClass": "Order", "column": "id", "type": "integer" },
    "function": "COUNT",
    "alias": "total_orders"
  }
]
```
*`function` must be a valid SQL aggregate (e.g., `SUM`, `COUNT`, `AVG`, `MAX`, `MIN`).*

---

## 4. The `Sort` Object

When ordering data, you specify an array of `Sort` objects. You can sort by physical columns or Virtual Attributes.

```json
"sorts": [
  {
    "attribute": { "modelClass": "Order", "column": "total_revenue", "isVirtual": true },
    "direction": "DESC"
  }
]
```

### Parameters
| Field | Type | Description | Required? |
|-------|------|-------------|-----------|
| `attribute` | `Attribute` | The attribute to sort by. | **Yes** |
| `direction` | `string` | Must be exactly `"ASC"` or `"DESC"`. | No (Default: `"ASC"`) |

---

## 5. The Recursive Filter System (`FilterNode`)

Filters are built using a Composite design pattern. The `innerFilters` and `outerFilters` accept a root `FilterNode` which can be a `FilterGroup` or a `FilterLeaf`.

### A. The `FilterLeaf` (Single Condition)

A single condition. It **must** contain a `type: "leaf"` flag.

```json
{
  "type": "leaf",
  "attribute": { /* Attribute Object */ },
  "operator": ">",
  "value": 1000
}
```

#### Allowed Operators
The backend strictly sanitizes operators. You **must** use one of the following:
`=`, `!=`, `>`, `>=`, `<`, `<=`, `like`, `in`, `between`, `is null`, `is not null`.

*Note: For `in` and `between`, the `value` must be a JSON Array (e.g., `[1, 2, 3]`). For `like`, the attribute type must be `string` or `text`.*

### B. The `FilterGroup` (Nested Conditions)

Used for `(A AND B)` logic. It recursively contains other nodes in its `children` array. It **must** contain a `type: "group"` flag.

```json
{
  "type": "group",
  "logic": "or",
  "children": [
    { /* FilterLeaf 1 */ },
    { /* FilterLeaf 2 */ }
  ]
}
```

#### `logic`
Must be either `"and"` or `"or"`.

---

## 6. What You CANNOT Include 🚫

To maintain absolute security:
1. **No Raw SQL**: You cannot pass `"sql": "WHERE x = y"`.
2. **No Unescaped Wildcards in Standard Operators**: If you want wildcards, you MUST use the `like` operator explicitly.
3. **No Foreign Keys for Joins**: Do not try to specify `users.id = orders.user_id`. The Graph-Theory Bidirectional Breadth-First Search (BFS) handles that implicitly based on the `targetModels` and selected attributes. The engine discovers relationships in both directions automatically (forward-declared and reverse-synthesized).

---

## 7. Attribute Level Security (ALS) Interception

The Dynamic Report Generator is built with enterprise security in mind. It strictly enforces Attribute Level Security (ALS) via the `GovernanceManager` service. 

**This means the AST payload is considered untrusted.**

When the backend receives the AST:
1. It queries the `GovernanceManager` for the active security matrix of the authenticated user's Role.
2. It traverses the `selectedAttributes`, `filters`, `groupBys`, and `sorts` within the AST.
3. **Blocking**: If the AST attempts to query a model or column that is flagged as `blocked` for the user, the execution will immediately throw an `UnauthorizedException`.
4. **Masking**: If the AST selects a column that is flagged as `masked` (e.g., `email`), the Engine dynamically intercepts the SQL generation and injects a database-level masking function (e.g., replacing `SELECT email` with `SELECT '***' AS email`).

The frontend UI does not need to worry about writing secure queries or managing PII—the Engine guarantees data security at the compilation layer.

---

## 8. Full Comprehensive Scenario Example

**Scenario Description**: A business analyst wants to analyze active user purchasing behavior. They want to see the total revenue, order count, and the user's pre-calculated **Lifetime Spend** (a Virtual Attribute), grouped by the user's country. They only want to see groupings that generated more than $10,000 in revenue and had more than 5 orders.

**Models Used**: `User`, `Order`.

### How the Scenario Maps to the AST:
1. **"analyze... purchasing behavior"**: We set `baseModel: "User"` and explicitly target `["Order"]` to enable joining across the graph.
2. **"total revenue and order count"**: We define two objects in the `aggregates` array using `SUM(Order.amount)` and `COUNT(Order.id)`.
3. **"user's pre-calculated Lifetime Spend"**: We add the `lifetime_spend` Virtual Attribute to the `selectedAttributes` array (flagging `"isVirtual": true`).
4. **"grouped by user's country"**: We pass an object into the `groupBys` array for `User.country`.
5. **"active users"**: We build an `innerFilters` group using `AND` logic, checking if `User.status = 'active'`.
6. **"generated more than $10,000... had more than 5 orders"**: We build an `outerFilters` group using `AND` logic. We use `"isVirtual": true` to reference the aggregated `amount` and `id` values from the `Order` model, applying the `>` operator to both to create the `HAVING` clause.
7. **"ordered by highest revenue"**: We add a `sorts` object for `Order.total_revenue` (flagged as virtual) and set the direction to `DESC`.

This payload demonstrates joins (`targetModels`), grouping, aggregation, Virtual Attribute retrieval, a recursive inner filter group, a recursive outer filter group, and dynamic sorting.

```json
{
  "baseModel": "User",
  "targetModels": ["Order"],
  "selectedAttributes": [
    { "modelClass": "User", "column": "lifetime_spend", "type": "integer", "isVirtual": true }
  ],
  "groupBys": [
    { "attribute": { "modelClass": "User", "column": "country", "type": "string" } }
  ],
  "aggregates": [
    { 
      "attribute": { "modelClass": "Order", "column": "amount", "type": "integer" },
      "function": "SUM",
      "alias": "total_revenue"
    },
    { 
      "attribute": { "modelClass": "Order", "column": "id", "type": "integer" },
      "function": "COUNT",
      "alias": "total_orders"
    }
  ],
  "innerFilters": {
    "type": "group",
    "logic": "and",
    "children": [
      {
        "type": "leaf",
        "attribute": { "modelClass": "User", "column": "status", "type": "string" },
        "operator": "=",
        "value": "active"
      }
    ]
  },
  "outerFilters": {
    "type": "group",
    "logic": "and",
    "children": [
        {
            "type": "leaf",
            "attribute": { "modelClass": "Order", "column": "amount", "type": "integer", "isVirtual": true },
            "operator": ">",
            "value": 10000
        },
        {
            "type": "leaf",
            "attribute": { "modelClass": "Order", "column": "id", "type": "integer", "isVirtual": true },
            "operator": ">",
            "value": 5
        }
    ]
  },
  "sorts": [
    {
        "attribute": { "modelClass": "Order", "column": "total_revenue", "isVirtual": true },
        "direction": "DESC"
    }
  ]
}
```
