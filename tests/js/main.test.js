import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

function loadHelpers() {
  const code = fs.readFileSync(path.resolve('assets/js/main.js'), 'utf8');
  const windowObj = {
    jQuery: null,
  };
  const context = {
    window: windowObj,
    document: {
      readyState: 'complete',
      addEventListener: () => {},
    },
    navigator: {},
  };
  vm.runInNewContext(code, context);
  return windowObj.AIResponseGenHelpers;
}

test('getTargetSelector maps summarize to internal note and others to response', () => {
  const h = loadHelpers();
  assert.equal(h.getTargetSelector('summarize'), '#internal_note');
  assert.equal(h.getTargetSelector('reply'), '#response');
  assert.equal(h.getTargetSelector('rewrite'), '#response');
});

test('isBlankHtmlDraft detects empty Redactor markup', () => {
  const h = loadHelpers();
  assert.equal(h.isBlankHtmlDraft(''), true);
  assert.equal(h.isBlankHtmlDraft('<p><br></p>'), true);
  assert.equal(h.isBlankHtmlDraft('<p>&nbsp;  </p>'), true);
  assert.equal(h.isBlankHtmlDraft('<p>Hello customer</p>'), false);
});

test('plainTextToHtml escapes HTML and splits paragraphs', () => {
  const h = loadHelpers();
  const out = h.plainTextToHtml('Hi <user>\nLine 2\n\nSecond & para');
  assert.equal(out, '<p>Hi &lt;user&gt;<br>Line 2</p>\n<p>Second &amp; para</p>');
});

test('sanitizePreviewHtml strips tags in fallback mode', () => {
  const h = loadHelpers();
  const safe = h.sanitizePreviewHtml('<p>Hello</p><script>alert(1)</script>');
  assert.equal(safe.includes('<script>'), false);
  assert.equal(safe.includes('Hello'), true);

  const nested = h.sanitizePreviewHtml('<scr<script>ipt>alert(1)</script><script');
  assert.equal(nested.includes('<script'), false);
  assert.equal(nested, '<p>iptalert(1)script</p>');
});

test('parseSseFrames extracts complete frames and keeps remainder', () => {
  const h = loadHelpers();
  const chunk =
    'event: delta\ndata: {"delta":"Hi "}\n\nevent: delta\ndata: {"delta":"there"}\n\nevent: done\ndata: {"ok":';
  const parsed = h.parseSseFrames(chunk);
  assert.equal(parsed.frames.length, 2);
  assert.equal(parsed.frames[0].event, 'delta');
  assert.equal(parsed.frames[0].data, '{"delta":"Hi "}');
  assert.equal(parsed.frames[1].data, '{"delta":"there"}');
  assert.equal(parsed.remainder, 'event: done\ndata: {"ok":');
});

test('formatMetaBadge formats model, latency, and token counts cleanly', () => {
  const h = loadHelpers();
  assert.equal(
    h.formatMetaBadge({
      model: 'gpt-5-nano',
      latency_ms: 142.4,
      usage: { input_tokens: 412, output_tokens: 118, total_tokens: 530 },
    }),
    'gpt-5-nano \u00b7 142 ms \u00b7 412 in / 118 out tokens'
  );
  assert.equal(h.formatMetaBadge({ model: 'llama3.3', latency_ms: 85 }), 'llama3.3 \u00b7 85 ms');
  assert.equal(h.formatMetaBadge(null), '');
});

test('buildSourceBadges formats PII redaction shield and KB sources cleanly', () => {
  const h = loadHelpers();
  const badges = h.buildSourceBadges({
    redacted_pii: true,
    sources: {
      faqs: ['SAML Audience Guide'],
      canned: ['SSO Reset Macro'],
    },
  });
  assert.deepStrictEqual(JSON.parse(JSON.stringify(badges)), [
    { label: 'PII Redacted', isShield: true },
    { label: 'FAQ: SAML Audience Guide', isShield: false },
    { label: 'Canned: SSO Reset Macro', isShield: false },
  ]);
  assert.deepStrictEqual(JSON.parse(JSON.stringify(h.buildSourceBadges(null))), []);
});
