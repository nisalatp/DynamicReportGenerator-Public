# Audit Logs Example: Java (Spring Boot)

This example demonstrates how an external Java application retrieves and filters audit logs from the Dynamic Report Generator.

## The Java Implementation

```java
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.core.type.TypeReference;
import java.util.List;
import java.util.Map;

public class AuditLogService {

    private final HttpClient client = HttpClient.newHttpClient();
    private final ObjectMapper mapper = new ObjectMapper();
    private final String baseUrl = "https://api.yourlaravelapp.com/api/admin/logs";
    private final String token = "Bearer YOUR_API_TOKEN";

    public List<Map<String, Object>> getLogs(String action, Integer reportId) throws Exception {
        StringBuilder url = new StringBuilder(baseUrl + "?");
        if (action != null) url.append("action=").append(action).append("&");
        if (reportId != null) url.append("report_id=").append(reportId);

        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(url.toString()))
                .header("Authorization", token)
                .GET()
                .build();

        HttpResponse<String> response = client.send(request, HttpResponse.BodyHandlers.ofString());
        return mapper.readValue(response.body(), new TypeReference<List<Map<String, Object>>>() {});
    }

    public List<Map<String, Object>> getErrorLogs() throws Exception {
        return getLogs("error", null);
    }

    public List<Map<String, Object>> getReportHistory(int reportId) throws Exception {
        return getLogs(null, reportId);
    }
}
```

## Usage Example

```java
AuditLogService logService = new AuditLogService();

// Get all error logs
List<Map<String, Object>> errors = logService.getErrorLogs();
for (Map<String, Object> error : errors) {
    System.out.println(
        error.get("created_at") + " | " +
        error.get("report_name") + " | " +
        error.get("details")
    );
}

// Get full history for a specific report
List<Map<String, Object>> history = logService.getReportHistory(42);
System.out.println("Report #42 lifecycle events: " + history.size());
```

> [!NOTE]
> Available action types: `created`, `updated`, `executed`, `assigned`, `unassigned`, `deleted`, `error`. Error logs include the exception stack trace.
