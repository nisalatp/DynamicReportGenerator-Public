# Schema Discovery Example: Java (Spring Boot)

> **Backend package — `nisalatp/dynamicreportgenerator` (v1.0.0, MIT):** this frontend example drives the
> published Composer package. Install and configure the Laravel backend first, register your reportable models
> in `config/dynamicreportgenerator.php`, and expose JSON endpoints via the `DynamicReport` facade
> (`generate`, `getAvailableModels`, `getModelAttributes`, `saveReport`, `loadAndGenerate`). Payload schema: [AST_REFERENCE.md](reference/ast-reference).

```bash
composer require nisalatp/dynamicreportgenerator
```


This example demonstrates how an external Java application can query the Report Generator for models, attributes (including Virtual Attributes), and relationships.

## 1. Defining the Discovery Models

```java
import java.util.List;
import java.util.Map;

public class SchemaResponses {
    public static class Relationship {
        public String type;
        public String foreignKey;
        public String localKey;
        public String methodName;
    }
}
```

## 2. Executing the Discovery Calls

```java
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.core.type.TypeReference;
import java.util.List;
import java.util.Map;

public class SchemaDiscoveryService {

    private final HttpClient client = HttpClient.newHttpClient();
    private final ObjectMapper mapper = new ObjectMapper();
    private final String baseUrl = "/api/api/schema";
    private final String token = "Bearer YOUR_API_TOKEN";

    public List<String> getAvailableModels() throws Exception {
        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl + "/models"))
                .header("Authorization", token)
                .GET()
                .build();

        HttpResponse<String> response = client.send(request, HttpResponse.BodyHandlers.ofString());
        return mapper.readValue(response.body(), new TypeReference<List<String>>() {});
    }

    public List<String> getModelAttributes(String model) throws Exception {
        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl + "/models/" + model + "/attributes"))
                .header("Authorization", token)
                .GET()
                .build();

        HttpResponse<String> response = client.send(request, HttpResponse.BodyHandlers.ofString());
        return mapper.readValue(response.body(), new TypeReference<List<String>>() {});
    }

    public Map<String, SchemaResponses.Relationship> getModelRelationships(String model) throws Exception {
        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl + "/models/" + model + "/relationships"))
                .header("Authorization", token)
                .GET()
                .build();

        HttpResponse<String> response = client.send(request, HttpResponse.BodyHandlers.ofString());
        return mapper.readValue(response.body(), new TypeReference<Map<String, SchemaResponses.Relationship>>() {});
    }
}
```

---

## Output Example Context

If the Java application calls `getModelAttributes("Order")`, the response list will look like this:

**Available Attributes**
- `id`
- `user_id`
- `amount`
- `created_at`
- `updated_at`
- `va:total_revenue` *(Note the Virtual Attribute prefix)*

If the Java application calls `getModelRelationships("Order")`, the response map will look like this:

**Discoverable Relationships**
- Key: `"User"`, Value: `Relationship { type: "BelongsTo", methodName: "user" }`
- Key: `"Product"`, Value: `Relationship { type: "BelongsToMany", methodName: "products" }`
