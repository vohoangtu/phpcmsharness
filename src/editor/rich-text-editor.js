import { Editor, Mark, Node } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import Link from '@tiptap/extension-link';
import Underline from '@tiptap/extension-underline';
import TextAlign from '@tiptap/extension-text-align';
import { Table } from '@tiptap/extension-table';
import TableRow from '@tiptap/extension-table-row';
import TableCell from '@tiptap/extension-table-cell';
import TableHeader from '@tiptap/extension-table-header';

const YoutubeEmbed = Node.create({
    name: 'youtubeEmbed',
    group: 'block',
    atom: true,
    addAttributes: () => ({ src: { default: null } }),
    parseHTML: () => [{ tag: 'iframe[src]' }],
    renderHTML: ({ HTMLAttributes }) => ['iframe', { ...HTMLAttributes, 'data-youtube-embed': 'true', width: '560', height: '315', title: 'YouTube video', allowfullscreen: 'true', allow: 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share', loading: 'lazy' }],
});

// A small MIT-compatible text style mark. Keeping the style attributes explicit
// makes the output predictable and lets the server sanitizer validate the CSS.
const TextStyle = Mark.create({
    name: 'textStyle',
    addAttributes: () => ({
        fontFamily: { default: null, parseHTML: element => element.style.fontFamily || null },
        fontSize: { default: null, parseHTML: element => element.style.fontSize || null },
        color: { default: null, parseHTML: element => element.style.color || null },
        backgroundColor: { default: null, parseHTML: element => element.style.backgroundColor || null },
    }),
    parseHTML: () => [{ tag: 'span[style]' }],
    renderHTML: ({ HTMLAttributes, mark }) => {
        const { fontFamily, fontSize, color, backgroundColor } = mark.attrs;
        const style = [fontFamily && `font-family: ${fontFamily}`, fontSize && `font-size: ${fontSize}`, color && `color: ${color}`, backgroundColor && `background-color: ${backgroundColor}`].filter(Boolean).join('; ');
        return ['span', { ...HTMLAttributes, ...(style ? { style } : {}) }, 0];
    },
});

const extensions = [
    StarterKit,
    TextStyle,
    Underline,
    Link.configure({ openOnClick: false, protocols: ['mailto'] }),
    Image.configure({
        inline: false,
        allowBase64: false,
        resize: {
            enabled: true,
            directions: ['top-left', 'top-right', 'bottom-left', 'bottom-right'],
            minWidth: 80,
            minHeight: 60,
            alwaysPreserveAspectRatio: true,
        },
    }),
    TextAlign.configure({ types: ['heading', 'paragraph'] }),
    Table.configure({ resizable: true }),
    TableRow,
    TableHeader,
    YoutubeEmbed,
    TableCell,
];

document.querySelectorAll('[data-rich-text-editor]').forEach(wrapper => {
    const source = document.getElementById(wrapper.dataset.sourceId);
    const surface = wrapper.querySelector('[data-rich-text-surface]');
    const toolbar = wrapper.querySelector('[data-rich-text-toolbar]');
    if (!source || !surface || !toolbar) return;

    const editor = new Editor({
        element: surface,
        extensions,
        content: source.value,
        onUpdate: ({ editor: current }) => { source.value = current.getHTML(); },
    });
    source.hidden = true;
    surface.hidden = false;
    wrapper.editor = editor;

    toolbar.addEventListener('click', event => {
        const button = event.target.closest('[data-editor-command]');
        if (!button) return;
        event.preventDefault();
        const command = button.dataset.editorCommand;
        const chain = editor.chain().focus();
        const commands = {
            bold: () => chain.toggleBold().run(),
            italic: () => chain.toggleItalic().run(),
            underline: () => chain.toggleUnderline().run(),
            strike: () => chain.toggleStrike().run(),
            clearFormatting: () => chain.unsetAllMarks().clearNodes().run(),
            bulletList: () => chain.toggleBulletList().run(),
            orderedList: () => chain.toggleOrderedList().run(),
            blockquote: () => chain.toggleBlockquote().run(),
            undo: () => chain.undo().run(),
            redo: () => chain.redo().run(),
            alignLeft: () => chain.setTextAlign('left').run(),
            alignCenter: () => chain.setTextAlign('center').run(),
            alignRight: () => chain.setTextAlign('right').run(),
            horizontalRule: () => chain.setHorizontalRule().run(),
            insertTable: () => chain.insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run(),
            addRow: () => chain.addRowAfter().run(),
            deleteTable: () => chain.deleteTable().run(),
            link: () => {
                const url = window.prompt('Nhập URL liên kết:', 'https://');
                if (url) chain.extendMarkRange('link').setLink({ href: url }).run();
            },
            unlink: () => chain.unsetLink().run(),
            image: () => {
                const picker = window.AdminMediaPicker;
                if (picker) picker.open({ multiple: false, onSelect: items => {
                    items.forEach(item => editor.chain().focus().setImage({ src: item.url, alt: item.alt || '' }).run());
                    const body = new FormData();
                    body.append('_csrf_token', document.querySelector('input[name="_csrf_token"]')?.value || '');
                    items.forEach(item => body.append('media_ids[]', String(item.id)));
                    fetch('/admin/media/mark-used', { method: 'POST', body }).catch(() => {});
                } });
                else window.alert('Media Library chưa sẵn sàng trên trang này.');
            },
            source: () => toggleSource(),
            youtube: () => {
                const url = window.prompt('Dán URL video YouTube:');
                if (!url) return;
                let videoId = '';
                try {
                    const parsed = new URL(url);
                    videoId = parsed.hostname.endsWith('youtu.be') ? parsed.pathname.slice(1) : parsed.searchParams.get('v') || parsed.pathname.split('/').filter(Boolean).pop();
                } catch { return; }
                if (/^[\w-]{11}$/.test(videoId)) editor.chain().focus().insertContent({ type: 'youtubeEmbed', attrs: { src: `https://www.youtube-nocookie.com/embed/${videoId}` } }).run();
            },
        };
        commands[command]?.();
    });

    toolbar.addEventListener('change', event => {
        const control = event.target;
        const chain = editor.chain().focus();
        if (control.matches('[data-editor-font-family]')) chain.setMark('textStyle', { fontFamily: control.value || null }).run();
        if (control.matches('[data-editor-font-size]')) chain.setMark('textStyle', { fontSize: control.value || null }).run();
        if (control.matches('[data-editor-color]')) chain.setMark('textStyle', { color: control.value }).run();
        if (control.matches('[data-editor-highlight]')) chain.setMark('textStyle', { backgroundColor: control.value }).run();
    });

    const wordCount = toolbar.querySelector('[data-editor-word-count]');
    const refreshWordCount = () => {
        if (!wordCount) return;
        const text = editor.getText().trim();
        const words = text ? text.split(/\s+/u).length : 0;
        wordCount.textContent = `${words.toLocaleString('vi-VN')} từ · ${text.length.toLocaleString('vi-VN')} ký tự`;
    };
    editor.on('update', refreshWordCount);
    refreshWordCount();

    toolbar.addEventListener('mousedown', event => {
        if (event.target.closest('button')) event.preventDefault();
    });

    const refreshActiveTools = () => toolbar.querySelectorAll('[data-editor-command]').forEach(button => {
        const command = button.dataset.editorCommand;
        const active = ({ bold: 'bold', italic: 'italic', underline: 'underline', strike: 'strike', bulletList: 'bulletList', orderedList: 'orderedList', blockquote: 'blockquote' })[command];
        if (active) {
            const selected = editor.isActive(active);
            button.classList.toggle('is-active', selected);
            button.setAttribute('aria-pressed', String(selected));
        }
    });
    editor.on('selectionUpdate', refreshActiveTools);

    toolbar.querySelector('[data-editor-command="heading"]')?.addEventListener('change', event => {
        const tag = event.target.value;
        const chain = editor.chain().focus();
        if (tag === 'p') chain.setParagraph().run();
        else chain.toggleHeading({ level: Number(tag.slice(1)) }).run();
    });

    function toggleSource() {
        const sourceMode = wrapper.classList.toggle('is-source-mode');
        surface.hidden = sourceMode;
        source.hidden = !sourceMode;
        if (!sourceMode) editor.commands.setContent(source.value, { emitUpdate: false });
        else source.value = editor.getHTML();
        const sourceButton = toolbar.querySelector('[data-editor-command="source"]');
        if (sourceButton) sourceButton.textContent = sourceMode ? '↩ Trình soạn thảo' : '</> HTML';
    }

    wrapper.closest('form')?.addEventListener('submit', () => {
        if (!wrapper.classList.contains('is-source-mode')) source.value = editor.getHTML();
    });
});

const mediaDialog = document.getElementById('rich-text-media-dialog');
if (mediaDialog) {
    const grid = mediaDialog.querySelector('[data-rich-media-grid]');
    const search = mediaDialog.querySelector('[data-rich-media-search]');
    const upload = mediaDialog.querySelector('[data-rich-media-upload]');
    const pageLabel = mediaDialog.querySelector('[data-rich-media-page]');
    const insertButton = mediaDialog.querySelector('[data-rich-media-insert]');
    let editorSelection = null;
    let allowMultiple = false;
    let page = 1;
    let pages = 1;
    let query = '';
    let picked = [];

    async function loadMedia() {
        grid.replaceChildren();
        const response = await fetch(`/admin/media/library-json?page=${page}&q=${encodeURIComponent(query)}`);
        const result = await response.json();
        pages = result.total_pages || 1;
        page = result.page || 1;
        pageLabel.textContent = `${result.total} ảnh · ${page}/${pages}`;
        result.items.forEach(item => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'product-media-choice';
            button.setAttribute('aria-pressed', 'false');
            const image = document.createElement('img');
            image.src = item.url;
            image.alt = item.alt || '';
            const label = document.createElement('span');
            label.textContent = item.name || item.url;
            button.append(image, label);
            button.addEventListener('click', () => {
                const exists = picked.some(file => file.id === item.id);
                if (!allowMultiple) picked = [];
                picked = exists ? picked.filter(file => file.id !== item.id) : [...picked, item];
                button.classList.toggle('is-selected', !exists);
                button.setAttribute('aria-pressed', String(!exists));
                insertButton.disabled = picked.length === 0;
            });
            grid.append(button);
        });
    }

    window.AdminMediaPicker = {
        open(options) {
            editorSelection = options.onSelect;
            allowMultiple = options.multiple === true;
            picked = [];
            insertButton.disabled = true;
            query = '';
            search.value = '';
            page = 1;
            mediaDialog.showModal();
            loadMedia();
        },
    };
    mediaDialog.querySelector('[data-rich-media-close]').addEventListener('click', () => mediaDialog.close());
    search.addEventListener('input', () => { query = search.value.trim(); page = 1; loadMedia(); });
    mediaDialog.querySelector('[data-rich-media-prev]').addEventListener('click', () => { if (page > 1) { page -= 1; loadMedia(); } });
    mediaDialog.querySelector('[data-rich-media-next]').addEventListener('click', () => { if (page < pages) { page += 1; loadMedia(); } });
    mediaDialog.querySelector('[data-rich-media-upload-button]').addEventListener('click', () => upload.click());
    upload.addEventListener('change', async () => {
        const csrf = document.querySelector('input[name="_csrf_token"]')?.value || '';
        for (const file of upload.files) {
            const body = new FormData();
            body.append('_csrf_token', csrf);
            body.append('file', file);
            const response = await fetch('/admin/media/upload-json', { method: 'POST', body });
            if (!response.ok) continue;
        }
        upload.value = '';
        await loadMedia();
    });
    insertButton.addEventListener('click', () => {
        editorSelection?.(picked);
        mediaDialog.close();
    });
}
