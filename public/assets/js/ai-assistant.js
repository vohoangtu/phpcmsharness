(() => {
    const labels = {
        post_seo: 'Gợi ý SEO bài viết', product_seo: 'Gợi ý SEO sản phẩm',
        keyword_ideas: 'Nghiên cứu từ khóa', content_audit: 'Phân tích nội dung', article_draft: 'Bản nháp bài viết',
    };
    let dialog, resultPanel, status, applyButton, pending = null;

    function ensureDialog() {
        if (dialog) return;
        dialog = document.createElement('dialog');
        dialog.className = 'ai-assistant-dialog';
        dialog.setAttribute('aria-labelledby', 'ai-assistant-title');
        dialog.innerHTML = '<div class="ai-assistant-dialog-head"><div><span class="dashboard-section-kicker">AI WRITING ASSISTANT</span><h2 id="ai-assistant-title"></h2></div><button type="button" class="wp-btn wp-btn-secondary" data-ai-close aria-label="Đóng">✕</button></div><p class="ai-assistant-status" data-ai-status aria-live="polite"></p><div class="ai-assistant-result" data-ai-result></div><div class="ai-assistant-dialog-actions"><button type="button" class="wp-btn wp-btn-secondary" data-ai-close>Đóng</button><button type="button" class="wp-btn wp-btn-primary" data-ai-apply hidden>Áp dụng vào biểu mẫu</button></div>';
        document.body.append(dialog);
        resultPanel = dialog.querySelector('[data-ai-result]');
        status = dialog.querySelector('[data-ai-status]');
        applyButton = dialog.querySelector('[data-ai-apply]');
        dialog.querySelectorAll('[data-ai-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
        applyButton.addEventListener('click', applyResult);
    }

    function editorText(form) {
        const wrapper = form.querySelector('[data-rich-text-editor]');
        if (wrapper?.editor) return wrapper.editor.getText();
        return form.querySelector('#raw-content')?.value || '';
    }

    function gatherContext(form) {
        return {
            title: form.querySelector('#post-title')?.value || '',
            excerpt: form.querySelector('#post-excerpt')?.value || '',
            description: form.querySelector('#product-short-description')?.value || form.querySelector('#post-excerpt')?.value || editorText(form),
            short_description: form.querySelector('#product-short-description')?.value || '',
            content: editorText(form),
            brief: form.querySelector('[data-ai-brief]')?.value || '',
            tags: form.querySelector('[name="tags"]')?.value || '',
            category: form.querySelector('[name="category_id"] option:checked')?.textContent?.trim() || '',
            seo_title: form.querySelector('[name="seo_title"]')?.value || '',
            seo_description: form.querySelector('[name="seo_description"]')?.value || '',
        };
    }

    function renderResult(action, result) {
        resultPanel.replaceChildren();
        const fields = action === 'article_draft'
            ? [['Tiêu đề', result.title], ['Tóm tắt', result.excerpt], ['Từ khóa chính', result.focus_keyword], ['Thẻ', result.tags], ['Nội dung HTML', result.html]]
            : action === 'content_audit'
                ? [['Tổng quan', result.summary], ['Điểm tốt', result.strengths], ['Vấn đề cần xem lại', result.issues], ['Đề xuất cải thiện', result.recommendations], ['Mức độ bao phủ từ khóa', result.keyword_coverage]]
                : action === 'keyword_ideas'
                ? [['Từ khóa chính', result.focus_keyword], ['Từ khóa gợi ý', result.keywords], ['Câu hỏi liên quan', result.questions]]
                : [['SEO title', result.seo_title], ['Meta description', result.meta_description], ['Từ khóa chính', result.focus_keyword], ['Từ khóa gợi ý', result.keywords]];
        fields.forEach(([label, value]) => {
            if (value === undefined || value === null || value === '') return;
            const section = document.createElement('section');
            const heading = document.createElement('strong');
            heading.textContent = label;
            const body = document.createElement('p');
            if (Array.isArray(value)) body.textContent = value.map(item => typeof item === 'object' ? `${item.keyword || ''}${item.intent ? ` · ${item.intent}` : ''}${item.reason ? ` — ${item.reason}` : ''}` : String(item)).join('\n');
            else body.textContent = String(value);
            section.append(heading, body);
            resultPanel.append(section);
        });
    }

    function mergeTags(form, values) {
        const field = form.querySelector('[name="tags"]');
        if (!field || !Array.isArray(values)) return;
        const existing = field.value.split(',').map(value => value.trim()).filter(Boolean);
        const seen = new Set(existing.map(value => value.toLocaleLowerCase('vi')));
        values.forEach(value => {
            const tag = typeof value === 'object' ? String(value.keyword || '') : String(value);
            const normalized = tag.trim();
            if (normalized && !seen.has(normalized.toLocaleLowerCase('vi'))) { existing.push(normalized); seen.add(normalized.toLocaleLowerCase('vi')); }
        });
        field.value = existing.join(', ');
    }

    const pause = milliseconds => new Promise(resolve => window.setTimeout(resolve, milliseconds));
    async function readJson(response) {
        const body = await response.text();
        try { return JSON.parse(body); }
        catch {
            if (response.redirected || /<form[^>]+(?:login|đăng nhập)|<title>[^<]*đăng nhập/i.test(body)) {
                throw new Error('Phiên quản trị đã hết hạn. Hãy đăng nhập lại rồi thử gửi yêu cầu.');
            }
            const phpError = body.match(/(?:Warning|Fatal error|Parse error|Notice):\s*([^<\r\n]+)/i);
            const detail = phpError ? phpError[1].trim() : `Máy chủ trả về nội dung không phải JSON (HTTP ${response.status}).`;
            throw new Error(`Không đọc được phản hồi từ máy chủ: ${detail}`);
        }
    }

    async function waitForJob(jobId, onStatus) {
        for (let attempt = 0; attempt < 240; attempt++) {
            await pause(1250);
            const response = await fetch(`/admin/ai/jobs/${encodeURIComponent(jobId)}`, { credentials: 'same-origin' });
            const payload = await readJson(response);
            if (!response.ok || !payload.ok) throw new Error(payload.error || 'Không đọc được trạng thái job AI.');
            const job = payload.job || {};
            if (job.status === 'completed') return job.result || {};
            if (job.status === 'failed') throw new Error(job.error || 'Worker không hoàn thành tác vụ AI.');
            if (job.status === 'cancelled') throw new Error('Tác vụ AI đã bị hủy trong Workers.');
            onStatus?.(job.status);
        }
        throw new Error('Tác vụ vẫn đang chạy. Xem tiến độ trong trang Workers rồi thử tải lại khi hoàn tất.');
    }

    function applyResult() {
        if (!pending) return;
        const { form, action, result } = pending;
        if (action === 'article_draft') {
            const title = form.querySelector('#post-title');
            const excerpt = form.querySelector('#post-excerpt');
            if (title && result.title) title.value = result.title;
            if (excerpt && result.excerpt) excerpt.value = result.excerpt;
            const wrapper = form.querySelector('[data-rich-text-editor]');
            if (wrapper?.editor && result.html) wrapper.editor.commands.setContent(result.html);
            else if (form.querySelector('#raw-content') && result.html) form.querySelector('#raw-content').value = result.html;
            mergeTags(form, result.tags || []);
        } else {
            const seoTitle = form.querySelector('[name="seo_title"]');
            const seoDescription = form.querySelector('[name="seo_description"]');
            if (action !== 'keyword_ideas') {
                if (seoTitle && result.seo_title) seoTitle.value = result.seo_title;
                if (seoDescription && result.meta_description) seoDescription.value = result.meta_description;
            }
            mergeTags(form, result.keywords || []);
        }
        dialog.close();
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    async function runGeneration(button) {
        const form = button.closest('form');
        const action = button.dataset.aiAction;
        if (!form || !labels[action]) return;
        ensureDialog();
        pending = null;
        dialog.querySelector('#ai-assistant-title').textContent = labels[action];
        status.textContent = 'Đang gửi yêu cầu…';
        resultPanel.replaceChildren();
        applyButton.hidden = true;
        dialog.showModal();
        button.disabled = true;
        try {
            const body = new FormData();
            body.append('_csrf_token', form.querySelector('[name="_csrf_token"]')?.value || '');
            body.append('action', action);
            body.append('context', JSON.stringify(gatherContext(form)));
            const response = await fetch('/admin/ai/generate', { method: 'POST', body, credentials: 'same-origin' });
            const payload = await readJson(response);
            if (!response.ok || !payload.ok) throw new Error(payload.error || 'Không thể xếp tác vụ AI vào Workers.');
            status.textContent = `Job #${payload.job_id} đã xếp hàng…`;
            const result = await waitForJob(payload.job_id, jobStatus => {
                status.textContent = jobStatus === 'running' ? `Job #${payload.job_id}: Worker đang xử lý yêu cầu AI…` : `Job #${payload.job_id}: đang chờ Worker…`;
            });
            pending = { form, action, result };
            status.textContent = 'Đề xuất được tạo từ dữ liệu đang có. Hãy xem lại trước khi áp dụng và lưu.';
            renderResult(action, pending.result);
            applyButton.hidden = action === 'content_audit';
        } catch (error) {
            status.textContent = error.message || 'Đã xảy ra lỗi khi gọi AI.';
            const link = document.createElement('a');
            const stillRunning = error.message?.includes('vẫn đang chạy');
            const workerIssue = /worker|exec/i.test(error.message || '');
            link.href = stillRunning || workerIssue ? '/admin/workers' : '/admin/harness';
            link.textContent = stillRunning || workerIssue ? 'Mở trang Workers để kiểm tra hàng đợi' : 'Kiểm tra cấu hình OpenAI-compatible API trong Harness';
            resultPanel.replaceChildren(link);
        } finally {
            button.disabled = false;
        }
    }

    document.addEventListener('click', event => {
        const button = event.target.closest('[data-ai-action]');
        if (button) runGeneration(button);
        const analyticsButton = event.target.closest('[data-ai-analytics]');
        if (analyticsButton) runAnalytics(analyticsButton);
    });

    async function runAnalytics(button) {
        const resultBox = document.querySelector('[data-ai-analytics-result]');
        if (!resultBox) return;
        button.disabled = true;
        button.textContent = 'Đang phân tích…';
        resultBox.hidden = false;
        resultBox.textContent = 'AI đang đọc dữ liệu GA4…';
        try {
            const body = new FormData();
            body.append('_csrf_token', document.querySelector('input[name="_csrf_token"]')?.value || '');
            body.append('context', document.querySelector('[data-ai-analytics-context]')?.value || '{}');
            const response = await fetch('/admin/ai/analytics', { method: 'POST', body, credentials: 'same-origin' });
            const payload = await readJson(response);
            if (!response.ok || !payload.ok) throw new Error(payload.error || 'Không thể xếp phân tích Analytics vào Workers.');
            resultBox.textContent = `Job #${payload.job_id} đã xếp hàng, đang chờ Worker…`;
            const result = await waitForJob(payload.job_id, jobStatus => {
                resultBox.textContent = jobStatus === 'running' ? `Job #${payload.job_id}: Worker đang gọi AI để phân tích GA4…` : `Job #${payload.job_id}: đang chờ Worker…`;
            });
            resultBox.replaceChildren();
            const summary = document.createElement('p'); summary.className = 'ai-analytics-summary'; summary.textContent = result.summary || '';
            resultBox.append(summary);
            [['Điểm đáng chú ý', result.findings], ['Cơ hội cải thiện', result.opportunities], ['Giới hạn dữ liệu', result.caveats]].forEach(([title, entries]) => {
                if (!Array.isArray(entries) || entries.length === 0) return;
                const section = document.createElement('section'); const heading = document.createElement('h3'); heading.textContent = title;
                const list = document.createElement('ul'); entries.forEach(entry => { const item = document.createElement('li'); item.textContent = String(entry); list.append(item); });
                section.append(heading, list); resultBox.append(section);
            });
        } catch (error) { resultBox.textContent = error.message || 'Không thể phân tích Analytics.'; }
        finally { button.disabled = false; button.textContent = 'Phân tích lại dữ liệu GA4'; }
    }
})();
