# MCP (AI Agent) Example: Governance ALS Setup

Because the `DynamicReportGenerator` API is strictly typed, it is incredibly easy to expose Governance (ALS) controls to AI Agents (like Claude or ChatGPT) using the Model Context Protocol (MCP).

This Python example defines MCP tools that allow an AI to configure the security matrix.

## The MCP Server (`governance_mcp_server.py`)

```python
import mcp
import requests

# Base URL for the Laravel backend
API_BASE = "http://localhost:8000/api/admin/security"

@mcp.tool("get_security_matrix")
def get_security_matrix(model_class: str, role_id: int) -> dict:
    """
    Fetch the current Attribute Level Security (ALS) matrix for a given model and role.
    
    Args:
        model_class: The full PHP class name of the Eloquent model (e.g. 'App\Models\User').
        role_id: The ID of the user role to inspect.
    """
    response = requests.get(
        f"{API_BASE}/matrix", 
        params={"model_class": model_class, "subject_id": role_id}
    )
    
    if response.status_code == 200:
        return response.json()
    else:
        raise Exception(f"Failed to fetch matrix: {response.text}")


@mcp.tool("save_security_matrix")
def save_security_matrix(model_class: str, role_id: int, is_reportable: bool, attribute_rules: dict) -> str:
    """
    Save new Attribute Level Security (ALS) rules for a given model and role.
    
    Args:
        model_class: The full PHP class name of the Eloquent model (e.g. 'App\Models\User').
        role_id: The ID of the user role to apply the rules to.
        is_reportable: Boolean indicating if the role is allowed to query this table AT ALL.
        attribute_rules: A dictionary mapping column names to restrictions. 
                         Valid restrictions are 'unrestricted', 'masked', or 'blocked'.
                         Example: {"email": "masked", "password": "blocked"}
    """
    payload = {
        "model_class": model_class,
        "subject_id": role_id,
        "is_reportable": is_reportable,
        "attributes": attribute_rules
    }
    
    response = requests.post(f"{API_BASE}/save", json=payload)
    
    if response.status_code == 200:
        return f"Successfully updated governance rules for {model_class} on Role ID {role_id}."
    else:
        raise Exception(f"Failed to save matrix: {response.text}")

if __name__ == "__main__":
    mcp.run()
```

## Example Agent Prompt
Once connected to the MCP server, an AI Agent can perfectly execute natural language requests like:
> *"Block the Sales Representatives (Role ID 3) from seeing the Payments table completely, and mask the total_amount field on the Orders table."*

The AI will automatically execute `save_security_matrix("App\Models\Payment", 3, False, {})` and `save_security_matrix("App\Models\Order", 3, True, {"total_amount": "masked"})`.
