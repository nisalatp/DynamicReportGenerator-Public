# MCP (AI Agent) Example: Report Assignment

Because the `DynamicReportGenerator` API relies on strict identifiers, it is incredibly easy to expose Report Assignment controls to AI Agents (like Claude or ChatGPT) using the Model Context Protocol (MCP).

This Python example defines an MCP tool that allows an AI to assign a report to specific users.

## The MCP Server (`assignment_mcp_server.py`)

```python
import mcp
import requests
from typing import List

# Base URL for the Laravel backend
API_BASE = "http://localhost:8000/api/admin/reports"

@mcp.tool("assign_report_to_users")
def assign_report_to_users(report_id: int, user_ids: List[int]) -> str:
    """
    Grant specific users the permission to view and execute a Saved Report.
    Note: This will overwrite any existing assignments for this report.
    
    Args:
        report_id: The ID of the SavedReport to assign.
        user_ids: A list of User IDs who should be granted access. 
                  (Pass an empty list [] to revoke access from everyone).
    """
    payload = {
        "user_ids": user_ids
    }
    
    response = requests.post(f"{API_BASE}/{report_id}/assign", json=payload)
    
    if response.status_code == 200:
        return f"Successfully synced access for Report ID {report_id}. {len(user_ids)} users now have access."
    else:
        raise Exception(f"Failed to assign report: {response.text}")


@mcp.tool("revoke_all_access")
def revoke_all_access(report_id: int) -> str:
    """
    Revoke execution access from all users for a specific Saved Report.
    
    Args:
        report_id: The ID of the SavedReport.
    """
    # Simply passing an empty array to the sync endpoint clears the pivot table
    return assign_report_to_users(report_id, [])

if __name__ == "__main__":
    mcp.run()
```

## Example Agent Prompt
Once connected to the MCP server, an AI Agent can seamlessly handle administration requests like:
> *"The 'Q3 Regional Sales' report (ID 42) is currently open to everyone. Please restrict it so only the leadership team (User IDs 1, 5, and 12) can execute it."*

The AI will automatically evaluate the request and execute `assign_report_to_users(42, [1, 5, 12])`. Because the Laravel backend uses Eloquent's `sync()` method, this single API call safely drops all unauthorized users and inserts the correct leadership team IDs into the pivot table.
