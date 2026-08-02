(() => {
  'use strict';

  const $ = (id) => document.getElementById(id);
  const dom = {
    dropZone: $('dropZone'), fileInput: $('fileInput'), editor: $('editor'), newImageButton: $('newImageButton'),
    canvas: $('canvas'), stage: $('stage'), cursorRing: $('cursorRing'), busy: $('busy'), toast: $('toast'),
    tolerance: $('tolerance'), toleranceValue: $('toleranceValue'), feather: $('feather'), featherValue: $('featherValue'),
    brushSize: $('brushSize'), brushSizeValue: $('brushSizeValue'), contiguous: $('contiguous'),
    decontaminate: $('decontaminate'), toolHelp: $('toolHelp'), compareOriginal: $('compareOriginal'),
    undoButton: $('undoButton'), redoButton: $('redoButton'), resetButton: $('resetButton'),
    zoomOutButton: $('zoomOutButton'), zoomInButton: $('zoomInButton'), zoomLabel: $('zoomLabel'), fitButton: $('fitButton'),
    exportFormat: $('exportFormat'), exportScale: $('exportScale'), customSizeRow: $('customSizeRow'),
    exportWidth: $('exportWidth'), exportHeight: $('exportHeight'), downloadButton: $('downloadButton'),
    fileName: $('fileName'), imageSize: $('imageSize'), transparentPercent: $('transparentPercent')
  };
  const ctx = dom.canvas.getContext('2d', { willReadFrequently: true });

  const state = {
    file: null, original: null, pixels: null, width: 0, height: 0,
    tool: 'wand', zoom: 1, offsetX: 0, offsetY: 0,
    undo: [], redo: [], isDrawing: false, isPanning: false,
    startPointer: null, strokeBefore: null, strokeBounds: null, lastBrushPoint: null,
    spacePressed: false, processing: false, toastTimer: null, sourceFileHandle: null
  };

  const toolHelp = {
    wand: '左クリックで背景を選択します。右ドラッグで画像を移動できます。',
    erase: '残った背景をなぞって透明にします。',
    restore: '消しすぎた部分をなぞって元に戻します。',
    pan: 'ドラッグして表示位置を移動します。'
  };

  function toast(message) {
    clearTimeout(state.toastTimer);
    dom.toast.textContent = message;
    dom.toast.classList.add('is-visible');
    state.toastTimer = setTimeout(() => dom.toast.classList.remove('is-visible'), 2600);
  }

  function setBusy(isBusy) {
    state.processing = isBusy;
    dom.busy.hidden = !isBusy;
  }

  async function openPicker() {
    if (state.processing) return;
    if (typeof window.showOpenFilePicker !== 'function') {
      dom.fileInput.click();
      return;
    }
    try {
      const [fileHandle] = await window.showOpenFilePicker({
        multiple: false,
        types: [{
          description: '画像ファイル',
          accept: {
            'image/png': ['.png'],
            'image/jpeg': ['.jpg', '.jpeg'],
            'image/webp': ['.webp'],
            'image/gif': ['.gif']
          }
        }]
      });
      const file = await fileHandle.getFile();
      await loadFile(file, fileHandle);
    } catch (error) {
      if (error.name !== 'AbortError') dom.fileInput.click();
    }
  }
  dom.dropZone.addEventListener('click', openPicker);
  dom.dropZone.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); openPicker(); }
  });
  dom.newImageButton.addEventListener('click', openPicker);
  dom.fileInput.addEventListener('change', () => {
    const file = dom.fileInput.files && dom.fileInput.files[0];
    if (file) loadFile(file, null);
    dom.fileInput.value = '';
  });
  ['dragenter', 'dragover'].forEach((name) => dom.dropZone.addEventListener(name, (event) => {
    event.preventDefault(); dom.dropZone.classList.add('is-dragover');
  }));
  ['dragleave', 'drop'].forEach((name) => dom.dropZone.addEventListener(name, (event) => {
    event.preventDefault(); dom.dropZone.classList.remove('is-dragover');
  }));
  dom.dropZone.addEventListener('drop', (event) => {
    const file = Array.from(event.dataTransfer.files || []).find((item) => item.type.startsWith('image/'));
    if (file) loadFile(file, null);
  });
  document.addEventListener('paste', (event) => {
    const item = Array.from(event.clipboardData?.items || []).find((entry) => entry.type.startsWith('image/'));
    const file = item?.getAsFile();
    if (file) loadFile(file, null);
  });

  async function loadFile(file, fileHandle = null) {
    if (!file.type.startsWith('image/')) { toast('画像ファイルを選択してください。'); return; }
    const objectUrl = URL.createObjectURL(file);
    const image = new Image();
    try {
      await new Promise((resolve, reject) => {
        image.onload = resolve;
        image.onerror = () => reject(new Error('画像を読み込めませんでした。'));
        image.src = objectUrl;
      });
      const width = image.naturalWidth;
      const height = image.naturalHeight;
      if (!width || !height || width * height > 16777216) throw new Error('画像が大きすぎます。4096×4096相当以下を使用してください。');
      dom.canvas.width = width;
      dom.canvas.height = height;
      ctx.clearRect(0, 0, width, height);
      ctx.drawImage(image, 0, 0);
      const loaded = ctx.getImageData(0, 0, width, height);
      state.file = file;
      state.sourceFileHandle = fileHandle;
      state.width = width;
      state.height = height;
      state.original = new ImageData(new Uint8ClampedArray(loaded.data), width, height);
      state.pixels = new ImageData(new Uint8ClampedArray(loaded.data), width, height);
      state.undo = [];
      state.redo = [];
      dom.dropZone.hidden = true;
      dom.editor.hidden = false;
      dom.newImageButton.disabled = false;
      dom.fileName.textContent = file.name || '貼り付け画像';
      dom.imageSize.textContent = `${width} × ${height}px`;
      dom.exportWidth.value = width;
      dom.exportHeight.value = height;
      render();
      requestAnimationFrame(fitToStage);
      updateHistoryButtons();
      updateTransparentPercent();
      toast('画像を読み込みました。白い背景をクリックしてください。');
    } catch (error) {
      toast(error.message || '画像を読み込めませんでした。');
    } finally {
      URL.revokeObjectURL(objectUrl);
    }
  }

  function render() {
    if (!state.pixels) return;
    ctx.putImageData(dom.compareOriginal.checked ? state.original : state.pixels, 0, 0);
    dom.canvas.style.transform = `translate(${state.offsetX}px, ${state.offsetY}px) scale(${state.zoom})`;
    dom.zoomLabel.textContent = `${Math.round(state.zoom * 100)}%`;
  }

  function fitToStage() {
    if (!state.width) return;
    const rect = dom.stage.getBoundingClientRect();
    const padding = 28;
    state.zoom = Math.min((rect.width - padding * 2) / state.width, (rect.height - padding * 2) / state.height, 4);
    state.zoom = Math.max(.05, state.zoom);
    state.offsetX = (rect.width - state.width * state.zoom) / 2;
    state.offsetY = (rect.height - state.height * state.zoom) / 2;
    render();
  }

  function setZoom(nextZoom, clientX, clientY) {
    if (!state.width) return;
    const rect = dom.stage.getBoundingClientRect();
    const anchorX = clientX == null ? rect.width / 2 : clientX - rect.left;
    const anchorY = clientY == null ? rect.height / 2 : clientY - rect.top;
    const imageX = (anchorX - state.offsetX) / state.zoom;
    const imageY = (anchorY - state.offsetY) / state.zoom;
    state.zoom = Math.min(16, Math.max(.05, nextZoom));
    state.offsetX = anchorX - imageX * state.zoom;
    state.offsetY = anchorY - imageY * state.zoom;
    render();
  }

  dom.zoomInButton.addEventListener('click', () => setZoom(state.zoom * 1.25));
  dom.zoomOutButton.addEventListener('click', () => setZoom(state.zoom / 1.25));
  dom.zoomLabel.addEventListener('click', fitToStage);
  dom.fitButton.addEventListener('click', fitToStage);
  dom.stage.addEventListener('wheel', (event) => {
    if (!state.width) return;
    event.preventDefault();
    setZoom(state.zoom * (event.deltaY < 0 ? 1.12 : 1 / 1.12), event.clientX, event.clientY);
  }, { passive: false });

  function canvasPoint(event) {
    const rect = dom.stage.getBoundingClientRect();
    return {
      x: Math.floor((event.clientX - rect.left - state.offsetX) / state.zoom),
      y: Math.floor((event.clientY - rect.top - state.offsetY) / state.zoom)
    };
  }
  function isInside(point) { return point.x >= 0 && point.y >= 0 && point.x < state.width && point.y < state.height; }

  document.querySelectorAll('[data-tool]').forEach((button) => button.addEventListener('click', () => {
    state.tool = button.dataset.tool;
    document.querySelectorAll('[data-tool]').forEach((entry) => entry.classList.toggle('is-active', entry === button));
    dom.toolHelp.textContent = toolHelp[state.tool];
    updateCursor();
  }));

  function updateCursor(event) {
    const brush = state.tool === 'erase' || state.tool === 'restore';
    dom.cursorRing.hidden = !brush || !event;
    dom.canvas.style.cursor = state.tool === 'pan' || state.spacePressed ? 'grab' : brush ? 'none' : 'crosshair';
    if (brush && event) {
      const rect = dom.stage.getBoundingClientRect();
      dom.cursorRing.style.left = `${event.clientX - rect.left}px`;
      dom.cursorRing.style.top = `${event.clientY - rect.top}px`;
      const size = Number(dom.brushSize.value) * state.zoom;
      dom.cursorRing.style.width = `${size}px`;
      dom.cursorRing.style.height = `${size}px`;
    }
  }
  dom.stage.addEventListener('pointermove', updateCursor);
  dom.stage.addEventListener('pointerleave', () => { dom.cursorRing.hidden = true; });
  dom.stage.addEventListener('contextmenu', (event) => event.preventDefault());

  dom.stage.addEventListener('pointerdown', (event) => {
    if (!state.pixels || state.processing || event.button > 2) return;
    const panMode = state.tool === 'pan' || state.spacePressed || event.button === 1 || event.button === 2;
    if (event.button === 2) event.preventDefault();
    dom.stage.setPointerCapture(event.pointerId);
    state.startPointer = { clientX: event.clientX, clientY: event.clientY, offsetX: state.offsetX, offsetY: state.offsetY };
    if (panMode) {
      state.isPanning = true;
      dom.canvas.style.cursor = 'grabbing';
      return;
    }
    const point = canvasPoint(event);
    if (!isInside(point)) return;
    if (state.tool === 'wand') {
      setBusy(true);
      setTimeout(() => {
        try { magicWand(point.x, point.y); } finally { setBusy(false); }
      }, 20);
      return;
    }
    if (state.tool === 'erase' || state.tool === 'restore') {
      state.isDrawing = true;
      state.strokeBefore = new Uint8ClampedArray(state.pixels.data);
      state.strokeBounds = { minX: point.x, minY: point.y, maxX: point.x, maxY: point.y };
      state.lastBrushPoint = point;
      applyBrush(point.x, point.y);
      render();
    }
  });

  dom.stage.addEventListener('pointermove', (event) => {
    if (state.isPanning && state.startPointer) {
      state.offsetX = state.startPointer.offsetX + event.clientX - state.startPointer.clientX;
      state.offsetY = state.startPointer.offsetY + event.clientY - state.startPointer.clientY;
      render();
    } else if (state.isDrawing) {
      const point = canvasPoint(event);
      const previous = state.lastBrushPoint || point;
      const distance = Math.hypot(point.x - previous.x, point.y - previous.y);
      const stepSize = Math.max(1, Number(dom.brushSize.value) * .18);
      const steps = Math.max(1, Math.ceil(distance / stepSize));
      for (let step = 1; step <= steps; step++) {
        const progress = step / steps;
        applyBrush(
          previous.x + (point.x - previous.x) * progress,
          previous.y + (point.y - previous.y) * progress
        );
      }
      state.lastBrushPoint = point;
      render();
    }
  });

  function finishPointer() {
    if (state.isDrawing && state.strokeBefore) commitFromSnapshot(state.strokeBefore, state.strokeBounds);
    state.isDrawing = false;
    state.isPanning = false;
    state.startPointer = null;
    state.strokeBefore = null;
    state.strokeBounds = null;
    state.lastBrushPoint = null;
    updateCursor();
  }
  dom.stage.addEventListener('pointerup', finishPointer);
  dom.stage.addEventListener('pointercancel', finishPointer);

  function colorDistance(r1, g1, b1, r2, g2, b2) {
    const meanR = (r1 + r2) / 2;
    const dr = r1 - r2;
    const dg = g1 - g2;
    const db = b1 - b2;
    return Math.sqrt((2 + meanR / 256) * dr * dr + 4 * dg * dg + (2 + (255 - meanR) / 256) * db * db) / 7.65;
  }

  function magicWand(seedX, seedY) {
    const data = state.pixels.data;
    const seedIndex = (seedY * state.width + seedX) * 4;
    if (data[seedIndex + 3] === 0) { toast('ここはすでに透明です。'); return; }
    const seed = [data[seedIndex], data[seedIndex + 1], data[seedIndex + 2]];
    const tolerance = Number(dom.tolerance.value);
    const softness = Math.max(.5, Number(dom.feather.value) * 1.75);
    const limit = tolerance + softness;
    const count = state.width * state.height;
    const selected = new Uint8Array(count);
    let minX = state.width, minY = state.height, maxX = -1, maxY = -1;

    const matches = (pixel) => {
      const offset = pixel * 4;
      return data[offset + 3] > 0 && colorDistance(data[offset], data[offset + 1], data[offset + 2], seed[0], seed[1], seed[2]) <= limit;
    };

    if (dom.contiguous.checked) {
      const queue = new Int32Array(count);
      let head = 0, tail = 0;
      const start = seedY * state.width + seedX;
      queue[tail++] = start;
      selected[start] = 1;
      while (head < tail) {
        const pixel = queue[head++];
        const x = pixel % state.width;
        const y = (pixel / state.width) | 0;
        if (x < minX) minX = x; if (x > maxX) maxX = x;
        if (y < minY) minY = y; if (y > maxY) maxY = y;
        const neighbors = [x > 0 ? pixel - 1 : -1, x + 1 < state.width ? pixel + 1 : -1, y > 0 ? pixel - state.width : -1, y + 1 < state.height ? pixel + state.width : -1];
        for (const next of neighbors) {
          if (next >= 0 && !selected[next] && matches(next)) { selected[next] = 1; queue[tail++] = next; }
        }
      }
    } else {
      minX = 0; minY = 0; maxX = state.width - 1; maxY = state.height - 1;
      for (let pixel = 0; pixel < count; pixel++) if (matches(pixel)) selected[pixel] = 1;
    }

    if (maxX < 0) return;
    const before = capturePatch(minX, minY, maxX, maxY);
    let changed = 0;
    for (let pixel = 0; pixel < count; pixel++) {
      if (!selected[pixel]) continue;
      const offset = pixel * 4;
      const distance = colorDistance(data[offset], data[offset + 1], data[offset + 2], seed[0], seed[1], seed[2]);
      const oldAlpha = data[offset + 3];
      const remaining = distance <= tolerance ? 0 : Math.min(1, (distance - tolerance) / softness);
      const newAlpha = Math.round(oldAlpha * remaining);
      if (newAlpha >= oldAlpha) continue;
      if (dom.decontaminate.checked && newAlpha > 0) {
        const alphaFraction = Math.max(.08, newAlpha / oldAlpha);
        data[offset] = unmatte(data[offset], seed[0], alphaFraction);
        data[offset + 1] = unmatte(data[offset + 1], seed[1], alphaFraction);
        data[offset + 2] = unmatte(data[offset + 2], seed[2], alphaFraction);
      }
      data[offset + 3] = newAlpha;
      changed++;
    }
    if (!changed) { toast('許容範囲に一致する背景がありません。'); return; }
    const after = capturePatch(minX, minY, maxX, maxY);
    pushHistory({ minX, minY, maxX, maxY, before, after });
    render();
    updateTransparentPercent();
    toast(`${changed.toLocaleString()}ピクセルを透過しました。`);
  }

  function unmatte(value, background, alpha) {
    return Math.max(0, Math.min(255, Math.round((value - background * (1 - alpha)) / alpha)));
  }

  function applyBrush(centerX, centerY) {
    const radius = Number(dom.brushSize.value) / 2;
    const minX = Math.max(0, Math.floor(centerX - radius));
    const maxX = Math.min(state.width - 1, Math.ceil(centerX + radius));
    const minY = Math.max(0, Math.floor(centerY - radius));
    const maxY = Math.min(state.height - 1, Math.ceil(centerY + radius));
    const data = state.pixels.data;
    const original = state.original.data;
    for (let y = minY; y <= maxY; y++) {
      for (let x = minX; x <= maxX; x++) {
        const distance = Math.hypot(x - centerX, y - centerY);
        if (distance > radius) continue;
        const strength = Math.min(1, (radius - distance) / Math.max(1, radius * .22));
        const offset = (y * state.width + x) * 4;
        if (state.tool === 'erase') data[offset + 3] = Math.round(data[offset + 3] * (1 - strength));
        else {
          for (let channel = 0; channel < 4; channel++) data[offset + channel] = Math.round(data[offset + channel] + (original[offset + channel] - data[offset + channel]) * strength);
        }
      }
    }
    state.strokeBounds.minX = Math.min(state.strokeBounds.minX, minX);
    state.strokeBounds.minY = Math.min(state.strokeBounds.minY, minY);
    state.strokeBounds.maxX = Math.max(state.strokeBounds.maxX, maxX);
    state.strokeBounds.maxY = Math.max(state.strokeBounds.maxY, maxY);
  }

  function capturePatch(minX, minY, maxX, maxY, source = state.pixels.data) {
    const width = maxX - minX + 1;
    const height = maxY - minY + 1;
    const patch = new Uint8ClampedArray(width * height * 4);
    for (let y = 0; y < height; y++) {
      const sourceStart = ((minY + y) * state.width + minX) * 4;
      patch.set(source.subarray(sourceStart, sourceStart + width * 4), y * width * 4);
    }
    return patch;
  }

  function commitFromSnapshot(snapshot, bounds) {
    const { minX, minY, maxX, maxY } = bounds;
    const before = capturePatch(minX, minY, maxX, maxY, snapshot);
    const after = capturePatch(minX, minY, maxX, maxY);
    let differs = false;
    for (let i = 0; i < before.length; i++) if (before[i] !== after[i]) { differs = true; break; }
    if (differs) pushHistory({ minX, minY, maxX, maxY, before, after });
    updateTransparentPercent();
  }

  function applyPatch(entry, patch) {
    const width = entry.maxX - entry.minX + 1;
    const height = entry.maxY - entry.minY + 1;
    for (let y = 0; y < height; y++) {
      const targetStart = ((entry.minY + y) * state.width + entry.minX) * 4;
      state.pixels.data.set(patch.subarray(y * width * 4, (y + 1) * width * 4), targetStart);
    }
  }

  function pushHistory(entry) {
    state.undo.push(entry);
    if (state.undo.length > 30) state.undo.shift();
    state.redo = [];
    updateHistoryButtons();
  }
  function updateHistoryButtons() {
    dom.undoButton.disabled = state.undo.length === 0;
    dom.redoButton.disabled = state.redo.length === 0;
  }
  function undo() {
    const entry = state.undo.pop();
    if (!entry) return;
    applyPatch(entry, entry.before);
    state.redo.push(entry);
    render(); updateHistoryButtons(); updateTransparentPercent();
  }
  function redo() {
    const entry = state.redo.pop();
    if (!entry) return;
    applyPatch(entry, entry.after);
    state.undo.push(entry);
    render(); updateHistoryButtons(); updateTransparentPercent();
  }
  dom.undoButton.addEventListener('click', undo);
  dom.redoButton.addEventListener('click', redo);
  dom.resetButton.addEventListener('click', () => {
    if (!state.pixels) return;
    const before = new Uint8ClampedArray(state.pixels.data);
    state.pixels.data.set(state.original.data);
    const after = new Uint8ClampedArray(state.pixels.data);
    pushHistory({ minX: 0, minY: 0, maxX: state.width - 1, maxY: state.height - 1, before, after });
    render(); updateTransparentPercent(); toast('元画像へ戻しました。戻る操作で取り消せます。');
  });

  function updateTransparentPercent() {
    if (!state.pixels) return;
    let transparent = 0;
    const data = state.pixels.data;
    for (let i = 3; i < data.length; i += 4) transparent += 1 - data[i] / 255;
    dom.transparentPercent.textContent = `透明 ${Math.round(transparent / (state.width * state.height) * 100)}%`;
  }

  function bindRange(input, output, formatter) {
    const update = () => { output.textContent = formatter(input.value); updateCursor(); };
    input.addEventListener('input', update); update();
  }
  bindRange(dom.tolerance, dom.toleranceValue, (value) => value);
  bindRange(dom.feather, dom.featherValue, (value) => `${value} px`);
  bindRange(dom.brushSize, dom.brushSizeValue, (value) => `${value} px`);
  dom.compareOriginal.addEventListener('change', render);
  document.querySelectorAll('[data-background]').forEach((button) => button.addEventListener('click', () => {
    document.querySelectorAll('[data-background]').forEach((entry) => entry.classList.toggle('is-active', entry === button));
    dom.stage.classList.remove('checker', 'light', 'dark', 'green');
    dom.stage.classList.add(button.dataset.background);
  }));

  dom.exportScale.addEventListener('change', () => { dom.customSizeRow.hidden = dom.exportScale.value !== 'custom'; });
  dom.exportWidth.addEventListener('input', () => {
    if (document.activeElement === dom.exportWidth && state.width) dom.exportHeight.value = Math.max(1, Math.round(Number(dom.exportWidth.value) * state.height / state.width));
  });
  dom.exportHeight.addEventListener('input', () => {
    if (document.activeElement === dom.exportHeight && state.height) dom.exportWidth.value = Math.max(1, Math.round(Number(dom.exportHeight.value) * state.width / state.height));
  });
  dom.downloadButton.addEventListener('click', () => {
    if (!state.pixels) return;
    let width, height;
    if (dom.exportScale.value === 'custom') {
      width = Math.max(1, Math.min(8192, Number(dom.exportWidth.value) || state.width));
      height = Math.max(1, Math.min(8192, Number(dom.exportHeight.value) || state.height));
    } else {
      const scale = Number(dom.exportScale.value);
      width = Math.max(1, Math.round(state.width * scale));
      height = Math.max(1, Math.round(state.height * scale));
    }
    const output = document.createElement('canvas');
    output.width = width; output.height = height;
    const outputCtx = output.getContext('2d');
    const source = document.createElement('canvas');
    source.width = state.width; source.height = state.height;
    source.getContext('2d').putImageData(state.pixels, 0, 0);
    outputCtx.imageSmoothingEnabled = width !== state.width || height !== state.height;
    outputCtx.imageSmoothingQuality = 'high';
    outputCtx.drawImage(source, 0, 0, width, height);
    const format = dom.exportFormat.value;
    const mime = format === 'webp' ? 'image/webp' : 'image/png';
    output.toBlob(async (blob) => {
      if (!blob) { toast('保存用画像を作成できませんでした。'); return; }
      const originalName = (state.file?.name || 'icon').replace(/\.[^.]+$/, '');
      const suggestedName = `${originalName}.${format}`;
      if (typeof window.showSaveFilePicker === 'function') {
        try {
          const pickerOptions = {
            suggestedName,
            types: [{
              description: format === 'webp' ? 'WebP画像' : 'PNG画像',
              accept: { [mime]: [`.${format}`] }
            }]
          };
          if (state.sourceFileHandle) pickerOptions.startIn = state.sourceFileHandle;
          const saveHandle = await window.showSaveFilePicker(pickerOptions);
          const writable = await saveHandle.createWritable();
          await writable.write(blob);
          await writable.close();
          state.sourceFileHandle = saveHandle;
          toast('透過画像を保存しました。');
          return;
        } catch (error) {
          if (error.name === 'AbortError') return;
          toast('同じ場所へ保存できないため、ダウンロードに切り替えます。');
        }
      }
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = suggestedName;
      anchor.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
      toast('透過画像を保存しました。');
    }, mime, .94);
  });

  document.addEventListener('keydown', (event) => {
    const modifier = event.ctrlKey || event.metaKey;
    if (modifier && event.key.toLowerCase() === 'z') { event.preventDefault(); event.shiftKey ? redo() : undo(); }
    if (modifier && event.key.toLowerCase() === 'y') { event.preventDefault(); redo(); }
    if (event.code === 'Space' && !/INPUT|SELECT|TEXTAREA/.test(event.target.tagName)) { event.preventDefault(); state.spacePressed = true; updateCursor(); }
    if (event.key === '[') { dom.brushSize.value = Math.max(1, Number(dom.brushSize.value) - 3); dom.brushSize.dispatchEvent(new Event('input')); }
    if (event.key === ']') { dom.brushSize.value = Math.min(150, Number(dom.brushSize.value) + 3); dom.brushSize.dispatchEvent(new Event('input')); }
  });
  document.addEventListener('keyup', (event) => {
    if (event.code === 'Space') { state.spacePressed = false; updateCursor(); }
  });
  window.addEventListener('resize', () => { if (state.width && state.zoom <= 1) fitToStage(); });
})();
