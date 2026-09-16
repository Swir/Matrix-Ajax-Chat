(() => {
  'use strict';

  const app = document.getElementById('app');
  const canvas = document.getElementById('matrix-rain');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

  function matrixRain() {
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    let cols = [];
    const chars = '01SWIR<>[]{}:/#';
    const resize = () => {
      const dpr = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
      canvas.width = Math.floor(innerWidth * dpr);
      canvas.height = Math.floor(innerHeight * dpr);
      canvas.style.width = `${innerWidth}px`;
      canvas.style.height = `${innerHeight}px`;
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      cols = Array(Math.ceil(innerWidth / 18)).fill(0).map(() => Math.random() * -40);
    };
    resize();
    addEventListener('resize', resize, { passive: true });
    setInterval(() => {
      ctx.fillStyle = 'rgba(3,6,17,.12)';
      ctx.fillRect(0, 0, innerWidth, innerHeight);
      ctx.font = '14px Consolas, monospace';
      ctx.fillStyle = '#4cc9ff';
      cols.forEach((y, i) => {
        ctx.fillText(chars[(Math.random() * chars.length) | 0], i * 18, y * 18);
        cols[i] = y * 18 > innerHeight && Math.random() > .975 ? 0 : y + 1;
      });
    }, 75);
  }
  matrixRain();

  if (!app) return;

  const currentUser = app.dataset.currentUser || '';
  const labels = {
    global: app.dataset.globalLabel || 'Global',
    empty: app.dataset.emptyLabel || 'No messages.',
    invite: app.dataset.inviteLabel || 'Invite',
    pending: app.dataset.pendingLabel || 'Pending',
    accepted: app.dataset.acceptedLabel || 'Private',
    declined: app.dataset.declinedLabel || 'Declined',
    accept: app.dataset.acceptLabel || 'Accept',
    decline: app.dataset.declineLabel || 'Decline',
  };

  const messagesEl = document.getElementById('messages');
  const onlineEl = document.getElementById('online-list');
  const countEl = document.getElementById('online-count');
  const channelTitle = document.getElementById('channel-title');
  const composer = document.getElementById('composer');
  const input = document.getElementById('message-input');
  const fileInput = document.getElementById('file-input');
  const inviteBanner = document.getElementById('invite-banner');
  const stateEl = document.getElementById('connection-state');
  let target = 'global';
  let inviteStates = {};
  let lastRenderSignature = '';

  async function request(action, options = {}) {
    const url = new URL('api.php', location.href);
    url.searchParams.set('action', action);
    if (options.query) {
      Object.entries(options.query).forEach(([k, v]) => url.searchParams.set(k, v));
    }
    const init = { credentials: 'same-origin', cache: 'no-store', ...options.init };
    if (init.method && init.method !== 'GET') {
      init.headers = { ...(init.headers || {}), 'X-CSRF-Token': csrf };
    }
    const response = await fetch(url, init);
    const data = await response.json().catch(() => ({ ok: false, error: 'invalid_response' }));
    if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);
    return data;
  }

  function postForm(action, fields) {
    const body = new FormData();
    Object.entries(fields).forEach(([key, value]) => body.append(key, value));
    return request(action, { init: { method: 'POST', body } });
  }

  function setConnection(ok) {
    if (!stateEl) return;
    stateEl.textContent = ok ? 'online' : 'reconnecting…';
    stateEl.style.color = ok ? '' : '#ffb86b';
  }

  function setTarget(next) {
    target = next || 'global';
    channelTitle.textContent = target === 'global' ? labels.global : `@${target}`;
    document.getElementById('global-button')?.classList.toggle('active', target === 'global');
    lastRenderSignature = '';
    loadMessages(true);
  }

  function actionForState(name) {
    const state = inviteStates[name] || '';
    if (state === 'accepted') return labels.accepted;
    if (state === 'pending') return labels.pending;
    if (state === 'sent') return labels.pending;
    if (state === 'declined') return labels.invite;
    return labels.invite;
  }

  function renderOnline(users) {
    countEl.textContent = String(users.length + 1);
    onlineEl.replaceChildren();

    users.forEach((u) => {
      const row = document.createElement('button');
      row.className = 'user-row';
      row.type = 'button';
      row.style.setProperty('--user-color', u.color || '#4cc9ff');

      const dot = document.createElement('span');
      dot.className = 'user-dot';
      const name = document.createElement('span');
      name.className = 'user-name';
      name.textContent = u.name;
      const action = document.createElement('span');
      action.className = 'user-action';
      action.textContent = actionForState(u.name);

      row.append(dot, name, action);
      row.addEventListener('click', async () => {
        const state = inviteStates[u.name] || '';
        if (state === 'accepted') {
          setTarget(u.name);
          return;
        }
        if (state === 'pending') {
          showInviteBanner(u.name);
          return;
        }
        try {
          await postForm('invite', { target: u.name });
          inviteStates[u.name] = 'sent';
          action.textContent = labels.pending;
        } catch (e) {
          console.error(e);
        }
      });
      onlineEl.append(row);
    });
  }

  function showInviteBanner(peer) {
    inviteBanner.classList.remove('hidden');
    inviteBanner.replaceChildren();
    const text = document.createElement('span');
    text.textContent = `@${peer}`;
    const accept = document.createElement('button');
    accept.className = 'primary';
    accept.textContent = labels.accept;
    const decline = document.createElement('button');
    decline.textContent = labels.decline;

    accept.addEventListener('click', async () => {
      await postForm('respond', { target: peer, decision: 'accept' });
      inviteStates[peer] = 'accepted';
      inviteBanner.classList.add('hidden');
      setTarget(peer);
    });
    decline.addEventListener('click', async () => {
      await postForm('respond', { target: peer, decision: 'decline' });
      inviteStates[peer] = 'declined';
      inviteBanner.classList.add('hidden');
    });

    inviteBanner.append(text, accept, decline);
  }

  function refreshInviteBanner() {
    inviteBanner.classList.add('hidden');
    for (const [peer, state] of Object.entries(inviteStates)) {
      if (state === 'pending') {
        showInviteBanner(peer);
        break;
      }
    }
  }

  function uploadNode(token) {
    const a = document.createElement('a');
    a.className = 'download-chip';
    a.href = `download.php?token=${encodeURIComponent(token)}`;
    a.textContent = '⇩ secure attachment';
    return a;
  }

  function renderMessages(items, forceScroll = false) {
    const signature = items.map(m => m.id).join(',');
    if (signature === lastRenderSignature) return;
    const nearBottom = messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 120;
    lastRenderSignature = signature;
    messagesEl.replaceChildren();

    if (!items.length) {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = labels.empty;
      messagesEl.append(empty);
      return;
    }

    items.forEach((m) => {
      const card = document.createElement('article');
      card.className = `message${m.sender === currentUser ? ' own' : ''}`;
      const head = document.createElement('div');
      head.className = 'message-head';
      const sender = document.createElement('span');
      sender.className = 'sender';
      sender.style.setProperty('--sender-color', m.color || '#4cc9ff');
      sender.textContent = m.sender;
      const time = document.createElement('span');
      time.className = 'time';
      time.textContent = m.time;
      head.append(sender, time);

      const body = document.createElement('div');
      body.className = 'message-body';
      if (typeof m.msg === 'string' && m.msg.startsWith('::UPLOAD::')) {
        body.append(uploadNode(m.msg.slice('::UPLOAD::'.length)));
      } else {
        body.textContent = m.msg || '';
      }

      card.append(head, body);
      messagesEl.append(card);
    });

    if (forceScroll || nearBottom) messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  async function loadMessages(forceScroll = false) {
    try {
      const data = await request('messages', { query: { target } });
      inviteStates = data.invites || {};
      renderMessages(data.messages || [], forceScroll);
      refreshInviteBanner();
      setConnection(true);
    } catch (e) {
      console.error(e);
      setConnection(false);
    }
  }

  async function loadOnline() {
    try {
      const data = await request('online');
      renderOnline(data.users || []);
      setConnection(true);
    } catch (e) {
      console.error(e);
      setConnection(false);
    }
  }

  composer.addEventListener('submit', async (event) => {
    event.preventDefault();
    const message = input.value.trim();
    if (!message) return;
    input.disabled = true;
    try {
      await postForm('send', { message, target });
      input.value = '';
      await loadMessages(true);
    } catch (e) {
      console.error(e);
      if (e.message === 'private_not_accepted') setTarget('global');
    } finally {
      input.disabled = false;
      input.focus();
    }
  });

  fileInput.addEventListener('change', async () => {
    const file = fileInput.files?.[0];
    if (!file) return;
    const body = new FormData();
    body.append('file', file);
    body.append('target', target);
    try {
      await request('upload', { init: { method: 'POST', body, headers: { 'X-CSRF-Token': csrf } } });
      await loadMessages(true);
    } catch (e) {
      console.error(e);
      alert(`Upload failed: ${e.message}`);
    } finally {
      fileInput.value = '';
    }
  });

  document.getElementById('global-button')?.addEventListener('click', () => setTarget('global'));
  document.getElementById('back-global')?.addEventListener('click', () => setTarget('global'));
  document.getElementById('profile-color')?.addEventListener('change', (event) => {
    postForm('color', { color: event.target.value }).catch(console.error);
  });

  loadOnline();
  loadMessages(true);
  setInterval(loadMessages, 1600);
  setInterval(loadOnline, 4500);
  setInterval(() => postForm('presence', {}).catch(() => setConnection(false)), 9000);

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(() => {});
  }
})();
