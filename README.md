# Scriptises

Private, lightweight screenplay writing and organization software built with PHP 8.2+, MySQL 8+, HTML, CSS, and vanilla JavaScript.

## Milestone 1 included

- Private email/password setup and sign-in.
- Projects with archive and safeguarded deletion.
- Optional episodes and acts; create, rename, reorder, and delete operations.
- Scene creation, duplication, movement, focused editing, and full-screenplay reading.
- Structured action, character, dialogue, parenthetical, transition, and note blocks.
- Character records and dialogue blocks linked by character IDs.
- Debounced autosave, manual-save feedback, optimistic version checks, browser-session undo/redo, navigation warning, and a database revision trail.

## Milestone 2 included

- Open a project and use **Search** to filter scenes or blocks by text, location, episode, act, character relationship, block type, or plot thread. Edit a matching heading or block in place and open the original scene for context.
- Open **Characters** to review dialogue in screenplay order, edit a profile, or find explicit associations and mentions in action. Set manual associations in the scene editor.
- Use **Plot threads** to link scenes, characters, acts, and episodes.
- Use **JSON backup** for a complete project or export matching and checked search results. See the [JSON format](docs/project-json.md).
- Use **Import screenplay** on the dashboard to review pasted text or a JSON file. Review the original and proposed structure, then create a project or add to an explicit destination. One pending review is kept per browser session.
- Use **Writing history** on a block to restore its earlier text. The history view shows the latest 40 text revisions for that block.

To upgrade an existing installation, start MySQL and run the migration command below. It applies `002_management.sql` to the current database.

DOCX and Fountain import and PDF export are planned for Milestone 3.

## Local setup (Laragon / Windows)

1. Start Apache and MySQL in Laragon. Point the virtual host document root at `C:\laragon\www\scriptises\public`, or use the PHP server below.
2. Create the database if it does not exist:

   ```powershell
   C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe -u root -e "CREATE DATABASE scriptises_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   ```

3. Configure database connection values with `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` environment variables as needed. Local defaults live in `config/config.php`; do not store production credentials there.
4. Apply schema migrations:

   ```powershell
   C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe database\migrate.php
   ```

5. For a standalone local preview:

   ```powershell
   C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe -S localhost:8080 -t public
   ```

   Open `http://localhost:8080/setup` and create the first private account.

## Checks

Run PHP syntax validation:

```powershell
Get-ChildItem -Recurse -Filter '*.php' | ForEach-Object { C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe -l $_.FullName }
```

Run the database-backed workflows:

```powershell
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe tests\integration.php
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe tests\management.php
```

The tests create isolated temporary users and projects and remove them at completion.
