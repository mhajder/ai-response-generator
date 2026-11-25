(function () {
  'use strict';

  const TOOLBAR_ID = 'ai-response-toolbar';
  const MODAL_ID = 'ai-response-modal-backdrop';
  const PREFS_KEY = 'aiResponseGenPrefs';

  function loadPrefs() {
    try {
      if (!window.localStorage) return {};
      const raw = window.localStorage.getItem(PREFS_KEY);
      if (!raw) return {};
      const parsed = JSON.parse(raw);
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
      return {};
    }
  }

  function savePref(key, value) {
    try {
      if (!window.localStorage) return;
      const prefs = loadPrefs();
      if (value === '' || value === null || value === undefined) {
        delete prefs[key];
      } else {
        prefs[key] = value;
      }
      window.localStorage.setItem(PREFS_KEY, JSON.stringify(prefs));
    } catch {
      // Ignore storage quota or disabled storage errors
    }
  }

  function getConfig() {
    return window.AIResponseGen || null;
  }

  function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf_token"]');
    if (meta && meta.getAttribute('content')) {
      return meta.getAttribute('content');
    }
    const input = document.querySelector('input[name="__CSRFToken__"]');
    return input ? input.value || '' : '';
  }

  function getTargetSelector(action) {
    return action === 'summarize' ? '#internal_note' : '#response';
  }

  function isBlankHtmlDraft(text) {
    const plain = String(text || '')
      .replace(/<[^>]*>/g, '')
      .replace(/&nbsp;/gi, ' ')
      .trim();
    return plain === '';
  }

  function plainTextToHtml(text) {
    const escaped = String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return escaped
      .split(/\n{2,}/)
      .map((para) => '<p>' + para.replace(/\n/g, '<br>') + '</p>')
      .join('\n');
  }

  function sanitizePreviewHtml(html) {
    const raw = String(html || '');
    if (typeof window.DOMParser === 'function') {
      const doc = new window.DOMParser().parseFromString(raw, 'text/html');
      const blockedTags = doc.querySelectorAll(
        'script, style, iframe, frame, frameset, object, embed, link, meta, base, form, ' +
          'svg, math, template, noscript, input, button, textarea, select'
      );
      blockedTags.forEach((node) => node.remove());

      const urlAttrs = [
        'href',
        'src',
        'xlink:href',
        'action',
        'formaction',
        'srcset',
        'background',
        'poster',
      ];
      const allElements = doc.body ? doc.body.querySelectorAll('*') : [];
      allElements.forEach((el) => {
        Array.from(el.attributes).forEach((attr) => {
          const name = attr.name.toLowerCase();
          // Browsers ignore ASCII whitespace/control characters inside URL schemes
          // (e.g. "java\tscript:"), so strip them before checking the scheme.
          // eslint-disable-next-line no-control-regex
          const val = attr.value.replace(/[\u0000-\u0020\u007f]+/g, '').toLowerCase();
          if (name.startsWith('on') || name === 'srcdoc') {
            el.removeAttribute(attr.name);
          } else if (
            urlAttrs.indexOf(name) !== -1 &&
            /^[a-z][a-z0-9+.-]*:/.test(val) &&
            !/^(?:https?|mailto|tel):/.test(val)
          ) {
            el.removeAttribute(attr.name);
          }
        });
      });

      return doc.body ? doc.body.innerHTML : '';
    }

    return plainTextToHtml(raw.replace(/<[^>]*>/g, ''));
  }

  function parseSseFrames(buffer) {
    const frames = [];
    const normalized = String(buffer || '').replace(/\r\n/g, '\n');
    const parts = normalized.split('\n\n');
    const remainder = parts.pop() || '';

    parts.forEach((block) => {
      let eventName = 'message';
      const dataLines = [];
      block.split('\n').forEach((line) => {
        if (line.startsWith('event:')) {
          eventName = line.slice(6).trim();
        } else if (line.startsWith('data:')) {
          dataLines.push(line.slice(5).trim());
        }
      });
      if (dataLines.length > 0) {
        frames.push({ event: eventName, data: dataLines.join('\n') });
      }
    });

    return { frames: frames, remainder: remainder };
  }

  function activateEditorTab(targetSelector) {
    const $ = window.jQuery;
    if (!$) return;
    const tabSelector =
      targetSelector === '#internal_note'
        ? 'a.post-note.action-button, a[href="#note"]'
        : 'a.post-response.action-button, a[href="#reply"]';
    const $tab = $(tabSelector).first();
    if ($tab.length && !$tab.hasClass('active')) {
      try {
        $tab.trigger('click');
      } catch {
        // Ignore click handler errors from third-party tabs
      }
    }
  }

  function getEditorContent(targetSelector) {
    const $ = window.jQuery;
    if (!$) return '';
    const $ta = $(targetSelector);
    if (!$ta.length) return '';

    try {
      if (typeof window.$R === 'function') {
        const code = window.$R(targetSelector, 'source.getCode');
        if (typeof code === 'string') return code;
      }
    } catch {
      // Fallback to Redactor v2 or textarea
    }

    try {
      if (typeof $ta.redactor === 'function' && $ta.hasClass('richtext')) {
        return $ta.redactor('source.getCode') || '';
      }
    } catch {
      // Fallback to textarea
    }

    return $ta.val() || '';
  }

  function isRichTextReady($ta, targetSelector) {
    if (!$ta.hasClass('richtext')) {
      return true;
    }
    try {
      if (typeof window.$R === 'function') {
        const instance = window.$R(targetSelector);
        if (instance) return true;
      }
    } catch {
      // ignore and fall back to Redactor v2 check
    }
    try {
      if (typeof $ta.redactor === 'function' && $ta.data('redactor')) {
        return true;
      }
    } catch {
      // ignore
    }
    return false;
  }

  function setEditorContent(targetSelector, content, mode, format) {
    const $ = window.jQuery;
    if (!$) return false;
    const $ta = $(targetSelector);
    if (!$ta.length) return false;

    activateEditorTab(targetSelector);

    const applyUpdate = function () {
      const current = getEditorContent(targetSelector);
      const hasRichText =
        $ta.hasClass('richtext') &&
        (typeof window.$R === 'function' || typeof $ta.redactor === 'function');

      let prepared = content;
      if (hasRichText && format === 'text') {
        prepared = plainTextToHtml(content);
      }

      let nextValue = prepared;
      if (mode === 'append' && !isBlankHtmlDraft(current)) {
        nextValue = hasRichText
          ? current + '\n<p><br></p>\n' + prepared
          : current + '\n\n' + prepared;
      }

      let updated = false;
      try {
        if (typeof window.$R === 'function' && $ta.hasClass('richtext')) {
          window.$R(targetSelector, 'source.setCode', nextValue);
          updated = true;
        }
      } catch {
        updated = false;
      }

      if (!updated) {
        try {
          if (typeof $ta.redactor === 'function' && $ta.hasClass('richtext')) {
            $ta.redactor('source.setCode', nextValue);
            updated = true;
          }
        } catch {
          updated = false;
        }
      }

      if (!updated) {
        $ta.val(nextValue);
      }
      $ta.trigger('change');
      return true;
    };

    let attempts = 0;
    const maxAttempts = 12;
    const waitAndApply = function () {
      if (isRichTextReady($ta, targetSelector) || attempts >= maxAttempts) {
        applyUpdate();
        return;
      }
      attempts += 1;
      window.setTimeout(waitAndApply, 25);
    };

    window.setTimeout(waitAndApply, 25);
    return true;
  }

  function callAjax(endpoint, payload) {
    const $ = window.jQuery;
    const cfg = getConfig();
    const csrf = getCsrfToken();
    const url = ((cfg && cfg.ajaxBase) || 'ajax.php/ai') + endpoint;
    const data = Object.assign({}, payload);
    if (csrf) {
      data.__CSRFToken__ = csrf;
    }

    return $.ajax({
      url: url,
      method: 'POST',
      data: data,
      dataType: 'json',
      headers: csrf ? { 'X-CSRFToken': csrf } : {},
    });
  }

  function copyTextToClipboard(text) {
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      return navigator.clipboard.writeText(text).then(
        function () {
          return true;
        },
        function () {
          return copyWithTextareaFallback(text);
        }
      );
    }
    return Promise.resolve(copyWithTextareaFallback(text));
  }

  function copyWithTextareaFallback(text) {
    if (!document.body || typeof document.createElement !== 'function') {
      return false;
    }
    const ta = document.createElement('textarea');
    ta.value = String(text || '');
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    ta.style.top = '0';
    document.body.appendChild(ta);
    try {
      ta.select();
      ta.setSelectionRange(0, ta.value.length);
      return typeof document.execCommand === 'function'
        ? Boolean(document.execCommand('copy'))
        : false;
    } catch {
      return false;
    } finally {
      document.body.removeChild(ta);
    }
  }

  function formatMetaBadge(meta) {
    if (!meta || typeof meta !== 'object') {
      return '';
    }
    const parts = [];
    if (meta.model) {
      parts.push(String(meta.model));
    }
    if (typeof meta.latency_ms === 'number' && Number.isFinite(meta.latency_ms)) {
      parts.push(Math.max(0, Math.round(meta.latency_ms)) + ' ms');
    }
    const usage = meta.usage;
    if (usage && typeof usage === 'object') {
      const hasIn = typeof usage.input_tokens === 'number' && Number.isFinite(usage.input_tokens);
      const hasOut =
        typeof usage.output_tokens === 'number' && Number.isFinite(usage.output_tokens);
      if (hasIn && hasOut) {
        parts.push(usage.input_tokens + ' in / ' + usage.output_tokens + ' out tokens');
      } else if (hasOut) {
        parts.push(usage.output_tokens + ' out tokens');
      }
    }
    return parts.join(' \u00b7 ');
  }

  function buildSourceBadges(meta) {
    if (!meta || typeof meta !== 'object') {
      return [];
    }
    const badges = [];
    if (meta.redacted_pii) {
      badges.push({ label: 'PII Redacted', isShield: true });
    }
    const sources = meta.sources;
    if (sources && typeof sources === 'object') {
      if (Array.isArray(sources.faqs)) {
        sources.faqs.forEach(function (q) {
          if (q) badges.push({ label: 'FAQ: ' + String(q), isShield: false });
        });
      }
      if (Array.isArray(sources.canned)) {
        sources.canned.forEach(function (title) {
          if (title) badges.push({ label: 'Canned: ' + String(title), isShield: false });
        });
      }
    }
    return badges;
  }

  function streamAjax(payload, onDelta, onDone, onError, isCurrent, signal) {
    const cfg = getConfig();
    const csrf = getCsrfToken();
    const url = ((cfg && cfg.ajaxBase) || 'ajax.php/ai') + '/stream';
    const bodyParams = new window.URLSearchParams();
    Object.keys(payload).forEach((key) => {
      if (payload[key] !== undefined && payload[key] !== null) {
        bodyParams.set(key, String(payload[key]));
      }
    });
    if (csrf) {
      bodyParams.set('__CSRFToken__', csrf);
    }

    const headers = { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' };
    if (csrf) {
      headers['X-CSRFToken'] = csrf;
    }

    const fetchOpts = {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers,
      body: bodyParams.toString(),
    };
    if (signal) {
      fetchOpts.signal = signal;
    }

    window
      .fetch(url, fetchOpts)
      .then((response) => {
        if (!isCurrent()) return null;
        const ctype = (response.headers.get('content-type') || '').toLowerCase();
        if (!response.ok || ctype.includes('application/json') || !response.body) {
          return response.text().then((raw) => {
            if (!isCurrent()) return;
            try {
              const parsed = JSON.parse(raw);
              if (parsed && parsed.ok) {
                onDone(parsed);
              } else {
                onError((parsed && parsed.error) || 'HTTP ' + response.status + ' error');
              }
            } catch {
              onError('HTTP ' + response.status + ' error');
            }
          });
        }

        const reader = response.body.getReader();
        const decoder = new window.TextDecoder();
        let buffer = '';
        let completed = false;

        function handleFrames(frames) {
          frames.forEach((frame) => {
            let data;
            try {
              data = JSON.parse(frame.data);
            } catch {
              data = null;
            }
            if (frame.event === 'delta' && data && typeof data.delta === 'string') {
              onDelta(data.delta);
            } else if (frame.event === 'done' && data && data.ok) {
              completed = true;
              onDone(data);
            } else if (frame.event === 'error') {
              completed = true;
              onError((data && data.error) || 'Streaming failed');
            }
          });
        }

        function pump() {
          return reader.read().then((chunk) => {
            if (!isCurrent()) {
              try {
                reader.cancel();
              } catch {
                // ignore
              }
              return;
            }
            if (chunk.done) {
              buffer += decoder.decode();
              if (buffer.trim() !== '') {
                handleFrames(parseSseFrames(buffer + '\n\n').frames);
              }
              if (!completed) {
                onError('Stream ended unexpectedly before completion.');
              }
              return;
            }
            buffer += decoder.decode(chunk.value, { stream: true });
            const parsed = parseSseFrames(buffer);
            buffer = parsed.remainder;
            handleFrames(parsed.frames);

            if (!completed) {
              return pump();
            }
          });
        }

        return pump();
      })
      .catch((err) => {
        if (!isCurrent() || (err && err.name === 'AbortError')) return;
        onError((err && err.message) || 'Streaming request failed');
      });
  }

  let activeRequestSeq = 0;
  let activeAbortController = null;
  let activeJqXhr = null;

  function abortActiveRequest() {
    activeRequestSeq += 1;
    if (activeAbortController) {
      try {
        activeAbortController.abort();
      } catch {
        // ignore
      }
      activeAbortController = null;
    }
    if (activeJqXhr && typeof activeJqXhr.abort === 'function') {
      try {
        activeJqXhr.abort();
      } catch {
        // ignore
      }
      activeJqXhr = null;
    }
  }

  function ensureModal() {
    let modal = document.getElementById(MODAL_ID);
    if (modal) return modal;

    modal = document.createElement('div');
    modal.id = MODAL_ID;
    modal.className = 'ai-modal-backdrop';
    modal.hidden = true;
    modal.innerHTML = [
      '<div class="ai-modal" role="dialog" aria-modal="true" aria-labelledby="ai-modal-title">',
      '  <div class="ai-modal-header">',
      '    <strong id="ai-modal-title">AI Assistant</strong>',
      '    <button type="button" class="ai-modal-close" aria-label="Close">&times;</button>',
      '  </div>',
      '  <div class="ai-modal-body">',
      '    <div class="ai-modal-translate" hidden>',
      '      <label class="ai-modal-translate-label" for="ai-translate-lang">Target language:</label>',
      '      <input id="ai-translate-lang" type="text" class="ai-modal-translate-input" list="ai-translate-langs" value="English" placeholder="e.g. English, Polish, German" />',
      '      <datalist id="ai-translate-langs">',
      '        <option value="English"></option>',
      '        <option value="Polish"></option>',
      '        <option value="German"></option>',
      '        <option value="Spanish"></option>',
      '        <option value="French"></option>',
      '      </datalist>',
      '      <button type="button" class="ai-btn ai-btn-primary ai-modal-translate-go">Translate</button>',
      '    </div>',
      '    <div class="ai-modal-status" hidden></div>',
      '    <div class="ai-modal-error" hidden></div>',
      '    <div class="ai-modal-preview-wrap" hidden>',
      '      <div class="ai-modal-view-tabs" hidden>',
      '        <button type="button" class="ai-tab-btn is-active" data-mode="preview">Preview</button>',
      '        <button type="button" class="ai-tab-btn" data-mode="edit">Edit Source</button>',
      '      </div>',
      '      <div class="ai-modal-rendered" hidden></div>',
      '      <textarea class="ai-modal-preview" rows="10"></textarea>',
      '      <div class="ai-modal-sources" hidden></div>',
      '      <div class="ai-modal-presets" hidden></div>',
      '      <div class="ai-modal-refine">',
      '        <input type="text" class="ai-modal-instruction" placeholder="Optional instruction to refine (e.g. shorter, translate to Polish, ask for logs)..." />',
      '        <button type="button" class="ai-btn ai-btn-secondary ai-modal-regenerate">Regenerate</button>',
      '      </div>',
      '    </div>',
      '  </div>',
      '  <div class="ai-modal-footer">',
      '    <span class="ai-modal-meta" hidden></span>',
      '    <div class="ai-modal-footer-actions">',
      '      <button type="button" class="ai-btn ai-btn-secondary ai-modal-copy" disabled>Copy</button>',
      '      <button type="button" class="ai-btn ai-btn-secondary ai-modal-append" disabled>Append</button>',
      '      <button type="button" class="ai-btn ai-btn-primary ai-modal-replace" disabled>Insert / Replace</button>',
      '    </div>',
      '  </div>',
      '</div>',
    ].join('\n');

    document.body.appendChild(modal);

    const close = function () {
      abortActiveRequest();
      modal.hidden = true;
    };

    modal.querySelector('.ai-modal-close').addEventListener('click', close);
    modal.addEventListener('click', function (e) {
      if (e.target === modal) close();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hidden) close();
    });

    return modal;
  }

  function openGenerationFlow(requestState) {
    abortActiveRequest();
    const modal = ensureModal();
    const titleEl = modal.querySelector('#ai-modal-title');
    const translateWrap = modal.querySelector('.ai-modal-translate');
    const translateInput = modal.querySelector('.ai-modal-translate-input');
    const translateGoBtn = modal.querySelector('.ai-modal-translate-go');
    const statusEl = modal.querySelector('.ai-modal-status');
    const errorEl = modal.querySelector('.ai-modal-error');
    const previewWrap = modal.querySelector('.ai-modal-preview-wrap');
    const viewTabs = modal.querySelector('.ai-modal-view-tabs');
    const tabPreviewBtn = modal.querySelector('.ai-tab-btn[data-mode="preview"]');
    const tabEditBtn = modal.querySelector('.ai-tab-btn[data-mode="edit"]');
    const renderedEl = modal.querySelector('.ai-modal-rendered');
    const previewInput = modal.querySelector('.ai-modal-preview');
    const sourcesEl = modal.querySelector('.ai-modal-sources');
    const presetsEl = modal.querySelector('.ai-modal-presets');
    const instructionInput = modal.querySelector('.ai-modal-instruction');
    const metaEl = modal.querySelector('.ai-modal-meta');
    const copyBtn = modal.querySelector('.ai-modal-copy');
    const appendBtn = modal.querySelector('.ai-modal-append');
    const replaceBtn = modal.querySelector('.ai-modal-replace');
    const regenBtn = modal.querySelector('.ai-modal-regenerate');

    const titleMap = {
      reply: 'AI Draft Reply',
      summarize: 'AI Ticket Summary (Internal Note)',
      rewrite: 'AI Rewrite Draft',
    };
    titleEl.textContent = titleMap[requestState.action] || 'AI Assistant';
    instructionInput.value = requestState.custom_instruction || '';

    const needsLanguagePrompt =
      requestState.action === 'rewrite' &&
      requestState.rewrite_mode === 'translate' &&
      !requestState.target_language;
    translateWrap.hidden = requestState.rewrite_mode !== 'translate';
    if (requestState.target_language) {
      translateInput.value = requestState.target_language;
    }
    modal.hidden = false;

    function resolveTargetInstance() {
      const cfg = getConfig();
      if (!cfg || !Array.isArray(cfg.instances)) return null;
      return (
        cfg.instances.find((i) => String(i.id) === String(requestState.instance_id)) ||
        cfg.instances[0] ||
        null
      );
    }

    function renderQuickPresetChips() {
      presetsEl.innerHTML = '';
      const inst = resolveTargetInstance();
      const presets = inst && Array.isArray(inst.quickPrompts) ? inst.quickPrompts : [];
      if (presets.length === 0 || requestState.action !== 'reply') {
        presetsEl.hidden = true;
        return;
      }
      presets.forEach(function (preset) {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'ai-preset-chip';
        chip.textContent = preset.label;
        chip.title = preset.instruction;
        chip.addEventListener('click', function () {
          instructionInput.value = preset.instruction;
          runRequest(preset.instruction);
        });
        presetsEl.appendChild(chip);
      });
      presetsEl.hidden = false;
    }

    renderQuickPresetChips();

    let lastResponseFormat = 'text';
    let currentViewMode = 'edit';
    let userPinnedEdit = false;
    let streamRenderRaf = null;
    let replaceConfirmArmed = false;

    function resetReplaceConfirm() {
      replaceConfirmArmed = false;
      replaceBtn.textContent = 'Insert / Replace';
    }

    function cancelPendingStreamRender() {
      if (streamRenderRaf === null) return;
      if (typeof window.cancelAnimationFrame === 'function') {
        window.cancelAnimationFrame(streamRenderRaf);
      } else {
        window.clearTimeout(streamRenderRaf);
      }
      streamRenderRaf = null;
    }

    function renderSourceBadges(meta) {
      sourcesEl.innerHTML = '';
      const badges = buildSourceBadges(meta);
      if (badges.length === 0) {
        sourcesEl.hidden = true;
        return;
      }
      badges.forEach(function (item) {
        const span = document.createElement('span');
        span.className = 'ai-source-badge' + (item.isShield ? ' is-shield' : '');
        span.textContent = item.label;
        sourcesEl.appendChild(span);
      });
      sourcesEl.hidden = false;
    }

    function renderStreamingPreviewHtml(rawText) {
      return /<[a-z][\s\S]*>/i.test(rawText)
        ? sanitizePreviewHtml(rawText)
        : plainTextToHtml(rawText);
    }

    function setViewMode(mode, allowPlainPreview) {
      currentViewMode = mode;
      const canShowPreview = lastResponseFormat === 'html' || Boolean(allowPlainPreview);
      if (!canShowPreview) {
        viewTabs.hidden = true;
        renderedEl.hidden = true;
        previewInput.hidden = false;
        return;
      }
      viewTabs.hidden = false;
      if (mode === 'preview') {
        renderedEl.innerHTML = renderStreamingPreviewHtml(previewInput.value);
        renderedEl.hidden = false;
        previewInput.hidden = true;
        tabPreviewBtn.classList.add('is-active');
        tabEditBtn.classList.remove('is-active');
      } else {
        renderedEl.hidden = true;
        previewInput.hidden = false;
        tabEditBtn.classList.add('is-active');
        tabPreviewBtn.classList.remove('is-active');
      }
    }

    function scheduleStreamPreviewRender(isCurrent) {
      if (streamRenderRaf !== null) return;
      const flushRender = function () {
        streamRenderRaf = null;
        if (!isCurrent() || modal.hidden) return;
        if (!userPinnedEdit) {
          setViewMode('preview', true);
        }
      };
      if (typeof window.requestAnimationFrame === 'function') {
        streamRenderRaf = window.requestAnimationFrame(flushRender);
      } else {
        streamRenderRaf = window.setTimeout(flushRender, 40);
      }
    }

    tabPreviewBtn.onclick = function () {
      userPinnedEdit = false;
      setViewMode('preview', false);
    };
    tabEditBtn.onclick = function () {
      userPinnedEdit = true;
      setViewMode('edit', false);
    };
    previewInput.oninput = function () {
      resetReplaceConfirm();
      if (currentViewMode === 'preview' && lastResponseFormat === 'html') {
        renderedEl.innerHTML = sanitizePreviewHtml(previewInput.value);
      }
    };

    function setActionsEnabled(enabled) {
      copyBtn.disabled = !enabled;
      appendBtn.disabled = !enabled;
      replaceBtn.disabled = !enabled;
      regenBtn.disabled = !enabled && statusEl.hidden === false;
    }

    function renderMetaFooter(meta) {
      const text = formatMetaBadge(meta);
      if (text) {
        metaEl.textContent = text;
        metaEl.hidden = false;
      } else {
        metaEl.textContent = '';
        metaEl.hidden = true;
      }
    }

    function canStreamCurrentInstance() {
      const target = resolveTargetInstance();
      return Boolean(
        target &&
        target.enableStreaming &&
        typeof window.fetch === 'function' &&
        typeof window.ReadableStream !== 'undefined' &&
        typeof window.TextDecoder === 'function'
      );
    }

    function runRequest(extraInstruction) {
      cancelPendingStreamRender();
      abortActiveRequest();
      resetReplaceConfirm();
      const requestSeq = activeRequestSeq;
      const isCurrent = () => requestSeq === activeRequestSeq;
      const controller =
        typeof window.AbortController === 'function' ? new window.AbortController() : null;
      activeAbortController = controller;

      statusEl.textContent = 'Generating with AI...';
      statusEl.hidden = false;
      errorEl.hidden = true;
      errorEl.textContent = '';
      previewWrap.hidden = true;
      previewInput.value = '';
      renderedEl.innerHTML = '';
      renderMetaFooter(null);
      renderSourceBadges(null);
      lastResponseFormat = 'text';
      userPinnedEdit = false;
      setViewMode('edit', false);
      setActionsEnabled(false);

      const payload = Object.assign({}, requestState);
      if (typeof extraInstruction === 'string') {
        const trimmedInstruction = extraInstruction.trim();
        if (trimmedInstruction !== '') {
          payload.custom_instruction = trimmedInstruction;
        } else {
          delete payload.custom_instruction;
        }
      }

      if (canStreamCurrentInstance()) {
        streamAjax(
          payload,
          function (delta) {
            statusEl.hidden = true;
            previewWrap.hidden = false;
            previewInput.value += delta;
            if (/<[a-z][\s\S]*>/i.test(previewInput.value)) {
              lastResponseFormat = 'html';
            }
            if (!userPinnedEdit) {
              scheduleStreamPreviewRender(isCurrent);
            }
          },
          function (resp) {
            cancelPendingStreamRender();
            activeAbortController = null;
            statusEl.hidden = true;
            lastResponseFormat = resp.format || 'text';
            previewInput.value = resp.text || previewInput.value;
            previewWrap.hidden = false;
            if (!userPinnedEdit) {
              setViewMode(lastResponseFormat === 'html' ? 'preview' : 'edit', false);
            }
            renderMetaFooter(resp.meta || null);
            renderSourceBadges(resp.meta || null);
            setActionsEnabled(true);
            regenBtn.disabled = false;
          },
          function (errMsg) {
            cancelPendingStreamRender();
            activeAbortController = null;
            statusEl.hidden = true;
            errorEl.textContent = errMsg || 'Failed to stream AI response.';
            errorEl.hidden = false;
            previewWrap.hidden = false;
            setViewMode('edit', false);
            regenBtn.disabled = false;
          },
          isCurrent,
          controller ? controller.signal : undefined
        );
        return;
      }

      activeJqXhr = callAjax('/response', payload);
      activeJqXhr
        .done(function (resp) {
          if (!isCurrent()) return;
          activeJqXhr = null;
          activeAbortController = null;
          statusEl.hidden = true;
          if (resp && resp.ok) {
            lastResponseFormat = resp.format || 'text';
            previewInput.value = resp.text || '';
            previewWrap.hidden = false;
            setViewMode(lastResponseFormat === 'html' ? 'preview' : 'edit', false);
            renderMetaFooter(resp.meta || null);
            renderSourceBadges(resp.meta || null);
            setActionsEnabled(true);
            regenBtn.disabled = false;
          } else {
            errorEl.textContent = (resp && resp.error) || 'Failed to generate AI response.';
            errorEl.hidden = false;
            previewWrap.hidden = false;
            setViewMode('edit', false);
            regenBtn.disabled = false;
          }
        })
        .fail(function (xhr, textStatus) {
          if (!isCurrent() || textStatus === 'abort') return;
          activeJqXhr = null;
          activeAbortController = null;
          statusEl.hidden = true;
          let msg = 'Request failed';
          try {
            const parsed = JSON.parse(xhr.responseText);
            if (parsed && parsed.error) msg = parsed.error;
          } catch {
            if (xhr && xhr.status) msg = 'HTTP ' + xhr.status + ' error';
          }
          errorEl.textContent = msg;
          errorEl.hidden = false;
          previewWrap.hidden = false;
          setViewMode('edit', false);
          regenBtn.disabled = false;
        });
    }

    const runTranslate = function () {
      const lang = translateInput.value.trim();
      if (!lang) {
        errorEl.textContent = 'Please enter a target language.';
        errorEl.hidden = false;
        translateInput.focus();
        return;
      }
      requestState.target_language = lang;
      runRequest(instructionInput.value);
    };

    translateGoBtn.onclick = runTranslate;
    translateInput.onkeydown = function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        runTranslate();
      }
    };

    regenBtn.onclick = function () {
      if (requestState.rewrite_mode === 'translate') {
        runTranslate();
      } else {
        runRequest(instructionInput.value);
      }
    };

    copyBtn.onclick = function () {
      copyTextToClipboard(previewInput.value).then(function (ok) {
        copyBtn.textContent = ok ? 'Copied!' : 'Copy failed';
        window.setTimeout(function () {
          copyBtn.textContent = 'Copy';
        }, 1500);
      });
    };

    const targetSelector = getTargetSelector(requestState.action);

    appendBtn.onclick = function () {
      if (setEditorContent(targetSelector, previewInput.value, 'append', lastResponseFormat)) {
        modal.hidden = true;
      } else {
        errorEl.textContent = 'Target editor (' + targetSelector + ') not found on this page.';
        errorEl.hidden = false;
      }
    };

    replaceBtn.onclick = function () {
      if (!replaceConfirmArmed && !isBlankHtmlDraft(getEditorContent(targetSelector))) {
        replaceConfirmArmed = true;
        replaceBtn.textContent = 'Confirm Replace';
        errorEl.textContent =
          'Editor already contains a draft. Click "Confirm Replace" to overwrite it, or click "Append".';
        errorEl.hidden = false;
        return;
      }
      resetReplaceConfirm();
      if (setEditorContent(targetSelector, previewInput.value, 'replace', lastResponseFormat)) {
        modal.hidden = true;
      } else {
        errorEl.textContent = 'Target editor (' + targetSelector + ') not found on this page.';
        errorEl.hidden = false;
      }
    };

    if (needsLanguagePrompt) {
      statusEl.hidden = true;
      errorEl.hidden = true;
      previewWrap.hidden = true;
      setActionsEnabled(false);
      translateInput.focus();
      translateInput.select();
    } else {
      runRequest(requestState.custom_instruction || '');
    }
  }

  function showInlineNotice(container, message, isError) {
    let notice = container.querySelector('.ai-toolbar-notice');
    if (!notice) {
      notice = document.createElement('span');
      notice.className = 'ai-toolbar-notice';
      container.appendChild(notice);
    }
    notice.textContent = message;
    notice.className = 'ai-toolbar-notice ' + (isError ? 'is-error' : 'is-ok');
    window.setTimeout(function () {
      notice.textContent = '';
      notice.className = 'ai-toolbar-notice';
    }, 5000);
  }

  function mountToolbar() {
    const cfg = getConfig();
    if (!cfg || !Array.isArray(cfg.instances) || cfg.instances.length === 0) {
      return;
    }
    if (document.getElementById(TOOLBAR_ID)) {
      return;
    }

    const anchor =
      document.querySelector('#response_options') ||
      document.querySelector('#reply') ||
      document.querySelector('form#reply');
    if (!anchor || !anchor.parentNode) {
      return;
    }

    const bar = document.createElement('div');
    bar.id = TOOLBAR_ID;
    bar.className = 'ai-toolbar';

    const label = document.createElement('span');
    label.className = 'ai-toolbar-brand';
    label.textContent = 'AI Assistant:';
    bar.appendChild(label);

    const savedPrefs = loadPrefs();
    let selectedInstanceId = cfg.instances[0].id;
    if (
      savedPrefs.instanceId &&
      cfg.instances.some(function (item) {
        return item.id === Number(savedPrefs.instanceId);
      })
    ) {
      selectedInstanceId = Number(savedPrefs.instanceId);
    }
    let toneSelect = null;
    let langSelect = null;
    let presetSelect = null;

    const getCurrentInstance = function () {
      return (
        cfg.instances.find(function (item) {
          return item.id === selectedInstanceId;
        }) || cfg.instances[0]
      );
    };

    const syncPresetSelect = function () {
      if (!presetSelect) return;
      const current = getCurrentInstance();
      const presets = current && Array.isArray(current.quickPrompts) ? current.quickPrompts : [];
      presetSelect.innerHTML = '';
      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = 'Quick Prompt...';
      presetSelect.appendChild(placeholder);
      presets.forEach(function (preset, idx) {
        const opt = document.createElement('option');
        opt.value = String(idx);
        opt.textContent = preset.label;
        presetSelect.appendChild(opt);
      });
      presetSelect.hidden = presets.length === 0;
    };

    if (cfg.instances.length > 1) {
      const instanceSelect = document.createElement('select');
      instanceSelect.className = 'ai-select ai-instance-select';
      instanceSelect.setAttribute('aria-label', 'AI Profile');
      cfg.instances.forEach(function (inst) {
        const opt = document.createElement('option');
        opt.value = String(inst.id);
        opt.textContent = inst.name;
        instanceSelect.appendChild(opt);
      });
      instanceSelect.value = String(selectedInstanceId);
      instanceSelect.addEventListener('change', function () {
        selectedInstanceId = Number(instanceSelect.value);
        savePref('instanceId', selectedInstanceId);
        if (toneSelect) {
          const nextTone = getCurrentInstance().defaultTone || '';
          toneSelect.value = nextTone;
          savePref('tone', nextTone);
        }
        syncPresetSelect();
      });
      bar.appendChild(instanceSelect);
    }

    if (cfg.canReply) {
      toneSelect = document.createElement('select');
      toneSelect.className = 'ai-select ai-tone-select';
      toneSelect.setAttribute('aria-label', 'Reply Tone');
      [
        ['', 'Default Tone'],
        ['professional', 'Professional'],
        ['friendly', 'Friendly'],
        ['concise', 'Concise'],
        ['technical', 'Technical'],
      ].forEach(function (pair) {
        const opt = document.createElement('option');
        opt.value = pair[0];
        opt.textContent = pair[1];
        toneSelect.appendChild(opt);
      });
      toneSelect.value =
        typeof savedPrefs.tone === 'string'
          ? savedPrefs.tone
          : getCurrentInstance().defaultTone || '';
      toneSelect.addEventListener('change', function () {
        savePref('tone', toneSelect.value);
      });
      bar.appendChild(toneSelect);

      langSelect = document.createElement('select');
      langSelect.className = 'ai-select ai-lang-select';
      langSelect.setAttribute('aria-label', 'Reply Language');
      [
        ['', 'Auto Language'],
        ['English', 'English'],
        ['Polish', 'Polish'],
        ['German', 'German'],
        ['Spanish', 'Spanish'],
        ['French', 'French'],
        ['Dutch', 'Dutch'],
        ['Portuguese', 'Portuguese'],
      ].forEach(function (pair) {
        const opt = document.createElement('option');
        opt.value = pair[0];
        opt.textContent = pair[1];
        langSelect.appendChild(opt);
      });
      langSelect.value =
        typeof savedPrefs.targetLanguage === 'string' ? savedPrefs.targetLanguage : '';
      langSelect.addEventListener('change', function () {
        savePref('targetLanguage', langSelect.value);
      });
      bar.appendChild(langSelect);

      const replyBtn = document.createElement('button');
      replyBtn.type = 'button';
      replyBtn.className = 'ai-btn ai-btn-primary';
      replyBtn.textContent = 'Generate Reply';
      replyBtn.addEventListener('click', function () {
        openGenerationFlow({
          ticket_id: cfg.ticketId,
          instance_id: selectedInstanceId,
          action: 'reply',
          tone: toneSelect ? toneSelect.value : '',
          target_language: langSelect ? langSelect.value : '',
        });
      });
      bar.appendChild(replyBtn);
    }

    if (cfg.canPostNote && cfg.instances.some((i) => i.enableSummarize)) {
      const sumBtn = document.createElement('button');
      sumBtn.type = 'button';
      sumBtn.className = 'ai-btn ai-btn-secondary';
      sumBtn.textContent = 'Summarize';
      sumBtn.addEventListener('click', function () {
        const current = getCurrentInstance();
        if (!current.enableSummarize) {
          showInlineNotice(bar, 'Summarization is disabled on this AI profile.', true);
          return;
        }
        openGenerationFlow({
          ticket_id: cfg.ticketId,
          instance_id: selectedInstanceId,
          action: 'summarize',
        });
      });
      bar.appendChild(sumBtn);
    }

    if (cfg.canReply && cfg.instances.some((i) => i.enableRewrite)) {
      const rewriteSelect = document.createElement('select');
      rewriteSelect.className = 'ai-select ai-rewrite-select';
      rewriteSelect.setAttribute('aria-label', 'Rewrite Draft Action');
      [
        ['', 'Rewrite Draft...'],
        ['improve', 'Polish & Improve'],
        ['shorter', 'Make Shorter'],
        ['formal', 'More Formal'],
        ['friendly', 'Warmer / Friendly'],
        ['translate', 'Translate Draft...'],
      ].forEach(function (pair) {
        const opt = document.createElement('option');
        opt.value = pair[0];
        opt.textContent = pair[1];
        rewriteSelect.appendChild(opt);
      });
      rewriteSelect.addEventListener('change', function () {
        const mode = rewriteSelect.value;
        rewriteSelect.value = '';
        if (!mode) return;

        const current = getCurrentInstance();
        if (!current.enableRewrite) {
          showInlineNotice(bar, 'Draft rewriting is disabled on this AI profile.', true);
          return;
        }

        const draft = getEditorContent('#response').trim();
        if (isBlankHtmlDraft(draft)) {
          showInlineNotice(bar, 'Type a draft reply first before rewriting.', true);
          return;
        }

        openGenerationFlow({
          ticket_id: cfg.ticketId,
          instance_id: selectedInstanceId,
          action: 'rewrite',
          rewrite_mode: mode,
          target_language: '',
          draft_text: draft,
        });
      });
      bar.appendChild(rewriteSelect);
    }

    if (
      cfg.canReply &&
      cfg.instances.some((i) => Array.isArray(i.quickPrompts) && i.quickPrompts.length > 0)
    ) {
      presetSelect = document.createElement('select');
      presetSelect.className = 'ai-select ai-preset-select';
      presetSelect.setAttribute('aria-label', 'Quick Action Preset');
      syncPresetSelect();
      presetSelect.addEventListener('change', function () {
        const idx = presetSelect.value;
        presetSelect.value = '';
        if (idx === '') return;
        const current = getCurrentInstance();
        const presets = current && Array.isArray(current.quickPrompts) ? current.quickPrompts : [];
        const preset = presets[Number(idx)];
        if (!preset || !preset.instruction) return;
        openGenerationFlow({
          ticket_id: cfg.ticketId,
          instance_id: selectedInstanceId,
          action: 'reply',
          tone: toneSelect ? toneSelect.value : '',
          target_language: langSelect ? langSelect.value : '',
          custom_instruction: preset.instruction,
        });
      });
      bar.appendChild(presetSelect);
    }

    if (cfg.isAdmin) {
      const testBtn = document.createElement('button');
      testBtn.type = 'button';
      testBtn.className = 'ai-btn ai-btn-ghost';
      testBtn.textContent = 'Test API';
      testBtn.title = 'Run an admin connectivity check against the selected AI profile';
      testBtn.addEventListener('click', function () {
        testBtn.disabled = true;
        showInlineNotice(bar, 'Testing connection...', false);
        callAjax('/test', { instance_id: selectedInstanceId })
          .done(function (resp) {
            if (resp && resp.ok) {
              showInlineNotice(
                bar,
                'Connected (' + resp.provider + ', ' + resp.latency_ms + ' ms)',
                false
              );
            } else {
              showInlineNotice(bar, (resp && resp.error) || 'Connection failed', true);
            }
          })
          .fail(function (xhr) {
            let msg = 'Connection failed';
            try {
              const r = JSON.parse(xhr.responseText);
              if (r && r.error) msg = r.error;
            } catch {
              // ignore parse error
            }
            showInlineNotice(bar, msg, true);
          })
          .always(function () {
            testBtn.disabled = false;
          });
      });
      bar.appendChild(testBtn);
    }

    anchor.parentNode.insertBefore(bar, anchor);
  }

  function init() {
    mountToolbar();
  }

  window.AIResponseGenInit = init;
  window.AIResponseGenHelpers = {
    getTargetSelector: getTargetSelector,
    isBlankHtmlDraft: isBlankHtmlDraft,
    plainTextToHtml: plainTextToHtml,
    sanitizePreviewHtml: sanitizePreviewHtml,
    parseSseFrames: parseSseFrames,
    formatMetaBadge: formatMetaBadge,
    buildSourceBadges: buildSourceBadges,
  };

  if (window.jQuery) {
    const $ = window.jQuery;
    if (!window.__aiResponseGenBound) {
      window.__aiResponseGenBound = true;
      $(document).on('click.aiResponseGen', 'a.ai-generate-reply', function (e) {
        e.preventDefault();
        const $a = $(this);
        const tid = $a.data('ticket-id');
        if (!tid) return false;
        openGenerationFlow({
          ticket_id: tid,
          instance_id: $a.data('instance-id') || '',
          action: $a.data('action') || 'reply',
        });
        return false;
      });

      $(document).on('pjax:end.aiResponseGen', init);
    }
    $(init);
  } else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
