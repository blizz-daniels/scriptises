# Project JSON format

Scriptises exports UTF-8 JSON with `schema_version` set to `"1.0.0"`. Download a complete project from **JSON backup** or use **Export matches** and **Export checked** in Search and Characters. A scene export includes all its blocks. A block export includes the scene and relevant reference data, along with just the selected blocks.

```json
{
  "schema_version": "1.0.0",
  "project": { "id": "example", "title": "The Study", "type": "film", "description": null, "status": "active" },
  "characters": [{ "id": "mara", "name": "MARA", "description": null, "aliases": ["M"] }],
  "episodes": [{ "id": "pilot", "title": "Pilot", "position": 1 }],
  "acts": [{ "id": "opening", "title": "Act 1", "position": 1, "episode_id": "pilot" }],
  "scenes": [{
    "id": "desk",
    "position": 1,
    "episode_id": "pilot",
    "act_id": "opening",
    "heading": "INT. STUDY - NIGHT",
    "int_ext": "INT.",
    "location": "Study",
    "time_of_day": "NIGHT",
    "summary": null,
    "blocks": [{ "id": "line-1", "position": 1, "type": "dialogue", "text": "It's late.", "speaker_id": "mara", "metadata": null }]
  }],
  "scene_character_links": [{ "scene_id": "desk", "character_id": "mara", "association_type": "present", "notes": null }],
  "plot_threads": [{
    "id": "deadline",
    "title": "The deadline",
    "description": null,
    "scenes": ["desk"],
    "characters": ["mara"],
    "acts": ["opening"],
    "episodes": ["pilot"]
  }]
}
```

- IDs are case-sensitive strings of up to 100 letters, digits, periods, underscores, or hyphens. They need to be unique per entity collection. Block IDs must be unique across all scenes. References must resolve within the document. Restore creates new internal IDs and may match characters by name when adding to an existing project.
- Positions are positive integers. Episode positions are unique in a project and block positions are unique in a scene. An omitted position uses the one-based order in its list. A scene in an act belonging to an episode must use that episode ID. Use `null` for an unassigned relationship.
- Project types are `film`, `short_film`, `limited_series`, `tv_series`, and `other`. Status is `active` or `archived`. Block types are `action`, `character`, `dialogue`, `parenthetical`, `transition`, and `note`. A dialogue speaker may be unassigned while drafting.
- Project title is required. Top-level entity lists are optional when empty. Scriptises also reads the nested episode/act/scene structure described in the initial specification.
- Backups exclude account data, server settings, history, original import files, and browser-local drafts. Keep complete backups in a private location.

JSON uploads are capped at 8 MB or the configured PHP upload limit, whichever is lower. Paste input is capped at 2 MB. Keep PHP's `post_max_size` above `upload_max_filesize` to allow room for review submissions and the form envelope.
