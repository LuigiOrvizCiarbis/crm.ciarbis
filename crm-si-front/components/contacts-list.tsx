"use client"

import type React from "react"
import { useState, useEffect, useMemo, useRef } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Badge } from "@/components/ui/badge"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { Checkbox } from "@/components/ui/checkbox"
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/components/ui/dropdown-menu"
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from "@/components/ui/dialog"
import { Sheet, SheetContent, SheetHeader, SheetTitle } from "@/components/ui/sheet"
import { Label } from "@/components/ui/label"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Separator } from "@/components/ui/separator"
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from "@/components/ui/alert-dialog"
import { MoreVertical, Phone, Mail, MessageSquare, Users, Loader2, Calendar, Hash, X, GripVertical, ArrowUpDown, ArrowUp, ArrowDown, Tags } from "lucide-react"
import { ImportContactsDialog } from "./import-contacts-dialog"
import { BulkTagsDialog } from "./contacts/bulk-tags-dialog"
import { ExtractDocumentDialog } from "./contacts/ExtractDocumentDialog"
import { getAuthToken } from "@/lib/api/auth-token"
import { getPipelineStages } from "@/lib/api/pipeline"
import { createOpportunity, getOpportunities, updateOpportunityStage } from "@/lib/api/opportunities"
import { updateContact, type ContactUpdate } from "@/lib/api/contacts"
import { CustomFieldInput } from "@/components/CustomFieldInput"
import { useContactFieldsStore } from "@/store/useContactFieldsStore"
import type { Tag } from "@/lib/api/tags"
import { useAutosave } from "@/lib/hooks/useAutosave"
import { cn } from "@/lib/utils"
import { EditableCell } from "@/components/editable-cell"
import { TagChips } from "@/components/tags/TagChips"
import { TagPicker } from "@/components/tags/TagPicker"
import { useToast } from "@/components/Toast"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { useTranslation } from "@/hooks/useTranslation"
import { useRouter } from "next/navigation"
import { format } from "date-fns"
import { es } from "date-fns/locale"
import {
  DndContext,
  closestCenter,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
} from "@dnd-kit/core"
import {
  arrayMove,
  SortableContext,
  horizontalListSortingStrategy,
  sortableKeyboardCoordinates,
  useSortable,
} from "@dnd-kit/sortable"
import { CSS } from "@dnd-kit/utilities"

interface Contact {
  id: number
  name: string
  email: string | null
  phone: string | null
  source: string
  custom_data?: Record<string, unknown>
  created_at: string
  updated_at: string
  conversations?: Array<{
    id: number
    last_message_at: string
    last_message_content: string
  }>
  tags?: Tag[]
}

const DEFAULT_FORM: { name: string; phone: string; email: string; source: string; custom_data: Record<string, unknown> } = {
  name: "",
  phone: "",
  email: "",
  source: "manual",
  custom_data: {},
}

function getInitials(name: string): string {
  return name.split(" ").map((n) => n[0]).join("").slice(0, 2).toUpperCase()
}

function capitalize(value: string): string {
  if (!value) return value
  return value.charAt(0).toUpperCase() + value.slice(1)
}

const AVATAR_GRADIENTS = [
  "from-cyan-500 to-blue-600",
  "from-violet-500 to-purple-600",
  "from-emerald-500 to-green-600",
  "from-orange-500 to-amber-600",
  "from-pink-500 to-rose-600",
  "from-indigo-500 to-blue-600",
]

function getAvatarGradient(id: number): string {
  return AVATAR_GRADIENTS[id % AVATAR_GRADIENTS.length]
}

const sourceColors: Record<string, string> = {
  whatsapp: "bg-green-500/10 text-green-600 border-green-200 dark:border-green-800",
  instagram: "bg-pink-500/10 text-pink-600 border-pink-200 dark:border-pink-800",
  facebook: "bg-blue-500/10 text-blue-600 border-blue-200 dark:border-blue-800",
  manual: "bg-gray-500/10 text-gray-600 border-gray-200 dark:border-gray-700",
}

const sourceLabels: Record<string, string> = {
  whatsapp: "WhatsApp",
  instagram: "Instagram",
  facebook: "Facebook",
  manual: "Manual",
}

type ColumnId = "select" | "contact" | "phone" | "email" | "source" | "tags" | "lastContact" | "actions" | `custom:${string}`
type SortField = "name" | "phone" | "email" | "source" | "updated_at"
type SortDirection = "asc" | "desc"

interface Column {
  id: ColumnId
  labelKey: string
  width: string
  minWidth: string
  draggable: boolean
}

const DEFAULT_COLUMNS: Column[] = [
  { id: "select", labelKey: "", width: "50px", minWidth: "50px", draggable: false },
  { id: "contact", labelKey: "contactsPage.table.contact", width: "260px", minWidth: "220px", draggable: true },
  { id: "phone", labelKey: "contactsPage.table.phone", width: "180px", minWidth: "160px", draggable: true },
  { id: "email", labelKey: "contactsPage.table.email", width: "220px", minWidth: "180px", draggable: true },
  { id: "source", labelKey: "contactsPage.table.source", width: "140px", minWidth: "120px", draggable: true },
  { id: "tags", labelKey: "Etiquetas", width: "210px", minWidth: "180px", draggable: true },
  { id: "lastContact", labelKey: "contactsPage.table.lastContact", width: "150px", minWidth: "130px", draggable: true },
  { id: "actions", labelKey: "contactsPage.table.actions", width: "150px", minWidth: "130px", draggable: true },
]

const COLUMN_ORDER_KEY = "contacts-column-order"
const COLUMN_SORT_FIELDS: Partial<Record<ColumnId, SortField>> = {
  contact: "name",
  phone: "phone",
  email: "email",
  source: "source",
  lastContact: "updated_at",
}

function formatCustomValue(value: unknown, type: string | undefined): string {
  if (value === null || value === undefined || value === "") return "—"
  if (Array.isArray(value)) return value.length > 0 ? value.join(", ") : "—"
  if (typeof value === "boolean") return value ? "Sí" : "No"
  if (type === "date" && typeof value === "string") {
    try {
      return format(new Date(value), "d MMM yyyy", { locale: es })
    } catch {
      return value
    }
  }
  // Un campo tipo archivo guarda un media_asset_id; mostrar el número crudo no
  // aporta, así que se indica solo que hay un archivo cargado.
  if (type === "file") return "📎"
  return String(value)
}

function loadColumnOrder(): Column[] {
  if (typeof window === "undefined") return DEFAULT_COLUMNS
  try {
    const saved = window.localStorage.getItem(COLUMN_ORDER_KEY)
    if (!saved) return DEFAULT_COLUMNS
    const ids = JSON.parse(saved) as ColumnId[]
    const ordered: Column[] = ids
      .map((id): Column | undefined => {
        const known = DEFAULT_COLUMNS.find((c) => c.id === id)
        if (known) return known
        if (typeof id === "string" && id.startsWith("custom:")) {
          return { id, labelKey: id.slice("custom:".length), width: "180px", minWidth: "140px", draggable: true }
        }
        return undefined
      })
      .filter((c): c is Column => Boolean(c))
    const missing = DEFAULT_COLUMNS.filter((c) => !ordered.find((o) => o.id === c.id))
    return [...ordered, ...missing]
  } catch {
    return DEFAULT_COLUMNS
  }
}

function SortableHeader({
  column,
  label,
  sortField,
  sortDirection,
  onSort,
}: {
  column: Column
  label: string
  sortField: SortField
  sortDirection: SortDirection
  onSort: (columnId: ColumnId) => void
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: column.id,
    disabled: !column.draggable,
  })

  const style: React.CSSProperties = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
    width: column.width,
    minWidth: column.minWidth,
  }

  if (!column.draggable) {
    return (
      <TableHead ref={setNodeRef} style={style} className={column.id === "actions" ? "text-right" : ""}>
        {label}
      </TableHead>
    )
  }

  const columnSortField = COLUMN_SORT_FIELDS[column.id]
  const isSorted = columnSortField === sortField
  const SortIcon = isSorted ? (sortDirection === "asc" ? ArrowUp : ArrowDown) : ArrowUpDown

  return (
    <TableHead
      ref={setNodeRef}
      style={style}
      className={cn(
        "select-none cursor-grab active:cursor-grabbing",
        column.id === "actions" ? "text-right" : "",
      )}
      {...attributes}
      {...listeners}
    >
      <div className={cn("flex items-center gap-2", column.id === "actions" ? "justify-end" : "")}>
        <GripVertical className="w-3.5 h-3.5 text-muted-foreground/60" />
        {columnSortField ? (
          <button
            type="button"
            className={cn(
              "inline-flex min-w-0 items-center gap-1.5 rounded px-1 py-0.5 text-left hover:bg-muted",
              isSorted && "text-primary",
            )}
            onPointerDown={(event) => event.stopPropagation()}
            onClick={(event) => {
              event.stopPropagation()
              onSort(column.id)
            }}
          >
            <span className="truncate">{label}</span>
            <SortIcon className="h-3.5 w-3.5 shrink-0" />
          </button>
        ) : (
          <span>{label}</span>
        )}
      </div>
    </TableHead>
  )
}

interface ContactsListProps {
  searchTerm?: string
  onSearchTermChange?: (value: string) => void
  sourceFilter?: string
  tagFilterSlugs?: string[]
}

export function ContactsList({
  searchTerm = "",
  onSearchTermChange,
  sourceFilter = "all",
  tagFilterSlugs = [],
}: ContactsListProps = {}) {
  const { t } = useTranslation()
  const router = useRouter()
  const { addToast } = useToast()
  const [contacts, setContacts] = useState<Contact[]>([])
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(20)
  const [sortField, setSortField] = useState<SortField>("updated_at")
  const [sortDirection, setSortDirection] = useState<SortDirection>("desc")
  const [paginationMeta, setPaginationMeta] = useState({
    total: 0,
    current_page: 1,
    last_page: 1,
    from: 0,
    to: 0,
  })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const contactFields = useContactFieldsStore((s) => s.fields)
  const fetchContactFields = useContactFieldsStore((s) => s.fetch)
  const contactFieldsLoaded = useContactFieldsStore((s) => s.loaded)
  useEffect(() => {
    if (!contactFieldsLoaded) fetchContactFields()
  }, [contactFieldsLoaded, fetchContactFields])
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set())

  const [dialogOpen, setDialogOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [formData, setFormData] = useState<{ name: string; phone: string; email: string; source: string; custom_data: Record<string, unknown> }>({ ...DEFAULT_FORM })
  const [formErrors, setFormErrors] = useState<Record<string, string>>({})
  const [editingContact, setEditingContact] = useState<Contact | null>(null)

  const [profileOpen, setProfileOpen] = useState(false)
  const [profileContact, setProfileContact] = useState<Contact | null>(null)

  // Extracción de datos desde un PDF. Vive acá y no en el panel de perfil
  // porque ese es de sólo lectura y no recarga el contacto al confirmar.
  const [extractionContact, setExtractionContact] = useState<Contact | null>(null)

  const [deleteContact, setDeleteContact] = useState<Contact | null>(null)
  const [deleting, setDeleting] = useState(false)
  const [addingToPipelineId, setAddingToPipelineId] = useState<number | null>(null)
  const [importOpen, setImportOpen] = useState(false)
  const [bulkTagsOpen, setBulkTagsOpen] = useState(false)

  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const profileResetRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  type EditableField = "name" | "phone" | "email" | "source"
  type EditableCellField = EditableField | `custom:${string}`
  const [editingCell, setEditingCell] = useState<{ contactId: number; field: EditableCellField } | null>(null)

  const customFieldColumns = useMemo<Column[]>(
    () =>
      contactFields.map((f) => ({
        id: `custom:${f.key}` as ColumnId,
        labelKey: f.label,
        width: "180px",
        minWidth: "140px",
        draggable: true,
      })),
    [contactFields],
  )

  const [columns, setColumns] = useState<Column[]>(DEFAULT_COLUMNS)

  useEffect(() => {
    setColumns(loadColumnOrder())
  }, [])

  useEffect(() => {
    if (customFieldColumns.length === 0) return
    setColumns((prev) => {
      const knownIds = new Set(prev.map((c) => c.id))
      const additions = customFieldColumns.filter((c) => !knownIds.has(c.id))
      if (additions.length === 0) {
        // Keep labels in sync (custom field renames).
        return prev.map((c) => {
          const next = customFieldColumns.find((cf) => cf.id === c.id)
          return next ? { ...c, labelKey: next.labelKey } : c
        })
      }
      const actionsIndex = prev.findIndex((c) => c.id === "actions")
      if (actionsIndex === -1) return [...prev, ...additions]
      return [...prev.slice(0, actionsIndex), ...additions, ...prev.slice(actionsIndex)]
    })
  }, [customFieldColumns])

  useEffect(() => {
    if (typeof window === "undefined") return
    window.localStorage.setItem(COLUMN_ORDER_KEY, JSON.stringify(columns.map((c) => c.id)))
  }, [columns])

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )

  const handleColumnDragEnd = (event: DragEndEvent): void => {
    const { active, over } = event
    if (!over || active.id === over.id) return
    setColumns((items) => {
      const oldIndex = items.findIndex((i) => i.id === active.id)
      const newIndex = items.findIndex((i) => i.id === over.id)
      if (oldIndex === -1 || newIndex === -1) return items
      if (!items[oldIndex].draggable || !items[newIndex].draggable) return items
      return arrayMove(items, oldIndex, newIndex)
    })
  }

  const handleSort = (columnId: ColumnId): void => {
    const nextSortField = COLUMN_SORT_FIELDS[columnId]
    if (!nextSortField) return

    if (nextSortField === sortField) {
      setSortDirection((current) => (current === "asc" ? "desc" : "asc"))
      return
    }

    setSortField(nextSortField)
    setSortDirection(nextSortField === "updated_at" ? "desc" : "asc")
  }

  const { save: saveAutosave } = useAutosave<{ id: number; updates: ContactUpdate; rollback: Partial<Contact> }>({
    onSave: async ({ id, updates }) => {
      const updated = await updateContact(id, updates)
      setContacts((prev) => prev.map((c) => (c.id === id ? { ...c, ...updated } : c)))
    },
    onError: ({ id, rollback }) => {
      setContacts((prev) => prev.map((c) => (c.id === id ? { ...c, ...rollback } : c)))
    },
  })

  const handleCellSave = (contact: Contact, field: EditableField, rawValue: string): void => {
    const trimmed = rawValue.trim()
    let nextValue: string | null = trimmed

    if (field === "name") {
      if (!trimmed) {
        addToast({ type: "error", title: "El nombre no puede estar vacío" })
        return
      }
    } else if (field === "email") {
      if (trimmed && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) {
        addToast({ type: "error", title: "Email inválido" })
        return
      }
      nextValue = trimmed || null
    } else if (field === "phone") {
      nextValue = trimmed || null
    }

    const previousValue = contact[field as keyof Contact]
    setContacts((prev) => prev.map((c) => (c.id === contact.id ? { ...c, [field]: nextValue } : c)))
    setEditingCell(null)
    saveAutosave({
      id: contact.id,
      updates: { [field]: nextValue } as ContactUpdate,
      rollback: { [field]: previousValue } as Partial<Contact>,
    })
  }

  const handleCustomCellSave = (contact: Contact, key: string, nextValue: unknown): void => {
    const previousCustomData = contact.custom_data ?? {}
    const current = previousCustomData[key]
    const normalized = nextValue === "" ? null : nextValue
    if (JSON.stringify(current ?? null) === JSON.stringify(normalized ?? null)) {
      return
    }

    const nextCustomData = { ...previousCustomData, [key]: normalized }
    setContacts((prev) =>
      prev.map((c) => (c.id === contact.id ? { ...c, custom_data: nextCustomData } : c)),
    )
    saveAutosave({
      id: contact.id,
      updates: { custom_data: { [key]: normalized } },
      rollback: { custom_data: previousCustomData },
    })
  }

  useEffect(() => {
    setPage(1)
  }, [searchTerm, sourceFilter, tagFilterSlugs, perPage, sortField, sortDirection])

  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(() => fetchContacts(), 300)
    return () => { if (debounceRef.current) clearTimeout(debounceRef.current) }
  }, [searchTerm, sourceFilter, tagFilterSlugs, page, perPage, sortField, sortDirection])

  useEffect(() => {
    return () => {
      if (profileResetRef.current) clearTimeout(profileResetRef.current)
    }
  }, [])

  // Listen to compact header events
  useEffect(() => {
    const handleNewContact = () => setDialogOpen(true)
    const handleExportCsv = () => exportCSV()
    const handleImportCsv = () => setImportOpen(true)
    window.addEventListener("contacts-new-contact", handleNewContact)
    window.addEventListener("contacts-export-csv", handleExportCsv)
    window.addEventListener("contacts-import-csv", handleImportCsv)
    return () => {
      window.removeEventListener("contacts-new-contact", handleNewContact)
      window.removeEventListener("contacts-export-csv", handleExportCsv)
      window.removeEventListener("contacts-import-csv", handleImportCsv)
    }
  }, [contacts])

  const fetchContacts = async () => {
    setLoading(true)
    setError(null)
    try {
      const queryParams = new URLSearchParams()
      if (searchTerm) queryParams.append("search", searchTerm)
      if (sourceFilter !== "all") queryParams.append("source", sourceFilter)
      if (tagFilterSlugs.length > 0) queryParams.append("tags", tagFilterSlugs.join(","))
      queryParams.append("page", String(page))
      queryParams.append("per_page", String(perPage))
      queryParams.append("sort_by", sortField)
      queryParams.append("sort_dir", sortDirection)
      const token = getAuthToken()
      const response = await fetch(`/api/contacts?${queryParams.toString()}`, {
        headers: { Authorization: `Bearer ${token}` },
      })
      if (!response.ok) throw new Error("Error al cargar contactos")
      const result = await response.json()
      setContacts(result.data || [])
      setPaginationMeta({
        total: result.meta?.total ?? 0,
        current_page: result.meta?.current_page ?? page,
        last_page: result.meta?.last_page ?? 1,
        from: result.meta?.from ?? 0,
        to: result.meta?.to ?? 0,
      })
    } catch (err) {
      setError(err instanceof Error ? err.message : "Error desconocido")
    } finally {
      setLoading(false)
    }
  }

  const exportCSV = () => {
    const headers = ["Nombre", "Teléfono", "Email", "Fuente", "Último contacto"]
    const rows = contacts.map((c) => [
      c.name,
      c.phone || "",
      c.email || "",
      sourceLabels[c.source] || c.source,
      getLastContact(c),
    ])
    const csvContent = [headers, ...rows].map((r) => r.map((v) => `"${v}"`).join(",")).join("\n")
    const blob = new Blob([csvContent], { type: "text/csv;charset=utf-8;" })
    const link = document.createElement("a")
    link.href = URL.createObjectURL(blob)
    link.download = `contactos_${new Date().toISOString().split("T")[0]}.csv`
    link.click()
  }

  const handleSaveContact = async () => {
    const errors: Record<string, string> = {}
    if (!formData.name.trim()) errors.name = "El nombre es obligatorio"
    if (formData.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email)) {
      errors.email = "Email inválido"
    }
    if (Object.keys(errors).length > 0) {
      setFormErrors(errors)
      return
    }

    setSaving(true)
    setFormErrors({})

    const isEdit = !!editingContact
    const url = isEdit ? `/api/contacts/${editingContact.id}` : "/api/contacts"
    const method = isEdit ? "PUT" : "POST"

    try {
      const token = getAuthToken()
      const response = await fetch(url, {
        method,
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
          name: formData.name.trim(),
          phone: formData.phone.trim() || null,
          email: formData.email.trim() || null,
          source: formData.source,
          custom_data: formData.custom_data ?? {},
        }),
      })

      if (!response.ok) {
        const result = await response.json().catch(() => ({}))
        if (result.errors) {
          setFormErrors(
            Object.fromEntries(
              Object.entries(result.errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : String(v)])
            )
          )
        } else {
          setFormErrors({ _general: result.message || (isEdit ? "Error al actualizar contacto" : "Error al crear contacto") })
        }
        return
      }

      setDialogOpen(false)
      setEditingContact(null)
      setFormData({ ...DEFAULT_FORM })
      fetchContacts()
    } catch {
      setFormErrors({ _general: "Error de conexión" })
    } finally {
      setSaving(false)
    }
  }

  const openEditDialog = (contact: Contact) => {
    setEditingContact(contact)
    setFormData({
      name: contact.name,
      phone: contact.phone || "",
      email: contact.email || "",
      source: contact.source || "manual",
      custom_data: (contact.custom_data as Record<string, unknown>) ?? {},
    })
    setFormErrors({})
    setDialogOpen(true)
  }

  const openProfileSheet = (contact: Contact) => {
    if (profileResetRef.current) clearTimeout(profileResetRef.current)
    setProfileContact(contact)
    requestAnimationFrame(() => setProfileOpen(true))
  }

  const handleProfileOpenChange = (open: boolean) => {
    setProfileOpen(open)
    if (!open) {
      if (profileResetRef.current) clearTimeout(profileResetRef.current)
      profileResetRef.current = setTimeout(() => setProfileContact(null), 300)
    }
  }

  const openContactConversation = (contact: Contact) => {
    const conversationId = contact.conversations?.[0]?.id
    if (conversationId) router.push(`/chats?chat=${conversationId}`)
  }

  const handleDeleteContact = async () => {
    if (!deleteContact) return
    setDeleting(true)
    try {
      const token = getAuthToken()
      const response = await fetch(`/api/contacts/${deleteContact.id}`, {
        method: "DELETE",
        headers: { Authorization: `Bearer ${token}` },
      })
      if (response.ok) {
        setDeleteContact(null)
        fetchContacts()
      }
    } catch {
      // silently fail
    } finally {
      setDeleting(false)
    }
  }

  const handleAddToPipeline = async (contact: Contact) => {
    const latestConversation = contact.conversations?.[0]
    setAddingToPipelineId(contact.id)
    try {
      const stages = await getPipelineStages()
      const sortedStages = [...stages].sort((a, b) => a.sort_order - b.sort_order)
      const targetStage = sortedStages.find((stage) => stage.is_default) ?? sortedStages[0]
      if (!targetStage) throw new Error("No hay etapas configuradas en el pipeline")

      const existingOpenOpportunities = await getOpportunities({ contactId: contact.id, status: "open" })
      const existingOpportunity = existingOpenOpportunities[0]

      if (existingOpportunity) {
        await updateOpportunityStage(existingOpportunity.id, targetStage.id)
      } else {
        await createOpportunity({
          contact_id: contact.id,
          conversation_id: latestConversation?.id ?? null,
          pipeline_stage_id: targetStage.id,
          source_type: latestConversation ? "conversation" : "manual",
          title: `Oportunidad - ${contact.name}`,
        })
      }

      addToast({
        type: "success",
        title: "Contacto agregado al pipeline",
        description: existingOpportunity
          ? `${contact.name} fue movido a ${targetStage.name}.`
          : `${contact.name} fue agregado a ${targetStage.name}.`,
      })
    } catch (error) {
      addToast({
        type: "error",
        title: "No se pudo agregar al pipeline",
        description: error instanceof Error ? error.message : "Error desconocido",
      })
    } finally {
      setAddingToPipelineId(null)
    }
  }

  const updateContactTags = (contactId: number, tags: Tag[]): void => {
    setContacts((prev) => prev.map((contact) => (contact.id === contactId ? { ...contact, tags } : contact)))
    setProfileContact((prev) => (prev?.id === contactId ? { ...prev, tags } : prev))
  }

  const filteredContacts = contacts
  const isInitialLoading = loading && contacts.length === 0 && !error

  const getLastContact = (contact: Contact): string => {
    if (contact.conversations && contact.conversations.length > 0) {
      return format(new Date(contact.conversations[0].last_message_at), "dd/MM/yyyy", { locale: es })
    }
    return format(new Date(contact.created_at), "dd/MM/yyyy", { locale: es })
  }

  const renderCell = (contact: Contact, columnId: ColumnId): React.ReactNode => {
    switch (columnId) {
      case "select":
        return (
          <Checkbox
            checked={selectedIds.has(contact.id)}
            onCheckedChange={() => toggleSelect(contact.id)}
          />
        )
      case "contact":
        return (
          <div className="flex items-center gap-3">
            <button
              type="button"
              onClick={() => openContactConversation(contact)}
              disabled={!contact.conversations?.[0]?.id}
              className="shrink-0 rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 disabled:cursor-default"
              title={contact.conversations?.[0]?.id ? "Abrir conversación" : "Sin conversación"}
            >
              <Avatar className="w-9 h-9">
                <AvatarFallback
                  className={`text-xs font-bold bg-gradient-to-br ${getAvatarGradient(contact.id)} text-white`}
                >
                  {getInitials(contact.name)}
                </AvatarFallback>
              </Avatar>
            </button>
            <div className="min-w-0 flex-1">
              <EditableCell
                type="text"
                value={contact.name}
                isEditing={editingCell?.contactId === contact.id && editingCell?.field === "name"}
                onEditStart={() => setEditingCell({ contactId: contact.id, field: "name" })}
                onCancel={() => setEditingCell(null)}
                onSave={(val) => handleCellSave(contact, "name", val)}
                renderDisplay={(val) => <p className="font-semibold text-sm truncate">{val}</p>}
                className="px-1"
              />
              {contact.conversations && contact.conversations.length > 0 ? (
                <button
                  type="button"
                  onClick={() => openContactConversation(contact)}
                  className="block max-w-[180px] truncate rounded px-1 text-left text-xs text-muted-foreground hover:bg-muted/60 hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                  title="Abrir conversación"
                >
                  {contact.conversations[0].last_message_content}
                </button>
              ) : null}
            </div>
          </div>
        )
      case "phone":
        return (
          <EditableCell
            type="text"
            value={contact.phone || ""}
            placeholder="+54 9 11 1234-5678"
            isEditing={editingCell?.contactId === contact.id && editingCell?.field === "phone"}
            onEditStart={() => setEditingCell({ contactId: contact.id, field: "phone" })}
            onCancel={() => setEditingCell(null)}
            onSave={(val) => handleCellSave(contact, "phone", val)}
            renderDisplay={(val) =>
              val ? (
                <div className="flex items-center gap-2 whitespace-nowrap">
                  <MessageSquare className="w-4 h-4 text-green-500 flex-shrink-0" />
                  <span className="text-sm font-mono tabular-nums">{val}</span>
                </div>
              ) : (
                <span className="text-muted-foreground text-sm">—</span>
              )
            }
          />
        )
      case "email":
        return (
          <EditableCell
            type="text"
            value={contact.email || ""}
            placeholder="email@dominio.com"
            isEditing={editingCell?.contactId === contact.id && editingCell?.field === "email"}
            onEditStart={() => setEditingCell({ contactId: contact.id, field: "email" })}
            onCancel={() => setEditingCell(null)}
            onSave={(val) => handleCellSave(contact, "email", val)}
            renderDisplay={(val) =>
              val ? <span className="text-sm text-muted-foreground">{val}</span> : <span className="text-muted-foreground">—</span>
            }
          />
        )
      case "source":
        return (
          <EditableCell
            type="select"
            value={contact.source}
            options={[
              { value: "manual", label: "Manual" },
              { value: "whatsapp", label: "WhatsApp" },
              { value: "instagram", label: "Instagram" },
              { value: "facebook", label: "Facebook" },
            ]}
            isEditing={editingCell?.contactId === contact.id && editingCell?.field === "source"}
            onEditStart={() => setEditingCell({ contactId: contact.id, field: "source" })}
            onCancel={() => setEditingCell(null)}
            onSave={(val) => handleCellSave(contact, "source", val)}
            renderDisplay={(val) => (
              <Badge
                className={sourceColors[val] || "bg-gray-500/10 text-gray-600 border-gray-200"}
                variant="outline"
              >
                {sourceLabels[val] || val}
              </Badge>
            )}
          />
        )
      case "tags":
        return (
          <div className="flex items-center gap-2">
            <TagChips tags={contact.tags} maxVisible={2} emptyLabel="Sin etiquetas" />
            <TagPicker
              target="contact"
              targetId={contact.id}
              value={contact.tags || []}
              onChange={(tags) => updateContactTags(contact.id, tags)}
              buttonLabel=""
              compact
            />
          </div>
        )
      case "lastContact":
        return <span className="text-sm text-muted-foreground tabular-nums">{getLastContact(contact)}</span>
      case "actions": {
        const telHref = contact.phone ? `tel:${contact.phone.replace(/[^\d+]/g, "")}` : undefined
        const mailHref = contact.email ? `mailto:${contact.email}` : undefined
        const conversationId = contact.conversations?.[0]?.id
        return (
          <div className="flex items-center justify-end gap-1">
            <Button
              asChild={!!telHref}
              variant="ghost"
              size="sm"
              disabled={!telHref}
              className="h-7 w-7 p-0 text-muted-foreground hover:text-foreground"
              title={contact.phone || "Sin teléfono"}
            >
              {telHref ? <a href={telHref}><Phone className="w-3.5 h-3.5" /></a> : <Phone className="w-3.5 h-3.5" />}
            </Button>
            <Button
              asChild={!!mailHref}
              variant="ghost"
              size="sm"
              disabled={!mailHref}
              className="h-7 w-7 p-0 text-muted-foreground hover:text-foreground"
              title={contact.email || "Sin email"}
            >
              {mailHref ? <a href={mailHref}><Mail className="w-3.5 h-3.5" /></a> : <Mail className="w-3.5 h-3.5" />}
            </Button>
            <Button
              variant="ghost"
              size="sm"
              disabled={!conversationId}
              onClick={() => conversationId && router.push(`/chats?chat=${conversationId}`)}
              className="h-7 w-7 p-0 text-muted-foreground hover:text-foreground"
              title={conversationId ? "Abrir conversación" : "Sin conversación"}
            >
              <MessageSquare className="w-3.5 h-3.5" />
            </Button>
            <DropdownMenu modal={false}>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="sm" className="h-7 w-7 p-0 text-muted-foreground hover:text-foreground">
                  <MoreVertical className="w-3.5 h-3.5" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onSelect={() => openProfileSheet(contact)}>Ver perfil</DropdownMenuItem>
                <DropdownMenuItem onClick={() => openEditDialog(contact)}>Editar</DropdownMenuItem>
                <DropdownMenuItem onSelect={() => setExtractionContact(contact)}>
                  Extraer datos de un PDF
                </DropdownMenuItem>
                <DropdownMenuItem>Crear tarea</DropdownMenuItem>
                <DropdownMenuItem
                  disabled={addingToPipelineId === contact.id}
                  onSelect={() => handleAddToPipeline(contact)}
                >
                  {addingToPipelineId === contact.id ? "Agregando..." : "Agregar a pipeline"}
                </DropdownMenuItem>
                <DropdownMenuItem
                  className="text-destructive focus:text-destructive"
                  onClick={() => setDeleteContact(contact)}
                >
                  Eliminar
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        )
      }
      default: {
        if (typeof columnId === "string" && columnId.startsWith("custom:")) {
          const key = columnId.slice("custom:".length)
          const field = contactFields.find((f) => f.key === key)
          const raw = (contact.custom_data ?? {})[key]

          if (!field) {
            return (
              <span className="truncate text-sm text-foreground">
                {formatCustomValue(raw, undefined)}
              </span>
            )
          }

          const isEditingThis = editingCell?.contactId === contact.id && editingCell.field === columnId

          if (isEditingThis) {
            // Tipos que despliegan portales (Select/Radix) o múltiples controles no
            // deben cerrarse en blur, porque interactuar con el portal dispara blur.
            const usesPortal = field.type === "select" || field.type === "multi_select"
            return (
              <div
                onBlur={(e) => {
                  if (usesPortal) return
                  if (!e.currentTarget.contains(e.relatedTarget as Node)) {
                    setEditingCell(null)
                  }
                }}
                onKeyDown={(e) => {
                  // En multi_select, Enter alterna opciones dentro del picker; cerrar
                  // la edición lo maneja onPickerClose al cerrar el desplegable.
                  if (e.key === "Enter" && field.type === "multi_select") return
                  if (e.key === "Escape" || e.key === "Enter") setEditingCell(null)
                }}
              >
                <CustomFieldInput
                  field={{ ...field, label: "" }}
                  value={raw}
                  autoOpen
                  onPickerClose={() => setEditingCell(null)}
                  onChange={(next) => {
                    handleCustomCellSave(contact, key, next)
                    // Single-value pickers terminan la edición tras elegir.
                    if (field.type === "select" || field.type === "boolean") {
                      setEditingCell(null)
                    }
                  }}
                />
              </div>
            )
          }

          return (
            <button
              type="button"
              className="w-full text-left rounded px-1 py-0.5 hover:bg-muted/60 transition-colors truncate text-sm text-foreground"
              onClick={() => setEditingCell({ contactId: contact.id, field: columnId })}
            >
              {formatCustomValue(raw, field.type)}
            </button>
          )
        }
        return null
      }
    }
  }

  const allSelected = filteredContacts.length > 0 && filteredContacts.every((c) => selectedIds.has(c.id))

  const handleSelectAll = () => {
    if (allSelected) {
      setSelectedIds(new Set())
    } else {
      setSelectedIds(new Set(filteredContacts.map((c) => c.id)))
    }
  }

  const toggleSelect = (id: number) => {
    const next = new Set(selectedIds)
    if (next.has(id)) next.delete(id)
    else next.add(id)
    setSelectedIds(next)
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 rounded-lg border bg-card px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-sm text-muted-foreground">
          {paginationMeta.total > 0 ? (
            <>
              Mostrando{" "}
              <span className="font-medium text-foreground">{paginationMeta.from}</span>
              {"-"}
              <span className="font-medium text-foreground">{paginationMeta.to}</span>
              {" de "}
              <span className="font-medium text-foreground">{paginationMeta.total}</span>
              {" contactos"}
            </>
          ) : (
            "Sin contactos para mostrar"
          )}
        </p>
        <div className="flex items-center gap-2">
          <span className="text-sm text-muted-foreground">Por página</span>
          <Select value={String(perPage)} onValueChange={(value) => setPerPage(Number(value))}>
            <SelectTrigger className="h-8 w-24">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="10">10</SelectItem>
              <SelectItem value="20">20</SelectItem>
              <SelectItem value="50">50</SelectItem>
              <SelectItem value="100">100</SelectItem>
            </SelectContent>
          </Select>
          <Button
            variant="outline"
            size="sm"
            disabled={loading || paginationMeta.current_page <= 1}
            onClick={() => setPage((current) => Math.max(current - 1, 1))}
          >
            Anterior
          </Button>
          <span className="min-w-20 text-center text-sm text-muted-foreground">
            {paginationMeta.current_page} / {paginationMeta.last_page}
          </span>
          <Button
            variant="outline"
            size="sm"
            disabled={loading || paginationMeta.current_page >= paginationMeta.last_page}
            onClick={() => setPage((current) => Math.min(current + 1, paginationMeta.last_page))}
          >
            Siguiente
          </Button>
        </div>
      </div>

      {/* Bulk selection bar */}
      {selectedIds.size > 0 && (
        <div className="flex items-center justify-between px-4 py-3 bg-primary/10 border border-primary/20 rounded-lg">
          <Badge variant="secondary" className="text-sm font-semibold">
            {selectedIds.size} contacto{selectedIds.size !== 1 ? "s" : ""} seleccionado{selectedIds.size !== 1 ? "s" : ""}
          </Badge>
          <div className="flex items-center gap-2">
            <Button size="sm" onClick={() => setBulkTagsOpen(true)}>
              <Tags className="w-3 h-3 mr-1" />
              {t("contactsPage.bulk.editTags")}
            </Button>
            <Button variant="outline" size="sm" onClick={() => setSelectedIds(new Set())}>
              <X className="w-3 h-3 mr-1" />
              Cancelar
            </Button>
          </div>
        </div>
      )}

      {extractionContact && (
        <ExtractDocumentDialog
          open
          onOpenChange={(open) => {
            if (!open) setExtractionContact(null)
          }}
          contactId={extractionContact.id}
          currentCustomData={(extractionContact.custom_data as Record<string, unknown>) ?? {}}
          onConfirmed={(customData) => {
            // El backend devuelve el custom_data ya mergeado y validado: se usa
            // ese en vez de recomponerlo acá.
            setContacts((prev) =>
              prev.map((c) => (c.id === extractionContact.id ? { ...c, custom_data: customData } : c)),
            )
            setExtractionContact((prev) => (prev ? { ...prev, custom_data: customData } : prev))
          }}
        />
      )}

      <BulkTagsDialog
        open={bulkTagsOpen}
        onOpenChange={setBulkTagsOpen}
        selectedIds={Array.from(selectedIds)}
        onSuccess={() => {
          setSelectedIds(new Set())
          fetchContacts()
        }}
      />


      {isInitialLoading ? (
        <div className="flex items-center justify-center py-16">
          <Loader2 className="w-8 h-8 animate-spin text-muted-foreground" />
        </div>
      ) : error ? (
        <div className="text-center py-12">
          <p className="text-destructive mb-4">{error}</p>
          <Button onClick={fetchContacts} variant="outline">Reintentar</Button>
        </div>
      ) : (
        <>
          <div className="space-y-3 md:hidden">
            {filteredContacts.map((contact) => {
              const conversation = contact.conversations?.[0]
              const conversationId = conversation?.id
              const telHref = contact.phone ? `tel:${contact.phone.replace(/[^\d+]/g, "")}` : undefined
              const mailHref = contact.email ? `mailto:${contact.email}` : undefined

              return (
                <div key={contact.id} className="rounded-lg border bg-card p-4 shadow-sm">
                  <div className="flex items-start gap-3">
                    <button
                      type="button"
                      onClick={() => openContactConversation(contact)}
                      disabled={!conversationId}
                      className="shrink-0 rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 disabled:cursor-default"
                      title={conversationId ? "Abrir conversación" : "Sin conversación"}
                    >
                      <Avatar className="h-11 w-11">
                        <AvatarFallback className={`font-bold bg-gradient-to-br ${getAvatarGradient(contact.id)} text-white`}>
                          {getInitials(contact.name)}
                        </AvatarFallback>
                      </Avatar>
                    </button>
                    <div className="min-w-0 flex-1">
                      <p className="truncate font-semibold text-foreground">{contact.name}</p>
                      <p className="mt-1 truncate text-xs text-muted-foreground">
                        {conversation?.last_message_content || "Sin conversación"}
                      </p>
                    </div>
                    <Checkbox
                      checked={selectedIds.has(contact.id)}
                      onCheckedChange={() => toggleSelect(contact.id)}
                      aria-label={`Seleccionar ${contact.name}`}
                    />
                  </div>

                  <div className="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div className="min-w-0">
                      <p className="text-xs text-muted-foreground">Teléfono</p>
                      <p className="truncate">{contact.phone || "—"}</p>
                    </div>
                    <div className="min-w-0">
                      <p className="text-xs text-muted-foreground">Email</p>
                      <p className="truncate">{contact.email || "—"}</p>
                    </div>
                  </div>

                  <div className="mt-4 flex items-center justify-between gap-3">
                    <div className="flex min-w-0 items-center gap-2">
                      <Badge className={sourceColors[contact.source] || "bg-gray-500/10 text-gray-600 border-gray-200"} variant="outline">
                        {sourceLabels[contact.source] || contact.source}
                      </Badge>
                      <span className="truncate text-xs text-muted-foreground">{getLastContact(contact)}</span>
                    </div>
                    <Button
                      variant={conversationId ? "default" : "outline"}
                      size="sm"
                      disabled={!conversationId}
                      onClick={() => openContactConversation(contact)}
                      className="min-h-10 shrink-0 gap-2"
                    >
                      <MessageSquare className="h-4 w-4" />
                      {conversationId ? "Abrir chat" : "Sin chat"}
                    </Button>
                  </div>

                  <div className="mt-3 flex items-center justify-end gap-1 border-t pt-3">
                    <Button asChild={!!telHref} variant="ghost" size="sm" disabled={!telHref} className="h-10 w-10 p-0">
                      {telHref ? <a href={telHref} aria-label={`Llamar a ${contact.name}`}><Phone className="h-4 w-4" /></a> : <Phone className="h-4 w-4" />}
                    </Button>
                    <Button asChild={!!mailHref} variant="ghost" size="sm" disabled={!mailHref} className="h-10 w-10 p-0">
                      {mailHref ? <a href={mailHref} aria-label={`Enviar email a ${contact.name}`}><Mail className="h-4 w-4" /></a> : <Mail className="h-4 w-4" />}
                    </Button>
                    <Button variant="ghost" size="sm" className="h-10 px-3" onClick={() => openProfileSheet(contact)}>
                      Ver perfil
                    </Button>
                  </div>
                </div>
              )
            })}
          </div>

          <div className="hidden border rounded-lg overflow-hidden md:block">
            <div className="overflow-x-auto">
              <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleColumnDragEnd}>
                <Table className="min-w-[1100px]">
                  <TableHeader className="sticky top-0 z-10 bg-background border-b">
                    <TableRow>
                      <SortableContext items={columns.map((c) => c.id)} strategy={horizontalListSortingStrategy}>
                        {columns.map((column) => {
                          if (column.id === "select") {
                            return (
                              <TableHead key={column.id} style={{ width: column.width, minWidth: column.minWidth }} className="px-4">
                                <Checkbox checked={allSelected} onCheckedChange={handleSelectAll} />
                              </TableHead>
                            )
                          }
                          const isCustom = typeof column.id === "string" && column.id.startsWith("custom:")
                          return (
                            <SortableHeader
                              key={column.id}
                              column={column}
                              label={
                                column.id === "tags"
                                  ? "Etiquetas"
                                  : isCustom
                                    ? capitalize(column.labelKey)
                                    : column.labelKey
                                      ? t(column.labelKey)
                                      : ""
                              }
                              sortField={sortField}
                              sortDirection={sortDirection}
                              onSort={handleSort}
                            />
                          )
                        })}
                      </SortableContext>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {loading ? (
                      <TableRow>
                        <TableCell colSpan={columns.length} className="h-40">
                          <div className="flex items-center justify-center gap-2 text-sm text-muted-foreground">
                            <Loader2 className="h-5 w-5 animate-spin" />
                            Cargando contactos
                          </div>
                        </TableCell>
                      </TableRow>
                    ) : (
                      filteredContacts.map((contact, idx) => (
                        <TableRow
                          key={contact.id}
                          className={`${idx % 2 === 0 ? "bg-muted/30" : ""} hover:bg-muted/50 transition-colors`}
                        >
                          {columns.map((column) => (
                            <TableCell
                              key={column.id}
                              className={`py-3 ${column.id === "select" ? "px-4" : ""} ${column.id === "actions" ? "text-right" : ""}`}
                              style={{ width: column.width, minWidth: column.minWidth, maxWidth: column.width }}
                            >
                              {renderCell(contact, column.id)}
                            </TableCell>
                          ))}
                        </TableRow>
                      ))
                    )}
                  </TableBody>
                </Table>
              </DndContext>
            </div>
          </div>

          {!loading && filteredContacts.length === 0 && (
            <div className="text-center py-12">
              <Users className="w-12 h-12 text-muted-foreground mx-auto mb-4" />
              <h3 className="text-lg font-medium text-foreground mb-2">No se encontraron contactos</h3>
              <p className="text-muted-foreground">
                {searchTerm ? "Intenta con otros términos de búsqueda" : "Comienza agregando tu primer contacto"}
              </p>
            </div>
          )}
        </>
      )}

      {/* Dialog Crear/Editar */}
      <Dialog open={dialogOpen} onOpenChange={(open) => {
        setDialogOpen(open)
        if (!open) {
          setEditingContact(null)
          setFormData({ ...DEFAULT_FORM })
          setFormErrors({})
        }
      }}>
        <DialogContent className="max-h-[85vh] flex flex-col">
          <DialogHeader>
            <DialogTitle>{editingContact ? "Editar Contacto" : "Nuevo Contacto"}</DialogTitle>
            <DialogDescription>
              {editingContact ? "Modifica los datos del contacto" : "Agrega un nuevo contacto a tu base de datos"}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-2 flex-1 overflow-y-auto min-h-0 pr-1">
            {formErrors._general && (
              <p className="text-sm text-destructive">{formErrors._general}</p>
            )}
            <div className="space-y-2">
              <Label htmlFor="name">Nombre *</Label>
              <Input
                id="name"
                placeholder="Nombre completo"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              />
              {formErrors.name && <p className="text-xs text-destructive">{formErrors.name}</p>}
            </div>
            <div className="space-y-2">
              <Label htmlFor="phone">Teléfono</Label>
              <Input
                id="phone"
                placeholder="+54 9 11 1234-5678"
                value={formData.phone}
                onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
              />
              {formErrors.phone && <p className="text-xs text-destructive">{formErrors.phone}</p>}
            </div>
            <div className="space-y-2">
              <Label htmlFor="email">Email</Label>
              <Input
                id="email"
                type="email"
                placeholder="contacto@email.com"
                value={formData.email}
                onChange={(e) => setFormData({ ...formData, email: e.target.value })}
              />
              {formErrors.email && <p className="text-xs text-destructive">{formErrors.email}</p>}
            </div>
            <div className="space-y-2">
              <Label htmlFor="source">Fuente</Label>
              <Select value={formData.source} onValueChange={(value) => setFormData({ ...formData, source: value })}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="manual">Manual</SelectItem>
                  <SelectItem value="whatsapp">WhatsApp</SelectItem>
                  <SelectItem value="instagram">Instagram</SelectItem>
                  <SelectItem value="facebook">Facebook</SelectItem>
                </SelectContent>
              </Select>
            </div>
            {contactFields.map((field) => {
              const errKey = `custom_data.${field.key}`
              return (
                <div key={field.id} className="space-y-1">
                  <CustomFieldInput
                    field={field}
                    value={formData.custom_data[field.key]}
                    onChange={(next) =>
                      setFormData((prev) => ({
                        ...prev,
                        custom_data: { ...prev.custom_data, [field.key]: next },
                      }))
                    }
                  />
                  {formErrors[errKey] ? (
                    <p className="text-xs text-destructive">{formErrors[errKey]}</p>
                  ) : null}
                </div>
              )
            })}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDialogOpen(false)} disabled={saving}>Cancelar</Button>
            <Button onClick={handleSaveContact} disabled={saving}>
              {saving && <Loader2 className="w-4 h-4 mr-2 animate-spin" />}
              {editingContact ? "Guardar Cambios" : "Crear Contacto"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Sheet Ver Perfil */}
      <Sheet open={profileOpen} onOpenChange={handleProfileOpenChange}>
        <SheetContent>
          <SheetHeader>
            <SheetTitle>Perfil del Contacto</SheetTitle>
          </SheetHeader>
          {profileContact && (
            <div className="mt-6 space-y-6">
              <div className="flex items-center gap-4">
                <Avatar className="w-16 h-16">
                  <AvatarFallback
                    className={`text-xl font-bold bg-gradient-to-br ${getAvatarGradient(profileContact.id)} text-white`}
                  >
                    {getInitials(profileContact.name)}
                  </AvatarFallback>
                </Avatar>
                <div>
                  <h3 className="text-lg font-semibold">{profileContact.name}</h3>
                  <Badge className={sourceColors[profileContact.source] || "bg-gray-500/10 text-gray-600"} variant="outline">
                    {sourceLabels[profileContact.source] || profileContact.source}
                  </Badge>
                </div>
              </div>
              <Separator />
              <div className="space-y-4">
                <div className="flex items-center gap-3">
                  <Phone className="w-4 h-4 text-muted-foreground" />
                  <div>
                    <p className="text-xs text-muted-foreground">Teléfono</p>
                    <p className="text-sm">{profileContact.phone || "No registrado"}</p>
                  </div>
                </div>
                <div className="flex items-center gap-3">
                  <Mail className="w-4 h-4 text-muted-foreground" />
                  <div>
                    <p className="text-xs text-muted-foreground">Email</p>
                    <p className="text-sm">{profileContact.email || "No registrado"}</p>
                  </div>
                </div>
                <div className="flex items-center gap-3">
                  <Hash className="w-4 h-4 text-muted-foreground" />
                  <div>
                    <p className="text-xs text-muted-foreground">ID</p>
                    <p className="text-sm">{profileContact.id}</p>
                  </div>
                </div>
                <div className="space-y-2 rounded-lg border bg-muted/20 p-3">
                  <p className="text-xs text-muted-foreground">Etiquetas</p>
                  <TagPicker
                    target="contact"
                    targetId={profileContact.id}
                    value={profileContact.tags || []}
                    onChange={(tags) => updateContactTags(profileContact.id, tags)}
                    buttonLabel="Gestionar etiquetas"
                  />
                </div>
                <div className="flex items-center gap-3">
                  <Calendar className="w-4 h-4 text-muted-foreground" />
                  <div>
                    <p className="text-xs text-muted-foreground">Creado</p>
                    <p className="text-sm">{format(new Date(profileContact.created_at), "dd/MM/yyyy HH:mm", { locale: es })}</p>
                  </div>
                </div>
                <div className="flex items-center gap-3">
                  <Calendar className="w-4 h-4 text-muted-foreground" />
                  <div>
                    <p className="text-xs text-muted-foreground">Última actualización</p>
                    <p className="text-sm">{format(new Date(profileContact.updated_at), "dd/MM/yyyy HH:mm", { locale: es })}</p>
                  </div>
                </div>
              </div>
              {profileContact.conversations && profileContact.conversations.length > 0 && (
                <>
                  <Separator />
                  <div>
                    <h4 className="text-sm font-medium mb-3">Última conversación</h4>
                    <div className="rounded-lg border p-3">
                      <p className="text-xs text-muted-foreground mb-1">
                        {format(new Date(profileContact.conversations[0].last_message_at), "dd/MM/yyyy HH:mm", { locale: es })}
                      </p>
                      <p className="text-sm">{profileContact.conversations[0].last_message_content}</p>
                    </div>
                  </div>
                </>
              )}
              <Separator />
              <div className="flex gap-2">
                <Button variant="outline" size="sm" className="flex-1" onClick={() => {
                  setProfileOpen(false)
                  setTimeout(() => openEditDialog(profileContact), 300)
                }}>Editar</Button>
                <Button variant="destructive" size="sm" className="flex-1" onClick={() => {
                  setProfileOpen(false)
                  setTimeout(() => setDeleteContact(profileContact), 300)
                }}>Eliminar</Button>
              </div>
            </div>
          )}
        </SheetContent>
      </Sheet>

      {/* Dialog Importar CSV */}
      <ImportContactsDialog
        open={importOpen}
        onOpenChange={setImportOpen}
        onImportComplete={() => fetchContacts()}
      />

      {/* AlertDialog Eliminar */}
      <AlertDialog open={!!deleteContact} onOpenChange={(open) => { if (!open) setDeleteContact(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Eliminar contacto</AlertDialogTitle>
            <AlertDialogDescription>
              ¿Estás seguro de que querés eliminar a{" "}
              <span className="font-medium text-foreground">{deleteContact?.name}</span>? Esta acción no se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deleting}>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDeleteContact}
              disabled={deleting}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {deleting && <Loader2 className="w-4 h-4 mr-2 animate-spin" />}
              Eliminar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
