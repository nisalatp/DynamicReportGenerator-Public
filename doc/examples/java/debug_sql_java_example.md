# Debug SQL & Join Plan Example: Java (Spring Boot)

This example demonstrates how an external Java application can inspect the raw compiled SQL and BFS join plan.

## The Java Implementation

```java
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;

public class SqlDebugService {

    private final HttpClient client = HttpClient.newHttpClient();
    private final String baseUrl = "https://api.yourlaravelapp.com/api/report/debug";
    private final String token = "Bearer YOUR_API_TOKEN";

    public String getRawSql(String payloadJson) throws Exception {
        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl + "/sql"))
                .header("Authorization", token)
                .header("Content-Type", "application/json")
                .POST(HttpRequest.BodyPublishers.ofString(payloadJson))
                .build();

        HttpResponse<String> response = client.send(request, HttpResponse.BodyHandlers.ofString());
        return response.body(); // {"sql": "SELECT ..."}
    }

    public String getJoinPlan(String payloadJson) throws Exception {
        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl + "/join-plan"))
                .header("Authorization", token)
                .header("Content-Type", "application/json")
                .POST(HttpRequest.BodyPublishers.ofString(payloadJson))
                .build();

        HttpResponse<String> response = client.send(request, HttpResponse.BodyHandlers.ofString());
        return response.body(); // {"steps": [...]}
    }
}
```

## Usage Example

```java
SqlDebugService debugService = new SqlDebugService();

String payload = """
{
    "baseModel": "User",
    "targetModels": ["Order"],
    "selectedAttributes": [
        { "model": "User", "column": "name", "type": "string" }
    ],
    "aggregates": [
        { "attribute": { "model": "Order", "column": "amount", "type": "integer" }, "function": "SUM", "alias": "total_revenue" }
    ],
    "groupBys": [
        { "attribute": { "model": "User", "column": "country", "type": "string" } }
    ]
}
""";

// Get the raw SQL
String sql = debugService.getRawSql(payload);
System.out.println("SQL: " + sql);

// Get the BFS join plan (includes direction: forward/reverse)
String plan = debugService.getJoinPlan(payload);
System.out.println("Join Plan: " + plan);
```

> [!TIP]
> Each step in the join plan includes a `direction` field (`"forward"` or `"reverse"`) indicating whether the relationship was explicitly declared or reverse-synthesized by the engine.
