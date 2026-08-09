import { NextRequest, NextResponse } from "next/server";
import { proxyToLaravel } from "@/lib/api/proxy-helper";

export async function POST(req: NextRequest) {
  let payload: any = {};
  try {
    payload = await req.json();
  } catch {
    payload = {};
  }

  const authHeader = req.headers.get("authorization");

  if (!authHeader) {
    return NextResponse.json(
      { success: false, code: "channelErrorSessionExpired" },
      { status: 401 }
    );
  }

  const baseUrls: string[] = [];
  const pushCandidate = (b?: string) => {
    if (!b) return;
    const base = b.replace(/\/$/, "");
    if (!base) return;
    baseUrls.push(base);
    if (base.startsWith("https://localhost")) {
      baseUrls.push("http://localhost:8000");
    }
  };

  pushCandidate(process.env.API_INTERNAL_URL);
  pushCandidate(process.env.NEXT_PUBLIC_API_URL);
  baseUrls.push("http://localhost:8000");
  baseUrls.push("http://host.docker.internal:8000");

  try {
    const { data, status } = await proxyToLaravel(
      "/api/admin/channels/messenger-auth",
      authHeader,
      {
        method: "POST",
        body: JSON.stringify(payload),
        baseUrls,
      }
    );
    return NextResponse.json(data, { status });
  } catch (e: any) {
    console.error("Proxy messenger-auth error:", e);
    return NextResponse.json(
      { success: false, code: "channelErrorServer" },
      { status: 503 }
    );
  }
}
