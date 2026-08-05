<?php

declare(strict_types=1);

namespace App\Analytics\Domain\Enum;

enum FeatureArea: string
{
    case Home = 'home';
    case Dashboard = 'dashboard';
    case Statistics = 'statistics';
    case Analysis = 'analysis';
    case Explore = 'explore';
    case Import = 'import';
    case Export = 'export';
    case Admin = 'admin';
    case Blog = 'blog';
    case Pages = 'pages';
    case Other = 'other';
}
