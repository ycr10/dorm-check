'use strict';

(() => {
  const API = new URL('api/index.php', location.href).pathname;
  const $ = (selector, node = document) => node.querySelector(selector);
  const app = $('#app');
  const modal = $('#modal');
  const state = { user: null, csrf: '', date: '', serverTime: '', route: '', generation: 0, poll: null, roster: null, tasks: [], settings: {}, authTab: 'login', refreshAt: '', busy: false, config: {}, filters: {} };
  const paths = {
    moon: 'M20.9 13.3A9 9 0 0 1 10.7 3.1 9 9 0 1 0 20.9 13.3Z',
    home: 'm3 10 9-7 9 7v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V10Z M9 21v-8h6v8',
    grid: 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
    people: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M16 3a4 4 0 0 1 0 8 M22 21v-2a4 4 0 0 0-3-3.9 M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
    check: 'm5 12 4 4L19 6',
    shield: 'M12 22s9-4 9-11V5l-9-3-9 3v6c0 7 9 11 9 11Z m-4-10 3 3 5-6',
    clock: 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z M12 7v5l3 2',
    arrow: 'M5 12h14 m-6-6 6 6-6 6',
    chevron: 'm9 6 6 6-6 6',
    calendar: 'M8 2v4 M16 2v4 M3 10h18 M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z',
    bell: 'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9 M10 21h4',
    file: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z M14 2v6h6 M8 13h8 M8 17h5',
    settings: 'M12 8a4 4 0 1 1 0 8 4 4 0 0 1 0-8Z M9 3l1-1h4l1 1 .4 2 2 .9 2-.7 2 3-.8 1.8.1 2.3 1.3 1.5-2 3-2.1-.3-2 1.2-.5 2.3h-4l-.5-2.3-2-1.2-2.1.3-2-3 1.3-1.5.1-2.3L2 8.5l2-3 2 .7 2-.9L9 3Z',
    log: 'M8 3h12v18H4V7 M8 3v4H4 M8 11h8 M8 15h8 M8 18h5',
    lock: 'M5 10h14v11H5z M8 10V7a4 4 0 0 1 8 0v3 M12 14v3',
    logout: 'M9 21H4V3h5 M9 12h12 m-4-4 4 4-4 4',
    refresh: 'M21 3v6h-6 M3 21v-6h6 M5.5 7a8 8 0 0 1 13.2-1L21 9 M3 15l2.3 3A8 8 0 0 0 18.5 17',
    download: 'M12 3v12 m-5-5 5 5 5-5 M4 16v5h16v-5',
    upload: 'M12 16V4 m-5 5 5-5 5 5 M4 16v5h16v-5',
    plus: 'M12 5v14 M5 12h14',
    search: 'M21 21l-5-5 M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z',
    eye: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
    close: 'm6 6 12 12 M18 6 6 18',
    menu: 'M4 6h16 M4 12h16 M4 18h16',
    warning: 'm12 3 10 18H2L12 3Z M12 9v5 M12 17h.01',
    info: 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z M12 11v6 M12 7h.01',
    clipboard: 'M9 3H5v19h14V3h-4 M9 2h6v4H9z M8 11h8 M8 15h8',
    edit: 'm16 3 5 5-13 13H3v-5L16 3Z M14 5l5 5',
    sun: 'M12 3V1 M12 23v-2 M3 12H1 M23 12h-2 M4.2 4.2l-1.4-1.4 M21.2 21.2l-1.4-1.4 M4.2 19.8l-1.4 1.4 M21.2 2.8l-1.4 1.4 M17 12a5 5 0 1 1-10 0 5 5 0 0 1 10 0Z',
    mail: 'M3 5h18v14H3z m0 0 9 8 9-8',
    help: 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z M9.1 9a3 3 0 0 1 5.8 1c0 2-3 2-3 4 M12 17h.01',
    key: 'M14 8a5 5 0 1 1-5 5l-7 7v2h4v-3h3v-3l2-2 M18 6h.01'
  };
  const statusMap = { present: ['已在寝', 'green'], away: ['外出未归', 'orange'], exempt: ['无需返寝', 'purple'], unknown: ['待核实', 'red'], unreported: ['未登记', ''] };
  const followupMap = { pending: '待联系', contacted: '已联系', safe: '已确认安全', waiting: '待返寝', closed: '已结束' };

  function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([key, value]) => {
      if (value === null || value === undefined || value === false) return;
      if (key === 'class') node.className = value;
      else if (key === 'text') node.textContent = value;
      else if (key.startsWith('on') && typeof value === 'function') node.addEventListener(key.slice(2), value);
      else if (key === 'dataset') Object.entries(value).forEach(([k, v]) => { node.dataset[k] = v; });
      else if (key === 'checked' || key === 'selected' || key === 'disabled' || key === 'hidden' || key === 'required') node[key] = !!value;
      else if (key === 'value') node.value = value;
      else node.setAttribute(key, value === true ? '' : String(value));
    });
    children.flat(Infinity).forEach(child => { if (child !== null && child !== undefined && child !== false) node.append(child instanceof Node ? child : document.createTextNode(String(child))); });
    return node;
  }
  function icon(name) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('aria-hidden', 'true');
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path'); path.setAttribute('d', paths[name] || paths.info); svg.append(path); return svg;
  }
  function button(label, handler, kind = '', glyph = '') { return el('button', { type: 'button', class: `button ${kind}`, onclick: handler }, glyph ? icon(glyph) : null, label); }
  function iconButton(name, label, handler) { return el('button', { type: 'button', class: 'icon-button', title: label, 'aria-label': label, onclick: handler }, icon(name)); }
  function text(tag, value, cls = '') { return el(tag, { class: cls, text: value ?? '' }); }
  function field(label, input, help = '') { return el('label', { class: 'field' }, text('span', label, 'field-label'), input, help ? text('span', help, 'inline-hint') : null); }
  function input(type = 'text', attrs = {}) { return el('input', { class: 'input', type, ...attrs }); }
  function select(options, value, attrs = {}) { const node = el('select', attrs, options.map(option => { const [val, label] = Array.isArray(option) ? option : [option, option]; return el('option', { value: val, text: label, selected: String(val) === String(value) }); })); return node; }
  function passwordInput(attrs = {}) { const box = el('div', { class: 'password-wrap' }); const node = input('password', attrs); box.append(node, iconButton('eye', '显示或隐藏密码', () => { node.type = node.type === 'password' ? 'text' : 'password'; })); return { node, box }; }
  function tag(label, color = '', dot = false) { return el('span', { class: `tag ${color}` }, dot ? el('span', { class: 'dot' }) : null, label); }
  function statusTag(status) { const data = statusMap[status] || [status || '未登记', '']; return tag(data[0], data[1], true); }
  function notice(message, kind = '') { return text('div', message, `notice ${kind}`); }
  function empty(title, message, action = null, glyph = 'home') { return el('div', { class: 'empty-state' }, icon(glyph), text('h3', title), text('p', message), action); }
  function panel(title, subtitle = '', actions = null) { const body = el('div', { class: 'panel-body' }); const node = el('section', { class: 'panel' }, el('div', { class: 'panel-header' }, el('div', {}, text('h2', title), subtitle ? text('p', subtitle) : null), actions), body); return { node, body }; }
  function table(headers, rows) { return el('div', { class: 'table-wrap' }, el('table', {}, el('thead', {}, el('tr', {}, headers.map(x => el('th', { scope: 'col' }, x)))), el('tbody', {}, rows))); }
  function row(...cells) { return el('tr', {}, cells.map(cell => el('td', {}, cell))); }
  function array(value) { return Array.isArray(value) ? value : []; }
  function roles() { return array(state.user?.roles).concat(state.user?.role || []).map(v => String(v).toLowerCase()); }
  function isAdmin() { return roles().some(v => ['admin', 'super_admin', 'system_admin'].includes(v)); }
  function isManager() { return isAdmin() || roles().some(v => ['committee', 'counselor', 'manager', 'monitor', 'secretary'].includes(v)); }
  function needsMfa() { return !!(state.user?.totp_required && !state.user?.totp_enabled); }
  function roleName() { return isAdmin() ? '系统管理员' : isManager() ? '班委管理' : '寝室长'; }
  function dateValue(value = state.serverTime) { try { const raw = value || Date.now(); const normalized = typeof raw === 'string' && /^\d{4}-\d{2}-\d{2} /.test(raw) ? raw.replace(' ', 'T') + '+08:00' : raw; return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(normalized)); } catch { return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Shanghai' }).format(new Date()); } }
  function dateLabel(value = state.date) { if (!value) return ''; const d = new Date(`${value}T12:00:00+08:00`); return new Intl.DateTimeFormat('zh-CN', { month: 'long', day: 'numeric', weekday: 'long', timeZone: 'Asia/Shanghai' }).format(d); }
  function timeLabel(value, full = false) { if (!value) return '—'; const normalized = String(value).includes('T') ? value : String(value).replace(' ', 'T') + (/[zZ]|[+-]\d\d:\d\d$/.test(String(value)) ? '' : '+08:00'); const d = new Date(normalized); if (Number.isNaN(d.getTime())) return String(value); return new Intl.DateTimeFormat('zh-CN', { timeZone: 'Asia/Shanghai', ...(full ? { year: 'numeric', month: '2-digit', day: '2-digit', second: '2-digit' } : {}), hour: '2-digit', minute: '2-digit', hour12: false }).format(d); }
  function roomName(task) { return task.room_name || task.room_label || [task.building || task.building_name, task.room_no || task.room_number].filter(Boolean).join(' · ') || (typeof task.room === 'string' ? task.room : task.room?.name || task.room?.room_no) || `寝室 ${task.room_id || task.id}`; }
  function nameOf(member) { return member.name || member.student_name || '未命名同学'; }
  function person(member) { const name = nameOf(member); return el('div', { class: 'person-cell' }, text('span', name.slice(-2), 'avatar'), el('div', {}, text('strong', name), text('span', member.student_no || ''))); }
  function pageHeader(title, subtitle, kicker = '', date = true) { const right = date ? el('div', { class: 'date-control' }, text('label', '查寝日期'), input('date', { value: state.date, 'aria-label': '查寝日期', onchange: e => { state.date = e.target.value || dateValue(); loadRoute(); } })) : null; return el('header', { class: 'page-header' }, el('div', {}, kicker ? text('div', kicker, 'page-kicker') : null, text('h1', title), text('p', subtitle, 'subtitle')), right); }
  function toast(message, error = false) { const node = el('div', { class: `toast ${error ? 'error' : ''}`, role: 'status' }, icon(error ? 'warning' : 'check'), text('span', message)); $('#toasts').append(node); setTimeout(() => node.remove(), error ? 8000 : 4500); }
  function showError(target, error) { if (!target?.isConnected) { toast(error.message || String(error), true); return; } const old = $('.error-box', target); if (old) old.remove(); const box = text('div', error.message || String(error), 'error-box'); box.setAttribute('role', 'alert'); target.prepend(box); }
  function errorMessage(body, fallback = '请求未完成，请稍后重试。') { return typeof body?.error === 'string' ? body.error : body?.error?.message || body?.message || fallback; }

  async function api(action, body = null, options = {}) {
    const url = new URL(API, location.origin); url.searchParams.set('action', action);
    Object.entries(options.query || {}).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') url.searchParams.set(k, String(v)); });
    const headers = { Accept: 'application/json' };
    if (body !== null) { headers['Content-Type'] = 'application/json'; headers['X-CSRF-Token'] = state.csrf; }
    if (options.passive) headers['X-Passive-Request'] = '1';
    let response;
    try { response = await fetch(url, { method: body === null ? 'GET' : 'POST', headers, body: body === null ? undefined : JSON.stringify(body), credentials: 'same-origin', cache: 'no-store', signal: options.signal }); }
    catch (error) { if (error.name === 'AbortError') throw error; throw new Error('未能连接服务器。当前操作尚未确认保存，请检查网络后重试。'); }
    if (options.download && response.ok && !response.headers.get('Content-Type')?.includes('application/json')) return response;
    let result;
    try { result = await response.json(); } catch { throw new Error('服务器没有返回有效数据，请检查部署配置或服务日志。'); }
    if (result.csrf) state.csrf = result.csrf;
    if (result.data?.csrf) state.csrf = result.data.csrf;
    if (!response.ok || result.ok === false || result.success === false) {
      const error = new Error(errorMessage(result)); error.code = result.error?.code || result.code || String(response.status); error.status = response.status;
      if (response.status === 401 && action !== 'login') { clearInterval(state.poll); state.user = null; renderAuth('login'); toast('登录状态已过期，请重新登录。', true); }
      if (error.code === 'MFA_SETUP_REQUIRED') { if (state.user) { state.user.totp_required = true; state.user.totp_enabled = false; navigate('account'); } }
      throw error;
    }
    const data = result.data ?? result;
    if (data.server_time || result.server_time) state.serverTime = data.server_time || result.server_time;
    if (data.config) state.config = data.config;
    return data;
  }
  async function busy(node, work) { const old = node.textContent; node.disabled = true; try { return await work(); } finally { if (node.isConnected) { node.disabled = false; node.textContent = old; } } }
  function openModal(title, subtitle, body, wide = false) { modal.replaceChildren(); modal.className = `modal ${wide ? 'wide' : ''}`; modal.append(el('div', { class: 'modal-head' }, el('div', {}, text('h2', title), subtitle ? text('p', subtitle) : null), iconButton('close', '关闭', closeModal)), el('div', { class: 'modal-body' }, body)); if (!modal.open) modal.showModal(); return modal; }
  function closeModal() { if (modal.open) modal.close(); modal.replaceChildren(); }
  function confirmAction(title, message, label = '确认', danger = false) { return new Promise(resolve => { const content = el('div', {}, text('p', message, 'muted')); const cancel = button('取消', () => { resolve(false); closeModal(); }, 'secondary'); const yes = button(label, () => { resolve(true); closeModal(); }, danger ? 'danger' : ''); content.append(el('div', { class: 'modal-foot' }, cancel, yes)); openModal(title, '', content); modal.addEventListener('close', () => resolve(false), { once: true }); }); }
  function requestReauth() { return new Promise((resolve, reject) => {
    const password = passwordInput({ autocomplete: 'current-password', required: true }); const otp = input('text', { autocomplete: 'one-time-code', inputmode: 'numeric', pattern: '[0-9]{6}', maxlength: 6, placeholder: '新的六位动态码' });
    const form = el('form', {}, notice('为保护全班信息，请确认是你本人正在操作。'), field('当前密码', password.box), state.user?.totp_enabled ? field('验证器动态码', otp) : null);
    let completed = false; const cancel = button('取消', closeModal, 'secondary'); const submit = el('button', { type: 'submit', class: 'button' }, '验证并继续');
    form.append(el('div', { class: 'modal-foot' }, cancel, submit));
    form.addEventListener('submit', async e => { e.preventDefault(); await busy(submit, async () => { try { await api('reauth', { password: password.node.value, otp: otp.value.trim() }); completed = true; closeModal(); resolve(true); } catch (error) { showError(form, error); } }); });
    openModal('安全验证', '敏感操作需要近期身份验证', form);
    modal.addEventListener('close', () => { if (!completed) { const error = new Error('已取消操作'); error.silent = true; reject(error); } }, { once: true });
  }); }
  async function secure(action, payload, options = {}) { try { return await api(action, payload, options); } catch (error) { if (['REAUTH_REQUIRED', 'RECENT_AUTH_REQUIRED', 'STEP_UP_REQUIRED'].includes(error.code)) { await requestReauth(); return api(action, payload, options); } throw error; } }
  function idempotency() { return crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${crypto.getRandomValues(new Uint32Array(2)).join('-')}`; }
  function brand() { return el('div', { class: 'brand' }, el('span', { class: 'brand-mark' }, icon('moon')), el('span', {}, '归寝', text('small', 'NIGHT CHECK'))); }
  function toggleTheme() { const dark = document.documentElement.dataset.theme === 'dark' || (!document.documentElement.dataset.theme && matchMedia('(prefers-color-scheme:dark)').matches); const theme = dark ? 'light' : 'dark'; document.documentElement.dataset.theme = theme; try { localStorage.setItem('dorm-theme', theme); } catch {} }

  function clearPrivateState() { state.user = null; state.roster = null; state.tasks = []; state.settings = {}; state.filters = {}; state.generation++; clearInterval(state.poll); closeModal(); }
  function renderAuth(tab = 'login') {
    clearPrivateState(); state.authTab = tab; app.setAttribute('aria-busy', 'false');
    const page = el('div', { class: 'auth-page' });
    page.append(el('header', { class: 'auth-top' }, brand(), iconButton('sun', '切换明暗外观', toggleTheme)));
    const illustration = el('div', { class: 'night-illustration', 'aria-hidden': 'true' });
    [15, 18, 12].forEach((count, index) => illustration.append(el('div', { class: 'building-illustration' }, Array.from({ length: count }, (_, i) => el('span', { class: `window-light ${(i + index) % 3 ? 'on' : ''}` })))));
    illustration.append(el('span', { class: 'moon-illustration' }, icon('moon')));
    const story = el('section', { class: 'auth-story' }, text('div', 'A GOOD NIGHT, TOGETHER', 'eyebrow'), el('h1', {}, '每一份安心，', el('br'), '从平安归寝开始。'), text('p', '记录今晚的去向，及时更新返寝情况。让寝室长少一点重复，让每位同学多一份安心。', 'lead'), illustration, el('div', { class: 'story-footnote' }, icon('shield'), '只为确认安全，不关联考核成绩。'));
    const titles = { login: ['欢迎回来', '请使用本人学号登录，进入授权的查寝工作区。'], activate: ['启用你的账号', '输入管理员单独交付的激活码，设置个人密码。'], reset: ['重置个人密码', '请先联系管理员核实身份并领取一次性重置码。'] };
    const card = el('section', { class: 'auth-card', 'aria-label': titles[tab][0] }, text('h2', titles[tab][0]), text('p', titles[tab][1], 'intro'));
    const tabs = el('div', { class: 'auth-tabs', role: 'tablist', 'aria-label': '账号操作' });
    [['login', '学号登录'], ['activate', '首次激活'], ['reset', '重置密码']].forEach(([key, title]) => tabs.append(el('button', { type: 'button', class: `auth-tab ${key === tab ? 'active' : ''}`, role: 'tab', 'aria-selected': key === tab ? 'true' : 'false', onclick: () => renderAuth(key) }, title)));
    card.append(tabs);
    const studentNo = input('text', { autocomplete: 'username', required: true, placeholder: '请输入完整学号', maxlength: 64, spellcheck: 'false' });
    const password = passwordInput({ autocomplete: tab === 'login' ? 'current-password' : 'new-password', required: true, placeholder: tab === 'login' ? '请输入个人密码' : '设置 15—64 个字符的密码', minlength: tab === 'login' ? 1 : 15, maxlength: 128 });
    const code = input('text', { autocomplete: 'one-time-code', required: tab !== 'login', placeholder: tab === 'activate' ? '请输入一次性激活码' : '请输入一次性重置码' });
    const otp = input('text', { autocomplete: 'one-time-code', inputmode: 'numeric', placeholder: '请输入验证器动态码或恢复码' });
    const otpField = field('二次验证', otp, '已启用两步验证的账号需输入动态码或恢复码。'); otpField.hidden = true;
    const repeat = passwordInput({ autocomplete: 'new-password', required: tab !== 'login', placeholder: '再次输入新密码', maxlength: 128 });
    const form = el('form', {}, field('学号', studentNo), tab !== 'login' ? field(tab === 'activate' ? '激活码' : '重置码', code) : null, field(tab === 'login' ? '密码' : '新密码', password.box), tab !== 'login' ? field('确认密码', repeat.box) : null, otpField);
    const submit = el('button', { type: 'submit', class: 'button full' }, tab === 'login' ? '安全登录' : tab === 'activate' ? '激活账号' : '重置密码', icon('arrow'));
    form.append(submit);
    if (tab === 'login') form.append(text('p', '账号由管理员建立。忘记密码时，请通过上方入口重置。', 'auth-footer'));
    else form.append(text('p', '请设置此系统专用密码，不使用学号、生日或通用密码。', 'auth-footer'));
    form.addEventListener('submit', async e => {
      e.preventDefault(); if (!form.reportValidity()) return;
      if (tab !== 'login' && (Array.from(password.node.value).length < 15 || Array.from(password.node.value).length > 64)) { showError(form, new Error('密码请使用 15—64 个字符。')); return; }
      if (tab !== 'login' && password.node.value !== repeat.node.value) { showError(form, new Error('两次输入的密码不一致。')); return; }
      await busy(submit, async () => {
        try {
          if (!state.csrf) await api('session');
          const payload = { student_no: studentNo.value.trim(), password: password.node.value, ...(tab === 'login' ? { otp: otp.value.trim() } : { code: code.value.trim() }) };
          const data = await api(tab === 'login' ? 'login' : tab === 'activate' ? 'activate' : 'reset_password', payload);
          if (data.needs_totp || data.requires_totp) { otpField.hidden = false; otp.required = true; otp.focus(); toast('请输入验证器动态码后再次登录。'); return; }
          if (tab !== 'login') { toast(tab === 'activate' ? '账号已激活，请使用学号和新密码登录。' : '密码已重置，旧会话已失效，请重新登录。'); renderAuth('login'); return; }
          state.user = data.user || (await api('session')).user;
          if (!state.user) throw new Error('登录未完成，请重试。');
          toast('登录成功，欢迎回来。'); renderShell(); navigate(needsMfa() ? 'account' : isManager() ? 'dashboard' : 'today');
        } catch (error) { if (['TOTP_REQUIRED', 'MFA_REQUIRED'].includes(error.code)) { otpField.hidden = false; otp.required = true; otp.focus(); } showError(form, error); }
      });
    });
    card.append(form); page.append(el('main', { id: 'main', class: 'auth-content' }, story, card), el('footer', { class: 'auth-bottom' }, text('span', '归寝 · 班级晚间查寝'), text('span', '学号登录 · 最小授权 · 操作留痕')));
    app.replaceChildren(page);
  }

  const routes = {
    dashboard: ['今日总览', 'grid'], today: ['我的查寝', 'home'], followups: ['异常跟进', 'bell'], reports: ['历史日报', 'file'], roster: ['名单与授权', 'people'], settings: ['系统设置', 'settings'], audit: ['操作记录', 'log'], account: ['我的账号', 'shield']
  };
  function navigate(route) { if (location.hash.slice(1) === route) loadRoute(); else location.hash = route; }
  function renderShell() {
    clearInterval(state.poll);
    const shell = el('div', { class: 'shell' }); const side = el('aside', { class: 'sidebar', 'aria-label': '主要导航' }, brand(), text('div', '日常工作', 'sidebar-section-label'));
    const nav = el('nav', { class: 'nav-list' });
    const names = isManager() ? ['dashboard', 'today', 'followups', 'reports', 'roster', ...(isAdmin() ? ['settings', 'audit'] : [])] : ['today'];
    names.forEach(key => nav.append(el('button', { type: 'button', class: 'nav-item', dataset: { route: key }, onclick: () => { shell.classList.remove('menu-open'); navigate(key); } }, icon(routes[key][1]), routes[key][0])));
    side.append(nav, el('div', { class: 'sidebar-spacer' }), el('div', { class: 'sidebar-note' }, text('strong', '每一位同学，都值得被关心。'), '查寝只为确认安全。请据实登记，及时更新返寝情况。'));
    const name = state.user?.name || state.user?.student_no || '我的账号';
    side.append(el('button', { type: 'button', class: 'profile-button', onclick: () => { shell.classList.remove('menu-open'); navigate('account'); } }, text('span', name.slice(-2), 'avatar'), el('span', { class: 'profile-info' }, text('strong', name), text('span', `${roleName()} · ${state.user?.student_no || ''}`)), icon('chevron')));
    const top = el('header', { class: 'topbar' }, el('div', { class: 'topbar-left' }, el('button', { type: 'button', class: 'icon-button mobile-menu', 'aria-label': '打开导航', onclick: () => shell.classList.toggle('menu-open') }, icon('menu')), text('span', '班级晚间查寝', 'topbar-title')), el('div', { class: 'topbar-right' }, el('span', { class: 'clock-chip' }, icon('clock'), text('span', `北京时间 · 每晚 ${state.config.check_time || '22:30'}`)), iconButton('refresh', '刷新当前页面', () => loadRoute()), iconButton('sun', '切换明暗外观', toggleTheme), iconButton('logout', '退出登录', doLogout)));
    shell.append(el('div', { class: 'mobile-mask', onclick: () => shell.classList.remove('menu-open') }), side, top, el('main', { id: 'main', class: 'content', tabindex: '-1' })); app.replaceChildren(shell); app.setAttribute('aria-busy', 'false');
    state.poll = setInterval(() => { if (state.user && document.visibilityState === 'visible' && !modal.open && !document.activeElement?.matches('input,select,textarea') && ['dashboard', 'today', 'followups'].includes(state.route)) loadRoute(true); }, 20000);
  }
  async function doLogout() { try { await api('logout', {}); state.user = null; clearInterval(state.poll); closeModal(); renderAuth('login'); } catch (error) { toast(error.message, true); } }
  async function loadRoute(passive = false) {
    if (!state.user) return;
    let route = location.hash.slice(1) || (isManager() ? 'dashboard' : 'today'); if (!routes[route]) route = 'today';
    if (needsMfa()) route = 'account';
    if (!isManager() && !['today', 'account'].includes(route)) route = 'today';
    if (!isAdmin() && ['settings', 'audit'].includes(route)) route = 'account';
    state.route = route; const generation = ++state.generation;
    document.querySelectorAll('.nav-item').forEach(n => n.classList.toggle('active', n.dataset.route === route));
    const main = $('#main'); if (!main) return;
    if (!passive) main.replaceChildren(el('div', { class: 'empty-state' }, el('span', { class: 'spinner' }), text('p', '正在读取最新信息…')));
    try {
      const views = { dashboard: viewDashboard, today: viewToday, followups: viewFollowups, reports: viewReports, roster: viewRoster, settings: viewSettings, audit: viewAudit, account: viewAccount };
      const node = await views[route]({ passive }); if (!state.user || generation !== state.generation) return;
      main.replaceChildren(node); state.refreshAt = new Date().toISOString();
    } catch (error) {
      if (error.silent || error.name === 'AbortError' || generation !== state.generation || !state.user) return;
      if (passive) { const status = $('[data-refresh-status]'); if (status) status.textContent = '自动刷新失败，请检查网络；当前仍显示上次结果。'; }
      else main.replaceChildren(pageHeader(routes[route][0], '当前信息未能读取。', '', false), el('section', { class: 'panel' }, empty('暂时无法读取', error.message, button('重新连接', () => loadRoute(), '', 'refresh'), 'warning')));
    }
  }

  // 业务页面在同一作用域内调用统一的认证、CSRF 和错误处理。

  async function viewAccount() {
    const user = state.user; const wrap = el('div', {}, pageHeader('我的账号', '管理个人密码、登录会话与身份验证。', 'ACCOUNT & SECURITY', false));
    if (needsMfa()) wrap.append(notice('请先绑定验证器。完成二次验证设置后，才能访问业务数据。', 'warning'));
    const info = panel('账号信息', '角色和管理范围由管理员核实后分配。');
    info.body.classList.add('padded'); info.body.append(el('dl', { class: 'detail-list' }, text('dt', '姓名'), text('dd', user.name || '—'), text('dt', '登录学号'), text('dd', user.student_no || '—', 'mono'), text('dt', '当前角色'), text('dd', roleName()), text('dt', '二次验证'), el('dd', {}, tag(user.totp_enabled ? '已启用' : user.totp_required ? '需要绑定' : '未启用', user.totp_enabled ? 'green' : 'orange')))); wrap.append(info.node);
    const security = panel('安全设置', '账号仅限本人使用，不要与他人共享密码。'); security.body.classList.add('padded');
    security.body.append(settingRow('个人密码', '修改密码后，需要重新登录。', button('修改密码', changePasswordModal, 'secondary small', 'key')), settingRow('二次验证', user.totp_allowed === false ? '仅系统设置指定的学号启用，本账号无需动态码。' : user.totp_enabled ? '已使用验证器动态码保护账号。' : '使用验证器 App 手动输入密钥绑定。', user.totp_allowed === false ? tag('未指定此账号') : button(user.totp_enabled ? '安全重新验证' : '绑定验证器', user.totp_enabled ? () => requestReauth().then(() => toast('身份验证成功。')).catch(e => { if (!e.silent) toast(e.message, true); }) : setupTotp, 'secondary small', 'shield')), settingRow('所有登录会话', '同时退出当前设备和其他设备，之后重新登录。', button('退出所有设备', async () => { try { await api('logout_all', {}); state.user = null; clearInterval(state.poll); renderAuth('login'); toast('所有登录会话已撤销。'); } catch (e) { toast(e.message, true); } }, 'secondary small', 'lock')), settingRow('退出当前账号', '离开共用设备前，请主动退出。', button('退出登录', doLogout, 'danger small', 'logout'))); wrap.append(security.node); return wrap;
  }
  function settingRow(title, description, action) { return el('div', { class: 'setting-row' }, el('div', {}, text('h3', title), text('p', description)), action); }
  function changePasswordModal() {
    const current = passwordInput({ autocomplete: 'current-password', required: true }); const next = passwordInput({ autocomplete: 'new-password', minlength: 15, maxlength: 128, required: true }); const repeat = passwordInput({ autocomplete: 'new-password', required: true });
    const form = el('form', {}, field('当前密码', current.box), field('新密码', next.box, '15—64 个字符，支持长口令；不要直接使用学号。'), field('确认新密码', repeat.box));
    const submit = el('button', { class: 'button', type: 'submit' }, '保存新密码'); form.append(el('div', { class: 'modal-foot' }, button('取消', closeModal, 'secondary'), submit));
    form.addEventListener('submit', async e => { e.preventDefault(); if (Array.from(next.node.value).length < 15 || Array.from(next.node.value).length > 64) { showError(form, new Error('密码请使用 15—64 个字符。')); return; } if (next.node.value !== repeat.node.value) { showError(form, new Error('两次输入的新密码不一致。')); return; } const payload = { current_password: current.node.value, password: next.node.value }; await busy(submit, async () => { try { await secure('change_password', payload); closeModal(); toast('密码已更新，请使用新密码重新登录。'); await refreshSession(); } catch (error) { if (!error.silent) { if (form.isConnected) showError(form, error); else toast(error.message, true); } } }); }); openModal('修改密码', '请设置此系统专用的个人密码。', form);
  }
  async function setupTotp() {
    try {
      const data = await api('totp_setup', {}); const code = input('text', { autocomplete: 'one-time-code', inputmode: 'numeric', pattern: '[0-9]{6}', maxlength: 6, required: true, placeholder: '六位动态码' });
      const form = el('form', {}, text('p', '在验证器 App 中新增账号，选择手动输入密钥。', 'muted'), text('div', data.secret || '', 'code-box'), button('复制绑定密钥', () => copyText(data.secret || ''), 'soft small', 'clipboard'), notice('绑定密钥只供本人使用，请勿截图发到群里。'), field('验证器中的动态码', code));
      const submit = el('button', { type: 'submit', class: 'button' }, '确认绑定'); form.append(el('div', { class: 'modal-foot' }, button('取消', closeModal, 'secondary'), submit));
      form.addEventListener('submit', async e => { e.preventDefault(); await busy(submit, async () => { try { const result = await api('totp_confirm', { code: code.value.trim() }); const recoveryCodes = array(result.recovery_codes); state.user = result.user || { ...state.user, totp_enabled: true };
        const content = el('div', {}, notice('二次验证已启用。请将以下恢复码保存在安全位置，每个码只能使用一次。'), el('div', { class: 'code-list' }, recoveryCodes.map(c => text('code', c))), el('div', { class: 'modal-foot' }, button('复制恢复码', () => copyText(recoveryCodes.join('\n')), 'secondary', 'clipboard'), button('我已安全保存', () => { closeModal(); renderShell(); navigate(isManager() ? 'dashboard' : 'today'); })));
        openModal('保存恢复码', '丢失验证器时，可用个人密码和一个恢复码恢复。', content);
      } catch (error) { showError(form, error); } }); }); openModal('绑定验证器', '为当前账号增加一层保护。', form);
    } catch (error) { toast(error.message, true); }
  }
  async function copyText(value) { try { await navigator.clipboard.writeText(value); toast('已复制。'); } catch { toast('浏览器未允许复制，请选中内容手动复制。', true); } }

  async function refreshSession() { const data = await api('session'); state.user = data.user || null; if (state.user) { renderShell(); loadRoute(); } else renderAuth('login'); }
  window.addEventListener('hashchange', () => { if (state.user) loadRoute(); });
  window.addEventListener('pagehide', () => { clearPrivateState(); app.replaceChildren(); });
  window.addEventListener('pageshow', event => { if (event.persisted) refreshSession().catch(() => { renderAuth('login'); }); });
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && state.user) api('session', null, { passive: true }).then(data => { if (!data.user) { state.user = null; renderAuth('login'); } }).catch(() => {}); });
  try { const storedTheme = localStorage.getItem('dorm-theme'); if (storedTheme === 'light' || storedTheme === 'dark') document.documentElement.dataset.theme = storedTheme; } catch {}

  // init 在所有页面函数定义完成后执行。
  async function init() { try { const data = await api('session'); state.user = data.user || null; state.date = dateValue(); if (state.user) { renderShell(); loadRoute(); } else renderAuth('login'); } catch (error) { renderAuth('login'); const form = $('form'); if (form) showError(form, error); } }

  function metricCards(metrics = {}) {
    const items = [['present', '已在寝', 'green', '已确认安全返寝', 'home'], ['away', '外出未归', 'orange', '仍需跟进返寝', 'clock'], ['exempt', '无需返寝', '', '已核实的请假安排', 'shield'], ['unknown', '待核实', 'red', '需要进一步确认', 'help'], ['unreported', '未登记', '', '尚未收到有效申报', 'file']];
    return el('div', { class: 'metric-grid' }, items.map(([key, label, color, hint, glyph]) => el('article', { class: `metric-card ${color}` }, el('div', { class: 'metric-label' }, label, icon(glyph)), text('div', metrics[key] ?? 0, 'metric-number mono'), text('div', hint, 'metric-note'))));
  }
  function refreshFooter() { return el('div', { class: 'panel-footer' }, el('span', { 'data-refresh-status': '' }, `最后读取 ${timeLabel(new Date().toISOString())} · 每 20 秒更新`), el('span', {}, '以服务端已收到的信息为准')); }
  function eventList(events, limit = 8) {
    if (!events.length) return empty('暂时没有新动态', '初报、补报和更正保存后，会出现在这里。', null, 'clock');
    const labels = { initial: '完成初次登记', return: '补报返寝', update: '更新人员情况', correct: '更正登记', correction: '更正登记', report_initial: '完成初次登记', report_update: '更新人员情况', report_correct: '更正登记', roster_adjust: '调整当晚名册', exemption_revoke: '撤销返寝例外', task_close: '结项归档', task_reopen: '重新开放' };
    return el('div', { class: 'timeline' }, (limit ? events.slice(0, limit) : events).map(event => el('div', { class: 'timeline-item' }, el('span', { class: 'timeline-mark' }, icon(event.type?.includes('correct') ? 'edit' : 'check')), el('div', { class: 'timeline-content' }, text('strong', event.title || `${event.student_name || event.name || event.room || ''} ${labels[event.type || event.event_type] || event.action || '查寝记录更新'}`.trim()), text('p', event.description || event.reason || event.note || event.location || (event.after_status ? `最新状态：${statusMap[event.after_status]?.[0] || event.after_status}` : '记录已保存，可在历史日报查看。')), text('time', `${event.actor_name || event.operator_name || ''} · 接收 ${timeLabel(event.recorded_at || event.created_at || event.updated_at, true)}${event.occurred_at ? ` · 人工发生时间 ${timeLabel(event.occurred_at, true)}` : ''}`), !limit && event.before && event.after ? el('details', {}, text('summary', '查看原值与新值'), text('pre', JSON.stringify({ 原值: event.before, 新值: event.after }, null, 2), 'event-data')) : null))));
  }

  function peopleOverview(people, events) {
    const p = panel('全员明细', '按姓名、学号、寝室和状态筛选；可查看个人完整时间线。'); p.body.remove();
    const search = input('search', { value: state.filters.peopleQuery || '', placeholder: '搜索姓名、学号或寝室', 'aria-label': '搜索全员明细' });
    const status = select([['', '全部状态'], ...Object.entries(statusMap).map(([key, value]) => [key, value[0]])], state.filters.peopleStatus || '', { 'aria-label': '全员状态筛选' }); const holder = el('div');
    function show() { state.filters.peopleQuery = search.value; state.filters.peopleStatus = status.value; const q = search.value.trim().toLowerCase(); const rows = people.filter(m => !status.value || m.current_status === status.value).filter(m => !q || `${m.name} ${m.student_no} ${m.room}`.toLowerCase().includes(q)); holder.replaceChildren(rows.length ? table(['同学', '当晚寝室', '原始初报', '当前状态', '去向', ''], rows.map(m => row(person(m), m.room, statusTag(m.initial_status), statusTag(m.current_status), m.location || '—', button('时间线', () => openModal(`${nameOf(m)} · 本晚时间线`, `${state.date} 晚`, eventList(events.filter(e => String(e.student_id) === String(m.student_id)), 0), true), 'soft small')))) : empty('没有符合条件的同学', '调整姓名、寝室或状态筛选后重试。')); }
    search.addEventListener('input', show); status.addEventListener('change', show); show(); p.node.append(el('div', { class: 'filter-bar' }, search, status), holder); return p.node;
  }
  function jobStatus(runs) {
    const p = panel('服务器自动任务', '这项状态来自实际 CLI 执行记录，不代表外部推送已经接入。');
    const last = runs[0]; const success = runs.find(r => r.status === 'success');
    if (!last) p.body.append(notice('尚无计划任务运行记录，请按部署教程配置每分钟 tick。浏览器打开不能代替服务器调度。', 'warning'));
    else { p.body.append(notice(`最近执行：${timeLabel(last.finished_at, true)} · ${last.status === 'success' ? '成功' : '失败，请联系维护人员'}；最近成功：${timeLabel(success?.finished_at, true)}`, last.status === 'success' ? 'neutral' : 'warning')); const age = new Date(String(state.serverTime).replace(' ', 'T') + '+08:00') - new Date(String(success?.finished_at || '').replace(' ', 'T') + '+08:00'); if (!success || age > 180000) p.body.append(notice('超过三分钟没有新的成功记录，请核查计划任务、服务器时间与运行日志。', 'warning')); }
    return p.node;
  }

  async function viewDashboard(options = {}) {
    const data = await api('dashboard', null, { query: { date: state.date }, passive: options.passive }); const metrics = data.metrics || {}; const rooms = array(data.rooms); const people = array(data.people); state.tasks = array(data.tasks);
    const wrap = el('div', {}, pageHeader('今晚，每个人都安心归寝。', '集中查看登记进度，及时联系未归和待核实的同学。', 'TONIGHT AT A GLANCE'));
    const submitted = metrics.rooms_submitted ?? metrics.submitted_rooms ?? 0; const total = metrics.rooms_total ?? metrics.expected_rooms ?? rooms.length;
    wrap.append(el('section', { class: 'hero-card' }, el('div', { class: 'hero-text' }, text('h2', `${dateLabel()} · 晚间查寝`), text('p', total && submitted === total ? '各寝室已完成初报。请继续关注尚未返寝和待核实的同学。' : `每天 ${state.config.check_time || '22:30'} 据实登记。后续返寝可持续补报，原始情况会保留。`)), el('div', { class: 'hero-side' }, el('div', { class: 'count-display mono' }, `${submitted}/${total}`, text('small', '寝室已完成初报')), el('span', { class: 'hero-symbol' }, icon('moon')))), metricCards(metrics));
    wrap.append(notice(`${data.arrival_summary || '以已收到的申报为准'} · 数据截至 ${timeLabel(data.as_of, true)}`, 'neutral'));
    const roomPanel = panel('寝室登记进度', `当晚名册 ${metrics.total ?? metrics.students_total ?? people.length} 人 · ${total} 间寝室`, button('查看日报', () => navigate('reports'), 'secondary small', 'file'));
    roomPanel.node.removeChild(roomPanel.body);
    roomPanel.node.append(rooms.length ? table(['寝室', '负责人', '初报状态', '当前情况', '最后更新', ''], rooms.map(room => {
      const task = array(data.tasks).find(t => String(t.room_id) === String(room.room_id || room.id)); const counts = room.metrics || room.counts || {}; const isReported = !!(room.submitted_at || room.submitted || room.reported);
      const current = room.summary || `${counts.present ?? room.present ?? 0} 人在寝${(counts.away ?? room.away ?? 0) ? ` · ${counts.away ?? room.away} 人未归` : ''}`;
      return row(el('strong', {}, roomName(room)), room.leader_name || room.responsible_name || room.leader || '待分配', tag(room.roster_empty ? '已移出本晚范围' : room.late ? '迟报' : isReported ? '已提交' : '未提交', room.roster_empty ? '' : room.late ? 'orange' : isReported ? 'green' : ''), current, text('span', timeLabel(room.updated_at || room.submitted_at), 'mono'), button('查看', () => task ? taskModal(task) : peopleModal(people.filter(p => String(p.room_id) === String(room.room_id || room.id)), roomName(room)), 'soft small'));
    })) : empty('还没有当晚寝室信息', '导入名单并分配寝室后，系统会建立当晚查寝任务。', isAdmin() ? button('导入名单', () => navigate('roster'), '', 'upload') : null));
    roomPanel.node.append(refreshFooter()); wrap.append(roomPanel.node);
    const split = el('div', { class: 'split-grid' }); const focus = panel('需要关心的同学', '未归、待核实和缺报分别展示。', button('跟进处理', () => navigate('followups'), 'secondary small', 'arrow'));
    const pending = people.filter(p => ['away', 'unknown', 'unreported'].includes(p.current_status || p.status)).slice(0, 6);
    focus.node.removeChild(focus.body); focus.node.append(pending.length ? table(['同学', '寝室', '当前状态', '去向'], pending.map(p => row(person(p), p.room || roomName(p), statusTag(p.current_status || p.status), p.location || '—'))) : empty('暂时没有待跟进人员', '已收到的记录中，没有未归、待核实或缺报情况。', null, 'check'));
    const recent = panel('最近动态', '初报、返寝补报与更正'); recent.body.append(eventList(array(data.recent_events))); split.append(focus.node, recent.node); wrap.append(split, peopleOverview(people, array(data.events)), jobStatus(array(data.job_runs)), text('p', '查寝用于确认安全，与平时考核成绩无关。', 'footer-note')); return wrap;
  }

  async function viewToday(options = {}) {
    const data = await api('tasks', null, { query: { date: state.date }, passive: options.passive }); const tasks = [...array(data.tasks), ...array(data.open_tasks)]; state.tasks = tasks;
    const wrap = el('div', {}, pageHeader('我的查寝', '核实每位同学的情况，完成今晚登记；有人返寝时，随时补报。', 'MY NIGHT CHECK'));
    if (String(data.server_time || '').slice(0, 10) === state.date && String(data.server_time || '').slice(11, 16) >= (data.reminder_time || '22:25') && tasks.some(t => t.phase === 'draft')) wrap.append(notice(`准备提醒：请在 ${data.check_time || '22:30'} 重新逐人核实并正式提交；草稿不计作初报。`, 'neutral'));
    const box = panel('本晚与未结项的旧晚任务', '仅显示你当前获授权的寝室与任务；每张卡片标明归属日期。'); box.body.remove();
    if (!tasks.length) box.node.append(empty('当前没有可登记的寝室', '可能尚未分配管理范围，或该日期没有任务。请联系班委核对；旧晚补报请切换查寝日期。', null, 'home'));
    tasks.forEach(task => {
      const members = array(task.members); const submitted = !!task.submitted_at; const pending = members.filter(m => ['away', 'unknown'].includes(m.current_status || m.status)); const closed = task.phase === 'closed'; const paused = task.phase === 'paused';
      const card = el('article', { class: 'task-card' }, el('div', { class: 'task-title' }, el('div', {}, text('h3', roomName(task)), el('div', { class: 'task-meta' }, text('span', `${task.business_date || state.date} 晚`), text('span', `${members.length} 位同学`), task.submitted_at ? text('span', `初报 ${timeLabel(task.submitted_at)}`) : text('span', `${timeLabel(task.check_at)} 开放正式登记`))), tag(task.roster_empty ? '已移出本晚范围' : closed ? '已结项' : paused ? '已暂停' : task.phase === 'draft' ? '准备中' : task.late ? '已迟报' : submitted ? '已提交' : '待登记', closed ? 'green' : paused ? '' : submitted ? 'blue' : 'orange')));
      card.append(el('div', { class: 'member-summary' }, members.map(m => { const status = m.current_status || m.status || 'unreported'; return el('span', { class: 'member-pill' }, el('span', { class: `dot ${statusMap[status]?.[1] || ''}` }), nameOf(m), text('span', ` · ${statusMap[status]?.[0] || status}`, 'muted')); })));
      const actions = el('div', { class: 'button-row' });
      if (!submitted && !closed && !paused) { const b = button(task.phase === 'draft' ? '查看并准备登记' : '开始今晚登记', () => initialModal(task), '', 'edit'); actions.append(b); }
      if (submitted && !closed && !paused) actions.append(button(pending.length ? `补报返寝 · ${pending.length} 人待确认` : '更新人员情况', () => updateModal(task), pending.length ? '' : 'secondary', 'check'));
      actions.append(button('查看本晚记录', () => taskModal(task), 'secondary', 'file'));
      if (submitted && !closed && !paused) actions.append(button('更正误填', () => correctionModal(task), 'secondary small', 'edit'));
      if (isManager()) { actions.append(button(closed ? '重新开放' : '结项归档', () => closeTask(task, closed), 'secondary small', closed ? 'refresh' : 'lock')); if (!closed && !paused && task.frozen_at) actions.append(button('调整当晚名册', () => adjustRosterModal(task), 'secondary small', 'people')); }
      card.append(actions); box.node.append(card);
    }); wrap.append(box.node, notice('零点之后仍可补报原晚任务。请核对页面的查寝日期；已确认安全不等于已返寝。', 'neutral')); return wrap;
  }
  function statusOptions(includeEmpty = false, allowExempt = true) { return [...(includeEmpty ? [['', '请选择状态']] : []), ['present', '已在寝'], ['away', '外出未归'], ...(allowExempt ? [['exempt', '已核实无需返寝']] : []), ['unknown', '待核实']]; }
  async function initialModal(task) {
    const userId = state.user?.id; let saved = null;
    try { saved = (await api('draft_get', null, { query: { task_id: task.id } })).draft; } catch (error) { toast(error.message, true); return; }
    if (!state.user || state.user.id !== userId) return;
    const members = array(task.members); const phaseDraft = task.phase === 'draft'; const form = el('form'); const edits = []; const checkLabel = timeLabel(task.check_at);
    form.append(notice(phaseDraft ? `当前尚未开放正式登记。可以保存在线草稿，${checkLabel} 后重新核实并提交。` : `请申报 ${checkLabel} 时的实际情况；后来发生的变化另填当前状态。`, phaseDraft ? 'warning' : 'neutral'));
    if (saved) form.append(notice(saved.stale ? '旧草稿对应的名册已变化，未自动恢复，请逐人重新核对。' : `已恢复本人 ${timeLabel(saved.updated_at, true)} 保存的在线草稿；仍需重新核实。`, saved.stale ? 'warning' : 'neutral'));
    function reopen() { openModal(`${roomName(task)} · 今晚登记`, `${task.business_date || state.date} 晚 · 逐人核实`, form, true); }
    const allButton = button('核实后批量设为在寝', async () => { const approved = await confirmAction('确认成员均已在寝', `请确认以下尚未初报的同学在 ${checkLabel} 均已在寝：${edits.filter(e => !e.immutable).map(e => nameOf(e.member)).join('、')}。`, '确认并填写'); if (approved) edits.filter(e => !e.immutable).forEach(item => { item.status.value = 'present'; item.current.value = ''; item.location.value = ''; item.currentLocation.value = ''; item.toggle(); }); reopen(); }, 'soft small', 'check');
    form.append(el('div', { class: 'button-row' }, allButton));
    const t = el('table', {}, el('thead', {}, row('同学', `${checkLabel} 时状态`, '去向 / 预计返寝', '当前变化 / 发生时间')), el('tbody'));
    members.forEach(member => {
      const immutable = member.initial_status && (member.initial_status !== 'unreported' || member.corrected_initial_status);
      const draft = saved && !saved.stale ? array(saved.members).find(m => String(m.student_id) === String(member.student_id)) : null;
      const allowExempt = !!member.exemption;
      const status = select(immutable ? [[member.initial_status, statusMap[member.initial_status]?.[0] || member.initial_status]] : statusOptions(true, allowExempt), immutable ? member.initial_status : draft?.status || '', { class: 'status-select', 'aria-label': `${nameOf(member)}的初报状态`, required: true, disabled: !!immutable });
      const location = input('text', { class: 'input table-input', value: immutable ? member.initial_location || '' : draft?.location || '', maxlength: 200, disabled: !!immutable, 'aria-label': `${nameOf(member)}的去向` });
      const current = select([['', `与 ${checkLabel} 一致`], ...statusOptions(false, allowExempt)], immutable ? '' : draft?.current_status || '', { class: 'status-select', disabled: !!immutable, 'aria-label': `${nameOf(member)}的当前状态` });
      const currentLocation = input('text', { class: 'input table-input', value: immutable ? member.location || '' : draft?.current_location || '', maxlength: 200, 'aria-label': `${nameOf(member)}的当前去向` });
      const expected = input('datetime-local', { value: draft?.expected_return_at?.replace(' ', 'T').slice(0, 16) || '', disabled: !!immutable, 'aria-label': `${nameOf(member)}预计返寝时间` });
      const occurred = input('datetime-local', { value: draft?.occurred_at?.replace(' ', 'T').slice(0, 16) || '', disabled: !!immutable, 'aria-label': `${nameOf(member)}实际变化时间` });
      const toggle = () => { location.required = !immutable && ['away', 'unknown'].includes(status.value); location.placeholder = status.value === 'away' ? '如：图书馆' : status.value === 'unknown' ? '填写已知情况' : '说明（选填）'; currentLocation.disabled = !!immutable || !current.value; currentLocation.required = !immutable && ['away', 'unknown'].includes(current.value); currentLocation.placeholder = currentLocation.required ? '填写当前去向 / 已知情况' : current.value ? '当前说明（选填）' : '沿用初报去向'; };
      status.addEventListener('change', toggle); current.addEventListener('change', toggle); toggle(); edits.push({ member, status, current, location, currentLocation, expected, occurred, immutable, toggle });
      $('tbody', t).append(row(person(member), el('div', {}, status, immutable ? text('small', '转入前已有申报，此处保留原值。', 'inline-hint') : null), el('div', {}, location, field('预计返寝（选填）', expected)), el('div', {}, current, field('当前去向 / 已知情况', currentLocation), field('实际变化时间（选填）', occurred))));
    });
    form.append(el('div', { class: 'table-wrap' }, t), text('p', '“无需返寝”须有后台核实安排。人工时间不确定时留空，系统保留真实接收时间。', 'inline-hint'));
    const truth = el('input', { type: 'checkbox', required: true }); form.append(el('label', { class: 'checkbox' }, truth, '我已逐人重新核实，初报与后来的变化分别填写。'));
    const values = () => edits.map(item => ({ student_id: item.member.student_id || item.member.id, status: item.status.value, location: item.location.value.trim(), ...(item.current.value ? { current_status: item.current.value, current_location: item.currentLocation.value.trim() } : {}), ...(item.expected.value ? { expected_return_at: item.expected.value.replace('T', ' ') + ':00' } : {}), ...(item.occurred.value ? { occurred_at: item.occurred.value.replace('T', ' ') + ':00' } : {}) }));
    const save = button('保存在线草稿', async () => { await busy(save, async () => { try { await api('draft_save', { task_id: task.id, roster_version: task.roster_version, members: values() }); toast('草稿已保存，尚未正式提交。'); } catch (error) { showError(form, error); } }); }, 'secondary', 'file');
    const submit = el('button', { class: 'button', type: 'submit', disabled: phaseDraft }, '提交本寝初报'); form.append(el('div', { class: 'modal-foot' }, button('取消', closeModal, 'secondary'), save, submit));
    const key = idempotency(); form.addEventListener('submit', async event => { event.preventDefault(); if (!form.reportValidity()) return; await busy(submit, async () => { try { await api('report_initial', { task_id: task.id, roster_version: task.roster_version, version: task.report_version, idempotency_key: key, members: values() }); closeModal(); toast('本寝初报已保存。之后有人返寝，可继续补报。'); loadRoute(); } catch (error) { showError(form, error); } }); }); reopen();
  }
  function updateModal(task, selectedId = null) {
    const members = array(task.members); const form = el('form'); const selections = [];
    form.append(notice('只选择本次实际发生变化的同学。原初报会保留。', 'neutral'));
    const list = el('div', { class: 'table-wrap' }); list.append(el('table', {}, el('thead', {}, row('选择', '同学', '当前状态')), el('tbody', {}, members.map(member => { const check = el('input', { type: 'checkbox', class: 'table-check', 'aria-label': `选择${nameOf(member)}`, checked: selectedId !== null ? String(selectedId) === String(member.student_id || member.id) : false }); selections.push({ check, member }); return row(check, person(member), statusTag(member.current_status || member.status)); })))); form.append(list);
    const status = select(statusOptions(false, false), 'present'); const location = input('text', { maxlength: 200, placeholder: '如：图书馆；已返寝可不填' }); const occurred = input('datetime-local'); const reason = input('text', { maxlength: 300, placeholder: '如：已当面确认返寝' });
    form.append(el('div', { class: 'form-grid' }, field('本次更新为', status), field('去向 / 说明', location), field('实际返寝时间（知道时再填）', occurred, '不确定具体时间请留空，系统记录确认时间。'), field('补报备注（选填）', reason)));
    const submit = el('button', { type: 'submit', class: 'button' }, '确认并保存补报'); form.append(el('div', { class: 'modal-foot' }, button('取消', closeModal, 'secondary'), submit)); const key = idempotency();
    form.addEventListener('submit', async e => { e.preventDefault(); const ids = selections.filter(s => s.check.checked).map(s => s.member.student_id || s.member.id); if (!ids.length) { showError(form, new Error('请先选择本次需要更新的同学。')); return; } await busy(submit, async () => { try { await api('report_update', { task_id: task.id, student_ids: ids, status: status.value, location: location.value.trim(), occurred_at: occurred.value ? occurred.value.replace('T', ' ') + ':00' : null, reason: reason.value.trim(), version: task.report_version, idempotency_key: key }); closeModal(); toast(`已保存 ${ids.length} 位同学的最新情况。`); loadRoute(); } catch (error) { showError(form, error); } }); }); openModal(`${roomName(task)} · 返寝补报`, `${task.business_date || state.date} 晚 · 可持续更新`, form);
  }
  function correctionModal(task) {
    const members = array(task.members); const student = select(members.map(m => [m.student_id || m.id, `${nameOf(m)} · ${m.student_no || ''}`]), members[0]?.student_id || members[0]?.id); const target = select(isManager() ? [['current', '更正当前状态'], ['initial', '更正查寝时点的申报事实']] : [['current', '更正当前状态']], 'current'); const status = select(statusOptions(false, true), 'present'); const location = input('text', { maxlength: 200 }); const reason = el('textarea', { required: true, maxlength: 500, placeholder: '说明原来哪里填错、核实后的情况及依据。' });
    const form = el('form', {}, notice('更正用于修复误填，原记录和本次原因会保留。正常返寝请使用“返寝补报”。', 'warning'), field('需要更正的同学', student), field('更正范围', target), field('核实后的状态', status), field('去向说明（选填）', location), field('更正原因', reason)); const submit = el('button', { type: 'submit', class: 'button' }, '保存更正'); form.append(el('div', { class: 'modal-foot' }, button('取消', closeModal, 'secondary'), submit)); const key = idempotency();
    form.addEventListener('submit', async e => { e.preventDefault(); await busy(submit, async () => { try { await api('report_correct', { task_id: task.id, student_id: student.value, status: status.value, location: location.value.trim(), reason: reason.value.trim(), target: target.value, version: task.report_version, idempotency_key: key }); closeModal(); toast('更正已保存，原始记录仍可追溯。'); loadRoute(); } catch (error) { showError(form, error); } }); }); openModal('更正误填记录', roomName(task), form);
  }
  async function taskModal(task) {
    const userId = state.user?.id;
    try {
      const data = await api('task_history', null, { query: { task_id: task.id } });
      if (!state.user || state.user.id !== userId) return;
      const fresh = data.task; const content = el('div', {}, el('div', { class: 'button-row spread' }, tag(fresh.phase === 'closed' ? '已结项' : fresh.submitted_at ? '已初报' : '未初报', fresh.submitted_at ? 'green' : 'orange'), text('span', `初报接收：${timeLabel(fresh.submitted_at, true)} · 任务 ${fresh.id}`, 'caption')), table(['同学', '原始初报', '更正后初报', '当前状态', '去向', '更新时间'], array(fresh.members).map(m => row(person(m), statusTag(m.initial_status || 'unreported'), m.corrected_initial_status ? statusTag(m.corrected_initial_status) : '—', statusTag(m.current_status || m.status), m.location || '—', timeLabel(m.updated_at, true)))), text('h3', '本晚完整时间线'), eventList(array(data.events), 0));
      content.append(el('div', { class: 'modal-foot' }, button('关闭', closeModal, 'secondary'))); openModal(roomName(fresh), `${fresh.business_date} 晚 · 名册版本 ${fresh.roster_version} · 报告版本 ${fresh.report_version}`, content, true);
    } catch (error) { toast(error.message, true); }
  }
  async function adjustRosterModal(task) {
    const userId = state.user?.id;
    try {
      const data = await api('roster'); if (!state.user || state.user.id !== userId) return;
      const selected = new Set(array(task.members).map(m => String(m.student_id))); const checks = [];
      const search = input('search', { placeholder: '搜索本班同学', 'aria-label': '搜索待调整名册' });
      const reason = el('textarea', { required: true, maxlength: 500, placeholder: '说明漏人、范围错误或换寝的核实依据。' });
      const rows = array(data.students).map(s => { const check = el('input', { type: 'checkbox', checked: selected.has(String(s.id)), 'aria-label': `纳入${nameOf(s)}` }); const node = row(check, person(s), [s.building, s.room_number].filter(Boolean).join(' · ')); checks.push({ check, student: s, node }); return node; });
      search.addEventListener('input', () => { const q = search.value.trim().toLowerCase(); checks.forEach(item => { item.node.hidden = !`${item.student.name} ${item.student.student_no}`.toLowerCase().includes(q); }); });
      const form = el('form', {}, notice('这里只调整本晚快照，不修改日常住宿关系。跨寝移动先在原寝带理由移除，再加入目标寝室；原事件和旧日报保留。初报后新加成员需要班委带理由补正。', 'warning'), search, table(['纳入本寝', '同学', '当前住宿'], rows), field('调整原因', reason));
      const submit = el('button', { type: 'submit', class: 'button' }, '保存当晚名册新版本'); form.append(el('div', { class: 'modal-foot' }, button('取消', closeModal, 'secondary'), submit));
      form.addEventListener('submit', async e => { e.preventDefault(); await busy(submit, async () => { try { await api('task_roster_adjust', { task_id: task.id, roster_version: task.roster_version, student_ids: checks.filter(x => x.check.checked).map(x => x.student.id), reason: reason.value.trim() }); closeModal(); toast('当晚名册已调整，原事件与固定日报仍保留。'); loadRoute(); } catch (error) { showError(form, error); } }); });
      openModal(`调整当晚名册 · ${roomName(task)}`, `${task.business_date} 晚 · 当前版本 ${task.roster_version}`, form, true);
    } catch (error) { toast(error.message, true); }
  }
  function peopleModal(people, title = '人员明细') { openModal(title, `${state.date} 晚`, el('div', {}, people.length ? table(['同学', '查寝时点初报', '最新状态', '去向'], people.map(p => row(person(p), statusTag(p.corrected_initial_status || p.initial_status || 'unreported'), statusTag(p.current_status || p.status), p.location || '—'))) : empty('暂无人员记录', '请刷新后重试。'), el('div', { class: 'modal-foot' }, button('关闭', closeModal, 'secondary'))), true); }
  async function closeTask(task, reopen = false) { const reason = el('textarea', { required: true, maxlength: 300, placeholder: reopen ? '填写重新开放的原因。' : '填写结项说明。尚有未归、缺报或未核实情况时不能结项。' }); const form = el('form', {}, field(reopen ? '重新开放原因' : '结项说明', reason)); const submit = el('button', { type: 'submit', class: 'button' }, reopen ? '重新开放任务' : '确认结项'); form.append(el('div', { class: 'modal-foot' }, button('取消', closeModal, 'secondary'), submit)); form.addEventListener('submit', async e => { e.preventDefault(); await busy(submit, async () => { try { await api(reopen ? 'task_reopen' : 'task_close', { task_id: task.id, reason: reason.value.trim() }); closeModal(); toast(reopen ? '任务已重新开放。' : '任务已结项。'); loadRoute(); } catch (error) { showError(form, error); } }); }); openModal(reopen ? '重新开放查寝任务' : '结项归档', roomName(task), form); }

  async function viewFollowups(options = {}) {
    const [dashboard, follows, inspections] = await Promise.all([api('dashboard', null, { query: { date: state.date }, passive: options.passive }), api('followups', null, { query: { date: state.date }, passive: options.passive }), api('inspections', null, { query: { date: state.date }, passive: options.passive })]); const people = array(dashboard.people); state.tasks = array(dashboard.tasks);
    const wrap = el('div', {}, pageHeader('把关心，落实到每个人。', '分开处理未归、待核实与缺报；联系进度与在寝状态分别记录。', 'CARE & FOLLOW-UP'), metricCards(dashboard.metrics));
    const list = panel('待跟进人员', '确认安全后仍需返寝的同学，保留待返寝状态。', button('登记抽查', () => inspectionModal(state.tasks), 'secondary small', 'clipboard')); list.body.remove();
    const status = select([['', '全部待跟进'], ['away', '外出未归'], ['unknown', '待核实'], ['unreported', '未登记']], state.filters.followupStatus || '', { 'aria-label': '人员状态筛选' }); const search = input('search', { value: state.filters.followupQuery || '', placeholder: '搜索姓名、学号或寝室', 'aria-label': '搜索跟进人员' }); const holder = el('div');
    function renderRows() { state.filters.followupQuery = search.value; state.filters.followupStatus = status.value; const query = search.value.trim().toLowerCase(); const filtered = people.filter(p => ['away', 'unknown', 'unreported'].includes(p.current_status || p.status)).filter(p => !status.value || (p.current_status || p.status) === status.value).filter(p => !query || `${p.name} ${p.student_no} ${p.room}`.toLowerCase().includes(query)); holder.replaceChildren(filtered.length ? table(['同学', '寝室', '人员状态', '已知去向', '联系进度', ''], filtered.map(p => { const f = array(follows.items).filter(x => String(x.student_id) === String(p.student_id)).at(-1); return row(person(p), p.room || roomName(p), statusTag(p.current_status || p.status), p.location || '尚待核实', tag(followupMap[f?.state || p.followup_state] || '待联系', f?.state === 'safe' ? 'blue' : ''), button('记录跟进', () => followupModal(p, f), 'soft small', 'edit')); })) : empty('当前没有符合条件的待办', '请尝试其他筛选，或等待寝室长更新。', null, 'check')); }
    search.addEventListener('input', renderRows); status.addEventListener('change', renderRows); list.node.append(el('div', { class: 'filter-bar' }, search, status), holder, refreshFooter()); renderRows(); wrap.append(list.node);
    const history = panel('抽查记录', '发现差异先核实，不自动判定瞒报。'); history.body.append(array(inspections.items).length ? eventList(array(inspections.items).map(item => ({ ...item, title: `${item.room || ''} · ${item.result || '抽查记录'}`, description: item.note, actor_name: item.actor_name || item.inspector_name })), 0) : empty('还没有抽查记录', '开展抽查后，可在这里保存现场情况。', null, 'clipboard')); wrap.append(history.node); return wrap;
  }
  function followupModal(personData, item = {}) { const stage = select(Object.entries(followupMap), item.state || personData.followup_state || 'pending'); const note = el('textarea', { required: true, maxlength: 1000, placeholder: '记录联系到谁、已确认的情况和下一步安排。' }); const form = el('form', {}, notice('“已确认安全”不会把人员自动改成“已在寝”。返寝后由授权人员另行补报。', 'neutral'), field('联系进度', stage), field('本次跟进说明', note)); const submit = el('button', { type: 'submit', class: 'button' }, '保存跟进'); form.append(el('div', { class: 'modal-foot' }, button('取消', closeModal, 'secondary'), submit)); form.addEventListener('submit', async e => { e.preventDefault(); await busy(submit, async () => { try { await api('followup_save', { task_id: personData.task_id, student_id: personData.student_id, state: stage.value, note: note.value.trim() }); closeModal(); toast('跟进记录已保存。'); loadRoute(); } catch (error) { showError(form, error); } }); }); openModal(`${nameOf(personData)} · 联系跟进`, personData.room || '', form); }
  function inspectionModal(tasks) { if (!tasks.length) { toast('当前没有可抽查的寝室任务。', true); return; } const task = select(tasks.map(t => [t.id, roomName(t)]), tasks[0]?.id); const result = select([['一致', '与申报一致'], ['需核实', '发现差异，待核实'], ['其他', '其他情况']], '一致'); const note = el('textarea', { required: true, maxlength: 1000, placeholder: '填写实际抽查情况与核实安排。' }); const form = el('form', {}, field('抽查寝室', task), field('抽查结果', result), field('现场记录', note)); const submit = el('button', { type: 'submit', class: 'button' }, '保存抽查'); form.append(el('div', { class: 'modal-foot' }, button('取消', closeModal, 'secondary'), submit)); form.addEventListener('submit', async e => { e.preventDefault(); await busy(submit, async () => { try { await api('inspection_save', { task_id: task.value, result: result.value, note: note.value.trim() }); closeModal(); toast('抽查记录已保存。'); loadRoute(); } catch (error) { showError(form, error); } }); }); openModal('登记宿舍抽查', `${state.date} 晚`, form); }

  async function viewReports(options = {}) {
    const data = await api('reports', null, { query: { date: state.date, version: 'latest' }, passive: options.passive });
    const wrap = el('div', {}, pageHeader('每一晚，都有清楚的记录。', '查看固定版本或最新情况，导出时保留初报、补报和数据截至时间。', 'DAILY REPORTS'));
    const versions = array(data.versions); const choices = [['latest', '当前最新快照'], ...versions.map(v => typeof v === 'object' ? [v.id || v.version, `${v.label || `固定版本 ${v.version || v.id}`} · ${timeLabel(v.as_of || v.created_at, true)}`] : [v, `固定版本 ${v}`])];
    const version = select(choices, 'latest', { 'aria-label': '报表版本' }); const body = el('div'); const actions = el('div', { class: 'button-row' }, button('保存固定版本', async () => { try { await api('report_snapshot', { date: state.date, reason: '班委手动保存汇报版本' }); toast('固定版本已保存。'); loadRoute(); } catch (error) { toast(error.message, true); } }, 'secondary', 'plus'), button('导出 Excel', () => downloadReport(version.value), '', 'download'));
    wrap.append(el('section', { class: 'hero-card' }, el('div', { class: 'hero-text' }, text('h2', '当晚日报'), text('p', '六张工作表使用同一数据快照，包含全员明细、未归、缺报和补报记录。')), actions));
    const content = panel('日报明细', '原始申报、更正及最新情况分别留存。'); content.body.remove(); content.node.append(el('div', { class: 'filter-bar' }, version, button('复制汇报文字', () => copyText(body.dataset.summary || ''), 'secondary small', 'clipboard')), body); wrap.append(content.node);
    const events = panel('补报与更正时间线', '此处与所选版本使用同一份快照，旧版本不会被后来记录改写。');
    function showReport(report) { body.dataset.summary = report.summary_text || ''; const metrics = report.metrics || {}; const people = array(report.people); const node = el('div', {}, el('div', { class: 'panel-body' }, notice(`数据截至 ${report.as_of || '当前'} · ${report.version && report.version !== 'latest' ? `版本 ${report.version}` : '最新快照'} · 名册 ${metrics.total ?? people.length} 人`, 'neutral'))); if (people.length) node.append(table(['同学', '当晚寝室', '查寝时点初报', '更正后初报', '最新状态', '去向', '最后更新'], people.map(p => row(person(p), p.room || roomName(p), statusTag(p.initial_status || 'unreported'), p.corrected_initial_status ? statusTag(p.corrected_initial_status) : '—', statusTag(p.current_status || p.status), p.location || '—', timeLabel(p.updated_at, true))))); else node.append(empty('该日期还没有人员明细', '导入名单后，系统会建立查寝任务和日报。', null, 'file')); body.replaceChildren(node); events.body.replaceChildren(eventList(array(report.events), 0)); }
    showReport(data); version.addEventListener('change', async () => { try { const chosen = version.value; const report = await api('reports', null, { query: { date: state.date, version: chosen } }); if (state.user && version.value === chosen) showReport(report); } catch (error) { toast(error.message, true); } });
    wrap.append(events.node); return wrap;
  }
  async function downloadReport(version) { try { const response = await secure('export', { date: state.date, version }, { download: true }); if (!(response instanceof Response)) throw new Error('服务器尚未返回 Excel 文件，请查看服务日志。'); const disposition = response.headers.get('Content-Disposition') || ''; let filename = `晚查寝_${state.date}.xlsx`; const encoded = disposition.match(/filename\*=UTF-8''([^;]+)/i); const plain = disposition.match(/filename="?([^";]+)"?/i); if (encoded) { try { filename = decodeURIComponent(encoded[1]); } catch {} } else if (plain) filename = plain[1]; saveBlob(await response.blob(), filename); toast('Excel 已生成并开始下载。'); } catch (error) { if (!error.silent) toast(error.message, true); } }
  function saveBlob(blob, filename) { const url = URL.createObjectURL(blob); const a = el('a', { href: url, download: filename }); document.body.append(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url), 15000); }

  async function viewRoster() {
    const data = await api('roster'); state.roster = data; const wrap = el('div', {}, pageHeader('把名单与责任，一一对应。', '按学号识别人，按楼栋和房间识别寝室。人员变动会保留生效范围。', 'PEOPLE & ACCESS', false));
    const tabHost = el('div'); const section = el('section', { class: 'panel' }); const tabs = el('div', { class: 'section-tabs', role: 'tablist' }); section.append(tabs, tabHost); wrap.append(section);
    const views = { students: ['学生名单', renderStudents], access: ['账号与授权', renderAccess], exemptions: ['请假安排', renderExemptions] };
    function tab(key) { [...tabs.children].forEach(n => n.classList.toggle('active', n.dataset.key === key)); tabHost.replaceChildren(views[key][1]()); }
    Object.entries(views).forEach(([key, [label]]) => tabs.append(el('button', { type: 'button', class: 'section-tab', dataset: { key }, role: 'tab', onclick: () => tab(key) }, label)));
    function renderStudents() { const holder = el('div'); const search = input('search', { placeholder: '搜索姓名、学号、班级或寝室', 'aria-label': '搜索学生' }); const listing = el('div'); holder.append(el('div', { class: 'panel-header' }, el('div', {}, text('h2', '全班名册'), text('p', `${array(data.students).length} 位同学 · 导入不自动开通账号`)), button('导入名单', importModal, '', 'upload')), el('div', { class: 'filter-bar' }, search, button('下载 CSV 模板', downloadTemplate, 'secondary small', 'download')), listing);
      function show() { const q = search.value.trim().toLowerCase(); const members = array(data.students).filter(s => !q || `${s.name} ${s.student_no} ${s.class_name} ${s.building} ${s.room || s.room_number}`.toLowerCase().includes(q)); listing.replaceChildren(members.length ? table(['同学', '班级', '楼栋 / 寝室', '负责人学号', ''], members.map(s => row(person(s), s.class_name || '—', [s.building, s.room || s.room_number].filter(Boolean).join(' · ') || '待分配', s.leader_no || s.leader_student_no || '—', el('div', { class: 'table-actions' }, button('登记特殊安排', () => exemptionModal(s), 'soft small'), button('移出当前名册', () => retireStudentModal(s), 'secondary small'))))) : empty(q ? '没有找到匹配同学' : '还没有导入名单', q ? '请尝试其他姓名、学号或寝室。' : '下载模板填写后，先预览，再确认导入。', !q ? button('导入名单', importModal, '', 'upload') : null, 'people')); }
      search.addEventListener('input', show); show(); return holder;
    }
    function renderAccess() { const holder = el('div'); holder.append(el('div', { class: 'panel-header' }, el('div', {}, text('h2', '个人账号'), text('p', '每人使用本人学号。学号不等于密码。')), isAdmin() ? button('建立账号', createUserModal, '', 'plus') : null)); const users = array(data.users); holder.append(users.length ? table(['账号', '角色', '状态', '二次验证', ''], users.map(user => row(person(user), user.role === 'admin' ? '系统管理员' : user.role === 'committee' ? '班委' : '寝室长', tag(user.status === 'disabled' ? '已停用' : user.status === 'pending' || user.status === 'inactive' ? '待激活' : '可用', user.status === 'disabled' ? 'red' : 'green'), tag(user.totp_enabled ? '已启用' : '未启用', user.totp_enabled ? 'green' : ''), el('div', { class: 'table-actions' }, user.role === 'leader' ? button('分配范围', () => grantModal(user, data.rooms), 'soft small') : null, isAdmin() ? button('重置', () => resetUser(user), 'secondary small') : null, isAdmin() && String(user.id) !== String(state.user.id) ? button(user.status === 'disabled' ? '启用' : '停用', () => toggleUser(user), 'secondary small') : null)))) : empty('还没有可管理账号', '管理员可以按核实后的学号建立个人账号。', null, 'lock'));
      holder.append(el('div', { class: 'panel-header' }, el('div', {}, text('h2', '寝室与任务授权'), text('p', '当前权限与历史负责人快照分开；到期或撤销后立即失效。')))); const grants = array(data.grants); holder.append(grants.length ? table(['获授权人', '寝室', '生效时间', '到期时间', '范围', ''], grants.map(grant => row(grant.user_name || grant.name || grant.student_no || grant.user_id, grant.room || roomName(grant), grant.valid_from || '—', grant.valid_to || '长期（待撤销）', grant.task_id ? `指定任务 ${grant.task_id}` : '生效日期内任务', button('撤销', async () => { if (!await confirmAction('撤销管理范围', '撤销后该账号不能继续访问这个范围，历史操作仍保留。', '确认撤销', true)) return; try { await secure('grant_revoke', { grant_id: grant.id }); toast('授权已撤销。'); loadRoute(); } catch (error) { if (!error.silent) toast(error.message, true); } }, 'danger small')))) : empty('还没有明确授权', '选择个人账号的“分配范围”，绑定寝室和授权起止时间。', null, 'shield')); return holder;
    }
    function renderExemptions() { const holder = el('div', {}, el('div', { class: 'panel-header' }, el('div', {}, text('h2', '已核实的返寝安排'), text('p', '登记现有许可或住宿安排，不能代替学校的正式请假审批。')), button('登记特殊安排', () => exemptionModal(), '', 'plus'))); holder.append(el('div', { class: 'panel-body' }, notice('填写有效日期、确认来源和必要说明。只有已核实安排才可在查寝时选择“无需返寝”。', 'neutral'))); const items = array(data.exemptions); holder.append(items.length ? table(['同学', '有效日期', '确认来源', '说明', ''], items.map(item => row(item.student_name || item.name || item.student_no || item.student_id, `${item.start_date} 至 ${item.end_date}`, item.source || '—', item.note || '—', button('撤销', async () => { const reason = prompt('填写撤销原因：'); if (!reason?.trim()) return; try { await api('exemption_revoke', { id: item.id, reason: reason.trim() }); toast('安排已撤销。'); loadRoute(); } catch (error) { toast(error.message, true); } }, 'danger small')))) : empty('在此登记有效安排', '尚无可列出的安排；也可从学生名单中选择具体同学后登记。', null, 'calendar')); return holder; }
    tab('students'); return wrap;
  }
  function retireStudentModal(member) {
    const reason = el('textarea', { required: true, maxlength: 500, placeholder: '填写转班、离班或不再纳入后续名册的核实依据。' });
    const form = el('form', {}, notice('此操作仅影响后续未冻结的名册，不删除旧晚记录。若本人仍是寝室负责人，请先完成负责人交接；账号及授权需另行撤销。重新纳入可以通过核实后的 CSV 导入。', 'warning'), field('移出原因', reason));
    const submit = el('button', { type: 'submit', class: 'button danger' }, '确认移出当前名册'); form.append(el('div', { class: 'modal-foot' }, button('取消', closeModal, 'secondary'), submit));
    form.addEventListener('submit', async e => { e.preventDefault(); await busy(submit, async () => { try { await api('student_retire', { student_id: member.id || member.student_id, reason: reason.value.trim() }); closeModal(); toast('当前名册已更新，旧晚记录保留；请另行核实账号与授权。'); loadRoute(); } catch (error) { showError(form, error); } }); });
    openModal(`移出当前名册 · ${nameOf(member)}`, member.student_no, form);
  }
  function downloadTemplate() { const content = '\uFEFF班级,学号,姓名,楼栋,房间号,寝室长学号\r\n示例班级,20260001,示例同学甲,3栋,501,20260001\r\n示例班级,20260002,示例同学乙,3栋,501,20260001\r\n'; saveBlob(new Blob([content], { type: 'text/csv;charset=utf-8' }), '班级名单导入模板_请替换示例.csv'); }
  function importModal() {
    const file = input('file', { accept: '.csv,text/csv', 'aria-label': '选择 CSV 名单' }); const csv = el('textarea', { rows: 7, placeholder: '也可粘贴包含表头的 CSV 文本：班级,学号,姓名,楼栋,房间号,寝室长学号' }); const preview = el('div'); let parsedHash = ''; let previewCsv = '';
    const form = el('div', {}, notice('使用 UTF-8 CSV，可从 Excel 另存。请勿直接上传真实名单以外的无关信息。', 'neutral'), field('选择名单文件', file, '最大 2 MB；导入前会检查并展示预览。'), field('CSV 内容', csv));
    const check = button('检查并预览', async () => { await busy(check, async () => { try { const result = await api('import_preview', { csv: csv.value }); parsedHash = result.hash || ''; previewCsv = csv.value; const errors = array(result.errors); const warnings = array(result.warnings); preview.replaceChildren(notice(`读取 ${result.count ?? array(result.rows).length} 条记录。${errors.length ? `有 ${errors.length} 项问题，暂不能导入。` : '格式检查通过，请核对人员和宿舍。'}`, errors.length ? 'warning' : 'neutral')); if (errors.length) preview.append(el('ul', {}, errors.map(e => text('li', typeof e === 'string' ? e : e.message || JSON.stringify(e))))); if (warnings.length) preview.append(el('ul', {}, warnings.map(e => text('li', typeof e === 'string' ? e : e.message || JSON.stringify(e), 'muted')))); const rows = array(result.rows); if (rows.length) preview.append(table(['学号', '姓名', '班级', '楼栋', '房间', '负责人学号'], rows.slice(0, 100).map(r => row(r.student_no, r.name, r.class_name, r.building, r.room || r.room_number, r.leader_no || r.leader_student_no || '—')))); commit.disabled = !!errors.length || !rows.length; } catch (error) { showError(form, error); commit.disabled = true; } }); }, 'secondary', 'search');
    const commit = button('确认导入', async () => { if (csv.value !== previewCsv) { toast('内容已变化，请重新检查预览。', true); commit.disabled = true; return; } await busy(commit, async () => { try { const result = await api('import_commit', { csv: previewCsv, hash: parsedHash, confirm: true }); closeModal(); toast(`名单已导入。${result.count !== undefined ? `共处理 ${result.count} 条。` : ''}`); loadRoute(); } catch (error) { showError(form, error); } }); }, '', 'check'); commit.disabled = true;
    file.addEventListener('change', async () => { const chosen = file.files?.[0]; if (!chosen) return; if (chosen.size > 2 * 1024 * 1024) { showError(form, new Error('文件超过 2 MB，请整理为本班名单后重试。')); file.value = ''; return; } csv.value = await chosen.text(); commit.disabled = true; }); csv.addEventListener('input', () => { commit.disabled = true; }); form.append(preview, el('div', { class: 'modal-foot' }, button('取消', closeModal, 'secondary'), check, commit)); openModal('导入班级名单', '核对预览后再正式保存，不会静默改写已冻结的当晚名册。', form, true);
  }
  function createUserModal() { const studentNo = input('text', { required: true, maxlength: 64, autocomplete: 'off' }); const name = input('text', { required: true, maxlength: 100 }); const role = select([['leader', '寝室长 / 代理'], ['committee', '班长 / 团支书'], ['admin', '系统管理员']], 'leader'); const form = el('form', {}, notice('先核实本人。建立账号不自动授权寝室；请在账号建立后另行分配管理范围。', 'neutral'), field('本人学号', studentNo), field('姓名', name), field('角色', role)); const submit = el('button', { type: 'submit', class: 'button' }, '建立并生成激活码'); form.append(el('div', { class: 'modal-foot' }, button('取消', closeModal, 'secondary'), submit)); form.addEventListener('submit', async e => { e.preventDefault(); const payload = { student_no: studentNo.value.trim(), name: name.value.trim(), role: role.value }; await busy(submit, async () => { try { const result = await secure('user_create', payload); secretModal('账号已建立', `${payload.name} · ${payload.student_no}`, result.activation_code, result.expires_at, '激活码'); loadRoute(); } catch (error) { if (!error.silent) showError(form, error); } }); }); openModal('建立个人账号', '不开放公众注册，不使用通用初始密码。', form); }
  function secretModal(title, subtitle, code, expires, kind = '一次性凭据') { const content = el('div', {}, notice(`请在核实本人后单独交付${kind}。不要发到群里。`, 'warning'), text('div', code || '服务器未返回凭据，请联系维护人员检查。', 'code-box'), text('p', expires ? `有效期至 ${expires}` : '凭据按服务器设定期限失效。', 'caption'), el('div', { class: 'modal-foot' }, button('复制凭据', () => copyText(code || ''), 'secondary', 'clipboard'), button('我已安全交付', closeModal))); openModal(title, subtitle, content); }
  async function resetUser(user) { if (!await confirmAction('重置个人账号', `请先核实 ${user.name || user.student_no} 本人。生成新重置码后，按系统规则撤销旧凭据和会话。`, '生成重置码')) return; try { const result = await secure('user_reset', { user_id: user.id }); secretModal('重置码已生成', `${user.name || ''} · ${user.student_no}`, result.reset_code, result.expires_at, '重置码'); } catch (error) { if (!error.silent) toast(error.message, true); } }
  async function toggleUser(user) { const disabled = user.status !== 'disabled'; if (!await confirmAction(disabled ? '停用账号' : '启用账号', disabled ? '停用后，现有登录状态也不能继续访问。历史操作仍保留。' : '确认此账号仍由已核实的本人持有。', disabled ? '确认停用' : '确认启用', disabled)) return; try { await secure('user_status', { user_id: user.id, status: disabled ? 'disabled' : 'active' }); toast(disabled ? '账号已停用。' : '账号已启用。'); loadRoute(); } catch (error) { if (!error.silent) toast(error.message, true); } }
  function grantModal(user, rooms) { if (!array(rooms).length) { toast('请先导入含楼栋和寝室号的名单。', true); return; } const room = select(array(rooms).map(r => [r.id || r.room_id, roomName(r)]), rooms[0]?.id || rooms[0]?.room_id); const from = input('date', { value: state.date, required: true }); const to = input('date'); const task = input('text', { placeholder: '普通授权留空；旧晚交接填指定任务 ID' }); const form = el('form', {}, notice('代理也使用自己的账号。授权范围和生效时间会留痕，撤销后立即失效。', 'neutral'), field('负责寝室', room), el('div', { class: 'form-grid' }, field('生效日期', from), field('到期日期（选填）', to)), field('指定旧晚任务（选填）', task, '用于交接未结项旧任务，填写完整任务 ID。')); const submit = el('button', { type: 'submit', class: 'button' }, '保存授权'); form.append(el('div', { class: 'modal-foot' }, button('取消', closeModal, 'secondary'), submit)); form.addEventListener('submit', async e => { e.preventDefault(); await busy(submit, async () => { try { await secure('grant_save', { user_id: user.id, room_id: room.value, valid_from: from.value, valid_to: to.value || null, task_id: task.value.trim() || null }); closeModal(); toast('管理范围已保存。'); loadRoute(); } catch (error) { if (!error.silent) showError(form, error); } }); }); openModal(`分配范围 · ${user.name || user.student_no}`, '角色权限不能通过寝室授权提升。', form); }
  function exemptionModal(member = null) { const students = array(state.roster?.students); if (!students.length) { toast('请先导入学生名单。', true); return; } const student = select(students.map(s => [s.id || s.student_id, `${nameOf(s)} · ${s.student_no || ''}`]), member?.id || member?.student_id || students[0]?.id); const start = input('date', { value: state.date, required: true }); const end = input('date', { value: state.date, required: true }); const source = input('text', { required: true, maxlength: 200, placeholder: '如：已核实的辅导员请假许可' }); const note = el('textarea', { maxlength: 500, placeholder: '仅填写处理所需的简要说明，不填写详细病情等无关信息。' }); const verified = el('input', { type: 'checkbox', required: true }); const form = el('form', {}, field('同学', student), el('div', { class: 'form-grid' }, field('开始日期', start), field('结束日期', end)), field('确认来源', source), field('简要说明（选填）', note), el('label', { class: 'checkbox' }, verified, '我已核实该安排有效；本系统登记不代替学校正式审批。')); const submit = el('button', { type: 'submit', class: 'button' }, '保存已核实安排'); form.append(el('div', { class: 'modal-foot' }, button('取消', closeModal, 'secondary'), submit)); form.addEventListener('submit', async e => { e.preventDefault(); await busy(submit, async () => { try { await api('exemption_save', { student_id: student.value, start_date: start.value, end_date: end.value, source: source.value.trim(), note: note.value.trim(), approved: true }); closeModal(); toast('已保存当晚返寝安排。'); loadRoute(); } catch (error) { showError(form, error); } }); }); openModal('登记请假 / 特殊住宿安排', '仅登记已核实的当晚返寝例外。', form); }

  async function viewSettings() { const data = await api('settings'); const settings = data.settings || data; state.settings = settings; const wrap = el('div', {}, pageHeader('系统设置', '设置班级、每日时间与暂停日期。所有时间均为北京时间。', 'SYSTEM SETTINGS', false)); const group = panel('每日查寝规则', '时间调整不会静默改写已经保存的历史日报。'); group.body.classList.add('padded'); const className = input('text', { value: settings.class_name || '', required: true, maxlength: 100 }); const check = input('time', { value: String(settings.check_time || '22:30').slice(0, 5), required: true }); const due = input('time', { value: String(settings.due_time || '22:35').slice(0, 5), required: true }); const reminder = input('time', { value: String(settings.reminder_time || '22:25').slice(0, 5) }); const retention = input('number', { value: settings.retention_days || 180, min: 1, max: 3650, required: true }); const paused = el('textarea', { rows: 4, placeholder: '每行一个 YYYY-MM-DD 日期' }, array(settings.paused_dates).join('\n')); const form = el('form', {}, field('班级名称', className), el('div', { class: 'form-grid' }, field('查寝时点', check, '初报对应这个时点的情况。'), field('正常提交截止', due, '超过后首次提交标记为迟报。'), field('准备提醒时间', reminder, '首版为站内提醒，网页关闭后不保证送达。')), field('暂停查寝日期', paused, '每行一个日期；节假日或整班活动可在此设置。'), field('业务记录建议保留天数', retention, '此设置用于 CLI prune 的默认截止日期。删除必须先备份并显式确认，不会静默清理。'), notice('实际自动任务由服务器执行。请按部署文档配置计划任务，并定期检查执行结果。', 'neutral')); const submit = el('button', { type: 'submit', class: 'button' }, '保存设置'); form.append(el('div', { class: 'button-row end' }, submit)); form.addEventListener('submit', async e => { e.preventDefault(); const dates = paused.value.split(/[\s,，]+/).filter(Boolean); if (dates.some(d => !/^\d{4}-\d{2}-\d{2}$/.test(d))) { showError(form, new Error('暂停日期请使用 YYYY-MM-DD 格式，每行一个。')); return; } await busy(submit, async () => { try { await secure('settings_save', { settings: { class_name: className.value.trim(), check_time: check.value, due_time: due.value, reminder_time: reminder.value, paused_dates: dates, retention_days: Number(retention.value) } }); state.config.check_time = check.value; const clock = $('.clock-chip span'); if (clock) clock.textContent = `北京时间 · 每晚 ${check.value}`; toast('系统设置已保存。'); } catch (error) { if (!error.silent) showError(form, error); } }); }); group.body.append(form); wrap.append(group.node, totpSettings(settings), jobStatus(array(data.job_runs))); return wrap; }
  function totpSettings(settings) {
    const configured = typeof settings.totp_student_no === 'string';
    const group = panel('两步验证账号', '按登录学号指定唯一账号，不改变账号角色及寝室授权。'); group.body.classList.add('padded');
    const studentNo = input('text', { value: settings.totp_student_no || '', required: true, minlength: 3, maxlength: 64, pattern: '(?:[A-Za-z0-9_]|-){3,64}', autocomplete: 'off', spellcheck: 'false', 'aria-label': '启用两步验证的学号', placeholder: '填写你自己的完整登录学号' });
    const form = el('form', {}, notice(configured ? `当前仅学号 ${settings.totp_student_no} 使用两步验证。` : '尚未配置：暂时沿用原规则，班委和管理员必须绑定验证器。首次保存后改为仅指定学号使用。', 'neutral'), field('启用两步验证的学号', studentNo, '必须是已激活且未停用的登录账号；保留前导零，不能留空。'), notice('保存变更会解除其他账号的验证器并作废其恢复码，让所有账号重新登录；指定账号已有的绑定和恢复码保留，尚未绑定时需本人绑定。', 'warning'));
    const submit = el('button', { type: 'submit', class: 'button' }, '保存两步验证账号'); form.append(el('div', { class: 'button-row end' }, submit));
    form.addEventListener('submit', async e => {
      e.preventDefault(); if (!form.reportValidity()) return;
      const value = studentNo.value.trim();
      if (configured && value === settings.totp_student_no) { toast('两步验证账号未变化。'); return; }
      if (!await confirmAction('仅保留指定账号的两步验证', `请确认学号 ${value} 对应正确本人。其他账号的绑定和恢复码会被撤销，所有账号（包括当前账号）需要重新登录。`, '确认变更', true)) return;
      await busy(submit, async () => {
        try {
          const result = await secure('settings_save', { settings: { totp_student_no: value }, totp_previous_student_no: settings.totp_student_no ?? null, confirm_totp_change: true });
          if (result.relogin_required) { await refreshSession(); toast('两步验证设置已更新，请重新登录。'); }
          else { toast('两步验证账号未变化。'); loadRoute(); }
        } catch (error) { if (!error.silent) showError(form, error); }
      });
    });
    group.body.append(form); return group.node;
  }
  async function viewAudit() {
    const page = state.filters.auditPage || 1; const data = await api('audit', null, { query: { page } });
    const wrap = el('div', {}, pageHeader('操作记录', '查看账号、授权和查寝操作的服务端记录；日志不记录密码或一次性凭据。', 'AUDIT TRAIL', false));
    const p = panel('操作审计', `第 ${page} 页 · 每页 50 条`); p.body.remove();
    p.node.append(array(data.items).length ? table(['时间', '操作者', '操作', '记录详情'], array(data.items).map(item => row(text('span', timeLabel(item.created_at, true), 'mono'), item.actor_name || item.actor_id || '系统', item.action || '—', el('details', {}, text('summary', '查看对象、前后值和原因'), text('pre', JSON.stringify(item.detail || {}, null, 2), 'event-data'))))) : empty('这一页没有操作记录', '可以返回上一页，或在发生业务操作后刷新。', null, 'log'));
    const previous = button('上一页', () => { state.filters.auditPage = Math.max(1, page - 1); loadRoute(); }, 'secondary small'); previous.disabled = page === 1;
    const next = button('下一页', () => { state.filters.auditPage = page + 1; loadRoute(); }, 'secondary small'); next.disabled = array(data.items).length < 50;
    p.node.append(el('div', { class: 'panel-footer' }, previous, text('span', `第 ${page} 页`), next)); wrap.append(p.node); return wrap;
  }

  init();
})();
