import { BrandPilot } from "@/components/brand/BrandPilot"
import { BroadcastResults } from "@/components/broadcasts/BroadcastResults"

export default async function BroadcastResultsPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params
  return (
    <>
      <BrandPilot />
      <BroadcastResults id={Number(id)} />
    </>
  )
}
