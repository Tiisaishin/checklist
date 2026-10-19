# Checklist / 到期提醒 SaaS —— 设计草案

> 状态：MVP 已实现（见 README）。技术栈：ThinkPHP 8（多应用）+ Vue 3 + MySQL 8 + Redis + Docker。

## 1. 核心概念

| 概念 | 说明 | 例子 |
| --- | --- | --- |
| **资产 Asset** | 有到期日的"东西"，可自动探测 | 域名、SSL 证书、VPS、SaaS 订阅、软件授权、合同、护照/驾照 |
| **检查项 Task** | 周期性要做的事，需要"打勾" | 每天看备份日志、每周看磁盘、每月对账、每年体检 |
| **提醒规则 Rule** | 什么时候、通过什么渠道、发什么内容 | 到期前 30/7/3/1/0 天 Telegram 提醒，未处理每天重复 |
| **保险箱 Vault** | 加密存放账号、密码、API Key、2FA 密钥 | 注册商账号、面板密码、TOTP |
| **租户 Tenant** | SaaS 隔离单位（个人或团队） | 个人空间、公司团队 |

资产和检查项可以挂在同一个"项目/分组"下，例如「博客项目」= 域名 + SSL + VPS + 每周备份检查 + 后台账号。

## 2. 功能清单

### 2.1 提醒与周期
- 周期：每天 / 每周几 / 每月几号 / 每年某日 / 每 N 天 / 自定义 cron（内部统一存 RRULE）
- 两种计算方式：**固定日程**（每月 1 号）与 **完成后顺延**（上次完成后 90 天）
- 提前提醒：可配置多个提前量（如 30,7,3,1,0 天），逾期后按频率持续催
- 免打扰时段、按用户时区发送、节假日顺延（可选）
- 升级通知：N 天未处理 → 通知团队管理员
- 稍后提醒（Snooze）：1 小时 / 明天 / 3 天

### 2.2 通知渠道
- **Telegram（首要）**：网页生成绑定码 → `t.me/<bot>?start=<code>` 一键绑定；支持私聊和群组
  - 消息带按钮：✅ 已完成/已续费、⏰ 明天再提醒、🔕 忽略本次
  - 命令：`/today` 今日待办、`/soon` 30 天内到期、`/done <id>`、`/add`
- 其他渠道（插件化 Driver）：邮件、Webhook、企业微信、钉钉、飞书、Bark、Server 酱、Discord、Slack、ntfy
- 通知模板：每个用户/每条规则可自定义，变量如 `{name} {expire_at} {days_left} {url} {price}`，多语言默认模板
- 每日/每周汇总摘要：一条消息列出所有待办，避免刷屏

### 2.3 自动探测（真正省心的部分）
- **域名**：RDAP/WHOIS 自动获取到期日、注册商、NS；续费后自动刷新到期日
- **SSL**：TLS 握手读取证书到期时间、签发者、SAN；支持自定义端口；证书变更提醒
- **网站可用性**：HTTP 状态码/关键字/响应时间监测（轻量 uptime）
- **DNS 变更**：记录 A/NS/MX 快照，被改动时告警（防劫持）
- 注册商/云平台 API 同步：Cloudflare、阿里云、腾讯云、Namecheap、GoDaddy 批量导入域名
- 探测完成后若到期日变化，自动标记"已续费"并关闭当前提醒

### 2.4 保险箱
- AES-256-GCM 加密，租户级数据密钥由 `APP_MASTER_KEY`（环境变量）包裹
- 查看明文需二次验证，访问记录审计
- 内置 TOTP 生成（存 2FA 密钥后直接显示 6 位码）
- 字段可自定义：登录地址、用户名、密码、备注、附件（发票/合同）

### 2.5 费用与统计
- 每个资产记录续费价格、币种、周期 → 年度支出统计、按月支出日历
- 仪表盘：已逾期 / 7 天内 / 30 天内 / 90 天内、本月需花费

### 2.6 其他实用功能
- 日历视图 + iCal 订阅地址（导入 Google/Apple 日历）
- 标签、分组、搜索、批量操作
- CSV/Excel 导入导出、JSON 备份
- 检查项的完成记录（谁、何时、备注、截图），可做成"巡检报告"
- 子清单：一个检查项包含多个步骤（如"月度维护"= 更新系统 + 检查备份 + 清理日志）
- 开放 API + 个人 Access Token
- 公开状态页（可选）

## 3. SaaS / 多用户
- 租户隔离：所有业务表带 `tenant_id`，Model 全局作用域自动过滤
- 角色：Owner / Admin / Member / Viewer；保险箱条目可单独授权
- 套餐与配额：资产数、监测频率、渠道数、成员数（`plans` 表 + 中间件校验）
- 支付：预留 Driver（Stripe / 支付宝 / 易支付）
- 平台超管后台：租户管理、套餐、公告、系统 Bot 配置、队列/任务监控
- 安全：登录 2FA、登录日志、API 限流、操作审计

## 4. 多语言
- 后端：ThinkPHP 语言包 `app/lang/{zh-cn,en,zh-tw,ja}.php`，按用户设置/`Accept-Language` 切换
- 前端：vue-i18n，与后端共用 key
- 通知模板按接收者语言渲染；日期按用户时区与格式显示

## 5. 技术架构

### 5.1 ThinkPHP 8 多应用目录
```
app/
├── common/        # 公共：Model、Service、通知 Driver、探测器、加密、租户作用域
├── admin/         # 平台超管后台
├── api/           # 用户端 REST API（给前端 SPA / 开放 API）
├── bot/           # Telegram Webhook 入口与命令处理
└── command/       # CLI：调度器、探测、队列任务
web/               # 前端 SPA（Vue 3 + Naive UI + vue-i18n），构建产物输出到 public/
```

### 5.2 后台任务
- `php think scheduler:run`：每分钟扫描 `next_notify_at <= now` 的提醒，投递队列
- `php think queue:work`（think-queue + Redis）：发送通知、执行探测
- 幂等：`notification_logs` 以 `(rule_id, target_date, offset)` 唯一，避免重复发送
- 探测任务按频率分批执行，失败重试 + 指数退避

### 5.3 Docker
```
docker-compose.yml
├── app        # php8.3-fpm + 扩展（redis, intl, gmp, openssl）
├── nginx
├── worker     # php think queue:work
├── scheduler  # php think scheduler:run（循环/supercronic）
├── mysql:8
└── redis:7
```
一键 `docker compose up -d`，首次启动自动迁移 + 创建超管。

## 6. 数据表草图
```
tenants(id, name, plan_id, locale, timezone, ...)
users(id, email, password, locale, timezone, totp_secret, ...)
tenant_users(tenant_id, user_id, role)
item_groups(id, tenant_id, name, description, color, is_default, remind_channels JSON)
assets(id, tenant_id, group_id, type, name, identifier, expire_at,
       auto_detect, detect_meta JSON, price, currency, renew_cycle, notes, ...)
tasks(id, tenant_id, group_id, title, rrule, mode[fixed|after_done],
      next_due_at, steps JSON, ...)
task_runs(id, task_id, user_id, done_at, note, attachments)
reminder_rules(id, tenant_id, target_type, target_id, offsets JSON,
               repeat_overdue, channels JSON, template_id, quiet_hours)
channels(id, tenant_id, user_id, type, config JSON(加密), verified_at)
templates(id, tenant_id, channel_type, locale, content)
notification_logs(id, rule_id, target_date, offset, channel_id, status, error)
vault_items(id, tenant_id, project_id, title, data_encrypted, ...)
vault_access_logs / audit_logs / plans / subscriptions / api_tokens
```

## 7. 迭代计划
1. **MVP**：注册登录、单租户个人使用、资产 + 检查项 CRUD、周期计算、Telegram 绑定与提醒、域名/SSL 自动探测、Docker 部署、中英文
2. **v2**：团队与角色、保险箱、更多通知渠道、模板自定义、Telegram 交互按钮、日历/iCal、导入导出
3. **v3**：套餐计费、超管后台、云平台 API 同步、uptime/DNS 监测、开放 API、状态页

## 8. 已确定
- 定位：公开 SaaS（套餐与配额已实现，支付在 v3）
- 前端：Vue 3 SPA（Naive UI）
- Telegram：同时支持平台 Bot 和用户自建 Bot，**每个用户都可以设置自己的 Bot**

## 9. MVP 进度
- [x] 注册登录、个人空间、Token 鉴权、角色（owner/admin/member/viewer）
- [x] 资产 CRUD、提前提醒天数、过期提醒、续费/已处理/稍后提醒
- [x] 域名 RDAP/WHOIS、SSL 证书自动探测
- [x] 周期检查（日/周/月/年、间隔、星期、几号/月末、固定日程/完成后顺延、步骤、完成记录）
- [x] Telegram 平台 Bot + 用户自建 Bot，绑定链接 / Chat ID，交互按钮与命令，Webhook / 轮询
- [x] 用户自定义通知模板与预览
- [x] 套餐配额、平台后台（用户、空间、套餐、系统设置）
- [x] 中英文、用户时区
- [x] Docker Compose 部署
- [x] 信息分组：默认分组 + 每个分组单独指定通知渠道（Bot）
- [ ] 团队成员邀请（数据结构已支持，缺邀请流程与界面）
- [ ] 保险箱、更多通知渠道、日历/iCal、导入导出（v2）
