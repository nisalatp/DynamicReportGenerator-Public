# CSV Export Example: Java (Spring Boot)

This example demonstrates how an external Java application triggers a CSV streaming export from the Dynamic Report Generator API.

## The Java Implementation

```java
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.file.Files;
import java.nio.file.Path;

public class CsvExportService {

    private final HttpClient client = HttpClient.newHttpClient();
    private final String baseUrl = "https://api.yourlaravelapp.com/api/report";
    private final String token = "Bearer YOUR_API_TOKEN";

    public void exportToCsv(String payloadJson, String filename) throws Exception {
        String body = String.format(
            "{\"payload\": %s, \"filename\": \"%s\"}", 
            payloadJson, filename
        );

        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl + "/export-csv"))
                .header("Authorization", token)
                .header("Content-Type", "application/json")
                .POST(HttpRequest.BodyPublishers.ofString(body))
                .build();

        // Stream the response directly to a file
        HttpResponse<Path> response = client.send(
            request, 
            HttpResponse.BodyHandlers.ofFile(Path.of("/tmp/" + filename))
        );

        System.out.println("CSV saved to: " + response.body());
        System.out.println("File size: " + Files.size(response.body()) + " bytes");
    }
}
```

## Usage Example

```java
CsvExportService exporter = new CsvExportService();

String payload = """
{
    "baseModel": "User",
    "targetModels": ["Order"],
    "selectedAttributes": [
        { "modelClass": "User", "column": "name", "dataType": "string" },
        { "modelClass": "User", "column": "country", "dataType": "string" }
    ],
    "groupBys": [
        { "attribute": { "modelClass": "User", "column": "country", "dataType": "string" } }
    ],
    "aggregates": [
        {
            "attribute": { "modelClass": "Order", "column": "amount", "dataType": "integer" },
            "function": "SUM",
            "alias": "total_revenue"
        }
    ],
    "sorts": [
        { "attribute": { "modelClass": "Order", "column": "total_revenue", "isVirtual": true }, "direction": "DESC" }
    ]
}
""";

exporter.exportToCsv(payload, "revenue_by_country.csv");
```

> [!TIP]
> The `BodyHandlers.ofFile()` streams bytes directly to disk without loading them into JVM heap memory — the Java counterpart to the PHP engine's O(1) cursor-based streaming.
