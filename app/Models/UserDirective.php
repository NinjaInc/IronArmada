<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A player's progress on one directive (daily or weekly objective) for one period.
 *
 * @property int $id
 * @property int $user_id
 * @property string $directive_key
 * @property string $period_key
 * @property int $period_start
 * @property int $baseline
 * @property bool $claimed
 */
class UserDirective extends Model
{
    protected $table = 'user_directives';

    protected $fillable = ['user_id', 'directive_key', 'period_key', 'period_start', 'baseline', 'claimed'];

    protected $casts = [
        'claimed' => 'boolean',
    ];
}
