import { BroadcastResults } from "@/components/broadcasts/BroadcastResults"

export default async function BroadcastResultsPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params
  return <BroadcastResults id={Number(id)} />
}
