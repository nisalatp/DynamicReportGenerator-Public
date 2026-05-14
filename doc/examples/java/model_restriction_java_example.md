# Model Restriction Example: Java (Spring Boot)

This example demonstrates how an external Java application manages whole-model restrictions via the REST API.

## The Java Implementation

```java
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.core.type.TypeReference;
import java.util.List;

public class ModelRestrictionService {

    private final HttpClient client = HttpClient.newHttpClient();
    private final ObjectMapper mapper = new ObjectMapper();
    private final String baseUrl = "https://api.yourlaravelapp.com/api/admin/models";
    private final String token = "Bearer YOUR_API_TOKEN";

    public List<String> getRestrictedModels() throws Exception {
        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl + "/restricted"))
                .header("Authorization", token)
                .GET()
                .build();

        HttpResponse<String> response = client.send(request, HttpResponse.BodyHandlers.ofString());
        return mapper.readValue(response.body(), new TypeReference<List<String>>() {});
    }

    public void restrictModel(String model) throws Exception {
        String body = String.format("{\"model_class\": \"%s\"}", model);

        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl + "/restrict"))
                .header("Authorization", token)
                .header("Content-Type", "application/json")
                .POST(HttpRequest.BodyPublishers.ofString(body))
                .build();

        client.send(request, HttpResponse.BodyHandlers.ofString());
    }

    public void unrestrictModel(String model) throws Exception {
        String body = String.format("{\"model_class\": \"%s\"}", model);

        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl + "/unrestrict"))
                .header("Authorization", token)
                .header("Content-Type", "application/json")
                .POST(HttpRequest.BodyPublishers.ofString(body))
                .build();

        client.send(request, HttpResponse.BodyHandlers.ofString());
    }
}
```

## Usage Example

```java
ModelRestrictionService service = new ModelRestrictionService();

// Restrict the Payment model from being used in reports
service.restrictModel("App\\Models\\Payment");

// Verify
List<String> restricted = service.getRestrictedModels();
System.out.println("Restricted: " + restricted);

// Unrestrict
service.unrestrictModel("App\\Models\\Payment");
```

> [!WARNING]
> Restricting a model flushes the BFS graph cache. Saved reports that depend on the restricted model will fail execution.
