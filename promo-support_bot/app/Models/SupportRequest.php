<?php

namespace App\Models;

use App\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property RequestStatus $status
 */
class SupportRequest extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'requests';

    protected $primaryKey = 'request_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'status' => RequestStatus::class,
            'forwarded_to_operator' => 'boolean',
            'forwarded_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'request_id', 'request_id');
    }

    /** @return HasOne<Message, $this> */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'request_id', 'request_id')->latestOfMany('message_id');
    }
}
