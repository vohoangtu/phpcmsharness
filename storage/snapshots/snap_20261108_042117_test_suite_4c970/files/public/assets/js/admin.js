// WP-Hexagon Admin JavaScript
document.addEventListener('DOMContentLoaded', () => {
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

function toggleSourceMode() {
    const visual = document.getElementById('visual-editor');
    const raw = document.getElementById('raw-content');
    if (!visual || !raw) return;

    if (raw.style.display === 'none') {
        raw.value = visual.innerHTML;
        visual.style.display = 'none';
        raw.style.display = 'block';
    } else {
        visual.innerHTML = raw.value;
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
    if (url && url.trim() !== '') {
        img.src = url.trim();
        img.style.display = 'block';
    } else {
        img.style.display = 'none';
    }
}
