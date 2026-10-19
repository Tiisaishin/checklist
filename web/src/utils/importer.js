/**
 * 域名批量导入：把 Excel / CSV / TXT / 粘贴文本解析成统一的行，并自动识别列。
 * 解析都在浏览器内完成，文件不会上传到服务器。
 */

export const FIELDS = ['domain', 'expire', 'registrar', 'notes', 'price']

/**
 * 表头关键词（小写，包含即匹配）。
 * 各平台的导出格式拿到样例后加到 PRESETS 里，PRESETS 优先于这里的通用规则。
 */
const HEADER_KEYWORDS = {
  domain: ['域名', 'domain', '网址', 'hostname', 'host name', 'site'],
  expire: ['到期', '过期', '有效期至', 'expir', 'expiry', 'expires', 'renewal date', 'renew date', 'valid until', 'paid until', 'end date'],
  registrar: ['注册商', '注册服务商', '服务商', '平台', 'registrar', 'provider', 'platform'],
  notes: ['备注', '说明', 'note', 'remark', 'memo', 'comment', 'tag'],
  price: ['续费价格', '价格', '续费金额', 'renewal price', 'renew price', 'price', 'cost'],
}

/**
 * 平台预设：match 为表头需要同时包含的关键词，columns 为字段 => 表头名（精确，忽略大小写与空格）
 * 例：{ name: '阿里云', match: ['域名', '到期时间'], columns: { domain: '域名', expire: '到期时间' }, registrar: '阿里云' }
 */
export const PRESETS = []

const DOMAIN_RE = /(?:^|[^a-z0-9.-])((?:[a-z0-9¡-￿](?:[a-z0-9¡-￿-]{0,61}[a-z0-9¡-￿])?\.)+(?:[a-z¡-￿]{2,63}|xn--[a-z0-9-]{2,59}))(?=$|[^a-z0-9.-])/gi
const FILE_EXT_RE = /\.(png|jpe?g|gif|svg|webp|css|js|json|html?|php|txt|csv|xlsx?|zip|pdf)$/i

/** 规范化域名：去掉协议、路径、端口、www. 和末尾的点 */
export function normalizeDomain(input) {
  let v = String(input ?? '').trim().toLowerCase()
  if (!v) return ''
  v = v.replace(/^[a-z]+:\/\//, '').replace(/[/?#:].*$/, '').replace(/\.$/, '').replace(/^\*\./, '')
  if (v.startsWith('www.')) v = v.slice(4)
  return v
}

export function isDomain(v) {
  const d = normalizeDomain(v)
  if (!d || d.length > 253 || FILE_EXT_RE.test(d)) return false
  return /^(?:[a-z0-9¡-￿](?:[a-z0-9¡-￿-]{0,61}[a-z0-9¡-￿])?\.)+(?:[a-z¡-￿]{2,63}|xn--[a-z0-9-]{2,59})$/i.test(d)
}

/** 从任意文本中提取域名 */
export function extractDomains(text) {
  const out = []
  // 中文标点、全角字符不属于域名，先替换为空格
  const cleaned = String(text).replace(/[\u2000-\u206f\u3000-\u303f\uff00-\uffef]/g, ' ')
  for (const m of cleaned.matchAll(DOMAIN_RE)) {
    let d = m[1]
    // 自由文本里中文紧挨着英文域名（如“提醒c-demo.net”）：后缀是英文时只取英文部分
    const tld = d.split('.').pop()
    if (/[^\x00-\x7f]/.test(d) && /^[a-z]+$/i.test(tld)) {
      const ascii = d.match(/[a-z0-9-]+(?:\.[a-z0-9-]+)+$/i)
      if (ascii) d = ascii[0]
    }
    if (isDomain(d)) out.push(normalizeDomain(d))
  }
  return out
}

const MONTHS = { jan: 1, feb: 2, mar: 3, apr: 4, may: 5, jun: 6, jul: 7, aug: 8, sep: 9, sept: 9, oct: 10, nov: 11, dec: 12 }

/**
 * 解析日期，返回 "YYYY-MM-DD HH:mm"（表格中的墙上时间）或 null
 * @param {'auto'|'mdy'|'dmy'} order 斜杠日期 03/04/2027 的顺序
 */
export function parseDate(value, order = 'auto') {
  if (value === null || value === undefined || value === '') return null
  if (value instanceof Date && !isNaN(value)) {
    return fmt(value.getUTCFullYear(), value.getUTCMonth() + 1, value.getUTCDate(), value.getUTCHours(), value.getUTCMinutes())
  }
  if (typeof value === 'number') {
    // Excel 序列日期（1900 系统）
    if (value > 20000 && value < 80000) {
      const d = new Date(Math.round((value - 25569) * 86400 * 1000))
      return parseDate(d)
    }
    // Unix 时间戳（秒 / 毫秒）
    if (value > 1e9 && value < 1e13) return parseDate(new Date(value < 1e11 ? value * 1000 : value))
    return null
  }

  let s = String(value).trim()
  if (!s) return null
  s = s.replace(/[年月]/g, '-').replace(/日/g, ' ').replace(/[（(].*?[)）]/g, '').replace(/\s+/g, ' ').trim()
  let m

  // 2027-03-15 / 2027/3/15 / 2027.03.15 [12:30[:00]] [Z|+08:00|UTC]
  if ((m = s.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})(?:[ T](\d{1,2}):(\d{2})(?::\d{2}(?:\.\d+)?)?)?\s*(z|utc|gmt|[+-]\d{2}:?\d{2})?$/i))) {
    if (m[6]) return parseDate(new Date(s.replace(' ', 'T').replace(/utc|gmt/i, 'Z')))
    return fmt(+m[1], +m[2], +m[3], +(m[4] || 0), +(m[5] || 0))
  }
  // 20270315
  if ((m = s.match(/^(\d{4})(\d{2})(\d{2})$/))) return fmt(+m[1], +m[2], +m[3])
  // 03/04/2027 或 03-04-2027
  if ((m = s.match(/^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})(?:[ T](\d{1,2}):(\d{2}))?/))) {
    let [a, b] = [+m[1], +m[2]]
    let mdy = order === 'mdy' || (order === 'auto' && a <= 12 && b > 12)
    if (order === 'auto' && a > 12) mdy = false
    if (order === 'auto' && a <= 12 && b <= 12) mdy = true // 无法判断时默认 月/日/年，可在界面切换
    return mdy ? fmt(+m[3], a, b, +(m[4] || 0), +(m[5] || 0)) : fmt(+m[3], b, a, +(m[4] || 0), +(m[5] || 0))
  }
  // 15-Mar-2027 / 15 Mar 2027
  if ((m = s.match(/^(\d{1,2})[- ]([a-z]{3,4})[a-z]*[-, ]+(\d{4})/i)) && MONTHS[m[2].toLowerCase()]) {
    return fmt(+m[3], MONTHS[m[2].toLowerCase()], +m[1])
  }
  // Mar 15, 2027
  if ((m = s.match(/^([a-z]{3,4})[a-z]*\.? (\d{1,2}),? (\d{4})/i)) && MONTHS[m[1].toLowerCase()]) {
    return fmt(+m[3], MONTHS[m[1].toLowerCase()], +m[2])
  }
  return null
}

function fmt(y, mo, d, h = 0, mi = 0) {
  if (y < 1990 || y > 2200 || mo < 1 || mo > 12 || d < 1 || d > 31) return null
  const p = (n) => String(n).padStart(2, '0')
  return `${y}-${p(mo)}-${p(d)} ${p(h)}:${p(mi)}`
}

/** 斜杠日期是否存在 月/日 与 日/月 都说得通的情况 */
export function hasAmbiguousDates(rows, col) {
  if (col === null || col === undefined) return false
  return rows.some((r) => {
    const m = String(r[col] ?? '').trim().match(/^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})/)
    return m && +m[1] <= 12 && +m[2] <= 12 && m[1] !== m[2]
  })
}

/** 读取文本文件：优先 UTF-8，失败则按 GBK（国内平台导出的 CSV 常见） */
export async function readText(file) {
  const buf = await file.arrayBuffer()
  try {
    return new TextDecoder('utf-8', { fatal: true }).decode(buf).replace(/^﻿/, '')
  } catch {
    return new TextDecoder('gbk').decode(buf)
  }
}

/** 解析 CSV / TSV（支持引号、转义、自动识别分隔符） */
export function parseDelimited(text) {
  const firstLines = text.split(/\r?\n/).slice(0, 10).join('\n')
  const counts = { ',': 0, '\t': 0, ';': 0, '|': 0 }
  for (const ch of firstLines) if (ch in counts) counts[ch]++
  const sep = Object.entries(counts).sort((a, b) => b[1] - a[1])[0]
  if (!sep || sep[1] === 0) return null
  const delimiter = sep[0]

  const rows = []
  let row = []
  let cell = ''
  let quoted = false
  for (let i = 0; i < text.length; i++) {
    const ch = text[i]
    if (quoted) {
      if (ch === '"' && text[i + 1] === '"') { cell += '"'; i++ } else if (ch === '"') quoted = false
      else cell += ch
    } else if (ch === '"' && cell === '') quoted = true
    else if (ch === delimiter) { row.push(cell); cell = '' } else if (ch === '\n' || ch === '\r') {
      if (ch === '\r' && text[i + 1] === '\n') i++
      row.push(cell); rows.push(row); row = []; cell = ''
    } else cell += ch
  }
  if (cell !== '' || row.length) { row.push(cell); rows.push(row) }
  return rows.map((r) => r.map((c) => c.trim())).filter((r) => r.some((c) => c !== ''))
}

/**
 * 解析纯文本：每行先按制表符 / 逗号 / 两个以上空格切分；
 * 如果整体不像表格，则直接提取所有域名（每个域名一行，同一行里的日期作为到期时间）
 */
export function parseText(text) {
  const table = parseDelimited(text)
  if (table && table.length && Math.max(...table.map((r) => r.length)) > 1) return table

  const rows = []
  for (const line of text.split(/\r?\n/)) {
    const domains = extractDomains(line)
    if (!domains.length) continue
    const date = line.match(/\d{4}[-/.年]\d{1,2}[-/.月]\d{1,2}日?(?:[ T]\d{1,2}:\d{2})?|\d{1,2}[-/]\d{1,2}[-/]\d{4}|\d{1,2}[- ][A-Za-z]{3,9}[-, ]+\d{4}/)
    // 一行里有多个域名时无法判断日期属于谁，只导入域名
    for (const d of domains) rows.push(date && domains.length === 1 ? [d, date[0]] : [d])
  }
  return rows
}

/** 读取文件为二维数组 */
export async function readFile(file) {
  const name = file.name.toLowerCase()
  if (name.endsWith('.xls')) throw new Error('XLS')
  if (name.endsWith('.xlsx')) {
    const { default: readExcel } = await import('read-excel-file/browser')
    const sheets = await readExcel(file)
    // 选域名最多的工作表
    let best = sheets[0]?.data || []
    let bestScore = -1
    for (const s of sheets) {
      const score = s.data.flat().filter((c) => typeof c === 'string' && isDomain(c)).length
      if (score > bestScore) { best = s.data; bestScore = score }
    }
    return best.map((r) => r.map((c) => (c === null || c === undefined ? '' : c))).filter((r) => r.some((c) => c !== ''))
  }
  return parseText(await readText(file))
}

/**
 * 识别表头行与各字段所在列
 * @returns {{ headerIndex: number, headers: string[], mapping: Record<string, number|null>, preset: string|null }}
 */
export function detectColumns(rows) {
  const width = Math.max(0, ...rows.slice(0, 50).map((r) => r.length))
  const lower = (v) => String(v ?? '').trim().toLowerCase()

  // 1. 在前 10 行里找表头：命中关键词最多、且本身不是域名的行
  let headerIndex = -1
  let bestHits = 0
  rows.slice(0, 10).forEach((r, i) => {
    const hits = r.filter((c) => {
      const v = lower(c)
      return v && !isDomain(v) && Object.values(HEADER_KEYWORDS).some((kws) => kws.some((k) => v.includes(k)))
    }).length
    if (hits > bestHits) { bestHits = hits; headerIndex = i }
  })
  const headers = headerIndex >= 0 ? rows[headerIndex].map((c) => String(c ?? '').trim()) : Array.from({ length: width }, (_, i) => `#${i + 1}`)
  const data = rows.slice(headerIndex + 1)
  const mapping = Object.fromEntries(FIELDS.map((f) => [f, null]))

  // 2. 平台预设
  let preset = null
  if (headerIndex >= 0) {
    const hs = headers.map((h) => lower(h).replace(/\s+/g, ''))
    for (const p of PRESETS) {
      if (p.match.every((k) => hs.some((h) => h.includes(k.toLowerCase().replace(/\s+/g, ''))))) {
        for (const [field, title] of Object.entries(p.columns)) {
          const idx = hs.indexOf(title.toLowerCase().replace(/\s+/g, ''))
          if (idx >= 0) mapping[field] = idx
        }
        preset = p.name
        break
      }
    }
  }

  // 3. 通用表头关键词（同一列不重复分配；“到期”优先于“注册”类日期）
  if (headerIndex >= 0) {
    const used = new Set(Object.values(mapping).filter((v) => v !== null))
    for (const field of ['expire', 'domain', 'registrar', 'price', 'notes']) {
      if (mapping[field] !== null) continue
      const idx = headers.findIndex((h, i) => !used.has(i) && HEADER_KEYWORDS[field].some((k) => lower(h).includes(k)))
      if (idx >= 0) { mapping[field] = idx; used.add(idx) }
    }
  }

  // 4. 按内容判断：域名列 = 域名占比最高的列；到期列 = 可解析日期最多且日期最晚的列
  const sample = data.slice(0, 200)
  const ratio = (col, test) => sample.length ? sample.filter((r) => test(r[col])).length / sample.length : 0
  if (mapping.domain === null || ratio(mapping.domain, isDomain) < 0.5) {
    let best = null
    let bestR = 0
    for (let c = 0; c < width; c++) {
      const r = ratio(c, isDomain)
      if (r > bestR) { bestR = r; best = c }
    }
    if (bestR >= 0.3) mapping.domain = best
  }
  // 日期列按非空单元格计算占比（粘贴文本时只有部分行带日期）
  const dateRatio = (col) => {
    const filled = sample.filter((r) => String(r[col] ?? '').trim() !== '')
    return filled.length ? filled.filter((r) => parseDate(r[col])).length / filled.length : 0
  }
  if (mapping.expire === null || dateRatio(mapping.expire) < 0.5) {
    let best = null
    let bestKey = ''
    for (let c = 0; c < width; c++) {
      if (c === mapping.domain) continue
      if (dateRatio(c) < 0.8) continue
      // 多个日期列时（注册时间 / 到期时间）取更晚的那列
      const latest = sample.map((r) => parseDate(r[c]) || '').sort().pop()
      if (latest > bestKey) { bestKey = latest; best = c }
    }
    if (best !== null) mapping.expire = best
  }

  return { headerIndex, headers, mapping, preset }
}

/**
 * 根据列映射生成导入条目（按域名去重）
 * @returns {{ items: Array, invalid: Array<{row:number, value:string}> }}
 */
export function buildItems(rows, headerIndex, mapping, dateOrder = 'auto') {
  const items = []
  const invalid = []
  const seen = new Set()
  rows.slice(headerIndex + 1).forEach((r, i) => {
    const raw = mapping.domain !== null ? r[mapping.domain] : ''
    const domain = normalizeDomain(raw)
    if (!domain) return
    if (!isDomain(domain)) { invalid.push({ row: headerIndex + i + 2, value: String(raw) }); return }
    if (seen.has(domain)) return
    seen.add(domain)
    const price = mapping.price !== null ? parseFloat(String(r[mapping.price]).replace(/[^\d.]/g, '')) : NaN
    items.push({
      domain,
      expire: mapping.expire !== null ? parseDate(r[mapping.expire], dateOrder) : null,
      registrar: mapping.registrar !== null ? String(r[mapping.registrar] ?? '').trim().slice(0, 128) : '',
      notes: mapping.notes !== null ? String(r[mapping.notes] ?? '').trim().slice(0, 500) : '',
      price: isNaN(price) ? null : price,
    })
  })
  return { items, invalid }
}
