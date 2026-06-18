# MCP (AI Agent) Example: Governance ALS Setup

Because the `DynamicReportGenerator` API is strictly typed, it is easy to expose Governance (ALS) controls to AI Agents using the Model Context Protocol (MCP).

Rules can be applied to a **Role** (affecting all users with that role) or to a specific **User** (individual override). The `subject_type` field controls this — the backend resolves the correct Eloquent model class from it.

## The MCP Server (`governance_mcp_server.py`)

```python
import mcp
import requests

API_BASE = "http://localhost:8000/api/admin/security"

@mcp.tool("get_security_matrix")
def get_security_matrix(model_class: str, subject_type: str, subject_id: int) -> dict:
    """
    Fetch the current Attribute Level Security (ALS) matrix for a given model and subject.

    Args:
        model_class:  Full PHP class name of the Eloquent model (e.g. 'App\\Models\\User').
        subject_type: Who to fetch rules for — 'Role' (all members) or 'User' (individual).
        subject_id:   ID of the role or user.
    """
    response = requests.get(
        f"{API_BASE}/matrix",
        params={
            "model_class":  model_class,
            "subject_type": subject_type,
            "subject_id":   subject_id,
        }
    )
    response.raise_for_status()
    return response.json()
    # Returns: { is_reportable: bool, attributes: [{ name, type, restriction }, ...] }
    # type is "physical" or "virtual" (va: prefixed attributes)
    # restriction is "unrestricted", "masked", or "blocked"


@mcp.tool("save_security_matrix")
def save_security_matrix(
    model_class:    str,
    subject_type:   str,
    subject_id:     int,
    is_reportable:  bool,
    attribute_rules: dict
) -> str:
    """
    Save Attribute Level Security (ALS) rules for a given model and subject.

    Args:
        model_class:     Full PHP class name (e.g. 'App\\Models\\User').
        subject_type:    'Role' or 'User'.
        subject_id:      ID of the role or user.
        is_reportable:   False = block the entire model from reports for this subject.
        attribute_rules: Dict mapping column names to restrictions.
                         Valid values: 'unrestricted', 'masked', 'blocked'.
                         Example: {"email": "masked", "password": "blocked"}
                         Omit a column to leave it unrestricted.
    """
    payload = {
        "model_class":   model_class,
        "subject_type":  subject_type,
        "subject_id":    subject_id,
        "is_reportable": is_reportable,
        "attributes":    attribute_rules,
    }

    response = requests.post(f"{API_BASE}/save", json=payload)
    response.raise_for_status()
    return f"Governance rules updated for {model_class} ({subject_type} ID {subject_id})."


if __name__ == "__main__":
    mcp.run()
```

## Example Agent Interactions

Once connected, an AI Agent can execute natural language governance requests:

**Role-level restriction** (affects all Sales Representatives):
> *"Block the Sales Representatives (Role ID 3) from seeing the Payments table completely, and mask the total_amount field on Orders."*

The AI calls:
```python
save_security_matrix("App\\Models\\Payment", "Role", 3, False, {})
save_security_matrix("App\\Models\\Order",   "Role", 3, True,  {"total_amount": "masked"})
```

**User-level override** (individual trusted analyst):
> *"Give analyst Jane (User ID 7) unrestricted access to the User model, overriding the role-level email mask."*

The AI calls:
```python
save_security_matrix("App\\Models\\User", "User", 7, True, {"email": "unrestricted"})
```

> [!NOTE]
> **How subject resolution works at query time**: When a report is executed, the engine calls `auth()->user()->getDynamicReportSubjects()` — which returns the authenticated user **plus** all their roles. It applies the union of all matching restrictions. The strictest rule across all subjects wins, so a Role-level block cannot be bypassed by a User-level "unrestricted" on the same attribute. Design your rules accordingly.
