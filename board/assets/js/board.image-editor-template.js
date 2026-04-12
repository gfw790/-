// ??? ??? ?? ?? ??? ??

(function() {
    const buildImageEditorModalHtml = (textPresetColors, shapePresetColors) => {
        return [
            '<div class="image-edit-dialog" role="dialog" aria-modal="true" aria-label="이미지 편집">',
            '  <div class="image-edit-head">',
            '    <strong>이미지 편집</strong>',
            '    <span class="image-edit-meta"></span>',
            '    <button type="button" class="btn btn-sm" data-action="close">닫기</button>',
            '  </div>',
            '  <div class="image-edit-tools">',
            '    <button type="button" class="tool-btn tool-select-btn" data-action="select" title="선택/이동"' +
            ' aria-label="선택/이동">↖</button>',
            '    <span class="tool-sep"></span>',
            '    <select class="editor-select" data-field="zoom"><option value="25">25%</option><option' +
            ' value="50">50%</option><option value="75">75%</option><option value="100" selected>100%</option' +
            '><option value="125">125%</option><option value="150">150%</option></select>',
            '    <label class="tool-check"><input type="checkbox" data-field="pixel-mode" title="픽셀 크기 조절"></label>',
            '    <label class="tool-input">W <input type="text" inputmode="numeric" data-field="width" disabled' +
            '></label>',
            '    <button type="button" class="tool-btn" data-action="toggle-ratio" title="비율 고정">🔒</button>',
            '    <label class="tool-input">H <input type="text" inputmode="numeric" data-field="height" disabled' +
            '></label>',
            '    <button type="button" class="tool-btn" data-action="crop" title="잘라내기" aria-label="잘라내기"' +
            '><span class="nm-tool-icon nm-tool-icon-crop" aria-hidden="true"></span></button>',
            '    <button type="button" class="tool-btn" data-action="rotate" title="회전" aria-label="회전"' +
            '><span class="nm-tool-icon nm-tool-icon-rotate" aria-hidden="true"></span></button>',
            '    <button type="button" class="tool-btn" data-action="text" title="텍스트 삽입" aria-label="텍스트 삽입"' +
            '><span class="nm-tool-icon nm-tool-icon-text" aria-hidden="true"></span></button>',
            '    <button type="button" class="tool-btn tool-btn-setting" data-action="text-settings"' +
            ' title="텍스트 옵션" aria-label="텍스트 옵션"><span class="nm-tool-icon nm-tool-icon-setting"' +
            ' aria-hidden="true"></span></button>',
            '    <button type="button" class="tool-btn" data-action="shape" title="도형 넣기" aria-label="도형 넣기"' +
            '><span class="nm-tool-icon nm-tool-icon-shape" aria-hidden="true"></span></button>',
            '    <button type="button" class="tool-btn tool-btn-setting" data-action="shape-settings"' +
            ' title="도형 옵션" aria-label="도형 옵션"><span class="nm-tool-icon nm-tool-icon-setting"' +
            ' aria-hidden="true"></span></button>',
            '    <button type="button" class="tool-btn" data-action="draw" title="그리기" aria-label="그리기"' +
            '><span class="nm-tool-icon nm-tool-icon-draw" aria-hidden="true"></span></button>',
            '    <button type="button" class="tool-btn tool-btn-setting" data-action="draw-settings"' +
            ' title="선 옵션" aria-label="선 옵션"><span class="nm-tool-icon nm-tool-icon-setting" aria-hidden="true"' +
            '></span></button>',
            '    <span class="tool-sep"></span>',
            '    <button type="button" class="tool-btn" data-action="undo" title="전단계" aria-label="전단계"' +
            '><span class="nm-tool-icon nm-tool-icon-undo" aria-hidden="true"></span></button>',
            '    <button type="button" class="tool-btn" data-action="redo" title="다음단계" aria-label="다음단계"' +
            '><span class="nm-tool-icon nm-tool-icon-redo" aria-hidden="true"></span></button>',
            '    <button type="button" class="tool-btn" data-action="reset" title="되돌리기" aria-label="되돌리기"' +
            '><span class="nm-tool-icon nm-tool-icon-reset" aria-hidden="true"></span></button>',
            '    <button type="button" class="tool-btn danger" data-action="delete" title="삭제" aria-label="삭제"' +
            '><span class="nm-tool-icon nm-tool-icon-remove" aria-hidden="true"></span></button>',
            '  </div>',
            '  <div class="image-edit-body">',
            '    <div class="image-edit-canvas-stage-wrap">',
            '      <div class="image-edit-canvas-stage">',
            '        <canvas class="image-edit-canvas"></canvas>',
            '        <canvas class="image-edit-overlay" tabindex="-1"></canvas>',
            '      </div>',
            '    </div>',
            '    <button type="button" class="image-crop-action" data-action="apply-crop-selection" hidden></button>',
            '    <div class="image-text-entry" hidden><textarea class="image-text-entry-input"' +
            ' spellcheck="false" wrap="off"></textarea></div>',
            '    <div class="image-text-panel" hidden>',
            '      <section class="image-text-section">',
            '        <div class="image-text-label">글자크기</div>',
            '        <div class="image-text-size-row">',
            '          <input type="text" class="image-text-size-input" data-field="text-size" value="22px">',
            '          <button type="button" class="image-text-size-btn" data-action="text-size-dec"' +
            ' title="글자크기 감소">−</button>',
            '          <input type="range" class="image-text-size-range" data-field="text-size-range"' +
            ' min="10" max="120" step="1" value="22">',
            '          <button type="button" class="image-text-size-btn" data-action="text-size-inc"' +
            ' title="글자크기 증가">+</button>',
            '        </div>',
            '      </section>',
            '      <section class="image-text-section">',
            '        <div class="image-text-label">글자 스타일</div>',
            '        <div class="image-text-style-row">',
            '          <button type="button" class="image-text-style-btn" data-style="bold"' +
            ' data-action="toggle-text-style" title="굵게">B</button>',
            '          <button type="button" class="image-text-style-btn style-italic" data-style="italic"' +
            ' data-action="toggle-text-style" title="기울임">I</button>',
            '          <button type="button" class="image-text-style-btn style-underline"' +
            ' data-style="underline" data-action="toggle-text-style" title="밑줄">U</button>',
            '          <button type="button" class="image-text-style-btn style-strike" data-style="strike"' +
            ' data-action="toggle-text-style" title="취소선">S</button>',
            '        </div>',
            '      </section>',
            '      <section class="image-text-section">',
            '        <div class="image-text-color-head">',
            '          <span class="image-text-label">글자 색상</span>',
            '          <button type="button" class="image-text-more-btn"' +
            ' data-action="open-text-color-picker">더보기 &gt;</button>',
            '        </div>',
            '        <div class="image-text-color-palette">',
            textPresetColors.map((color) =>
                `          <button type="button" class="color-chip" data-action="set-text-color"` +
                ` data-color="${color}" style="--chip:${color};"` +
                ` title="${color}"></button>`
                ).join(''),
            '        </div>',
            '      </section>',
            '    </div>',
            '    <div class="image-shape-panel" hidden>',
            '      <section class="image-shape-section">',
            '        <div class="image-shape-label">도형</div>',
            '        <div class="image-shape-type-row">',
            '          <button type="button" class="image-shape-type-btn" data-action="set-shape-type"' +
            ' data-shape="rect" title="사각형"><span class="shape-type-icon rect" aria-hidden="true"></span' +
            '></button>',
            '          <button type="button" class="image-shape-type-btn" data-action="set-shape-type"' +
            ' data-shape="roundRect" title="둥근 사각형"><span class="shape-type-icon round-rect" aria-hidden="true"' +
            '></span></button>',
            '          <button type="button" class="image-shape-type-btn" data-action="set-shape-type"' +
            ' data-shape="ellipse" title="원형"><span class="shape-type-icon ellipse" aria-hidden="true"></span' +
            '></button>',
            '          <button type="button" class="image-shape-type-btn" data-action="set-shape-type"' +
            ' data-shape="line" title="직선"><span class="shape-type-icon line" aria-hidden="true"></span></button>',
            '          <button type="button" class="image-shape-type-btn" data-action="set-shape-type"' +
            ' data-shape="freeQuad" title="자유 사각형"><span class="shape-type-icon free-quad" aria-hidden="true"' +
            '></span></button>',
            '        </div>',
            '      </section>',
            '      <section class="image-shape-section">',
            '        <div class="image-shape-label">선굵기</div>',
            '        <div class="image-shape-width-row">',
            '          <input type="text" class="image-shape-width-input" data-field="shape-line-width" value="2px">',
            '          <button type="button" class="image-shape-width-btn"' +
            ' data-action="shape-line-width-dec" title="선굵기 감소">−</button>',
            '          <input type="range" class="image-shape-width-range"' +
            ' data-field="shape-line-width-range" min="1" max="30" step="1" value="2">',
            '          <button type="button" class="image-shape-width-btn"' +
            ' data-action="shape-line-width-inc" title="선굵기 증가">+</button>',
            '        </div>',
            '      </section>',
            '      <section class="image-shape-section">',
            '        <div class="image-shape-label">선종류</div>',
            '        <div class="image-shape-line-style-row">',
            '          <button type="button" class="image-shape-line-style-btn"' +
            ' data-action="set-shape-line-style" data-line-style="solid" title="실선"><span' +
            ' class="line-style-sample solid"></span></button>',
            '          <button type="button" class="image-shape-line-style-btn"' +
            ' data-action="set-shape-line-style" data-line-style="dash" title="쇄선"><span' +
            ' class="line-style-sample dash"></span></button>',
            '          <button type="button" class="image-shape-line-style-btn"' +
            ' data-action="set-shape-line-style" data-line-style="dot" title="점선"><span' +
            ' class="line-style-sample dot"></span></button>',
            '        </div>',
            '      </section>',
            '      <section class="image-shape-section">',
            '        <div class="image-shape-color-tab-row">',
            '          <button type="button" class="image-shape-color-tab-btn"' +
            ' data-action="set-shape-color-tab" data-target="stroke">테두리</button>',
            '          <button type="button" class="image-shape-color-tab-btn"' +
            ' data-action="set-shape-color-tab" data-target="fill">채우기</button>',
            '          <button type="button" class="image-shape-color-tab-btn image-shape-more-btn"' +
            ' data-action="open-shape-color-picker">더보기 &gt;</button>',
            '        </div>',
            '        <div class="image-shape-color-palette">',
            shapePresetColors.map((color) => (
                color === 'transparent' ?
                '          <button type="button" class="color-chip transparent"' +
                ' data-action="set-shape-color" data-color="transparent" title="투명"></button>' :
                `          <button type="button" class="color-chip" data-action="set-shape-color"` +
                ` data-color="${color}" style="--chip:${color};"` +
                ` title="${color}"></button>`
            )).join(''),
            '        </div>',
            '        <div class="image-shape-alpha-inline-row">',
            '          <span class="image-shape-alpha-title">투명도</span>',
            '          <input type="range" class="image-shape-alpha-range"' +
            ' data-field="shape-color-alpha-inline" min="0" max="100" step="1" value="100">',
            '          <span class="shape-color-alpha-inline-label">100%</span>',
            '        </div>',
            '      </section>',
            '    </div>',
            '    <div class="image-shape-color-dim" hidden></div>',
            '    <div class="image-shape-color-picker" hidden>',
            '      <div class="image-shape-color-picker-top">',
            '        <span class="image-shape-color-preview"></span>',
            '        <input type="text" class="image-shape-color-hex" data-field="shape-color-hex" value="#ff2d2d">',
            '        <button type="button" class="image-shape-color-apply-btn"' +
            ' data-action="apply-shape-color-hex">입력</button>',
            '        <button type="button" class="image-shape-color-close-btn"' +
            ' data-action="close-shape-color-picker">×</button>',
            '      </div>',
            '      <canvas class="image-shape-color-sv" data-field="shape-color-sv" width="220"' +
            ' height="150"></canvas>',
            '      <canvas class="image-shape-color-hue" data-field="shape-color-hue" width="220" height="12"' +
            '></canvas>',
            '      <div class="image-shape-alpha-row">',
            '        <span class="image-shape-alpha-title">투명도</span>',
            '        <input type="range" class="image-shape-alpha-range" data-field="shape-color-alpha"' +
            ' min="0" max="100" step="1" value="100">',
            '        <span class="shape-color-alpha-label">100%</span>',
            '      </div>',
            '    </div>',
            '    <div class="image-draw-panel" hidden>',
            '      <section class="image-draw-section">',
            '        <div class="image-draw-label">선굵기</div>',
            '        <div class="image-draw-width-row">',
            '          <input type="text" class="image-draw-width-input" data-field="draw-line-width" value="2px">',
            '          <button type="button" class="image-draw-width-btn" data-action="draw-line-width-dec"' +
            ' title="선굵기 감소">−</button>',
            '          <input type="range" class="image-draw-width-range" data-field="draw-line-width-range"' +
            ' min="1" max="30" step="1" value="2">',
            '          <button type="button" class="image-draw-width-btn" data-action="draw-line-width-inc"' +
            ' title="선굵기 증가">+</button>',
            '        </div>',
            '      </section>',
            '      <section class="image-draw-section">',
            '        <div class="image-draw-color-head">',
            '          <span class="image-draw-label">선색상</span>',
            '          <button type="button" class="image-draw-more-btn"' +
            ' data-action="open-draw-color-picker">더보기 &gt;</button>',
            '        </div>',
            '        <div class="image-draw-color-palette">',
            shapePresetColors.filter((color) => color !== 'transparent').map((color) =>
                `          <button type="button" class="color-chip" data-action="set-draw-color"` +
                ` data-color="${color}" style="--chip:${color};"` +
                ` title="${color}"></button>`
                ).join(''),
            '        </div>',
            '        <div class="image-draw-alpha-inline-row">',
            '          <span class="image-draw-alpha-title">투명도</span>',
            '          <input type="range" class="image-draw-alpha-range"' +
            ' data-field="draw-color-alpha-inline" min="0" max="100" step="1" value="100">',
            '          <span class="draw-color-alpha-inline-label">100%</span>',
            '        </div>',
            '      </section>',
            '    </div>',
            '    <div class="image-draw-color-dim" hidden></div>',
            '    <div class="image-draw-color-picker" hidden>',
            '      <div class="image-draw-color-picker-top">',
            '        <span class="image-draw-color-preview"></span>',
            '        <input type="text" class="image-draw-color-hex" data-field="draw-color-hex" value="#ff2d2d">',
            '        <button type="button" class="image-draw-color-apply-btn"' +
            ' data-action="apply-draw-color-hex">입력</button>',
            '        <button type="button" class="image-draw-color-close-btn"' +
            ' data-action="close-draw-color-picker">×</button>',
            '      </div>',
            '      <canvas class="image-draw-color-sv" data-field="draw-color-sv" width="220" height="150"></canvas>',
            '      <canvas class="image-draw-color-hue" data-field="draw-color-hue" width="220" height="12"></canvas>',
            '      <div class="image-draw-alpha-row">',
            '        <span class="image-draw-alpha-title">투명도</span>',
            '        <input type="range" class="image-draw-alpha-range" data-field="draw-color-alpha" min="0"' +
            ' max="100" step="1" value="100">',
            '        <span class="draw-color-alpha-label">100%</span>',
            '      </div>',
            '    </div>',
            '    <div class="image-text-color-dim" hidden></div>',
            '    <div class="image-text-color-picker" hidden>',
            '      <div class="image-text-color-picker-top">',
            '        <span class="image-text-color-preview"></span>',
            '        <input type="text" class="image-text-color-hex" data-field="text-color-hex" value="#ff2d2d">',
            '        <button type="button" class="image-text-color-apply-btn"' +
            ' data-action="apply-text-color-hex">입력</button>',
            '        <button type="button" class="image-text-color-close-btn"' +
            ' data-action="close-text-color-picker">×</button>',
            '      </div>',
            '      <canvas class="image-text-color-sv" data-field="text-color-sv" width="220" height="150"></canvas>',
            '      <canvas class="image-text-color-hue" data-field="text-color-hue" width="220" height="12"></canvas>',
            '    </div>',
            '  </div>',
            '  <div class="image-edit-foot">',
            '    <button type="button" class="btn" data-action="cancel">취소</button>',
            '    <button type="button" class="btn btn-primary" data-action="apply">적용</button>',
            '  </div>',
            '</div>'

        ].join('');
    };

    window.BOARD_buildImageEditorModalHtml = buildImageEditorModalHtml;
})();
