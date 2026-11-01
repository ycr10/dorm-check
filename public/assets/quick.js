'use strict';

(() => {
  const root = document.querySelector('#quick-app');
  const state = { csrf: '', identity: null, task: null, serverTime: '', retry: null };
  const labels = { present: '已在寝', away: '外出未归', exempt: '已核实无需返寝', unknown: '待核实', unreported: '未登记' };
  const cookieName = 'dorm_quick_details';

  function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs)) {
      if (value === null || value === undefined || value === false) continue;
      if (key === 'class') node.className = value;
      else if (key === 'text') node.textContent = value;
      else if (key.startsWith('on') && typeof value === 'function') node.addEventListener(key.slice(2), value);
      else if (['checked', 'disabled', 'required', 'hidden'].includes(key)) node[key] = !!value;
      else if (key === 'value') node.value = value;
      else node.setAttribute(key, value === true ? '' : String(value));
    }
    children.flat(Infinity).forEach(child => { if (child !== null && child !== undefined && child !== false) node.append(child instanceof Node ? child : document.createTextNode(String(child))); });
    return node;
  }
  function field(label, control, help = '') { return el('label', { class: 'field' }, el('span', { class: 'field-label', text: label }), control, help ? el('span', { class: 'inline-hint', text: help }) : null); }
  function button(label, handler, kind = 'secondary') { return el('button', { type: 'button', class: `button ${kind}`, onclick: handler }, label); }
  function panel(title, subtitle, body) { return el('section', { class: 'panel' }, el('div', { class: 'panel-header' }, el('div', {}, el('h2', { text: title }), el('p', { text: subtitle }))), el('div', { class: 'panel-body' }, body)); }
  function feedback(target, message, error = false) { target.replaceChildren(el('div', { class: error ? 'error-box' : 'notice neutral', role: 'status', text: message })); }
  function cookiePath() { return new URL('quick.html', location.href).pathname; }
  function writeCookie(identity) {
    const value = identity ? encodeURIComponent(JSON.stringify(identity)) : '';
    document.cookie = `${cookieName}=${value}; Path=${cookiePath()}; Max-Age=${identity ? 2592000 : 0}; SameSite=Strict${location.protocol === 'https:' ? '; Secure' : ''}`;
  }
  function readCookie() {
    const pair = document.cookie.split('; ').find(value => value.startsWith(`${cookieName}=`));
    if (!pair) return null;
    try {
      const value = JSON.parse(decodeURIComponent(pair.slice(cookieName.length + 1)));
      if (typeof value.student_no === 'string' && typeof value.room_number === 'string' && value.student_no.length <= 64 && value.room_number.length <= 100) return value;
    } catch { /* Ignore malformed local preferences. */ }
    return null;
  }
  async function api(action, body = null) {
    const url = new URL('api/index.php', location.href); url.searchParams.set('action', action);
    const headers = { Accept: 'application/json' };
    if (body !== null) { headers['Content-Type'] = 'application/json'; headers['X-CSRF-Token'] = state.csrf; }
    let response;
    try { response = await fetch(url, { method: body === null ? 'GET' : 'POST', headers, credentials: 'same-origin', cache: 'no-store', body: body === null ? undefined : JSON.stringify(body) }); }
    catch { throw new Error('网络连接失败，提交结果尚未确认；请检查网络后使用原表单重试。'); }
    let result;
    try { result = await response.json(); } catch { throw new Error('服务器返回的数据无法读取，请联系班委。'); }
    if (result.csrf) state.csrf = result.csrf;
    if (!response.ok || !result.ok) { const error = new Error(result.error?.message || '操作未完成，请联系班委。'); error.code = result.error?.code; throw error; }
    return result.data;
  }
  async function request(action, payload) {
    const fingerprint = JSON.stringify({ action, payload });
    if (!state.retry || state.retry.fingerprint !== fingerprint) {
      const bytes = crypto.getRandomValues(new Uint8Array(16));
      state.retry = { fingerprint, key: Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('') };
    }
    const result = await api(action, { ...payload, idempotency_key: state.retry.key });
    state.retry = null; return result;
  }
  async function busy(buttonNode, work) { buttonNode.disabled = true; try { await work(); } finally { if (buttonNode.isConnected) buttonNode.disabled = false; } }
  function renderEntry(message = '') {
    state.identity = null; state.task = null; state.retry = null;
    const saved = readCookie();
    const no = el('input', { class: 'input', type: 'text', value: saved?.student_no || '', maxlength: 64, required: true, autocomplete: 'off', spellcheck: 'false', placeholder: '请输入寝室长学号' });
    const room = el('input', { class: 'input', type: 'text', value: saved?.room_number || '', maxlength: 100, required: true, autocomplete: 'off', placeholder: '例如 501（按名单填写）' });
    const remember = el('input', { type: 'checkbox', checked: !!saved });
    const result = el('div', { class: 'quick-feedback' });
    const submit = el('button', { type: 'submit', class: 'button full' }, '进入当晚登记');
    const form = el('form', {}, field('寝室长学号', no), field('寝室号', room, '只填写房间号；不要添加楼栋。若多个楼栋无法区分，请联系班委。'), el('label', { class: 'checkbox' }, remember, '在这台设备的浏览器记住学号和寝室号（30 天）'), el('p', { class: 'inline-hint', text: '仅保存在本机 Cookie，非身份验证，不保存名单和申报内容；共用设备请勿勾选。' }), el('div', { class: 'quick-inline' }, button('清除已记住的信息', () => { writeCookie(null); no.value = ''; room.value = ''; remember.checked = false; feedback(result, '已清除本机记住的信息。'); }, 'soft small')), result, submit);
    form.addEventListener('submit', async event => {
      event.preventDefault(); if (!form.reportValidity()) return;
      const identity = { student_no: no.value.trim(), room_number: room.value.trim() };
      if (!identity.student_no || !identity.room_number) { feedback(result, '请填写寝室长学号和寝室号。', true); return; }
      if (remember.checked) writeCookie(identity); else writeCookie(null);
      await busy(submit, async () => {
        try { const data = await api('quick_lookup', identity); state.identity = identity; state.task = data.task; state.serverTime = data.server_time; renderTask(); }
        catch (error) { feedback(result, error.message, true); }
      });
    });
    root.replaceChildren(el('div', { class: 'page-kicker', text: 'QUICK CHECK-IN' }), el('h1', { text: '寝室便捷登记' }), el('p', { class: 'quick-subtitle', text: '输入寝室长学号和寝室号，登记当晚情况。' }), el('div', { class: 'notice warning', text: '无需登录：学号与寝室号不能证明填报人身份，请仅由负责人如实登记。不要在公共设备保存信息。' }), panel('进入登记', '凌晨 00:00—07:59 显示上一晚；08:00 起显示当天。', form));
    if (message) feedback(result, message);
  }
  function renderTask(message = '') {
    const task = state.task; const info = el('div', { class: 'quick-feedback' });
    const header = el('div', {}, el('div', { class: 'page-kicker', text: 'TONIGHT’S CHECK-IN' }), el('h1', { text: task.room }), el('p', { class: 'quick-subtitle', text: `${task.business_date} 晚 · 查寝 ${task.check_at.slice(11,16)} · 截止 ${task.due_at.slice(11,16)}` }), el('div', { class: 'quick-meta' }, el('span', { text: `成员 ${task.members.length} 人` }), el('span', { text: task.submitted_at ? `已初报 ${task.submitted_at}` : '尚未初报' }), el('span', { text: `状态：${task.phase === 'paused' ? '暂停' : task.phase === 'closed' ? '已结项' : task.phase === 'open' ? '开放' : '尚未开放'}` })), el('div', { class: 'notice warning', text: '此入口不验证身份。请先逐人核实；一键全到只填写状态，仍需勾选确认并提交。' }));
    const reload = button('重新读取', async () => {
      try { const data = await api('quick_lookup', state.identity); state.task = data.task; state.serverTime = data.server_time; state.retry = null; renderTask('已重新读取，请核对当前内容。'); }
      catch (error) { feedback(info, error.message, true); }
    }, 'secondary');
    const change = button('更换寝室', () => renderEntry(), 'secondary');
    const topActions = el('div', { class: 'quick-actions' }, reload, change);
    let content;
    if (!task.members.length) content = el('p', { class: 'muted', text: '当晚没有可登记的成员，请联系班委核实名单。' });
    else if (task.phase === 'closed' || task.phase === 'paused') content = el('p', { class: 'muted', text: '该任务已结项或暂停，不能在此修改，请联系班委。' });
    else if (!task.submitted_at) content = initialForm(task, info);
    else content = updateForm(task, info);
    root.replaceChildren(header, topActions, info, panel(task.submitted_at ? '当前返寝情况' : '整寝初报', '请核对每位同学，状态变化后及时更新。', content));
    if (message) feedback(info, message);
  }
  function initialForm(task, info) {
    const rows = [];
    const list = el('div');
    for (const member of task.members) {
      const status = el('select', { 'aria-label': `${member.name}的初报状态` }, ...[['', '请选择'], ['present', '已在寝'], ['away', '外出未归'], ['unknown', '待核实'], ['exempt', '已核实无需返寝']].map(([value, label]) => el('option', { value, text: label })));
      const location = el('input', { class: 'input', type: 'text', maxlength: 200, placeholder: '未归或待核实时填写查寝时的情况', 'aria-label': `${member.name}的初报去向` });
      const current = el('select', { 'aria-label': `${member.name}的当前状态` }, ...[['', '与查寝时一致'], ['present', '现在已在寝'], ['away', '现在外出未归'], ['unknown', '现在待核实'], ['exempt', '现在已核实无需返寝']].map(([value, label]) => el('option', { value, text: label })));
      const currentLocation = el('input', { class: 'input', type: 'text', maxlength: 200, placeholder: '当前外出或待核实时必填', 'aria-label': `${member.name}的当前去向` });
      const occurred = el('input', { class: 'input', type: 'datetime-local', 'aria-label': `${member.name}的变化时间` });
      currentLocation.disabled = true; occurred.disabled = true;
      current.addEventListener('change', () => { const needsLocation = ['away','unknown'].includes(current.value); currentLocation.disabled = !needsLocation; if (!needsLocation) currentLocation.value = ''; occurred.disabled = !current.value; if (!current.value) occurred.value = ''; });
      if (member.immutable) { status.value = member.initial_status; status.disabled = true; location.value = member.initial_location || ''; location.disabled = true; }
      const locationField = field('查寝时去向或已知情况', location);
      const toggleLocation = () => { locationField.hidden = !['away','unknown'].includes(status.value); if (locationField.hidden && !member.immutable) location.value = ''; };
      status.addEventListener('change', toggleLocation); toggleLocation();
      const currentDetails = el('details', { class: 'quick-details' }, el('summary', {}, '迟报时情况已变化？填写当前状态'), field('现在的状态', current, '如查寝后已返寝或去向改变，单独填写当前情况。'), field('当前去向或已知情况', currentLocation), field('变化发生时间（选填）', occurred, '不确定时留空，以服务器接收时间记录。'));
      const wrapper = el('div', { class: 'quick-member' }, el('div', { class: 'quick-member-head' }, el('h3', { text: member.name }), member.immutable ? el('span', { class: 'tag', text: '原始记录已存在，不可覆盖' }) : null), member.immutable ? el('p', { class: 'inline-hint', text: `已登记：${labels[member.initial_status] || member.initial_status}` }) : field('查寝时状态', status), member.immutable ? null : locationField, member.immutable ? null : currentDetails);
      list.append(wrapper); rows.push({ member, status, location, current, currentLocation, occurred, toggleLocation });
    }
    const all = button('一键全到', () => { rows.filter(row => !row.member.immutable).forEach(row => { row.status.value = 'present'; row.location.value = ''; row.toggleLocation(); row.current.value = ''; row.current.dispatchEvent(new Event('change')); }); feedback(info, '已为可编辑成员填写“已在寝”，请逐人核实查寝时情况后确认提交。'); }, 'soft');
    const confirm = el('input', { type: 'checkbox', required: true }); const submit = el('button', { type: 'submit', class: 'button' }, '确认提交整寝初报');
    const form = el('form', {}, el('div', { class: 'quick-actions' }, all), list, el('label', { class: 'checkbox' }, confirm, '我已逐人核实上述情况，确认如实提交。'), submit);
    if (task.phase !== 'open') { all.disabled = true; submit.disabled = true; form.prepend(el('div', { class: 'notice neutral', text: '查寝尚未开放，可以查看名单，开放后重新读取并提交。' })); }
    form.addEventListener('submit', async event => {
      event.preventDefault(); if (!form.reportValidity()) return;
      const members = rows.map(({ member, status, location, current, currentLocation, occurred }) => ({ student_id: member.student_id, status: member.immutable ? member.initial_status : status.value, location: member.immutable ? member.initial_location || '' : location.value.trim(), ...(!member.immutable && current.value ? { current_status: current.value, current_location: currentLocation.value.trim(), ...(occurred.value ? { occurred_at: occurred.value.replace('T',' ') + ':00' } : {}) } : {}) }));
      if (members.some((member, index) => !rows[index].member.immutable && !member.status)) { feedback(info, '请逐人选择状态，或核实后点击一键全到。', true); return; }
      if (members.some((member, index) => !rows[index].member.immutable && ['away','unknown'].includes(member.status) && !member.location)) { feedback(info, '外出未归或待核实人员请填写查寝时的已知情况。', true); return; }
      if (members.some(member => ['away','unknown'].includes(member.current_status) && !member.current_location)) { feedback(info, '当前外出未归或待核实人员请填写当前已知情况。', true); return; }
      await busy(submit, async () => {
        try { const result = await request('quick_initial', { ...state.identity, version: task.version, roster_version: task.roster_version, members }); state.task = result.task; renderTask('整寝初报已保存。后续返寝请在下方及时更新。'); }
        catch (error) { feedback(info, error.message, true); }
      });
    });
    return form;
  }
  function updateForm(task, info) {
    const list = el('div');
    for (const member of task.members) {
      const select = el('select', { 'aria-label': `${member.name}的当前状态` }, ...[['present','已在寝'], ['away','外出未归'], ['unknown','待核实'], ['exempt','已核实无需返寝']].map(([value,label]) => el('option', { value, text: label })));
      select.value = member.current_status === 'unreported' ? 'unknown' : member.current_status;
      const location = el('input', { class: 'input', type: 'text', maxlength: 200, placeholder: '外出未归或待核实时必填' });
      const locationField = field('当前去向或已知情况', location);
      const toggleLocation = () => { locationField.hidden = !['away','unknown'].includes(select.value); if (locationField.hidden) location.value = ''; };
      select.addEventListener('change', toggleLocation); toggleLocation();
      const submit = el('button', { type: 'submit', class: 'button secondary small' }, '保存此人最新状态');
      const form = el('form', { class: 'quick-member' }, el('div', { class: 'quick-member-head' }, el('h3', { text: member.name }), el('span', { class: 'tag', text: labels[member.current_status] || member.current_status })), field('更新为', select), locationField, submit);
      form.addEventListener('submit', async event => {
        event.preventDefault();
        if (['away','unknown'].includes(select.value) && !location.value.trim()) { feedback(info, '外出未归或待核实人员请填写当前已知情况。', true); return; }
        await busy(submit, async () => {
          try { const result = await request('quick_update', { ...state.identity, version: task.version, student_ids: [member.student_id], status: select.value, location: location.value.trim() }); state.task = result.task; renderTask('最新情况已保存，原始初报仍保留。'); }
          catch (error) { feedback(info, error.message, true); }
        });
      });
      list.append(form);
    }
    return list;
  }
  window.addEventListener('pagehide', () => { state.identity = null; state.task = null; state.retry = null; root.replaceChildren(); });
  window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
  api('session').then(() => renderEntry()).catch(error => renderEntry(error.message));
})();
