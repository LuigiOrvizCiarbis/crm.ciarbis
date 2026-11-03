<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificá tu email de BIMS</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
</head>
<body style="margin: 0; padding: 0; background-color: #0f172a; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;">
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color: #0f172a;">
        <tr>
            <td style="padding: 40px 20px;">
                <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px; margin: 0 auto;">

                    <!-- Header con Logo -->
                    <tr>
                        <td style="text-align: center; padding-bottom: 32px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 0 auto;">
                                <tr>
                                    <td style="background: linear-gradient(135deg, #0ea5e9 0%, #8b5cf6 100%); width: 56px; height: 56px; border-radius: 16px; text-align: center; vertical-align: middle;">
                                        <span style="font-size: 28px;">💬</span>
                                    </td>
                                </tr>
                            </table>
                            <h1 style="color: #ffffff; font-size: 28px; font-weight: 700; margin: 16px 0 0 0; letter-spacing: -0.5px;">
                                BIMS
                            </h1>
                        </td>
                    </tr>

                    <!-- Card Principal -->
                    <tr>
                        <td>
                            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color: #1e293b; border-radius: 16px; overflow: hidden; border: 1px solid #334155;">

                                <!-- Gradient Header -->
                                <tr>
                                    <td style="height: 4px; background: linear-gradient(90deg, #0ea5e9 0%, #8b5cf6 50%, #0ea5e9 100%);"></td>
                                </tr>

                                <!-- Contenido -->
                                <tr>
                                    <td style="padding: 40px 32px;">

                                        <!-- Icono de Email -->
                                        <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 0 auto 24px auto;">
                                            <tr>
                                                <td style="background-color: rgba(14, 165, 233, 0.1); width: 80px; height: 80px; border-radius: 50%; text-align: center; vertical-align: middle;">
                                                    <span style="font-size: 40px;">✉️</span>
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- Saludo -->
                                        <h2 style="color: #ffffff; font-size: 24px; font-weight: 600; margin: 0 0 8px 0; text-align: center;">
                                            ¡Hola {{ $userName }}! 👋
                                        </h2>

                                        <p style="color: #94a3b8; font-size: 16px; line-height: 1.6; margin: 0 0 24px 0; text-align: center;">
                                            Gracias por unirte a <strong style="color: #0ea5e9;">BIMS</strong>
                                        </p>

                                        <!-- Mensaje Principal -->
                                        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color: #0f172a; border-radius: 12px; margin-bottom: 32px;">
                                            <tr>
                                                <td style="padding: 24px;">
                                                    <p style="color: #e2e8f0; font-size: 15px; line-height: 1.7; margin: 0; text-align: center;">
                                                        Para comenzar a gestionar tus conversaciones de WhatsApp, Instagram y Facebook desde un solo lugar, necesitamos verificar tu dirección de correo electrónico.
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- Botón CTA -->
                                        <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 0 auto 32px auto;">
                                            <tr>
                                                <td>
                                                    <a href="{{ $verificationUrl }}" style="display: inline-block; background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); color: #ffffff; text-decoration: none; font-size: 16px; font-weight: 600; padding: 16px 48px; border-radius: 12px; box-shadow: 0 4px 14px rgba(14, 165, 233, 0.4);">
                                                        ✓ Verificar mi Email
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- Expiración -->
                                        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom: 24px;">
                                            <tr>
                                                <td style="text-align: center;">
                                                    <span style="display: inline-block; background-color: rgba(251, 191, 36, 0.1); color: #fbbf24; font-size: 13px; padding: 8px 16px; border-radius: 20px; border: 1px solid rgba(251, 191, 36, 0.2);">
                                                        ⏱️ Este enlace expira en 60 minutos
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- Divider -->
                                        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom: 24px;">
                                            <tr>
                                                <td style="height: 1px; background-color: #334155;"></td>
                                            </tr>
                                        </table>

                                        <!-- Link alternativo -->
                                        <p style="color: #64748b; font-size: 13px; line-height: 1.6; margin: 0; text-align: center;">
                                            Si el botón no funciona, copia y pega este enlace en tu navegador:
                                        </p>
                                        <p style="color: #0ea5e9; font-size: 12px; line-height: 1.6; margin: 8px 0 0 0; text-align: center; word-break: break-all;">
                                            {{ $verificationUrl }}
                                        </p>

                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Mensaje de seguridad -->
                    <tr>
                        <td style="padding-top: 24px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color: rgba(30, 41, 59, 0.5); border-radius: 12px; border: 1px solid #334155;">
                                <tr>
                                    <td style="padding: 20px 24px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td style="width: 40px; vertical-align: top;">
                                                    <span style="font-size: 20px;">🔒</span>
                                                </td>
                                                <td>
                                                    <p style="color: #94a3b8; font-size: 13px; line-height: 1.6; margin: 0;">
                                                        <strong style="color: #e2e8f0;">¿No creaste esta cuenta?</strong><br>
                                                        Si no te registraste en BIMS, podés ignorar este correo de forma segura.
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding-top: 40px; text-align: center;">
                            <p style="color: #64748b; font-size: 13px; margin: 0 0 8px 0;">
                                Hecho con 💜 por el equipo de Social Impulse
                            </p>
                            <p style="color: #475569; font-size: 12px; margin: 0;">
                                © {{ date('Y') }} Social Impulse. Todos los derechos reservados.
                            </p>
                        </td>
                    </tr>

                    <!-- Social Links (opcional) -->
                    <tr>
                        <td style="padding-top: 24px; text-align: center;">
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 0 auto;">
                                <tr>
                                    <td style="padding: 0 8px;">
                                        <a href="#" style="color: #64748b; text-decoration: none; font-size: 12px;">Términos</a>
                                    </td>
                                    <td style="color: #475569;">•</td>
                                    <td style="padding: 0 8px;">
                                        <a href="#" style="color: #64748b; text-decoration: none; font-size: 12px;">Privacidad</a>
                                    </td>
                                    <td style="color: #475569;">•</td>
                                    <td style="padding: 0 8px;">
                                        <a href="#" style="color: #64748b; text-decoration: none; font-size: 12px;">Soporte</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
