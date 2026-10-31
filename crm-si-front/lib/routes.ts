export const publicRoutes = [
  "/",
  "/login",
  "/register",
  "/forgot-password",
  "/reset-password",
  "/pricing",
  "/email-verified",
  "/verify-email/confirm",
  "/privacy-policy",
  "/terms",
  "/data-deletion",
  "/invitation",
]

export const authOnlyRoutes = ["/login", "/register", "/forgot-password", "/reset-password"]

export const unverifiedAllowedRoutes = [
  "/verify-email",
  "/email-verified",
  "/pricing",
  "/verify-email/confirm",
]

// /perfil queda permitido porque el backend exime password y sessions del
// bloqueo de trial vencido (EnsureTrialNotExpired) — cerrar sesiones ajenas o
// cambiar la contraseña son acciones de seguridad que un usuario con trial
// vencido debe poder seguir haciendo. El resto de /perfil (nombre, avatar,
// preferencias) sigue bloqueado por el backend con 402; cada bloque de la
// página maneja ese error por su cuenta.
export const trialExpiredAllowedRoutes = ["/trial-expired", "/pricing", "/perfil"]

export const routesWithoutAppShell = [
  ...publicRoutes,
  "/verify-email",
  "/trial-expired",
]

export function isRouteMatch(pathname: string, routes: string[]) {
  return routes.some((route) => (route === "/" ? pathname === "/" : pathname.startsWith(route)))
}
