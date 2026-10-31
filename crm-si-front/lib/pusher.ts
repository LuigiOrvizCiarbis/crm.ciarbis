import Pusher from "pusher-js";
import { getAuthToken, workspaceHeaders } from "@/lib/api/auth-token";

let pusherInstance: Pusher | null = null;

export function getPusher(): Pusher {
  if (pusherInstance) return pusherInstance;

  const key = process.env.NEXT_PUBLIC_REVERB_APP_KEY ?? "";
  const host = process.env.NEXT_PUBLIC_REVERB_HOST ?? "localhost";
  const port = Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 8080);
  const scheme = process.env.NEXT_PUBLIC_REVERB_SCHEME ?? "http";
  const useTLS = scheme === "https";

  pusherInstance = new Pusher(key, {
    wsHost: host,
    wsPort: useTLS ? 443 : port,
    wssPort: useTLS ? 443 : port,
    forceTLS: useTLS,
    enabledTransports: ["ws", "wss"],
    disableStats: true,
    cluster: "",
    authorizer: (channel) => ({
      authorize: (socketId, callback) => {
        fetch("/api/broadcasting/auth", {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            Authorization: `Bearer ${getAuthToken()}`,
            ...workspaceHeaders(),
          },
          body: new URLSearchParams({ socket_id: socketId, channel_name: channel.name }),
        })
          .then((res) => {
            if (!res.ok) throw new Error(`Auth failed: ${res.status}`);
            return res.json();
          })
          .then((data) => callback(null, data))
          .catch((err) => callback(err, null));
      },
    }),
  });

  return pusherInstance;
}

export function disconnectPusher(): void {
  if (pusherInstance) {
    pusherInstance.disconnect();
    pusherInstance = null;
  }
}
