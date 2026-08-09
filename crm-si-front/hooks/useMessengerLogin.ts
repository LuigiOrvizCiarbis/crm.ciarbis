"use client";

import { useState, useEffect, useRef, useCallback } from "react";
import { ChannelError, ChannelErrorDetail } from "@/lib/channel-error";

export interface MessengerPageOption {
  page_id: string;
  name: string;
}

/** Identifica a este canal dentro del `state` del OAuth y del postMessage. */
const PROVIDER = "messenger";

/**
 * Conexión de Facebook Messenger vía Facebook Login, con popup + redirect
 * explícito (mismo enfoque que useInstagramLogin).
 *
 * NO usa FB.login del SDK JS a propósito: el code que genera el SDK queda atado
 * a un redirect_uri interno no documentado y el canje server-side falla con el
 * error 36008 ("redirect_uri is not identical"). Con el diálogo OAuth clásico
 * controlamos el redirect_uri nosotros y el matching es determinístico.
 *
 * Flujo:
 *  1. Popup a facebook.com/{v}/dialog/oauth con redirect_uri = {origin}/meta/callback
 *     y state = "messenger:{uuid}".
 *  2. Meta redirige el popup a /meta/callback?code=... — esa página nos manda el
 *     code por postMessage y se cierra.
 *  3. POST /api/messenger-auth con {code, redirect_uri}.
 *  4. Si el usuario administra varias páginas, el backend devuelve
 *     {pages, onboarding_token} y mostramos el selector (segunda vuelta).
 *
 * A diferencia de Instagram no se piden permisos instagram_*: Messenger sirve a
 * cualquier página, incluidas las que no tienen Instagram vinculado.
 *
 * Requisito de dashboard: {origin}/meta/callback debe estar en los "URI de
 * redireccionamiento de OAuth válidos" de Facebook Login for Business.
 */
export const useMessengerLogin = () => {
  // Lista de páginas pendiente de elección (cuando hay más de una).
  const [pageOptions, setPageOptions] = useState<MessengerPageOption[] | null>(null);
  const [onboardingToken, setOnboardingToken] = useState<string | null>(null);
  // state anti-CSRF del OAuth: solo aceptamos el callback que iniciamos.
  const stateRef = useRef<string | null>(null);

  const getAuthToken = (): string | null => {
    const authStorage = localStorage.getItem("auth-storage");
    return authStorage ? JSON.parse(authStorage)?.state?.token : null;
  };

  const redirectUri = useCallback(() => {
    return `${window.location.origin}/meta/callback`;
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
        const response = await fetch(`/api/messenger-auth`, {
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
      throw new ChannelError({ code: "channelErrorMessenger" });
    } catch (error) {
      console.error("Error conectando Messenger:", error);
      const detail: ChannelErrorDetail =
        error instanceof ChannelError ? error.detail : { code: "channelErrorMessenger" };
      window.dispatchEvent(new CustomEvent("channel-error", { detail }));
    }
  }, []);

  // Escuchar el postMessage de la página de callback del popup.
  useEffect(() => {
    const onMessage = (event: MessageEvent) => {
      if (event.origin !== window.location.origin) return;
      const data = event.data;
      // El callback es compartido con otros canales de Meta: filtrar por provider.
      if (!data || data.type !== "meta-auth" || data.provider !== PROVIDER) return;

      // Ignorar callbacks que no correspondan al login que iniciamos.
      if (!stateRef.current || data.state !== stateRef.current) return;
      stateRef.current = null;

      if (data.code) {
        window.dispatchEvent(new CustomEvent("channel-connecting"));
        postToBackend({ code: data.code, redirect_uri: redirectUri() });
      } else {
        console.warn("Messenger login cancelado o fallido:", data.error);
      }
    };

    window.addEventListener("message", onMessage);
    return () => window.removeEventListener("message", onMessage);
  }, [postToBackend, redirectUri]);

  const launchMessengerLogin = useCallback(() => {
    const appId = process.env.NEXT_PUBLIC_FACEBOOK_APP_ID;
    if (!appId) {
      console.warn("Messenger: falta NEXT_PUBLIC_FACEBOOK_APP_ID.");
      return;
    }

    const version = process.env.NEXT_PUBLIC_FACEBOOK_GRAPH_API_VERSION || "v21.0";

    const scope = [
      "pages_show_list",
      "pages_manage_metadata",
      "pages_messaging",
      "pages_read_engagement",
      "business_management",
    ].join(",");

    // El provider va en el state para que el callback compartido sepa a quién
    // devolverle el code.
    const state = `${PROVIDER}:${crypto.randomUUID()}`;
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
      "messenger-login",
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
    launchMessengerLogin,
    pageOptions,
    selectPage,
    cancelPageSelection,
  };
};
