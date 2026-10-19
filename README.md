# Checklist

到期提醒 + 周期检查的多用户 SaaS。域名、SSL 证书、服务器、订阅服务快到期时，以及每天/每周/每月/每年的例行检查到点时，通过 Telegram 提醒你。

- **后端**：ThinkPHP 8 多应用（`api` 用户端 / `admin` 平台管理 / `bot` Telegram Webhook / `common` 公共层）
- **前端**：Vue 3 + Naive UI + vue-i18n（简体中文 / English），浅色与深色主题
- **部署**：Docker Compose（app + worker + scheduler + telegram + MySQL + Redis）

## 功能（MVP）

| 模块 | 说明 |
| --- | --- |
| 资产到期 | 域名、SSL、服务器、订阅、授权、账号等；可设置提前提醒天数（如 30/7/3/1/0），过期后每天提醒；记录续费价格并统计未来 12 个月的支出 |
| 域名到期 | 独立页面：统计卡片一键筛选（30 天内 / 已过期 / 异常），多选批量操作（重新检测、改分组、改提醒天数、开关自动探测、归档、删除），详情抽屉展示注册商、域名状态、DNS 与变化记录 |
| SSL 证书监控 | 独立页面：按主机（可带端口）监控证书，统计与筛选（14 天内 / 已过期 / 异常），展示签发机构与覆盖域名，同样支持批量操作与变化记录 |
| 批量导入 / 导出 | 域名和 SSL 各自导入：上传 Excel（.xlsx）/ CSV / TXT 或粘贴任意文本，在浏览器里解析（自动识别 UTF-8 / GBK、表头、域名列、到期时间列和各种日期格式），预览确认后导入；SSL 可按表格中的主机（含端口）导入，或把域名列表生成主域 / www / 自定义子域；已存在的跳过或更新；整批撤销；列表可导出 CSV |
| 自动探测 | 域名通过 RDAP（回退 WHOIS）获取到期日、注册商、DNS 服务器、域名状态；SSL 通过 TLS 握手读取证书并判断过期、自签名、域名不匹配、证书链不受信任。按剩余天数自适应安排检测频率（越临近越频繁，不低于套餐间隔），失败按 1h/3h/6h/12h 退避。自动发现并通知：续费成功、DNS 服务器变化、域名状态异常（暂停解析 / 赎回期 / 待删除）、证书已更新、证书异常、90 天短期证书自动续期失败、连续 3 次检测失败 |
| 周期检查 | 每 N 天/周/月/年，指定星期几、几号（含月末）、时间；支持“固定日程”和“完成后顺延”两种模式；可拆分检查步骤，并保留完成记录 |
| 信息分组 | 资产和检查项按分组管理，每个空间自带一个不可删除的**默认分组**；**每个分组可以单独指定通知渠道（Bot）**，例如「客户 A」发到客户 A 的群，「服务器」发到运维 Bot |
| 团队成员 | 所有者/管理员生成邀请链接（绑定被邀请人邮箱，7 天有效、只能使用一次）。对方登录或注册后加入，关闭公开注册时也能通过邀请注册。角色分为所有者、管理员、成员、只读，支持修改角色、移出成员、转让所有权、退出空间；成员数受套餐限制 |
| 保险箱 | 账号、密码、API Key、两步验证密钥、自定义字段、安全笔记加密保存（每个空间独立派生密钥，AES-256-GCM）；查看明文或验证码前需输入登录密码解锁（仅对当前登录 10 分钟有效）；内置 TOTP 验证码与密码生成器；支持「团队可见 / 仅自己可见」，只读成员不能查看明文；查看、复制、修改、删除都有访问记录 |
| Telegram | **每个用户可以使用平台 Bot，也可以填写自己的 Bot Token**；用绑定链接或 Chat ID 绑定私聊、群组或频道；消息自带按钮：✅ 已续费/完成、⏰ 稍后提醒、🔄 重新检测；支持 `/today` `/soon` `/id` 命令 |
| 通知模板 | 每个用户自定义自己收到的消息，可插入变量，提供实时预览 |
| 多用户 / SaaS | 用户注册后自动创建个人空间；数据按空间（tenant）隔离；套餐限制资产数、检查项数、渠道数和探测频率；平台后台管理用户、空间、套餐、系统 Bot |
| 多语言 | 界面、接口提示和通知内容都按用户语言显示；时间按用户时区显示和计算 |

## 快速部署（Docker）

```bash
cp .env.example .env      # 修改 APP_URL、ADMIN_EMAIL、ADMIN_PASSWORD、数据库密码
docker compose up -d --build
```

打开 `http://服务器:8080`，用 `ADMIN_EMAIL` / `ADMIN_PASSWORD` 登录。超级管理员可以在「平台管理 → 系统设置」中配置平台 Telegram Bot。

- **APP_URL 是 https 时**：平台 Bot 和用户自建 Bot 都会自动设置 Webhook（`/bot/telegram/...`）
- **没有 HTTPS 时**：由 `telegram` 容器轮询（getUpdates）接收消息，功能完全一样
- **APP_KEY** 用于加密 Bot Token。不填会自动生成并存放在 `runtime` 卷中，请务必备份。环境变量值中不要包含双引号

| 容器 | 作用 |
| --- | --- |
| app | nginx + php-fpm；启动时自动执行数据库迁移和初始化 |
| worker | `php think queue:work`：发送通知、执行探测 |
| scheduler | `php think checklist:schedule --loop`：每分钟计算提醒，每 10 分钟投递探测 |
| telegram | `php think checklist:telegram-poll`：没有 HTTPS 时接收 Telegram 消息 |

## 本地开发

```bash
# 后端（PHP 8.1+，可用 SQLite）
cd server
composer install
cat > .env <<'ENV'
APP_DEBUG = true
DB_DRIVER = sqlite
QUEUE_DRIVER = sync
ADMIN_EMAIL = admin@example.com
ADMIN_PASSWORD = admin12345
ENV
php think checklist:install
php -S 127.0.0.1:8000 -t public public/router.php
php think checklist:schedule --loop      # 另开终端
php think checklist:telegram-poll        # 需要接收 Telegram 消息时

# 前端
cd web
npm install
npm run dev   # http://localhost:5173，/api 会代理到 127.0.0.1:8000
```

其他命令：`php think checklist:detect <资产ID>` 立即探测指定资产并输出结果。

## 目录结构

```
server/                     ThinkPHP 8
├── app/api                 用户端 API（/api）
├── app/admin               平台管理 API（/adminapi）
├── app/bot                 Telegram Webhook（/bot）
├── app/common
│   ├── service             提醒引擎、周期计算、模板、配额、加密…
│   ├── detector            域名 RDAP/WHOIS、SSL 探测
│   ├── telegram            Bot API 客户端、Update 处理
│   ├── notify              通知渠道驱动（可扩展邮件、Webhook 等）
│   ├── command / job       调度器、轮询、队列任务
│   └── lang                后端语言包
└── database/migrations     数据表结构
web/                        Vue 3 前端
docker/                     nginx / php / supervisor / entrypoint
```

## 提醒规则

- **资产**：在接收人每日提醒时间（个人设置，默认 09:00）之后发送。剩余天数命中提前天数时提醒；新建时如果已经错过较早的提醒点，会补发一次；过期后每天提醒，直到续费（到期日变化）或点击「已处理」
- **检查项**：在检查项设置的时间点发送。命中提前天数时提醒，到期后每天提醒，直到点击「完成」，完成后自动推进到下一周期
- 每条提醒每天每个渠道最多发送一次（通过唯一键去重）
- **发往哪些渠道**：条目自己指定的渠道 → 所在分组的渠道 → 默认分组的渠道 → 空间内全部有效渠道（依次取第一个已设置的）。Telegram 里的 `/today` `/soon` 也只列出会发到当前聊天的条目

更多规划见 [docs/DESIGN.md](docs/DESIGN.md)。
