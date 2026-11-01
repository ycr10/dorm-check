'use strict';

(() => {
  const footer = document.querySelector('.filing-footer');
  if (!footer) return;
  async function refresh() {
    try {
      const url = new URL('api/index.php?action=public_settings', location.href);
      const response = await fetch(url, { credentials: 'omit', cache: 'no-store' });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error('备案信息暂不可用');
      const { filing_icp: icp, filing_police: police } = result.data;
      const links = [];
      if (typeof icp === 'string' && icp) {
        const a = document.createElement('a');
        a.href = 'https://beian.miit.gov.cn/'; a.target = '_blank'; a.rel = 'noopener noreferrer'; a.textContent = icp;
        links.push(a);
      }
      const code = typeof police === 'string' ? police.match(/^[\p{Script=Han}]{1,10}公网安备([0-9]{8,30})号$/u) : null;
      if (code) {
        const a = document.createElement('a');
        a.href = `https://beian.mps.gov.cn/#/query/webSearch?code=${encodeURIComponent(code[1])}`;
        a.target = '_blank'; a.rel = 'noopener noreferrer';
        const img = document.createElement('img'); img.src = 'assets/police-filing.png'; img.alt = ''; img.width = 20; img.height = 20;
        a.append(img, document.createTextNode(police)); links.push(a);
      }
      footer.replaceChildren(...links); footer.hidden = links.length === 0;
    } catch {
      // Keep any already displayed information when a later refresh fails.
    }
  }
  window.refreshFiling = refresh;
  refresh();
})();
