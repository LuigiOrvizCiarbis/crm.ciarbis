<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('messages')
            ->whereNotNull('external_id')
            ->where('external_id', 'like', 'mail-%-att-%')
            ->orderBy('id')
            ->chunkById(250, function ($attachments): void {
                foreach ($attachments as $attachment) {
                    $parentExternalId = preg_replace('/-att-\d+$/', '', (string) $attachment->external_id);
                    if (! $parentExternalId || $parentExternalId === $attachment->external_id) {
                        continue;
                    }

                    $parentId = DB::table('messages')
                        ->where('external_id', $parentExternalId)
                        ->value('id');

                    if ($parentId) {
                        DB::table('messages')
                            ->where('id', $attachment->id)
                            ->update(['mail_parent_message_id' => $parentId]);
                    }
                }
            });
    }

    public function down(): void
    {
        DB::table('messages')
            ->whereNotNull('external_id')
            ->where('external_id', 'like', 'mail-%-att-%')
            ->update(['mail_parent_message_id' => null]);
    }
};
