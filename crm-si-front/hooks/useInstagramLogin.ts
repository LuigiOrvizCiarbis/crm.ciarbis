"use client";

import { useState, useEffect, useRef, useCallback } from "react";
import { ChannelError, ChannelErrorDetail } from "@/lib/channel-error";

export interface InstagramPageOption {
  page_id: string;
  name: string;
  username: string | null;
}

/**
 * Conexión de Instagram vía Facebook Login (Messenger Platform), usando el
 * flujo OAuth documentado con popup + redirect explícito.
 *
 * NO usa FB.login del SDK JS a propósito: el code que genera el SDK queda
 * atado a un redirect_uri interno no documentado y el canje server-side falla
 * con el error 36008 ("redirect_uri is not identical"). Con el diálogo OAuth
 * clásico controlamos el redirect_uri nosotros: el mismo valor exacto va en el
 * diálogo y en el canje, así que el matching es determinístico.
 *
 * Flujo:
 *  1. Popup a https://www.facebook.com/{v}/dialog/oauth con redirect_uri =
 *     {origin}/instagram/callback y response_type=code.
 *  2. Meta redirige el popup a /instagram/callback?code=... — esa página nos
 *     manda el code por postMessage y se cierra.
 *  3. POST /api/instagram-auth con {code, redirect_uri}. El backend canjea el
 *     code usando ese mismo redirect_uri.
 *  4. Si el usuario tiene varias páginas con IG vinculado, el backend devuelve
 *     {pages, onboarding_token} y mostramos el selector (segunda vuelta).
 *
 * Requisito de dashboard: {origin}/instagram/callback debe estar en los
 * "URI de redireccionamiento de OAuth válidos" de Facebook Login for Business.
 */
export const useInstagramLogin = () => {
  // Lista de páginas pendiente de elección (cuando hay más de una).
  const [pageOptions, setPageOptions] = useState<InstagramPageOption[] | null>(null);
  const [onboardingToken, setOnboardingToken] = useState<string | null>(null);
  // state anti-CSRF del OAuth: solo aceptamos el callback que iniciamos.
  const stateRef = useRef<string | null>(null);

  const getAuthToken = (): string | null => {
    const authStorage = localStorage.getItem("auth-storage");
    return authStorage ? JSON.parse(authStorage)?.state?.token : null;
  };

  const redirectUri = useCallback(() => {
    return `${window.location.origin}/instagram/callback`;
  }, []);

  const postToBackend = useCallback(async (body: Record<string, unknown>) => {
    const token = getAuthToken();
    if (!token) {
      window.dispatchEvent(new CustomEvent("channel-error", {
        detail: { code: "channelErrorSessionExpired" } satisfies ChannelErrorDetail,
      }));
      return;
    }

    try {
      let data: any;
      try {
        const response = await fetch(`/api/instagram-auth`, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Authorization: `Bearer ${token}`,
          },
          body: JSON.stringify(body),
        });

        data = await response.json();
      } catch {
        // Error de red o respuesta no-JSON: el front muestra un mensaje traducido.
        throw new ChannelError({ code: "channelErrorNetwork" });
      }

      if (data.success) {
        setPageOptions(null);
        setOnboardingToken(null);
        window.dispatchEvent(new CustomEvent("channel-connected"));
        return;
      }

      // Varias páginas: pedir al usuario que elija una.
      if (data.requires_page_selection && Array.isArray(data.pages)) {
        setOnboardingToken(data.onboarding_token ?? null);
        setPageOptions(data.pages);
        return;
      }

      // Los proxies mandan `code` (el front lo traduce); Laravel manda `message`
      // (texto ya redactado para el usuario).
      if (data.code) throw new ChannelError({ code: data.code });
      if (data.message) throw new ChannelError({ message: data.message });
      throw new ChannelError({ code: "channelErrorInstagram" });
    } catch (error) {
      console.error("Error conectando Instagram:", error);
      const detail: ChannelErrorDetail =
        error instanceof ChannelError ? error.detail : { code: "channelErrorInstagram" };
      window.dispatchEvent(new CustomEvent("channel-error", { detail }));
    }
  }, []);

  // Escuchar el postMessage de la página de callback del popup.
  useEffect(() => {
    const onMessage = (event: MessageEvent) => {
      if (event.origin !== window.location.origin) return;
      const data = event.data;
      if (!data || data.type !== "instagram-auth") return;

      // Ignorar callbacks que no correspondan al login que iniciamos.
      if (!stateRef.current || data.state !== stateRef.current) return;
      stateRef.current = null;

      if (data.code) {
        window.dispatchEvent(new CustomEvent("channel-connecting"));
        postToBackend({ code: data.code, redirect_uri: redirectUri() });
      } else {
        console.warn("Instagram login cancelado o fallido:", data.error);
      }
    };

    window.addEventListener("message", onMessage);
    return () => window.removeEventListener("message", onMessage);
  }, [postToBackend, redirectUri]);

  const launchInstagramLogin = useCallback(() => {
    const appId = process.env.NEXT_PUBLIC_FACEBOOK_APP_ID;
    if (!appId) {
      console.warn("Instagram: falta NEXT_PUBLIC_FACEBOOK_APP_ID.");
      return;
    }

    const version = process.env.NEXT_PUBLIC_FACEBOOK_GRAPH_API_VERSION || "v21.0";

    // Permiso exacto de IG messaging: mantener sincronizado con el dashboard de
    // Meta (App Review). Configurable por env para no re-desplegar si cambia.
    const messagingPermission =
      process.env.NEXT_PUBLIC_INSTAGRAM_MESSAGING_PERMISSION ||
      "instagram_manage_messages";

    const scope = [
      "pages_show_list",
      "pages_manage_metadata",
      "pages_read_engagement",
      "pages_messaging",
      "instagram_basic",
      "instagram_manage_comments",
      messagingPermission,
      "business_management",
    ].join(",");

    const state = crypto.randomUUID();
    stateRef.current = state;

    const params = new URLSearchParams({
      client_id: appId,
      redirect_uri: redirectUri(),
      response_type: "code",
      scope,
      state,
      display: "popup",
    });

    window.open(
      `https://www.facebook.com/${version}/dialog/oauth?${params.toString()}`,
      "instagram-login",
      "width=620,height=720,menubar=no,toolbar=no",
    );
  }, [redirectUri]);

  // Segunda vuelta: el usuario eligió una página.
  const selectPage = useCallback(
    (pageId: string) => {
      if (!onboardingToken) return;
      window.dispatchEvent(new CustomEvent("channel-connecting"));
      postToBackend({ onboarding_token: onboardingToken, page_id: pageId });
    },
    [onboardingToken, postToBackend]
  );

  const cancelPageSelection = useCallback(() => {
    setPageOptions(null);
    setOnboardingToken(null);
  }, []);

  return {
    launchInstagramLogin,
    // Este flujo no depende del SDK de Facebook: siempre está listo.
    isFacebookSDKLoaded: true,
    pageOptions,
    selectPage,
    cancelPageSelection,
  };
};
