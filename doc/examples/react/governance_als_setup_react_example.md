# React Example: Governance ALS Setup

This example demonstrates how to build a React component that interacts with the backend Laravel API to configure the Attribute Level Security (ALS) matrix.

## The React Component (`GovernanceConfig.jsx`)

```jsx
import React, { useState, useEffect } from 'react';
import axios from 'axios';

export default function GovernanceConfig() {
    const [selectedModel, setSelectedModel] = useState('App\\Models\\User');
    const [selectedRole, setSelectedRole] = useState(2); // ID of Data Analyst Role
    const [matrix, setMatrix] = useState(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        fetchMatrix();
    }, [selectedModel, selectedRole]);

    const fetchMatrix = async () => {
        setLoading(true);
        try {
            const response = await axios.get('/api/admin/security/matrix', {
                params: {
                    model_class: selectedModel,
                    subject_id: selectedRole
                }
            });
            setMatrix(response.data);
        } catch (error) {
            console.error("Failed to fetch matrix", error);
        } finally {
            setLoading(false);
        }
    };

    const handleRestrictionChange = (index, newRestriction) => {
        const newAttributes = [...matrix.attributes];
        newAttributes[index].restriction = newRestriction;
        setMatrix({ ...matrix, attributes: newAttributes });
    };

    const saveMatrix = async () => {
        // Flatten the attributes array into a key-value mapping object
        // e.g., { email: 'masked', password: 'blocked' }
        const attributesMap = {};
        matrix.attributes.forEach(attr => {
            attributesMap[attr.name] = attr.restriction;
        });

        try {
            await axios.post('/api/admin/security/save', {
                model_class: selectedModel,
                subject_id: selectedRole,
                is_reportable: matrix.is_reportable,
                attributes: attributesMap
            });
            alert('Governance rules saved successfully!');
        } catch (error) {
            console.error("Failed to save matrix", error);
        }
    };

    return (
        <div className="governance-container">
            <h2>Data Governance (ALS)</h2>
            
            <div className="selectors">
                <select value={selectedModel} onChange={e => setSelectedModel(e.target.value)}>
                    <option value="App\Models\User">User</option>
                    <option value="App\Models\Order">Order</option>
                </select>

                <select value={selectedRole} onChange={e => setSelectedRole(Number(e.target.value))}>
                    <option value={1}>Admin</option>
                    <option value={2}>Data Analyst</option>
                </select>
            </div>

            {loading && <p>Loading rules...</p>}

            {matrix && !loading && (
                <div className="matrix-editor">
                    <label>
                        <input 
                            type="checkbox" 
                            checked={matrix.is_reportable} 
                            onChange={e => setMatrix({ ...matrix, is_reportable: e.target.checked })} 
                        />
                        Allow Reporting on this Model
                    </label>

                    <table>
                        <thead>
                            <tr>
                                <th>Attribute</th>
                                <th>Access Level</th>
                            </tr>
                        </thead>
                        <tbody>
                            {matrix.attributes.map((attr, index) => (
                                <tr key={attr.name}>
                                    <td>{attr.name}</td>
                                    <td>
                                        <select 
                                            value={attr.restriction} 
                                            onChange={e => handleRestrictionChange(index, e.target.value)}
                                        >
                                            <option value="unrestricted">Unrestricted</option>
                                            <option value="masked">Masked (***)</option>
                                            <option value="blocked">Blocked (Hidden)</option>
                                        </select>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    
                    <button onClick={saveMatrix} className="btn-primary">
                        Save Security Matrix
                    </button>
                </div>
            )}
        </div>
    );
}
```
