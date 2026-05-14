# Register Virtual Attribute Example: Java (Spring Boot / API Gateway)

This example demonstrates how an external Java application can programmatically register a Virtual Attribute (VA) with the Laravel microservice.

## 1. Defining the Registration DTO

```java
public class RegisterVARequest {
    public String name;
    public String base_model;
    public String return_type;
    public String sql_fragment;
    
    public RegisterVARequest(String name, String base_model, String return_type, String sql_fragment) {
        this.name = name;
        this.base_model = base_model;
        this.return_type = return_type;
        this.sql_fragment = sql_fragment;
    }
}
```

## 2. Executing the Registration Call

```java
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import com.fasterxml.jackson.databind.ObjectMapper;

public class VARegistrationService {

    public void registerAttribute() throws Exception {
        
        RegisterVARequest req = new RegisterVARequest(
            "total_revenue",
            "Order",
            "integer",
            "SUM(orders.amount)"
        );

        ObjectMapper mapper = new ObjectMapper();
        String jsonPayload = mapper.writeValueAsString(req);

        HttpClient client = HttpClient.newHttpClient();
        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create("https://api.yourlaravelapp.com/va/register"))
                .header("Content-Type", "application/json")
                .header("Authorization", "Bearer YOUR_ADMIN_TOKEN")
                .POST(HttpRequest.BodyPublishers.ofString(jsonPayload))
                .build();

        HttpResponse<String> response = client.send(request, HttpResponse.BodyHandlers.ofString());

        if (response.statusCode() == 200) {
            System.out.println("Virtual Attribute registered successfully.");
        } else {
            System.out.println("Failed to register VA: " + response.body());
        }
    }
}
```

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst wants to analyze active user purchasing behavior across specific product categories. They want to see the total revenue and order count, grouped by the user's country and the product's category. They only want to see groupings that generated more than $10,000 in revenue and had more than 5 orders. 

Before they can build this AST, an administrator must use the Java API client to register `total_revenue` (the sum of order amounts) and `total_orders` (the count of orders) as Virtual Attributes so they can be referenced in the AST's `outerFilters` (HAVING clauses).

**Models Used**: `User`, `Order`, `Product`.

The admin uses the Java service above to register the two VAs. Once registered, the user can build the following AST, referencing the VAs using `"isVirtual": true`:

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
