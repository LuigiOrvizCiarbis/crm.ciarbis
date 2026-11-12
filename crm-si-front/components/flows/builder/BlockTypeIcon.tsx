import {
  AlignLeft,
  CalendarDays,
  CircleDot,
  Heading1,
  Heading2,
  ListChecks,
  Pilcrow,
  SquareCheck,
  SquareChevronDown,
  TextCursorInput,
  Type,
  type LucideIcon,
} from "lucide-react"

import type { FlowBlock } from "@/lib/flow-builder/types"

const ICONS: Record<FlowBlock["type"], LucideIcon> = {
  heading: Heading1,
  subheading: Heading2,
  body: Pilcrow,
  caption: Type,
  short_text: TextCursorInput,
  long_text: AlignLeft,
  dropdown: SquareChevronDown,
  single_choice: CircleDot,
  multiple_choice: ListChecks,
  date: CalendarDays,
  opt_in: SquareCheck,
}

export function BlockTypeIcon({ type, className }: { type: FlowBlock["type"]; className?: string }) {
  const Icon = ICONS[type]
  return <Icon className={className} aria-hidden />
}
