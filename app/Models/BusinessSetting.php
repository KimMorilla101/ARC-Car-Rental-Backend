<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One stored business setting. Read values through App\Support\BusinessSettings, which applies the
 * confirmed defaults for keys that were never set.
 */
#[Fillable(['key', 'value'])]
class BusinessSetting extends Model
{
    //
}
