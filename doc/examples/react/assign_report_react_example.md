# React Example: Report Assignment

This example demonstrates how to build a React component that interacts with the backend Laravel API to assign a `SavedReport` to specific users.

## The React Component (`ReportAssignmentModal.jsx`)

```jsx
import React, { useState, useEffect } from 'react';
import axios from 'axios';

export default function ReportAssignmentModal({ reportId, initialAssignedUsers, onClose }) {
    const [users, setUsers] = useState([]);
    const [selectedUserIds, setSelectedUserIds] = useState(initialAssignedUsers || []);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        // Fetch the master list of all users available for assignment
        const fetchUsers = async () => {
            try {
                const response = await axios.get('/api/admin/users');
                setUsers(response.data);
            } catch (error) {
                console.error("Failed to fetch users", error);
            } finally {
                setLoading(false);
            }
        };

        fetchUsers();
    }, []);

    const toggleUser = (userId) => {
        setSelectedUserIds(prev => 
            prev.includes(userId)
                ? prev.filter(id => id !== userId) // Remove if already selected
                : [...prev, userId]                // Add if not selected
        );
    };

    const saveAssignments = async () => {
        setSaving(true);
        try {
            // Send the array of User IDs to the sync endpoint
            await axios.post(`/api/admin/reports/${reportId}/assign`, {
                user_ids: selectedUserIds
            });
            alert('Report assignments updated successfully!');
            onClose(); // Close the modal
        } catch (error) {
            console.error("Failed to assign report", error);
        } finally {
            setSaving(false);
        }
    };

    if (loading) return <div>Loading users...</div>;

    return (
        <div className="modal-overlay">
            <div className="modal-content">
                <h3>Assign Report ID: {reportId}</h3>
                <p>Select the users who are authorized to execute this report:</p>
                
                <div className="user-list">
                    {users.map(user => (
                        <label key={user.id} style={{ display: 'block', margin: '10px 0' }}>
                            <input 
                                type="checkbox" 
                                checked={selectedUserIds.includes(user.id)}
                                onChange={() => toggleUser(user.id)}
                            />
                            {user.name} ({user.email})
                        </label>
                    ))}
                </div>

                <div className="modal-actions" style={{ marginTop: '20px' }}>
                    <button onClick={onClose} disabled={saving}>Cancel</button>
                    <button onClick={saveAssignments} disabled={saving} className="btn-primary">
                        {saving ? 'Saving...' : 'Save Assignments'}
                    </button>
                </div>
            </div>
        </div>
    );
}
```
