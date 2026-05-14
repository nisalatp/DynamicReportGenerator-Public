# Java (Spring Boot) Example: Governance ALS Setup

If you are running the `DynamicReportGenerator` as a standalone microservice, you can configure the Attribute Level Security (ALS) rules remotely using a Java Spring Boot client.

## The RestTemplate Client (`GovernanceClient.java`)

```java
package com.istore.reporting.client;

import org.springframework.stereotype.Service;
import org.springframework.web.client.RestTemplate;
import org.springframework.http.*;
import java.util.Map;
import java.util.HashMap;

@Service
public class GovernanceClient {

    private final RestTemplate restTemplate;
    private final String baseUrl = "http://reporting-engine.internal/api/admin/security";

    public GovernanceClient() {
        this.restTemplate = new RestTemplate();
    }

    /**
     * DTO for receiving the matrix from the API
     */
    public static class MatrixResponse {
        public boolean is_reportable;
        public java.util.List<AttributeRule> attributes;
    }

    public static class AttributeRule {
        public String name;
        public String type; // physical or virtual
        public String restriction; // unrestricted, masked, blocked
    }

    /**
     * Fetch the security matrix for a specific model and role.
     */
    public MatrixResponse getMatrix(String model, int roleId) {
        String url = String.format("%s/matrix?model_class=%s&subject_id=%d", baseUrl, model, roleId);
        
        ResponseEntity<MatrixResponse> response = restTemplate.getForEntity(url, MatrixResponse.class);
        return response.getBody();
    }

    /**
     * Save updated security rules for a role.
     */
    public void saveMatrix(String model, int roleId, boolean isReportable, Map<String, String> attributeRules) {
        String url = baseUrl + "/save";

        Map<String, Object> payload = new HashMap<>();
        payload.put("model_class", model);
        payload.put("subject_id", roleId);
        payload.put("is_reportable", isReportable);
        payload.put("attributes", attributeRules);

        HttpHeaders headers = new HttpHeaders();
        headers.setContentType(MediaType.APPLICATION_JSON);
        
        // Add auth token if required by the microservice
        // headers.setBearerAuth("service-to-service-token");

        HttpEntity<Map<String, Object>> request = new HttpEntity<>(payload, headers);
        
        restTemplate.postForEntity(url, request, String.class);
    }
    
    /**
     * Example Usage
     */
    public void configureDataAnalystSecurity() {
        // 1. Block analysts from seeing User passwords, but mask emails
        Map<String, String> userRules = new HashMap<>();
        userRules.put("email", "masked");
        userRules.put("password", "blocked");
        userRules.put("remember_token", "blocked");
        
        saveMatrix("App\\Models\\User", 2, true, userRules);
        
        // 2. Completely block analysts from seeing the Payments table
        Map<String, String> noPaymentRules = new HashMap<>();
        saveMatrix("App\\Models\\Payment", 2, false, noPaymentRules);
    }
}
```
