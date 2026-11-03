import { getAuthToken } from "./auth-token";

export type DashboardPeriodo =
  | "hoy"
  | "esta-semana"
  | "este-mes"
  | "este-trimestre"
  | "este-ano";

export interface DashboardStage {
  id: number;
  name: string;
  sort_order: number;
  count: number;
  value: number;
  /** Oportunidades con status open. */
  open_count: number;
  open_value: number;
  /** Abiertas sin actividad (o edición) en los últimos `stale_after_days` días. */
  stale_count: number;
  stale_value: number;
  pipeline_id: number;
  pipeline_name: string;
}

export interface DashboardKpis {
  total_opportunities: number;
  pipeline_value: number;
  weighted_value: number;
  avg_value: number;
  total_conversations: number;
  total_contacts: number;
  /** Ganadas con fecha de cierre dentro del período. */
  won_count: number;
  won_value: number;
  global_conversion_rate: number;
  /** Valor ganado / cantidad ganada; 0 sin ventas. */
  avg_ticket: number;
}

export interface DashboardOmnichannel {
  channel_id: number | null;
  channel_name: string | null;
  channel_type: number | string | null;
  conversations_count: number;
}

export interface DashboardDailyEntry {
  date: string;
  [stageKey: string]: number | string;
}

export interface DashboardRevenueChannel {
  channel_id: number | null;
  channel_name: string | null;
  channel_type: number | string | null;
  won_value: number;
  won_count: number;
  lost_count: number;
}

export interface DashboardCollections {
  currency: string;
  /** Hay facturas en otras monedas, que quedan afuera de los números. */
  other_currencies: boolean;
  collected_cents: number;
  outstanding_cents: number;
  overdue_cents: number;
  overdue_count: number;
}

export interface DashboardRevenue {
  won_value: number;
  won_count: number;
  /** Abiertas × probabilidad de su etapa, foto de hoy. */
  open_weighted_value: number;
  /** null cuando ninguna etapa con oportunidades abiertas tiene probabilidad. */
  projected_value: number | null;
  stages_without_probability: number;
  /** Meta mensual × meses del período; null en hoy/semana o sin meta. */
  goal: number | null;
  by_channel: DashboardRevenueChannel[];
  /** null sin permiso de facturas o sin facturación. */
  collections: DashboardCollections | null;
}

/** Valores redondeados a 1 decimal; `avg_*` es null cuando no hubo muestras en el período. */
export interface DashboardEfficiency {
  response_time: { avg_minutes: number | null; previous_avg_minutes: number | null; samples: number; goal_minutes: number | null };
  cycle_time: { avg_days: number | null; previous_avg_days: number | null; samples: number; goal_days: number | null };
  tasks: { completed: number; total: number; previous_completed: number; previous_total: number };
}

export interface DashboardGoals {
  response_time_minutes: number | null;
  cycle_time_days: number | null;
  /** Meta de ingresos por mes; el Panel la multiplica en trimestre y año. */
  revenue_monthly: number | null;
}

/** Conversaciones en las que respondió la IA, según cómo terminaron. */
export interface DashboardAiOutcomes {
  total: number;
  /** Ningún vendedor intervino; no prueba que se haya resuelto. */
  ai_only: number;
  with_human: number;
  handed_off: number;
}

export interface DashboardAiImpact {
  ai_enabled: boolean;
  automations_active: boolean;
  conversations: DashboardAiOutcomes;
  previous_conversations: DashboardAiOutcomes;
  response_time: { ai_avg_seconds: number | null; human_avg_seconds: number | null; ai_samples: number };
  handoffs: {
    total: number;
    avg_minutes_to_attend: number | null;
    /** Derivaciones sin responder hoy, de cualquier fecha. */
    waiting_count: number;
    waiting: Array<{
      id: number;
      conversation_id: number;
      contact_name: string | null;
      /** null cuando el cliente sólo pidió hablar con una persona. */
      motive: string | null;
      created_at: string;
    }>;
  };
  automations: {
    /** Las ejecuciones se borran pasado este plazo: el período se acotó a esos días. */
    limited_to_days: number | null;
    succeeded: number;
    /** Fallidas y para revisar. */
    failed: number;
    failing_rules: Array<{ id: number; name: string; failures: number; last_failed_at: string }>;
  };
}

export interface DashboardMetrics {
  /** Embudo filtrado; null cuando se pidieron todos. */
  pipeline: { id: number; name: string; is_default: boolean } | null;
  stages: DashboardStage[];
  stale_after_days: number;
  kpis: DashboardKpis;
  previous: DashboardKpis;
  trends: Record<keyof DashboardKpis, number>;
  daily_series: DashboardDailyEntry[];
  omnichannel: DashboardOmnichannel[];
  efficiency: DashboardEfficiency;
  /** null para quien sólo ve sus propios números. */
  ai: DashboardAiImpact | null;
  revenue: DashboardRevenue;
  range: {
    start: string;
    end: string;
    periodo: DashboardPeriodo;
  };
}

export interface DashboardFilters {
  periodo?: DashboardPeriodo;
  canal?: string | number | null;
  owner?: string | number | null;
  /** Id del embudo, "default" (el principal) o "todos". */
  pipeline?: string | number | null;
  /** Id de la sucursal; sin valor, todas las que el usuario puede ver. */
  sucursal?: string | number | null;
}

export class DashboardApiError extends Error {
  constructor(message: string, public status: number) {
    super(message);
    this.name = "DashboardApiError";
  }
}

/** Mismos parámetros para métricas y comparativa por sucursal: las dos siguen el encabezado del Panel. */
function dashboardQuery(filters: DashboardFilters): string {
  const params = new URLSearchParams();
  if (filters.periodo) params.set("periodo", filters.periodo);
  for (const key of ["canal", "owner", "pipeline", "sucursal"] as const) {
    const value = filters[key];
    if (value !== undefined && value !== null && value !== "") params.set(key, String(value));
  }
  const qs = params.toString();
  return qs ? `?${qs}` : "";
}

export async function getDashboardMetrics(
  filters: DashboardFilters = {}
): Promise<DashboardMetrics> {
  const token = getAuthToken();

  if (!token) {
    throw new DashboardApiError("No auth token", 401);
  }

  const url = `/api/dashboard/metrics${dashboardQuery(filters)}`;

  const res = await fetch(url, {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
    },
    cache: "no-store",
  });

  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    throw new DashboardApiError(
      body?.error || body?.message || `Dashboard request failed (${res.status})`,
      res.status
    );
  }

  return (await res.json()) as DashboardMetrics;
}

/** Una fila por sucursal, con los mismos criterios que los KPIs del Panel. */
export interface BranchComparisonRow {
  id: number;
  name: string;
  is_active: boolean;
  contacts_new: number;
  conversations: number;
  opportunities_count: number;
  opportunities_value: number;
  opportunities_won: number;
}

export async function getBranchComparison(filters: DashboardFilters): Promise<BranchComparisonRow[]> {
  const token = getAuthToken();
  if (!token) throw new DashboardApiError("No auth token", 401);

  const res = await fetch(`/api/dashboard/branches${dashboardQuery(filters)}`, {
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
    cache: "no-store",
  });

  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    throw new DashboardApiError(body?.message || `Branch comparison failed (${res.status})`, res.status);
  }

  return ((await res.json()).data ?? []) as BranchComparisonRow[];
}

async function goalsRequest(init?: RequestInit): Promise<DashboardGoals> {
  const token = getAuthToken();
  if (!token) throw new DashboardApiError("No auth token", 401);

  const res = await fetch("/api/dashboard/goals", {
    ...init,
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json", "Content-Type": "application/json" },
    cache: "no-store",
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new DashboardApiError(body?.message || body?.error || `Goals request failed (${res.status})`, res.status);
  }
  return body.data as DashboardGoals;
}

export function getDashboardGoals(): Promise<DashboardGoals> {
  return goalsRequest();
}

export function updateDashboardGoals(goals: DashboardGoals): Promise<DashboardGoals> {
  return goalsRequest({ method: "PUT", body: JSON.stringify(goals) });
}
