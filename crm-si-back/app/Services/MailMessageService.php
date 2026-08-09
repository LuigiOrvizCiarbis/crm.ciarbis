<?php

namespace App\Services;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Events\MessageSent;
use App\Events\TenantMessageReceived;
use App\Jobs\GenerateAiReplyJob;
use App\Models\AiConfig;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\MailConfig;
use App\Models\Message;
use App\Models\PipelineStage;
use App\Models\User;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

/**
 * Canal MAIL: ingesta por polling IMAP y envío por SMTP, con las credenciales
 * de cada tenant guardadas en MailConfig.
 *
 * A diferencia de WhatsApp/Instagram no hay webhook: `syncMailbox()` corre desde
 * SyncMailChannelJob y avanza un cursor por UID. El par (UIDVALIDITY, last_uid)
 * es la garantía de no reprocesar ni saltear: si el servidor cambia UIDVALIDITY
 * los UID viejos dejan de ser comparables y se reinicia el cursor.
 *
 * Cada email entrante es un Message dentro de la conversación del remitente,
 * igual que en los otros canales, para que la bandeja del CRM sea uniforme.
 */
class MailMessageService
{
    /**
     * Tope de mensajes por corrida. Evita que una casilla con años de historial
     * bloquee el worker en la primera sincronización.
     */
    private const MAX_MESSAGES_PER_RUN = 50;

    /** Tamaño máximo de adjunto que persistimos (10 MB). */
    private const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

    /**
     * Tope de Message-ID en el header References. La cadena completa crece con
     * cada vuelta del hilo y algunos servidores rechazan headers muy largos.
     */
    private const MAX_REFERENCES = 20;

    public function __construct(
        private MailTransportFactory $transports,
    ) {}

    // ---------------------------------------------------------------------
    // Conexión / validación de credenciales
    // ---------------------------------------------------------------------

    /**
     * Prueba las credenciales IMAP abriendo la conexión y seleccionando INBOX.
     * Se usa en el onboarding, antes de persistir la config.
     *
     * @throws \InvalidArgumentException si el servidor rechaza o no responde
     */
    public function assertImapCredentials(MailConfig $config, string $plainPassword): void
    {
        $mailbox = $this->transports->imap($config, $plainPassword);

        try {
            $mailbox->connect();
            $mailbox->inbox()->status();
        } catch (\Throwable $e) {
            Log::warning('Mail: fallo la validación IMAP', [
                'host' => $config->imap_host,
                'port' => $config->imap_port,
                'error' => $e->getMessage(),
            ]);

            throw new \InvalidArgumentException($this->describeConnectionError($e), 0, $e);
        } finally {
            try {
                $mailbox->disconnect();
            } catch (\Throwable) {
                // Cerrar es best-effort: no queremos tapar el error original.
            }
        }
    }

    /**
     * Prueba las credenciales SMTP abriendo la conexión y autenticando, sin
     * enviar ningún email. Se usa en el onboarding junto con la de IMAP: si
     * sólo se valida IMAP, una casilla con SMTP mal configurado queda
     * conectada pero no puede responder nunca.
     *
     * @throws \InvalidArgumentException si el servidor rechaza o no responde
     */
    public function assertSmtpCredentials(MailConfig $config, string $plainPassword): void
    {
        $transport = $this->transports->smtpTransport($config, $plainPassword);

        try {
            $transport->start();
        } catch (\Throwable $e) {
            Log::warning('Mail: fallo la validación SMTP', [
                'host' => $config->smtp_host,
                'port' => $config->smtp_port,
                'error' => $e->getMessage(),
            ]);

            throw new \InvalidArgumentException($this->describeConnectionError($e), 0, $e);
        } finally {
            try {
                $transport->stop();
            } catch (\Throwable) {
                // Cerrar es best-effort: no queremos tapar el error original.
            }
        }
    }

    /**
     * Traduce excepciones de red/autenticación a mensajes accionables para el
     * usuario. El detalle técnico queda en el log, no en la respuesta HTTP.
     */
    private function describeConnectionError(\Throwable $e): string
    {
        $message = strtolower($e->getMessage());

        $isAuth = str_contains($message, 'authenticat')
            || str_contains($message, 'login')
            || str_contains($message, 'credential')
            || str_contains($message, 'invalid user')
            || str_contains($message, 'auth');

        if ($isAuth) {
            return 'Credenciales inválidas. Verificá el email y la contraseña. '.
                'En Gmail, Outlook y similares necesitás una contraseña de aplicación.';
        }

        return 'No pudimos conectarnos al servidor de correo. Verificá el host, el puerto y el tipo de encriptación.';
    }

    // ---------------------------------------------------------------------
    // Ingesta (polling IMAP)
    // ---------------------------------------------------------------------

    /**
     * Sincroniza la casilla: trae los mensajes con UID mayor al cursor y los
     * persiste como mensajes entrantes. Devuelve cuántos creó.
     *
     * Los errores se guardan en `last_error` para que la UI pueda mostrar el
     * estado del canal, y se relanzan para que el job los registre.
     */
    public function syncMailbox(MailConfig $config): int
    {
        $channel = $config->channels()->first();

        if (! $channel) {
            Log::warning('Mail sync: config sin canal asociado', ['mail_config_id' => $config->id]);

            return 0;
        }

        $mailbox = $this->transports->imap($config);
        $created = 0;

        try {
            $mailbox->connect();
            $inbox = $mailbox->inbox();

            $status = $inbox->status();
            $uidValidity = isset($status['UIDVALIDITY']) ? (string) $status['UIDVALIDITY'] : null;

            // UIDVALIDITY distinto ⇒ los UID guardados ya no identifican los
            // mismos mensajes (RFC 3501). Reiniciamos el cursor y arrancamos
            // desde los mensajes nuevos para no reimportar la casilla entera.
            $cursorReset = $uidValidity !== null
                && $config->uidvalidity !== null
                && $uidValidity !== $config->uidvalidity;

            $lastUid = $cursorReset ? null : $config->last_uid;

            if ($cursorReset) {
                Log::info('Mail sync: UIDVALIDITY cambió, reiniciando cursor', [
                    'mail_config_id' => $config->id,
                    'old' => $config->uidvalidity,
                    'new' => $uidValidity,
                ]);
            }

            $messages = $this->fetchNewMessages($inbox, $lastUid);

            $highestUid = (int) ($lastUid ?? 0);

            foreach ($messages as $mail) {
                $uid = $mail->uid();

                // El rango UID de IMAP es inclusivo: el propio cursor vuelve a
                // aparecer en la respuesta. Lo salteamos explícitamente.
                if ($lastUid !== null && $uid <= (int) $lastUid) {
                    continue;
                }

                if ($this->storeIncomingMessage($channel, $config, $mail, $uidValidity)) {
                    $created++;
                }

                $highestUid = max($highestUid, $uid);
            }

            $config->forceFill([
                'last_uid' => $highestUid > 0 ? $highestUid : $config->last_uid,
                'uidvalidity' => $uidValidity ?? $config->uidvalidity,
                'last_synced_at' => now(),
                'last_error' => null,
            ])->save();

            return $created;
        } catch (\Throwable $e) {
            Log::error('Mail sync: error sincronizando casilla', [
                'mail_config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);

            $config->forceFill([
                'last_synced_at' => now(),
                'last_error' => Str::limit($e->getMessage(), 500),
            ])->save();

            throw $e;
        } finally {
            try {
                $mailbox->disconnect();
            } catch (\Throwable) {
                // best-effort
            }
        }
    }

    /**
     * Trae los mensajes nuevos, siempre en orden ascendente por UID. Con cursor
     * pedimos el rango `last_uid:*`; sin cursor (primera sync) sólo los más
     * recientes, para no importar años de historial.
     *
     * @return iterable<MessageInterface>
     */
    private function fetchNewMessages(FolderInterface $inbox, ?int $lastUid): iterable
    {
        $query = $inbox->messages()
            ->withHeaders()
            ->withBody()
            // No marcamos como leídos: el dueño de la casilla sigue viendo su
            // bandeja tal cual, y el cursor por UID ya evita duplicados.
            ->leaveUnread();

        if ($lastUid !== null) {
            // Con backlog grande (o tras una caída del worker) el rango
            // `$lastUid:*` puede tener miles de mensajes pendientes; sin este
            // tope se traen headers y cuerpos de todos de una sola corrida y
            // el job puede no llegar a guardar el cursor antes del timeout,
            // repitiendo el mismo trabajo en cada reintento sin avanzar.
            return $query->oldest()
                // INF (no la cadena '*') es el "hasta el final" que espera
                // ImapQueryBuilder::uid(): su firma tipa $to como int|float|null
                // y traduce INF al UID máximo. Pasar '*' tira TypeError y deja
                // la casilla sin sincronizar.
                ->uid($lastUid, INF)
                ->limit(self::MAX_MESSAGES_PER_RUN)
                ->get();
        }

        // Primera sync: el recorte por límite se aplica después de ordenar, así
        // que hay que pedir orden descendente para quedarse con los UID más
        // altos. Con `oldest()` la casilla arrancaría por los emails más viejos
        // del historial y tardaría muchas corridas en llegar a los actuales.
        return $query->newest()
            ->limit(self::MAX_MESSAGES_PER_RUN)
            ->get()
            // Volvemos a orden ascendente: `storeIncomingMessage()` va pisando
            // `last_message_at` de la conversación, y procesar del más nuevo al
            // más viejo dejaría el email más antiguo como último mensaje.
            ->sortBy(fn (MessageInterface $mail) => $mail->uid())
            ->values();
    }

    /**
     * Persiste un email entrante como Message. Devuelve false si era duplicado
     * o si debía ignorarse (p. ej. un email que enviamos nosotros mismos).
     */
    private function storeIncomingMessage(Channel $channel, MailConfig $config, MessageInterface $mail, ?string $uidValidity): bool
    {
        $from = $mail->from();
        $fromEmail = $from?->email();

        if (! $fromEmail) {
            Log::info('Mail sync: mensaje sin remitente, ignorado', ['uid' => $mail->uid()]);

            return false;
        }

        // Copias de nuestros propios envíos (algunos servidores dejan el enviado
        // en INBOX). No las duplicamos como entrantes.
        if (strcasecmp($fromEmail, $config->email_address) === 0) {
            return false;
        }

        $messageId = $mail->messageId();

        // La identidad se namespacea por casilla: `external_id` tiene unique
        // global y un mismo Message-ID puede llegar a dos casillas conectadas
        // (un mail dirigido a soporte@ y a ventas@ a la vez). Sin el prefijo,
        // la segunda copia se descartaría como duplicado y nunca aparecería en
        // el hilo de ese canal. El Message-ID crudo se guarda aparte, en
        // `mail_message_id`, que es lo que usan los headers de threading.
        //
        // Sin Message-ID cae al UID, único dentro de la casilla sólo mientras
        // UIDVALIDITY no cambie. Si el server reasigna un UID viejo bajo una
        // UIDVALIDITY nueva, sin este sufijo la clave sintética coincidiría con
        // la de un mensaje distinto y el mensaje nuevo se descartaría como
        // duplicado (aunque el cursor sí avanzaría).
        $externalId = 'mail-'.$config->id.'-'.($messageId ?: 'uid-'.$uidValidity.'-'.$mail->uid());

        if (Message::where('external_id', $externalId)->exists()) {
            return false;
        }

        $contact = $this->findOrCreateContact($channel, $fromEmail, $from?->name());
        $conversation = $this->findOrCreateConversation($contact, $channel, $config);

        $content = $this->extractContent($mail);
        $attachments = $this->storeAttachments($mail, $channel->tenant_id);
        $primary = $attachments[0] ?? null;

        try {
            $message = Message::create([
                'tenant_id' => $channel->tenant_id,
                'conversation_id' => $conversation->getKey(),
                'sender_type' => SenderType::CONTACT,
                'sender_id' => $contact->getKey(),
                'content' => $content,
                'message_type' => $primary ? $primary['type'] : MessageType::Text,
                'media_url' => $primary['url'] ?? null,
                'media_mime_type' => $primary['mime_type'] ?? null,
                'media_filename' => $primary['filename'] ?? null,
                'direction' => MessageDirection::INBOUND,
                'external_id' => $externalId,
                'mail_message_id' => $messageId ?: null,
                'delivered_at' => $mail->date() ?? now(),
            ]);
        } catch (QueryException $e) {
            // Carrera entre dos corridas del job sobre la misma casilla.
            if ($this->isUniqueViolation($e)) {
                return false;
            }

            throw $e;
        }

        // Los adjuntos extra van como mensajes propios para que se vean todos
        // en el hilo (el modelo Message soporta una sola media por fila).
        foreach (array_slice($attachments, 1) as $index => $attachment) {
            Message::create([
                'tenant_id' => $channel->tenant_id,
                'conversation_id' => $conversation->getKey(),
                'sender_type' => SenderType::CONTACT,
                'sender_id' => $contact->getKey(),
                'content' => '',
                'message_type' => $attachment['type'],
                'media_url' => $attachment['url'],
                'media_mime_type' => $attachment['mime_type'],
                'media_filename' => $attachment['filename'],
                'direction' => MessageDirection::INBOUND,
                'external_id' => $externalId.'-att-'.($index + 1),
                // Las filas extra son el mismo email: no repetimos su
                // Message-ID para no duplicarlo en la cadena References.
                'delivered_at' => $mail->date() ?? now(),
            ]);
        }

        Conversation::where('id', $conversation->getKey())->update([
            'last_message_at' => $message->created_at,
            'last_message_content' => Str::limit($content, 120),
        ]);

        try {
            broadcast(new MessageSent($message));
            broadcast(new TenantMessageReceived($message, $channel->tenant_id));
        } catch (\Exception $e) {
            Log::error('Error broadcasting mail message: '.$e->getMessage());
        }

        $this->maybeDispatchAiReply($message);

        return true;
    }

    /**
     * Contenido del mensaje: el asunto encabeza el cuerpo porque en email es
     * parte del significado, a diferencia de los canales de chat.
     */
    private function extractContent(MessageInterface $mail): string
    {
        $subject = trim((string) $mail->subject());
        $body = $mail->text();

        if (! $body && $mail->html()) {
            $body = $this->htmlToText($mail->html());
        }

        $body = $this->stripQuotedReply(trim((string) $body));

        if ($subject && $body) {
            return $subject."\n\n".$body;
        }

        return $subject ?: $body;
    }

    /**
     * HTML a texto plano legible, preservando los saltos de bloque.
     */
    private function htmlToText(string $html): string
    {
        $withoutInvisible = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $withBreaks = preg_replace('#<(br|/p|/div|/tr|/li|/h[1-6])\s*/?>#i', "\n", $withoutInvisible) ?? $withoutInvisible;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Colapsar el exceso de líneas en blanco que deja el markup.
        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    /**
     * Recorta la cita del mensaje anterior que agregan los clientes de correo.
     * Sin esto, cada respuesta arrastra todo el hilo y ensucia la conversación
     * (y el contexto que ve la IA).
     */
    private function stripQuotedReply(string $text): string
    {
        $patterns = [
            // "On <fecha> <alguien> wrote:" / "El <fecha> <alguien> escribió:"
            '/^\s*(On|El)\s.+?(wrote|escribió|escribio):\s*$/imu',
            // Encabezado de reenvío/respuesta de Outlook y similares.
            '/^\s*-{2,}\s*(Original Message|Mensaje original|Forwarded message)\s*-{2,}\s*$/imu',
            '/^\s*_{5,}\s*$/m',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                $text = substr($text, 0, $matches[0][1]);
            }
        }

        // Bloque final de líneas citadas con ">".
        $lines = explode("\n", $text);
        while (! empty($lines) && (trim(end($lines)) === '' || str_starts_with(ltrim(end($lines)), '>'))) {
            array_pop($lines);
        }

        return trim(implode("\n", $lines));
    }

    /**
     * Guarda los adjuntos en el disco público, igual que los otros canales.
     *
     * @return list<array{url: string, mime_type: string, filename: string, type: MessageType}>
     */
    private function storeAttachments(MessageInterface $mail, int $tenantId): array
    {
        $stored = [];

        try {
            foreach ($mail->attachments() as $attachment) {
                $contents = $attachment->contents();

                if ($contents === '') {
                    continue;
                }

                if (strlen($contents) > self::MAX_ATTACHMENT_BYTES) {
                    Log::info('Mail sync: adjunto omitido por tamaño', [
                        'filename' => $attachment->filename(),
                        'bytes' => strlen($contents),
                    ]);

                    continue;
                }

                $mimeType = $attachment->contentType() ?: 'application/octet-stream';
                $original = $attachment->filename() ?: 'adjunto';
                $extension = pathinfo($original, PATHINFO_EXTENSION) ?: 'bin';
                $filename = uniqid('mail_').'.'.$extension;
                $path = "messages/{$tenantId}/{$filename}";

                Storage::disk('public')->put($path, $contents);

                $stored[] = [
                    'url' => '/storage/'.$path,
                    'mime_type' => $mimeType,
                    'filename' => $original,
                    'type' => $this->mimeToMessageType($mimeType),
                ];
            }
        } catch (\Throwable $e) {
            // Un adjunto ilegible no debe perder el email entero.
            Log::warning('Mail sync: error guardando adjuntos: '.$e->getMessage());
        }

        return $stored;
    }

    private function mimeToMessageType(string $mimeType): MessageType
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => MessageType::Image,
            str_starts_with($mimeType, 'audio/') => MessageType::Audio,
            str_starts_with($mimeType, 'video/') => MessageType::Video,
            default => MessageType::Document,
        };
    }

    private function findOrCreateContact(Channel $channel, string $email, ?string $name): Contact
    {
        $contact = Contact::firstOrCreate(
            [
                'tenant_id' => $channel->tenant_id,
                'source' => 'mail',
                'external_id' => strtolower($email),
            ],
            [
                'name' => $name ?: Str::before($email, '@'),
                'email' => $email,
                'branch_id' => $channel->branch_id,
            ]
        );

        // Si el contacto ya existía sin email (creado por otro canal o import),
        // lo completamos con el dato que acaba de llegar.
        if (! $contact->wasRecentlyCreated && ! $contact->email) {
            $contact->update(['email' => $email]);
        }

        return $contact;
    }

    private function findOrCreateConversation(Contact $contact, Channel $channel, MailConfig $config): Conversation
    {
        $conversation = Conversation::firstOrCreate(
            [
                'tenant_id' => $channel->tenant_id,
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
            ],
            [
                'status' => 'open',
                'last_message_at' => now(),
                'branch_id' => $contact->branch_id ?? $channel->branch_id,
                'ai_autoreply_enabled' => (bool) $config->ai_autoreply_default,
            ]
        );

        if (! $conversation->pipeline_stage_id) {
            $defaultStage = PipelineStage::where('tenant_id', $channel->tenant_id)
                ->orderByDesc('is_default')
                ->orderBy('sort_order', 'asc')
                ->first();

            if ($defaultStage) {
                $conversation->update(['pipeline_stage_id' => $defaultStage->id]);
            }
        }

        return $conversation;
    }

    /**
     * Misma política que WhatsApp/Instagram: sólo texto e imagen, conversación
     * con auto-respuesta activa y tenant con IA habilitada.
     */
    private function maybeDispatchAiReply(Message $message): void
    {
        if ($message->direction !== MessageDirection::INBOUND
            || $message->sender_type !== SenderType::CONTACT
            || ! in_array($message->message_type, [MessageType::Text, MessageType::Image], true)) {
            return;
        }

        $conversation = Conversation::find($message->conversation_id);
        if (! $conversation || ! $conversation->ai_autoreply_enabled) {
            return;
        }

        $hasEnabledAiConfig = AiConfig::withoutGlobalScopes()
            ->where('tenant_id', $message->tenant_id)
            ->where('enabled', true)
            ->exists();

        if (! $hasEnabledAiConfig) {
            return;
        }

        GenerateAiReplyJob::dispatch($conversation->id)
            ->delay(now()->addSeconds(8));
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23505 es unique_violation en Postgres.
        return (string) ($e->getCode()) === '23505'
            || str_contains(strtolower($e->getMessage()), 'unique');
    }

    // ---------------------------------------------------------------------
    // Envío saliente (SMTP)
    // ---------------------------------------------------------------------

    /**
     * @return array{config: MailConfig, to: string}
     */
    private function resolveOutboundContext(Conversation $conversation): array
    {
        $channel = $conversation->channel;
        if (! $channel) {
            throw new \InvalidArgumentException('La conversación no tiene un canal asociado.');
        }

        if ($channel->type !== ChannelType::MAIL) {
            throw new \InvalidArgumentException('Solo se pueden enviar emails desde conversaciones de este canal.');
        }

        if (! $channel->isActive()) {
            throw new \InvalidArgumentException('El canal de email está desconectado.');
        }

        $config = $channel->mailConfig;
        if (! $config) {
            throw new \InvalidArgumentException('El canal no tiene una configuración válida de email.');
        }

        $to = $conversation->contact?->email ?: $conversation->contact?->external_id;
        if (! $to || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('El contacto no tiene una dirección de email válida.');
        }

        return ['config' => $config, 'to' => $to];
    }

    /**
     * Contexto de la respuesta: asunto + headers de threading.
     *
     * El asunto con `Re:` sólo es un fallback; lo que realmente hace que los
     * clientes de correo agrupen la respuesta dentro del hilo original son los
     * headers In-Reply-To y References (RFC 5322 §3.6.4). Sin ellos, Gmail y
     * Outlook muestran nuestra respuesta como un mensaje suelto, y pueden
     * mezclar hilos de distintos contactos que compartan asunto.
     *
     * El Message-ID del entrante se guarda en `mail_message_id`, así que sólo
     * hay que recuperarlo.
     *
     * @return array{subject: string, in_reply_to: ?string, references: list<string>}
     */
    private function replyContext(Conversation $conversation): array
    {
        $lastInbound = Message::where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::INBOUND)
            ->latest('id')
            ->first();

        return [
            'subject' => $this->replySubject($lastInbound),
            'in_reply_to' => $this->normalizeMessageId($lastInbound?->mail_message_id),
            'references' => $this->buildReferences($conversation, $lastInbound),
        ];
    }

    /**
     * Asunto de la respuesta: reusa el del hilo con el prefijo Re:, sin
     * encadenar prefijos si el asunto ya venía respondido.
     */
    private function replySubject(?Message $lastInbound): string
    {
        $firstLine = trim(Str::before((string) $lastInbound?->content, "\n"));

        if ($firstLine === '') {
            return 'Mensaje de '.config('app.name');
        }

        return Str::startsWith(strtolower($firstLine), 're:')
            ? Str::limit($firstLine, 200, '')
            : 'Re: '.Str::limit($firstLine, 195, '');
    }

    /**
     * Cadena References del hilo: los Message-ID previos, del más viejo al más
     * nuevo. Los clientes la usan para reconstruir el árbol cuando falta algún
     * mensaje intermedio.
     *
     * Se acota a los últimos mensajes porque la cadena completa puede crecer
     * sin límite y algunos servidores rechazan headers demasiado largos.
     *
     * @return list<string>
     */
    private function buildReferences(Conversation $conversation, ?Message $lastInbound): array
    {
        $ids = Message::where('conversation_id', $conversation->id)
            ->whereNotNull('mail_message_id')
            ->latest('id')
            ->limit(self::MAX_REFERENCES)
            ->pluck('mail_message_id')
            ->reverse()
            ->values();

        $references = [];
        foreach ($ids as $id) {
            $normalized = $this->normalizeMessageId($id);
            if ($normalized !== null && ! in_array($normalized, $references, true)) {
                $references[] = $normalized;
            }
        }

        // El mensaje al que respondemos debe cerrar la cadena.
        $inReplyTo = $this->normalizeMessageId($lastInbound?->mail_message_id);
        if ($inReplyTo !== null) {
            $references = array_values(array_filter($references, fn (string $r) => $r !== $inReplyTo));
            $references[] = $inReplyTo;
        }

        return $references;
    }

    /**
     * Devuelve un Message-ID con el formato `<id@dominio>` que exige el RFC, o
     * null si el valor guardado no es un Message-ID real.
     *
     * Los entrantes que llegaron sin Message-ID tienen `mail_message_id` en
     * null: para esos, mandar el header sería peor que omitirlo.
     */
    private function normalizeMessageId(?string $mailMessageId): ?string
    {
        $value = trim((string) $mailMessageId);

        if ($value === '' || ! str_contains($value, '@')) {
            return null;
        }

        if (! str_starts_with($value, '<')) {
            $value = '<'.$value;
        }

        if (! str_ends_with($value, '>')) {
            $value .= '>';
        }

        return $value;
    }

    /**
     * Envía el email por SMTP. Devuelve el Message-ID generado, que guardamos
     * como external_id para poder dedupear si el servidor deja copia en INBOX.
     *
     * `$reply` lleva asunto y headers de threading (ver replyContext()).
     *
     * @param  array{subject: string, in_reply_to: ?string, references: list<string>}  $reply
     * @param  list<array{path: string, name: string, mime: string}>  $attachments
     */
    private function sendEmail(
        MailConfig $config,
        string $to,
        array $reply,
        string $body,
        array $attachments = []
    ): string {
        $email = (new Email)
            ->from($this->fromAddress($config))
            ->to($to)
            ->subject($reply['subject'])
            ->text($body);

        // Threading: sin estos headers el cliente del contacto muestra la
        // respuesta fuera del hilo original.
        $headers = $email->getHeaders();

        if ($reply['in_reply_to'] !== null) {
            $headers->addTextHeader('In-Reply-To', $reply['in_reply_to']);
        }

        if ($reply['references'] !== []) {
            $headers->addTextHeader('References', implode(' ', $reply['references']));
        }

        foreach ($attachments as $attachment) {
            $email->addPart(new DataPart(
                new File($attachment['path']),
                $attachment['name'],
                $attachment['mime'],
            ));
        }

        try {
            $this->transports->smtp($config)->send($email);
        } catch (\Throwable $e) {
            Log::error('Mail: error enviando por SMTP', [
                'mail_config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);

            throw new \InvalidArgumentException(
                'No se pudo enviar el email. Verificá la configuración SMTP del canal.',
                0,
                $e
            );
        }

        return $email->getHeaders()->get('Message-ID')?->getBodyAsString() ?? '';
    }

    private function fromAddress(MailConfig $config): Address
    {
        return new Address(
            $config->email_address,
            $config->from_name ?: ''
        );
    }

    public function sendTextMessageFromCRM(Conversation $conversation, string $content, User $user): Message
    {
        ['config' => $config, 'to' => $to] = $this->resolveOutboundContext($conversation);

        $externalId = $this->sendEmail($config, $to, $this->replyContext($conversation), $content);

        return $this->persistOutbound($conversation, $user, [
            'content' => $content,
            'message_type' => MessageType::Text,
            'external_id' => $externalId,
            'last_content' => $content,
        ]);
    }

    public function sendImageMessageFromCRM(
        Conversation $conversation,
        string $localMediaPath,
        string $mediaUrl,
        string $mimeType,
        ?string $caption,
        User $user
    ): Message {
        return $this->sendAttachmentFromCRM(
            $conversation,
            $localMediaPath,
            $mediaUrl,
            $mimeType,
            $caption ?? '',
            $user,
            MessageType::Image,
            '📷 '.($caption ?: 'Imagen'),
        );
    }

    public function sendAudioMessageFromCRM(
        Conversation $conversation,
        string $localMediaPath,
        string $mediaUrl,
        string $mimeType,
        User $user
    ): Message {
        return $this->sendAttachmentFromCRM(
            $conversation,
            $localMediaPath,
            $mediaUrl,
            $mimeType,
            '',
            $user,
            MessageType::Audio,
            '🎵 Audio',
        );
    }

    /**
     * Envío con adjunto: el archivo ya está en el disco público (lo guardó el
     * controlador), así que lo adjuntamos desde ahí.
     */
    private function sendAttachmentFromCRM(
        Conversation $conversation,
        string $localMediaPath,
        string $mediaUrl,
        string $mimeType,
        string $caption,
        User $user,
        MessageType $type,
        string $lastContent
    ): Message {
        ['config' => $config, 'to' => $to] = $this->resolveOutboundContext($conversation);

        $absolutePath = Storage::disk('public')->path($localMediaPath);

        if (! is_readable($absolutePath)) {
            throw new \InvalidArgumentException('No se pudo leer el archivo adjunto.');
        }

        $externalId = $this->sendEmail(
            $config,
            $to,
            $this->replyContext($conversation),
            $caption !== '' ? $caption : 'Te enviamos un archivo adjunto.',
            [[
                'path' => $absolutePath,
                'name' => basename($localMediaPath),
                'mime' => $mimeType,
            ]],
        );

        return $this->persistOutbound($conversation, $user, [
            'content' => $caption,
            'message_type' => $type,
            'external_id' => $externalId,
            'media_url' => $mediaUrl,
            'media_mime_type' => $mimeType,
            'media_filename' => basename($localMediaPath),
            'last_content' => $lastContent,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistOutbound(Conversation $conversation, User $user, array $data): Message
    {
        $message = Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::USER,
            'sender_id' => $user->id,
            'content' => $data['content'],
            'message_type' => $data['message_type'],
            'media_url' => $data['media_url'] ?? null,
            'media_mime_type' => $data['media_mime_type'] ?? null,
            'media_filename' => $data['media_filename'] ?? null,
            'direction' => MessageDirection::OUTBOUND,
            'delivered_at' => now(),
            'external_id' => $data['external_id'] ?: null,
            // Nuestras respuestas también forman parte del hilo: sin esto
            // quedarían fuera de la cadena References.
            'mail_message_id' => $data['external_id'] ?: null,
        ]);

        $conversation->update([
            'last_message_at' => $message->created_at,
            'last_message_content' => $data['last_content'],
            // Handoff: si un humano responde desde el CRM, el bot se apaga.
            'ai_autoreply_enabled' => false,
        ]);

        try {
            broadcast(new MessageSent($message));
            broadcast(new TenantMessageReceived($message, $conversation->tenant_id));
        } catch (\Exception $e) {
            Log::error('Error broadcasting outbound mail message: '.$e->getMessage());
        }

        return $message;
    }

    /**
     * Auto-respuesta de IA: igual que el envío manual pero con sender SYSTEM y
     * sin handoff (el bot no se apaga a sí mismo).
     */
    public function sendSystemTextMessageFromCRM(Conversation $conversation, string $content): Message
    {
        ['config' => $config, 'to' => $to] = $this->resolveOutboundContext($conversation);

        $externalId = $this->sendEmail($config, $to, $this->replyContext($conversation), $content);

        $message = Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::SYSTEM,
            'content' => $content,
            'direction' => MessageDirection::OUTBOUND,
            'delivered_at' => now(),
            'message_type' => MessageType::Text,
            'external_id' => $externalId ?: null,
            'mail_message_id' => $externalId ?: null,
        ]);

        $conversation->update([
            'last_message_at' => $message->created_at,
            'last_message_content' => $content,
        ]);

        try {
            broadcast(new MessageSent($message));
            broadcast(new TenantMessageReceived($message, $conversation->tenant_id));
        } catch (\Exception $e) {
            Log::error('Error broadcasting AI outbound mail message: '.$e->getMessage());
        }

        return $message;
    }
}
