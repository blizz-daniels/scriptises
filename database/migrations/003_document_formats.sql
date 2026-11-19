ALTER TABLE import_records
    MODIFY source_type ENUM('json', 'paste', 'docx', 'fountain') NOT NULL,
    MODIFY original_source MEDIUMBLOB NOT NULL;
