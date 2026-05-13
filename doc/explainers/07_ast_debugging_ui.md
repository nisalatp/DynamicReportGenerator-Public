# 07. AST Debugging UI

To fully demonstrate the core value proposition of the Dynamic Report Generator—which is that the frontend sends a strictly typed JSON Abstract Syntax Tree (AST) rather than raw SQL—we have implemented a Real-Time AST Debugging UI in the Demo Application.

## The AST Inspector Panel

Within the `builder.blade.php` AlpineJS interface, there is a **"🔍 Inspect AST"** button located in the top-right corner, next to the Saved Reports Library.

Clicking this button toggles a persistent side panel on the right side of the screen, shrinking the main workspace slightly to fit them side-by-side. This allows you to work visually in the builder *while simultaneously* watching the payload update.

### Real-Time Reactivity

Because the Demo App uses AlpineJS, the AST payload state (`payload` object) is inherently reactive. 

```html
<!-- Persistent Live AST Panel -->
<div x-show="astPanelOpen" style="width: 450px;" class="bg-dark text-success border-start border-secondary shadow-lg d-flex flex-column">
    <div class="flex-grow-1 overflow-auto p-3">
        <!-- Live JSON Stringification of the Alpine state -->
        <pre class="m-0" x-text="JSON.stringify(payload, null, 2)"></pre>
    </div>
</div>
```

As the user interacts with the visual builder—adding Target Models, selecting columns, configuring group bys, defining recursive inner filters, or adding sorting logic—the JSON displayed in the Offcanvas updates in **real-time**.

### Academic & Technical Demonstration

This feature is incredibly valuable for technical demonstrations or academic capstone defense presentations. 

It visibly proves that:
1. **Separation of Concerns**: The frontend is completely ignorant of the database structure. It only knows about models and columns. It does not know how to join them.
2. **DTO Strictness**: The payload exactly matches the `ReportRequest` schema definition expected by the engine.
3. **Recursive Flexibility**: When a user creates a nested "OR" filter condition, the inspector instantly visualizes the `FilterGroup` and `FilterLeaf` Composite Pattern construction.

Evaluators can watch the payload grow dynamically as the user interacts with the UI, and then watch that exact payload be transmitted to the `/builder/generate` endpoint, successfully proving the decoupled AST architecture.
