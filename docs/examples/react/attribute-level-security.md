# React Example: Governance ALS Setup

This example demonstrates how to build a React component that interacts with the backend to configure the Attribute Level Security (ALS) matrix. The component supports switching between subject types — applying rules to a **Role** (affecting all users with that role) or to a specific **User** (individual override).

## The React Component (`GovernanceConfig.jsx`)

```jsx
import React, { useState, useEffect } from 'react';
import axios from 'axios';

export default function GovernanceConfig({ roles = [], users = [] }) {
    const [selectedModel, setSelectedModel] = useState('App\\Models\\User');
    const [subjectType, setSubjectType]     = useState('Role'); // 'Role' or 'User'
    const [subjectId, setSubjectId]         = useState(roles[0]?.id ?? 1);
    const [matrix, setMatrix]               = useState(null);
    const [loading, setLoading]             = useState(false);

    useEffect(() => {
        fetchMatrix();
    }, [selectedModel, subjectType, subjectId]);

    // Reset subjectId when the type changes so we don't send a stale ID
    const handleSubjectTypeChange = (newType) => {
        setSubjectType(newType);
        setSubjectId(newType === 'Role' ? (roles[0]?.id ?? 1) : (users[0]?.id ?? 1));
    };

    const fetchMatrix = async () => {
        if (!subjectId) return;
        setLoading(true);
        try {
            const response = await axios.get('/admin/security/matrix', {
                params: {
                    model_class:  selectedModel,
                    subject_type: subjectType,
                    subject_id:   subjectId,
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
        const updated = [...matrix.attributes];
        updated[index].restriction = newRestriction;
        setMatrix({ ...matrix, attributes: updated });
    };

    const saveMatrix = async () => {
        // Flatten [{ name, restriction }] → { columnName: restriction }
        const attributesMap = {};
        matrix.attributes.forEach(attr => {
            attributesMap[attr.name] = attr.restriction;
        });

        try {
            await axios.post('/admin/security/save', {
                model_class:   selectedModel,
                subject_type:  subjectType,
                subject_id:    subjectId,
                is_reportable: matrix.is_reportable,
                attributes:    attributesMap
            });
            alert('Governance rules saved successfully!');
        } catch (error) {
            console.error("Failed to save matrix", error);
        }
    };

    const subjectOptions = subjectType === 'Role' ? roles : users;

    return (
        <div className="governance-container">
            <h2>Data Governance (ALS)</h2>

            {/* Model selector */}
            <select value={selectedModel} onChange={e => setSelectedModel(e.target.value)}>
                <option value="App\Models\User">User</option>
                <option value="App\Models\Order">Order</option>
                <option value="App\Models\Product">Product</option>
            </select>

            {/* Subject type toggle */}
            <select value={subjectType} onChange={e => handleSubjectTypeChange(e.target.value)}>
                <option value="Role">By Role (affects all role members)</option>
                <option value="User">By User (individual override)</option>
            </select>

            {/* Subject ID — populated from the chosen type */}
            <select value={subjectId} onChange={e => setSubjectId(Number(e.target.value))}>
                {subjectOptions.map(s => (
                    <option key={s.id} value={s.id}>{s.name}</option>
                ))}
            </select>

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
                                <th>Type</th>
                                <th>Access Level</th>
                            </tr>
                        </thead>
                        <tbody>
                            {matrix.attributes.map((attr, index) => (
                                <tr key={attr.name}>
                                    <td><code>{attr.name}</code></td>
                                    <td style={{ color: attr.name.startsWith('va:') ? 'blue' : 'grey', fontSize: '0.8em' }}>
                                        {attr.name.startsWith('va:') ? 'Virtual' : 'Physical'}
                                    </td>
                                    <td>
                                        <select
                                            value={attr.restriction}
                                            onChange={e => handleRestrictionChange(index, e.target.value)}
                                        >
                                            <option value="unrestricted">Unrestricted</option>
                                            <option value="masked">Masked (***)</option>
                                            <option value="blocked">Blocked (###)</option>
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

> [!NOTE]
> **Subject resolution at query time**: Setting rules on a `Role` affects every user that belongs to it. Setting rules on a `User` provides a per-user override. At execution time the engine calls `auth()->user()->getDynamicReportSubjects()` and applies the union of all matching restrictions — the strictest rule wins.
