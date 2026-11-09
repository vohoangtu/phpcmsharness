// WP-Hexagon Admin JavaScript
document.addEventListener('DOMContentLoaded', () => {
    initializeProductMediaManager();
    initializeMediaLibraryPage();

    const titleInput = document.getElementById('post-title');
    const slugInput = document.getElementById('post-slug');

    if (titleInput && slugInput) {
        let slugManuallyEdited = slugInput.value.trim() !== '';
        slugInput.addEventListener('input', () => {
            slugManuallyEdited = slugInput.value.trim() !== '';
        });

        titleInput.addEventListener('input', () => {
            if (!slugManuallyEdited) {
                slugInput.value = vietnameseSlugify(titleInput.value);
            }
        });
    }
});

function initializeMediaLibraryPage() {
    const fileInput = document.getElementById('media-upload-file');
    const fileName = document.querySelector('[data-upload-file-name]');
    const dropzone = document.querySelector('.media-upload-dropzone');
    const form = document.querySelector('[data-media-upload-form]');
    const previews = document.querySelector('[data-media-upload-previews]');
    const status = document.querySelector('[data-media-upload-status]');
    const objectUrls = [];

    const renderPreviews = () => {
        if (!previews || !fileInput) return;
        objectUrls.forEach(url => URL.revokeObjectURL(url));
        objectUrls.length = 0;
        previews.replaceChildren();
        const files = [...(fileInput.files || [])];
        if (fileName) fileName.textContent = files.length ? `${files.length} ảnh đã chọn` : 'Chọn ảnh hoặc kéo thả vào đây';
        files.forEach(file => {
            const tile = document.createElement('div');
            tile.className = 'media-upload-preview';
            const image = document.createElement('img');
            const url = URL.createObjectURL(file);
            objectUrls.push(url);
            image.src = url;
            image.alt = file.name;
            const caption = document.createElement('span');
            caption.textContent = file.name;
            tile.append(image, caption);
            previews.append(tile);
        });
    };

    fileInput?.addEventListener('change', () => {
        renderPreviews();
    });
    dropzone?.addEventListener('dragover', event => {
        if ([...event.dataTransfer.types].includes('Files')) {
            event.preventDefault();
            dropzone.classList.add('is-drag-over');
        }
    });
    dropzone?.addEventListener('dragleave', event => {
        if (!dropzone.contains(event.relatedTarget)) dropzone.classList.remove('is-drag-over');
    });
    dropzone?.addEventListener('drop', event => {
        event.preventDefault();
        dropzone.classList.remove('is-drag-over');
        if (!fileInput || !event.dataTransfer.files.length) return;
        fileInput.files = event.dataTransfer.files;
        fileInput.dispatchEvent(new Event('change', { bubbles: true }));
    });
    form?.addEventListener('submit', async event => {
        const files = [...(fileInput?.files || [])];
        if (!files.length) return;
        event.preventDefault();
        const submit = form.querySelector('[type="submit"]');
        const csrf = form.querySelector('input[name="_csrf_token"]')?.value || '';
        const altText = form.querySelector('[name="alt_text"]')?.value || '';
        let uploaded = 0;
        const failedFiles = [];
        if (submit) submit.disabled = true;
        for (const file of files) {
            if (status) status.textContent = `Đang tải ${uploaded + 1}/${files.length}: ${file.name}`;
            const body = new FormData();
            body.append('_csrf_token', csrf);
            body.append('file', file);
            body.append('alt_text', altText || file.name.replace(/\.[^.]+$/, ''));
            try {
                const response = await fetch('/admin/media/upload-json', { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.error || 'Không thể tải ảnh lên.');
                uploaded += 1;
            } catch (error) {
                failedFiles.push(file);
                if (status) status.textContent = `${uploaded} ảnh đã tải lên. ${file.name}: ${error instanceof Error ? error.message : 'Tải lên thất bại.'}`;
            }
        }
        if (uploaded === files.length) {
            if (status) status.textContent = `Đã tải lên thành công ${uploaded} ảnh. Đang cập nhật thư viện…`;
            window.setTimeout(() => window.location.reload(), 650);
        } else if (submit) {
            if (fileInput && typeof DataTransfer !== 'undefined') {
                const retryFiles = new DataTransfer();
                failedFiles.forEach(file => retryFiles.items.add(file));
                fileInput.files = retryFiles.files;
                renderPreviews();
            }
            submit.disabled = false;
        }
    });
    document.querySelectorAll('[data-copy-media-url]').forEach(button => {
        button.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(button.dataset.copyMediaUrl);
                const previous = button.textContent;
                button.textContent = 'Đã sao chép';
                window.setTimeout(() => { button.textContent = previous; }, 1300);
            } catch {
                const input = button.closest('.media-library-url')?.querySelector('input');
                input?.select();
                document.execCommand('copy');
            }
        });
    });

    const selected = () => [...document.querySelectorAll('[data-media-select]:checked')];
    const selectedCount = document.querySelector('[data-media-selected-count]');
    const bulkDelete = document.querySelector('[data-media-bulk-delete]');
    const bulkIds = document.querySelector('[data-media-bulk-ids]');
    const moveIds = document.querySelector('[data-media-move-ids]');
    const moveButton = document.querySelector('[data-media-move]');
    const selectAll = document.querySelector('[data-media-select-all]');
    const syncSelection = () => {
        const items = selected();
        if (selectedCount) selectedCount.textContent = items.length ? `Đã chọn ${items.length} ảnh` : 'Chưa chọn ảnh';
        if (bulkDelete) bulkDelete.disabled = items.length === 0;
        if (moveButton) moveButton.disabled = items.length === 0;
        if (bulkIds) {
            bulkIds.replaceChildren();
            items.forEach(item => {
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = 'media_ids[]'; input.value = item.value;
                bulkIds.append(input);
            });
        }
        if (moveIds) {
            moveIds.replaceChildren();
            items.forEach(item => {
                const input = document.createElement('input'); input.type = 'hidden'; input.name = 'media_ids[]'; input.value = item.value; moveIds.append(input);
            });
        }
        if (selectAll) selectAll.checked = document.querySelectorAll('[data-media-select]').length > 0 && items.length === document.querySelectorAll('[data-media-select]').length;
    };
    document.querySelectorAll('[data-media-select]').forEach(input => input.addEventListener('change', syncSelection));
    selectAll?.addEventListener('change', () => {
        document.querySelectorAll('[data-media-select]').forEach(input => { input.checked = selectAll.checked; });
        syncSelection();
    });
    document.querySelector('[data-media-bulk-form]')?.addEventListener('submit', event => {
        if (!selected().length || !window.confirm(`Xóa ${selected().length} ảnh đã chọn? Các nội dung đang dùng ảnh có thể không còn hiển thị ảnh.`)) event.preventDefault();
    });
    document.querySelector('[data-media-move-form]')?.addEventListener('submit', event => {
        if (!selected().length) event.preventDefault();
    });

    const grid = document.querySelector('.wp-media-grid');
    document.querySelectorAll('[data-media-view]').forEach(button => button.addEventListener('click', () => {
        grid?.classList.toggle('is-list-view', button.dataset.mediaView === 'list');
        document.querySelectorAll('[data-media-view]').forEach(view => view.classList.toggle('is-active', view === button));
    }));

    const previewDialog = document.querySelector('[data-media-preview-dialog]');
    document.querySelectorAll('[data-media-preview]').forEach(button => button.addEventListener('click', () => {
        const image = previewDialog?.querySelector('[data-media-preview-image]');
        if (image) { image.src = button.dataset.mediaPreview; image.alt = button.dataset.mediaAlt || ''; }
        previewDialog?.querySelector('[data-media-preview-name]')?.replaceChildren(document.createTextNode(button.dataset.mediaName || ''));
        previewDialog?.querySelector('[data-media-preview-meta]')?.replaceChildren(document.createTextNode(`${button.dataset.mediaDimensions} · ${button.dataset.mediaSize}`));
        previewDialog?.querySelector('[data-media-preview-alt]')?.replaceChildren(document.createTextNode(button.dataset.mediaAlt || 'Chưa có văn bản thay thế'));
        previewDialog?.showModal();
    }));
    previewDialog?.querySelector('[data-media-preview-close]')?.addEventListener('click', () => previewDialog.close());

    const editDialog = document.querySelector('[data-media-edit-dialog]');
    const canvas = editDialog?.querySelector('[data-edit-canvas]');
    const context = canvas?.getContext('2d');
    const cropX = editDialog?.querySelector('[data-crop-x]');
    const cropY = editDialog?.querySelector('[data-crop-y]');
    const cropWidth = editDialog?.querySelector('[data-crop-width]');
    const cropHeight = editDialog?.querySelector('[data-crop-height]');
    const rotate = editDialog?.querySelector('[data-rotate]');
    const cropRatio = editDialog?.querySelector('[data-crop-ratio]');
    const outputWidth = editDialog?.querySelector('[data-output-width]');
    const outputFormat = editDialog?.querySelector('[data-output-format]');
    const outputQuality = editDialog?.querySelector('[data-output-quality]');
    const qualityValue = editDialog?.querySelector('[data-quality-value]');
    let sourceImage = null;
    let sourceName = '';
    let sourceAlt = '';
    const renderEditedPreview = () => {
        if (!sourceImage || !canvas || !context) return;
        const x = Math.max(0, Number(cropX.value) || 0);
        const y = Math.max(0, Number(cropY.value) || 0);
        const w = Math.min(sourceImage.naturalWidth - x, Math.max(1, Number(cropWidth.value) || sourceImage.naturalWidth));
        const h = Math.min(sourceImage.naturalHeight - y, Math.max(1, Number(cropHeight.value) || sourceImage.naturalHeight));
        const angle = (Number(rotate.value) || 0) * Math.PI / 180;
        const scale = Math.min(1, 900 / Math.max(w, h));
        const sw = Math.max(1, Math.round(w * scale));
        const sh = Math.max(1, Math.round(h * scale));
        const sideways = Math.abs(Number(rotate.value) % 180) === 90;
        canvas.width = sideways ? sh : sw;
        canvas.height = sideways ? sw : sh;
        context.clearRect(0, 0, canvas.width, canvas.height);
        context.save();
        context.translate(canvas.width / 2, canvas.height / 2);
        context.rotate(angle);
        context.drawImage(sourceImage, x, y, w, h, -sw / 2, -sh / 2, sw, sh);
        context.restore();
    };
    document.querySelectorAll('[data-edit-image]').forEach(button => button.addEventListener('click', () => {
        sourceName = button.dataset.editName || 'image';
        sourceAlt = button.dataset.editAlt || '';
        sourceImage = new Image();
        sourceImage.onload = () => {
            cropX.value = '0'; cropY.value = '0'; cropWidth.value = String(sourceImage.naturalWidth); cropHeight.value = String(sourceImage.naturalHeight); rotate.value = '0';
            renderEditedPreview();
            editDialog.showModal();
        };
        sourceImage.src = button.dataset.editImage;
    }));
    [cropX, cropY, cropWidth, cropHeight, rotate].forEach(input => input?.addEventListener('input', renderEditedPreview));
    cropRatio?.addEventListener('change', () => {
        if (!sourceImage || cropRatio.value === 'free') return;
        const ratio = Number(cropRatio.value);
        let width = Math.max(1, Number(cropWidth.value) || sourceImage.naturalWidth);
        let height = Math.max(1, Number(cropHeight.value) || sourceImage.naturalHeight);
        if (width / height > ratio) width = Math.round(height * ratio);
        else height = Math.round(width / ratio);
        cropWidth.value = String(width);
        cropHeight.value = String(height);
        cropX.value = String(Math.max(0, Math.round((sourceImage.naturalWidth - width) / 2)));
        cropY.value = String(Math.max(0, Math.round((sourceImage.naturalHeight - height) / 2)));
        renderEditedPreview();
    });
    outputQuality?.addEventListener('input', () => { if (qualityValue) qualityValue.value = `${outputQuality.value}%`; });
    editDialog?.querySelector('[data-edit-reset]')?.addEventListener('click', () => {
        if (!sourceImage) return;
        cropX.value = '0'; cropY.value = '0'; cropWidth.value = String(sourceImage.naturalWidth); cropHeight.value = String(sourceImage.naturalHeight); rotate.value = '0'; renderEditedPreview();
    });
    editDialog?.querySelector('[data-edit-save]')?.addEventListener('click', async event => {
        if (!sourceImage || !canvas) return;
        const button = event.currentTarget;
        const status = editDialog.querySelector('[data-edit-status]');
        button.disabled = true;
        status.textContent = 'Đang xử lý ảnh…';
        try {
            const maxWidth = Math.max(100, Math.min(4000, Number(outputWidth.value) || 1600));
            const scale = Math.min(1, maxWidth / canvas.width);
            const output = document.createElement('canvas');
            output.width = Math.max(1, Math.round(canvas.width * scale));
            output.height = Math.max(1, Math.round(canvas.height * scale));
            output.getContext('2d').drawImage(canvas, 0, 0, output.width, output.height);
            const format = outputFormat.value || 'image/webp';
            const blob = await new Promise(resolve => output.toBlob(resolve, format, (Number(outputQuality.value) || 86) / 100));
            if (!blob) throw new Error('Không thể tạo ảnh mới.');
            const actualType = blob.type || format;
            const extension = actualType === 'image/webp' ? 'webp' : 'jpg';
            const body = new FormData();
            body.append('_csrf_token', form?.querySelector('input[name="_csrf_token"]')?.value || document.querySelector('input[name="_csrf_token"]')?.value || '');
            body.append('file', blob, `${sourceName.replace(/\.[^.]+$/, '')}-edited.${extension}`);
            body.append('alt_text', sourceAlt);
            const response = await fetch('/admin/media/upload-json', { method: 'POST', body });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || 'Không thể lưu ảnh mới.');
            status.textContent = 'Đã lưu ảnh mới, đang cập nhật thư viện…';
            window.setTimeout(() => window.location.reload(), 650);
        } catch (error) {
            status.textContent = error instanceof Error ? error.message : 'Không thể xử lý ảnh.';
            button.disabled = false;
        }
    });
}

function initializePostContentEditor() {
    const visual = document.getElementById('visual-editor');
    const raw = document.getElementById('raw-content');
    if (!visual || !raw) return;

    if (typeof Sanitizer !== 'function' || typeof visual.setHTML !== 'function') {
        raw.style.display = 'block';
        return;
    }

    visual.setHTML(raw.value, { sanitizer: createEditorSanitizer() });
    raw.value = visual.innerHTML;
    raw.style.display = 'none';
    visual.style.display = 'block';
}

function vietnameseSlugify(str) {
    str = str.toLowerCase().trim();
    str = str.replace(/[àáạảãâầấậẩẫăằắặẳẵ]/g, 'a');
    str = str.replace(/[èéẹẻẽêềếệểễ]/g, 'e');
    str = str.replace(/[ìíịỉĩ]/g, 'i');
    str = str.replace(/[òóọỏõôồốộổỗơờớợởỡ]/g, 'o');
    str = str.replace(/[ùúụủũưừứựửữ]/g, 'u');
    str = str.replace(/[ỳýỵỷỹ]/g, 'y');
    str = str.replace(/đ/g, 'd');
    str = str.replace(/[^a-z0-9\s-]/g, '');
    str = str.replace(/[\s-]+/g, '-');
    return str.replace(/^-+|-+$/g, '');
}

function formatDoc(cmd, value = null) {
    const visual = document.getElementById('visual-editor');
    if (!visual) return;
    visual.focus();
    document.execCommand(cmd, false, value);
    syncEditorContent();
}

function insertLinkPrompt() {
    const url = prompt('Nhập địa chỉ liên kết (URL):', 'https://');
    if (url) {
        formatDoc('createLink', url);
    }
}

function syncEditorContent() {
    const visual = document.getElementById('visual-editor');
    const raw = document.getElementById('raw-content');
    if (visual && raw && visual.style.display !== 'none') {
        raw.value = visual.innerHTML;
    }
}

function createEditorSanitizer() {
    return new Sanitizer({
        elements: [
            'a', 'b', 'blockquote', 'br', 'em', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'i', 'li', 'ol', 'p', 'strong', 'u', 'ul'
        ],
        attributes: ['href', 'title']
    });
}

function toggleSourceMode() {
    const visual = document.getElementById('visual-editor');
    const raw = document.getElementById('raw-content');
    if (!visual || !raw) return;

    if (raw.style.display === 'none') {
        raw.value = visual.innerHTML;
        visual.style.display = 'none';
        raw.style.display = 'block';
    } else {
        if (typeof Sanitizer !== 'function' || typeof visual.setHTML !== 'function') {
            alert('Trình duyệt này chưa hỗ trợ làm sạch HTML an toàn. Hãy dùng chế độ mã HTML để chỉnh sửa nội dung.');
            return;
        }

        visual.setHTML(raw.value, { sanitizer: createEditorSanitizer() });
        raw.style.display = 'none';
        visual.style.display = 'block';
    }
}

function selectFeaturedImage(url) {
    const input = document.getElementById('featured-image-input');
    if (input) {
        input.value = url;
        previewFeaturedUrl(url);
    }
}

function previewFeaturedUrl(url) {
    const img = document.getElementById('featured-preview-img');
    if (!img) return;
    const empty = document.querySelector('.product-featured-empty');
    const clear = document.querySelector('[data-clear-featured]');
    if (url && url.trim() !== '') {
        img.src = url.trim();
        img.style.display = 'block';
        if (empty) empty.style.display = 'none';
        if (clear) clear.hidden = false;
    } else {
        img.style.display = 'none';
        if (empty) empty.style.display = 'grid';
        if (clear) clear.hidden = true;
    }
}

function initializeProductMediaManager() {
    const form = document.querySelector('.product-editor-form');
    const galleryValue = document.getElementById('product-gallery-images');
    const galleryGrid = document.getElementById('product-gallery-grid');
    const featuredValue = document.getElementById('featured-image-input');
    if (!form || !galleryValue || !galleryGrid || !featuredValue) return;

    const csrfToken = form.querySelector('input[name="_csrf_token"]')?.value || '';
    const markMediaUsed = ids => {
        if (!ids.length) return;
        const body = new FormData();
        body.append('_csrf_token', csrfToken);
        ids.forEach(id => body.append('media_ids[]', String(id)));
        fetch('/admin/media/mark-used', { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest' } }).catch(() => {});
    };
    const statusFor = target => document.querySelector(`[data-product-image-status="${target}"]`);
    let galleryItems = [...new Set(galleryValue.value.split(/[\n,]+/).map(value => value.trim()).filter(Boolean))]
        .map(url => ({ url, alt: '' }));
    let draggedIndex = null;
    let pendingUploads = 0;

    const syncGalleryValue = () => {
        galleryValue.value = galleryItems.map(item => item.url).join('\n');
    };

    const renderGallery = () => {
        galleryGrid.replaceChildren();
        galleryItems.forEach((item, index) => {
            const card = document.createElement('article');
            card.className = 'product-gallery-item';
            card.draggable = true;
            card.dataset.galleryIndex = String(index);

            const image = document.createElement('img');
            image.src = item.url;
            image.alt = item.alt || `Ảnh sản phẩm ${index + 1}`;
            image.loading = 'lazy';
            image.addEventListener('error', () => card.classList.add('is-image-error'), { once: true });

            const name = document.createElement('span');
            name.className = 'product-gallery-item-name';
            name.textContent = item.alt || item.url.split('/').pop() || item.url;
            name.title = item.url;

            const actions = document.createElement('div');
            actions.className = 'product-gallery-item-actions';
            [
                ['up', '↑', 'Di chuyển ảnh lên'],
                ['down', '↓', 'Di chuyển ảnh xuống'],
                ['remove', 'Gỡ', 'Gỡ ảnh khỏi album'],
            ].forEach(([action, label, title]) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = action === 'remove' ? 'product-gallery-remove' : 'product-gallery-move';
                button.dataset.galleryAction = action;
                button.dataset.galleryIndex = String(index);
                button.textContent = label;
                button.title = title;
                button.setAttribute('aria-label', `${title}: ${name.textContent}`);
                if ((action === 'up' && index === 0) || (action === 'down' && index === galleryItems.length - 1)) button.disabled = true;
                actions.append(button);
            });

            card.append(image, name, actions);
            galleryGrid.append(card);
        });
        galleryGrid.classList.toggle('is-empty', galleryItems.length === 0);
        if (galleryItems.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'product-gallery-empty';
            empty.textContent = 'Chưa có ảnh phụ. Tải ảnh lên hoặc chọn từ Media Library.';
            galleryGrid.append(empty);
        }
        syncGalleryValue();
    };

    const addGalleryItem = item => {
        if (!item?.url || galleryItems.some(existing => existing.url === item.url)) return;
        galleryItems.push({ url: item.url, alt: item.alt || item.name || '' });
        renderGallery();
    };

    const updateFeatured = (url, alt = '') => {
        featuredValue.value = url;
        previewFeaturedUrl(url);
        const image = document.getElementById('featured-preview-img');
        if (image && alt) image.alt = alt;
    };

    galleryGrid.addEventListener('click', event => {
        const button = event.target.closest('[data-gallery-action]');
        if (!button) return;
        const index = Number(button.dataset.galleryIndex);
        const action = button.dataset.galleryAction;
        if (!Number.isInteger(index) || !galleryItems[index]) return;
        if (action === 'remove') galleryItems.splice(index, 1);
        if (action === 'up' && index > 0) [galleryItems[index - 1], galleryItems[index]] = [galleryItems[index], galleryItems[index - 1]];
        if (action === 'down' && index < galleryItems.length - 1) [galleryItems[index + 1], galleryItems[index]] = [galleryItems[index], galleryItems[index + 1]];
        renderGallery();
    });

    galleryGrid.addEventListener('dragstart', event => {
        const card = event.target.closest('.product-gallery-item');
        if (!card) return;
        draggedIndex = Number(card.dataset.galleryIndex);
        card.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
    });
    galleryGrid.addEventListener('dragend', event => {
        event.target.closest('.product-gallery-item')?.classList.remove('is-dragging');
        draggedIndex = null;
    });
    galleryGrid.addEventListener('dragover', event => {
        if (event.target.closest('.product-gallery-item')) event.preventDefault();
    });
    galleryGrid.addEventListener('drop', event => {
        const card = event.target.closest('.product-gallery-item');
        if (!card || draggedIndex === null) return;
        event.preventDefault();
        const targetIndex = Number(card.dataset.galleryIndex);
        if (targetIndex === draggedIndex) return;
        const [moved] = galleryItems.splice(draggedIndex, 1);
        galleryItems.splice(targetIndex, 0, moved);
        renderGallery();
    });

    const uploadFiles = async (files, target, input) => {
        const status = statusFor(target);
        const fileList = [...files];
        if (!fileList.length) return;
        pendingUploads += 1;
        if (status) status.textContent = `Đang tải ${fileList.length} ảnh lên Media Library…`;
        let uploadedCount = 0;
        for (const file of fileList) {
            const body = new FormData();
            body.append('_csrf_token', csrfToken);
            body.append('file', file);
            body.append('alt_text', document.getElementById('post-title')?.value || file.name);
            try {
                const response = await fetch('/admin/products/upload-image', { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.error || 'Không thể tải ảnh lên.');
                if (target === 'featured') updateFeatured(result.item.url, result.item.alt);
                else addGalleryItem(result.item);
                uploadedCount += 1;
            } catch (error) {
                if (status) status.textContent = error instanceof Error ? error.message : 'Tải ảnh lên thất bại.';
            }
        }
        if (input && uploadedCount === fileList.length) input.value = '';
        if (status && uploadedCount > 0) status.textContent = `Đã tải lên ${uploadedCount}/${fileList.length} ảnh.`;
        pendingUploads -= 1;
    };

    document.querySelectorAll('[data-product-image-input]').forEach(input => {
        input.addEventListener('change', () => uploadFiles(input.files, input.dataset.productImageInput, input));
    });

    document.querySelectorAll('[data-product-dropzone]').forEach(zone => {
        const target = zone.dataset.productDropzone;
        zone.addEventListener('dragover', event => {
            if ([...event.dataTransfer.types].includes('Files')) {
                event.preventDefault();
                zone.classList.add('is-drag-over');
            }
        });
        zone.addEventListener('dragleave', event => {
            if (!zone.contains(event.relatedTarget)) zone.classList.remove('is-drag-over');
        });
        zone.addEventListener('drop', event => {
            event.preventDefault();
            zone.classList.remove('is-drag-over');
            uploadFiles(event.dataTransfer.files, target, document.querySelector(`[data-product-image-input="${target}"]`));
        });
    });

    document.querySelector('[data-clear-featured]')?.addEventListener('click', () => {
        updateFeatured('');
        const fileInput = document.getElementById('featured-image-file');
        if (fileInput) fileInput.value = '';
    });

    const dialog = document.getElementById('product-media-dialog');
    const mediaGrid = document.getElementById('product-media-grid');
    const pageLabel = dialog?.querySelector('[data-media-picker-page]');
    const countLabel = dialog?.querySelector('[data-media-picker-count]');
    const addSelectedButton = dialog?.querySelector('[data-add-selected-media]');
    const prevButton = dialog?.querySelector('[data-media-page="prev"]');
    const nextButton = dialog?.querySelector('[data-media-page="next"]');
    const searchInput = dialog?.querySelector('[data-media-search]');
    const uploadInput = dialog?.querySelector('[data-media-upload-input]');
    const detailImage = dialog?.querySelector('[data-media-detail-image]');
    const detailName = dialog?.querySelector('[data-media-detail-name]');
    const detailDimensions = dialog?.querySelector('[data-media-detail-dimensions]');
    const detailAlt = dialog?.querySelector('[data-media-detail-alt]');
    const detailUrl = dialog?.querySelector('[data-media-detail-url]');
    const pickerStatus = dialog?.querySelector('[data-media-picker-status]');
    const selectedMedia = new Map();
    let pickerTarget = 'featured';
    let pickerPage = 1;
    let pickerTotalPages = 1;
    let pickerQuery = '';
    let searchTimer;
    let mediaRequestId = 0;

    const updatePickerSelection = () => {
        if (countLabel) countLabel.textContent = selectedMedia.size ? `Đã chọn ${selectedMedia.size} ảnh` : 'Chưa chọn ảnh';
        if (addSelectedButton) {
            addSelectedButton.disabled = selectedMedia.size === 0;
            addSelectedButton.textContent = pickerTarget === 'featured' ? 'Dùng ảnh này' : `Thêm ${selectedMedia.size || ''} ảnh vào album`.trim();
        }
        mediaGrid?.querySelectorAll('[data-media-id]').forEach(button => {
            const selected = selectedMedia.has(String(button.dataset.mediaId));
            button.classList.toggle('is-selected', selected);
            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
        const current = [...selectedMedia.values()].at(-1);
        if (detailImage) {
            detailImage.src = current?.url || '';
            detailImage.hidden = !current;
            detailImage.alt = current?.alt || '';
        }
        if (detailName) detailName.textContent = current?.name || 'Chưa chọn ảnh';
        if (detailDimensions) detailDimensions.textContent = current?.width && current?.height ? `${current.width} × ${current.height} px` : '—';
        if (detailAlt) detailAlt.textContent = current?.alt || 'Chưa có';
        if (detailUrl) detailUrl.textContent = current?.url || '—';
    };

    const loadMediaPage = async page => {
        if (!dialog || !mediaGrid) return;
        const requestId = ++mediaRequestId;
        mediaGrid.replaceChildren();
        const loading = document.createElement('p');
        loading.className = 'product-media-loading';
        loading.textContent = 'Đang tải thư viện ảnh…';
        mediaGrid.append(loading);
        try {
            const params = new URLSearchParams({ page: String(page), q: pickerQuery });
            const response = await fetch(`/admin/products/media-library?${params}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const result = await response.json();
            if (requestId !== mediaRequestId) return;
            if (!response.ok || !Array.isArray(result.items)) throw new Error('Không tải được Media Library.');
            pickerPage = result.page;
            pickerTotalPages = result.total_pages;
            mediaGrid.replaceChildren();
            if (result.items.length === 0) {
                const empty = document.createElement('p');
                empty.className = 'product-media-loading';
                empty.textContent = pickerQuery ? 'Không tìm thấy ảnh phù hợp.' : 'Thư viện chưa có ảnh. Hãy tải ảnh lên trước.';
                mediaGrid.append(empty);
            }
            result.items.forEach(item => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'product-media-choice';
                button.dataset.mediaId = String(item.id);
                button.dataset.mediaUrl = item.url;
                button.dataset.mediaAlt = item.alt || '';
                button.dataset.mediaName = item.name || item.url;
                button.dataset.mediaWidth = String(item.width || '');
                button.dataset.mediaHeight = String(item.height || '');
                button.setAttribute('aria-pressed', 'false');
                const image = document.createElement('img');
                image.src = item.url;
                image.alt = item.alt || '';
                image.loading = 'lazy';
                const caption = document.createElement('span');
                caption.textContent = item.name || item.url;
                button.append(image, caption);
                mediaGrid.append(button);
            });
            if (pageLabel) pageLabel.textContent = `${result.total} ảnh · ${pickerPage} / ${pickerTotalPages}`;
            if (prevButton) prevButton.disabled = pickerPage <= 1;
            if (nextButton) nextButton.disabled = pickerPage >= pickerTotalPages;
            updatePickerSelection();
        } catch (error) {
            if (requestId !== mediaRequestId) return;
            mediaGrid.replaceChildren();
            const failure = document.createElement('p');
            failure.className = 'product-media-loading';
            failure.textContent = error instanceof Error ? error.message : 'Không tải được thư viện ảnh.';
            mediaGrid.append(failure);
        }
    };

    const uploadToPicker = async files => {
        const fileList = [...files];
        if (!fileList.length) return;
        pendingUploads += 1;
        if (pickerStatus) pickerStatus.textContent = `Đang tải ${fileList.length} ảnh lên…`;
        const uploaded = [];
        for (const file of fileList) {
            const body = new FormData();
            body.append('_csrf_token', csrfToken);
            body.append('file', file);
            body.append('alt_text', document.getElementById('post-title')?.value || file.name);
            try {
                const response = await fetch('/admin/products/upload-image', { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.error || 'Không thể tải ảnh lên.');
                uploaded.push(result.item);
            } catch (error) {
                if (pickerStatus) pickerStatus.textContent = error instanceof Error ? error.message : 'Tải ảnh lên thất bại.';
            }
        }
        if (uploadInput) uploadInput.value = '';
        if (uploaded.length) {
            if (pickerTarget === 'featured') {
                selectedMedia.clear();
                const latest = uploaded[uploaded.length - 1];
                selectedMedia.set(String(latest.id), latest);
            } else {
                uploaded.forEach(item => selectedMedia.set(String(item.id), item));
            }
            pickerQuery = '';
            if (searchInput) searchInput.value = '';
            await loadMediaPage(1);
            updatePickerSelection();
            if (pickerStatus) pickerStatus.textContent = `Đã thêm ${uploaded.length} ảnh vào thư viện. Xác nhận lựa chọn để áp dụng.`;
        }
        pendingUploads -= 1;
    };

    document.querySelectorAll('[data-open-media-picker]').forEach(button => {
        button.addEventListener('click', () => {
            pickerTarget = button.dataset.openMediaPicker;
            selectedMedia.clear();
            pickerQuery = '';
            if (searchInput) searchInput.value = '';
            const title = dialog?.querySelector('#product-media-title');
            if (title) title.textContent = pickerTarget === 'featured' ? 'Chọn ảnh sản phẩm chính' : 'Thêm ảnh vào album';
            if (pickerStatus) pickerStatus.textContent = '';
            if (dialog?.showModal) dialog.showModal();
            else if (dialog) dialog.setAttribute('open', '');
            loadMediaPage(1);
        });
    });

    mediaGrid?.addEventListener('click', event => {
        const button = event.target.closest('[data-media-id]');
        if (!button) return;
        const id = String(button.dataset.mediaId);
        if (pickerTarget === 'featured') selectedMedia.clear();
        if (selectedMedia.has(id)) selectedMedia.delete(id);
        else selectedMedia.set(id, {
            id,
            url: button.dataset.mediaUrl,
            alt: button.dataset.mediaAlt,
            name: button.dataset.mediaName,
            width: Number(button.dataset.mediaWidth),
            height: Number(button.dataset.mediaHeight),
        });
        updatePickerSelection();
    });

    addSelectedButton?.addEventListener('click', () => {
        if (pickerTarget === 'featured') {
            const selected = [...selectedMedia.values()].at(-1);
            if (selected) { updateFeatured(selected.url, selected.alt); markMediaUsed([selected.id]); }
        } else {
            selectedMedia.forEach(addGalleryItem);
            markMediaUsed([...selectedMedia.values()].map(item => item.id));
        }
        dialog?.close();
    });
    prevButton?.addEventListener('click', () => loadMediaPage(pickerPage - 1));
    nextButton?.addEventListener('click', () => loadMediaPage(pickerPage + 1));
    searchInput?.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => {
            pickerQuery = searchInput.value.trim();
            loadMediaPage(1);
        }, 250);
    });
    dialog?.querySelector('[data-media-upload]')?.addEventListener('click', () => uploadInput?.click());
    uploadInput?.addEventListener('change', () => uploadToPicker(uploadInput.files));
    dialog?.querySelector('[data-media-browser]')?.addEventListener('dragover', event => {
        if ([...event.dataTransfer.types].includes('Files')) event.preventDefault();
    });
    dialog?.querySelector('[data-media-browser]')?.addEventListener('drop', event => {
        if (![...event.dataTransfer.types].includes('Files')) return;
        event.preventDefault();
        uploadToPicker(event.dataTransfer.files);
    });
    dialog?.querySelector('[data-close-media-picker]')?.addEventListener('click', () => dialog.close());
    dialog?.addEventListener('keydown', event => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            searchInput?.focus();
        }
    });
    dialog?.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });

    form.addEventListener('submit', event => {
        if (pendingUploads > 0) {
            event.preventDefault();
            const status = statusFor('gallery');
            if (status) status.textContent = 'Vui lòng chờ ảnh tải lên xong rồi lưu sản phẩm.';
            return;
        }
        syncGalleryValue();
    });
    renderGallery();
}
