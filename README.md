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

## Milestone 3 included

- Upload .docx, .fountain, or .json files from **Import screenplay**. Review headings, scene details, and speakers alongside the source text. The project keeps the original uploaded file for download.
- Open **Export PDF** in a project to preview a full screenplay, episode, act, scene selection, character dialogue, plot thread, or inclusive scene range. Search can also send its matches or checked results to the preview. Click **Download PDF** after checking the content.
- See [document compatibility and deployment](docs/documents-and-deployment.md) for format limitations and hosting instructions.

To upgrade an existing installation, install the Composer dependencies and apply `003_document_formats.sql` with the migration command below.

## Local setup (Laragon / Windows)

1. Start MySQL in Laragon. If using Apache, point the virtual host document root at `C:\laragon\www\scriptises\public` and configure front-controller routing as described in the [deployment guide](docs/documents-and-deployment.md).
2. Create the database if it does not exist:

   ```powershell
   C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe -u root -e "CREATE DATABASE scriptises_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   ```

3. Configure database connection values with `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` environment variables as needed. Local defaults live in `config/config.php`; do not store production credentials there.
4. Install Composer dependencies and apply schema migrations:

   ```powershell
   $scriptisesPhp = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
   & $scriptisesPhp -d extension=zip C:\laragon\bin\composer\composer.phar install
   & $scriptisesPhp database\migrate.php
   ```

5. For a standalone local preview:

   ```powershell
   & $scriptisesPhp -d extension=zip -S 127.0.0.1:8080 -t public
   ```

   Open `http://127.0.0.1:8080` and create the first private account if prompted. If your PHP configuration already loads ZIP, omit `-d extension=zip`.

## Checks

Run PHP syntax validation:

```powershell
Get-ChildItem -Recurse -Filter '*.php' | ForEach-Object { C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe -l $_.FullName }
```

Run the database-backed workflows:

```powershell
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe tests\integration.php
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe tests\management.php
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe -d extension=zip tests\documents.php
```

Set `PDFTOTEXT_BIN` to the path of Poppler's `pdftotext` to check the actual generated PDF text. Set `SCRIPTISES_LONG_PDF=1` to also test a 320-scene screenplay.

The tests create isolated temporary users and projects and remove them at completion.
