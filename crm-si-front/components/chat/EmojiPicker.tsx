"use client"

import { useState } from "react"
import { useTranslation } from "@/hooks/useTranslation"

export const EMOJI_GROUPS = [
  {
    key: "smileys",
    translationKey: "chats.emojiCategorySmileys",
    icon: "😀",
    emojis: [
      "😀", "😃", "😄", "😁", "😆", "😅", "😂", "🤣",
      "😊", "😇", "🙂", "😉", "😍", "🥰", "😘", "😎",
      "🤔", "🤨", "😐", "😴", "🥲", "😢", "😭", "😤", "😮",
    ],
  },
  {
    key: "gestures",
    translationKey: "chats.emojiCategoryGestures",
    icon: "👍",
    emojis: [
      "👍", "👎", "👌", "✌️", "🤞", "🤝", "👏", "🙌",
      "🙏", "💪", "👋", "🤙", "☝️", "✋", "🖐️", "🤟",
    ],
  },
  {
    key: "hearts",
    translationKey: "chats.emojiCategoryHearts",
    icon: "❤️",
    emojis: [
      "❤️", "🧡", "💛", "💚", "💙", "💜", "🤍", "🖤",
      "💔", "❣️", "💕", "💞", "💗", "💖", "💘", "💝",
    ],
  },
  {
    key: "celebration",
    translationKey: "chats.emojiCategoryCelebration",
    icon: "🎉",
    emojis: [
      "🎉", "🎊", "✨", "🔥", "🚀", "🏆", "🎯", "🥳",
      "💥", "⭐", "🌟", "🎁", "🎈", "🍾", "🥂", "👑",
    ],
  },
  {
    key: "objects",
    translationKey: "chats.emojiCategoryObjects",
    icon: "📌",
    emojis: [
      "📌", "📅", "💬", "📞", "✅", "❌", "⚠️", "💡",
      "📎", "📷", "💰", "📦", "🕐", "📝", "🔗", "📊",
    ],
  },
] as const

interface EmojiPickerProps {
  onSelect: (emoji: string) => void
  /** En la hoja móvil la grilla necesita más aire para que el toque sea cómodo. */
  size?: "compact" | "touch"
}

/**
 * Grilla de emojis con pestañas de categoría. Se usa dentro de un popover en
 * escritorio y dentro de una hoja inferior en móvil, así que no trae contenedor
 * propio: sólo el contenido.
 */
export function EmojiPicker({ onSelect, size = "compact" }: EmojiPickerProps) {
  const { t } = useTranslation()
  const [activeKey, setActiveKey] = useState<string>(EMOJI_GROUPS[0].key)

  const activeGroup = EMOJI_GROUPS.find((g) => g.key === activeKey) ?? EMOJI_GROUPS[0]
  const isTouch = size === "touch"

  return (
    <div className="flex flex-col gap-2">
      <div
        role="tablist"
        aria-label={t("chats.emojiPickerTitle")}
        className="flex items-center gap-1 border-b border-border pb-2"
      >
        {EMOJI_GROUPS.map((group) => {
          const isActive = group.key === activeKey
          return (
            <button
              key={group.key}
              type="button"
              role="tab"
              aria-selected={isActive}
              aria-label={t(group.translationKey)}
              title={t(group.translationKey)}
              onClick={() => setActiveKey(group.key)}
              className={`flex flex-1 items-center justify-center rounded-md text-lg transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none ${
                isTouch ? "h-10" : "h-8"
              } ${isActive ? "bg-accent" : "hover:bg-accent/60"}`}
            >
              <span aria-hidden="true">{group.icon}</span>
            </button>
          )
        })}
      </div>

      <div
        role="tabpanel"
        aria-label={t(activeGroup.translationKey)}
        className={`grid grid-cols-8 gap-1 overflow-y-auto ${isTouch ? "max-h-64" : "max-h-48"}`}
      >
        {activeGroup.emojis.map((emoji) => (
          <button
            key={`${activeGroup.key}-${emoji}`}
            type="button"
            className={`flex items-center justify-center rounded-md transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none ${
              isTouch ? "h-11 text-2xl" : "h-9 text-lg"
            }`}
            onClick={() => onSelect(emoji)}
            aria-label={`${t(activeGroup.translationKey)} ${emoji}`}
          >
            {emoji}
          </button>
        ))}
      </div>
    </div>
  )
}
