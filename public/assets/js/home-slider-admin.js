(() => {
    const form = document.querySelector('[data-home-slider-form]');
    if (!form) return;
    const list = form.querySelector('[data-slider-list]');
    const template = form.querySelector('[data-slider-template]');

    function reindex() {
        [...list.querySelectorAll('[data-slider-card]')].forEach((card, index) => {
            card.querySelector('[data-slide-number]').textContent = String(index + 1);
            card.querySelectorAll('input, textarea, select').forEach(field => {
                const fieldName = field.dataset.slideField || field.name?.match(/slides\[\d+\]\[([^\]]+)\]/)?.[1];
                if (fieldName) field.name = `slides[${index}][${fieldName}]`;
            });
        });
    }

    form.addEventListener('click', event => {
        const add = event.target.closest('[data-slider-add]');
        if (add) {
            if (list.children.length >= 12) return window.alert('Slider hỗ trợ tối đa 12 ảnh.');
            list.append(template.content.cloneNode(true));
            reindex();
            return;
        }
        const remove = event.target.closest('[data-slide-remove]');
        if (remove) {
            remove.closest('[data-slider-card]').remove();
            reindex();
            return;
        }
        const pick = event.target.closest('[data-slide-pick]');
        if (pick) {
            const card = pick.closest('[data-slider-card]');
            const picker = window.AdminMediaPicker;
            if (!picker) return window.alert('Media Library chưa sẵn sàng. Hãy tải lại trang.');
            picker.open({ multiple: false, onSelect: items => {
                const image = items[0];
                if (!image) return;
                card.querySelector('[data-slide-image]').value = image.url;
                card.querySelector('[data-slide-media-id]').value = String(image.id || 0);
                const preview = card.querySelector('[data-slide-preview]');
                const img = document.createElement('img');
                img.src = image.url;
                img.alt = image.alt || '';
                preview.replaceChildren(img);
            } });
        }
    });

    let dragging = null;
    list.addEventListener('dragstart', event => {
        const card = event.target.closest('[data-slider-card]');
        if (!card) return;
        dragging = card;
        card.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
    });
    list.addEventListener('dragend', () => {
        dragging?.classList.remove('is-dragging');
        dragging = null;
        reindex();
    });
    list.addEventListener('dragover', event => {
        if (!dragging) return;
        event.preventDefault();
        const target = event.target.closest('[data-slider-card]');
        if (!target || target === dragging) return;
        const box = target.getBoundingClientRect();
        list.insertBefore(dragging, event.clientY < box.top + box.height / 2 ? target : target.nextSibling);
    });
    form.addEventListener('submit', event => {
        reindex();
        const enabled = form.querySelector('[name="enabled"]')?.checked;
        if (enabled && ![...list.querySelectorAll('[data-slide-image]')].some(input => input.value.trim())) {
            event.preventDefault();
            window.alert('Hãy chọn ít nhất một ảnh trước khi bật slider.');
        }
    });
})();
