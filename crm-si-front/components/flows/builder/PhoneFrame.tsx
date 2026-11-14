import type { ReactNode } from "react"

/** Marco de teléfono de las vistas previas del constructor. */
export function PhoneFrame({ children }: { children: ReactNode }) {
  return (
    <div className="mx-auto w-full max-w-[300px] rounded-[2.25rem] border-[6px] border-neutral-800 bg-neutral-800 shadow-lg">
      <div className="flex h-[560px] flex-col overflow-hidden rounded-[1.85rem] bg-white text-neutral-900">{children}</div>
    </div>
  )
}
