<?php

namespace App\Support;

class PermissionCatalog
{
    /**
     * Flat list of every permission known to the system, grouped by resource.
     *
     * @return array<string, list<string>>
     */
    public static function grouped(): array
    {
        return [
            'conversations' => [
                'conversations.view_any',
                'conversations.view_assigned',
                'conversations.view',
                'conversations.update',
                'conversations.delete',
                'conversations.assign',
                'conversations.change_stage',
                'conversations.send_message',
            ],
            'messages' => [
                'messages.view',
                'messages.create',
                'messages.update',
                'messages.update_any',
                'messages.delete',
                'messages.delete_any',
            ],
            'contacts' => [
                'contacts.view_any',
                'contacts.view_assigned',
                'contacts.view',
                'contacts.create',
                'contacts.update',
                'contacts.delete',
                'contacts.import',
                'contacts.view_history',
            ],
            'contact_fields' => [
                'contact_fields.view',
                'contact_fields.manage',
            ],
            'opportunities' => [
                'opportunities.view_any',
                'opportunities.view_assigned',
                'opportunities.view',
                'opportunities.create',
                'opportunities.update',
                'opportunities.delete',
                'opportunities.assign',
                'opportunities.change_stage',
                'opportunities.view_summary',
            ],
            'pipeline_stages' => [
                'pipeline_stages.view',
                'pipeline_stages.manage',
            ],
            'products' => [
                'products.view',
                'products.manage',
            ],
            'product_fields' => [
                'product_fields.view',
                'product_fields.manage',
            ],
            'tasks' => [
                'tasks.view_any',
                'tasks.view_assigned',
                'tasks.view',
                'tasks.create',
                'tasks.update',
                'tasks.update_any',
                'tasks.delete',
                'tasks.delete_any',
                'tasks.assign',
            ],
            'tags' => [
                'tags.view',
                'tags.manage',
                'tags.attach',
            ],
            'channels' => [
                'channels.view_any',
                'channels.view_assigned',
                'channels.view',
                'channels.create',
                'channels.update',
                'channels.delete',
                'channels.manage_users',
                'channels.connect_whatsapp',
                'channels.connect_mail',
            ],
            'ai_config' => [
                'ai_config.view',
                'ai_config.manage',
            ],
            'woocommerce' => [
                'woocommerce.view',
                'woocommerce.manage',
            ],
            'webhooks' => [
                'webhooks.view',
                'webhooks.manage',
            ],
            'automations' => [
                'automations.view',
                'automations.manage',
            ],
            'templates' => [
                'templates.view',
                'templates.sync',
                'templates.create',
                'templates.delete',
                'templates.send',
            ],
            'analytics' => [
                'analytics.view',
                'analytics.view_team',
            ],
            'users' => [
                'users.view',
                'users.update',
                'users.assign_role',
                'users.deactivate',
            ],
            'invitations' => [
                'invitations.view',
                'invitations.create',
                'invitations.revoke',
            ],
            'roles' => [
                'roles.view',
                'roles.manage',
            ],
            'permissions' => [
                'permissions.view',
            ],
            'branches' => [
                'branches.view_any',
                'branches.view',
                'branches.manage',
                'branches.view_all',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(...array_values(self::grouped()));
    }

    /**
     * Permissions granted to the immutable Owner role (everything).
     *
     * @return list<string>
     */
    public static function ownerPermissions(): array
    {
        return self::all();
    }

    /**
     * Permissions granted to the seeded Admin role.
     * Excludes the most destructive/sensitive permissions so the Owner remains the
     * single irreplaceable account.
     *
     * @return list<string>
     */
    public static function adminPermissions(): array
    {
        $excluded = [
            'roles.manage',
            'channels.delete',
            'users.deactivate',
        ];

        return array_values(array_diff(self::all(), $excluded));
    }

    /**
     * Permissions granted to the seeded Member role (default non-admin seat).
     * Safe defaults: see/work on your own assignments, send messages and create
     * follow-up artifacts, but never reach team-wide data or administrative
     * actions.
     *
     * @return list<string>
     */
    public static function memberPermissions(): array
    {
        return [
            'conversations.view_assigned',
            'conversations.view',
            'conversations.send_message',
            'messages.view',
            'messages.create',
            'messages.update',
            'messages.delete',
            'contacts.view_assigned',
            'contacts.view',
            'contacts.create',
            'contacts.update',
            'contacts.view_history',
            'contact_fields.view',
            'opportunities.view_assigned',
            'opportunities.view',
            'opportunities.create',
            'opportunities.update',
            'opportunities.change_stage',
            'pipeline_stages.view',
            'products.view',
            'product_fields.view',
            'tasks.view_assigned',
            'tasks.view',
            'tasks.create',
            'tasks.update',
            'tasks.delete',
            'tags.view',
            'tags.attach',
            'templates.view',
            'analytics.view',
            'branches.view_any',
            'branches.view',
        ];
    }
}
