"use client";

import { useState, useEffect, useRef, useCallback } from "react";
import { ChannelError, ChannelErrorDetail } from "@/lib/channel-error";

declare global {
  interface Window {
    FB: any;
    fbAsyncInit: () => void;
    // true recién tras FB.init(); compartido con useInstagramLogin.
    __fbSdkInitialized?: boolean;
  }
}

export const useFacebookSDK = () => {
  const [isFacebookSDKLoaded, setIsFacebookSDKLoaded] = useState(false);
  const signupEventRef = useRef<any>(null);
  const lastResponseRef = useRef<any>(null);
  const codeRef = useRef<string | null>(null);
  const sentRef = useRef<boolean>(false);
  const debugEnabled = process.env.NEXT_PUBLIC_WHATSAPP_DEBUG === "1";

  const logDebug = useCallback((...args: any[]) => {
    if (debugEnabled) {
      console.info("[WA Embedded Signup]", ...args);
    }
  }, [debugEnabled]);

  const sendToBackend = useCallback(async (
    code: string,
    response: any,
    signupPayload?: any
  ) => {
    try {
      const authStorage = localStorage.getItem("auth-storage");
      const token = authStorage ? JSON.parse(authStorage)?.state?.token : null;
      const embeddedEvent = signupPayload ?? signupEventRef.current ?? null;
      const embeddedData = embeddedEvent?.data ?? null;

      if (!token) {
        throw new ChannelError({ code: "channelErrorSessionExpired" });
      }

      let data: any;
      try {
        const backendResponse = await fetch(`/api/whatsapp-auth`, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Authorization: `Bearer ${token}`,
          },
          body: JSON.stringify({
            code: code,
            full_response: response,
            data: embeddedData,
            embedded_signup_event: embeddedEvent?.event ?? null,
            embedded_signup_version: embeddedEvent?.version ?? null,
            waba_id: embeddedData?.waba_id ?? null,
            phone_number_id: embeddedData?.phone_number_id ?? null,
            business_id: embeddedData?.business_id ?? null,
          }),
        });

        data = await backendResponse.json();
      } catch {
        // Error de red o respuesta no-JSON: el front muestra un mensaje traducido.
        throw new ChannelError({ code: "channelErrorNetwork" });
      }

      if (data.success) {
        window.dispatchEvent(new CustomEvent("channel-connected"));
      } else {
        // Los proxies mandan `code` (el front lo traduce); Laravel manda `message`
        // (texto ya redactado para el usuario).
        if (data.code) throw new ChannelError({ code: data.code });
        if (data.message) throw new ChannelError({ message: data.message });
        throw new ChannelError({ code: "channelErrorWhatsApp" });
      }
    } catch (error) {
      console.error("Error enviando datos al backend:", error);
      const detail: ChannelErrorDetail =
        error instanceof ChannelError ? error.detail : { code: "channelErrorWhatsApp" };
      window.dispatchEvent(new CustomEvent("channel-error", { detail }));
    }
  }, []);

  // Load Facebook SDK script.
  //
  // Comparte el flag global __fbSdkInitialized con useInstagramLogin: el SDK y
  // FB.init() son globales y deben correr UNA sola vez, sin importar qué hook se
  // monta primero. Marcamos "listo" recién tras init (no por presencia del
  // <script>), porque FB.login()/launchWhatsAppSignup fallan si init no corrió.
  useEffect(() => {
    if (window.__fbSdkInitialized) {
      setIsFacebookSDKLoaded(true);
      return;
    }

    let cancelled = false;

    const runInit = () => {
      if (!window.__fbSdkInitialized && window.FB) {
        window.FB.init({
          appId: process.env.NEXT_PUBLIC_FACEBOOK_APP_ID,
          autoLogAppEvents: true,
          xfbml: true,
          version: process.env.NEXT_PUBLIC_FACEBOOK_GRAPH_API_VERSION,
        });
        window.__fbSdkInitialized = true;
      }
    };

    if (!document.getElementById("facebook-jssdk")) {
      const script = document.createElement("script");
      script.id = "facebook-jssdk";
      script.src = "https://connect.facebook.net/en_US/sdk.js";
      script.async = true;
      script.defer = true;
      script.crossOrigin = "anonymous";
      const prevInit = window.fbAsyncInit;
      window.fbAsyncInit = function () {
        if (typeof prevInit === "function") prevInit();
        runInit();
      };
      document.head.appendChild(script);
    }

    const interval = setInterval(() => {
      if (cancelled) return;
      if (window.FB && !window.__fbSdkInitialized) {
        runInit();
      }
      if (window.__fbSdkInitialized) {
        setIsFacebookSDKLoaded(true);
        clearInterval(interval);
      }
    }, 150);

    return () => {
      cancelled = true;
      clearInterval(interval);
    };
  }, []);

  // Message listener — separate effect with proper cleanup
  useEffect(() => {
    const handleEmbeddedEvent = (payload: any) => {
      if (!payload || payload.type !== "WA_EMBEDDED_SIGNUP") return;

      signupEventRef.current = payload;
      logDebug("Event recibido:", payload.event, payload);

      if (payload.event === "FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING") {
        if (codeRef.current && !sentRef.current) {
          sentRef.current = true;
          sendToBackend(
            codeRef.current,
            lastResponseRef.current,
            signupEventRef.current
          );
        }
        return;
      }

      if (payload.event === "CANCEL") {
        console.warn(
          "[WA Embedded Signup] Flujo cancelado/abortado en paso:",
          payload?.data?.current_step ?? "desconocido"
        );
      }
    };

    const handleMessage = (event: MessageEvent) => {
      if (!event.origin.endsWith("facebook.com")) return;

      try {
        const data = JSON.parse(event.data);
        handleEmbeddedEvent(data);
      } catch {
        const raw: any = (event as any).data;
        if (
          raw &&
          typeof raw === "object" &&
          raw.type === "WA_EMBEDDED_SIGNUP"
        ) {
          handleEmbeddedEvent(raw);
        } else if (typeof event.data === "string" && event.data.includes("&code=")) {
          const params = new URLSearchParams(event.data);
          const code = params.get("code");
          if (code) {
            codeRef.current = code;
            if (
              signupEventRef.current?.event === "FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING" &&
              !sentRef.current
            ) {
              sentRef.current = true;
              sendToBackend(code, null, signupEventRef.current);
            } else {
              setTimeout(() => {
                if (!sentRef.current && codeRef.current) {
                  console.warn(
                    "[WA Embedded Signup] No llegó FINISH en 6s. Enviando fallback al backend."
                  );
                  sentRef.current = true;
                  sendToBackend(codeRef.current, null, signupEventRef.current);
                }
              }, 6000);
            }
          }
        } else {
          console.warn("Received non-JSON message from Facebook:", event.data);
        }
      }
    };

    window.addEventListener("message", handleMessage);
    return () => {
      window.removeEventListener("message", handleMessage);
    };
  }, [logDebug, sendToBackend]);

  // Response callback
  const fbLoginCallback = useCallback((response: any) => {
    if (response.authResponse) {
      const code = response.authResponse.code;
      codeRef.current = code;
      lastResponseRef.current = response;

      window.dispatchEvent(new CustomEvent("channel-connecting"));

      if (
        signupEventRef.current?.event === "FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING" &&
        !sentRef.current
      ) {
        sentRef.current = true;
        sendToBackend(code, response, signupEventRef.current);
        return;
      }

      setTimeout(() => {
        if (!sentRef.current && codeRef.current) {
          console.warn(
            "[WA Embedded Signup] No llegó FINISH en 6s tras login callback. Enviando fallback al backend."
          );
          sentRef.current = true;
          sendToBackend(
            codeRef.current as string,
            lastResponseRef.current,
            signupEventRef.current
          );
        }
      }, 6000);
    } else {
      console.warn("Facebook login cancelled or failed:", response);
    }
  }, [sendToBackend]);

  const launchWhatsAppSignup = useCallback(() => {
    if (!window.FB || !window.__fbSdkInitialized) {
      console.warn("WhatsApp: el SDK de Facebook todavía no está listo.");
      return;
    }

    window.FB.login(fbLoginCallback, {
      config_id: process.env.NEXT_PUBLIC_FACEBOOK_CONFIGURATION_ID,
      response_type: "code",
      override_default_response_type: true,
      extras: {
        setup: {},
        featureType: "whatsapp_business_app_onboarding",
        sessionInfoVersion: "3",
      },
    });
  }, [fbLoginCallback]);

  return {
    launchWhatsAppSignup,
    isFacebookSDKLoaded,
  };
};
