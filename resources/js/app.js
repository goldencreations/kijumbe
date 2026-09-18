import { createIcons, Orbit, ChevronsUpDown, LayoutDashboard, Layers, Users, Wallet, ArrowUpRight, CircleAlert, MessageCircle, History, LogOut, PanelLeft, ChevronRight, RefreshCw, Plus, Activity, CalendarDays, ArrowDownLeft, ArrowRight, CalendarRange, ChartPie, ChartNoAxesCombined, CalendarClock, UserPlus, Send, Search, SlidersHorizontal, Settings2, Eye, Inbox, ArrowLeft, Play, FileCheck2, X, ShieldCheck, Trash2 } from 'lucide';
import Chart from 'chart.js/auto';

createIcons({ icons: { Orbit, ChevronsUpDown, LayoutDashboard, Layers, Users, Wallet, ArrowUpRight, CircleAlert, MessageCircle, History, LogOut, PanelLeft, ChevronRight, RefreshCw, Plus, Activity, CalendarDays, ArrowDownLeft, ArrowRight, CalendarRange, ChartPie, ChartNoAxesCombined, CalendarClock, UserPlus, Send, Search, SlidersHorizontal, Settings2, Eye, Inbox, ArrowLeft, Play, FileCheck2, X, ShieldCheck, Trash2 } });
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
        location.assign(redirect ? redirect.replace('{id}', encodeURIComponent(data.data.id)) : location.href);
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

Chart.defaults.font.family = "'Instrument Sans', sans-serif";
Chart.defaults.font.size = 10;
Chart.defaults.color = '#858d90';
const cashChart = document.querySelector('[data-cash-chart]');
if (cashChart) {
    const rows = JSON.parse(cashChart.dataset.cashChart);
    new Chart(cashChart, {
        type: 'bar',
        data: {
            labels: rows.map(row => new Date(row.date + 'T12:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })),
            datasets: [
                { label: 'Collections', data: rows.map(row => row.collected), backgroundColor: '#318e7b', borderRadius: 3, maxBarThickness: 15 },
                { label: 'Payouts', data: rows.map(row => row.paid), backgroundColor: '#252b2d', borderRadius: 3, maxBarThickness: 15 },
            ],
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => `${ctx.dataset.label}: TZS ${ctx.parsed.y.toLocaleString()}` } } }, scales: { x: { grid: { display: false }, border: { display: false }, ticks: { maxTicksLimit: 7, maxRotation: 0 } }, y: { beginAtZero: true, border: { display: false }, grid: { color: '#f0f2f1' } } } },
    });
}
const groupChart = document.querySelector('[data-group-chart]');
if (groupChart) {
    const groups = JSON.parse(groupChart.dataset.groupChart);
    new Chart(groupChart, { type: 'doughnut', data: { labels: ['Active', 'Draft', 'Archived'], datasets: [{ data: [groups.active || 0, groups.draft || 0, groups.archived || 0], backgroundColor: ['#318e7b', '#d9ab58', '#c9cecf'], borderWidth: 3, hoverOffset: 4 }] }, options: { responsive: true, maintainAspectRatio: false, cutout: '76%', plugins: { legend: { display: false } } } });
}
