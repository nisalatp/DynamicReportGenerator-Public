# Update & Delete Report Example: Java (Spring Boot)

This example demonstrates how an external Java application updates and deletes saved reports via the REST API.

## The Java Implementation

```java
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;

public class ReportLifecycleService {

    private final HttpClient client = HttpClient.newHttpClient();
    private final String baseUrl = "https://api.yourlaravelapp.com/api/report/saved";
    private final String token = "Bearer YOUR_API_TOKEN";

    // ─── Update Report ────────────────────────────────────────
    public String updateReport(int reportId, String name, String description) throws Exception {
        String body = String.format(
            "{\"name\": \"%s\", \"description\": \"%s\"}", 
            name, description
        );

        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl + "/" + reportId))
                .header("Authorization", token)
                .header("Content-Type", "application/json")
                .PUT(HttpRequest.BodyPublishers.ofString(body))
                .build();

        HttpResponse<String> response = client.send(request, HttpResponse.BodyHandlers.ofString());
        return response.body();
    }

    // ─── Delete Report ────────────────────────────────────────
    public int deleteReport(int reportId) throws Exception {
        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(baseUrl + "/" + reportId))
                .header("Authorization", token)
                .DELETE()
                .build();

        HttpResponse<String> response = client.send(request, HttpResponse.BodyHandlers.ofString());
        return response.statusCode();
    }
}
```

## Usage Example

```java
ReportLifecycleService service = new ReportLifecycleService();

// Update report name and description
String updated = service.updateReport(42, "Q2 Revenue Analysis", "Updated with 2026 data");
System.out.println("Updated: " + updated);

// Delete a report
int statusCode = service.deleteReport(42);
System.out.println("Delete status: " + statusCode); // 200
```

> [!NOTE]
> Both operations automatically log the action to `dynamic_report_logs` for audit compliance.
