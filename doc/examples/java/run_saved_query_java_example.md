# Run Saved Query Example: Java (Spring Boot)

This example shows how to directly execute a saved report configuration from the database using a Java client.

## 1. Executing the Call

```java
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;

public class ReportExecutionService {

    public void runSavedReport(int reportId) throws Exception {
        
        HttpClient client = HttpClient.newHttpClient();
        
        // We hit the /execute endpoint. We don't need to send the AST.
        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create("https://api.yourlaravelapp.com/report/saved/" + reportId + "/execute"))
                .header("Accept", "application/json")
                .header("Authorization", "Bearer YOUR_API_TOKEN")
                .POST(HttpRequest.BodyPublishers.noBody()) // Empty POST body
                .build();

        HttpResponse<String> response = client.send(request, HttpResponse.BodyHandlers.ofString());

        if (response.statusCode() == 200) {
            System.out.println("Report Execution Results:");
            System.out.println(response.body());
        } else {
            System.out.println("Failed to execute report: " + response.body());
        }
    }
}
```

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst opens the Java Desktop App's Library and clicks on "High Value Segment Analysis" (analyzing active user purchasing behavior across specific product categories). They do NOT want to modify it. They just want to see the latest data. They click "Run Report Directly".

**Models Used**: `User`, `Order`, `Product`.

The Java client only passes the `reportId`. The Laravel backend fetches the record from the database and implicitly hydrates this massive AST payload. It then validates it, compiles it into SQL, executes it, and returns the data array directly to the Java application:

```json
{
    "baseModel": "User",
    "targetModels": [
        "Order",
        "Product"
    ],
    "selectedAttributes": [],
    "groupBys": [
        {
            "attribute": {
                "modelClass": "User",
                "column": "country",
                "dataType": "string"
            }
        },
        {
            "attribute": {
                "modelClass": "Product",
                "column": "category",
                "dataType": "string"
            }
        }
    ],
    "aggregates": [
        {
            "attribute": {
                "modelClass": "Order",
                "column": "amount",
                "dataType": "integer"
            },
            "function": "SUM",
            "alias": "total_revenue"
        },
        {
            "attribute": {
                "modelClass": "Order",
                "column": "id",
                "dataType": "integer"
            },
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
                "attribute": {
                    "modelClass": "User",
                    "column": "status",
                    "dataType": "string"
                },
                "operator": "=",
                "value": "active"
            },
            {
                "type": "group",
                "logic": "or",
                "children": [
                    {
                        "type": "leaf",
                        "attribute": {
                            "modelClass": "Product",
                            "column": "category",
                            "dataType": "string"
                        },
                        "operator": "=",
                        "value": "Electronics"
                    },
                    {
                        "type": "leaf",
                        "attribute": {
                            "modelClass": "Product",
                            "column": "category",
                            "dataType": "string"
                        },
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
                "attribute": {
                    "modelClass": "Order",
                    "column": "amount",
                    "dataType": "integer",
                    "isVirtual": true
                },
                "operator": ">",
                "value": 10000
            },
            {
                "type": "leaf",
                "attribute": {
                    "modelClass": "Order",
                    "column": "id",
                    "dataType": "integer",
                    "isVirtual": true
                },
                "operator": ">",
                "value": 5
            }
        ]
    }
}
```
