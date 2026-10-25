<?php

declare(strict_types=1);

namespace App\Storage\Domain\Enum;

/**
 * What a measured item is (ADR 017). The six categories form the segmented bar; the order of the cases is the
 * display order. Color and icon live here so that the bar, the tables and the history chart always match.
 */
enum StorageCategory: string
{
    case Database = 'database';
    case Imports = 'imports';
    case Media = 'media';
    case Application = 'application';
    case Temporary = 'temporary';
    case Other = 'other';

    public function color(): string
    {
        return match ($this) {
            self::Database => '#206bc4',
            self::Imports => '#2fb344',
            self::Media => '#f76707',
            self::Application => '#ae3ec9',
            self::Temporary => '#f59f00',
            self::Other => '#868e96',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Database => 'fas fa-database',
            self::Imports => 'fas fa-file-import',
            self::Media => 'fas fa-photo-film',
            self::Application => 'fas fa-code',
            self::Temporary => 'fas fa-broom',
            self::Other => 'fas fa-box-archive',
        };
    }

    public function labelKey(): string
    {
        return 'storage.category.'.$this->value;
    }
}
