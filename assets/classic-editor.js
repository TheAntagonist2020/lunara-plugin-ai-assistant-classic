(function () {
    function qs(selector, root) {
        return (root || document).querySelector(selector);
    }

    function getTinyMce() {
        if (window.tinymce && window.tinymce.get) {
            return window.tinymce.get('content');
        }
        return null;
    }

    function getEditorContent() {
        var editor = getTinyMce();
        if (editor && !editor.isHidden()) {
            return editor.getContent({ format: 'html' }) || '';
        }

        var textarea = qs('#content');
        return textarea ? textarea.value : '';
    }

    function getSelectedEditorText() {
        var editor = getTinyMce();
        if (editor && !editor.isHidden()) {
            return editor.selection.getContent({ format: 'text' }) || '';
        }

        var textarea = qs('#content');
        if (textarea) {
            var start = textarea.selectionStart || 0;
            var end = textarea.selectionEnd || 0;
            return textarea.value.slice(start, end);
        }

        return '';
    }

    function insertEditorHtml(html) {
        var editor = getTinyMce();
        if (editor && !editor.isHidden()) {
            editor.execCommand('mceInsertContent', false, html);
            editor.save();
            return;
        }

        var textarea = qs('#content');
        if (textarea) {
            var start = textarea.selectionStart || textarea.value.length;
            var end = textarea.selectionEnd || textarea.value.length;
            textarea.value = textarea.value.slice(0, start) + html + textarea.value.slice(end);
            textarea.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function getPostTitle() {
        var title = qs('#title');
        return title ? title.value : '';
    }

    function setPostTitle(value) {
        var title = qs('#title');
        if (title) {
            title.value = value;
            title.dispatchEvent(new Event('input', { bubbles: true }));
            title.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function setExcerpt(value) {
        var excerpt = qs('#excerpt');
        if (!excerpt) {
            window.alert(LunaraAIClassic.i18n.noExcerpt);
            return;
        }

        excerpt.value = value;
        excerpt.dispatchEvent(new Event('input', { bubbles: true }));
        excerpt.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function cleanLine(text) {
        return String(text || '')
            .replace(/^[-*#\d.)\s]+/, '')
            .replace(/^"|"$/g, '')
            .trim();
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = String(text || '');
        return div.innerHTML;
    }

    function textToHtml(text) {
        return escapeHtml(text).replace(/\n/g, '<br>');
    }

    function firstUsefulLine(text) {
        var lines = String(text || '').split(/\n+/).map(cleanLine).filter(Boolean);
        return lines.length ? lines[0] : '';
    }

    function getPostType(root) {
        return root.getAttribute('data-post-type') || '';
    }

    function setStatus(root, text, isError) {
        var status = qs('.lunara-ai-classic-status', root);
        if (status) {
            status.textContent = text || '';
            status.classList.toggle('is-error', !!isError);
        }
    }

    function renderResult(root, text) {
        var wrap = qs('.lunara-ai-classic-result-wrap', root);
        var result = qs('.lunara-ai-classic-result', root);
        if (wrap) {
            wrap.hidden = false;
        }
        if (result) {
            result.textContent = text || '';
        }
    }

    function getResult(root) {
        var result = qs('.lunara-ai-classic-result', root);
        return result ? result.textContent : '';
    }

    function getApplyLine(root) {
        var input = qs('#lunara-ai-classic-line', root);
        return cleanLine((input && input.value) || firstUsefulLine(getResult(root)));
    }

    function generate(root) {
        var button = qs('.lunara-ai-classic-generate', root);
        var payload = {
            mode: qs('#lunara-ai-classic-mode', root).value,
            postType: getPostType(root),
            filmTitle: qs('#lunara-ai-classic-film-title', root).value,
            filmYear: qs('#lunara-ai-classic-film-year', root).value,
            notes: qs('#lunara-ai-classic-notes', root).value,
            referenceText: qs('#lunara-ai-classic-reference', root).value,
            postTitle: getPostTitle(),
            postContent: getEditorContent()
        };

        button.disabled = true;
        setStatus(root, LunaraAIClassic.i18n.working, false);
        renderResult(root, '');

        window.fetch(LunaraAIClassic.restUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': LunaraAIClassic.nonce
            },
            body: JSON.stringify(payload)
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok) {
                        throw new Error(data.message || LunaraAIClassic.i18n.failed);
                    }
                    return data;
                });
            })
            .then(function (data) {
                renderResult(root, data.text || 'No text returned.');
                setStatus(root, LunaraAIClassic.i18n.ready, false);
            })
            .catch(function (error) {
                setStatus(root, error.message || LunaraAIClassic.i18n.failed, true);
            })
            .finally(function () {
                button.disabled = false;
            });
    }

    function applyResult(root, action) {
        var result = getResult(root);
        var line = getApplyLine(root);

        if (!result) {
            setStatus(root, LunaraAIClassic.i18n.noResult, true);
            return;
        }

        if (action === 'title') {
            setPostTitle(line);
        } else if (action === 'excerpt') {
            setExcerpt(line);
        } else if (action === 'h2') {
            insertEditorHtml('<h2>' + escapeHtml(line) + '</h2>');
        } else if (action === 'quote') {
            insertEditorHtml('<blockquote class="wp-block-pullquote"><p>' + escapeHtml(line) + '</p></blockquote>');
        } else if (action === 'replace') {
            insertEditorHtml(textToHtml(result));
        } else if (action === 'full') {
            insertEditorHtml('<div class="lunara-ai-inserted">' + textToHtml(result) + '</div>');
        }

        setStatus(root, LunaraAIClassic.i18n.ready, false);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var root = qs('#lunara-ai-classic-root');
        if (!root) {
            return;
        }

        if (getPostType(root) === 'journal') {
            qs('#lunara-ai-classic-mode', root).value = 'journal';
        }

        qs('.lunara-ai-classic-use-selection', root).addEventListener('click', function () {
            var selected = getSelectedEditorText();
            var reference = qs('#lunara-ai-classic-reference', root);
            if (selected && reference) {
                reference.value = selected;
                qs('#lunara-ai-classic-mode', root).value = 'rewrite';
                setStatus(root, 'Selected text loaded for rewrite.', false);
            } else {
                setStatus(root, 'Highlight text in the editor first, then click Use Selected Text.', true);
            }
        });

        qs('.lunara-ai-classic-generate', root).addEventListener('click', function () {
            generate(root);
        });

        root.addEventListener('click', function (event) {
            var button = event.target.closest('[data-lunara-apply]');
            if (button) {
                applyResult(root, button.getAttribute('data-lunara-apply'));
            }
        });
    });
})();
