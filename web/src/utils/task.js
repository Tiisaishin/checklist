/** 生成频率描述，例如 “每 2 周 · 周一 周三 10:30” */
export function frequencyText(task, t) {
  const n = task.interval || 1
  let text = n > 1 ? t('task.everyN', { n, unit: t('task.freqs.' + task.freq) }) : t('task.freqText.' + task.freq)
  if (task.freq === 'weekly' && task.by_weekday) {
    text += ' · ' + String(task.by_weekday).split(',').filter(Boolean).map((d) => weekdayName(Number(d), t)).join(' ')
  } else if (task.freq === 'monthly' && task.by_monthday) {
    text += ' · ' + (task.by_monthday === -1 ? t('task.lastDay') : `${task.by_monthday}`)
  } else if (task.freq === 'yearly' && task.by_month) {
    text += ` · ${task.by_month}/${task.by_monthday === -1 ? t('task.lastDay') : task.by_monthday || ''}`
  }
  return `${text} ${task.time_of_day || ''}`.trim()
}

export function weekdayName(d, t) {
  return t('task.wd.' + d)
}
