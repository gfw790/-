(function (window, document) {
  'use strict';

  var MEASURE_IMAGE_PLACEHOLDER = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

  function bindEvent(target, eventName, handler) {
    if (!target) {
      return;
    }

    if (target.addEventListener) {
      target.addEventListener(eventName, handler);
      return;
    }

    if (target.attachEvent) {
      target.attachEvent('on' + eventName, handler);
      return;
    }

    target['on' + eventName] = handler;
  }

  function hasClass(element, className) {
    if (!element) {
      return false;
    }

    if (element.classList) {
      return element.classList.contains(className);
    }

    return (' ' + String(element.className || '') + ' ').indexOf(' ' + className + ' ') !== -1;
  }

  function addClass(element, className) {
    if (!element) {
      return;
    }

    if (element.classList) {
      element.classList.add(className);
      return;
    }

    if (!hasClass(element, className)) {
      element.className = String(element.className || '') + ' ' + className;
    }
  }

  function removeClass(element, className) {
    if (!element) {
      return;
    }

    if (element.classList) {
      element.classList.remove(className);
      return;
    }

    element.className = String(element.className || '')
      .replace(new RegExp('(^|\\s)' + className + '(?:\\s|$)', 'g'), ' ')
      .replace(/\s+/g, ' ')
      .replace(/^\s+|\s+$/g, '');
  }

  function emptyElement(element) {
    while (element && element.firstChild) {
      element.removeChild(element.firstChild);
    }
  }

  function prepareMeasurementNode(node) {
    if (!node || node.nodeType !== 1 || !node.querySelectorAll) {
      return node;
    }

    var images = node.querySelectorAll('img');
    Array.prototype.forEach.call(images, function (image) {
      image.setAttribute('src', MEASURE_IMAGE_PLACEHOLDER);
      image.setAttribute('alt', '');
      image.setAttribute('loading', 'eager');
      image.setAttribute('decoding', 'sync');
    });

    return node;
  }

  function nextFrame(callback) {
    if (window.requestAnimationFrame) {
      window.requestAnimationFrame(callback);
      return;
    }

    window.setTimeout(callback, 16);
  }

  function resolveElement(target, root) {
    if (!target) {
      return null;
    }

    if (typeof target === 'string') {
      return (root || document).querySelector(target);
    }

    return target && target.nodeType === 1 ? target : null;
  }

  function eventTargetMatches(target, element) {
    if (!target || !element) {
      return false;
    }

    if (target === element) {
      return true;
    }

    if (target.closest) {
      return target.closest('#' + element.id) === element;
    }

    while (target) {
      if (target === element) {
        return true;
      }
      target = target.parentNode;
    }

    return false;
  }

  function createPrintPreview(options) {
    var settings = options || {};
    var modal = resolveElement(settings.modal);
    var openButton = resolveElement(settings.openButton);
    var closeButton = resolveElement(settings.closeButton, modal || document);
    var printButton = resolveElement(settings.printButton, modal || document);
    var paper = resolveElement(settings.paper, modal || document);

    if (!modal || !openButton || !closeButton || !printButton || !paper) {
      return null;
    }

    var previousBodyOverflow = '';
    var originalMarkup = paper.innerHTML;
    var splitClassNames = settings.splitClassNames || [
      'print-photo-grid',
      'print-chip-list'
    ];
    var pageMetricProperties = [
      '--print-page-width',
      '--print-page-height',
      '--print-margin-top',
      '--print-margin-right',
      '--print-margin-bottom',
      '--print-margin-left',
      '--print-footer-height'
    ];

    function applyPageMetrics(target) {
      if (!target || !window.getComputedStyle) {
        return;
      }

      var computedPaperStyle = window.getComputedStyle(paper);
      pageMetricProperties.forEach(function (propertyName) {
        var value = computedPaperStyle.getPropertyValue(propertyName);
        if (value && String(value).trim() !== '') {
          target.style.setProperty(propertyName, value.trim());
        }
      });
    }

    function hasSplitClass(node) {
      if (!node || node.nodeType !== 1) {
        return false;
      }

      var className = String(node.className || '');
      return splitClassNames.some(function (splitClassName) {
        return className.indexOf(splitClassName) !== -1;
      });
    }

    function createPrintPage() {
      var page = document.createElement('section');
      page.className = 'print-paper-page';
      applyPageMetrics(page);

      var body = document.createElement('div');
      body.className = 'print-paper-page-body';

      var inner = document.createElement('div');
      inner.className = 'print-page-inner';
      body.appendChild(inner);

      var footer = document.createElement('div');
      footer.className = 'print-page-footer';

      page.appendChild(body);
      page.appendChild(footer);

      return {
        page: page,
        inner: inner,
        footer: footer
      };
    }

    function createMeasurementPage() {
      var page = document.createElement('section');
      page.className = 'print-paper-page';
      applyPageMetrics(page);
      page.style.position = 'absolute';
      page.style.left = '-99999px';
      page.style.top = '0';
      page.style.visibility = 'hidden';
      page.style.pointerEvents = 'none';
      page.style.zIndex = '-1';

      var body = document.createElement('div');
      body.className = 'print-paper-page-body';

      var inner = document.createElement('div');
      inner.className = 'print-page-inner';

      body.appendChild(inner);
      page.appendChild(body);
      document.body.appendChild(page);

      return {
        page: page,
        inner: inner
      };
    }

    function buildSectionClone(sourceSection, headingNode) {
      var section = document.createElement('section');
      section.className = sourceSection.className;

      if (headingNode) {
        section.appendChild(headingNode.cloneNode(true));
      }

      return section;
    }

    function appendNodeWithOptionalSplit(renderParent, measureParent, node, allowSplit, overflowsPage) {
      var renderClone = node.cloneNode(true);
      var measureClone = prepareMeasurementNode(node.cloneNode(true));
      renderParent.appendChild(renderClone);
      measureParent.appendChild(measureClone);

      if (!overflowsPage()) {
        return true;
      }

      renderParent.removeChild(renderClone);
      measureParent.removeChild(measureClone);

      if (!allowSplit || !hasSplitClass(node)) {
        return false;
      }

      var renderContainer = node.cloneNode(false);
      var measureContainer = node.cloneNode(false);
      renderParent.appendChild(renderContainer);
      measureParent.appendChild(measureContainer);

      var sourceChildren = Array.prototype.slice.call(node.children || []);
      for (var index = 0; index < sourceChildren.length; index += 1) {
        var child = sourceChildren[index];
        var childRender = child.cloneNode(true);
        var childMeasure = prepareMeasurementNode(child.cloneNode(true));
        renderContainer.appendChild(childRender);
        measureContainer.appendChild(childMeasure);

        if (!overflowsPage()) {
          continue;
        }

        renderContainer.removeChild(childRender);
        measureContainer.removeChild(childMeasure);

        if (renderContainer.children.length === 0) {
          renderParent.removeChild(renderContainer);
          measureParent.removeChild(measureContainer);
          return false;
        }

        return {
          remainderNode: (function () {
            var remainder = node.cloneNode(false);
            for (var restIndex = index; restIndex < sourceChildren.length; restIndex += 1) {
              remainder.appendChild(sourceChildren[restIndex].cloneNode(true));
            }
            return remainder;
          })()
        };
      }

      return true;
    }

    function paginate() {
      if (!originalMarkup) {
        return;
      }

      var operationCount = 0;

      var sourceWrapper = document.createElement('div');
      sourceWrapper.innerHTML = originalMarkup;
      var topLevelBlocks = Array.prototype.slice.call(sourceWrapper.children);
      var measurement = createMeasurementPage();
      var pages = [];
      var currentPage = null;
      var currentMeasureInner = measurement.inner;

      function guardProgress(label) {
        operationCount += 1;
        if (operationCount > 5000) {
          throw new Error('print pagination guard triggered: ' + label);
        }
      }

      function replaceMeasurementInner() {
        var measurementBody = document.createElement('div');
        measurementBody.className = 'print-paper-page-body';
        currentMeasureInner = document.createElement('div');
        currentMeasureInner.className = 'print-page-inner';
        measurementBody.appendChild(currentMeasureInner);
        emptyElement(measurement.page);
        measurement.page.appendChild(measurementBody);
      }

      function syncFooter() {
        pages.forEach(function (entry, index) {
          entry.footer.textContent = String(index + 1) + ' / ' + String(pages.length);
        });
      }

      function startNewPage() {
        guardProgress('start-new-page');

        if (pages.length > 100) {
          throw new Error('print pagination exceeded safe page limit');
        }

        currentPage = createPrintPage();
        pages.push(currentPage);
        replaceMeasurementInner();
      }

      function pageHasContent() {
        return currentPage && currentPage.inner.children.length > 0;
      }

      function appendSyncedNode(renderNode, measureNode) {
        currentPage.inner.appendChild(renderNode);
        currentMeasureInner.appendChild(measureNode);
      }

      function forceAppendSyncedNode(node) {
        appendSyncedNode(
          node.cloneNode(true),
          prepareMeasurementNode(node.cloneNode(true))
        );
      }

      function removeLastSyncedNode() {
        if (currentPage.inner.lastElementChild) {
          currentPage.inner.removeChild(currentPage.inner.lastElementChild);
        }

        if (currentMeasureInner.lastElementChild) {
          currentMeasureInner.removeChild(currentMeasureInner.lastElementChild);
        }
      }

      function overflowsPage() {
        return currentMeasureInner.scrollHeight > currentMeasureInner.clientHeight;
      }

      function appendBlock(block) {
        guardProgress('append-block');

        var renderClone = block.cloneNode(true);
        var measureClone = prepareMeasurementNode(block.cloneNode(true));
        appendSyncedNode(renderClone, measureClone);

        if (!overflowsPage()) {
          return;
        }

        removeLastSyncedNode();
        if (pageHasContent()) {
          startNewPage();
          appendBlock(block);
          return;
        }

        appendSyncedNode(renderClone, measureClone);
      }

      function tryAppendWholeBlock(block) {
        var renderClone = block.cloneNode(true);
        var measureClone = prepareMeasurementNode(block.cloneNode(true));
        appendSyncedNode(renderClone, measureClone);

        if (!overflowsPage()) {
          return true;
        }

        removeLastSyncedNode();
        return false;
      }

      function appendSectionWithSplit(sectionBlock) {
        guardProgress('append-section');

        if (tryAppendWholeBlock(sectionBlock)) {
          return;
        }

        if (pageHasContent()) {
          startNewPage();
          if (tryAppendWholeBlock(sectionBlock)) {
            return;
          }
        }

        var children = Array.prototype.slice.call(sectionBlock.children);
        var heading = children.length > 0 ? children[0] : null;
        var contentChildren = heading ? children.slice(1) : children;
        var sectionRender = null;
        var sectionMeasure = null;

        function ensureSectionOnCurrentPage() {
          guardProgress('ensure-section');

          if (sectionRender && sectionMeasure) {
            return;
          }

          sectionRender = buildSectionClone(sectionBlock, heading);
          sectionMeasure = buildSectionClone(sectionBlock, heading);
          appendSyncedNode(sectionRender, sectionMeasure);

          if (!overflowsPage()) {
            return;
          }

          removeLastSyncedNode();
          if (pageHasContent()) {
            startNewPage();
            sectionRender = buildSectionClone(sectionBlock, heading);
            sectionMeasure = buildSectionClone(sectionBlock, heading);
            appendSyncedNode(sectionRender, sectionMeasure);
          } else {
            appendSyncedNode(sectionRender, sectionMeasure);
          }
        }

        ensureSectionOnCurrentPage();

        contentChildren.forEach(function (child) {
          if (!sectionRender || !sectionMeasure) {
            ensureSectionOnCurrentPage();
          }

          var pendingNode = child;
          var attemptedFreshPage = false;
          var retryCount = 0;
          while (pendingNode) {
            retryCount += 1;
            guardProgress('section-child-loop');
            if (retryCount > 50) {
              throw new Error('print section child retry limit exceeded');
            }

            var appendResult = appendNodeWithOptionalSplit(
              sectionRender,
              sectionMeasure,
              pendingNode,
              true,
              overflowsPage
            );

            if (appendResult === true) {
              pendingNode = null;
              continue;
            }

            var minimumChildren = heading ? 1 : 0;
            var pageHasPriorBlocks = currentPage.inner.children.length > 1;
            if (sectionRender.children.length > minimumChildren || (!attemptedFreshPage && pageHasPriorBlocks)) {
              attemptedFreshPage = true;
              sectionRender = null;
              sectionMeasure = null;
              startNewPage();
              ensureSectionOnCurrentPage();
              pendingNode = appendResult && appendResult.remainderNode ? appendResult.remainderNode : pendingNode;
              continue;
            }

            if (appendResult && appendResult.remainderNode) {
              pendingNode = appendResult.remainderNode;
              startNewPage();
              sectionRender = null;
              sectionMeasure = null;
              ensureSectionOnCurrentPage();
              continue;
            }

            if (appendNodeWithOptionalSplit(sectionRender, sectionMeasure, pendingNode, false, overflowsPage) !== true) {
              forceAppendSyncedNode(pendingNode);
            }
            pendingNode = null;
          }
        });
      }

      startNewPage();

      topLevelBlocks.forEach(function (block) {
        guardProgress('top-level-block');

        if (block.nodeType !== 1) {
          return;
        }

        if (block.dataset && block.dataset.printPageBreak === 'before' && pageHasContent()) {
          startNewPage();
        }

        if (block.tagName === 'SECTION') {
          appendSectionWithSplit(block);
        } else {
          appendBlock(block);
        }
      });

      paper.innerHTML = '';
      pages.forEach(function (entry) {
        paper.appendChild(entry.page);
      });
      syncFooter();

      if (measurement.page.parentNode) {
        measurement.page.parentNode.removeChild(measurement.page);
      }
    }

    function openModal() {
      if (hasClass(modal, 'is-open')) {
        return;
      }

      previousBodyOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
      addClass(document.body, 'print-preview-open');
      addClass(modal, 'is-open');
      modal.setAttribute('aria-hidden', 'false');
      nextFrame(function () {
        try {
          paginate();
        } catch (error) {
          closeModal();
          window.alert('출력 미리보기를 준비하는 중 문제가 발생했습니다. 다시 시도해 주세요.');
          if (window.console && console.error) {
            console.error(error);
          }
        }
      });
    }

    function closeModal() {
      removeClass(modal, 'is-open');
      modal.setAttribute('aria-hidden', 'true');
      removeClass(document.body, 'print-preview-open');
      document.body.style.overflow = previousBodyOverflow;
    }

    function runPrint() {
      try {
        paginate();
        window.print();
      } catch (error) {
        window.alert('인쇄용 페이지를 준비하는 중 문제가 발생했습니다. 다시 시도해 주세요.');
        if (window.console && console.error) {
          console.error(error);
        }
      }
    }

    function handleOpenPointerRelease(event) {
      event = event || window.event;
      if (hasClass(modal, 'is-open')) {
        return;
      }

      if (typeof event.button === 'number' && event.button !== 0) {
        return;
      }

      openModal();
    }

    bindEvent(openButton, 'click', openModal);
    bindEvent(openButton, 'keydown', function (event) {
      event = event || window.event;
      var key = event.key || event.keyCode;
      if (key === 'Enter' || key === ' ' || key === 'Spacebar' || key === 13 || key === 32) {
        if (event.preventDefault) {
          event.preventDefault();
        }
        openModal();
      }
    });
    bindEvent(document, 'click', function (event) {
      event = event || window.event;
      var target = event.target || event.srcElement;
      if (eventTargetMatches(target, openButton)) {
        openModal();
      }
    });
    bindEvent(document, 'mouseup', function (event) {
      event = event || window.event;
      var target = event.target || event.srcElement;
      if (eventTargetMatches(target, openButton)) {
        handleOpenPointerRelease(event);
      }
    });
    bindEvent(document, 'touchend', function (event) {
      event = event || window.event;
      var target = event.target || event.srcElement;
      if (eventTargetMatches(target, openButton)) {
        handleOpenPointerRelease(event);
      }
    });
    bindEvent(closeButton, 'click', closeModal);
    bindEvent(printButton, 'click', runPrint);
    bindEvent(modal, 'click', function (event) {
      event = event || window.event;
      if (event.target === modal) {
        closeModal();
      }
    });
    bindEvent(document, 'keydown', function (event) {
      event = event || window.event;
      var key = event.key || event.keyCode;
      if ((key === 'Escape' || key === 'Esc' || key === 27) && hasClass(modal, 'is-open')) {
        closeModal();
      }
    });
    bindEvent(window, 'beforeprint', function () {
      if (hasClass(modal, 'is-open')) {
        paginate();
      }
    });
    bindEvent(window, 'resize', function () {
      if (hasClass(modal, 'is-open')) {
        paginate();
      }
    });

    return {
      open: openModal,
      close: closeModal,
      print: runPrint,
      refresh: paginate
    };
  }

  window.RiskPrintPreview = {
    init: createPrintPreview
  };
})(window, document);
