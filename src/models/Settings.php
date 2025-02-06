<?php

namespace esign\craftblitzvercel\models;

use craft\base\Model;

/**
 * Blitz Vercel Purger settings
 */
class Settings extends Model
{
    public string $bypassToken = '';

    public function rules(): array
    {
        return [
            ['bypassToken', 'required'],
            ['bypassToken', 'string'],
        ];
    }
}
