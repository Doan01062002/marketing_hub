// CẦU NỐI ZALO CÁ NHÂN cho Marketing Hub (zca-js — API KHÔNG CHÍNH THỨC, giả lập Zalo Web).
// Chạy thường trực (systemd, user wsvn), chỉ nghe 127.0.0.1. PHP gọi qua HTTP kèm X-ZB-Key.
// Dùng cho NỘI BỘ: nhắn bạn bè Zalo + nhận tin đến đẩy vào Cửa sổ chat (AI Chat tuỳ chọn).
//   GET  /status                 → trạng thái + QR (data URL) khi đang chờ quét
//   POST /login                  → bắt đầu đăng nhập QR (quét bằng app Zalo trên điện thoại)
//   POST /logout                 → đăng xuất, xoá phiên
//   GET  /friends                → danh sách bạn bè [{uid,name,phone,avatar}]
//   POST /find  {phones:[]}      → tra uid theo số điện thoại
//   POST /send  {uid,text}       → gửi tin cá nhân
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { Zalo, ThreadType, LoginQRCallbackEventType } from 'zca-js';

const DIR = path.dirname(fileURLToPath(import.meta.url));
const CFG = JSON.parse(fs.readFileSync(path.join(DIR, 'config.json'), 'utf8')); // {port, key, hook_url, hook_key}
const SESSION = path.join(DIR, 'session.json');
const log = (...a) => console.log(new Date().toISOString(), ...a);

let api = null;                 // API zca-js khi đã đăng nhập
let st = { state: 'off', name: '', avatar: '', uid: '', qr: '', error: '', since: null };
let friendsCache = { at: 0, list: [], errAt: 0 };
const hubSent = new Map();     // "uid|nội dung" → lúc gửi (tin do Hub/AI gửi)
const infoCache = new Map();   // uid → {name, avatar, at}
let reconnectTimer = null;

function set(p) { st = { ...st, ...p }; }
function saveSession(cred) { fs.writeFileSync(SESSION, JSON.stringify(cred), { mode: 0o600 }); }

async function hook(payload) {
  if (!CFG.hook_url) return;
  try {
    await fetch(CFG.hook_url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-ZB-Key': CFG.hook_key || CFG.key }, body: JSON.stringify(payload), signal: AbortSignal.timeout(15000) });
  } catch (e) { log('hook lỗi', e.message); }
}

async function afterLogin(a) {
  api = a;
  friendsCache = { at: 0, list: [] };
  try {
    const info = await api.fetchAccountInfo();
    const p = info?.profile || info || {};
    set({ state: 'online', uid: String(p.userId || api.getOwnId?.() || ''), name: p.displayName || p.zaloName || st.name, avatar: p.avatar || st.avatar, qr: '', error: '', since: new Date().toISOString() });
  } catch { set({ state: 'online', uid: String(api.getOwnId?.() || ''), qr: '', error: '', since: new Date().toISOString() }); }
  try { const ctx = api.getContext(); saveSession({ imei: ctx.imei, cookie: ctx.cookie.toJSON().cookies, userAgent: ctx.userAgent }); } catch (e) { log('không lưu được phiên', e.message); }
  const L = api.listener;
  L.on('message', (m) => {
    if (m.type !== ThreadType.User) return;                 // chỉ tin cá nhân, bỏ nhóm
    const d = m.data || {};
    const text = typeof d.content === 'string' ? d.content : (d.content?.title || d.content?.description || '[' + (d.msgType || 'tệp') + ']');
    const uid = String(m.threadId);
    if (m.isSelf) { const k = uid + '|' + String(text).trim(), t = hubSent.get(k); if (t && Date.now() - t < 120_000) { hubSent.delete(k); return; } }
    const peer0 = friendsCache.list.find((f) => f.uid === uid) || infoCache.get(uid);
    (peer0 && peer0.avatar ? Promise.resolve(peer0) : userInfo(uid).catch(() => ({}))).then((peer) => hook({ event: 'message', dir: m.isSelf ? 'out' : 'in', uid: String(m.threadId), name: m.isSelf ? (peer.name || '') : (d.dName || peer.name || ''), avatar: peer.avatar || '', text, msgId: String(d.msgId || d.cliMsgId || Date.now()), ts: Number(d.ts) || Date.now(), owner: st.uid }));
  });
  L.on('closed', (code, reason) => { log('listener đóng', code, reason); if (st.state === 'online') scheduleReconnect('Mất kết nối Zalo (' + (reason || code) + ')'); });
  L.on('error', (e) => log('listener lỗi', e?.message || e));
  L.start({ retryOnClose: true });
  log('đã đăng nhập', st.name, st.uid);
  setTimeout(() => friends().catch((e) => log('chưa tải được bạn bè', e.message)), 30_000);   // để phiên ổn định rồi mới tải, tránh 429
  hook({ event: 'online', uid: st.uid, name: st.name, avatar: st.avatar });
}

function scheduleReconnect(why) {
  set({ state: 'reconnecting', error: why });
  clearTimeout(reconnectTimer);
  reconnectTimer = setTimeout(loginSaved, 60_000);
}

async function loginSaved() {
  if (!fs.existsSync(SESSION)) { set({ state: 'off' }); return; }
  try {
    const cred = JSON.parse(fs.readFileSync(SESSION, 'utf8'));
    const z = new Zalo({ selfListen: true, checkUpdate: false, logging: false });
    await afterLogin(await z.login(cred));
  } catch (e) {
    log('đăng nhập lại thất bại', e.message);
    set({ state: 'error', error: 'Phiên Zalo hết hạn hoặc bị đăng xuất — quét QR lại (' + e.message + ')' });
    hook({ event: 'logout', reason: e.message });
  }
}

async function loginQR() {
  if (st.state === 'qr' || st.state === 'scanned') return;
  set({ state: 'qr', qr: '', error: '', name: '', avatar: '' });
  const z = new Zalo({ selfListen: true, checkUpdate: false, logging: false });
  try {
    const a = await z.loginQR({}, (ev) => {
      if (ev.type === LoginQRCallbackEventType.QRCodeGenerated) set({ state: 'qr', qr: 'data:image/png;base64,' + ev.data.image });
      else if (ev.type === LoginQRCallbackEventType.QRCodeScanned) set({ state: 'scanned', name: ev.data.display_name, avatar: ev.data.avatar, qr: '' });
      else if (ev.type === LoginQRCallbackEventType.QRCodeExpired) { set({ state: 'off', qr: '', error: 'Mã QR hết hạn — bấm tạo mã mới' }); ev.actions.abort(); }
      else if (ev.type === LoginQRCallbackEventType.QRCodeDeclined) { set({ state: 'off', qr: '', error: 'Đã từ chối trên điện thoại' }); ev.actions.abort(); }
      else if (ev.type === LoginQRCallbackEventType.GotLoginInfo) saveSession({ imei: ev.data.imei, cookie: ev.data.cookie, userAgent: ev.data.userAgent });
    });
    if (a) await afterLogin(a);
  } catch (e) { if (st.state !== 'off') set({ state: 'error', qr: '', error: 'Đăng nhập QR lỗi: ' + e.message }); log('loginQR lỗi', e.message); }
}

async function friends() {
  if (!api) throw new Error('Zalo chưa đăng nhập');
  if (Date.now() - friendsCache.at < 10 * 60_000) return friendsCache.list;
  if (Date.now() - friendsCache.errAt < 15 * 60_000) throw new Error('Zalo đang giới hạn tải danh sách bạn bè — thử lại sau 15 phút');
  const out = [];
  let r;   // gọi MỘT lần như Zalo Web (count mặc định 20000) — chia trang nhiều lần dễ bị 429
  try { r = await api.getAllFriends(); } catch (e) { friendsCache.errAt = Date.now(); throw e; }
  for (const u of (Array.isArray(r) ? r : [])) out.push({ uid: String(u.userId), name: u.displayName || u.zaloName || '', phone: String(u.phoneNumber || '').replace(/\D/g, ''), avatar: u.avatar || '' });
  friendsCache = { at: Date.now(), list: out };
  return out;
}

// Thông tin 1 người (tên + ảnh đại diện) — dùng khi danh sách bạn bè chưa có. Cache 6 giờ.
async function userInfo(uid) {
  uid = String(uid);
  const c = infoCache.get(uid); if (c && Date.now() - c.at < 6 * 3600_000) return c;
  const f = friendsCache.list.find((x) => x.uid === uid); if (f && f.avatar) return f;
  if (!api) return {};
  const r = await api.getUserInfo(uid);
  const p = r?.changed_profiles?.[uid] || r?.changed_profiles?.[uid + '_0'] || Object.values(r?.changed_profiles || {})[0] || {};
  const v = { uid, name: p.displayName || p.zaloName || '', avatar: p.avatar || '', at: Date.now() };
  infoCache.set(uid, v); return v;
}

const json = (res, code, obj) => { res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8' }); res.end(JSON.stringify(obj)); };
const body = (req) => new Promise((ok) => { let s = ''; req.on('data', (c) => { s += c; if (s.length > 1e6) req.destroy(); }); req.on('end', () => { try { ok(JSON.parse(s || '{}')); } catch { ok({}); } }); });

http.createServer(async (req, res) => {
  if (req.headers['x-zb-key'] !== CFG.key) return json(res, 403, { error: 'sai khoá' });
  const url = new URL(req.url, 'http://x');
  try {
    if (req.method === 'GET' && url.pathname === '/status') return json(res, 200, st);
    if (req.method === 'POST' && url.pathname === '/login') { loginQR(); await new Promise((r) => setTimeout(r, 2500)); return json(res, 200, st); }
    if (req.method === 'POST' && url.pathname === '/logout') {
      try { api?.listener?.stop(); } catch {}
      api = null; clearTimeout(reconnectTimer); try { fs.unlinkSync(SESSION); } catch {}
      set({ state: 'off', name: '', avatar: '', uid: '', qr: '', error: '' }); return json(res, 200, st);
    }
    if (req.method === 'GET' && url.pathname === '/info') {   // ?uids=1,2,3 (tối đa 30, giãn 400ms)
      const out = {};
      for (const u of (url.searchParams.get('uids') || '').split(',').filter(Boolean).slice(0, 30)) {
        try { out[u] = await userInfo(u); } catch (e) { out[u] = { error: e.message }; }
        await new Promise((r) => setTimeout(r, 400));
      }
      return json(res, 200, { users: out });
    }
    if (req.method === 'GET' && url.pathname === '/friends') return json(res, 200, { friends: await friends() });
    if (req.method === 'POST' && url.pathname === '/find') {
      if (!api) throw new Error('Zalo chưa đăng nhập');
      const b = await body(req); const phones = (b.phones || []).map((p) => String(p).replace(/\D/g, '')).filter(Boolean).slice(0, 50);
      const out = {};
      for (const p of phones) {   // từng số một, chậm rãi — tra số là thao tác Zalo theo dõi chặt
        try { const u = await api.findUser(p); if (u?.uid) out[p] = { uid: String(u.uid), name: u.display_name || u.zalo_name || '', avatar: u.avatar || '' }; } catch {}
        await new Promise((r) => setTimeout(r, 800));
      }
      return json(res, 200, { users: out });
    }
    if (req.method === 'POST' && url.pathname === '/send') {
      if (!api) throw new Error('Zalo chưa đăng nhập');
      const b = await body(req); const text = String(b.text || '').slice(0, 2000);
      if (!b.uid || !text.trim()) return json(res, 400, { error: 'thiếu người nhận hoặc nội dung' });
      hubSent.set(String(b.uid) + '|' + text.trim(), Date.now());   // tin Hub tự gửi: Hub đã lưu, bỏ qua bản dội lại từ listener
      const r = await api.sendMessage({ msg: text }, String(b.uid), ThreadType.User);
      return json(res, 200, { ok: true, msgId: String(r?.message?.msgId || Date.now()) });
    }
    return json(res, 404, { error: 'không có' });
  } catch (e) { return json(res, 500, { error: e.message }); }
}).listen(CFG.port || 8721, '127.0.0.1', () => { log('zalo-bridge nghe 127.0.0.1:' + (CFG.port || 8721)); loginSaved(); });
