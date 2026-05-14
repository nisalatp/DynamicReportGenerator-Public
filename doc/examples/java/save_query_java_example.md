# Save Query Example: Java (Spring Boot / API Gateway)

This example demonstrates how an external Java application can save an AST configuration into the Laravel microservice's Saved Reports Library.

## 1. Defining the Save DTO

```java
public class SaveReportRequest {
    public String name;
    public String description;
    public Object payload; // The AST object
    
    public SaveReportRequest(String name, String description, Object payload) {
        this.name = name;
        this.description = description;
        this.payload = payload;
    }
}
```

## 2. Executing the Save Call

```java
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import com.fasterxml.jackson.databind.ObjectMapper;

public class ReportLibraryService {

    public void saveReportConfiguration(Object currentAst) throws Exception {
        
        SaveReportRequest saveReq = new SaveReportRequest(
            "High Value Segment Analysis",
            "Active users buying electronics/software with revenue > $10k",
            currentAst
        );

        ObjectMapper mapper = new ObjectMapper();
        String jsonPayload = mapper.writeValueAsString(saveReq);

        HttpClient client = HttpClient.newHttpClient();
        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create("https://api.yourlaravelapp.com/report/save"))
                .header("Content-Type", "application/json")
                .header("Authorization", "Bearer YOUR_API_TOKEN")
                .POST(HttpRequest.BodyPublishers.ofString(jsonPayload))
                .build();

        HttpResponse<String> response = client.send(request, HttpResponse.BodyHandlers.ofString());

        if (response.statusCode() == 200) {
            System.out.println("Report successfully saved to the library.");
        } else {
            System.out.println("Failed to save report: " + response.body());
        }
    }
}
```

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst wants to analyze active user purchasing behavior across specific product categories. They want to see the total revenue and order count, grouped by the user's country and the product's category. They only want to see groupings that generated more than $10,000 in revenue and had more than 5 orders. They have just built this query and want to save it as "High Value Segment Analysis".

**Models Used**: `User`, `Order`, `Product`.

When the Java `saveReportConfiguration` method is executed, the `currentAst` object is serialized into the following massive payload, saving the exact structural state of the dynamic query to be executed natively by Laravel later:

```json
{
  "name": "High Value Segment Analysis",
  "description": "Active users buying electronics/software with revenue > $10k",
  "payload": {
    "baseModel": "User",
    "targetModels": ["Order", "Product"],
    "selectedAttributes": [],
    "groupBys": [
      { "attribute": { "model": "User", "column": "country", "type": "string" } },
      { "attribute": { "model": "Product", "column": "category", "type": "string" } }
    ],
    "aggregates": [
      { 
        "attribute": { "model": "Order", "column": "amount", "type": "integer" },
        "function": "SUM",
        "alias": "total_revenue"
      },
      { 
        "attribute": { "model": "Order", "column": "id", "type": "integer" },
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
          "attribute": { "model": "User", "column": "status", "type": "string" },
          "operator": "=",
          "value": "active"
        },
        {
          "type": "group",
          "logic": "or",
          "children": [
              {
                  "type": "leaf",
                  "attribute": { "model": "Product", "column": "category", "type": "string" },
                  "operator": "=",
                  "value": "Electronics"
              },
              {
                  "type": "leaf",
                  "attribute": { "model": "Product", "column": "category", "type": "string" },
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
              "attribute": { "model": "Order", "column": "amount", "type": "integer", "isVirtual": true },
              "operator": ">",
              "value": 10000
          },
          {
              "type": "leaf",
              "attribute": { "model": "Order", "column": "id", "type": "integer", "isVirtual": true },
              "operator": ">",
              "value": 5
          }
      ]
    }
  }
}
```
