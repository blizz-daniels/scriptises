(() => {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const status = document.querySelector('[data-save-status]');
  const timers = new Map();
  const dirty = new Set();
  const inFlight = new Set();
  const endpointLocks = new Set();
  const history = [];
  let historyIndex = -1;
  let recoveredDraft = false;

  const valueOf = (element) => element.value;
  const draftKey = (element) => `scriptises:draft:${location.pathname}:${element.dataset.endpoint}:${element.dataset.field}`;
  const preserveDraft = (element) => {
    try { localStorage.setItem(draftKey(element), JSON.stringify({ value: valueOf(element), savedAt: Date.now() })); } catch (_) {}
  };
  const clearDraft = (element) => {
    try { localStorage.removeItem(draftKey(element)); } catch (_) {}
  };
  const setStatus = (text, kind = '') => {
    if (!status) return;
    status.textContent = text;
    status.dataset.state = kind;
  };
  const noteHistory = (element, from, to) => {
    if (from === to || element.dataset.replaying === 'true') return;
    history.splice(historyIndex + 1);
    history.push({ element, from, to });
    if (history.length > 100) history.shift();
    historyIndex = history.length - 1;
  };
  const setVersions = (endpoint, version) => {
    document.querySelectorAll(`[data-autosave][data-endpoint="${CSS.escape(endpoint)}"]`).forEach((input) => {
      input.dataset.version = String(version);
    });
  };
  const toggleSpeaker = (element) => {
    if (element.dataset.field !== 'block_type') return;
    const block = element.closest('[data-block]');
    const speaker = block?.querySelector('.speaker-field');
    if (speaker) speaker.classList.toggle('hidden', element.value !== 'dialogue');
    if (block) {
      block.dataset.blockType = element.value;
      block.className = `block block-${element.value}`;
      const label = block.querySelector('.block-tools > span');
      if (label) label.textContent = element.value[0].toUpperCase() + element.value.slice(1);
    }
  };
  const send = async (element) => {
    const endpoint = element.dataset.endpoint;
    if (!endpoint || endpointLocks.has(endpoint)) return;
    const value = valueOf(element);
    endpointLocks.add(endpoint);
    dirty.add(element);
    inFlight.add(element);
    setStatus('Saving…', 'saving');
    try {
      const response = await fetch(endpoint, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
        body: JSON.stringify({ field: element.dataset.field, value, version: Number(element.dataset.version || 1) }),
        credentials: 'same-origin'
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'Unable to save this change.');
      setVersions(endpoint, data.version);
      dirty.delete(element);
      clearDraft(element);
      element.dataset.lastValue = value;
      toggleSpeaker(element);
      setStatus(dirty.size ? 'Unsaved changes' : 'Saved', dirty.size ? 'dirty' : 'saved');
      if (valueOf(element) !== value) queueSave(element, 0);
    } catch (error) {
      dirty.add(element);
      element.classList.add('save-error');
      setStatus(error.message || 'Save failed', 'error');
    } finally {
      inFlight.delete(element);
      endpointLocks.delete(endpoint);
      document.querySelectorAll(`[data-autosave][data-endpoint="${CSS.escape(endpoint)}"]`).forEach((other) => {
        if (other !== element && dirty.has(other)) queueSave(other, 0);
      });
    }
  };
  const queueSave = (element, delay = 650) => {
    clearTimeout(timers.get(element));
    timers.set(element, setTimeout(() => send(element), delay));
  };

  document.querySelectorAll('[data-autosave]').forEach((element) => {
    try {
      const draft = JSON.parse(localStorage.getItem(draftKey(element)) || 'null');
      if (draft && typeof draft.value === 'string' && draft.value !== valueOf(element)) {
        element.value = draft.value;
        recoveredDraft = true;
        dirty.add(element);
      }
    } catch (_) {}
    element.dataset.lastValue = valueOf(element);
    element.addEventListener('focus', () => { element.dataset.focusValue = valueOf(element); });
    element.addEventListener('input', () => {
      noteHistory(element, element.dataset.lastValue ?? '', valueOf(element));
      element.dataset.lastValue = valueOf(element);
      element.classList.remove('save-error');
      dirty.add(element);
      preserveDraft(element);
      setStatus('Unsaved changes', 'dirty');
      queueSave(element);
    });
    element.addEventListener('change', () => {
      noteHistory(element, element.dataset.lastValue ?? '', valueOf(element));
      element.dataset.lastValue = valueOf(element);
      element.classList.remove('save-error');
      dirty.add(element);
      preserveDraft(element);
      setStatus('Unsaved changes', 'dirty');
      queueSave(element, 50);
    });
  });
  if (recoveredDraft) setStatus('Recovered unsaved draft', 'dirty');

  const replay = (step, property) => {
    if (!step || !document.contains(step.element)) return;
    step.element.dataset.replaying = 'true';
    step.element.value = step[property];
    step.element.dataset.lastValue = step[property];
    step.element.dispatchEvent(new Event('input', { bubbles: true }));
    delete step.element.dataset.replaying;
    queueSave(step.element, 0);
  };
  const undo = () => { if (historyIndex < 0) return; replay(history[historyIndex], 'from'); historyIndex--; };
  const redo = () => { if (historyIndex >= history.length - 1) return; historyIndex++; replay(history[historyIndex], 'to'); };
  document.querySelector('[data-editor-undo]')?.addEventListener('click', undo);
  document.querySelector('[data-editor-redo]')?.addEventListener('click', redo);
  document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
      event.preventDefault();
      if (event.shiftKey) redo(); else undo();
    }
  });
  document.querySelector('[data-save-now]')?.addEventListener('click', () => {
    dirty.forEach((element) => queueSave(element, 0));
  });
  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => { if (!window.confirm(form.dataset.confirm || 'Continue?')) event.preventDefault(); });
  });
  window.addEventListener('beforeunload', (event) => {
    if (dirty.size || inFlight.size) { event.preventDefault(); event.returnValue = ''; }
  });
})();
