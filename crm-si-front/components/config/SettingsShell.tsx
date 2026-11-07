"use client"

import Link from "next/link"
import { ArrowLeft, ChevronRight } from "lucide-react"

import { BrandPilot } from "@/components/brand/BrandPilot"
import { SidebarLayout } from "@/components/SidebarLayout"
import { SettingsHeadingLevelProvider } from "@/components/config/SettingsBlock"
import {
  SETTINGS_ROOT,
  useSettingsPages,
  type SettingsGroupDefinition,
  type SettingsPageDefinition,
} from "@/components/config/settings-pages"
import { Skeleton } from "@/components/ui/skeleton"
import { useTranslation } from "@/hooks/useTranslation"
import { cn } from "@/lib/utils"
import { useAuthStore } from "@/store/useAuthStore"

/** Índice de /configuracion: un mapa por grupos de todo lo que se puede ajustar. */
export function SettingsIndex() {
  const { t } = useTranslation()
  const groups = useSettingsPages()
  const hasHydrated = useAuthStore((state) => state._hasHydrated)

  return (
    <SidebarLayout>
      <BrandPilot />
      <div className="flex-1 overflow-y-auto">
        <div className="mx-auto w-full max-w-5xl px-4 pb-24 pt-6 md:px-8 md:pt-10">
          <header className="mb-6 md:mb-8">
            <h1 className="text-2xl font-semibold tracking-tight text-foreground">
              {t("settings.title")}
            </h1>
            <p className="mt-1.5 max-w-[68ch] text-pretty text-sm leading-6 text-muted-foreground">
              {t("settings.page.description")}
            </p>
          </header>

          {!hasHydrated ? (
            <IndexSkeleton />
          ) : groups.length === 0 ? (
            <RestrictedNotice />
          ) : (
            <div className="divide-y divide-border border-t border-border">
              {groups.map((group) => (
                <IndexGroup key={group.id} group={group} />
              ))}
            </div>
          )}
        </div>
      </div>
    </SidebarLayout>
  )
}

function IndexGroup({ group }: { group: SettingsGroupDefinition }) {
  const titleId = `settings-group-${group.id}`

  return (
    <section
      aria-labelledby={titleId}
      className="grid gap-x-10 gap-y-4 py-8 md:grid-cols-[14rem_minmax(0,1fr)]"
    >
      <div>
        <h2 id={titleId} className="text-[0.9375rem] font-semibold text-foreground">
          {group.title}
        </h2>
        <p className="mt-1 text-pretty text-sm leading-6 text-muted-foreground">
          {group.description}
        </p>
      </div>
      {/* El -mx compensa el padding de cada fila para que los íconos alineen con el título del grupo en mobile. */}
      <ul className="-mx-3 grid gap-x-4 gap-y-1 sm:grid-cols-2">
        {group.pages.map((page) => (
          <li key={page.slug}>
            <IndexLink page={page} />
          </li>
        ))}
      </ul>
    </section>
  )
}

function IndexLink({ page }: { page: SettingsPageDefinition }) {
  const Icon = page.icon

  return (
    <Link
      href={page.href}
      className="group flex h-full items-start gap-3 rounded-lg p-3 outline-none transition-colors hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring"
    >
      <span className="flex size-9 shrink-0 items-center justify-center rounded-lg border border-border bg-background text-muted-foreground transition-colors group-hover:text-primary">
        <Icon className="size-4" aria-hidden="true" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="flex items-center gap-1 text-sm font-medium text-foreground">
          {page.title}
          <ChevronRight
            className="size-3.5 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100 motion-reduce:transition-none"
            aria-hidden="true"
          />
        </span>
        <span className="mt-0.5 block text-pretty text-sm leading-5 text-muted-foreground">
          {page.description}
        </span>
      </span>
    </Link>
  )
}

function IndexSkeleton() {
  return (
    <div className="divide-y divide-border border-t border-border" aria-hidden="true">
      {[0, 1].map((group) => (
        <div key={group} className="grid gap-x-10 gap-y-4 py-8 md:grid-cols-[14rem_minmax(0,1fr)]">
          <div className="space-y-2">
            <Skeleton className="h-4 w-28" />
            <Skeleton className="h-3 w-44" />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            {[0, 1, 2, 3].map((item) => (
              <div key={item} className="flex gap-3">
                <Skeleton className="size-9 rounded-lg" />
                <div className="flex-1 space-y-2">
                  <Skeleton className="h-4 w-32" />
                  <Skeleton className="h-3 w-full" />
                </div>
              </div>
            ))}
          </div>
        </div>
      ))}
    </div>
  )
}

function RestrictedNotice() {
  const { t } = useTranslation()

  return (
    <div className="border-t border-border py-12">
      <h2 className="text-base font-semibold text-foreground">{t("settings.page.restricted.title")}</h2>
      <p className="mt-1.5 max-w-lg text-sm text-muted-foreground">
        {t("settings.page.restricted.description")}
      </p>
    </div>
  )
}

/** Una página de configuración con la navegación de todas las demás al costado. */
export function SettingsPageView({ slug }: { slug: string }) {
  const { t } = useTranslation()
  const groups = useSettingsPages()
  const hasHydrated = useAuthStore((state) => state._hasHydrated)
  const page = groups.flatMap((group) => group.pages).find((item) => item.slug === slug)

  return (
    <SidebarLayout>
      <BrandPilot />
      <div className="flex-1 overflow-y-auto">
        <div className="mx-auto flex w-full max-w-6xl gap-10 px-4 md:px-8 xl:gap-16">
          <aside className="sticky top-0 hidden max-h-dvh w-52 shrink-0 self-start overflow-y-auto pb-10 pt-10 md:block">
            <BackToIndex />
            <SettingsSideNav groups={groups} activeSlug={slug} />
          </aside>

          <div className="min-w-0 flex-1 pb-24 pt-6 md:pt-10">
            <div className="mb-6 md:hidden">
              <BackToIndex />
            </div>
            {!hasHydrated ? (
              <PageSkeleton />
            ) : !page ? (
              <div className="py-6">
                <h1 className="text-xl font-semibold tracking-tight text-foreground">
                  {t("settings.page.notFound.title")}
                </h1>
                <p className="mt-1.5 max-w-lg text-sm text-muted-foreground">
                  {t("settings.page.notFound.description")}
                </p>
              </div>
            ) : page.multiBlock ? (
              <>
                <header>
                  <h1 className="text-xl font-semibold tracking-tight text-foreground">{page.title}</h1>
                  <p className="mt-1.5 max-w-[68ch] text-pretty text-sm leading-6 text-muted-foreground">
                    {page.description}
                  </p>
                </header>
                <SettingsHeadingLevelProvider level="section">
                  <div className="mt-10 space-y-12">{page.render()}</div>
                </SettingsHeadingLevelProvider>
              </>
            ) : (
              <SettingsHeadingLevelProvider level="page" pageTitle={page.title}>
                {page.render()}
              </SettingsHeadingLevelProvider>
            )}
          </div>
        </div>
      </div>
    </SidebarLayout>
  )
}

function BackToIndex() {
  const { t } = useTranslation()

  return (
    <Link
      href={SETTINGS_ROOT}
      className="inline-flex items-center gap-1.5 rounded-md text-sm font-medium text-muted-foreground outline-none transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
    >
      <ArrowLeft className="size-4" aria-hidden="true" />
      {t("settings.title")}
    </Link>
  )
}

function SettingsSideNav({
  groups,
  activeSlug,
}: {
  groups: SettingsGroupDefinition[]
  activeSlug: string
}) {
  const { t } = useTranslation()

  return (
    <nav aria-label={t("settings.page.navigationLabel")} className="mt-8 space-y-6">
      {groups.map((group) => (
        <div key={group.id}>
          <p className="px-2.5 pb-1.5 text-xs font-medium text-muted-foreground">{group.title}</p>
          <ul className="space-y-0.5">
            {group.pages.map((page) => {
              const Icon = page.icon
              const isActive = page.slug === activeSlug
              return (
                <li key={page.slug}>
                  <Link
                    href={page.href}
                    aria-current={isActive ? "page" : undefined}
                    className={cn(
                      "flex items-center gap-2.5 rounded-md px-2.5 py-1.5 text-sm outline-none transition-colors focus-visible:ring-2 focus-visible:ring-ring",
                      isActive
                        ? "bg-muted font-medium text-foreground"
                        : "text-muted-foreground hover:bg-muted/60 hover:text-foreground",
                    )}
                  >
                    <Icon
                      className={cn("size-4 shrink-0", isActive ? "text-primary" : "text-muted-foreground")}
                      aria-hidden="true"
                    />
                    <span className="truncate">{page.title}</span>
                  </Link>
                </li>
              )
            })}
          </ul>
        </div>
      ))}
    </nav>
  )
}

function PageSkeleton() {
  return (
    <div className="space-y-3" aria-hidden="true">
      <Skeleton className="h-6 w-48" />
      <Skeleton className="h-4 w-80 max-w-full" />
      <div className="mt-8 space-y-3">
        <Skeleton className="h-10 w-full max-w-2xl" />
        <Skeleton className="h-10 w-full max-w-2xl" />
      </div>
    </div>
  )
}
