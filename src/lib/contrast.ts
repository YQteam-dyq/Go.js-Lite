export interface Rgb {
  r: number
  g: number
  b: number
}

export interface HslTriple {
  h: number
  s: number
  l: number
}

export function parseHslTriple(value: string): HslTriple | null {
  const match = value.trim().match(/^([\d.]+)\s+([\d.]+)%\s+([\d.]+)%$/)
  if (!match) return null
  const h = Number(match[1])
  const s = Number(match[2]) / 100
  const l = Number(match[3]) / 100
  if (!Number.isFinite(h) || !Number.isFinite(s) || !Number.isFinite(l)) return null
  return { h, s, l }
}

function channelToLinear(channel: number): number {
  const c = channel / 255
  return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4)
}

export function hslToRgb({ h, s, l }: HslTriple): Rgb {
  const hue = ((h % 360) + 360) % 360
  const c = (1 - Math.abs(2 * l - 1)) * s
  const x = c * (1 - Math.abs(((hue / 60) % 2) - 1))
  const m = l - c / 2

  let rgb: [number, number, number]
  if (hue < 60) rgb = [c, x, 0]
  else if (hue < 120) rgb = [x, c, 0]
  else if (hue < 180) rgb = [0, c, x]
  else if (hue < 240) rgb = [0, x, c]
  else if (hue < 300) rgb = [x, 0, c]
  else rgb = [c, 0, x]

  return {
    r: Math.round((rgb[0] + m) * 255),
    g: Math.round((rgb[1] + m) * 255),
    b: Math.round((rgb[2] + m) * 255),
  }
}

export function relativeLuminance(rgb: Rgb): number {
  return (
    0.2126 * channelToLinear(rgb.r) +
    0.7152 * channelToLinear(rgb.g) +
    0.0722 * channelToLinear(rgb.b)
  )
}

export function contrastRatio(foreground: Rgb, background: Rgb): number {
  const a = relativeLuminance(foreground)
  const b = relativeLuminance(background)
  const lighter = Math.max(a, b)
  const darker = Math.min(a, b)
  return (lighter + 0.05) / (darker + 0.05)
}

export function meetsWcagAA(ratio: number, largeText = false): boolean {
  return ratio >= (largeText ? 3 : 4.5)
}
