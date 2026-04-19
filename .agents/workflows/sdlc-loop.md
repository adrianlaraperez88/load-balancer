---
name: sdlc-loop
description: The standard 3-agent SDLC workflow for building verified features.
---
## Execution Flow
1. **Plan:** Read the user's prompt and apply the `system-architect` skill to generate a specification document. Save this as a temporary artifact.
2. **Code:** Pass the specification artifact to the `backend-developer` skill. Wait for the code generation to complete.
3. **Verify:** Pass both the specification and the generated code to the `qa-automation` skill.
4. **The Loop:** - If QA outputs `STATUS: REJECTED`, pass the feedback back to `backend-developer` and repeat step 2.
   - If QA outputs `STATUS: APPROVED`, finalize the files and terminate the workflow.