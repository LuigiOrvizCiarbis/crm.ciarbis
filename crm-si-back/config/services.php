<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Config de IA. El proveedor, la API key, el modelo y el enabled son
    // por-tenant (tabla ai_configs, BYOK). Acá solo queda lo global.
    'ai' => [
        // Cantidad de mensajes de historial que se mandan al modelo por respuesta.
        'max_history' => env('AI_MAX_HISTORY', 20),
        // Cota del catálogo inyectado al system prompt, para no exceder el
        // límite de contexto del proveedor (haría que generate() devuelva null).
        'catalog_max_products' => env('AI_CATALOG_MAX_PRODUCTS', 100),
        'catalog_max_description_chars' => env('AI_CATALOG_MAX_DESCRIPTION_CHARS', 300),
        'catalog_max_chars' => env('AI_CATALOG_MAX_CHARS', 12000),
        // Cantidad máxima de imágenes del historial que se envían como bloque
        // visual al modelo (las más viejas degradan a un placeholder de texto,
        // para acotar costo y tokens). Cota de tamaño por imagen en bytes.
        'max_images' => env('AI_MAX_IMAGES', 3),
        'max_image_bytes' => env('AI_MAX_IMAGE_BYTES', 5242880),
        // Timeout (segundos) del request de generación al proveedor. Los mensajes
        // con imágenes (visión) tardan bastante más que texto puro: con 10s el
        // request cortaba antes de que el modelo respondiera y el autoreply
        // quedaba sin respuesta. 60s cubre visión con Opus/GPT-4o holgadamente.
        'generate_timeout' => env('AI_GENERATE_TIMEOUT', 60),
        // Traducciones de chat: usan la misma API key BYOK del tenant, pero un
        // modelo económico independiente del modelo elegido para el bot.
        'translation_models' => [
            'openai' => env('AI_OPENAI_TRANSLATION_MODEL', 'gpt-5-mini'),
            'claude' => env('AI_CLAUDE_TRANSLATION_MODEL', 'claude-haiku-4-5-20251001'),
        ],
    ],

    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID'),
        'app_secret' => env('FACEBOOK_APP_SECRET'),
        'graph_version' => env('FACEBOOK_GRAPH_API_VERSION', 'v26.0'),
        'verify_token' => env('FACEBOOK_VERIFY_TOKEN', 'embbebedsecret'),
        // Base pública desde la que Meta puede fetchear media saliente (imágenes,
        // audio) enviada por attachment.url. Debe ser HTTPS y accesible desde
        // internet. En local requiere un túnel (p. ej. ngrok). Fallback a app.url.
        'public_media_base_url' => env('META_PUBLIC_MEDIA_BASE_URL', env('APP_URL')),
    ],

    'instagram' => [
        // Permiso exacto de mensajería de Instagram: verificar el nombre vigente
        // en el dashboard de Meta (App Review). Documentado como
        // 'instagram_manage_messages' al momento de implementar.
        'messaging_permission' => env('INSTAGRAM_MESSAGING_PERMISSION', 'instagram_manage_messages'),
    ],

    'messenger' => [
        // Tag HUMAN_AGENT: extiende la ventana de respuesta de 24h a 7 días, pero
        // sólo para mensajes tipeados por una persona. Requiere el feature
        // "Human Agent" aprobado en App Review (separado de las permissions
        // pages_*), y usarlo en la auto-respuesta de IA sería una violación de
        // política. Mientras esté en false todo sale con messaging_type=RESPONSE.
        'human_agent_enabled' => env('MESSENGER_HUMAN_AGENT_ENABLED', false),
    ],

    'google_calendar' => [
        'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_CALENDAR_REDIRECT_URI'),
    ],

];
