(() => {
  const form = document.querySelector('[data-import-review]');
  if (!form) return;
  const editor = form.querySelector('[data-review-editor]');
  const json = form.querySelector('[data-review-json]');
  const apply = form.querySelector('[data-apply-json]');
  const alert = form.querySelector('[data-review-error]');
  const flags = JSON.parse(form.querySelector('[data-review-flags]').textContent);
  const types = ['action', 'character', 'dialogue', 'parenthetical', 'transition', 'note'];
  let draft;
  let selected = '';
  let dirty = false;

  const id = () => `draft-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
  const element = (tag, text = '') => {
    const node = document.createElement(tag);
    node.textContent = text;
    return node;
  };
  const label = (text, input) => {
    const wrapper = element('label', text);
    wrapper.append(input);
    return wrapper;
  };
  const saveDraft = () => {
    json.value = JSON.stringify(draft, null, 2);
    dirty = true;
  };
  const textInput = (title, value, onChange, rows = 0) => {
    const input = element(rows ? 'textarea' : 'input');
    if (rows) {
      input.rows = rows;
      input.className = 'writing-text';
    }
    input.value = value ?? '';
    input.addEventListener('input', () => { onChange(input.value); saveDraft(); });
    return label(title, input);
  };
  const picker = (title, options, value, onChange) => {
    const select = element('select');
    options.forEach(([id, name]) => select.add(new Option(name, id)));
    select.value = value ?? '';
    select.addEventListener('change', () => onChange(select.value));
    return label(title, select);
  };
  const button = (text, action) => {
    const node = element('button', text);
    node.type = 'button';
    node.className = 'small';
    node.addEventListener('click', action);
    return node;
  };
  const positions = () => {
    draft.scenes.forEach((scene, index) => {
      scene.position = index + 1;
      scene.blocks.forEach((block, number) => { block.position = number + 1; });
    });
  };
  const changeStructure = (work) => {
    work();
    positions();
    saveDraft();
    render();
  };
  const options = (rows, field) => rows.map((row) => [row.id, row[field]]);

  function divisionControls() {
    const divisions = element('details');
    divisions.className = 'link-options';
    divisions.append(element('summary', 'Characters, episodes, and acts'));
    for (const [kind, name, property] of [
      ['characters', 'character', 'name'], ['episodes', 'episode', 'title'], ['acts', 'act', 'title']
    ]) {
      const group = element('div');
      group.className = 'form-stack compact';
      draft[kind].forEach((row) => {
        group.append(textInput(name[0].toUpperCase() + name.slice(1), row[property], (value) => { row[property] = value; }));
        if (kind === 'acts') group.append(picker('Act episode', [['', 'None'], ...options(draft.episodes, 'title')], row.episode_id, (value) => {
          row.episode_id = value || null;
          draft.scenes.filter(scene => scene.act_id === row.id).forEach(scene => { scene.episode_id = value || null; });
          saveDraft();
          render();
        }));
      });
      group.append(button('Add ' + name, () => {
        const value = window.prompt('Name this ' + name + ':');
        if (!value?.trim()) return;
        draft[kind].push({
          id: id(), [property]: value.trim(),
          ...(kind === 'characters' ? {} : { position: Math.max(0, ...draft[kind].map(row => row.position)) + 1 })
        });
        saveDraft();
        render();
        editor.querySelector('details').open = true;
      }));
      divisions.append(group);
    }
    return divisions;
  }

  function blockControls(scene, block, index, sceneIndex) {
    const article = element('article');
    article.className = 'review-block form-stack compact';
    if (flags[block.id]) {
      const warning = element('p', flags[block.id]);
      warning.className = 'review-flag';
      article.append(warning);
    }
    article.append(picker('Block ' + (index + 1) + ' type', types.map(type => [type, type]), block.type, (value) => {
      block.type = value;
      if (value !== 'dialogue') block.speaker_id = null;
      saveDraft();
      render();
    }));
    if (block.type === 'dialogue') article.append(picker('Speaker', [['', 'Unassigned'], ...options(draft.characters, 'name')], block.speaker_id, (value) => {
      block.speaker_id = value || null;
      saveDraft();
    }));
    article.append(textInput('Text', block.text, (value) => { block.text = value; }, 3));
    if (index > 0) article.append(button('Split scene here', () => changeStructure(() => {
      const next = { ...scene, id: id(), heading: '', blocks: scene.blocks.splice(index) };
      draft.scenes.splice(sceneIndex + 1, 0, next);
      selected = next.id;
    })));
    return article;
  }

  function render() {
    editor.replaceChildren();
    editor.className = 'form-stack';
    editor.append(
      textInput('Project title', draft.project.title, (value) => { draft.project.title = value; }),
      divisionControls()
    );
    const scene = draft.scenes.find(item => item.id === selected) || draft.scenes[0];
    const actions = element('div');
    actions.className = 'page-actions';
    actions.append(button('Add scene', () => changeStructure(() => {
      const item = { id: id(), heading: '', blocks: [], episode_id: scene?.episode_id ?? null, act_id: scene?.act_id ?? null };
      draft.scenes.splice(scene ? draft.scenes.indexOf(scene) + 1 : 0, 0, item);
      selected = item.id;
    })));
    if (!scene) {
      editor.append(element('p', 'This project has no scenes.'), actions);
      return;
    }
    selected = scene.id;
    const index = draft.scenes.indexOf(scene);
    editor.append(picker('Scene', draft.scenes.map((item, number) => [item.id, (number + 1) + '. ' + (item.heading || 'Untitled scene')]), selected, (value) => { selected = value; render(); }));
    if (index > 0) actions.append(button('Combine with previous', () => changeStructure(() => {
      const previous = draft.scenes[index - 1];
      if (scene.heading) previous.blocks.push({ id: id(), type: 'action', text: scene.heading });
      previous.blocks.push(...scene.blocks);
      for (const plot of draft.plot_threads) {
        plot.scenes = [...new Set(plot.scenes.map(ref => ref === scene.id ? previous.id : ref))];
      }
      const present = new Set(draft.scene_character_links.filter(link => link.scene_id === previous.id).map(link => link.character_id));
      draft.scene_character_links = draft.scene_character_links.filter(link => link.scene_id !== scene.id || !present.has(link.character_id));
      draft.scene_character_links.filter(link => link.scene_id === scene.id).forEach(link => { link.scene_id = previous.id; });
      draft.scenes.splice(index, 1);
      selected = previous.id;
    })));
    editor.append(actions);
    if (flags[scene.id]) {
      const warning = element('p', flags[scene.id]);
      warning.className = 'review-flag';
      editor.append(warning);
    }
    editor.append(
      textInput('Scene heading', scene.heading, (value) => { scene.heading = value; }),
      picker('Episode', [['', 'None'], ...options(draft.episodes, 'title')], scene.episode_id, (value) => {
        scene.episode_id = value || null;
        const act = draft.acts.find(item => item.id === scene.act_id);
        if (act?.episode_id && act.episode_id !== scene.episode_id) scene.act_id = null;
        saveDraft();
        render();
      }),
      picker('Act', [['', 'None'], ...options(draft.acts, 'title')], scene.act_id, (value) => {
        scene.act_id = value || null;
        const act = draft.acts.find(item => item.id === value);
        if (act?.episode_id) scene.episode_id = act.episode_id;
        saveDraft();
        render();
      })
    );
    scene.blocks.forEach((block, blockIndex) => editor.append(blockControls(scene, block, blockIndex, index)));
    editor.append(button('Add block', () => changeStructure(() => {
      scene.blocks.push({ id: id(), type: 'action', text: '' });
    })));
  }

  function loadJson() {
    try {
      const next = JSON.parse(json.value);
      if (!next || typeof next !== 'object' || !next.project || !Array.isArray(next.scenes)) throw new Error();
      for (const key of ['episodes', 'acts', 'characters', 'plot_threads', 'scene_character_links']) {
        if (!Array.isArray(next[key])) next[key] = [];
      }
      draft = next;
      render();
      alert.hidden = true;
      editor.hidden = false;
    } catch {
      editor.hidden = true;
      alert.textContent = 'This proposal could not be shown in the editor. Check the JSON and apply it again.';
      alert.hidden = false;
    }
  }
  apply.hidden = false;
  apply.addEventListener('click', loadJson);
  json.addEventListener('input', () => { dirty = true; editor.hidden = true; });
  loadJson();
  for (const flow of [form, document.querySelector('form[action="/import/cancel"]')]) {
    flow?.addEventListener('submit', (event) => { if (!event.defaultPrevented) dirty = false; });
  }
  window.addEventListener('beforeunload', (event) => {
    if (dirty) { event.preventDefault(); event.returnValue = ''; }
  });
})();
