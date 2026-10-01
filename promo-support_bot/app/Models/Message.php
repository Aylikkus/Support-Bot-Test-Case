<?php

namespace App\Models;

use App\Enums\MessageType;
use App\Enums\SenderType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property SenderType $sender_type
 * @property MessageType $message_type
 * @property string|null $image_path
 * @property string|null $file_id
 * @property string|null $file_name
 */
class Message extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'messages';

    protected $primaryKey = 'message_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'telegram_message_id' => 'integer',
            'operator_id' => 'integer',
            'sender_type' => SenderType::class,
            'message_type' => MessageType::class,
        ];
    }

    /** @return BelongsTo<SupportRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(SupportRequest::class, 'request_id', 'request_id');
    }
}
