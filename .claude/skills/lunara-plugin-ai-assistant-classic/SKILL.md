```markdown
# lunara-plugin-ai-assistant-classic Development Patterns

> Auto-generated skill from repository analysis

## Overview
This skill provides guidance for contributing to the `lunara-plugin-ai-assistant-classic` TypeScript codebase. It covers established coding conventions, file organization, and common workflows, ensuring consistency and maintainability across the project. While no specific framework is used, the repository follows clear patterns for file naming, imports/exports, and testing.

## Coding Conventions

### File Naming
- Use **camelCase** for all file names.
  - Example: `aiAssistantCore.ts`, `userSettingsManager.ts`

### Import Style
- Use **relative imports** for referencing modules within the project.
  - Example:
    ```typescript
    import { getUserSettings } from './userSettingsManager';
    ```

### Export Style
- Use **named exports** for all modules.
  - Example:
    ```typescript
    // In aiAssistantCore.ts
    export function processInput(input: string): string {
      // ...
    }
    ```

### Commit Patterns
- Commit messages are **freeform** and do not follow a strict prefixing convention.
- Average commit message length is around 48 characters.

## Workflows

### Adding a New Feature
**Trigger:** When implementing a new capability or module  
**Command:** `/add-feature`

1. Create a new file using camelCase (e.g., `newFeatureModule.ts`).
2. Implement the feature using TypeScript, following the import/export conventions.
3. Add or update tests in a corresponding `*.test.ts` file.
4. Commit your changes with a clear, concise message.
5. Open a pull request for review.

### Fixing a Bug
**Trigger:** When resolving a reported issue or bug  
**Command:** `/fix-bug`

1. Locate the relevant module(s) using relative imports.
2. Apply the necessary code changes.
3. Update or add tests as needed in `*.test.ts` files.
4. Commit with a descriptive message about the fix.
5. Open a pull request referencing the issue (if applicable).

### Writing and Running Tests
**Trigger:** When verifying code correctness  
**Command:** `/run-tests`

1. Create or update test files named with the `*.test.ts` pattern.
2. Write tests covering new or changed functionality.
3. Use the project's preferred test runner (framework not specified).
4. Run tests and ensure all pass before committing.

## Testing Patterns

- Test files follow the `*.test.ts` naming convention.
- The specific testing framework is **unknown**; check existing test files for patterns.
- Place tests alongside or near the modules they cover.
- Example test file name: `aiAssistantCore.test.ts`

## Commands
| Command      | Purpose                                   |
|--------------|-------------------------------------------|
| /add-feature | Start the workflow for adding a new feature|
| /fix-bug     | Begin the process to fix a bug            |
| /run-tests   | Run all tests in the repository           |
```
