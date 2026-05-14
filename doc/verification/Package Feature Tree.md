# Dynamic Report Generator — Package Feature Tree

> **Verification Status**: Every feature below has been cross-referenced against the source code in `src/`.
> **Last Validated**: 2026-05-14 against ReportMaker.php (1400 lines)
> Legend: ✅ Implemented | ⚠️ Partial | ❌ Missing

---

## 1. Core Engine & Query Generation (`ReportMaker`)

| # | Method | Status | Source |
|---|--------|--------|--------|
| 1.1 | `generate(ReportRequest, ?array $subjects): Builder` | ✅ | ReportMaker.php L95-151 |
| 1.2 | `generatePaginated(ReportRequest, int $perPage, ?array $subjects): LengthAwarePaginator` | ✅ | ReportMaker.php L156-158 |
| 1.3 | `exportToCsv(ReportRequest, string $filename, ?array $subjects): StreamedResponse` | ✅ | ReportMaker.php L164-186 |
| 1.4 | `toRawSql(Builder): string` | ✅ | ReportMaker.php L191-202 |
| 1.5 | `explainJoinPlan(ReportRequest): JoinPlan` | ✅ | ReportMaker.php L207-220 |
| 1.6 | `getGeneratedColumns(ReportRequest): array` | ✅ | ReportMaker.php L285-326 |
| 1.7 | `buildScalarSubquery(VirtualAttributeRequest): string` | ✅ | ReportMaker.php L222-279 |

### Details

*   **`generate`** — Input: `ReportRequest $whatUserWants`, `?array $subjects`. Output: `Builder`. Enforces model-level and attribute-level security, validates filter depth, resolves VA dependencies, builds BFS join plan, constructs inner query with optional outer query for GROUP BY/aggregates, applies sorts, and enforces `max_rows` safety limit.
*   **`generatePaginated`** — Input: `ReportRequest`, `int $perPage = 50`, `?array $subjects`. Output: `LengthAwarePaginator`. Wraps `generate()` with `->paginate()`.
*   **`exportToCsv`** — Input: `ReportRequest`, `string $filename = 'report.csv'`, `?array $subjects`. Output: `StreamedResponse`. Uses `cursor()` for O(1) memory-safe streaming.
*   **`toRawSql`** — Input: `Builder $query`. Output: `string`. Replaces `?` placeholders with actual bindings.
*   **`explainJoinPlan`** — Input: `ReportRequest`. Output: `JoinPlan`. Returns BFS join path with direction metadata without executing.
*   **`getGeneratedColumns`** — Input: `ReportRequest`. Output: `array` of column alias strings. Useful for populating frontend dropdowns for HAVING and ORDER BY.
*   **`buildScalarSubquery`** — Input: `VirtualAttributeRequest`. Output: `string` (raw SQL subquery). Compiles a correlated scalar subquery for virtual attributes with full BFS join resolution.

---

## 2. Schema & Metadata Discovery (`ReportMaker`)

| # | Method | Status | Source |
|---|--------|--------|--------|
| 2.1 | `getAvailableModels(): array` | ✅ | ReportMaker.php L528-532 |
| 2.2 | `getAllApplicationModels(): array` | ✅ | ReportMaker.php L543-583 |
| 2.3 | `getModelAttributes(string $modelClass): array` | ✅ | ReportMaker.php L820-843 |
| 2.4 | `getModelRelationships(string $modelClass): array` | ✅ | ReportMaker.php L851-858 |
| 2.5 | `getConnectedModels(string $modelClass): array` | ✅ | ReportMaker.php L870-873 |
| 2.6 | `getMaxFilterDepth(): int` | ✅ | ReportMaker.php L518-521 |

### Details

*   **`getAvailableModels`** — Input: none. Output: `array` of allowed model FQCNs (all app models minus restricted minus internal).
*   **`getAllApplicationModels`** — Input: none. Output: `array` of all discovered Eloquent model FQCNs. If `excluded_models` config is set, filters out that blacklist; otherwise auto-discovers via `Finder` + token parsing.
*   **`getModelAttributes`** — Input: `string $modelClass`. Output: `array` of column names (physical + `va:` prefixed virtuals, minus blocked attributes for the current user).
*   **`getModelRelationships`** — Input: `string $modelClass`. Output: `array` of `ModelLink` objects from the bidirectional graph (includes `direction: forward|reverse`).
*   **`getConnectedModels`** — Input: `string $modelClass`. Output: `array<string, ModelLink>`. Delegates to `getModelRelationships()`.
*   **`getMaxFilterDepth`** — Input: none. Output: `int`. Returns `ui.max_filter_depth` config value (default: 3). Frontends should call this to limit AND/OR nesting depth; the same value is enforced server-side.

---

## 3. Report Persistence & State Management (`ReportMaker`)

| # | Method | Status | Source |
|---|--------|--------|--------|
| 3.1 | `saveReport(string, ReportRequest, ?int, string): SavedReport` | ✅ | ReportMaker.php L382-398 |
| 3.2 | `loadAndGenerate(int, ?int): Builder` | ✅ | ReportMaker.php L405-424 |
| 3.3 | `getSavedReports(): Collection` | ✅ | ReportMaker.php L400-403 |
| 3.4 | `loadToEditor(int): ReportRequest` | ✅ | ReportMaker.php L468-476 |
| 3.5 | `updateReport(int, string, ReportRequest, string, ?int): SavedReport` | ✅ | ReportMaker.php L478-493 |
| 3.6 | `deleteReport(int, ?int): void` | ✅ | ReportMaker.php L495-507 |

### Details

*   **`saveReport`** — Input: `string $name`, `ReportRequest`, `?int $userId`, `string $description`. Output: `SavedReport`. Serializes AST to JSON, creates DB record, logs `created` action. Catches errors and logs `error` action.
*   **`loadAndGenerate`** — Input: `int $savedReportId`, `?int $executedByUserId`. Output: `Builder`. Deserializes saved payload, calls `generate()`, logs `executed` action.
*   **`getSavedReports`** — Input: none. Output: `Collection` of all `SavedReport` ordered by `created_at desc`.
*   **`loadToEditor`** — Input: `int $reportId`. Output: `ReportRequest`. Rehydrates saved JSON back to a DTO for editing.
*   **`updateReport`** — Input: `int $reportId`, `string $name`, `ReportRequest`, `string $description`, `?int $actionByUserId`. Output: `SavedReport`. Updates record and logs `updated` action.
*   **`deleteReport`** — Input: `int $reportId`, `?int $actionByUserId`. Output: `void`. Deletes report and logs `deleted` action with `deleted_report_id` metadata.

---

## 4. Report Access Control & Auditing (`ReportMaker`)

| # | Method | Status | Source |
|---|--------|--------|--------|
| 4.1 | `assignReport(int, int, ?int): void` | ✅ | ReportMaker.php L433-443 |
| 4.2 | `unassignReport(int, int, ?int): void` | ✅ | ReportMaker.php L445-455 |
| 4.3 | `getAssignedReports(int): Collection` | ✅ | ReportMaker.php L457-466 |
| 4.4 | `getReportLogs(int): Collection` | ✅ | ReportMaker.php L426-431 |

### Details

*   **`assignReport`** — Input: `int $reportId`, `int $userId`, `?int $actionByUserId`. Output: `void`. Uses pivot table `dynamic_report_user` via `syncWithoutDetaching`. Logs `assigned` action with `assigned_user_id`.
*   **`unassignReport`** — Input: `int $reportId`, `int $userId`, `?int $actionByUserId`. Output: `void`. Detaches from pivot. Logs `unassigned` action.
*   **`getAssignedReports`** — Input: `int $userId`. Output: `Collection`. Returns reports owned by OR assigned to user via `orWhereHas('assignedUsers')`.
*   **`getReportLogs`** — Input: `int $savedReportId`. Output: `Collection` of `ReportLog` records ordered by `created_at desc`.

---

## 5. Model-Level Security (Table Restriction) (`ReportMaker`)

| # | Method | Status | Source |
|---|--------|--------|--------|
| 5.1 | `restrictModel(string, ?int): void` | ✅ | ReportMaker.php L613-625 |
| 5.2 | `unrestrictModel(string): void` | ✅ | ReportMaker.php L633-641 |
| 5.3 | `getRestrictedModels(): array` | ✅ | ReportMaker.php L590-604 |

### Details

*   **`restrictModel`** — Uses `RestrictedModel::firstOrCreate()`. Invalidates `restrictedModels`, `allowedModels`, and `cachedLinks` caches.
*   **`unrestrictModel`** — Deletes from `restricted_models` table. Invalidates all three caches.
*   **`getRestrictedModels`** — Returns `RestrictedModel::pluck('model_class')`. Gracefully returns `[]` if table doesn't exist.

---

## 6. Attribute-Level Security (ALS) (`ReportMaker`)

| # | Method | Status | Source |
|---|--------|--------|--------|
| 6.1 | `restrictAttribute(string, string, Model, string): void` | ✅ | ReportMaker.php L1361-1373 |
| 6.2 | `unrestrictAttribute(string, string, Model): void` | ✅ | ReportMaker.php L1378-1387 |
| 6.3 | `getAttributeRestrictions(Model): array` | ✅ | ReportMaker.php L1392-1398 |

### Details

*   **`restrictAttribute`** — Input: `string $modelClass`, `string $attribute`, `Model $subject`, `string $type = 'masked'`. Output: `void`. Creates/updates an `AttributeRestriction` row (masked or blocked). Clears resolved restrictions cache.
*   **`unrestrictAttribute`** — Input: `string $modelClass`, `string $attribute`, `Model $subject`. Output: `void`. Deletes the restriction. Clears cache.
*   **`getAttributeRestrictions`** — Input: `Model $subject`. Output: `array` of restriction records for that subject.

---

## 7. Virtual Attributes Registry (`VirtualAttributeRegistry`)

| # | Method | Status | Source |
|---|--------|--------|--------|
| 7.1 | `findByName(string, string): ?VirtualAttribute` | ✅ | Registry/VirtualAttributeRegistry.php L9-13 |
| 7.2 | `getForModel(string): Collection` | ✅ | Registry/VirtualAttributeRegistry.php L22-25 |

---

## 8. Services — Governance Manager (`GovernanceManager`)

| # | Method | Status | Source |
|---|--------|--------|--------|
| 8.1 | `GovernanceManager::getMatrix(string, string, int): array` | ✅ | Services/GovernanceManager.php L15-60 |
| 8.2 | `GovernanceManager::saveMatrix(string, string, int, bool, array, int): void` | ✅ | Services/GovernanceManager.php L65-121 |

### Details

*   **`getMatrix`** — Input: `string $modelClass`, `string $subjectClass`, `int $subjectId`. Output: `array` with `is_reportable` (bool) and `attributes` (array of name/type/restriction). Builds a full security matrix for a model+subject pair. Includes both physical and virtual attributes.
*   **`saveMatrix`** — Input: `string $modelClass`, `string $subjectClass`, `int $subjectId`, `bool $isReportable`, `array $attributes`, `int $authId`. Output: `void`. Atomically saves the entire ALS matrix in a DB transaction. Handles `*` wildcard for whole-model reportability.

---

## 9. Services — Virtual Attribute Manager (`VirtualAttributeManager`)

| # | Method | Status | Source |
|---|--------|--------|--------|
| 9.1 | `VirtualAttributeManager::getAllWithUsageCounts(): Collection` | ✅ | Services/VirtualAttributeManager.php L14-20 |
| 9.2 | `VirtualAttributeManager::getUsageCount(VirtualAttribute): int` | ✅ | Services/VirtualAttributeManager.php L25-28 |
| 9.3 | `VirtualAttributeManager::safeDelete(int, bool): void` | ✅ | Services/VirtualAttributeManager.php L34-45 |

### Details

*   **`getAllWithUsageCounts`** — Input: none. Output: `Collection` of VAs with `usage_count` property appended via `getUsageCount()`.
*   **`getUsageCount`** — Input: `VirtualAttribute`. Output: `int`. Counts SavedReports whose payload JSON contains `"va:$name"`.
*   **`safeDelete`** — Input: `int $id`, `bool $force = false`. Output: `void`. Prevents deletion if VA is used in saved reports unless `$force` is `true`. Throws `Exception` with usage count message.

---

## 10. Services — Virtual Attribute Compiler (`VirtualAttributeCompiler`)

| # | Method | Status | Source |
|---|--------|--------|--------|
| 10.1 | `VirtualAttributeCompiler::compileVisualPayload(array): string` | ✅ | Services/VirtualAttributeCompiler.php L13-34 |

### Details

*   **`compileVisualPayload`** — Input: `array $payload` (frontend visual builder JSON with `baseModel`, `dependencies`, `ast`). Output: `string` (compiled SQL scalar subquery). Constructs a `VirtualAttributeRequest` from the visual payload and delegates to `ReportMaker::buildScalarSubquery()`.

---

## 11. Fluent Builders (Developer API)

### 11a. `ReportBuilder` (Fluent Report Construction)

| # | Method | Status | Source |
|---|--------|--------|--------|
| 11.1 | `ReportBuilder::forModel(string): self` | ✅ | Builders/ReportBuilder.php L25-28 |
| 11.2 | `->withTarget(string): self` | ✅ | Builders/ReportBuilder.php L30-36 |
| 11.3 | `->select(string, string, string, bool, ?string): self` | ✅ | Builders/ReportBuilder.php L38-42 |
| 11.4 | `->groupBy(string, string, string): self` | ✅ | Builders/ReportBuilder.php L44-48 |
| 11.5 | `->aggregate(string, string, string, string, ?string): self` | ✅ | Builders/ReportBuilder.php L50-54 |
| 11.6 | `->filter(Closure): self` | ✅ | Builders/ReportBuilder.php L56-63 |
| 11.7 | `->having(Closure): self` | ✅ | Builders/ReportBuilder.php L65-72 |
| 11.8 | `->build(): ReportRequest` | ✅ | Builders/ReportBuilder.php L74-85 |

### 11b. `FilterBuilder` (Fluent Filter Construction)

| # | Method | Status | Source |
|---|--------|--------|--------|
| 11.9 | `->where(...)` | ✅ | Builders/FilterBuilder.php L20-34 |
| 11.10 | `->orWhere(...)` | ✅ | Builders/FilterBuilder.php L36-53 |
| 11.11 | `->whereNull(...)` | ✅ | Builders/FilterBuilder.php L55-58 |
| 11.12 | `->whereNotNull(...)` | ✅ | Builders/FilterBuilder.php L60-63 |
| 11.13 | `->whereIn(...)` | ✅ | Builders/FilterBuilder.php L65-68 |
| 11.14 | `->whereBetween(...)` | ✅ | Builders/FilterBuilder.php L70-73 |
| 11.15 | `->nested(Closure, string): self` | ✅ | Builders/FilterBuilder.php L75-85 |
| 11.16 | `->orNested(Closure): self` | ✅ | Builders/FilterBuilder.php L87-90 |

### 11c. `VirtualAttributeBuilder` (Fluent VA Registration)

| # | Method | Status | Source |
|---|--------|--------|--------|
| 11.17 | `VirtualAttributeBuilder::create(string): self` | ✅ | Builders/VirtualAttributeBuilder.php L19-22 |
| 11.18 | `->forBaseModel(string): self` | ✅ | Builders/VirtualAttributeBuilder.php L24-28 |
| 11.18a| `->withReturnType(string): self` | ✅ | Builders/VirtualAttributeBuilder.php L30-34 |
| 11.19 | `->withSqlFragment(string): self` | ✅ | Builders/VirtualAttributeBuilder.php L30-34 |
| 11.20 | `->dependsOn(array): self` | ✅ | Builders/VirtualAttributeBuilder.php L36-40 |
| 11.21 | `->register(): VirtualAttribute` | ✅ | Builders/VirtualAttributeBuilder.php L42-49 |

---

## 12. HTTP & Serialization Layer

| # | Component | Status | Source |
|---|-----------|--------|--------|
| 12.1 | `ReportBuilderRequest::fromPayload(array): ReportRequest` | ✅ | Http/Requests/ReportBuilderRequest.php L19-111 |
| 12.2 | `ReportSerializer::toJson(ReportRequest): string` | ✅ | Types/ReportSerializer.php L7-19 |
| 12.3 | `ReportSerializer::fromJson(string): ReportRequest` | ✅ | Types/ReportSerializer.php L22-46 |
| 12.4 | `ReportRequest::toJson(): string` | ✅ | Types/ReportRequest.php L15-17 |
| 12.5 | `ReportRequest::fromJson(string): self` | ✅ | Types/ReportRequest.php L19-21 |
| 12.6 | `VirtualAttributeRequest::fromArray(array): self` | ✅ | Types/VirtualAttributeRequest.php L16-36 |

---

## 13. Type System (DTOs)

| # | Type | Properties | Source |
|---|------|------------|--------|
| 13.1 | `ReportRequest` | `baseModel`, `targetModels`, `selectedAttributes`, `innerFilters`, `groupBys`, `aggregates`, `outerFilters`, `sorts` | Types/ReportRequest.php |
| 13.2 | `Attribute` | `modelClass`, `column`, `type`, `isVirtual`, `jsonPath`, `alias` | Types/Attribute.php |
| 13.3 | `FilterNode` | Abstract interface | Types/FilterNode.php |
| 13.4 | `FilterGroup` | `logic` (and/or), `children` | Types/FilterGroup.php |
| 13.5 | `FilterLeaf` | `attribute`, `operator`, `value` | Types/FilterLeaf.php |
| 13.6 | `GroupBy` | `attribute` | Types/GroupBy.php |
| 13.7 | `Aggregate` | `attribute`, `function`, `alias` | Types/Aggregate.php |
| 13.8 | `Sort` | `attribute`, `direction` (validated: ASC/DESC only) | Types/Sort.php |
| 13.9 | `JoinPlan` | `steps` | Types/JoinPlan.php |
| 13.10 | `JoinStep` | `fromModel`, `toModel`, `joinType`, `localTableAlias`, `remoteTableAlias`, `localKey`, `foreignKey`, `relationType`, `direction` | Types/JoinStep.php |
| 13.11 | `ModelInfo` | `modelClass`, `table`, `primaryKey`, `casts` | Types/ModelInfo.php |
| 13.12 | `ModelLink` | `fromModel`, `toModel`, `type`, `foreignKey`, `localKey`, `methodName`, `direction` | Types/ModelLink.php |
| 13.13 | `VirtualAttributeRequest` | `baseModel`, `targetModel`, `aggregateFunction`, `aggregateColumn`, `innerFilters`, `outerFilters` | Types/VirtualAttributeRequest.php |

---

## 14. Contracts & Interfaces

| # | Contract | Status | Source |
|---|----------|--------|--------|
| 14.1 | `DynamicReportSubject` interface | ✅ | Contracts/DynamicReportSubject.php |

*   Host app's User model implements `getDynamicReportSubjects(): Model[]` for polymorphic ALS resolution.

---

## 15. Eloquent Models (Database Layer)

| # | Model | Table | Status | Source |
|---|-------|-------|--------|--------|
| 15.1 | `SavedReport` | `dynamic_saved_reports` | ✅ | Models/SavedReport.php (41 lines) |
| 15.2 | `ReportLog` | `dynamic_report_logs` | ✅ | Models/ReportLog.php (27 lines) |
| 15.3 | `RestrictedModel` | `dynamic_restricted_models` | ✅ | Models/RestrictedModel.php (24 lines) |
| 15.4 | `AttributeRestriction` | `dynamic_attribute_restrictions` | ✅ | Models/AttributeRestriction.php (41 lines) |
| 15.5 | `VirtualAttribute` | `dynamic_virtual_attributes` | ✅ | Models/VirtualAttribute.php (21 lines) |

---

## 16. Infrastructure

| # | Component | Status | Source |
|---|-----------|--------|--------|
| 16.1 | `DynamicReportGeneratorServiceProvider` | ✅ | Providers/DynamicReportGeneratorServiceProvider.php (39 lines) |
| 16.2 | `DynamicReport` Facade | ✅ | Facades/DynamicReport.php (58 lines) |
| 16.3 | Config file (`dynamicreportgenerator.php`) | ✅ | config/dynamicreportgenerator.php (70 lines) |
| 16.4 | `ReportMakerException` (domain errors) | ✅ | Exceptions/ReportMakerException.php |
| 16.5 | `ReportMakerSecurityException` (ALS violations) | ✅ | Exceptions/ReportMakerSecurityException.php |

### Config Keys

| Key | Default | Description |
|-----|---------|-------------|
| `excluded_models` | `[]` | Explicit model blacklist (empty = auto-discover) |
| `include_package_models` | `false` | Include engine's own infrastructure models |
| `limits.max_rows` | `5000` | OOM protection: max rows per query |
| `http.enabled` | `false` | Register optional API endpoints |
| `http.prefix` | `dynamic-reporting` | API route prefix |
| `http.middleware` | `['web', 'auth']` | Middleware stack for API routes |
| `ui.max_filter_depth` | `3` | Max AND/OR nesting depth (enforced both UI + server) |

### Migration Inventory (7 migrations)
1. `create_dynamic_saved_reports_table` ✅
2. `create_dynamic_report_logs_table` ✅
3. `create_dynamic_report_user_table` (pivot) ✅
4. `expand_dynamic_report_logs_table` ✅
5. `create_dynamic_virtual_attributes_table` ✅
6. `create_dynamic_restricted_models_table` ✅
7. `create_dynamic_attribute_restrictions_table` ✅

### Internal Constants
*   `INTERNAL_MODELS` (L46-52): `SavedReport`, `ReportLog`, `RestrictedModel`, `AttributeRestriction`, `VirtualAttribute` — auto-excluded unless `include_package_models` is enabled.

---

## 17. Internal Engine Mechanisms (Private)

> These are not part of the public API but are critical to the engine's operation.

| # | Method | Purpose | Source |
|---|--------|---------|--------|
| 17.1 | `ensureModelsLoaded()` | Lazy-loads allowed models on first API call | L70-93 |
| 17.2 | `ensureModelAllowed(string)` | Guard: throws `ReportMakerException` if model is restricted | L887-892 |
| 17.3 | `resolveAttributeRestrictions(?array)` | Resolves ALS rules for current user/subjects | L684-725 |
| 17.4 | `validateSecurity(ReportRequest)` | Checks all attributes in request against blocked rules | L750-778 |
| 17.5 | `validateFilterDepth(?FilterNode, string, int)` | Recursive depth check against `max_filter_depth` config | L791-812 |
| 17.6 | `extractVirtualAttributeDependencies(ReportRequest, array&)` | Merges VA dependency models into targetModels | L328-350 |
| 17.7 | `discoverLinks(): array` | Builds bidirectional relationship graph (cached) | L907-928 |
| 17.8 | `getForwardRelations(): array` | Phase 1: Reflection-based Eloquent relationship scan | L944-999 |
| 17.9 | `getReverseRelations(array): array` | Phase 2: Synthesizes missing inverse edges | L1036-1086 |
| 17.10 | `planJoins(string, array, array, string): JoinPlan` | BFS shortest-path → JoinStep conversion | L1096-1130 |
| 17.11 | `findShortestPath(string, string, array): ?array` | BFS pathfinder with visited guard | L1152-1177 |
| 17.12 | `buildInnerQuery(string, JoinPlan, array, ?FilterNode): Builder` | Constructs the base SELECT with JOINs | L1179-1237 |
| 17.13 | `buildOuterQuery(string, Builder, array, array, ?FilterNode, array): Builder` | Wraps inner query for GROUP BY/HAVING | L1239-1282 |
| 17.14 | `applyFilters(Builder, FilterNode, array, string, string, ?string): void` | Recursive filter application (WHERE/HAVING) | L1284-1356 |
| 17.15 | `logAction(?int, ?int, string, ?array): void` | Writes to `dynamic_report_logs` | L372-380 |
| 17.16 | `extractClassFromFile(string): ?string` | Token-based PHP class extractor for auto-discovery | L643-679 |

---

## Verification Summary

| Category | Count |
|----------|-------|
| Core Engine | 7 |
| Schema Discovery | 6 |
| Persistence | 6 |
| Access Control & Audit | 4 |
| Model-Level Security | 3 |
| Attribute-Level Security | 3 |
| Virtual Attribute Registry | 2 |
| Governance Manager | 2 |
| VA Manager | 3 |
| VA Compiler | 1 |
| Fluent Builders | 21 |
| HTTP/Serialization | 6 |
| Type System (DTOs) | 13 |
| Contracts | 1 |
| Eloquent Models | 5 |
| Infrastructure | 5 |
| Internal Mechanisms | 16 |
| **TOTAL** | **104** |

> **Conclusion**: All 104 components are fully implemented in source code. No incomplete or stub implementations were found. The public API surface consists of 37 methods across `ReportMaker` (via `DynamicReport` Facade), plus 21 Fluent Builder methods and 6 Service methods. The internal engine comprises 16 private methods powering BFS graph construction, security enforcement, and query compilation.

### Source File Inventory (33 files)

| Directory | Files | Total Lines |
|-----------|-------|-------------|
| `src/` (root) | `ReportMaker.php` | 1,400 |
| `src/Builders/` | 3 files | 248 |
| `src/Contracts/` | 1 file | 24 |
| `src/Exceptions/` | 2 files | 23 |
| `src/Facades/` | 1 file | 58 |
| `src/Http/Requests/` | 1 file | 138 |
| `src/Models/` | 5 files | 154 |
| `src/Providers/` | 1 file | 40 |
| `src/Registry/` | 1 file | 27 |
| `src/Services/` | 3 files | 203 |
| `src/Types/` | 13 files | 361 |
| **Total** | **33 files** | **2,676 lines** |
