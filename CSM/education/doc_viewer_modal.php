<?php
// doc_viewer_modal.php - Common Document Viewer Modal for Scans, Certificates, and Attached Evidence with Zoom & Pan
?>
<!-- MODAL: DOCUMENT VIEWER (SCAN / CERTIFICATE / EVIDENCE) -->
<div id="csm-doc-viewer-modal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(15,23,42,0.8); backdrop-filter:blur(3px); z-index:99999; align-items:center; justify-content:center; padding:12px; box-sizing:border-box;" onclick="if(event.target === this) closeDocViewer()">
  <div style="background:#ffffff; border-radius:16px; width:100%; max-width:1180px; height:94vh; display:flex; flex-direction:column; box-shadow:0 25px 50px -12px rgba(0,0,0,0.35); overflow:hidden; border:1px solid #cbd5e1; box-sizing:border-box;">
    <!-- Header -->
    <div style="padding:10px 18px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; flex-shrink:0; flex-wrap:wrap; gap:8px;">
      <div style="display:flex; align-items:center; gap:10px; overflow:hidden; max-width:460px;">
        <span id="csm-doc-modal-icon" style="font-size:20px; line-height:1;">📄</span>
        <div style="overflow:hidden;">
          <h3 id="csm-doc-modal-title" style="margin:0; font-size:14px; font-weight:700; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">증빙 문서 미리보기</h3>
          <p id="csm-doc-modal-sub" style="margin:2px 0 0 0; font-size:11px; color:#64748b; font-family:monospace; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"></p>
        </div>
      </div>

      <!-- Controls & Actions -->
      <div style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
        <!-- Zoom & Pan Controls (for Images) -->
        <div id="csm-doc-zoom-toolbar" style="display:none; align-items:center; background:#ffffff; border:1px solid #cbd5e1; border-radius:8px; padding:2px 4px; box-shadow:0 1px 2px rgba(0,0,0,0.05); gap:2px;">
          <button type="button" onclick="zoomDoc(-0.25)" style="width:28px; height:28px; border:none; background:transparent; border-radius:6px; cursor:pointer; font-weight:bold; font-size:16px; color:#334155; display:flex; align-items:center; justify-content:center;" title="축소 (마우스 휠 아래)">−</button>
          <button type="button" id="csm-doc-zoom-pct" onclick="resetDocZoom()" style="min-width:52px; height:28px; border:none; background:transparent; border-radius:6px; cursor:pointer; font-size:11px; font-weight:700; color:#0f172a; padding:0 4px;" title="클릭 시 100% 원본 크기">100%</button>
          <button type="button" onclick="zoomDoc(0.25)" style="width:28px; height:28px; border:none; background:transparent; border-radius:6px; cursor:pointer; font-weight:bold; font-size:16px; color:#334155; display:flex; align-items:center; justify-content:center;" title="확대 (마우스 휠 위)">+</button>
          <span style="width:1px; height:16px; background:#e2e8f0; margin:0 3px;"></span>
          <button type="button" onclick="fitDocZoom()" style="padding:0 8px; height:28px; border:none; background:transparent; border-radius:6px; cursor:pointer; font-size:11px; font-weight:600; color:#334155;" title="화면에 맞추고 가운데 정렬">화면맞춤</button>
          <button type="button" onclick="rotateDoc()" style="padding:0 8px; height:28px; border:none; background:transparent; border-radius:6px; cursor:pointer; font-size:11px; font-weight:600; color:#334155; display:inline-flex; align-items:center; gap:2px;" title="90도 회전">⟳ 회전</button>
        </div>

        <a id="csm-doc-modal-newtab" href="#" target="_blank" rel="noopener" style="padding:6px 12px; background:#ffffff; color:#334155; border:1px solid #cbd5e1; border-radius:8px; font-size:12px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:4px; box-shadow:0 1px 2px rgba(0,0,0,0.05);" title="새 탭에서 원본 열기">
          새 창에서 열기 ↗
        </a>
        <button type="button" onclick="closeDocViewer()" style="width:32px; height:32px; border:none; background:transparent; color:#64748b; border-radius:8px; cursor:pointer; font-size:18px; display:flex; align-items:center; justify-content:center; transition:background 0.2s;" onmouseover="this.style.background='#e2e8f0'; this.style.color='#0f172a'" onmouseout="this.style.background='transparent'; this.style.color='#64748b'" title="닫기 (ESC)">
          ✕
        </button>
      </div>
    </div>

    <!-- Content Body -->
    <div id="csm-doc-modal-body" style="flex:1; background:#0f172a15; position:relative; overflow:hidden; display:flex; align-items:center; justify-content:center; padding:0; box-sizing:border-box;">
    </div>

    <!-- Bottom Guide Bar (for image pan/zoom tips) -->
    <div id="csm-doc-bottom-bar" style="display:none; padding:7px 18px; background:#f8fafc; border-top:1px solid #e2e8f0; font-size:11px; color:#475569; justify-content:space-between; align-items:center; flex-shrink:0;">
      <span>✋ <strong>화면 이동(Pan):</strong> 마우스로 사진을 클릭한 채 드래그하면 서명란으로 자유롭게 이동합니다. &bull; 🔍 <strong>휠 스크롤:</strong> 확대/축소 &bull; <strong>더블클릭:</strong> 2배 확대 / 화면맞춤 전환</span>
      <span style="font-size:10px; color:#94a3b8; font-weight:600;">ESC 키: 닫기</span>
    </div>
  </div>
</div>

<script>
let docScale = 1.0;
let docRotation = 0;
let panX = 0;
let panY = 0;
let isPanningDoc = false;
let panStartX = 0;
let panStartY = 0;

function openDocViewer(url, title, filename) {
  const modal = document.getElementById('csm-doc-viewer-modal');
  if (!modal) return;
  document.getElementById('csm-doc-modal-title').textContent = title || '증빙 문서 미리보기';
  document.getElementById('csm-doc-modal-sub').textContent = filename || url.split('/').pop().split('?')[0];
  document.getElementById('csm-doc-modal-newtab').href = url;
  
  const icon = document.getElementById('csm-doc-modal-icon');
  const clean = (filename || url).toLowerCase();
  if (clean.includes('이수증') || clean.includes('cert')) {
    icon.textContent = '🏅';
  } else {
    icon.textContent = '📄';
  }

  const body = document.getElementById('csm-doc-modal-body');
  const zoomToolbar = document.getElementById('csm-doc-zoom-toolbar');
  const bottomBar = document.getElementById('csm-doc-bottom-bar');
  body.innerHTML = '';

  const isPdf = clean.includes('.pdf');
  if (isPdf) {
    if (zoomToolbar) zoomToolbar.style.display = 'none';
    if (bottomBar) bottomBar.style.display = 'none';

    const iframe = document.createElement('iframe');
    iframe.src = url;
    iframe.style.width = '100%';
    iframe.style.height = '100%';
    iframe.style.border = 'none';
    iframe.style.background = '#ffffff';
    iframe.title = title;
    body.appendChild(iframe);
  } else {
    if (zoomToolbar) zoomToolbar.style.display = 'inline-flex';
    if (bottomBar) bottomBar.style.display = 'flex';

    docRotation = 0;
    docScale = 1.0;
    panX = 0;
    panY = 0;

    const wrap = document.createElement('div');
    wrap.id = 'csm-doc-scroll-wrap';
    wrap.style.cssText = 'width:100%; height:100%; overflow:hidden; display:flex; align-items:center; justify-content:center; position:relative; cursor:grab; user-select:none; touch-action:none;';

    const img = document.createElement('img');
    img.id = 'csm-doc-img';
    img.src = url;
    img.alt = title || '스캔본 미리보기';
    img.draggable = false;
    img.style.cssText = 'box-shadow:0 8px 30px rgba(0,0,0,0.22); border-radius:6px; background:#ffffff; user-select:none; pointer-events:auto; transform-origin:center center; will-change:transform;';
    
    img.onload = function() {
      fitDocZoom();
    };

    wrap.appendChild(img);
    body.appendChild(wrap);

    // Mouse Drag (Pan)
    wrap.addEventListener('mousedown', function(e) {
      if (e.button !== 0) return; // Left click only
      e.preventDefault();
      isPanningDoc = true;
      wrap.style.cursor = 'grabbing';
      panStartX = e.clientX - panX;
      panStartY = e.clientY - panY;
    });

    // Double click to toggle zoom
    wrap.addEventListener('dblclick', function(e) {
      e.preventDefault();
      if (docScale > 1.3) {
        fitDocZoom();
      } else {
        docScale = 2.0;
        panX = 0;
        panY = 0;
        applyDocZoom();
      }
    });

    // Mouse Wheel Zoom
    wrap.addEventListener('wheel', function(e) {
      e.preventDefault();
      const delta = e.deltaY < 0 ? 0.2 : -0.2;
      zoomDoc(delta);
    }, { passive: false });

    // Touch events for mobile/tablet
    wrap.addEventListener('touchstart', function(e) {
      if (e.touches.length === 1) {
        isPanningDoc = true;
        panStartX = e.touches[0].clientX - panX;
        panStartY = e.touches[0].clientY - panY;
      }
    }, { passive: true });
  }

  modal.style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

// Global Mouse Move and Up for continuous smooth drag even outside wrap
window.addEventListener('mousemove', function(e) {
  if (!isPanningDoc) return;
  e.preventDefault();
  panX = e.clientX - panStartX;
  panY = e.clientY - panStartY;
  applyDocZoom();
});

window.addEventListener('mouseup', function() {
  if (isPanningDoc) {
    isPanningDoc = false;
    const wrap = document.getElementById('csm-doc-scroll-wrap');
    if (wrap) wrap.style.cursor = 'grab';
  }
});

window.addEventListener('touchmove', function(e) {
  if (!isPanningDoc || e.touches.length !== 1) return;
  panX = e.touches[0].clientX - panStartX;
  panY = e.touches[0].clientY - panStartY;
  applyDocZoom();
}, { passive: true });

window.addEventListener('touchend', function() {
  isPanningDoc = false;
});

function zoomDoc(delta) {
  docScale = Math.min(Math.max(0.2, docScale + delta), 4.5);
  applyDocZoom();
}

function resetDocZoom() {
  docScale = 1.0;
  panX = 0;
  panY = 0;
  applyDocZoom();
}

function fitDocZoom() {
  const wrap = document.getElementById('csm-doc-scroll-wrap');
  const img = document.getElementById('csm-doc-img');
  if (!wrap || !img) return;

  const natW = img.naturalWidth || 800;
  const natH = img.naturalHeight || 1100;
  const availW = wrap.clientWidth - 40;
  const availH = wrap.clientHeight - 40;

  if (availW > 0 && availH > 0) {
    const scaleW = availW / natW;
    const scaleH = availH / natH;
    docScale = Math.min(scaleW, scaleH, 1.0);
  } else {
    docScale = 0.8;
  }
  panX = 0;
  panY = 0;
  applyDocZoom();
}

function rotateDoc() {
  docRotation = (docRotation + 90) % 360;
  applyDocZoom();
}

function applyDocZoom() {
  const img = document.getElementById('csm-doc-img');
  const pct = document.getElementById('csm-doc-zoom-pct');
  if (!img) return;

  const natW = img.naturalWidth || 800;
  const natH = img.naturalHeight || 1100;
  const targetW = Math.round(natW * docScale);
  const targetH = Math.round(natH * docScale);

  img.style.width = targetW + 'px';
  img.style.height = targetH + 'px';
  img.style.transform = `translate(${panX}px, ${panY}px) rotate(${docRotation}deg)`;

  if (pct) pct.textContent = Math.round(docScale * 100) + '%';
}

function openDocLink(el) {
  const url = el.getAttribute('href');
  const title = el.getAttribute('data-doc-title') || el.getAttribute('title') || '증빙 문서 미리보기';
  const name = decodeURIComponent(el.getAttribute('data-doc-name') || '');
  openDocViewer(url, title, name);
}

function closeDocViewer() {
  const modal = document.getElementById('csm-doc-viewer-modal');
  if (!modal) return;
  modal.style.display = 'none';
  const body = document.getElementById('csm-doc-modal-body');
  if (body) body.innerHTML = '';
  document.body.style.overflow = '';
}

if (!window._csmDocViewerEscBound) {
  window._csmDocViewerEscBound = true;
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeDocViewer();
  });
}
</script>
