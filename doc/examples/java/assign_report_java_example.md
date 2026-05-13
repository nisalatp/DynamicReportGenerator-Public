# Java (Spring Boot) Example: Report Assignment

If you are running the `DynamicReportGenerator` as a standalone microservice, you can trigger report assignments remotely using a Java Spring Boot client.

## The RestTemplate Client (`ReportAssignmentClient.java`)

```java
package com.istore.reporting.client;

import org.springframework.stereotype.Service;
import org.springframework.web.client.RestTemplate;
import org.springframework.http.*;
import java.util.Map;
import java.util.HashMap;
import java.util.List;
import java.util.Arrays;

@Service
public class ReportAssignmentClient {

    private final RestTemplate restTemplate;
    private final String baseUrl = "http://reporting-engine.internal/api/admin/reports";

    public ReportAssignmentClient() {
        this.restTemplate = new RestTemplate();
    }

    /**
     * Assign a specific report to a list of users.
     * 
     * @param reportId The ID of the SavedReport
     * @param userIds  A list of User IDs to grant execution access to
     */
    public void assignReportToUsers(int reportId, List<Integer> userIds) {
        String url = String.format("%s/%d/assign", baseUrl, reportId);

        Map<String, Object> payload = new HashMap<>();
        payload.put("user_ids", userIds);

        HttpHeaders headers = new HttpHeaders();
        headers.setContentType(MediaType.APPLICATION_JSON);
        
        // Add auth token if required by the microservice
        // headers.setBearerAuth("service-to-service-token");

        HttpEntity<Map<String, Object>> request = new HttpEntity<>(payload, headers);
        
        // The endpoint uses the Laravel eloquent sync() method, so it will 
        // completely overwrite the existing assignments with this new list.
        ResponseEntity<String> response = restTemplate.postForEntity(url, request, String.class);
        
        if (!response.getStatusCode().is2xxSuccessful()) {
            throw new RuntimeException("Failed to assign report. Status: " + response.getStatusCode());
        }
    }
    
    /**
     * Example Usage
     */
    public void grantAccessToDataTeam() {
        int targetReportId = 15; // e.g., "Global Sales & Payments Overview"
        
        // Let's say user IDs 2, 5, and 9 belong to the Data Team
        List<Integer> dataTeamIds = Arrays.asList(2, 5, 9);
        
        try {
            assignReportToUsers(targetReportId, dataTeamIds);
            System.out.println("Report " + targetReportId + " successfully assigned to Data Team.");
        } catch (Exception e) {
            System.err.println("Error assigning report: " + e.getMessage());
        }
    }
}
```
