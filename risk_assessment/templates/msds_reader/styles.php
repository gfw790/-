<style>
  * { box-sizing: border-box; }
  :root {
    --bg: #08111d;
    --panel: #0f1d31;
    --panel-soft: #13243c;
    --line: rgba(194, 211, 229, 0.18);
    --text: #f5f8fc;
    --muted: #9fb4c8;
    --accent: #ffb11a;
    --accent-dark: #1f2937;
    --shadow: 0 24px 50px rgba(0, 0, 0, 0.28);
  }
  html {
    background: var(--bg);
    scroll-behavior: smooth;
  }
  body {
    margin: 0;
    min-height: 100vh;
    background:
      radial-gradient(circle at top, rgba(255, 177, 26, 0.16), transparent 28%),
      linear-gradient(180deg, #091220 0%, #0a1423 100%);
    color: var(--text);
    font-family: "Malgun Gothic", sans-serif;
  }
  a { color: inherit; }
  .reader-shell {
    width: min(100%, 1240px);
    margin: 0 auto;
    padding: 22px 18px 40px;
  }
  .reader-content-grid {
    display: grid;
    gap: 16px;
    align-items: start;
  }
  .reader-topbar {
    position: sticky;
    top: 0;
    z-index: 50;
    margin-bottom: 16px;
    padding-top: max(12px, env(safe-area-inset-top));
    backdrop-filter: blur(18px);
  }
  .reader-topbar-inner {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 12px;
    border: 1px solid var(--line);
    border-radius: 22px;
    background: rgba(11, 22, 38, 0.82);
    box-shadow: var(--shadow);
  }
  .reader-title {
    min-width: 0;
  }
  .reader-title .eyebrow {
    margin: 0 0 6px;
    color: var(--muted);
    font-size: 12px;
    letter-spacing: 0.16em;
    text-transform: uppercase;
  }
  .reader-title h1 {
    margin: 0;
    font-size: 22px;
    line-height: 1.3;
    word-break: keep-all;
  }
  .reader-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 44px;
    padding: 0 14px;
    border: 1px solid transparent;
    border-radius: 14px;
    text-decoration: none;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
  }
  .btn-accent {
    background: var(--accent);
    color: var(--accent-dark);
  }
  .btn-ghost {
    background: rgba(255, 255, 255, 0.06);
    border-color: var(--line);
    color: var(--text);
  }
  .btn-soft {
    background: rgba(255, 255, 255, 0.1);
    border-color: rgba(255, 255, 255, 0.12);
    color: var(--text);
  }
  .info-card,
  .viewer-card,
  .status-card {
    border: 1px solid var(--line);
    border-radius: 28px;
    background: linear-gradient(180deg, rgba(19, 36, 60, 0.96), rgba(12, 23, 38, 0.98));
    box-shadow: var(--shadow);
  }
  .info-card {
    padding: 20px;
    margin-bottom: 16px;
  }
  .info-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
  }
  .info-item {
    padding: 14px;
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
  }
  .info-item dt {
    margin: 0 0 8px;
    color: var(--muted);
    font-size: 12px;
    font-weight: 700;
  }
  .info-item dd {
    margin: 0;
    font-size: 15px;
    line-height: 1.6;
    word-break: break-word;
  }
  .status-card {
    padding: 24px 20px;
    text-align: center;
  }
  .status-card h2 {
    margin: 0 0 10px;
    font-size: 24px;
  }
  .status-card p {
    margin: 0;
    color: var(--muted);
    line-height: 1.7;
  }
  .viewer-card {
    padding: 16px;
  }
  .mobile-text-reader {
    display: none;
    width: 100%;
    max-width: 100%;
    min-width: 0;
    margin-bottom: 14px;
    border: 1px solid var(--line);
    border-radius: 24px;
    background: linear-gradient(180deg, rgba(19, 36, 60, 0.98), rgba(11, 21, 35, 0.98));
    box-shadow: var(--shadow);
    overflow: hidden;
  }
  .mobile-text-head {
    padding: 16px 18px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    background: linear-gradient(135deg, rgba(255, 177, 26, 0.15), rgba(255, 255, 255, 0.02));
  }
  .mobile-text-head h2 {
    margin: 0 0 6px;
    font-size: 18px;
  }
  .mobile-text-head p {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.6;
  }
  .mobile-text-head-actions {
    display: none;
    margin-top: 12px;
  }
  .mobile-text-head-actions .btn {
    min-height: 38px;
    padding: 0 12px;
    font-size: 13px;
  }
  .mobile-text-status {
    padding: 12px 18px 0;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.6;
  }
  .mobile-glossary-manage {
    padding: 12px 14px 0;
  }
  .mobile-glossary-manage .btn {
    width: 100%;
    min-height: 40px;
    font-size: 13px;
  }
  .mobile-glossary-manage-pc {
    display: none;
  }
  .pc-glossary-editor {
    margin: 12px 14px 0;
    padding: 16px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.04);
  }
  .pc-glossary-editor-page {
    position: sticky;
    top: calc(88px + env(safe-area-inset-top));
    height: calc(100vh - 108px);
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }
  .pc-glossary-editor-head h3 {
    margin: 0 0 6px;
    font-size: 16px;
    color: #ffd27a;
  }
  .pc-glossary-editor-head p {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.6;
  }
  .pc-glossary-editor-form {
    display: flex;
    flex-direction: column;
    gap: 14px;
    margin-top: 12px;
    flex: 1 1 auto;
    min-height: 0;
  }
  .pc-glossary-editor-toolbar {
    position: sticky;
    top: 0;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding-bottom: 4px;
    background: linear-gradient(180deg, rgba(15, 29, 49, 0.98), rgba(15, 29, 49, 0.9));
  }
  .pc-glossary-editor-list {
    flex: 1 1 auto;
    min-height: 0;
    overflow: auto;
    padding-right: 6px;
    display: grid;
    gap: 14px;
    align-content: start;
  }
  .pc-glossary-row {
    display: grid;
    gap: 12px;
    padding: 14px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 16px;
    background: rgba(8, 17, 29, 0.54);
  }
  .pc-glossary-row-new {
    position: sticky;
    top: 0;
    z-index: 1;
    background: linear-gradient(180deg, rgba(19, 36, 60, 0.98), rgba(11, 21, 35, 0.96));
    border-color: rgba(255, 177, 26, 0.24);
  }
  .pc-glossary-field {
    display: grid;
    gap: 6px;
  }
  .pc-glossary-field span {
    color: var(--muted);
    font-size: 12px;
    font-weight: 700;
  }
  .pc-glossary-field input,
  .pc-glossary-field textarea {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 14px;
    background: rgba(12, 23, 38, 0.96);
    color: var(--text);
    font: inherit;
    font-size: 14px;
    line-height: 1.6;
    outline: none;
  }
  .pc-glossary-field textarea {
    resize: vertical;
    min-height: 110px;
  }
  .pc-glossary-field input:focus,
  .pc-glossary-field textarea:focus {
    border-color: rgba(255, 177, 26, 0.48);
    box-shadow: 0 0 0 3px rgba(255, 177, 26, 0.12);
  }
  .pc-glossary-editor-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    flex-wrap: wrap;
  }
  .mobile-section-jump {
    display: none;
    position: sticky;
    top: 8px;
    z-index: 8;
    padding: 12px 14px 0;
    background: linear-gradient(180deg, rgba(19, 36, 60, 0.96), rgba(19, 36, 60, 0.82) 82%, rgba(19, 36, 60, 0));
  }
  .mobile-section-select {
    width: 100%;
    min-height: 44px;
    padding: 0 14px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 14px;
    background: rgba(12, 23, 38, 0.96);
    color: var(--text);
    font: inherit;
    font-size: 14px;
    outline: none;
  }
  .mobile-section-select:focus {
    border-color: rgba(255, 177, 26, 0.48);
    box-shadow: 0 0 0 3px rgba(255, 177, 26, 0.12);
  }
  .mobile-text-status.is-error {
    color: #ffcabd;
  }
  .mobile-text-body {
    display: grid;
    width: 100%;
    min-width: 0;
    gap: 10px;
    padding: 14px;
  }
  .mobile-text-section {
    position: relative;
    width: 100%;
    max-width: 100%;
    min-width: 0;
    padding: 14px;
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.045);
    border: 1px solid rgba(255, 255, 255, 0.08);
  }
  .mobile-text-section.is-editable {
    padding-top: 42px;
  }
  .mobile-card-edit {
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 2;
    min-height: 30px;
    padding: 0 10px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 999px;
    background: rgba(8, 17, 29, 0.78);
    color: #dce7f4;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
  }
  .mobile-card-edit:active {
    transform: scale(0.98);
  }
  .mobile-text-section.has-index {
    border-color: rgba(255, 177, 26, 0.28);
    background: linear-gradient(180deg, rgba(255, 177, 26, 0.08), rgba(255, 255, 255, 0.04));
  }
  .mobile-text-section h3 {
    margin: 0 0 10px;
    font-size: 16px;
    line-height: 1.4;
    color: #ffd27a;
  }
  .mobile-text-paragraph {
    margin: 0;
    color: #f4f7fb;
    font-size: 15px;
    line-height: 1.8;
    word-break: keep-all;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
  }
  .mobile-text-paragraph + .mobile-text-paragraph {
    margin-top: 10px;
  }
  .mobile-glossary-trigger,
  .mobile-glossary-trigger:link,
  .mobile-glossary-trigger:visited,
  .mobile-glossary-trigger:hover,
  .mobile-glossary-trigger:active {
    display: inline;
    padding: 0;
    border: 0;
    border-bottom: 1px solid rgba(255, 177, 26, 0.75);
    background: transparent;
    color: #f4f7fb;
    font: inherit;
    font-weight: inherit;
    font-size: inherit;
    line-height: inherit;
    cursor: pointer;
    text-decoration: none;
  }
  .mobile-text-fallback-kv.has-glossary-trigger {
    display: block;
    margin-left: 14px;
    padding-left: 12px;
    color: #dce7f4;
    font-size: 14px;
    line-height: 1.75;
  }
  .mobile-text-fallback-kv.has-glossary-trigger .mobile-glossary-trigger {
    display: inline-block;
    text-align: left;
    line-height: 1.75;
  }
  .mobile-text-fallback-body {
    margin-left: 14px;
    padding-left: 12px;
  }
  .mobile-text-fallback-subhead {
    margin-top: 12px;
    padding: 2px 0 0;
    color: #ffe2a0;
    font-size: 14px;
    font-weight: 800;
    line-height: 1.65;
    word-break: keep-all;
    overflow-wrap: anywhere;
  }
  .mobile-text-fallback-subhead:first-of-type {
    margin-top: 0;
  }
  .mobile-text-fallback-kv {
    display: flex;
    flex-wrap: wrap;
    gap: 0 4px;
    align-items: start;
    margin-top: 8px;
    margin-left: 14px;
    padding-left: 12px;
    min-width: 0;
  }
  .mobile-text-fallback-kv-detail {
    margin-left: 8px;
  }
  .mobile-text-fallback-kv-label {
    color: #dce7f4;
    font-size: 14px;
    line-height: 1.75;
    white-space: normal;
    flex: 0 1 auto;
  }
  .mobile-text-fallback-kv-value {
    flex: 0 1 auto;
    min-width: 0;
    color: #dce7f4;
    font-size: 14px;
    line-height: 1.75;
    word-break: keep-all;
    overflow-wrap: anywhere;
  }
  .mobile-text-fallback-detail {
    margin-top: 8px;
    margin-left: 8px;
    padding-left: 12px;
    color: #dce7f4;
    font-size: 14px;
    line-height: 1.75;
    word-break: keep-all;
    overflow-wrap: anywhere;
  }
  .mobile-pictogram-card {
    margin-top: 10px;
    margin-left: 14px;
    padding: 14px;
    border-radius: 16px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    background: rgba(255, 255, 255, 0.04);
  }
  .mobile-pictogram-card.is-inline {
    margin-left: 0;
    padding: 12px;
  }
  .mobile-pictogram-title {
    margin: 0 0 10px;
    color: #dce7f4;
    font-size: 14px;
    font-weight: 800;
    line-height: 1.5;
  }
  .mobile-pictogram-list {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
  }
  .mobile-pictogram-item {
    position: relative;
    display: grid;
    justify-items: center;
    gap: 8px;
    min-width: 92px;
  }
  .mobile-pictogram-item::before {
    content: "";
    position: absolute;
    top: 10px;
    width: 50px;
    height: 50px;
    background: #ffffff;
    transform: rotate(45deg);
    z-index: 0;
  }
  .mobile-pictogram-svg {
    position: relative;
    z-index: 1;
    width: 72px;
    height: 72px;
    display: block;
  }
  .mobile-pictogram-image {
    position: relative;
    z-index: 1;
    width: 72px;
    height: 72px;
    display: block;
    object-fit: contain;
  }
  .mobile-pictogram-label {
    color: #f4f7fb;
    font-size: 12px;
    line-height: 1.45;
    text-align: center;
    word-break: keep-all;
  }
  .mobile-text-empty {
    padding: 18px;
    color: var(--muted);
    text-align: center;
    line-height: 1.7;
  }
  .pc-section-editor {
    margin: 12px 14px 0;
    padding: 16px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.04);
  }
  .pc-section-editor-head h3 {
    margin: 0 0 6px;
    font-size: 16px;
    color: #ffd27a;
  }
  .pc-section-editor-head p {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.6;
  }
  .pc-section-editor-notice {
    margin-top: 12px;
    padding: 10px 12px;
    border-radius: 12px;
    background: rgba(56, 189, 122, 0.14);
    color: #b7f7cf;
    font-size: 13px;
    font-weight: 700;
  }
  .pc-section-editor-form {
    display: grid;
    gap: 12px;
    margin-top: 12px;
  }
  .pc-section-editor-textarea {
    width: 100%;
    min-height: 240px;
    padding: 14px 16px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 16px;
    background: rgba(8, 17, 29, 0.72);
    color: var(--text);
    font: inherit;
    font-size: 14px;
    line-height: 1.75;
    resize: vertical;
    outline: none;
  }
  .pc-section-editor-textarea:focus {
    border-color: rgba(255, 177, 26, 0.48);
    box-shadow: 0 0 0 3px rgba(255, 177, 26, 0.12);
  }
  .pc-section-editor-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    flex-wrap: wrap;
  }
  .mobile-editor-modal {
    position: fixed;
    inset: 0;
    z-index: 120;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 18px;
    background: rgba(3, 9, 18, 0.72);
    backdrop-filter: blur(10px);
  }
  .mobile-editor-modal.is-open {
    display: flex;
  }
  .mobile-editor-dialog {
    width: min(100%, 720px);
    max-height: min(82vh, 880px);
    display: grid;
    gap: 12px;
    padding: 18px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 24px;
    background: linear-gradient(180deg, rgba(19, 36, 60, 0.98), rgba(11, 21, 35, 0.98));
    box-shadow: var(--shadow);
  }
  .mobile-editor-head h3 {
    margin: 0 0 6px;
    font-size: 18px;
    color: #ffd27a;
  }
  .mobile-editor-head p {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.6;
  }
  .mobile-editor-textarea {
    width: 100%;
    min-height: 320px;
    padding: 14px 16px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.04);
    color: var(--text);
    font: inherit;
    font-size: 14px;
    line-height: 1.7;
    resize: vertical;
    outline: none;
  }
  .mobile-editor-textarea:focus {
    border-color: rgba(255, 177, 26, 0.48);
    box-shadow: 0 0 0 3px rgba(255, 177, 26, 0.12);
  }
  .mobile-editor-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
  }
  .mobile-glossary-modal,
  .mobile-glossary-editor-modal {
    position: fixed;
    inset: 0;
    z-index: 125;
    display: none;
    align-items: end;
    justify-content: center;
    padding: 16px 12px calc(24px + env(safe-area-inset-bottom));
    background: rgba(3, 9, 18, 0.68);
    backdrop-filter: blur(10px);
  }
  .mobile-glossary-modal.is-open,
  .mobile-glossary-editor-modal.is-open {
    display: flex;
  }
  .mobile-glossary-dialog,
  .mobile-glossary-editor-dialog {
    width: 100%;
    max-width: 720px;
    max-height: 84vh;
    overflow: auto;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 26px;
    background: linear-gradient(180deg, rgba(16, 31, 51, 0.99), rgba(8, 17, 29, 0.99));
    box-shadow: var(--shadow);
  }
  .mobile-glossary-head,
  .mobile-glossary-editor-head {
    padding: 18px 18px 12px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  }
  .mobile-glossary-eyebrow {
    margin: 0 0 6px;
    color: var(--muted);
    font-size: 11px;
    letter-spacing: 0.12em;
    text-transform: uppercase;
  }
  .mobile-glossary-head h3,
  .mobile-glossary-editor-head h3 {
    margin: 0;
    color: #ffd27a;
    font-size: 18px;
    line-height: 1.4;
  }
  .mobile-glossary-editor-head p {
    margin: 8px 0 0;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.6;
  }
  .mobile-glossary-content {
    padding: 18px;
    color: #f4f7fb;
    font-size: 15px;
    line-height: 1.8;
    white-space: pre-wrap;
    word-break: keep-all;
    overflow-wrap: anywhere;
  }
  .mobile-glossary-actions,
  .mobile-glossary-editor-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    padding: 14px 18px 18px;
  }
  .mobile-glossary-sheet {
    position: fixed;
    inset: 0;
    z-index: 126;
    display: none;
    align-items: end;
    justify-content: center;
    padding: 16px 12px calc(24px + env(safe-area-inset-bottom));
  }
  .mobile-glossary-sheet:target {
    display: flex;
  }
  .mobile-glossary-sheet-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(3, 9, 18, 0.68);
    backdrop-filter: blur(10px);
  }
  .mobile-glossary-sheet-dialog {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 720px;
    max-height: 84vh;
    overflow: auto;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 26px;
    background: linear-gradient(180deg, rgba(16, 31, 51, 0.99), rgba(8, 17, 29, 0.99));
    box-shadow: var(--shadow);
  }
  .mobile-glossary-editor-list {
    display: grid;
    gap: 12px;
    padding: 16px 16px 0;
  }
  .mobile-glossary-row {
    display: grid;
    gap: 10px;
    padding: 14px;
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
  }
  .mobile-glossary-field {
    display: grid;
    gap: 6px;
  }
  .mobile-glossary-field label {
    color: #9fc4eb;
    font-size: 12px;
    font-weight: 800;
  }
  .mobile-glossary-input,
  .mobile-glossary-textarea {
    width: 100%;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 14px;
    background: rgba(12, 23, 38, 0.96);
    color: var(--text);
    font: inherit;
    outline: none;
  }
  .mobile-glossary-input {
    min-height: 42px;
    padding: 0 12px;
    font-size: 14px;
  }
  .mobile-glossary-textarea {
    min-height: 120px;
    padding: 12px;
    font-size: 14px;
    line-height: 1.7;
    resize: vertical;
  }
  .mobile-glossary-remove {
    justify-self: end;
    min-height: 34px;
    padding: 0 12px;
    border: 1px solid rgba(255, 120, 120, 0.22);
    border-radius: 999px;
    background: rgba(255, 107, 107, 0.08);
    color: #ffc7c7;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
  }
  .mobile-glossary-add {
    margin: 14px 16px 0;
    width: calc(100% - 32px);
  }
  .mobile-scroll-top {
    position: fixed;
    right: 16px;
    bottom: calc(88px + env(safe-area-inset-bottom));
    z-index: 32;
    display: none;
    align-items: center;
    justify-content: center;
    min-width: 48px;
    min-height: 48px;
    padding: 0 14px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 999px;
    background: rgba(255, 177, 26, 0.96);
    color: #122033;
    font-size: 13px;
    font-weight: 800;
    box-shadow: 0 18px 38px rgba(0, 0, 0, 0.32);
    cursor: pointer;
  }
  .mobile-scroll-top.is-visible {
    display: inline-flex;
  }
  .mobile-text-subsection {
    margin-top: 10px;
    padding: 12px;
    border-radius: 16px;
    background: rgba(255, 177, 26, 0.08);
    border: 1px solid rgba(255, 177, 26, 0.18);
  }
  .mobile-text-subsection:first-child {
    margin-top: 0;
  }
  .mobile-text-subsection-title {
    margin: 0 0 8px;
    font-size: 14px;
    font-weight: 800;
    color: #ffd27a;
  }
  .mobile-text-table {
    display: grid;
    gap: 8px;
  }
  .mobile-text-table-row {
    display: grid;
    grid-template-columns: minmax(88px, 120px) minmax(0, 1fr);
    gap: 10px;
    align-items: start;
    padding: 10px 12px;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.07);
  }
  .mobile-text-table-label {
    color: #9fc4eb;
    font-size: 13px;
    font-weight: 800;
    line-height: 1.5;
    word-break: keep-all;
  }
  .mobile-text-table-value {
    color: #f4f7fb;
    font-size: 14px;
    line-height: 1.7;
    word-break: keep-all;
    white-space: pre-wrap;
  }
  .mobile-text-grid-table {
    width: 100%;
    overflow: hidden;
    border-radius: 16px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    background: rgba(255, 255, 255, 0.035);
  }
  .mobile-text-grid-row {
    display: grid;
    gap: 0;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
  }
  .mobile-text-grid-row:first-child {
    border-top: 0;
  }
  .mobile-text-grid-cell {
    padding: 10px 12px;
    font-size: 13px;
    line-height: 1.55;
    color: #f4f7fb;
    border-left: 1px solid rgba(255, 255, 255, 0.08);
    word-break: break-word;
  }
  .mobile-text-grid-cell:first-child {
    border-left: 0;
  }
  .mobile-text-grid-row.is-head .mobile-text-grid-cell {
    background: rgba(255, 177, 26, 0.14);
    color: #ffe2a0;
    font-weight: 800;
  }
  .viewer-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 14px;
  }
  .viewer-toolbar-group {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
  }
  .viewer-toolbar select,
  .viewer-toolbar button {
    font: inherit;
  }
  .viewer-page-select {
    min-width: 116px;
    min-height: 42px;
    padding: 0 12px;
    border-radius: 12px;
    border: 1px solid var(--line);
    background: rgba(255, 255, 255, 0.05);
    color: var(--text);
  }
  .viewer-page-indicator {
    color: var(--muted);
    font-size: 13px;
    font-weight: 700;
  }
  .viewer-canvas-wrap {
    position: relative;
    overflow: auto;
    border-radius: 22px;
    background:
      linear-gradient(180deg, rgba(255, 255, 255, 0.035), rgba(255, 255, 255, 0.02)),
      #09101a;
    border: 1px solid rgba(255, 255, 255, 0.08);
    min-height: 58vh;
    padding: 18px;
    touch-action: pan-x pan-y pinch-zoom;
  }
  .viewer-canvas-wrap.is-loading::after {
    content: "페이지를 읽기 좋은 크기로 준비하고 있습니다.";
    position: absolute;
    inset: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 18px;
    border-radius: 18px;
    background: rgba(7, 15, 27, 0.84);
    color: var(--muted);
    text-align: center;
    line-height: 1.6;
  }
  #pdf-canvas {
    display: block;
    margin: 0 auto;
    border-radius: 18px;
    background: #ffffff;
    box-shadow: 0 18px 36px rgba(0, 0, 0, 0.28);
  }
  .viewer-help {
    margin-top: 14px;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.7;
    text-align: center;
  }
  .viewer-embed-wrap {
    display: none;
    overflow: hidden;
    border-radius: 22px;
    background:
      linear-gradient(180deg, rgba(255, 255, 255, 0.035), rgba(255, 255, 255, 0.02)),
      #09101a;
    border: 1px solid rgba(255, 255, 255, 0.08);
    min-height: 78vh;
  }
  .viewer-embed-frame {
    display: block;
    width: 100%;
    height: 78vh;
    border: 0;
    background: #ffffff;
  }
  .mobile-bottom-nav {
    display: none;
    position: fixed;
    left: 12px;
    right: 12px;
    bottom: max(10px, env(safe-area-inset-bottom));
    z-index: 60;
    padding: 8px;
    border: 1px solid rgba(24, 59, 86, 0.10);
    border-radius: 20px;
    background: rgba(255, 255, 255, 0.96);
    backdrop-filter: blur(14px);
    box-shadow: 0 18px 40px rgba(17, 52, 77, 0.18);
  }
  .mobile-bottom-nav-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 6px;
  }
  .mobile-nav-link {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    min-height: 58px;
    border-radius: 14px;
    color: #45627b;
    text-decoration: none;
    font-size: 11px;
    font-weight: 700;
  }
  .mobile-nav-link.is-active {
    background: linear-gradient(180deg, rgba(35, 104, 162, 0.14), rgba(35, 104, 162, 0.08));
    color: #17486f;
  }
  .mobile-nav-icon {
    font-size: 18px;
    line-height: 1;
  }
  @media (max-width: 960px) {
    .info-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }
  @media (min-width: 641px) {
    .reader-shell.can-edit-mobile-msds {
      width: min(100%, 1600px);
    }
    .reader-shell.can-edit-mobile-msds .reader-content-grid {
      grid-template-columns: minmax(360px, 480px) minmax(0, 1fr);
    }
    .reader-shell.can-edit-mobile-msds .mobile-text-reader {
      display: block;
      position: sticky;
      top: 96px;
      max-height: calc(100vh - 120px);
    }
    .reader-shell.can-edit-mobile-msds .mobile-text-head,
    .reader-shell.can-edit-mobile-msds .mobile-glossary-manage,
    .reader-shell.can-edit-mobile-msds .mobile-section-jump,
    .reader-shell.can-edit-mobile-msds .mobile-text-status {
      flex: 0 0 auto;
    }
    .reader-shell.can-edit-mobile-msds .mobile-text-reader {
      display: flex;
      flex-direction: column;
    }
    .reader-shell.can-edit-mobile-msds .mobile-text-body {
      flex: 1 1 auto;
      min-height: 0;
      overflow: auto;
      padding-right: 12px;
    }
    .reader-shell.can-edit-mobile-msds .mobile-glossary-manage .btn {
      width: auto;
      min-width: 160px;
    }
    .mobile-glossary-manage-mobile {
      display: none;
    }
    .mobile-glossary-manage-pc {
      display: inline-flex;
    }
    .viewer-toolbar,
    .viewer-canvas-wrap,
    .viewer-help {
      display: none;
    }
    .viewer-embed-wrap {
      display: block;
    }
  }
  @media (max-width: 640px) {
    body {
      padding-bottom: 112px;
    }
    .reader-topbar,
    .info-card {
      display: none;
    }
    .reader-shell {
      padding: 12px 12px 28px;
    }
    .mobile-text-reader {
      display: block;
    }
    .reader-topbar-inner,
    .info-card,
    .viewer-card,
    .status-card {
      border-radius: 22px;
    }
    .reader-topbar-inner {
      padding: 10px 12px;
      border-radius: 18px;
    }
    .reader-topbar {
      margin-bottom: 10px;
    }
    .reader-title .eyebrow {
      margin-bottom: 2px;
      font-size: 10px;
      letter-spacing: 0.12em;
    }
    .reader-title h1 {
      font-size: 16px;
      line-height: 1.25;
    }
    .reader-actions,
    .reader-actions .btn {
      width: 100%;
    }
    .reader-actions .btn {
      min-height: 38px;
      font-size: 13px;
    }
    .info-card {
      padding: 14px;
    }
    .info-grid {
      grid-template-columns: 1fr;
    }
    .info-item {
      padding: 12px;
    }
    .viewer-card {
      padding: 10px;
    }
    .mobile-text-reader {
      border-radius: 20px;
      margin-bottom: 10px;
    }
    .mobile-text-head-actions {
      display: flex;
    }
    .viewer-card {
      display: none;
    }
    .mobile-text-head {
      padding: 14px 14px 12px;
    }
    .mobile-text-head h2 {
      font-size: 17px;
    }
    .mobile-text-head p,
    .mobile-text-status {
      font-size: 12px;
    }
    .mobile-section-jump {
      display: block;
      top: calc(8px + env(safe-area-inset-top));
      padding: 10px 12px 0;
    }
    .mobile-section-select {
      min-height: 42px;
      font-size: 13px;
    }
    .mobile-text-status {
      padding: 10px 14px 0;
    }
    .mobile-glossary-manage {
      padding: 10px 12px 0;
    }
    .mobile-glossary-manage-mobile {
      display: inline-flex;
    }
    .mobile-glossary-manage-pc,
    .pc-glossary-editor {
      display: none;
    }
    .mobile-text-body {
      padding: 12px;
      gap: 8px;
    }
    .mobile-text-section {
      padding: 12px;
      border-radius: 16px;
    }
    .mobile-card-edit {
      top: 8px;
      right: 8px;
      min-height: 28px;
      padding: 0 9px;
      font-size: 11px;
    }
    .mobile-text-section h3 {
      font-size: 15px;
    }
    .mobile-text-subsection {
      padding: 10px;
    }
    .mobile-text-subsection-title {
      font-size: 13px;
    }
    .mobile-text-table-row {
      grid-template-columns: 92px minmax(0, 1fr);
      gap: 8px;
      padding: 9px 10px;
    }
    .mobile-text-table-label {
      font-size: 12px;
    }
    .mobile-text-table-value,
    .mobile-text-grid-cell {
      font-size: 13px;
    }
    .mobile-text-paragraph {
      font-size: 14px;
      line-height: 1.75;
    }
    .mobile-editor-dialog {
      width: 100%;
      max-height: 86vh;
      padding: 14px;
      border-radius: 20px;
    }
    .mobile-editor-textarea {
      min-height: 44vh;
      font-size: 15px;
    }
    .mobile-editor-actions .btn {
      flex: 1 1 0;
    }
    .mobile-glossary-actions .btn,
    .mobile-glossary-editor-actions .btn {
      flex: 1 1 0;
    }
    .mobile-glossary-dialog,
    .mobile-glossary-editor-dialog {
      border-radius: 22px;
    }
    .viewer-toolbar {
      margin-bottom: 10px;
      align-items: stretch;
    }
    .viewer-toolbar-group {
      width: 100%;
      justify-content: space-between;
    }
    .viewer-toolbar-group .btn,
    .viewer-toolbar-group .viewer-page-select {
      flex: 1 1 calc(50% - 4px);
      min-width: 0;
    }
    .viewer-page-indicator {
      width: 100%;
      text-align: center;
    }
    .viewer-canvas-wrap {
      min-height: 52vh;
      padding: 10px;
    }
    .viewer-help {
      font-size: 12px;
    }
    .mobile-scroll-top {
      display: inline-flex;
      right: 14px;
      bottom: calc(84px + env(safe-area-inset-bottom));
    }
    .mobile-bottom-nav {
      display: block;
    }
  }
</style>
