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
     * Fetch the security matrix for a specific model and subject.
     *
     * @param model       FQCN of the Eloquent model (e.g. "App\\Models\\User")
     * @param subjectType "Role" or "User" — determines whose rules are fetched
     * @param subjectId   ID of the role or user
     */
    public MatrixResponse getMatrix(String model, String subjectType, int subjectId) {
        String url = String.format(
            "%s/matrix?model_class=%s&subject_type=%s&subject_id=%d",
            baseUrl, model, subjectType, subjectId
        );

        ResponseEntity<MatrixResponse> response = restTemplate.getForEntity(url, MatrixResponse.class);
        return response.getBody();
    }

    /**
     * Save updated security rules for a subject (role or individual user).
     *
     * @param subjectType "Role" or "User"
     */
    public void saveMatrix(String model, String subjectType, int subjectId, boolean isReportable, Map<String, String> attributeRules) {
        String url = baseUrl + "/save";

        Map<String, Object> payload = new HashMap<>();
        payload.put("model_class",   model);
        payload.put("subject_type",  subjectType);
        payload.put("subject_id",    subjectId);
        payload.put("is_reportable", isReportable);
        payload.put("attributes",    attributeRules);

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
        // 1. Apply Role-level rules: mask emails and block passwords for all Data Analysts (Role ID 2)
        Map<String, String> userRules = new HashMap<>();
        userRules.put("email",          "masked");
        userRules.put("password",       "blocked");
        userRules.put("remember_token", "blocked");

        saveMatrix("App\\Models\\User", "Role", 2, true, userRules);

        // 2. Completely block Data Analysts from the Payments table at the Role level
        saveMatrix("App\\Models\\Payment", "Role", 2, false, new HashMap<>());

        // 3. Apply a User-level override: give a specific trusted analyst (User ID 7) full access
        saveMatrix("App\\Models\\User", "User", 7, true, new HashMap<>());
    }
}
```
