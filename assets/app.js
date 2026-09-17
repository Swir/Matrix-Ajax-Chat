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
    soundOn: app.dataset.soundOnLabel || 'Sound: on',
    soundOff: app.dataset.soundOffLabel || 'Sound: off',
    uploading: app.dataset.uploadingLabel || 'Uploading file…',
    cmdHelp: app.dataset.cmdHelp || '/help /ping /clear /whoami',
    cmdPong: app.dataset.cmdPong || 'PONG',
    cmdCleared: app.dataset.cmdCleared || 'Terminal view cleared.',
    cmdUnknown: app.dataset.cmdUnknown || 'Unknown command. Use /help.',
  };

  const messagesEl = document.getElementById('messages');
  const onlineEl = document.getElementById('online-list');
  const privateEl = document.getElementById('private-list');
  const countEl = document.getElementById('online-count');
  const channelTitle = document.getElementById('channel-title');
  const composer = document.getElementById('composer');
  const input = document.getElementById('message-input');
  const fileInput = document.getElementById('file-input');
  const inviteBanner = document.getElementById('invite-banner');
  const stateEl = document.getElementById('connection-state');
  const typingEl = document.getElementById('typing-status');
  const uploadStatusEl = document.getElementById('upload-status');
  const charCounterEl = document.getElementById('char-counter');
  const soundToggle = document.getElementById('sound-toggle');
  const fullscreenToggle = document.getElementById('fullscreen-toggle');

  let target = 'global';
  let inviteStates = {};
  let lastRenderSignature = '';
  let lastItems = [];
  let lastLatencyMs = null;
  let onlineNames = new Set();
  const clearedThrough = new Map();
  const systemNotices = [];
  const lastMaxByTarget = new Map();
  const initializedTargets = new Set();
  const notifiedPendingInvites = new Set();

  let soundEnabled = localStorage.getItem('matrix-chat-sound') !== '0';
  let audioCtx = null;

  function ensureAudio() {
    if (!soundEnabled) return null;
    const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextCtor) return null;
    if (!audioCtx) audioCtx = new AudioContextCtor();
    if (audioCtx.state === 'suspended') audioCtx.resume().catch(() => {});
    return audioCtx;
  }

  function beep(freq = 700, duration = 0.05, volume = 0.025) {
    const ctx = ensureAudio();
    if (!ctx) return;
    try {
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.value = freq;
      gain.gain.setValueAtTime(volume, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.00001, ctx.currentTime + duration);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start();
      osc.stop(ctx.currentTime + duration);
    } catch (_) {}
  }

  function alertBeep() {
    beep(360, 0.12, 0.03);
    setTimeout(() => beep(260, 0.16, 0.03), 140);
  }

  document.addEventListener('pointerdown', ensureAudio, { once: true });

  function refreshSoundButton() {
    if (soundToggle) soundToggle.textContent = soundEnabled ? labels.soundOn : labels.soundOff;
  }
  refreshSoundButton();

  async function request(action, options = {}) {
    const started = performance.now();
    const url = new URL('api.php', location.href);
    url.searchParams.set('action', action);
    if (options.query) {
      Object.entries(options.query).forEach(([k, v]) => url.searchParams.set(k, v));
    }
    const init = { credentials: 'same-origin', cache: 'no-store', ...options.init };
    if (init.method && init.method !== 'GET') {
      init.headers = { ...(init.headers || {}), 'X-CSRF-Token': csrf };
    }

    try {
      const response = await fetch(url, init);
      const data = await response.json().catch(() => ({ ok: false, error: 'invalid_response' }));
      if (!response.ok || !data.ok) throw new Error(data.error || `HTTP ${response.status}`);
      lastLatencyMs = Math.max(0, Math.round(performance.now() - started));
      setConnection(true, lastLatencyMs);
      return data;
    } catch (error) {
      setConnection(false);
      throw error;
    }
  }

  function postForm(action, fields) {
    const body = new FormData();
    Object.entries(fields).forEach(([key, value]) => body.append(key, value));
    return request(action, { init: { method: 'POST', body } });
  }

  function setConnection(ok, latency = null) {
    if (!stateEl) return;
    stateEl.textContent = ok ? `online${Number.isFinite(latency) ? ` · ${latency} ms` : ''}` : 'reconnecting…';
    stateEl.style.color = ok ? '' : '#ffb86b';
  }

  function setTarget(next) {
    target = next || 'global';
    channelTitle.textContent = target === 'global' ? labels.global : `@${target}`;
    document.getElementById('global-button')?.classList.toggle('active', target === 'global');
    lastRenderSignature = '';
    renderPrivateChannels();
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
    onlineNames = new Set(users.map((u) => u.name));
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

    renderPrivateChannels();
  }

  function renderPrivateChannels() {
    if (!privateEl) return;
    privateEl.replaceChildren();
    const peers = Object.entries(inviteStates)
      .filter(([, state]) => state === 'accepted')
      .map(([name]) => name)
      .sort((a, b) => a.localeCompare(b));

    peers.forEach((peer) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `private-channel${target === peer ? ' active' : ''}`;
      const status = document.createElement('span');
      status.className = `private-status${onlineNames.has(peer) ? ' online' : ''}`;
      const name = document.createElement('span');
      name.textContent = `@${peer}`;
      button.append(status, name);
      button.addEventListener('click', () => setTarget(peer));
      privateEl.append(button);
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
      renderPrivateChannels();
      beep(900, 0.05);
      setTarget(peer);
    });
    decline.addEventListener('click', async () => {
      await postForm('respond', { target: peer, decision: 'decline' });
      inviteStates[peer] = 'declined';
      inviteBanner.classList.add('hidden');
      renderPrivateChannels();
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

  function formatBytes(value) {
    const bytes = Number(value) || 0;
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  }

  function attachmentUrls(meta) {
    const params = new URLSearchParams();
    if (meta.token) params.set('token', meta.token);
    else params.set('legacy_message', String(meta.legacy_message || ''));
    const download = `download.php?${params.toString()}`;
    params.set('inline', '1');
    return { download, inline: `download.php?${params.toString()}` };
  }

  function attachmentNode(meta) {
    const wrap = document.createElement('div');
    wrap.className = 'attachment';
    const urls = attachmentUrls(meta);

    if (String(meta.mime || '').startsWith('image/')) {
      const img = document.createElement('img');
      img.className = 'attachment-preview';
      img.src = urls.inline;
      img.alt = meta.name || 'image';
      img.loading = 'lazy';
      img.addEventListener('click', () => window.open(urls.inline, '_blank', 'noopener'));
      wrap.append(img);
    }

    const a = document.createElement('a');
    a.className = 'download-chip';
    a.href = urls.download;
    a.textContent = `⇩ ${meta.name || 'attachment'} · ${formatBytes(meta.size)}`;
    wrap.append(a);
    return wrap;
  }

  function addSystemNotice(text) {
    systemNotices.push({ target, text: String(text) });
    if (systemNotices.length > 8) systemNotices.shift();
    lastRenderSignature = '';
    renderMessages(lastItems, true);
  }

  function renderSystemNotices() {
    systemNotices.filter((notice) => notice.target === target).forEach((notice) => {
      const card = document.createElement('article');
      card.className = 'message system-message';
      const body = document.createElement('div');
      body.className = 'message-body';
      body.textContent = notice.text;
      card.append(body);
      messagesEl.append(card);
    });
  }

  function renderMessages(items, forceScroll = false) {
    lastItems = items;
    const cutoff = clearedThrough.get(target) || 0;
    const visible = items.filter((m) => Number(m.id) > cutoff);
    const noticesKey = systemNotices.filter((n) => n.target === target).map((n) => n.text).join('|');
    const signature = `${visible.map((m) => `${m.id}:${m.attachment ? 'a' : ''}`).join(',')}::${noticesKey}`;
    if (signature === lastRenderSignature) return;
    const nearBottom = messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 120;
    lastRenderSignature = signature;
    messagesEl.replaceChildren();

    if (!visible.length && !systemNotices.some((n) => n.target === target)) {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = labels.empty;
      messagesEl.append(empty);
      return;
    }

    visible.forEach((m) => {
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
      if (m.attachment) {
        body.append(attachmentNode(m.attachment));
      } else if (typeof m.msg === 'string' && (m.msg.startsWith('::UPLOAD::') || m.msg.startsWith('::FILE_TAG::'))) {
        body.textContent = '[attachment unavailable]';
      } else {
        body.textContent = m.msg || '';
      }

      card.append(head, body);
      messagesEl.append(card);
    });

    renderSystemNotices();
    if (forceScroll || nearBottom) messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  function maybeNotifyMessages(items) {
    const maxId = items.reduce((max, item) => Math.max(max, Number(item.id) || 0), 0);
    const previous = lastMaxByTarget.get(target) || 0;
    if (initializedTargets.has(target) && maxId > previous) {
      const incoming = items.some((item) => Number(item.id) > previous && item.sender !== currentUser);
      if (incoming) beep(620, 0.08, 0.02);
    }
    lastMaxByTarget.set(target, maxId);
    initializedTargets.add(target);
  }

  function notifyNewInvites(nextStates) {
    Object.entries(nextStates).forEach(([peer, state]) => {
      if (state === 'pending' && !notifiedPendingInvites.has(peer)) {
        notifiedPendingInvites.add(peer);
        alertBeep();
      }
      if (state !== 'pending') notifiedPendingInvites.delete(peer);
    });
  }

  async function loadMessages(forceScroll = false) {
    try {
      const data = await request('messages', { query: { target } });
      notifyNewInvites(data.invites || {});
      inviteStates = data.invites || {};
      const items = data.messages || [];
      maybeNotifyMessages(items);
      renderMessages(items, forceScroll);
      renderPrivateChannels();
      refreshInviteBanner();
    } catch (e) {
      console.error(e);
    }
  }

  async function loadOnline() {
    try {
      const data = await request('online');
      renderOnline(data.users || []);
    } catch (e) {
      console.error(e);
    }
  }

  function updateComposerMeta() {
    if (!input || !charCounterEl || !typingEl) return;
    charCounterEl.textContent = `${input.value.length} / ${input.maxLength}`;
    typingEl.classList.toggle('hidden', input.value.length === 0);
  }

  function handleCommand(raw) {
    const command = raw.trim().split(/\s+/)[0].toLowerCase();
    if (command === '/help') {
      addSystemNotice(labels.cmdHelp);
    } else if (command === '/ping') {
      addSystemNotice(`${labels.cmdPong}: ${Number.isFinite(lastLatencyMs) ? lastLatencyMs : '--'} ms`);
    } else if (command === '/clear') {
      const maxId = lastItems.reduce((max, item) => Math.max(max, Number(item.id) || 0), 0);
      clearedThrough.set(target, maxId);
      systemNotices.splice(0, systemNotices.length, ...systemNotices.filter((n) => n.target !== target));
      addSystemNotice(labels.cmdCleared);
    } else if (command === '/whoami') {
      addSystemNotice(`ID: ${currentUser} | NODE: ${target}`);
    } else {
      addSystemNotice(labels.cmdUnknown);
    }
  }

  composer.addEventListener('submit', async (event) => {
    event.preventDefault();
    const message = input.value.trim();
    if (!message) return;

    if (message.startsWith('/')) {
      handleCommand(message);
      input.value = '';
      updateComposerMeta();
      return;
    }

    input.disabled = true;
    try {
      beep(900, 0.03, 0.018);
      await postForm('send', { message, target });
      input.value = '';
      updateComposerMeta();
      await loadMessages(true);
    } catch (e) {
      console.error(e);
      if (e.message === 'private_not_accepted') setTarget('global');
    } finally {
      input.disabled = false;
      input.focus();
    }
  });

  input.addEventListener('input', updateComposerMeta);
  updateComposerMeta();

  fileInput.addEventListener('change', async () => {
    const file = fileInput.files?.[0];
    if (!file) return;
    const body = new FormData();
    body.append('file', file);
    body.append('target', target);
    uploadStatusEl?.classList.remove('hidden');
    try {
      await request('upload', { init: { method: 'POST', body, headers: { 'X-CSRF-Token': csrf } } });
      beep(980, 0.05, 0.018);
      await loadMessages(true);
    } catch (e) {
      console.error(e);
      alert(`Upload failed: ${e.message}`);
    } finally {
      uploadStatusEl?.classList.add('hidden');
      fileInput.value = '';
    }
  });

  document.getElementById('global-button')?.addEventListener('click', () => setTarget('global'));
  document.getElementById('back-global')?.addEventListener('click', () => setTarget('global'));
  document.getElementById('profile-color')?.addEventListener('change', (event) => {
    postForm('color', { color: event.target.value }).then(() => beep(820, 0.04)).catch(console.error);
  });

  soundToggle?.addEventListener('click', () => {
    soundEnabled = !soundEnabled;
    localStorage.setItem('matrix-chat-sound', soundEnabled ? '1' : '0');
    refreshSoundButton();
    if (soundEnabled) beep(760, 0.05);
  });

  fullscreenToggle?.addEventListener('click', async () => {
    try {
      if (!document.fullscreenElement) await document.documentElement.requestFullscreen();
      else await document.exitFullscreen();
    } catch (_) {}
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
