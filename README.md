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

JSON import/export, search, focused character views, plot threads, DOCX/Fountain import, and PDF export are intentionally deferred to later approved milestones.

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

Run the database-backed Milestone 1 workflow test:

```powershell
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe tests\integration.php
```

The test creates an isolated temporary user/project and removes it at completion.
