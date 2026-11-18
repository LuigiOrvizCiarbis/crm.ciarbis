import { NextRequest, NextResponse } from "next/server"
import { readSessionJar, writeSessionJar } from "@/lib/auth-session"

/** Activa otra cuenta guardada en la cookie de este navegador. */
export async function POST(request: NextRequest) {
  const body = await request.json().catch(() => ({}))
  const userId = Number(body?.userId)
  const jar = readSessionJar(request)

  if (!Number.isInteger(userId) || !jar.tokens[userId]) {
    return NextResponse.json({ message: "La cuenta ya no está guardada en este navegador." }, { status: 404 })
  }

  const response = new NextResponse(null, { status: 204 })
  writeSessionJar(response, { ...jar, active: userId })
  return response
}
