<?php

namespace App\Services;

use App\Models\MailConfig;
use DirectoryTree\ImapEngine\Mailbox;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;

/**
 * Construye los clientes IMAP y SMTP a partir de una MailConfig. Aislado del
 * servicio de mensajes para poder testear el parseo/armado sin abrir sockets.
 */
class MailTransportFactory
{
    /**
     * Cliente IMAP (lectura de la casilla). No conecta hasta que se usa.
     */
    public function imap(MailConfig $config, ?string $plainPassword = null): Mailbox
    {
        return new Mailbox([
            'host' => $config->imap_host,
            'port' => (int) $config->imap_port,
            'encryption' => $this->imapEncryption($config->imap_encryption),
            'username' => $config->email_address,
            'password' => $plainPassword ?? $config->getDecryptedPassword() ?? '',
            'timeout' => 30,
        ]);
    }

    /**
     * Mailer SMTP (envío). Se arma por config porque cada tenant usa su propio
     * servidor, así que no podemos usar el mailer por defecto de Laravel.
     */
    public function smtp(MailConfig $config, ?string $plainPassword = null): Mailer
    {
        return new Mailer($this->smtpTransport($config, $plainPassword));
    }

    /**
     * Transporte SMTP crudo (sin envolver en Mailer). Se usa en el onboarding
     * para llamar `start()` y validar host/puerto/credenciales sin mandar un
     * email real.
     */
    public function smtpTransport(MailConfig $config, ?string $plainPassword = null): EsmtpTransport
    {
        $password = $plainPassword ?? $config->getDecryptedPassword() ?? '';

        // `smtps://` fuerza TLS implícito (puerto 465); `smtp://` hace STARTTLS
        // cuando el servidor lo anuncia. Con `none` desactivamos el upgrade.
        $scheme = match ($config->smtp_encryption) {
            'ssl' => 'smtps',
            default => 'smtp',
        };

        $options = [];
        if ($config->smtp_encryption === 'none') {
            $options['auto_tls'] = 'false';
        }

        $dsn = new Dsn(
            scheme: $scheme,
            host: $config->smtp_host,
            user: $config->email_address,
            password: $password,
            port: (int) $config->smtp_port,
            options: $options,
        );

        /** @var EsmtpTransport */
        return (new EsmtpTransportFactory)->create($dsn);
    }

    /**
     * ImapEngine espera `ssl`/`tls`/`starttls` o false. Nuestro `tls` de la UI
     * significa STARTTLS (el caso típico del puerto 143).
     */
    private function imapEncryption(string $encryption): string|false
    {
        return match ($encryption) {
            'ssl' => 'ssl',
            'tls' => 'starttls',
            default => false,
        };
    }
}
