-- Marketing Hub — phòng Marketing — schema (SQLite)
-- Chạy khi chưa có CSDL (api/_boot.php). Bảng/cột thêm sau này được
-- api/_boot.php tự bổ sung cho CSDL cũ (khối "Nâng cấp CSDL").

CREATE TABLE IF NOT EXISTS users (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  email TEXT UNIQUE NOT NULL,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'staff',      -- admin (quản trị) | manager (trưởng nhóm) | staff (nhân viên)
  title TEXT,                              -- chức danh hiển thị, vd "Giám đốc Marketing (CMO)"
  approve INTEGER NOT NULL DEFAULT 0,      -- có quyền duyệt hay không
  active INTEGER NOT NULL DEFAULT 1,       -- 0 = đã khoá, không đăng nhập được
  phone TEXT,
  scope TEXT,                              -- phạm vi hiển thị: Tập đoàn / Công ty / Chi nhánh / Brand…
  co TEXT, br TEXT,                        -- công ty / chi nhánh phụ trách (theo ORG ở frontend)
  last_login_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS campaigns (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  subject TEXT,
  co TEXT, br TEXT,                          -- công ty con / chi nhánh (ORG.companies[].branches[] phía frontend)
  state TEXT NOT NULL DEFAULT 'DRAFT',       -- IDEA/DRAFT/PLANNED/READY/ACTIVE/PAUSED/COMPLETED/REVIEWED/CANCELLED
  approval TEXT NOT NULL DEFAULT 'NA',       -- PENDING/APPROVED/REJECTED/CONDITIONAL/NA
  health TEXT NOT NULL DEFAULT 'ON_TRACK',   -- ON_TRACK/AT_RISK/BLOCKED
  owner_id TEXT REFERENCES users(id),
  channels TEXT,                             -- JSON array chuỗi
  budget INTEGER NOT NULL DEFAULT 0,
  spent INTEGER NOT NULL DEFAULT 0,
  leads INTEGER NOT NULL DEFAULT 0,
  roas REAL NOT NULL DEFAULT 0,
  start_date TEXT, end_date TEXT,
  objective TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS content_items (
  id TEXT PRIMARY KEY,
  title TEXT NOT NULL,
  type TEXT, pillar TEXT, subject TEXT,
  co TEXT, br TEXT,
  state TEXT NOT NULL DEFAULT 'IDEA',        -- IDEA/DRAFT/IN_REVIEW/CHANGES/APPROVED/PUBLISHED
  owner_id TEXT REFERENCES users(id),
  channel TEXT, due_date TEXT,
  campaign_id TEXT REFERENCES campaigns(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS tasks (
  id TEXT PRIMARY KEY,
  title TEXT NOT NULL,
  co TEXT, br TEXT,
  board TEXT NOT NULL,                       -- content/design/ads/research/lead
  col TEXT NOT NULL,                         -- tên cột kanban
  type TEXT,
  assignee_id TEXT REFERENCES users(id),
  assigner_id TEXT REFERENCES users(id),
  due_date TEXT,
  pri TEXT NOT NULL DEFAULT 'P2',            -- P0..P3
  life TEXT NOT NULL DEFAULT 'DRAFT',
  apr TEXT NOT NULL DEFAULT 'NA',            -- PENDING/APPROVED/REJECTED/NA
  health TEXT NOT NULL DEFAULT 'ON_TRACK',
  campaign_id TEXT REFERENCES campaigns(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS leads (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  phone TEXT,
  email TEXT,
  notes TEXT,
  source_ref TEXT,                           -- nguồn chi tiết, vd 'comment:facebook_page:123_456'
  co TEXT, br TEXT,
  campaign_id TEXT REFERENCES campaigns(id),
  source TEXT,
  score INTEGER NOT NULL DEFAULT 0,
  state TEXT NOT NULL DEFAULT 'CREATED',     -- CREATED/VALIDATING/ROUTED/DELIVERED/ACCEPTED/REJECTED
  subject TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Mỗi hàng = MỘT kênh cần đo (1 website, 1 kênh YouTube, 1 Fanpage, 1 tài khoản quảng cáo).
-- Một loại có thể có bao nhiêu kênh cũng được.
CREATE TABLE IF NOT EXISTS connections (
  id TEXT PRIMARY KEY,
  provider TEXT NOT NULL,                    -- ga4 | gsc | gbp | youtube | tiktok | google_ads | facebook_page | meta_ads
  label TEXT NOT NULL DEFAULT '',            -- tên kênh do người dùng đặt / lấy từ nền tảng
  status TEXT NOT NULL DEFAULT 'disconnected', -- connected | disconnected | error
  external_account_id TEXT,                  -- GA4 property id / YouTube channel id / Ads customer id / Page id / act_xxx
  access_token TEXT,
  refresh_token TEXT,
  expires_at TEXT,
  last_error TEXT,
  last_metrics TEXT,                         -- JSON số liệu lần đồng bộ gần nhất
  auth_method TEXT NOT NULL DEFAULT 'oauth', -- oauth (đăng nhập) | manual (dán ID + token/API key)
  secret TEXT,                               -- manual: Page token / YouTube API key / GA4 service-account JSON / Meta system-user token
  client_id TEXT,                            -- YouTube nhập tay: OAuth client riêng của kênh (dùng với refresh_token)
  client_secret TEXT,
  connected_by TEXT REFERENCES users(id),
  connected_at TEXT,
  last_synced_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_connections_provider ON connections(provider);

CREATE TABLE IF NOT EXISTS insights (
  id TEXT PRIMARY KEY,
  question TEXT NOT NULL,
  insight TEXT NOT NULL,
  source TEXT, confidence TEXT, subject TEXT, state TEXT NOT NULL DEFAULT 'Insight',
  created_by TEXT REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS segments (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  size INTEGER NOT NULL DEFAULT 0,
  value TEXT, campaigns_fit TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS trends (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  volume_change TEXT, direction INTEGER NOT NULL DEFAULT 1,
  data_points TEXT, note TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS competitors (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  share_of_voice INTEGER NOT NULL DEFAULT 0,
  spend_estimate TEXT, latest_move TEXT, threat TEXT,
  website TEXT, web_snapshot TEXT, web_checked_at TEXT, web_change TEXT, -- theo dõi website đối thủ (trang mới, tiêu đề…)
  place_id TEXT, place_name TEXT, place_rating REAL, place_reviews INTEGER, place_address TEXT, place_url TEXT, place_synced_at TEXT, teardowns TEXT, -- Google Maps & Phiếu học hỏi đối thủ
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Nhật ký thao tác: ai làm gì, lúc nào — dùng để theo dõi công việc nhân viên.
CREATE TABLE IF NOT EXISTS activity_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id TEXT REFERENCES users(id),
  action TEXT NOT NULL,                      -- create | update | delete | approve | reject | login | sync …
  entity TEXT NOT NULL,                      -- campaign | content | task | lead | connection | user | okr | brand …
  entity_id TEXT,
  detail TEXT,                               -- mô tả ngắn tiếng Việt
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_activity_user ON activity_log(user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_activity_time ON activity_log(created_at);

-- Lịch sử số liệu kênh: mỗi lần đồng bộ ghi một bản chụp (tối đa 1 bản/kênh/ngày).
CREATE TABLE IF NOT EXISTS metrics_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  connection_id TEXT NOT NULL,
  provider TEXT NOT NULL,
  day TEXT NOT NULL,                         -- YYYY-MM-DD
  metrics TEXT NOT NULL,                     -- JSON giống connections.last_metrics
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(connection_id, day)
);

-- Mục tiêu & kết quả then chốt (OKR) theo quý.
CREATE TABLE IF NOT EXISTS okrs (
  id TEXT PRIMARY KEY,
  quarter TEXT NOT NULL,                     -- vd 2026-Q4
  objective TEXT NOT NULL,
  key_result TEXT NOT NULL,
  target REAL NOT NULL DEFAULT 0,
  current REAL NOT NULL DEFAULT 0,
  unit TEXT,                                 -- lead | ₫ | % | x | …
  direction INTEGER NOT NULL DEFAULT 1,      -- 1 = càng cao càng tốt, -1 = càng thấp càng tốt (vd CPL)
  metric TEXT NOT NULL DEFAULT 'manual',     -- manual (nhập tay) | leads | spend | roas | cpl | content_published | tasks_done | … (tự tính từ dữ liệu thật)
  owner_id TEXT REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Thương hiệu & thông điệp: thông điệp chính, giọng văn, quy tắc dùng logo/màu…
CREATE TABLE IF NOT EXISTS brand_items (
  id TEXT PRIMARY KEY,
  kind TEXT NOT NULL,                        -- message | tone | rule | asset
  title TEXT NOT NULL,
  content TEXT,
  subject TEXT,
  created_by TEXT REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ===================== v4: AI, đăng bài, chăm sóc bình luận, tự động hoá =====================

-- Cấu hình chỉnh trên giao diện (khoá API AI, SMTP, webhook…). Khoá bí mật không bao giờ trả ra trình duyệt.
CREATE TABLE IF NOT EXISTS app_settings (
  key TEXT PRIMARY KEY,
  value TEXT,
  updated_by TEXT,
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Bài đăng đa kênh: một bài → nhiều kênh đích (post_targets).
CREATE TABLE IF NOT EXISTS posts (
  id TEXT PRIMARY KEY,
  title TEXT,                                -- tên nội bộ
  body TEXT NOT NULL DEFAULT '',             -- nội dung chung
  link TEXT,                                 -- link gắn kèm (đã gắn UTM nếu có)
  media TEXT,                                -- JSON [{path,type:image|video,name}]
  campaign_id TEXT REFERENCES campaigns(id),
  content_id TEXT REFERENCES content_items(id),
  status TEXT NOT NULL DEFAULT 'draft',      -- draft|pending|approved|scheduled|publishing|published|partial|failed|rejected
  scheduled_at TEXT,                         -- giờ VN 'YYYY-MM-DD HH:MM'; NULL = đăng ngay khi duyệt
  created_by TEXT REFERENCES users(id),
  approved_by TEXT REFERENCES users(id),
  published_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS post_targets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  post_id TEXT NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
  connection_id TEXT NOT NULL,
  provider TEXT NOT NULL,                    -- facebook_page | instagram | youtube | …
  caption TEXT,                              -- nội dung riêng cho kênh này (NULL = dùng body)
  status TEXT NOT NULL DEFAULT 'pending',    -- pending|publishing|published|failed
  external_id TEXT, permalink TEXT, error TEXT,
  published_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_post_targets_post ON post_targets(post_id);

-- Bình luận kéo về từ các kênh để chăm sóc.
CREATE TABLE IF NOT EXISTS comments (
  id TEXT PRIMARY KEY,                       -- <provider>:<external_id>
  connection_id TEXT NOT NULL,
  provider TEXT NOT NULL,                    -- facebook_page | instagram | youtube
  external_id TEXT NOT NULL,
  parent_external_id TEXT,                   -- trả lời của bình luận khác
  post_external_id TEXT, post_permalink TEXT, post_excerpt TEXT,
  author_name TEXT, author_id TEXT,
  message TEXT,
  created_time TEXT,                         -- thời điểm trên nền tảng (UTC 'YYYY-MM-DD HH:MM:SS')
  status TEXT NOT NULL DEFAULT 'new',        -- new|replied|hidden|ignored|escalated
  intent TEXT, sentiment TEXT,               -- hoi_gia|dat_lich|khieu_nai|khen|spam|khac / positive|neutral|negative
  assigned_to TEXT REFERENCES users(id),
  lead_id TEXT REFERENCES leads(id),
  reply_text TEXT, replied_by TEXT, replied_at TEXT, reply_external_id TEXT,
  fetched_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_comments_status ON comments(status, created_time);
CREATE TABLE IF NOT EXISTS reply_templates (
  id TEXT PRIMARY KEY,
  title TEXT NOT NULL,
  body TEXT NOT NULL,                        -- có thể dùng {ten}, {sdt_hotline}, {link}
  intent TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Kịch bản tự động: khi <trigger> và <conditions> thì làm <actions>.
CREATE TABLE IF NOT EXISTS automations (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  trigger TEXT NOT NULL,                     -- comment.new | lead.new | lead.state | post.published | metric.threshold | schedule.daily | task.overdue | …
  conditions TEXT,                           -- JSON
  actions TEXT,                              -- JSON [{type,…}]
  enabled INTEGER NOT NULL DEFAULT 0,
  template_key TEXT,
  created_by TEXT REFERENCES users(id),
  last_run_at TEXT, run_count INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS automation_runs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  automation_id TEXT,
  event TEXT, entity TEXT, entity_id TEXT,
  status TEXT NOT NULL,                      -- ok|skipped|failed
  detail TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_automation_runs_time ON automation_runs(created_at);

-- Chuỗi chăm sóc khách (nurture): các bước cách nhau N giờ, gửi email / webhook / tạo việc…
CREATE TABLE IF NOT EXISTS sequences (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  steps TEXT NOT NULL,                       -- JSON [{delay_hours, channel:email|webhook|task|note, subject, body, use_ai}]
  stop_on_states TEXT,                       -- JSON trạng thái đầu mối thì dừng, vd ["ACCEPTED","REJECTED"]
  enabled INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS sequence_enrollments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  sequence_id TEXT NOT NULL,
  lead_id TEXT NOT NULL,
  step_index INTEGER NOT NULL DEFAULT 0,
  next_run_at TEXT,                          -- giờ VN 'YYYY-MM-DD HH:MM:SS'
  status TEXT NOT NULL DEFAULT 'active',     -- active|done|stopped|failed
  last_error TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(sequence_id, lead_id)
);

-- Thông báo trong app (chuông ở thanh trên).
CREATE TABLE IF NOT EXISTS notifications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id TEXT,                              -- NULL = gửi mọi Quản trị + Trưởng nhóm
  title TEXT NOT NULL,
  body TEXT,
  link TEXT,                                 -- route trong app, vd 'care' hoặc 'demand'
  read_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id, read_at);

-- ===== Lớp DỰ ÁN (11/10/2026, theo đặc tả Marketing Hub nhiều dự án) =====
-- Mỗi dự án là một không gian độc lập: kênh, chiến dịch, nội dung, lead, automation, thành viên.
CREATE TABLE IF NOT EXISTS projects (
  id TEXT PRIMARY KEY,                       -- slug ngắn, vd 'webseo', 'tongkhobear'
  name TEXT NOT NULL,
  type TEXT NOT NULL DEFAULT 'client',       -- internal | client
  domain TEXT,                               -- website chính của dự án
  timezone TEXT NOT NULL DEFAULT 'Asia/Ho_Chi_Minh',
  color TEXT,                                -- màu nhận diện trên giao diện
  goal TEXT,                                 -- mục tiêu marketing (một câu)
  budget_month INTEGER NOT NULL DEFAULT 0,   -- ngân sách KẾ HOẠCH mỗi tháng (₫); không phải ngân sách đã gửi nền tảng
  settings TEXT,                             -- JSON: hotline, brand voice, telegram_chat_id riêng…
  status TEXT NOT NULL DEFAULT 'active',     -- active | paused | archived
  created_by TEXT REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
-- Vai trò theo dự án (tách khỏi vai trò hệ thống admin/manager/staff):
--   owner (chủ dự án) | manager (quản lý, duyệt) | content (nội dung) | ads (quảng cáo) | client (khách xem & duyệt) | viewer (chỉ xem)
CREATE TABLE IF NOT EXISTS project_members (
  project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  role TEXT NOT NULL DEFAULT 'viewer',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (project_id, user_id)
);

-- Tiện ích (11/10/2026): ý tưởng chiến dịch và landing page nháp — thuộc dự án
CREATE TABLE IF NOT EXISTS ideas (
  id TEXT PRIMARY KEY,
  project_id TEXT NOT NULL REFERENCES projects(id),
  title TEXT NOT NULL,
  insight TEXT, source TEXT, channel TEXT, audience TEXT, message TEXT, cta TEXT, kpi TEXT,
  budget INTEGER NOT NULL DEFAULT 0,          -- ngân sách ĐỀ XUẤT (₫)
  landing_id TEXT, research_id TEXT,
  campaign_id TEXT,                           -- chiến dịch nháp đã tạo từ ý tưởng (chống tạo trùng)
  state TEXT NOT NULL DEFAULT 'new',          -- new | converted | archived
  created_by TEXT REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_ideas_project ON ideas(project_id);
CREATE TABLE IF NOT EXISTS landing_pages (
  id TEXT PRIMARY KEY,
  project_id TEXT NOT NULL REFERENCES projects(id),
  title TEXT NOT NULL, cta TEXT, channel TEXT, campaign_id TEXT,
  source_type TEXT NOT NULL DEFAULT 'prompt', -- prompt | link | image
  source_ref TEXT,                            -- link tham chiếu hoặc đường dẫn ảnh đã tải lên
  prompt TEXT,
  content TEXT NOT NULL,                      -- HTML bản nháp (xem thử trong khung cách ly)
  sections TEXT,                              -- JSON các khối nội dung
  state TEXT NOT NULL DEFAULT 'draft',        -- draft | approved | published
  version INTEGER NOT NULL DEFAULT 1,
  published_url TEXT,
  ai_used INTEGER NOT NULL DEFAULT 0,
  created_by TEXT REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_landing_project ON landing_pages(project_id);

-- Báo cáo từ máy HL (app phòng marketing/kinh doanh trên PC) — một bản/dự án/loại/ngày (11/10/2026)
CREATE TABLE IF NOT EXISTS hl_reports (
  project_id TEXT NOT NULL REFERENCES projects(id),
  kind TEXT NOT NULL,                        -- tim_khach | email_chien_dich | hop_thu | seo_hang_ngay | kinh_doanh | tong_ngay
  day TEXT NOT NULL,                         -- YYYY-MM-DD (giờ VN)
  data TEXT NOT NULL,                        -- JSON đã tổng hợp (không SĐT/email thô)
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (project_id, kind, day)
);

-- 12/10/2026: CỬA SỔ CHAT — hộp thư chung mọi dự án (Messenger Fanpage + Zalo OA)
CREATE TABLE IF NOT EXISTS inbox_threads (
  id TEXT PRIMARY KEY,                       -- <provider>:<external_thread_id>
  project_id TEXT NOT NULL,
  connection_id TEXT NOT NULL,
  provider TEXT NOT NULL,                    -- facebook_page | zalo_oa
  external_id TEXT NOT NULL,
  customer_id TEXT, customer_name TEXT, customer_avatar TEXT,
  last_message TEXT, last_direction TEXT, last_time TEXT,   -- UTC
  unread INTEGER NOT NULL DEFAULT 0,
  read_at TEXT,                              -- lần người chăm sóc mở gần nhất (UTC)
  status TEXT NOT NULL DEFAULT 'open',       -- open | done
  assigned_to TEXT REFERENCES users(id),
  lead_id TEXT REFERENCES leads(id),
  note TEXT,
  ai_mode TEXT NOT NULL DEFAULT 'on',       -- on | off: AI được tự trả lời hội thoại này (khi dự án bật auto)
  needs_human INTEGER NOT NULL DEFAULT 0,   -- AI bàn giao người thật
  kind TEXT NOT NULL DEFAULT 'message',    -- message (Messenger/Zalo) | comment (bình luận dưới bài Fanpage)
  post_id TEXT, permalink TEXT,              -- bình luận: bài viết + link bình luận
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_inbox_threads_list ON inbox_threads(project_id, status, unread, last_time);
CREATE TABLE IF NOT EXISTS inbox_messages (
  id TEXT PRIMARY KEY,                       -- <provider>:<external_message_id>
  thread_id TEXT NOT NULL REFERENCES inbox_threads(id),
  direction TEXT NOT NULL,                   -- in (khách) | out (mình)
  text TEXT, attachments TEXT,               -- attachments: JSON [{type,url,name}]
  sent_at TEXT NOT NULL,                     -- UTC
  sender_name TEXT, sent_by TEXT, external_id TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_inbox_messages_thread ON inbox_messages(thread_id, sent_at);
-- 12/10/2026: hàng đợi việc AI cho máy HL (Claude Code / gói Max) khi Hub không có khoá API
CREATE TABLE IF NOT EXISTS inbox_ai_jobs (
  id TEXT PRIMARY KEY,
  thread_id TEXT NOT NULL REFERENCES inbox_threads(id),
  project_id TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT 'auto',         -- auto (tự gửi) | suggest (chỉ gợi ý cho người)
  status TEXT NOT NULL DEFAULT 'queued',     -- queued | taken | done | failed
  system TEXT NOT NULL, messages TEXT NOT NULL,   -- messages: JSON [{role,content}]
  result TEXT,                               -- JSON {reply,handoff,reason,phone}
  error TEXT, taken_at TEXT, done_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_inbox_ai_jobs_q ON inbox_ai_jobs(status, created_at);

-- 12/10/2026: Kho kênh tham khảo — dùng chung mọi dự án; kênh mẫu để học format nội dung rồi "làm theo" cho dự án.
CREATE TABLE IF NOT EXISTS ref_channels (
  id TEXT PRIMARY KEY,
  platform TEXT NOT NULL,                    -- tiktok | x | youtube | facebook | instagram | threads
  handle TEXT NOT NULL, url TEXT NOT NULL,
  name TEXT, bio TEXT, avatar TEXT,
  followers INTEGER, likes INTEGER, posts_count INTEGER,
  extra TEXT,                                -- JSON số liệu/bài gần đây máy HL lấy được
  formats TEXT,                              -- JSON [{name, desc, structure, why}] format nội dung đáng học
  samples TEXT,                              -- JSON [{path, caption}] ảnh mẫu (uploads/…)
  projects TEXT,                             -- JSON [project_id] dự án áp dụng
  notes TEXT, fetched_at TEXT, fetch_error TEXT,
  created_by TEXT REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
-- Việc giao máy HL (ngoài AI chat): ref_fetch (lấy số liệu kênh), ref_adapt (soạn bài + vẽ slide theo format mẫu).
CREATE TABLE IF NOT EXISTS hl_jobs (
  id TEXT PRIMARY KEY,
  kind TEXT NOT NULL,
  project_id TEXT, ref_id TEXT,
  payload TEXT NOT NULL,                     -- JSON đầu vào cho máy HL
  status TEXT NOT NULL DEFAULT 'queued',     -- queued | taken | done | failed
  result TEXT, error TEXT,
  taken_at TEXT, done_at TEXT,
  created_by TEXT REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_hl_jobs_q ON hl_jobs(status, created_at);
