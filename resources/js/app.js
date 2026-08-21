const loadImage = (source) => new Promise((resolve) => {
    if (!source) return resolve(null);

    const image = new Image();
    image.crossOrigin = 'anonymous';
    image.onload = () => resolve(image);
    image.onerror = () => resolve(null);
    image.src = source;
});

const extractBackgroundUrl = (node) => {
    const hero = node.querySelector('.adventurer-card-hero');
    const background = hero ? window.getComputedStyle(hero).getPropertyValue('--adventurer-card-bg') : '';
    return background.match(/url\(["']?(.*?)["']?\)/)?.[1] ?? null;
};

const drawContainImage = (context, image, x, y, width, height) => {
    const scale = Math.min(width / image.width, height / image.height);
    const drawWidth = image.width * scale;
    const drawHeight = image.height * scale;
    context.drawImage(image, x + (width - drawWidth) / 2, y + (height - drawHeight) / 2, drawWidth, drawHeight);
};

const drawCoverImage = (context, image, x, y, width, height) => {
    const scale = Math.max(width / image.width, height / image.height);
    const drawWidth = image.width * scale;
    const drawHeight = image.height * scale;
    context.drawImage(image, x + (width - drawWidth) / 2, y + (height - drawHeight) / 2, drawWidth, drawHeight);
};

const roundedRect = (context, x, y, width, height, radius) => {
    const safeRadius = Math.min(radius, width / 2, height / 2);
    context.beginPath();
    context.moveTo(x + safeRadius, y);
    context.arcTo(x + width, y, x + width, y + height, safeRadius);
    context.arcTo(x + width, y + height, x, y + height, safeRadius);
    context.arcTo(x, y + height, x, y, safeRadius);
    context.arcTo(x, y, x + width, y, safeRadius);
    context.closePath();
};

const canvasToBlob = (canvas) => new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));

window.adventurerCardToBlob = async (node, player) => {
    const canvas = document.createElement('canvas');
    canvas.width = 1080;
    canvas.height = 1080;

    const context = canvas.getContext('2d');
    const weapons = (player.favorite_weapons ?? []).slice(0, 3);
    const valmons = (player.valmon_badges ?? []).filter((valmon) => valmon.owned).slice(0, 14);
    const [backgroundImage, avatarImage, ...itemImages] = await Promise.all([
        loadImage(extractBackgroundUrl(node)),
        loadImage(node.querySelector('img[alt="アバター"]')?.currentSrc),
        ...weapons.map((weapon) => loadImage(weapon.image)),
        ...valmons.map((valmon) => loadImage(valmon.image)),
    ]);
    const weaponImages = itemImages.slice(0, weapons.length);
    const valmonImages = itemImages.slice(weapons.length);

    context.fillStyle = '#101b31';
    context.fillRect(0, 0, 1080, 1080);
    if (backgroundImage) {
        context.save();
        context.globalAlpha = 0.62;
        drawCoverImage(context, backgroundImage, 0, 0, 1080, 1080);
        context.restore();
    }
    const overlay = context.createLinearGradient(0, 0, 0, 1080);
    overlay.addColorStop(0, 'rgba(8,18,37,0.98)');
    overlay.addColorStop(0.55, 'rgba(13,30,54,0.78)');
    overlay.addColorStop(1, 'rgba(5,14,29,0.96)');
    context.fillStyle = overlay;
    context.fillRect(0, 0, 1080, 1080);

    context.strokeStyle = '#d9b454';
    context.lineWidth = 8;
    roundedRect(context, 16, 16, 1048, 1048, 28);
    context.stroke();
    context.fillStyle = '#f8e7ad';
    context.font = '700 24px sans-serif';
    context.fillText('VALZERIA ADVENTURER CARD', 58, 62);
    context.textAlign = 'right';
    context.fillStyle = '#ffffff';
    context.font = '700 18px sans-serif';
    context.fillText('#ヴァルゼリアの冒険者', 1022, 62);
    context.textAlign = 'left';

    roundedRect(context, 64, 106, 236, 236, 18);
    context.fillStyle = '#fff3c9';
    context.fill();
    context.strokeStyle = '#f2d579';
    context.lineWidth = 5;
    context.stroke();
    context.save();
    roundedRect(context, 72, 114, 220, 220, 13);
    context.clip();
    if (avatarImage) drawContainImage(context, avatarImage, 80, 122, 204, 204);
    context.restore();

    context.fillStyle = '#ffffff';
    context.font = '700 58px sans-serif';
    context.fillText(player.name ?? '冒険者', 340, 160);
    context.fillStyle = '#d9e7fa';
    context.font = '700 29px sans-serif';
    context.fillText(`Lv.${player.level ?? 1}  /  ${player.job ?? ''}`, 344, 208);
    roundedRect(context, 340, 238, 300, 58, 28);
    context.fillStyle = 'rgba(206,157,43,0.22)';
    context.fill();
    context.strokeStyle = '#f0c654';
    context.lineWidth = 3;
    context.stroke();
    context.fillStyle = '#ffe8a3';
    context.font = '700 24px sans-serif';
    context.fillText(`戦力  ${Number(player.power ?? 0).toLocaleString()}`, 372, 277);
    context.fillStyle = '#f5cf70';
    context.font = '700 23px sans-serif';
    context.fillText(player.equipped_title ?? '', 344, 332);

    roundedRect(context, 64, 376, 952, 118, 16);
    context.fillStyle = 'rgba(5,14,29,0.72)';
    context.fill();
    context.strokeStyle = 'rgba(239,204,105,0.9)';
    context.lineWidth = 2;
    context.stroke();
    context.fillStyle = '#f7db8b';
    context.font = '700 20px sans-serif';
    context.fillText('一言コメント', 94, 414);
    context.fillStyle = '#ffffff';
    context.font = '600 28px sans-serif';
    const comment = String(player.profile_comment ?? 'よろしくお願いします');
    context.fillText(comment.length > 38 ? `${comment.slice(0, 38)}…` : comment, 94, 462);

    roundedRect(context, 64, 530, 952, 430, 18);
    context.fillStyle = 'rgba(7,17,33,0.78)';
    context.fill();
    context.strokeStyle = '#d8b35a';
    context.lineWidth = 2;
    context.stroke();
    context.fillStyle = '#f6e6b6';
    context.font = '700 26px sans-serif';
    context.fillText('お気に入り武器', 94, 578);
    context.fillStyle = '#d7bd67';
    context.font = '600 16px sans-serif';
    context.fillText('WEAPON COLLECTION', 94, 602);

    weapons.forEach((weapon, index) => {
        const x = 110 + index * 308;
        const image = weaponImages[index];
        roundedRect(context, x, 632, 264, 280, 14);
        context.fillStyle = 'rgba(247,250,255,0.96)';
        context.fill();
        context.strokeStyle = weapon.quality?.border_color ?? '#d1b46c';
        context.lineWidth = 4;
        context.stroke();
        if (image) drawContainImage(context, image, x + 28, 650, 208, 154);
        context.textAlign = 'center';
        context.fillStyle = weapon.rank_color ?? '#42556d';
        context.font = '700 25px sans-serif';
        context.fillText(weapon.rank ?? '', x + 132, 832);
        context.fillStyle = '#273a55';
        context.font = '700 17px sans-serif';
        context.fillText(String(weapon.name ?? '').slice(0, 15), x + 132, 864);
        context.fillStyle = '#8b5d12';
        context.font = '700 19px sans-serif';
        context.fillText(`+${weapon.enhance_level ?? 0}`, x + 132, 894);
        context.textAlign = 'left';
    });
    if (weapons.length === 0) {
        context.fillStyle = '#cbd5e1';
        context.font = '700 18px sans-serif';
        context.fillText('お気に入り武器を登録するとここに飾られます', 94, 730);
    }

    roundedRect(context, 64, 980, 952, 60, 16);
    context.fillStyle = 'rgba(7,17,33,0.76)';
    context.fill();
    context.strokeStyle = '#94b27e';
    context.lineWidth = 2;
    context.stroke();
    context.fillStyle = '#d7efbd';
    context.font = '700 18px sans-serif';
    context.fillText(`ヴァルモン  ${valmons.length}/21`, 86, 1017);
    valmons.forEach((valmon, index) => {
        const x = 320 + index * 44;
        const y = 992;
        const image = valmonImages[index];
        context.fillStyle = 'rgba(255,255,255,0.84)';
        context.beginPath();
        context.arc(x, y + 18, 18, 0, Math.PI * 2);
        context.fill();
        if (image) drawContainImage(context, image, x - 16, y + 2, 32, 32);
        if (valmon.is_partner) {
            context.fillStyle = '#d49a17';
            context.font = '700 15px sans-serif';
            context.fillText('★', x + 16, y + 3);
        }
    });
    if (valmons.length === 0) {
        context.fillStyle = '#cbd5e1';
        context.font = '600 17px sans-serif';
        context.fillText('仲間にしたヴァルモンがここに並びます', 320, 1017);
    }
    return canvasToBlob(canvas);
};

const submitLockButtonSelector = 'button[type="submit"], button:not([type]), input[type="submit"]';

const restoreSubmitLock = (form) => {
    form.removeAttribute('aria-busy');
    delete form.dataset.submitLocked;

    form.querySelectorAll('[data-submit-lock-active]').forEach((button) => {
        button.disabled = button.dataset.submitLockWasDisabled === 'true';
        button.removeAttribute('aria-busy');
        button.removeAttribute('data-submit-lock-active');
        button.classList.remove('is-submit-locking');

        if (button instanceof HTMLInputElement) {
            button.value = button.dataset.submitLockOriginalValue ?? button.value;
            delete button.dataset.submitLockOriginalValue;
        } else if (button.dataset.submitLockOriginalHtml !== undefined) {
            button.innerHTML = button.dataset.submitLockOriginalHtml;
            delete button.dataset.submitLockOriginalHtml;
        }

        delete button.dataset.submitLockWasDisabled;
    });
};

const showSubmitLockFeedback = (form, button) => {
    if (!button) return;

    button.dataset.submitLockWasDisabled = button.disabled ? 'true' : 'false';
    button.dataset.submitLockActive = '';
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.classList.add('is-submit-locking');

    const loadingText = button.dataset.loadingText || form.dataset.loadingText || '処理中...';

    if (button instanceof HTMLInputElement) {
        button.dataset.submitLockOriginalValue = button.value;
        button.value = loadingText;
        return;
    }

    button.dataset.submitLockOriginalHtml = button.innerHTML;
    const spinner = document.createElement('span');
    spinner.className = 'submit-lock-spinner';
    spinner.setAttribute('aria-hidden', 'true');

    const label = document.createElement('span');
    label.textContent = loadingText;
    button.replaceChildren(spinner, label);
};

const initializeMultiImagePickers = () => {
    document.querySelectorAll('[data-multi-image-picker]').forEach((picker) => {
        if (picker.dataset.multiImagePickerReady === 'true') return;

        const input = picker.querySelector('[data-multi-image-input]');
        const buttonLabel = picker.querySelector('[data-multi-image-button-label]');
        const feedback = picker.querySelector('[data-multi-image-feedback]');
        const list = picker.querySelector('[data-multi-image-list]');
        const maxFiles = Math.max(1, Number.parseInt(picker.dataset.maxFiles || '4', 10));

        if (!(input instanceof HTMLInputElement) || !feedback || !list) return;

        picker.dataset.multiImagePickerReady = 'true';
        let selectedFiles = Array.from(input.files ?? []).slice(0, maxFiles);

        const fileKey = (file) => [
            file.name,
            file.size,
            file.type,
            file.lastModified,
        ].join(':');

        const syncInputFiles = () => {
            if (typeof DataTransfer !== 'function') return false;

            const transfer = new DataTransfer();
            selectedFiles.forEach((file) => transfer.items.add(file));
            input.files = transfer.files;

            return true;
        };

        const formatFileSize = (size) => {
            if (size < 1024 * 1024) {
                return `${Math.max(1, Math.round(size / 1024))}KB`;
            }

            return `${(size / (1024 * 1024)).toFixed(1)}MB`;
        };

        const render = (message = null, failed = false) => {
            list.replaceChildren();
            list.classList.toggle('hidden', selectedFiles.length === 0);

            selectedFiles.forEach((file, index) => {
                const item = document.createElement('li');
                item.className = 'flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2';

                const summary = document.createElement('div');
                summary.className = 'min-w-0';

                const name = document.createElement('div');
                name.className = 'truncate text-xs font-black text-slate-700';
                name.textContent = file.name;

                const size = document.createElement('div');
                size.className = 'mt-0.5 text-[11px] font-bold text-slate-400';
                size.textContent = formatFileSize(file.size);

                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'shrink-0 rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-[11px] font-black text-slate-600 transition hover:border-rose-300 hover:bg-rose-50 hover:text-rose-700 active:scale-95';
                removeButton.textContent = '外す';
                removeButton.setAttribute('aria-label', `${file.name}を選択から外す`);
                removeButton.addEventListener('click', () => {
                    selectedFiles.splice(index, 1);
                    syncInputFiles();
                    render(`${file.name}を選択から外しました。`);
                });

                summary.append(name, size);
                item.append(summary, removeButton);
                list.append(item);
            });

            if (buttonLabel) {
                buttonLabel.textContent = selectedFiles.length === 0 ? '画像を選択' : '画像を追加';
            }

            feedback.classList.toggle('text-rose-700', failed);
            feedback.classList.toggle('text-slate-500', !failed);
            feedback.textContent = message
                ? `${message} 選択中 ${selectedFiles.length} / ${maxFiles}枚`
                : selectedFiles.length === 0
                    ? `画像は1枚ずつ追加できます。選択中 0 / ${maxFiles}枚`
                    : `続けて画像を追加できます。選択中 ${selectedFiles.length} / ${maxFiles}枚`;
        };

        input.addEventListener('change', () => {
            const incomingFiles = Array.from(input.files ?? []);

            if (typeof DataTransfer !== 'function') {
                selectedFiles = incomingFiles.slice(0, maxFiles);
                render(
                    incomingFiles.length > maxFiles ? `最大${maxFiles}枚です。超過分は追加できませんでした。` : null,
                    incomingFiles.length > maxFiles
                );
                return;
            }

            const selectedKeys = new Set(selectedFiles.map(fileKey));
            let duplicateCount = 0;
            let overflowCount = 0;

            incomingFiles.forEach((file) => {
                const key = fileKey(file);

                if (selectedKeys.has(key)) {
                    duplicateCount += 1;
                    return;
                }

                if (selectedFiles.length >= maxFiles) {
                    overflowCount += 1;
                    return;
                }

                selectedFiles.push(file);
                selectedKeys.add(key);
            });

            syncInputFiles();

            if (overflowCount > 0) {
                render(`最大${maxFiles}枚です。${overflowCount}枚は追加できませんでした。`, true);
            } else if (duplicateCount > 0) {
                render('同じ画像は追加済みです。');
            } else {
                render();
            }
        });

        input.form?.addEventListener('reset', () => {
            window.setTimeout(() => {
                selectedFiles = [];
                render();
            }, 0);
        });

        syncInputFiles();
        render();
    });
};

const initializeCharacterIconDesignAutosave = () => {
    document.querySelectorAll('form[data-character-icon-autosave]').forEach((form) => {
        if (form.dataset.characterIconAutosaveReady === 'true') return;

        form.dataset.characterIconAutosaveReady = 'true';

        const status = form.querySelector('[data-character-icon-autosave-status]');
        const intentField = form.querySelector('[data-character-icon-intent]');
        let timer = null;
        let saving = false;
        let queued = false;
        let pendingSubmitter = null;

        const rememberSubmitIntent = (submitter) => {
            if (
                !intentField
                || !(submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement)
                || submitter.name !== 'intent'
                || !['draft', 'confirm'].includes(submitter.value)
            ) {
                return;
            }

            intentField.value = submitter.value;
        };

        const updateStatus = (message, failed = false) => {
            if (!status) return;

            status.textContent = message;
            status.classList.toggle('text-rose-700', failed);
            status.classList.toggle('text-slate-500', !failed);
        };

        const saveDraft = async () => {
            if (saving) {
                queued = true;
                return;
            }

            saving = true;
            queued = false;
            updateStatus('下書きを保存中...');

            const payload = new FormData(form);
            payload.set('intent', 'draft');

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: payload,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const result = await response.json().catch(() => ({}));

                if (!response.ok || result.success === false) {
                    throw new Error(result.message || '自動保存できませんでした。');
                }

                updateStatus('下書き保存済み');
            } catch (error) {
                updateStatus(
                    error instanceof Error
                        ? `${error.message}「下書きを保存」を押してください。`
                        : '自動保存できませんでした。「下書きを保存」を押してください。',
                    true
                );
            } finally {
                saving = false;

                if (pendingSubmitter !== null) {
                    const submitter = pendingSubmitter;
                    pendingSubmitter = null;

                    if (submitter instanceof HTMLElement) {
                        form.requestSubmit(submitter);
                    } else {
                        form.requestSubmit();
                    }
                    return;
                }

                if (queued) {
                    void saveDraft();
                }
            }
        };

        const queueSave = (delay) => {
            window.clearTimeout(timer);
            queued = true;
            updateStatus('変更内容を自動保存します...');
            timer = window.setTimeout(() => {
                queued = false;
                void saveDraft();
            }, delay);
        };

        form.addEventListener('input', () => queueSave(800));
        form.addEventListener('change', () => queueSave(150));
        form.addEventListener('click', (event) => {
            const submitter = event.target instanceof Element
                ? event.target.closest('[name="intent"]')
                : null;
            rememberSubmitIntent(submitter);
        });
        form.addEventListener('submit', (event) => {
            rememberSubmitIntent(event.submitter);
            window.clearTimeout(timer);
            queued = false;

            if (!saving) return;

            event.preventDefault();
            pendingSubmitter = event.submitter ?? false;
            updateStatus('下書き保存の完了後に進みます...');
        });
    });
};

document.addEventListener('submit', (event) => {
    const form = event.target instanceof HTMLFormElement ? event.target : null;
    if (!form?.matches('form[data-submit-lock]') || event.defaultPrevented) return;

    if (form.dataset.submitLocked === 'true') {
        event.preventDefault();
        event.stopImmediatePropagation();
        return;
    }

    form.dataset.submitLocked = 'true';
    form.setAttribute('aria-busy', 'true');

    const submitter = event.submitter instanceof HTMLElement
        ? event.submitter
        : form.querySelector(submitLockButtonSelector);
    showSubmitLockFeedback(form, submitter);
});

window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-submit-lock]').forEach(restoreSubmitLock);
    initializeMultiImagePickers();
    initializeCharacterIconDesignAutosave();
});

document.addEventListener('DOMContentLoaded', () => {
    initializeMultiImagePickers();
    initializeCharacterIconDesignAutosave();
});
document.addEventListener('livewire:navigated', () => {
    initializeMultiImagePickers();
    initializeCharacterIconDesignAutosave();
});

const closeJobArtTooltips = (except = null) => {
    document.querySelectorAll('.battle-log-job-art-tooltip.is-open').forEach((tooltip) => {
        if (tooltip === except) return;

        tooltip.classList.remove('is-open');
        tooltip.querySelector('.battle-log-job-art-tooltip-trigger')?.setAttribute('aria-expanded', 'false');
    });
};

document.addEventListener('click', (event) => {
    const trigger = event.target instanceof Element
        ? event.target.closest('.battle-log-job-art-tooltip-trigger')
        : null;

    if (!trigger) {
        closeJobArtTooltips();
        return;
    }

    const tooltip = trigger.closest('.battle-log-job-art-tooltip');
    if (!tooltip) return;

    const shouldOpen = !tooltip.classList.contains('is-open');
    closeJobArtTooltips(tooltip);
    tooltip.classList.toggle('is-open', shouldOpen);
    trigger.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;

    closeJobArtTooltips();
});
