import { createIcons, Orbit, ChevronsUpDown, LayoutDashboard, Layers, Users, Wallet, ArrowUpRight, CircleAlert, MessageCircle, History, LogOut, PanelLeft, ChevronRight, RefreshCw, Plus, Activity, CalendarDays, ArrowDownLeft, ArrowRight, CalendarRange, ChartPie, ChartNoAxesCombined, CalendarClock, UserPlus, Send, Search, SlidersHorizontal, Settings2, Eye, Inbox, ArrowLeft, Play, FileCheck2, X, ShieldCheck, Trash2, RotateCcw, Bold, Italic, Code2, Quote, List } from 'lucide';
import Chart from 'chart.js/auto';

createIcons({ icons: { Orbit, ChevronsUpDown, LayoutDashboard, Layers, Users, Wallet, ArrowUpRight, CircleAlert, MessageCircle, History, LogOut, PanelLeft, ChevronRight, RefreshCw, Plus, Activity, CalendarDays, ArrowDownLeft, ArrowRight, CalendarRange, ChartPie, ChartNoAxesCombined, CalendarClock, UserPlus, Send, Search, SlidersHorizontal, Settings2, Eye, Inbox, ArrowLeft, Play, FileCheck2, X, ShieldCheck, Trash2, RotateCcw, Bold, Italic, Code2, Quote, List } });
const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
const overlay = document.querySelector('[data-sidebar-close]');
const sidebar = document.querySelector('#sidebar');
const smallScreen = () => window.matchMedia('(max-width: 700px)').matches;
function closeSidebar() {
    document.body.classList.remove('sidebar-open');
    if (overlay) overlay.hidden = true;
    if (sidebar) sidebar.inert = smallScreen() || document.body.classList.contains('sidebar-collapsed');
    sidebarToggle?.setAttribute('aria-expanded', String(!smallScreen() && !document.body.classList.contains('sidebar-collapsed')));
}
closeSidebar();
sidebarToggle?.addEventListener('click', () => {
    if (smallScreen()) {
        const opened = document.body.classList.toggle('sidebar-open');
        overlay.hidden = !opened;
        sidebar.inert = !opened;
        sidebarToggle.setAttribute('aria-expanded', String(opened));
    } else {
        const collapsed = document.body.classList.toggle('sidebar-collapsed');
        sidebar.inert = collapsed;
        sidebarToggle.setAttribute('aria-expanded', String(!collapsed));
    }
});
overlay?.addEventListener('click', closeSidebar);
window.addEventListener('resize', closeSidebar);
document.addEventListener('keydown', event => { if (event.key === 'Escape') closeSidebar(); });
document.querySelector('[data-refresh]')?.addEventListener('click', () => location.reload());

const processingPayoutRefreshInterval = 15000;
const refreshProcessingPayouts = () => {
    const administratorIsBusy = document.visibilityState !== 'visible'
        || document.querySelector('dialog[open]')
        || document.querySelector('input:focus, select:focus, textarea:focus');

    if (administratorIsBusy) {
        window.setTimeout(refreshProcessingPayouts, processingPayoutRefreshInterval);
        return;
    }

    location.reload();
};

if (document.body.dataset.page === 'payouts' && document.querySelector('.badge-processing')) {
    window.setTimeout(refreshProcessingPayouts, processingPayoutRefreshInterval);
}

document.querySelectorAll('[data-auto-submit]').forEach(el => el.addEventListener('change', () => el.form.requestSubmit()));
document.querySelectorAll('[data-open-dialog]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.openDialog)?.showModal()));
document.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
const templateToken = value => {
    const token = document.createElement('code');
    token.className = 'template-token';
    token.dataset.templateToken = value;
    token.contentEditable = 'false';
    token.textContent = value;
    return token;
};
const renderTemplateInline = value => {
    const fragment = document.createDocumentFragment();
    const pattern = /(\{[a-z0-9_]+\})|```([^`\n]+)```|\*([^*\n]+)\*|_([^_\n]+)_/gi;
    let cursor = 0;
    for (const match of value.matchAll(pattern)) {
        if (match.index > cursor) fragment.append(document.createTextNode(value.slice(cursor, match.index)));
        if (match[1]) fragment.append(templateToken(match[1]));
        else {
            const formatted = document.createElement(match[2] ? 'code' : match[3] ? 'strong' : 'em');
            if (match[2]) formatted.className = 'template-format-monospace';
            formatted.append(renderTemplateInline(match[2] || match[3] || match[4]));
            fragment.append(formatted);
        }
        cursor = match.index + match[0].length;
    }
    if (cursor < value.length) fragment.append(document.createTextNode(value.slice(cursor)));
    return fragment;
};
const renderTemplateValue = (container, value) => {
    const fragment = document.createDocumentFragment();
    value.split('\n').forEach(line => {
        const quote = line.startsWith('> ');
        const bullet = /^[-*] /.test(line);
        const block = document.createElement(quote ? 'blockquote' : 'div');
        if (bullet) block.dataset.templateBullet = 'true';
        block.append(renderTemplateInline(line.replace(quote ? /^> / : bullet ? /^[-*] / : /^/, '')));
        fragment.append(block);
    });
    container.replaceChildren(fragment);
};
const renderTemplateEditor = editor => {
    const input = editor.closest('form')?.querySelector('[data-template-input]');
    if (!input) return;
    renderTemplateValue(editor, input.value);
};
const serializeTemplateNode = node => {
    if (node.nodeType === Node.TEXT_NODE) return node.textContent.replace(/\u00a0/g, '');
    if (node.nodeType !== Node.ELEMENT_NODE) return '';
    if (node.matches('[data-template-token]')) return node.dataset.templateToken;
    const content = [...node.childNodes].map(serializeTemplateNode).join('');
    if (node.matches('strong,b')) return `*${content}*`;
    if (node.matches('em,i')) return `_${content}_`;
    if (node.matches('.template-format-monospace,[face="monospace"]')) return `\`\`\`${content}\`\`\``;
    if (node.matches('blockquote')) return `> ${content}`;
    if (node.matches('[data-template-bullet]')) return `- ${content}`;
    if (node.matches('br')) return '\n';
    return content;
};
const syncTemplateEditor = editor => {
    const input = editor.closest('form')?.querySelector('[data-template-input]');
    if (!input) return;
    input.value = [...editor.children].map(serializeTemplateNode).join('\n').replace(/\r\n/g, '\n');
};
const toggleTemplateLines = (editor, range, format, button) => {
    const lines = [...editor.children].filter(line => range.intersectsNode(line));
    if (lines.length === 0) return false;
    const removing = format === 'bullet'
        ? lines.every(line => line.hasAttribute('data-template-bullet'))
        : lines.every(line => line.matches('blockquote'));
    lines.forEach(line => {
        if (format === 'bullet') {
            line.toggleAttribute('data-template-bullet', !removing);
            return;
        }
        const replacement = document.createElement(removing ? 'div' : 'blockquote');
        if (line.hasAttribute('data-template-bullet')) replacement.dataset.templateBullet = 'true';
        replacement.append(...line.childNodes);
        line.replaceWith(replacement);
    });
    syncTemplateEditor(editor);
    button.classList.toggle('is-active', !removing);
    button.setAttribute('aria-pressed', String(!removing));
    return true;
};
const templateFormat = (editor, format, button) => {
    if (!editor) return;
    editor.focus();
    const command = format === 'bullet' ? 'insertUnorderedList' : format === 'quote' ? 'formatBlock' : format === 'monospace' ? 'fontName' : format;
    const selection = window.getSelection();
    const range = selection?.rangeCount ? selection.getRangeAt(0) : null;
    if (!range || !editor.contains(range.commonAncestorContainer)) return;
    if (['bullet', 'quote'].includes(format) && toggleTemplateLines(editor, range, format, button)) return;
    const tokenSelected = range && [...editor.querySelectorAll('[data-template-token]')].some(token => range.intersectsNode(token));
    if (tokenSelected && !range.collapsed && ['bold', 'italic', 'monospace'].includes(format)) {
        const token = [...editor.querySelectorAll('[data-template-token]')].find(candidate => range.intersectsNode(candidate));
        const existing = token.parentElement?.matches('strong,em,.template-format-monospace');
        const wrapper = format === 'bold' ? 'strong' : format === 'italic' ? 'em' : 'code';
        if (existing && ((format === 'bold' && token.parentElement.matches('strong')) || (format === 'italic' && token.parentElement.matches('em')) || (format === 'monospace' && token.parentElement.matches('.template-format-monospace')))) {
            token.parentElement.replaceWith(token);
        } else {
            const formatted = document.createElement(wrapper);
            if (format === 'monospace') formatted.className = 'template-format-monospace';
            token.replaceWith(formatted);
            formatted.append(token);
        }
        syncTemplateEditor(editor);
        button.classList.toggle('is-active', !existing);
        button.setAttribute('aria-pressed', String(!existing));
        return;
    }
    if (tokenSelected && !range.collapsed) return;
    if (format === 'quote') {
        const blockquote = range?.commonAncestorContainer.nodeType === Node.ELEMENT_NODE
            ? range.commonAncestorContainer.closest?.('blockquote')
            : range?.commonAncestorContainer.parentElement?.closest('blockquote');
        document.execCommand(command, false, blockquote ? 'div' : 'blockquote');
    } else if (format === 'monospace') {
        document.execCommand(command, false, 'monospace');
    } else {
        document.execCommand(command, false, null);
    }
    syncTemplateEditor(editor);
    const active = format === 'quote'
        ? Boolean(editor.querySelector('blockquote:focus, blockquote')) && Boolean(window.getSelection()?.anchorNode?.parentElement?.closest('blockquote'))
        : format === 'monospace'
            ? Boolean(window.getSelection()?.anchorNode?.parentElement?.closest('[face="monospace"],.template-format-monospace'))
            : document.queryCommandState(command);
    button.classList.toggle('is-active', active);
    button.setAttribute('aria-pressed', String(active));
};
document.querySelectorAll('[data-template-editor]').forEach(editor => {
    renderTemplateEditor(editor);
    editor.addEventListener('input', () => syncTemplateEditor(editor));
    editor.addEventListener('beforeinput', event => {
        if (!event.inputType.startsWith('delete')) return;
        const selection = window.getSelection();
        const range = selection?.rangeCount ? selection.getRangeAt(0) : null;
        if (range && [...editor.querySelectorAll('[data-template-token]')].some(token => range.intersectsNode(token))) {
            event.preventDefault();
        }
    });
    syncTemplateEditor(editor);
});
document.querySelectorAll('[data-template-preview]').forEach(preview => renderTemplateValue(preview, preview.textContent));
document.querySelectorAll('[data-template-toolbar]').forEach(toolbar => {
    const editor = toolbar.closest('form')?.querySelector('[data-template-editor]');
    toolbar.querySelectorAll('[data-template-format]').forEach(button => {
        button.addEventListener('mousedown', event => event.preventDefault());
        button.setAttribute('aria-pressed', 'false');
        button.addEventListener('click', () => templateFormat(editor, button.dataset.templateFormat, button));
    });
});
document.querySelectorAll('[data-interaction-editor]').forEach(editor => {
    const type = editor.querySelector('[data-interaction-type]');
    const settings = editor.querySelector('[data-interaction-settings]');
    const listButtonField = editor.querySelector('[data-list-button-field]');
    const listButtonInput = listButtonField.querySelector('input');
    const optionsContainer = editor.querySelector('[data-interaction-options]');
    const optionsInput = editor.querySelector('[data-interaction-options-input]');
    const addButton = editor.querySelector('[data-add-interaction-option]');
    const limitText = editor.querySelector('[data-interaction-limit]');
    let options = JSON.parse(optionsInput.value || '[]');

    const sync = () => {
        options = [...optionsContainer.querySelectorAll('[data-interaction-option]')].map(row => ({
            id: row.querySelector('[data-option-id]').value,
            title: row.querySelector('[data-option-title]').value,
            description: row.querySelector('[data-option-description]').value,
        }));
        optionsInput.value = JSON.stringify(options);
    };
    const addOption = (option = {}) => {
        const row = document.createElement('div');
        row.className = 'interaction-option';
        row.dataset.interactionOption = '';
        const makeInput = (attribute, placeholder, value, maxLength) => {
            const input = document.createElement('input');
            input.dataset[attribute] = '';
            input.placeholder = placeholder;
            input.value = value || '';
            input.maxLength = maxLength;
            input.required = true;
            input.addEventListener('input', sync);
            return input;
        };
        row.append(
            makeInput('optionTitle', 'Button or row title', option.title, type.value === 'buttons' ? 20 : 24),
            makeInput('optionId', 'Reply / command ID', option.id, 256),
            makeInput('optionDescription', 'Description (optional)', option.description, 72),
        );
        row.lastElementChild.required = false;
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'icon-button';
        remove.title = 'Remove choice';
        remove.setAttribute('aria-label', 'Remove choice');
        remove.innerHTML = '<i data-lucide="trash-2"></i>';
        remove.addEventListener('click', () => { row.remove(); sync(); update(); });
        row.append(remove);
        optionsContainer.append(row);
        createIcons({ icons: { Trash2 } });
    };
    const render = () => {
        optionsContainer.replaceChildren();
        options.forEach(addOption);
        sync();
    };
    const update = () => {
        const enabled = type.value !== 'none';
        const maximum = type.value === 'buttons' ? 3 : 10;
        settings.hidden = !enabled;
        listButtonField.hidden = type.value !== 'list';
        listButtonInput.disabled = type.value !== 'list';
        optionsContainer.querySelectorAll('input').forEach(input => { input.disabled = !enabled; });
        addButton.disabled = !enabled || optionsContainer.children.length >= maximum;
        limitText.textContent = type.value === 'buttons'
            ? 'Use 1 to 3 choices. Button titles can contain up to 20 characters.'
            : 'Use 1 to 10 choices. The action ID is the command Kijumbe receives.';
        optionsContainer.querySelectorAll('[data-option-title]').forEach(input => { input.maxLength = type.value === 'buttons' ? 20 : 24; });
    };
    addButton.addEventListener('click', () => { addOption(); sync(); update(); });
    type.addEventListener('change', () => {
        if (type.value !== 'none' && optionsContainer.children.length === 0) addOption();
        sync();
        update();
    });
    render();
    update();
});
document.querySelectorAll('[data-toggle-password]').forEach(button => button.addEventListener('click', () => {
    const input = document.getElementById(button.dataset.togglePassword);
    input.type = input.type === 'password' ? 'text' : 'password';
    button.setAttribute('aria-label', input.type === 'password' ? 'Show password' : 'Hide password');
    button.title = button.getAttribute('aria-label');
    button.setAttribute('aria-pressed', String(input.type === 'text'));
}));
document.querySelectorAll('[data-decision-type]').forEach(select => select.addEventListener('change', () => {
    const field = select.form.querySelector('[data-grace-field]');
    field.hidden = select.value !== 'extend_grace';
    field.querySelector('input').required = !field.hidden;
    field.querySelector('input').disabled = field.hidden;
}));

document.querySelectorAll('[data-api-form]').forEach(form => form.addEventListener('submit', async event => {
    event.preventDefault();
    const button = form.querySelector('[type="submit"]');
    if (button.disabled) return;
    const errorBox = form.querySelector('.form-error');
    errorBox.hidden = true;
    button.disabled = true;
    const original = button.textContent;
    button.textContent = 'Saving...';
    try {
        const payload = Object.fromEntries(new FormData(form));
        const method = payload._method || 'POST';
        delete payload._method;
        delete payload._token;
        form.querySelectorAll('[data-json]').forEach(input => {
            payload[input.name] = input.value.trim() ? JSON.parse(input.value) : [];
        });
        form.querySelectorAll('[data-eat]:not(:disabled)').forEach(input => {
            if (input.value) payload[input.name] = input.value + ':00+03:00';
        });
        if ('opted_in' in payload) payload.opted_in = payload.opted_in === '1';
        if (form.querySelector('[data-cycle-members]')) {
            payload.members = [...form.querySelectorAll('[data-participant]')].filter(row => row.querySelector('[data-member-id]').checked).map(row => ({
                group_member_id: Number(row.querySelector('[data-member-id]').dataset.memberId),
                payout_position: Number(row.querySelector('[data-position]').value),
            }));
        }
        const response = await fetch(form.action, {
            method, credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify(payload),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const message = response.status === 419 ? 'Your session expired. Refresh this page and try again.' : response.status === 401 ? 'Please sign in again.' : Object.values(data.errors || {}).flat().join(' ') || data.message || 'The request could not be completed. Please try again.';
            throw new Error(message);
        }
        sessionStorage.setItem('kijumbe-notice', 'Saved successfully.');
        const redirect = form.dataset.redirect;
        if (redirect) {
            location.assign(redirect.replace('{id}', encodeURIComponent(data.data.id)));
        } else {
            sessionStorage.setItem('kijumbe-scroll-y', String(window.scrollY));
            location.reload();
        }
    } catch (error) {
        errorBox.textContent = error instanceof SyntaxError ? 'Template components must be valid JSON.' : error.message;
        errorBox.hidden = false;
        errorBox.focus();
        button.disabled = false;
        button.textContent = original;
    }
}));
const notice = sessionStorage.getItem('kijumbe-notice');
if (notice && document.querySelector('#toast')) {
    const toast = document.querySelector('#toast');
    toast.textContent = notice;
    toast.hidden = false;
    sessionStorage.removeItem('kijumbe-notice');
    setTimeout(() => { toast.hidden = true; }, 4000);
}
const savedScrollValue = sessionStorage.getItem('kijumbe-scroll-y');
const savedScrollY = Number(savedScrollValue);
if (savedScrollValue !== null) {
    sessionStorage.removeItem('kijumbe-scroll-y');
    if (Number.isFinite(savedScrollY)) requestAnimationFrame(() => window.scrollTo({ top: savedScrollY, behavior: 'auto' }));
}

Chart.defaults.font.family = "'Instrument Sans', sans-serif";
Chart.defaults.font.size = 10;
Chart.defaults.color = '#858d90';
const reduceChartMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const chartAnimation = duration => reduceChartMotion ? false : { duration, easing: 'easeOutQuart' };
const cashChart = document.querySelector('[data-cash-chart]');
if (cashChart) {
    const rows = JSON.parse(cashChart.dataset.cashChart);
    const makeFill = context => {
        const chart = context.chart;
        const area = chart.chartArea;
        if (!area) return 'rgba(49, 142, 123, 0.08)';
        const gradient = chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
        gradient.addColorStop(0, context.dataset.borderColor === '#318e7b' ? 'rgba(49, 142, 123, 0.18)' : 'rgba(37, 43, 45, 0.12)');
        gradient.addColorStop(1, 'rgba(255, 255, 255, 0)');
        return gradient;
    };
    new Chart(cashChart, {
        type: 'line',
        data: {
            labels: rows.map(row => new Date(row.date + 'T12:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })),
            datasets: [
                { label: 'Collections', data: rows.map(row => row.collected), borderColor: '#318e7b', backgroundColor: makeFill, fill: true, tension: 0.42, borderWidth: 2.4, pointRadius: 0, pointHoverRadius: 4, pointHoverBorderWidth: 2, pointHoverBackgroundColor: '#fff', pointHoverBorderColor: '#318e7b' },
                { label: 'Payouts', data: rows.map(row => row.paid), borderColor: '#252b2d', backgroundColor: makeFill, fill: true, tension: 0.42, borderWidth: 2.4, pointRadius: 0, pointHoverRadius: 4, pointHoverBorderWidth: 2, pointHoverBackgroundColor: '#fff', pointHoverBorderColor: '#252b2d' },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: chartAnimation(1200),
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#191b1d',
                    padding: 11,
                    cornerRadius: 6,
                    displayColors: true,
                    callbacks: { label: ctx => `${ctx.dataset.label}: TZS ${ctx.parsed.y.toLocaleString()}` },
                },
            },
            scales: {
                x: { grid: { display: false }, border: { display: false }, ticks: { maxTicksLimit: 7, maxRotation: 0, padding: 8 } },
                y: { beginAtZero: true, border: { display: false }, grid: { color: '#edf0ef', drawTicks: false }, ticks: { padding: 8 } },
            },
            elements: { line: { capBezierPoints: true } },
        },
    });
}
const groupChart = document.querySelector('[data-group-chart]');
if (groupChart) {
    const groups = JSON.parse(groupChart.dataset.groupChart);
    new Chart(groupChart, { type: 'doughnut', data: { labels: ['Active', 'Completed', 'Draft', 'Archived'], datasets: [{ data: [groups.active || 0, groups.completed || 0, groups.draft || 0, groups.archived || 0], backgroundColor: ['#318e7b', '#252b2d', '#d9ab58', '#c9cecf'], borderWidth: 3, hoverOffset: 4 }] }, options: { responsive: true, maintainAspectRatio: false, cutout: '76%', animation: reduceChartMotion ? false : { animateRotate: true, animateScale: true, duration: 1100, easing: 'easeOutQuart' }, plugins: { legend: { display: false } } } });
}
