# Build Query Example: Java (Spring Boot Frontend integration / Desktop)

This example demonstrates how an external Java application can construct an extremely complex JSON Abstract Syntax Tree (AST) required by the Dynamic Report Generator API.

> [!IMPORTANT]
> Please refer to the [AST Reference Guide](../AST_REFERENCE.md) for the complete, definitive constraints and validation rules of the payload structure.

## 1. Defining the Complete DTOs
First, create Java records or classes to represent the AST nodes, fully mapping the complex recursion of `FilterGroup` and `FilterLeaf`, along with `GroupBy` and `Aggregate`.

```java
import java.util.List;

public class ReportPayload {
    public String baseModel;
    public List<String> targetModels;
    public List<Attribute> selectedAttributes;
    public Object innerFilters; // FilterGroup or FilterLeaf
    public List<GroupBy> groupBys;
    public List<Aggregate> aggregates;
    public Object outerFilters; // FilterGroup or FilterLeaf
}

public class Attribute {
    public String modelClass;
    public String column;
    public String type;
    public boolean isVirtual = false;
    
    public Attribute(String modelClass, String column, String type) {
        this.modelClass = modelClass;
        this.column = column;
        this.type = type;
    }
}

public class GroupBy {
    public Attribute attribute;
    public GroupBy(Attribute attr) { this.attribute = attr; }
}

public class Aggregate {
    public Attribute attribute;
    public String function;
    public String alias;
    public Aggregate(Attribute attr, String function, String alias) {
        this.attribute = attr;
        this.function = function;
        this.alias = alias;
    }
}

public class FilterLeaf {
    public String type = "leaf";
    public Attribute attribute;
    public String operator;
    public Object value;

    public FilterLeaf(Attribute attr, String operator, Object value) {
        this.attribute = attr;
        this.operator = operator;
        this.value = value;
    }
}

public class FilterGroup {
    public String type = "group";
    public String logic; // "and" or "or"
    public List<Object> children; // Array of FilterLeaf or FilterGroup

    public FilterGroup(String logic, List<Object> children) {
        this.logic = logic;
        this.children = children;
    }
}
```

## 2. Generating the Request

Using the standard Java 11+ `HttpClient`, you can serialize these objects into JSON and send them to the Laravel microservice.

```java
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import com.fasterxml.jackson.databind.ObjectMapper;
import java.util.Arrays;

public class ReportService {

    public void generateReport() throws Exception {
        ReportPayload payload = new ReportPayload();
        payload.baseModel = "User";
        payload.targetModels = Arrays.asList("Order", "Product");
        
        // Multiple Group Bys
        payload.groupBys = Arrays.asList(
            new GroupBy(new Attribute("User", "country", "string")),
            new GroupBy(new Attribute("Product", "category", "string"))
        );
        
        // Multiple Aggregates
        payload.aggregates = Arrays.asList(
            new Aggregate(new Attribute("Order", "amount", "integer"), "SUM", "total_revenue"),
            new Aggregate(new Attribute("Order", "id", "integer"), "COUNT", "total_orders")
        );
        
        // Complex Nested Inner Filter (WHERE)
        payload.innerFilters = new FilterGroup("and", Arrays.asList(
            new FilterLeaf(new Attribute("User", "status", "string"), "=", "active"),
            new FilterGroup("or", Arrays.asList(
                new FilterLeaf(new Attribute("Product", "category", "string"), "=", "Electronics"),
                new FilterLeaf(new Attribute("Product", "category", "string"), "=", "Software")
            ))
        ));
        
        // Complex Outer Filter (HAVING)
        Attribute virtAmount = new Attribute("Order", "amount", "integer");
        virtAmount.isVirtual = true;
        Attribute virtCount = new Attribute("Order", "id", "integer");
        virtCount.isVirtual = true;

        payload.outerFilters = new FilterGroup("and", Arrays.asList(
            new FilterLeaf(virtAmount, ">", 10000),
            new FilterLeaf(virtCount, ">", 5)
        ));

        // Convert to JSON
        ObjectMapper mapper = new ObjectMapper();
        String jsonPayload = mapper.writeValueAsString(payload);

        // Send POST request
        HttpClient client = HttpClient.newHttpClient();
        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create("https://api.yourlaravelapp.com/report/generate"))
                .header("Content-Type", "application/json")
                .header("Authorization", "Bearer YOUR_API_TOKEN")
                .POST(HttpRequest.BodyPublishers.ofString(jsonPayload))
                .build();

        HttpResponse<String> response = client.send(request, HttpResponse.BodyHandlers.ofString());
        System.out.println("Generated Report Data: " + response.body());
    }
}
```

---

## Full Comprehensive Scenario Example

**Scenario Description**: A business analyst wants to analyze active user purchasing behavior across specific product categories. They want to see the total revenue and order count, grouped by the user's country and the product's category. They only want to see groupings that generated more than $10,000 in revenue and had more than 5 orders.

**Models Used**: `User`, `Order`, `Product`.

When the Java `generateReport()` method is executed, the `ObjectMapper` parses the POJOs into this exact JSON AST representation:

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
    },
    "sorts": [
        {
            "attribute": {
                "modelClass": "Order",
                "column": "total_revenue",
                "isVirtual": true
            },
            "direction": "DESC"
        }
    ]
}
```
