"use client"

import { useState } from "react"
import { Eraser, Globe, Loader2 } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import { useToast } from "@/components/Toast"
import { useTranslation } from "@/hooks/useTranslation"
import { generateWebsiteContext } from "@/lib/api/ai-config"

export const WEBSITE_CONTEXT_MAX_LENGTH = 8000

interface AiWebsiteContextCardProps {
  url: string
  onUrlChange: (value: string) => void
  context: string
  onContextChange: (value: string) => void
  hasApiKey: boolean
}

/**
 * Información complementaria al system prompt, generada desde el sitio web
 * del tenant. El borrador queda sin guardar: se persiste con el botón
 * Guardar de la configuración de IA.
 */
export function AiWebsiteContextCard({
  url,
  onUrlChange,
  context,
  onContextChange,
  hasApiKey,
}: AiWebsiteContextCardProps) {
  const { addToast } = useToast()
  const { t } = useTranslation()
  const [generating, setGenerating] = useState(false)
  const [confirmOverwrite, setConfirmOverwrite] = useState(false)

  const contextLength = context.length
  const isContextEmpty = context.trim().length === 0
  const isNearLimit = contextLength >= WEBSITE_CONTEXT_MAX_LENGTH * 0.9
  const limitLabel = WEBSITE_CONTEXT_MAX_LENGTH.toLocaleString()
  const usage = Math.min((contextLength / WEBSITE_CONTEXT_MAX_LENGTH) * 100, 100)
  const canGenerate = hasApiKey && Boolean(url.trim()) && !generating

  const generate = async () => {
    setGenerating(true)
    const { data, error } = await generateWebsiteContext(url.trim())
    setGenerating(false)

    if (!data) {
      addToast({
        type: "error",
        title: t("common.error"),
        description: error ?? t("common.error"),
      })
      return
    }

    onContextChange(data.website_context)
    onUrlChange(data.website_url)
    addToast({
      type: "success",
      title: t("settings.aiAssistant.website.generated"),
      description: t("settings.aiAssistant.website.generatedHint", {
        count: data.pages_read.length,
      }),
    })
  }

  const handleGenerateClick = () => {
    if (isContextEmpty) {
      void generate()
      return
    }
    setConfirmOverwrite(true)
  }

  return (
    <div className="rounded-lg border border-border bg-muted/20">
      <div className="flex flex-col gap-3 border-b border-border p-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="space-y-1">
          <Label htmlFor="ai-website-context" className="text-sm font-semibold">
            {t("settings.aiAssistant.website.title")}
          </Label>
          <p
            id="ai-website-context-help"
            className="max-w-2xl text-xs leading-5 text-muted-foreground"
          >
            {t("settings.aiAssistant.website.hint", { max: limitLabel })}
          </p>
        </div>
        <div className="flex shrink-0 flex-wrap gap-2">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => onContextChange("")}
            disabled={isContextEmpty || generating}
          >
            <Eraser className="h-4 w-4" />
            {t("settings.aiAssistant.website.clear")}
          </Button>
        </div>
      </div>

      <div className="space-y-3 p-4">
        <div className="space-y-2">
          <Label htmlFor="ai-website-url">
            {t("settings.aiAssistant.website.urlLabel")}
          </Label>
          <div className="flex flex-col gap-2 sm:flex-row">
            <Input
              id="ai-website-url"
              type="url"
              inputMode="url"
              value={url}
              onChange={(e) => onUrlChange(e.target.value)}
              placeholder="https://tuempresa.com"
              disabled={generating}
              className="bg-background"
            />
            <Button
              type="button"
              variant="outline"
              onClick={handleGenerateClick}
              disabled={!canGenerate}
              className="shrink-0"
            >
              {generating ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <Globe className="h-4 w-4" />
              )}
              {generating
                ? t("settings.aiAssistant.website.generating")
                : t("settings.aiAssistant.website.generate")}
            </Button>
          </div>
          {!hasApiKey && (
            <p className="text-xs text-muted-foreground">
              {t("settings.aiAssistant.website.needsKey")}
            </p>
          )}
        </div>

        <Textarea
          id="ai-website-context"
          value={context}
          onChange={(e) => onContextChange(e.target.value)}
          placeholder={t("settings.aiAssistant.website.placeholder")}
          maxLength={WEBSITE_CONTEXT_MAX_LENGTH}
          rows={8}
          disabled={generating}
          aria-describedby="ai-website-context-help ai-website-context-count"
          className="min-h-[180px] resize-y bg-background font-mono text-sm leading-6"
        />

        <div className="space-y-2">
          <div
            className="h-1.5 overflow-hidden rounded-full bg-muted"
            role="progressbar"
            aria-label={t("settings.aiAssistant.website.usage")}
            aria-valuemin={0}
            aria-valuemax={WEBSITE_CONTEXT_MAX_LENGTH}
            aria-valuenow={contextLength}
          >
            <div
              className={
                isNearLimit
                  ? "h-full rounded-full bg-warning transition-[width]"
                  : "h-full rounded-full bg-primary transition-[width]"
              }
              style={{ width: `${usage}%` }}
            />
          </div>
          <div className="flex justify-end text-xs">
            <span
              id="ai-website-context-count"
              className={
                isNearLimit
                  ? "shrink-0 font-medium text-warning"
                  : "shrink-0 text-muted-foreground"
              }
            >
              {contextLength.toLocaleString()} / {limitLabel}
            </span>
          </div>
        </div>
      </div>

      <AlertDialog open={confirmOverwrite} onOpenChange={setConfirmOverwrite}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {t("settings.aiAssistant.website.overwriteTitle")}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {t("settings.aiAssistant.website.overwriteDescription")}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction onClick={() => void generate()}>
              {t("settings.aiAssistant.website.overwriteConfirm")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
