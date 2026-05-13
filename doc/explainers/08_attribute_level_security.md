# 08. Attribute Level Security (ALS)

The Attribute Level Security (ALS) system is the cornerstone of the Dynamic Report Generator's enterprise capabilities. It allows Data Owners to strictly govern which roles and users have access to specific data models and their individual columns.

## The Governance Manager Service

All ALS interactions are routed through the `GovernanceManager` service. This service acts as the boundary between the Data Governance UI and the database's `dynamic_attribute_restrictions` table.

### 1. Retrieving the Security Matrix
When an Admin opens the Governance tab and selects a target model (e.g., `App\Models\User`) and a subject (e.g., the `Data Analyst` Role), the `GovernanceManager::getMatrix()` method is invoked.

This method performs the following:
1. Instantiates the target Model to read its physical table columns.
2. Queries the `VirtualAttributeRegistry` to fetch any custom metrics associated with the model.
3. Queries the `dynamic_attribute_restrictions` table for existing rules applied to the chosen Role.
4. Returns a unified array mapping every attribute to its current restriction status (`unrestricted`, `masked`, or `blocked`).

### 2. Saving the Security Matrix
When the Admin changes a toggle (for instance, hiding the `password` field and masking the `email` field) and saves, the frontend submits the state to `GovernanceManager::saveMatrix()`.

**The `is_reportable` Flag**
The most powerful control in this method is the `$isReportable` boolean. If an Admin decides that a specific Role should *never* query a table (e.g., Sales Reps should never query `Payments`), setting this to false creates a wildcard (`*`) restriction of type `blocked`. The AST schema builder will immediately throw a Permission Exception if the user even attempts to select the model as a data source.

**Attribute-Level Rules**
If the model is reportable, the service iterates through the submitted attribute array:
- If a column is set to `unrestricted`, any existing restriction in the database is deleted.
- If a column is set to `masked` or `blocked`, the service uses `updateOrCreate()` to persist the rule into the `dynamic_attribute_restrictions` table.

## Execution Time Enforcement
As explained in `06_execution_and_exporting.md`, these rules are not just UI suggestions. When a user executes a report, the Engine queries the `GovernanceManager` to retrieve the active matrix for their specific Role. 

If the user attempts to retrieve an email, and the database indicates that column is `masked`, the Engine dynamically intercepts the SQL compilation and replaces `SELECT users.email` with a database-level masking string (e.g., `SELECT '***' as email`), ensuring the sensitive data never leaves the database server memory.
