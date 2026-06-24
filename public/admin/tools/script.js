// Import the background removal function dynamically only when needed.

document.addEventListener('DOMContentLoaded', () => {
    // Initialize Lucide icons
    refreshIcons();

    // DOM Elements
    const uploadArea = document.getElementById('uploadArea');
    const fileInput = document.getElementById('fileInput');
    const selectFileBtn = document.getElementById('selectFileBtn');

    const workspaceContainer = document.getElementById('workspaceContainer');
    const imageListContainer = document.getElementById('imageListContainer');
    const fileCountDisplay = document.getElementById('fileCountDisplay');
    const overallStatusText = document.getElementById('overallStatusText');
    const globalProgressContainer = document.getElementById('globalProgressContainer');
    const globalProgressBar = document.getElementById('globalProgressBar');

    const addMoreBtn = document.getElementById('addMoreBtn');
    const processAllBtn = document.getElementById('processAllBtn');
    const processBtnContent = document.getElementById('processBtnContent');
    const downloadAllBtn = document.getElementById('downloadAllBtn');
    const resetAppBtn = document.getElementById('resetAppBtn');
    const targetWidthInput = document.getElementById('targetWidthInput');
    const targetHeightInput = document.getElementById('targetHeightInput');
    const keepAspectRatioInput = document.getElementById('keepAspectRatioInput');
    const baseNameInput = document.getElementById('baseNameInput');
    const outputFormatSelect = document.getElementById('outputFormatSelect');
    const qualityControl = document.getElementById('qualityControl');
    const qualityInput = document.getElementById('qualityInput');
    const aiModelSelect = document.getElementById('aiModelSelect');
    const toastContainer = document.getElementById('toastContainer');
    const processModeInputs = Array.from(document.querySelectorAll('input[name="processMode"]'));

// State
    // Format: { id: string, file: File, origUrl: string, resultUrl: string|null, status: 'pending'|'processing'|'success'|'error', errorMsg: string|null, progress: number }
    let queue = [];
    let isProcessing = false;
    let removeBackgroundFn = null;
    let jsZipCtor = null;

    // --- Core Functions ---

    function showToast(message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        let iconName = 'check-circle';
        if (type === 'error') iconName = 'alert-circle';
        else if (type === 'info') iconName = 'info';

        toast.innerHTML = `<i data-lucide="${iconName}" style="width: 20px; height: 20px;"></i> <span>${message}</span>`;
        if(toastContainer) {
            toastContainer.appendChild(toast);
        } else {
            document.body.appendChild(toast); // Fallback
        }
        if(window.lucide) window.lucide.createIcons();

        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    async function convertBlobFormat(blob, format, quality) {
        if (format === 'image/png') return blob;
        const image = await new Promise((resolve, reject) => {
            const img = new Image();
            img.onload = () => resolve(img);
            img.onerror = reject;
            img.src = URL.createObjectURL(blob);
        });
        const canvas = document.createElement('canvas');
        canvas.width = image.naturalWidth;
        canvas.height = image.naturalHeight;
        const ctx = canvas.getContext('2d');
        if (format === 'image/jpeg') {
            ctx.fillStyle = '#FFFFFF';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
        }
        ctx.drawImage(image, 0, 0);
        URL.revokeObjectURL(image.src);
        return new Promise((resolve, reject) => {
            canvas.toBlob(res => {
                if (res) resolve(res);
                else reject(new Error('Format conversion failed'));
            }, format, quality);
        });
    }

    // --- Event Listeners ---

    uploadArea.addEventListener('click', () => fileInput.click());
    selectFileBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        fileInput.click();
    });
    addMoreBtn.addEventListener('click', () => fileInput.click());

    fileInput.addEventListener('change', (e) => {
        if (e.target.files && e.target.files.length > 0) {
            handleFilesSelection(Array.from(e.target.files));
        }
    });

    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.stopPropagation();
        uploadArea.classList.add('dragover');
    });

    uploadArea.addEventListener('dragleave', (e) => {
        e.preventDefault();
        e.stopPropagation();
        uploadArea.classList.remove('dragover');
    });

    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        e.stopPropagation();
        uploadArea.classList.remove('dragover');
        if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
            handleFilesSelection(Array.from(e.dataTransfer.files));
        }
    });

    document.addEventListener('paste', (e) => {
        if (!e.clipboardData) return;
        
        const items = e.clipboardData.items;
        const files = [];
        
        for (let i = 0; i < items.length; i++) {
            if (items[i].type.indexOf('image') !== -1) {
                const blob = items[i].getAsFile();
                if (blob) {
                    files.push(blob);
                }
            }
        }
        
        if (files.length > 0) {
            handleFilesSelection(files);
        }
    });

    processAllBtn.addEventListener('click', () => {
        if (!isProcessing && queue.some(q => q.status === 'pending')) {
            startBatchProcessing();
        }
    });

    downloadAllBtn.addEventListener('click', () => {
        if (!isProcessing) {
            downloadAllResults();
        }
    });

    if (convertAllWebpBtn) {
        convertAllWebpBtn.addEventListener('click', () => {
            if (!isProcessing) {
                convertAllToWebp();
            }
        });
    }

    resetAppBtn.addEventListener('click', () => {
        if (isProcessing) {
            alert('処理中はリセットできません。完了をお待ち下さい。');
            return;
        }
        resetApp();
    });

    processModeInputs.forEach(input => {
        input.addEventListener('change', updateProcessButtonText);
    });

    if (baseNameInput) {
        baseNameInput.addEventListener('input', () => {
            if (!isProcessing && queue.length > 0) {
                renderQueueList();
            }
        });
    }

    if (outputFormatSelect) {
        outputFormatSelect.addEventListener('change', () => {
            if (outputFormatSelect.value === 'png') {
                qualityControl.style.display = 'none';
            } else {
                qualityControl.style.display = 'flex';
            }
        });
    }

    updateProcessButtonText();

    // --- Functions ---

    function handleFilesSelection(files) {
        let addedCount = 0;
        files.forEach(file => {
            if (!file.type.startsWith('image/')) return;

            const itemObj = {
                id: 'id_' + Math.random().toString(36).substr(2, 9),
                file: file,
                origUrl: URL.createObjectURL(file),
                resultUrl: null,
                status: 'pending',
                errorMsg: null,
                progress: 0,
                resultWidth: null,
                resultHeight: null,
                outputName: null,
                resultMode: null,
                isWebp: false
            };
            queue.push(itemObj);
            addedCount++;
        });

        if (addedCount > 0) {
            fileInput.value = ''; // Reset
            updateWorkspaceUI();
        }
    }

    function removeQueueItem(id) {
        if (isProcessing) return; // Prevent removal during processing
        const idx = queue.findIndex(q => q.id === id);
        if (idx !== -1) {
            const item = queue[idx];
            if (item.origUrl) URL.revokeObjectURL(item.origUrl);
            if (item.resultUrl) URL.revokeObjectURL(item.resultUrl);
            queue.splice(idx, 1);

            if (queue.length === 0) {
                resetApp();
            } else {
                updateWorkspaceUI();
            }
        }
    }

    // --- UI Rendering ---

    function updateWorkspaceUI() {
        if (queue.length === 0) {
            workspaceContainer.classList.add('hidden');
            uploadArea.classList.remove('hidden');
            return;
        }

        uploadArea.classList.add('hidden');
        workspaceContainer.classList.remove('hidden');

        fileCountDisplay.textContent = queue.length;

        // Count pending
        const pendingCount = queue.filter(q => q.status === 'pending').length;
        const successCount = queue.filter(q => q.status === 'success' && q.resultUrl).length;
        if (!isProcessing) {
            if (pendingCount > 0) {
                overallStatusText.textContent = `${pendingCount}枚の画像が待機中`;
                processAllBtn.disabled = false;
                addMoreBtn.disabled = false;
            } else {
                overallStatusText.textContent = `すべての画像の処理が完了しました`;
                processAllBtn.disabled = true;
                addMoreBtn.disabled = false;
            }
            downloadAllBtn.classList.toggle('hidden', successCount === 0);
            downloadAllBtn.disabled = successCount === 0;

            
        }

        renderQueueList();
    }

    function renderQueueList() {
        imageListContainer.innerHTML = '';

        queue.forEach(item => {
            const div = document.createElement('div');
            div.className = `queue-item ${item.status}`;
            div.id = `queue_${item.id}`;
            div.draggable = true;

            div.addEventListener('dragstart', function(e) {
                if (isProcessing) {
                    e.preventDefault();
                    return;
                }
                const idx = queue.findIndex(q => 'queue_' + q.id === this.id);
                e.dataTransfer.setData('text/plain', idx);
                this.classList.add('dragging');
            });

            div.addEventListener('dragover', function(e) {
                if (isProcessing) return;
                e.preventDefault();
                this.classList.add('drag-over');
            });

            div.addEventListener('dragleave', function(e) {
                this.classList.remove('drag-over');
            });

            div.addEventListener('drop', function(e) {
                if (isProcessing) return;
                e.preventDefault();
                this.classList.remove('drag-over');
                
                const dragStartIndex = parseInt(e.dataTransfer.getData('text/plain'), 10);
                if (isNaN(dragStartIndex) || dragStartIndex < 0) return;
                
                const dropTargetIndex = queue.findIndex(q => 'queue_' + q.id === this.id);
                if (dropTargetIndex < 0 || dragStartIndex === dropTargetIndex) return;

                const draggedItem = queue.splice(dragStartIndex, 1)[0];
                queue.splice(dropTargetIndex, 0, draggedItem);
                
                renderQueueList();
            });

            div.addEventListener('dragend', function(e) {
                this.classList.remove('dragging');
                const items = document.querySelectorAll('.queue-item');
                items.forEach(it => it.classList.remove('drag-over'));
            });

            // Status Icon & Text
            let statusHtml = '';
            if (item.status === 'pending') {
                statusHtml = `<i data-lucide="clock" style="width: 14px; height: 14px;"></i> 待機中`;
            } else if (item.status === 'processing') {
                statusHtml = `<i data-lucide="loader-2" class="spin" style="width: 14px; height: 14px;"></i> 処理中... ${item.progress}%`;
            } else if (item.status === 'success') {
                const sizeText = item.resultWidth && item.resultHeight ? ` (${item.resultWidth}x${item.resultHeight})` : '';
                const formatText = item.outputExtension ? ` [${item.outputExtension.toUpperCase()}]` : '';
                statusHtml = `<i data-lucide="check-circle-2" style="width: 14px; height: 14px;"></i> 完了${sizeText}${formatText}`;
            } else if (item.status === 'error') {
                statusHtml = `<i data-lucide="alert-circle" style="width: 14px; height: 14px;"></i> エラー`;
            }

            // Image Preview (shows original unless result is ready)
            const imgSrc = item.resultUrl ? item.resultUrl : item.origUrl;
            const bgClass = item.resultUrl && item.resultMode === 'remove' ? 'transparent-bg' : '';
            const imgClass = item.resultUrl ? 'result-img' : '';

            // Actions (Download available if success)
            let actionHtml = '';
            if (item.status === 'success') {
                const itemIndex = queue.indexOf(item);
                const dlName = getDynamicFileName(item, itemIndex);
                actionHtml = `<a href="${item.resultUrl}" download="${dlName}" class="dl-btn"><i data-lucide="download" style="width: 16px; height: 16px;"></i> 保存する</a>`;
                
            }

            // Allow remove if not processing
            if (!isProcessing) {
                actionHtml += `<button class="remove-btn" onclick="document.dispatchEvent(new CustomEvent('removeQueueItem', {detail: '${item.id}'}))"><i data-lucide="trash-2" style="width: 16px; height: 16px;"></i></button>`;
            }

            div.innerHTML = `
                <div class="item-preview">
                    <div class="${bgClass}"></div>
                    <img src="${imgSrc}" class="${imgClass}" alt="${escapeHtml(item.file.name)}">
                </div>
                <div class="item-info">
                    <div class="item-filename" title="${escapeHtml(item.file.name)}">${escapeHtml(item.file.name)}</div>
                    <div class="item-status ${item.status}" id="status_${item.id}">
                        ${statusHtml}
                    </div>
                    ${item.errorMsg ? `<div class="error-text" style="color:var(--danger); font-size:12px; margin-bottom:10px;">${escapeHtml(item.errorMsg)}</div>` : ''}
                    <div class="item-actions">
                        ${actionHtml}
                    </div>
                </div>
            `;
            imageListContainer.appendChild(div);
        });

        refreshIcons();
    }

    // Allow remove function to be called from inline HTML
    document.addEventListener('removeQueueItem', (e) => {
        removeQueueItem(e.detail);
    });

    

    // --- Batch Processing Engine --- //

    async function startBatchProcessing() {
        isProcessing = true;

        // Update global UI
        processAllBtn.disabled = true;
        addMoreBtn.disabled = true;
        downloadAllBtn.disabled = true;
        targetWidthInput.disabled = true;
        targetHeightInput.disabled = true;
        keepAspectRatioInput.disabled = true;
        processModeInputs.forEach(input => input.disabled = true);
        processAllBtn.classList.add('processing');
        globalProgressContainer.classList.remove('hidden');
        globalProgressBar.style.width = '0%';

        const pendingItems = queue.filter(q => q.status === 'pending');
        const totalToProcess = pendingItems.length;
        let processedCount = 0;
        const resizeOptions = getResizeOptions();
        const processMode = getProcessMode();

        try {
            // Lazy load AI Engine on first run
            if (processMode === 'remove' && !removeBackgroundFn) {
                overallStatusText.innerHTML = `<i data-lucide="loader-2" class="spin" style="width: 16px; height: 16px; vertical-align: middle;"></i> AIモデルの読込と初期化を行っています... (初回のみ)`;
                processBtnContent.innerHTML = 'AIモデル読込中...';
                refreshIcons();

                const imglyMod = await import("https://esm.sh/@imgly/background-removal@1.7.0");
                removeBackgroundFn = imglyMod.default || imglyMod.removeBackground;
            }

            processBtnContent.innerHTML = processMode === 'remove' ? '複数画像を透過中...' : '複数画像をリサイズ中...';

            // Process one by one sequentially (prevent browser out-of-memory)
            for (let i = 0; i < queue.length; i++) {
                if (queue[i].status !== 'pending') continue;

                // Update status to processing
                queue[i].status = 'processing';
                queue[i].progress = 0;
                overallStatusText.innerHTML = `${processMode === 'remove' ? '透過処理中' : 'リサイズ中'}... (${processedCount + 1} / ${totalToProcess}枚目)`;
                updateWorkspaceUI(); // Refresh UI

                const domStatus = document.getElementById(`status_${queue[i].id}`);

                try {
                    const config = {
                        progress: (key, current, total) => {
                            if (total > 0) {
                                const percentage = Math.round((current / total) * 100);
                                queue[i].progress = percentage;
                                if (domStatus) {
                                    domStatus.innerHTML = `<i data-lucide="loader-2" class="spin" style="width: 14px; height: 14px;"></i> 処理中... ${percentage}%`;
                                    refreshIcons();
                                }
                            }
                        }
                    };

                    const resizedImage = await resizeImageForProcessing(queue[i].file, resizeOptions, processMode === 'resize');
                    let resultBlob;

                    if (processMode === 'remove') {
                        const processingUrl = URL.createObjectURL(resizedImage.blob);

                        try {
                            const aiModelSelect = document.getElementById('aiModelSelect');
                            if (aiModelSelect) {
                                config.model = aiModelSelect.value;
                            }
                            resultBlob = await removeBackgroundFn(processingUrl, config);
                        } finally {
                            URL.revokeObjectURL(processingUrl);
                        }
                    } else {
                        resultBlob = resizedImage.blob;
                    }

                    // Format conversion
                    if (domStatus) {
                        domStatus.innerHTML = `<i data-lucide="loader-2" class="spin" style="width: 14px; height: 14px;"></i> フォーマット変換中...`;
                        refreshIcons();
                    }
                    const outputFormatSelect = document.getElementById('outputFormatSelect');
                    const formatValue = outputFormatSelect ? outputFormatSelect.value : 'png';
                    const mimeType = `image/${formatValue}`;
                    const qualityInput = document.getElementById('qualityInput');
                    const quality = qualityInput ? (parseInt(qualityInput.value, 10) / 100) : 0.8;
                    resultBlob = await convertBlobFormat(resultBlob, mimeType, quality);
                    queue[i].outputExtension = formatValue === 'jpeg' ? 'jpg' : formatValue;

                    queue[i].status = 'success';
                    queue[i].resultUrl = URL.createObjectURL(resultBlob);
                    queue[i].progress = 100;
                    queue[i].resultWidth = resizedImage.width;
                    queue[i].resultHeight = resizedImage.height;
                    queue[i].resultMode = processMode;

                } catch (err) {
                    console.error("Item processing failed:", err);
                    queue[i].status = 'error';
                    queue[i].errorMsg = err.message || '処理中にエラーが発生しました';
                }

                processedCount++;
                const globalPct = (processedCount / totalToProcess) * 100;
                globalProgressBar.style.width = `${globalPct}%`;

                updateWorkspaceUI(); // Refresh UI after each completion
            }

        } catch (globalErr) {
            console.error("Global processing error:", globalErr);
            alert("AIエンジンの読み込み、または全体処理中に重大なエラーが発生しました。\n" + globalErr.message);
        } finally {
            // Complete
            isProcessing = false;
            processAllBtn.classList.remove('processing');
            processBtnContent.innerHTML = '';
            globalProgressContainer.classList.add('hidden');
            targetWidthInput.disabled = false;
            targetHeightInput.disabled = false;
            keepAspectRatioInput.disabled = false;
            processModeInputs.forEach(input => input.disabled = false);
            updateProcessButtonText();
            updateWorkspaceUI();
        }
    }

    function resetApp() {
        // Cleanup all memory
        queue.forEach(item => {
            if (item.origUrl) URL.revokeObjectURL(item.origUrl);
            if (item.resultUrl) URL.revokeObjectURL(item.resultUrl);
        });
        queue = [];
        fileInput.value = '';
        updateWorkspaceUI();
    }

    function getResizeOptions() {
        return {
            width: parsePositiveNumber(targetWidthInput.value),
            height: parsePositiveNumber(targetHeightInput.value),
            keepAspectRatio: keepAspectRatioInput.checked
        };
    }

    function getProcessMode() {
        const checked = processModeInputs.find(input => input.checked);
        return checked ? checked.value : 'remove';
    }

    function updateProcessButtonText() {
        if (isProcessing) return;
        processBtnContent.textContent = getProcessMode() === 'remove' ? '全画像を透過する' : '全画像をリサイズする';
    }

    function parsePositiveNumber(value) {
        const parsed = Math.floor(Number(value));
        return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
    }

    async function resizeImageForProcessing(file, options, forcePngOutput = false) {
        const image = await loadImage(file);
        const targetSize = calculateTargetSize(image.naturalWidth, image.naturalHeight, options);

        if (!forcePngOutput && targetSize.width === image.naturalWidth && targetSize.height === image.naturalHeight) {
            return {
                blob: file,
                width: image.naturalWidth,
                height: image.naturalHeight
            };
        }

        const canvas = document.createElement('canvas');
        canvas.width = targetSize.width;
        canvas.height = targetSize.height;

        const ctx = canvas.getContext('2d');
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(image, 0, 0, targetSize.width, targetSize.height);

        const blob = await new Promise((resolve, reject) => {
            canvas.toBlob(result => {
                if (result) {
                    resolve(result);
                } else {
                    reject(new Error('画像のリサイズに失敗しました'));
                }
            }, 'image/png');
        });

        return {
            blob,
            width: targetSize.width,
            height: targetSize.height
        };
    }

    function calculateTargetSize(originalWidth, originalHeight, options) {
        const requestedWidth = options.width;
        const requestedHeight = options.height;

        if (!requestedWidth && !requestedHeight) {
            return { width: originalWidth, height: originalHeight };
        }

        if (options.keepAspectRatio) {
            let scale = 1;
            if (requestedWidth && requestedHeight) {
                scale = Math.min(requestedWidth / originalWidth, requestedHeight / originalHeight);
            } else if (requestedWidth) {
                scale = requestedWidth / originalWidth;
            } else if (requestedHeight) {
                scale = requestedHeight / originalHeight;
            }
            return {
                width: Math.max(1, Math.round(originalWidth * scale)),
                height: Math.max(1, Math.round(originalHeight * scale))
            };
        } else {
            return {
                width: requestedWidth || originalWidth,
                height: requestedHeight || originalHeight
            };
        }
    }

    function loadImage(file) {
        return new Promise((resolve, reject) => {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = () => {
                URL.revokeObjectURL(url);
                resolve(img);
            };
            img.onerror = () => {
                URL.revokeObjectURL(url);
                reject(new Error('画像を読み込めませんでした'));
            };
            img.src = url;
        });
    }

    async function downloadAllResults() {
        const successItems = queue.filter(item => item.status === 'success' && item.resultUrl);
        if (successItems.length === 0) return;

        const originalText = downloadAllBtn.innerHTML;
        downloadAllBtn.disabled = true;
        downloadAllBtn.innerHTML = `<i data-lucide="loader-2" class="spin" style="width: 16px; height: 16px;"></i> ZIP作成中...`;
        refreshIcons();

        try {
            if (!jsZipCtor) {
                const jsZipModule = await import('https://esm.sh/jszip@3.10.1');
                jsZipCtor = jsZipModule.default;
            }

            const zip = new jsZipCtor();
            const usedNames = new Set();

            for (const item of successItems) {
                const response = await fetch(item.resultUrl);
                const blob = await response.blob();
                const itemIndex = queue.indexOf(item);
                const name = makeUniqueFileName(getDynamicFileName(item, itemIndex), usedNames);
                zip.file(name, blob);
            }

            const zipBlob = await zip.generateAsync({ type: 'blob' });
            const zipUrl = URL.createObjectURL(zipBlob);
            const link = document.createElement('a');
            link.href = zipUrl;
            link.download = `${getZipNamePrefix(successItems)}_images_${formatDateStamp(new Date())}.zip`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(zipUrl);
        } catch (err) {
            console.error('ZIP download failed:', err);
            alert('一括ダウンロード用ZIPの作成に失敗しました。\n' + (err.message || err));
        } finally {
            downloadAllBtn.innerHTML = originalText;
            downloadAllBtn.disabled = false;
            refreshIcons();
        }
    }

    

    function getDynamicFileName(item, index) {
        const customBase = baseNameInput ? baseNameInput.value.trim() : '';
        const ext = item.outputExtension ? `.${item.outputExtension}` : '.png';

        if (customBase) {
            const num = String(index + 1).padStart(2, '0');
            return `${customBase}${num}${ext}`;
        }

        const dotIndex = item.file.name.lastIndexOf('.');
        const origBaseName = dotIndex > 0 ? item.file.name.slice(0, dotIndex) : item.file.name;
        const sizePart = item.resultWidth && item.resultHeight ? `_${item.resultWidth}x${item.resultHeight}` : '';
        const prefix = item.resultMode === 'resize' ? 'resized' : 'removed';
        return `${prefix}_${origBaseName}${sizePart}${ext}`;
    }

    function getZipNamePrefix(items) {
        const hasRemoved = items.some(item => item.resultMode === 'remove');
        const hasResized = items.some(item => item.resultMode === 'resize');
        if (hasRemoved && hasResized) return 'processed';
        return hasResized ? 'resized' : 'removed';
    }

    function makeUniqueFileName(name, usedNames) {
        if (!usedNames.has(name)) {
            usedNames.add(name);
            return name;
        }

        const dotIndex = name.lastIndexOf('.');
        const baseName = dotIndex > 0 ? name.slice(0, dotIndex) : name;
        const extension = dotIndex > 0 ? name.slice(dotIndex) : '';
        let counter = 2;
        let nextName = `${baseName}_${counter}${extension}`;

        while (usedNames.has(nextName)) {
            counter++;
            nextName = `${baseName}_${counter}${extension}`;
        }

        usedNames.add(nextName);
        return nextName;
    }

    function formatDateStamp(date) {
        const pad = value => String(value).padStart(2, '0');
        return `${date.getFullYear()}${pad(date.getMonth() + 1)}${pad(date.getDate())}_${pad(date.getHours())}${pad(date.getMinutes())}`;
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, char => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[char]));
    }

    function refreshIcons() {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    }
});
