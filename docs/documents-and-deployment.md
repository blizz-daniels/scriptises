# Documents and deployment

## Import review

Upload a file on the dashboard's **Import screenplay** page. Review the extracted text or original source next to the proposed screenplay. You can fix headings and scene details, change block types and speakers, and split or combine scenes before confirming. The original file remains downloadable from the confirmed project's **Original import sources**.

- **DOCX** imports body text and table paragraphs in document order. Named screenplay paragraph styles help identify headings, dialogue, and related elements. Inline formatting, drawings, headers, comments, and the Word page layout stay in the original file.
- **Fountain** reads regular and forced scene headings, action, character cues, dialogue, parentheticals, transitions, and notes. It retains original scene numbers, title-page fields, and omitted passages as notes. Scene synopses become scene summaries. Simultaneous dialogue appears sequentially, inline styling markers remain text, and explicit page breaks are imported as notes. Use UTF-8 encoding. See the [Fountain syntax reference](https://fountain.io/syntax/).
- **JSON** uses the [versioned project format](project-json.md). Archives and original uploaded files remain separate from the project's JSON backup.

DOCX and JSON uploads are limited to 8 MB or PHP's upload limit if lower. Fountain input and the text extracted from DOCX are limited to 2 MB. DOCX imports also limit expanded XML parts. Import a larger script in sections if it exceeds these bounds.

## PDF exports

Use the project's **Export PDF** page or a PDF preview action in Search. Select either the entire screenplay or a subset. Character exports can also be restricted to an episode, act, or checked scenes. PDF content follows screenplay order even when scenes are checked out of order. Scene headings and speaker names provide context for selected lines.

The preview shows the selected content and its formatting. mPDF paginates the download as US Letter and adds page numbers. Writing notes are optional. Font substitution and page breaks can differ from the browser preview. Create a fresh preview if the screenplay changes before you download.

## PHP and MySQL hosting

1. Use PHP 8.2+ with PDO MySQL, ZIP, GD, mbstring, DOM, XML, and zlib. Use MySQL 8+ or a compatible MariaDB version. Install the dependencies from the lock file with `composer install --no-dev --optimize-autoloader`.
2. Create an application database and set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` in the environment used by PHP. Set `APP_DEBUG=0`. From the project directory, run `php database/migrate.php`.
3. Set the web server's document root to `public/` and route non-file requests to `index.php`. For an Apache virtual host, set `DirectoryIndex index.php` and `FallbackResource /index.php` inside its `public/` directory block. For Nginx, send missing paths to `/index.php?$query_string` and serve PHP through your PHP-FPM setup. Keep `app/`, `config/`, `storage/`, and `vendor/` outside the document root.
4. Use HTTPS. Give the PHP worker write access to `storage/` for logs and the mPDF cache, and to its configured private session and upload directories. Set `post_max_size` above `upload_max_filesize` if you raise upload limits. Set up the first private account before sharing the installation URL.
5. Back up the database before upgrades. A database backup retains account records, revisions, and original uploads. A project JSON backup carries the screenplay and its relationships.

For InfinityFree, transfer the Git-ignored `config/config.hosted.php` alongside `config/config.php`. Keep `config/.htaccess` in place to deny direct requests to the config folder. If the app lives inside the host's fixed `htdocs` directory, configure Apache rewrites so web requests go through `public/`. See [InfinityFree's document-root guidance](https://forum.infinityfree.com/t/how-to-install-a-laravel-site-on-infinityfree/118578).

Hosted PHP requests load the private config automatically. Explicit `DB_*` environment variables take precedence. PHP CLI and the built-in development server default to the local database. Set `APP_ENV=local` for a local Apache setup that should also use Laragon.
