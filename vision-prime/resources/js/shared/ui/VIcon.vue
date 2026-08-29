<script setup lang="ts">
import { computed } from 'vue'
import {
  Bot,
  Brain,
  CloudDownload,
  Database,
  FileEdit,
  Image,
  Info,
  Pencil,
  RefreshCw,
  Rocket,
  Save,
  Star,
  Table2,
  Trash2,
  TriangleAlert,
  Upload,
  Activity,
  ArrowDownRight,
  ArrowUpRight,
  Ban,
  BarChart3,
  BellRing,
  Building2,
  Calendar,
  CalendarClock,
  CheckCircle2,
  Clock,
  Eye,
  EyeOff,
  FileText,
  Gauge,
  GraduationCap,
  LifeBuoy,
  Lightbulb,
  LineChart,
  ListChecks,
  Megaphone,
  Moon,
  MousePointerClick,
  Newspaper,
  Phone,
  PieChart,
  Plane,
  RotateCcw,
  Search,
  Settings,
  Sun,
  ShieldCheck,
  ShoppingBag,
  Sparkles,
  Stethoscope,
  Timer,
  TrendingDown,
  TrendingUp,
  UserCheck,
  Users,
  XCircle,
  Zap,
} from '@lucide/vue'
import type { Component } from 'vue'

export type IconName =
  | 'activity'
  | 'arrow-down'
  | 'arrow-up'
  | 'ban'
  | 'bell'
  | 'building'
  | 'calendar'
  | 'calendar-clock'
  | 'chart-bar'
  | 'chart-doughnut'
  | 'chart-line'
  | 'check'
  | 'clock'
  | 'cursor-click'
  | 'document'
  | 'eye'
  | 'eye-off'
  | 'file'
  | 'gauge'
  | 'graduation'
  | 'lightbulb'
  | 'list'
  | 'megaphone'
  | 'moon'
  | 'news'
  | 'phone'
  | 'plane'
  | 'rotate'
  | 'search'
  | 'settings'
  | 'shield'
  | 'shopping-bag'
  | 'sparkles'
  | 'stethoscope'
  | 'sun'
  | 'support'
  | 'timer'
  | 'trend-down'
  | 'trend-up'
  | 'trending-up'
  | 'user-check'
  | 'users'
  | 'x'
  | 'zap'
  | 'alert'
  | 'bot'
  | 'brain'
  | 'cloud-download'
  | 'database'
  | 'file-edit'
  | 'image'
  | 'info'
  | 'pencil'
  | 'refresh'
  | 'rocket'
  | 'save'
  | 'star'
  | 'table'
  | 'trash'
  | 'upload'

export type IconTone = 'brand' | 'success' | 'warning' | 'danger' | 'neutral' | 'violet'

const registry: Record<IconName, Component> = {
  activity: Activity,
  'arrow-down': ArrowDownRight,
  'cursor-click': MousePointerClick,
  document: FileText,
  'trending-up': TrendingUp,
  'arrow-up': ArrowUpRight,
  ban: Ban,
  bell: BellRing,
  building: Building2,
  calendar: Calendar,
  'calendar-clock': CalendarClock,
  'chart-bar': BarChart3,
  'chart-doughnut': PieChart,
  'chart-line': LineChart,
  check: CheckCircle2,
  clock: Clock,
  eye: Eye,
  'eye-off': EyeOff,
  file: FileText,
  gauge: Gauge,
  graduation: GraduationCap,
  lightbulb: Lightbulb,
  list: ListChecks,
  megaphone: Megaphone,
  moon: Moon,
  news: Newspaper,
  phone: Phone,
  plane: Plane,
  rotate: RotateCcw,
  search: Search,
  settings: Settings,
  shield: ShieldCheck,
  'shopping-bag': ShoppingBag,
  sparkles: Sparkles,
  stethoscope: Stethoscope,
  sun: Sun,
  support: LifeBuoy,
  timer: Timer,
  'trend-down': TrendingDown,
  'trend-up': TrendingUp,
  'user-check': UserCheck,
  users: Users,
  x: XCircle,
  zap: Zap,
  alert: TriangleAlert,
  bot: Bot,
  brain: Brain,
  'cloud-download': CloudDownload,
  database: Database,
  'file-edit': FileEdit,
  image: Image,
  info: Info,
  pencil: Pencil,
  refresh: RefreshCw,
  rocket: Rocket,
  save: Save,
  star: Star,
  table: Table2,
  trash: Trash2,
  upload: Upload,
}

const toneClasses: Record<IconTone, string> = {
  brand: 'text-brand-700',
  success: 'text-success-600',
  warning: 'text-warning-600',
  danger: 'text-danger-600',
  neutral: 'text-ink-muted',
  violet: 'text-violet-600',
}

const props = withDefaults(
  defineProps<{
    name: IconName
    tone?: IconTone
    size?: 'sm' | 'md' | 'lg' | 'xl'
  }>(),
  {
    tone: 'neutral',
    size: 'md',
  },
)

const sizeClasses: Record<string, string> = {
  sm: 'size-4',
  md: 'size-5',
  lg: 'size-6',
  xl: 'size-8',
}

const icon = computed(() => registry[props.name] ?? Sparkles)
</script>

<template>
  <component
    :is="icon"
    :class="['shrink-0', toneClasses[tone], sizeClasses[size]]"
    aria-hidden="true"
  />
</template>
