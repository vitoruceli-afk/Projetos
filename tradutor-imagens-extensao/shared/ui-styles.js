// CSS da interface injetada nas páginas. Fica num script (e não num arquivo .css
// carregado por <link>) para funcionar em páginas com CSP restritiva. Aplicado via
// CSSStyleSheet construível no shadow DOM, que não sofre bloqueio de CSP.
globalThis.TI_UI_CSS = `
:host { all: initial; }
* { box-sizing: border-box; }

:host {
  --ti-primary: #1a66e0;
  --ti-primary-strong: #1452b8;
  --ti-bg: #ffffff;
  --ti-surface: #f5f8ff;
  --ti-text: #0f172a;
  --ti-muted: #475569;
  --ti-border: #d6e0f0;
  --ti-danger: #b42318;
  --ti-success: #067647;
  --ti-shadow: 0 12px 32px rgba(15, 23, 42, 0.18);
  --ti-radius: 14px;
  --ti-font-scale: 1;
}
@media (prefers-color-scheme: dark) {
  :host {
    --ti-primary: #6aa3ff;
    --ti-primary-strong: #8cb8ff;
    --ti-bg: #111827;
    --ti-surface: #1f2937;
    --ti-text: #f8fafc;
    --ti-muted: #cbd5e1;
    --ti-border: #334155;
    --ti-danger: #fda29b;
    --ti-success: #6ce9a6;
    --ti-shadow: 0 12px 32px rgba(0, 0, 0, 0.5);
  }
}

.ti-stack {
  position: fixed;
  right: 16px;
  bottom: 16px;
  width: min(400px, calc(100vw - 32px));
  max-height: calc(100vh - 32px);
  overflow: auto;
  display: flex;
  flex-direction: column;
  gap: 10px;
  font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
  font-size: calc(14px * var(--ti-font-scale));
  color: var(--ti-text);
  pointer-events: none;
}
.ti-stack > * { pointer-events: auto; }

.ti-card {
  background: var(--ti-bg);
  border: 1px solid var(--ti-border);
  border-radius: var(--ti-radius);
  box-shadow: var(--ti-shadow);
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  outline: none;
}
.ti-header { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.ti-title { margin: 0; font-size: 1.05em; font-weight: 700; }
.ti-langs { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; color: var(--ti-muted); }
.ti-chip {
  background: var(--ti-surface);
  border: 1px solid var(--ti-border);
  border-radius: 999px;
  padding: 3px 10px;
  font-weight: 600;
  color: var(--ti-text);
}
.ti-section-label {
  font-size: 0.8em;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--ti-muted);
  margin-bottom: 4px;
}
.ti-text {
  margin: 0;
  white-space: pre-wrap;
  word-break: break-word;
  background: var(--ti-surface);
  border-radius: 10px;
  padding: 10px 12px;
  max-height: 180px;
  overflow: auto;
  line-height: 1.45;
}
.ti-text-translated { border-left: 3px solid var(--ti-primary); }
.ti-figure { margin: 0; }
.ti-figure img {
  display: block;
  max-width: 100%;
  max-height: 260px;
  border-radius: 10px;
  border: 1px solid var(--ti-border);
  margin: 0 auto;
}
.ti-actions { display: flex; flex-wrap: wrap; gap: 6px; }
.ti-btn {
  appearance: none;
  border: 1px solid var(--ti-border);
  background: var(--ti-bg);
  color: var(--ti-text);
  border-radius: 10px;
  padding: 7px 10px;
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}
.ti-btn:hover { background: var(--ti-surface); }
.ti-btn:focus-visible { outline: 3px solid var(--ti-primary); outline-offset: 2px; }
.ti-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.ti-btn-primary { background: var(--ti-primary); border-color: var(--ti-primary); color: #fff; }
.ti-btn-primary:hover { background: var(--ti-primary-strong); }
.ti-btn-icon { padding: 4px 9px; font-size: 1.1em; line-height: 1; }
.ti-status { margin: 0; min-height: 1.2em; font-size: 0.9em; color: var(--ti-muted); }
.ti-status-error { color: var(--ti-danger); }
.ti-status-ok { color: var(--ti-success); }

.ti-toast {
  background: var(--ti-bg);
  color: var(--ti-text);
  border: 1px solid var(--ti-border);
  border-left: 4px solid var(--ti-primary);
  border-radius: 12px;
  box-shadow: var(--ti-shadow);
  padding: 10px 14px;
  line-height: 1.4;
}
.ti-toast-error { border-left-color: var(--ti-danger); }
.ti-toast-success { border-left-color: var(--ti-success); }

.ti-hover-btn {
  position: fixed;
  appearance: none;
  border: 0;
  border-radius: 999px;
  background: var(--ti-primary);
  color: #fff;
  font: 600 13px/1 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
  padding: 8px 12px;
  box-shadow: 0 4px 14px rgba(0, 0, 0, 0.3);
  cursor: pointer;
  pointer-events: auto;
}
.ti-hover-btn:hover { background: var(--ti-primary-strong); }
.ti-hover-btn:focus-visible { outline: 3px solid #fff; outline-offset: 2px; }

.ti-page-overlay {
  position: fixed;
  object-fit: fill;
  pointer-events: none;
  border-radius: 2px;
}

.ti-highlight {
  position: fixed;
  pointer-events: none;
  border: 3px solid var(--ti-primary);
  background: rgba(26, 102, 224, 0.12);
  border-radius: 6px;
  box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.85);
}

.ti-select-layer {
  position: fixed;
  inset: 0;
  cursor: crosshair;
  background: rgba(15, 23, 42, 0.35);
  pointer-events: auto;
  touch-action: none;
}
.ti-select-box {
  position: fixed;
  border: 2px dashed #fff;
  background: rgba(26, 102, 224, 0.2);
  pointer-events: none;
}
.ti-select-hint {
  position: fixed;
  top: 12px;
  left: 50%;
  transform: translateX(-50%);
  background: #0f172a;
  color: #fff;
  padding: 8px 14px;
  border-radius: 999px;
  font: 600 13px/1.2 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
  pointer-events: none;
}
`;
