"use client"

import { useState } from "react"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Card, CardContent } from "@/components/ui/card"
import { Badge } from "@/components/Badges"
import { useToast } from "@/components/Toast"
import { CreditCard, Check, Zap, Star, Lock } from "lucide-react"

interface PaymentModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

const plans = [
  {
    id: "starter",
    name: "Starter",
    price: "$29",
    period: "/mes",
    description: "Perfecto para pequeños equipos",
    features: [
      "Hasta 1,000 mensajes/mes",
      "2 canales conectados",
      "Soporte por email",
      "Panel básico",
      "Integraciones básicas",
    ],
    popular: false,
    current: true,
  },
  {
    id: "professional",
    name: "Professional",
    price: "$79",
    period: "/mes",
    description: "Para equipos en crecimiento",
    features: [
      "Hasta 10,000 mensajes/mes",
      "5 canales conectados",
      "Soporte prioritario",
      "Analytics avanzados",
      "Automatizaciones IA",
      "API completa",
      "Integraciones premium",
    ],
    popular: true,
    current: false,
  },
  {
    id: "enterprise",
    name: "Enterprise",
    price: "$199",
    period: "/mes",
    description: "Para grandes organizaciones",
    features: [
      "Mensajes ilimitados",
      "Canales ilimitados",
      "Soporte 24/7",
      "Panel personalizado",
      "IA personalizada",
      "SSO y seguridad avanzada",
      "Manager dedicado",
      "SLA garantizado",
    ],
    popular: false,
    current: false,
  },
]

export function PaymentModal({ open, onOpenChange }: PaymentModalProps) {
  const [selectedPlan, setSelectedPlan] = useState("professional")
  const [step, setStep] = useState<"plans" | "payment">("plans")
  const [isProcessing, setIsProcessing] = useState(false)
  const [paymentData, setPaymentData] = useState({
    cardNumber: "",
    expiryDate: "",
    cvv: "",
    name: "",
    email: "",
    company: "",
  })
  const { addToast } = useToast()

  const handlePlanSelect = (planId: string) => {
    setSelectedPlan(planId)
  }

  const handleContinue = () => {
    setStep("payment")
  }

  const handleBack = () => {
    setStep("plans")
  }

  const handlePayment = async () => {
    setIsProcessing(true)

    // Simulate payment processing
    await new Promise((resolve) => setTimeout(resolve, 3000))

    setIsProcessing(false)

    addToast({
      type: "success",
      title: "¡Upgrade exitoso!",
      description: `Plan ${plans.find((p) => p.id === selectedPlan)?.name} activado correctamente`,
    })

    onOpenChange(false)
    setStep("plans")
    setPaymentData({
      cardNumber: "",
      expiryDate: "",
      cvv: "",
      name: "",
      email: "",
      company: "",
    })
  }

  const selectedPlanData = plans.find((p) => p.id === selectedPlan)

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Zap className="w-5 h-5 text-primary" />
            {step === "plans" ? "Seleccioná tu plan" : "Información de pago"}
          </DialogTitle>
        </DialogHeader>

        {step === "plans" && (
          <div className="space-y-6">
            <div className="text-center">
              <p className="text-muted-foreground">Elegí el plan que mejor se adapte a las necesidades de tu equipo</p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
              {plans.map((plan) => (
                <Card
                  key={plan.id}
                  className={`relative cursor-pointer transition-all hover:shadow-lg ${
                    selectedPlan === plan.id ? "ring-2 ring-primary bg-primary/5" : ""
                  } ${plan.current ? "opacity-60" : ""}`}
                  onClick={() => !plan.current && handlePlanSelect(plan.id)}
                >
                  {plan.popular && (
                    <div className="absolute -top-3 left-1/2 transform -translate-x-1/2">
                      <Badge variant="default" className="gap-1">
                        <Star className="w-3 h-3" />
                        Más Popular
                      </Badge>
                    </div>
                  )}

                  {plan.current && (
                    <div className="absolute -top-3 left-1/2 transform -translate-x-1/2">
                      <Badge variant="success" className="gap-1">
                        <Check className="w-3 h-3" />
                        Plan Actual
                      </Badge>
                    </div>
                  )}

                  <CardContent className="p-6">
                    <div className="text-center mb-6">
                      <h3 className="text-xl font-bold mb-2">{plan.name}</h3>
                      <div className="flex items-baseline justify-center gap-1">
                        <span className="text-3xl font-bold">{plan.price}</span>
                        <span className="text-muted-foreground">{plan.period}</span>
                      </div>
                      <p className="text-sm text-muted-foreground mt-2">{plan.description}</p>
                    </div>

                    <div className="space-y-3">
                      {plan.features.map((feature, index) => (
                        <div key={index} className="flex items-center gap-2">
                          <Check className="w-4 h-4 text-success flex-shrink-0" />
                          <span className="text-sm">{feature}</span>
                        </div>
                      ))}
                    </div>

                    {selectedPlan === plan.id && !plan.current && (
                      <div className="mt-4 p-2 bg-primary/10 rounded-lg">
                        <div className="flex items-center gap-2 text-primary">
                          <Check className="w-4 h-4" />
                          <span className="text-sm font-medium">Plan seleccionado</span>
                        </div>
                      </div>
                    )}
                  </CardContent>
                </Card>
              ))}
            </div>

            <div className="flex justify-center">
              <Button
                onClick={handleContinue}
                disabled={!selectedPlan || plans.find((p) => p.id === selectedPlan)?.current}
                className="gap-2"
              >
                <CreditCard className="w-4 h-4" />
                Continuar con el Pago
              </Button>
            </div>
          </div>
        )}

        {step === "payment" && selectedPlanData && (
          <div className="space-y-6">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              {/* Payment Form */}
              <div className="space-y-4">
                <h3 className="text-lg font-semibold">Información de pago</h3>

                <div className="space-y-4">
                  <div>
                    <Label htmlFor="cardNumber">Número de tarjeta</Label>
                    <Input
                      id="cardNumber"
                      placeholder="1234 5678 9012 3456"
                      value={paymentData.cardNumber}
                      onChange={(e) => setPaymentData((prev) => ({ ...prev, cardNumber: e.target.value }))}
                    />
                  </div>

                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <Label htmlFor="expiryDate">Fecha de vencimiento</Label>
                      <Input
                        id="expiryDate"
                        placeholder="MM/YY"
                        value={paymentData.expiryDate}
                        onChange={(e) => setPaymentData((prev) => ({ ...prev, expiryDate: e.target.value }))}
                      />
                    </div>
                    <div>
                      <Label htmlFor="cvv">CVV</Label>
                      <Input
                        id="cvv"
                        placeholder="123"
                        value={paymentData.cvv}
                        onChange={(e) => setPaymentData((prev) => ({ ...prev, cvv: e.target.value }))}
                      />
                    </div>
                  </div>

                  <div>
                    <Label htmlFor="name">Nombre del titular</Label>
                    <Input
                      id="name"
                      placeholder="Juan Pérez"
                      value={paymentData.name}
                      onChange={(e) => setPaymentData((prev) => ({ ...prev, name: e.target.value }))}
                    />
                  </div>

                  <div>
                    <Label htmlFor="email">Email</Label>
                    <Input
                      id="email"
                      type="email"
                      placeholder="juan@empresa.com"
                      value={paymentData.email}
                      onChange={(e) => setPaymentData((prev) => ({ ...prev, email: e.target.value }))}
                    />
                  </div>

                  <div>
                    <Label htmlFor="company">Empresa (Opcional)</Label>
                    <Input
                      id="company"
                      placeholder="Mi Empresa S.A."
                      value={paymentData.company}
                      onChange={(e) => setPaymentData((prev) => ({ ...prev, company: e.target.value }))}
                    />
                  </div>
                </div>
              </div>

              {/* Order Summary */}
              <div className="space-y-4">
                <h3 className="text-lg font-semibold">Resumen del pedido</h3>

                <Card>
                  <CardContent className="p-4">
                    <div className="space-y-4">
                      <div className="flex items-center justify-between">
                        <div>
                          <h4 className="font-medium">{selectedPlanData.name}</h4>
                          <p className="text-sm text-muted-foreground">{selectedPlanData.description}</p>
                        </div>
                        <div className="text-right">
                          <p className="font-bold">{selectedPlanData.price}</p>
                          <p className="text-sm text-muted-foreground">{selectedPlanData.period}</p>
                        </div>
                      </div>

                      <div className="border-t pt-4">
                        <div className="flex justify-between items-center">
                          <span className="font-medium">Total</span>
                          <span className="text-xl font-bold">
                            {selectedPlanData.price}
                            {selectedPlanData.period}
                          </span>
                        </div>
                      </div>
                    </div>
                  </CardContent>
                </Card>

                <div className="space-y-3">
                  <h4 className="font-medium">Incluye:</h4>
                  {selectedPlanData.features.map((feature, index) => (
                    <div key={index} className="flex items-center gap-2">
                      <Check className="w-4 h-4 text-success flex-shrink-0" />
                      <span className="text-sm">{feature}</span>
                    </div>
                  ))}
                </div>

                <div className="flex items-center gap-2 p-3 bg-muted/50 rounded-lg">
                  <Lock className="w-4 h-4 text-success" />
                  <span className="text-sm">Pago seguro con encriptación SSL</span>
                </div>
              </div>
            </div>

            <div className="flex justify-between pt-4 border-t">
              <Button variant="outline" onClick={handleBack}>
                Volver
              </Button>
              <Button onClick={handlePayment} disabled={isProcessing} className="gap-2">
                {isProcessing ? (
                  <>
                    <div className="w-4 h-4 border-2 border-current border-t-transparent rounded-full animate-spin" />
                    Procesando...
                  </>
                ) : (
                  <>
                    <CreditCard className="w-4 h-4" />
                    Confirmar Pago
                  </>
                )}
              </Button>
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}
