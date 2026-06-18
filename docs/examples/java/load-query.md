# Load Query Example: Java (Spring Boot)

> **Backend package — `nisalatp/dynamicreportgenerator` (v1.0.0, MIT):** this frontend example drives the
> published Composer package. Install and configure the Laravel backend first, register your reportable models
> in `config/dynamicreportgenerator.php`, and expose JSON endpoints via the `DynamicReport` facade
> (`generate`, `getAvailableModels`, `getModelAttributes`, `saveReport`, `loadAndGenerate`). Payload schema: [AST_REFERENCE.md](reference/ast-reference).

```bash
composer require nisalatp/dynamicreportgenerator
```


This example shows how an external Java application can fetch a saved report from the database and parse its AST back into Java objects.

## 1. Fetching the Saved Report

```java
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import com.fasterxml.jackson.databind.ObjectMapper;
import java.util.List;

public class ReportLibraryService {

    public void loadSavedReport(int reportId) throws Exception {
        
        HttpClient client = HttpClient.newHttpClient();
        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create("/api/report/saved/" + reportId))
                .header("Accept", "application/json")
                .header("Authorization", "Bearer YOUR_API_TOKEN")
                .GET()
                .build();

        HttpResponse<String> response = client.send(request, HttpResponse.BodyHandlers.ofString());

        if (response.statusCode() == 200) {
            ObjectMapper mapper = new ObjectMapper();
            
            // Assuming we have a class representing the wrapper
            SavedReport wrapper = mapper.readValue(response.body(), SavedReport.class);
            
            // We can now deserialize the raw JSON payload back into our AST objects
            // ReportPayload is the DTO we defined in the build query example
            ReportPayload ast = mapper.readValue(wrapper.payload, ReportPayload.class);
            
            System.out.println("Successfully loaded AST for: " + wrapper.name);
            System.out.println("Base Model: " + ast.baseModel);
        }
    }
}

class SavedReport {
    public int id;
    public String name;
    public String description;
    public String payload; // The JSON string of the AST
}
```

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst opens the Java Desktop App's Library and clicks on "High Value Segment Analysis" (analyzing active user purchasing behavior across specific product categories). They want to load this report back into the Java UI so they can modify the HAVING clause filters.

**Models Used**: `User`, `Order`, `Product`.

When the user requests to load the report, the `ObjectMapper` takes the `wrapper.payload` string and deserializes it directly into the `ReportPayload` POJO structure, perfectly rehydrating this massive configuration in memory:

```json
{
  "baseModel": "User",
  "targetModels": ["Order", "Product"],
  "selectedAttributes": [],
  "groupBys": [
    { "attribute": { "modelClass": "User", "column": "country", "type": "string" } },
    { "attribute": { "modelClass": "Product", "column": "category", "type": "string" } }
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
      },
      {
        "type": "group",
        "logic": "or",
        "children": [
            {
                "type": "leaf",
                "attribute": { "modelClass": "Product", "column": "category", "type": "string" },
                "operator": "=",
                "value": "Electronics"
            },
            {
                "type": "leaf",
                "attribute": { "modelClass": "Product", "column": "category", "type": "string" },
                "operator": "=",
                "value": "Software"
            }
        ]
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
  }
}
```
