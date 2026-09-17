import {
  Activity,
  ArrowUpCircle,
  Bell,
  BellRing,
  Bug,
  CalendarClock,
  ClipboardCheck,
  Code2,
  Cpu,
  Database,
  FileCog,
  FileText,
  FileX,
  FolderOpen,
  FolderTree,
  Gauge,
  Globe,
  HardDrive,
  HardDriveDownload,
  History,
  KeyRound,
  Laptop,
  LayoutDashboard,
  MailPlus,
  PackageCheck,
  Puzzle,
  Rocket,
  Server,
  Settings,
  Shield,
  ShieldAlert,
  ShieldCheck,
  Terminal,
  Timer,
  User as UserIcon,
  UserCog,
  Users as UsersIcon,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import type { Capabilities } from '@shared/types'
import type { TranslationKey } from '@/hooks/useI18n'

export interface NavItem {
  to: string
  labelKey: TranslationKey
  icon: LucideIcon
  show: boolean
  group?: string
  match?: (path: string) => boolean
}

export interface NavContext {
  caps: Capabilities
  role?: string
}

export function buildNavItems({ caps, role }: NavContext): NavItem[] {
  const isAdmin = role === 'admin'
  const disk = caps.disk
  const mysql = caps.mysql
  const ftp = caps.ftp ?? true

  return [
    { to: '/dashboard', labelKey: 'nav.dashboard', icon: LayoutDashboard, show: true },
    { to: '/files', labelKey: 'nav.files', icon: FolderOpen, show: true },
    {
      to: '/db',
      labelKey: 'nav.database',
      icon: Database,
      show: mysql,
      match: (p) => p.startsWith('/db'),
    },
    { to: '/phpinfo', labelKey: 'nav.phpInfo', icon: Code2, show: true },
    { to: '/system', labelKey: 'nav.system', icon: Cpu, show: true },
    { to: '/disk-analysis', labelKey: 'nav.diskAnalysis', icon: HardDrive, show: disk },
    { to: '/ftp', labelKey: 'nav.ftp', icon: UsersIcon, show: ftp },
    { to: '/error-log', labelKey: 'nav.errorLog', icon: Bug, show: true },
    { to: '/operation-log', labelKey: 'nav.operationLog', icon: History, show: true },
    { to: '/notifications', labelKey: 'nav.notifications', icon: Bell, show: true },
    { to: '/env-check', labelKey: 'nav.envCheck', icon: ClipboardCheck, show: true },
    { to: '/security-scan', labelKey: 'nav.securityScan', icon: ShieldAlert, show: true },
    { to: '/ssl', labelKey: 'nav.ssl', icon: Shield, show: true },
    { to: '/cron', labelKey: 'nav.cron', icon: CalendarClock, show: true },
    { to: '/backup', labelKey: 'nav.backup', icon: HardDriveDownload, show: true },
    { to: '/htaccess', labelKey: 'nav.htaccess', icon: FileText, show: true },
    { to: '/upgrade', labelKey: 'nav.upgrade', icon: PackageCheck, show: true },
    { to: '/api-tokens', labelKey: 'nav.apiTokens', icon: KeyRound, show: true },
    { to: '/deploy', labelKey: 'nav.deploy', icon: Rocket, show: true },
    { to: '/users', labelKey: 'nav.users', icon: UserCog, show: isAdmin },
    { to: '/sessions', labelKey: 'nav.sessions', icon: Shield, show: isAdmin },
    { to: '/user-activity', labelKey: 'nav.userActivity', icon: Activity, show: isAdmin },
    { to: '/profile', labelKey: 'nav.profile', icon: UserIcon, show: true },
    { to: '/groups', labelKey: 'nav.groups', icon: FolderTree, show: isAdmin },
    {
      to: '/tokens',
      labelKey: 'nav.tokens',
      icon: KeyRound,
      show: isAdmin || role === 'operator',
    },
    { to: '/invitations', labelKey: 'nav.invitations', icon: MailPlus, show: isAdmin },
    { to: '/devices', labelKey: 'nav.devices', icon: Laptop, show: true },
    {
      to: '/notification-preferences',
      labelKey: 'nav.notificationPrefs',
      icon: BellRing,
      show: true,
    },
    { to: '/approvals', labelKey: 'nav.approvals', icon: ShieldCheck, show: isAdmin },
    { to: '/composer', labelKey: 'nav.composer', icon: PackageCheck, show: isAdmin },
    { to: '/php-opcache', labelKey: 'nav.phpOpcache', icon: Gauge, show: isAdmin },
    { to: '/php-extensions', labelKey: 'nav.phpExtensions', icon: Puzzle, show: isAdmin },
    { to: '/php-errors', labelKey: 'nav.phpErrors', icon: Bug, show: isAdmin },
    { to: '/php-fpm', labelKey: 'nav.phpFpm', icon: Server, show: isAdmin },
    { to: '/php-bench', labelKey: 'nav.phpBench', icon: Timer, show: isAdmin },
    { to: '/php-ini', labelKey: 'nav.phpIni', icon: FileCog, show: isAdmin },
    { to: '/php-processes', labelKey: 'nav.phpProcesses', icon: Cpu, show: isAdmin },
    { to: '/php-upgrade', labelKey: 'nav.phpUpgrade', icon: ArrowUpCircle, show: isAdmin },
    { to: '/webshell', labelKey: 'nav.webshell', icon: Terminal, show: true },
    { to: '/website-monitor', labelKey: 'nav.websiteMonitor', icon: Globe, show: true },
    { to: '/custom-error-pages', labelKey: 'nav.customErrorPages', icon: FileX, show: true },
    { to: '/settings', labelKey: 'nav.settings', icon: Settings, show: true },
  ]
}

export function isNavItemActive(item: NavItem, pathname: string): boolean {
  if (item.match) return item.match(pathname)
  return pathname === item.to || pathname.startsWith(item.to + '/')
}

export function filterCommands<T extends { label: string; keywords?: string }>(
  commands: T[],
  query: string,
): T[] {
  const needle = query.trim().toLowerCase()
  if (!needle) return commands
  return commands.filter((command) => {
    const haystack = `${command.label} ${command.keywords ?? ''}`.toLowerCase()
    return needle
      .split(/\s+/)
      .filter(Boolean)
      .every((part) => haystack.includes(part))
  })
}
